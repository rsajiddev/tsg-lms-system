<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

/**
 * Weighted course grade, completion state and certificate eligibility.
 *
 * A single learner-facing score is assembled from five components and stored per student/course:
 *   video 30%   lessons' watched share (or completion when a lesson has no video)
 *   quiz  28%   best submitted score across the course's lesson + module quizzes
 *   exercise 14% best reviewed score across the course's exercise assignments
 *   project  14% best reviewed score across the course's mini-project assignments
 *   final    14% best submitted score across the course's final quiz
 *
 * A component that has no items in the course is dropped and its weight is redistributed, so a
 * course without a project never loses 14% nobody could earn. Weights and the certificate pass
 * bar live in app_settings and are seeded once by the migration.
 *
 * Every read is fail-open: grading runs after a lesson/quiz/assignment action and must never turn
 * a successful student action into a 500, so a missing column or table degrades that component to
 * absent (or, for the whole recompute, to a no-op) and logs instead of throwing.
 */
final class CourseGrading
{
    public const VIDEO = 'video';
    public const QUIZ = 'quiz';
    public const EXERCISE = 'exercise';
    public const PROJECT = 'project';
    public const FINAL = 'final';

    private const WEIGHT_KEYS = [
        self::VIDEO => ['grade_weight_video', 30],
        self::QUIZ => ['grade_weight_quiz', 28],
        self::EXERCISE => ['grade_weight_exercise', 14],
        self::PROJECT => ['grade_weight_project', 14],
        self::FINAL => ['grade_weight_final', 14],
    ];

    /** Bit per present component, stored so a report can tell a 0% from a not-offered component. */
    private const MASK = [self::VIDEO => 1, self::QUIZ => 2, self::EXERCISE => 4, self::PROJECT => 8, self::FINAL => 16];

    public const MIN_GRADE_KEY = 'certificate_min_grade_percent';
    public const MIN_ATTENDANCE_KEY = 'certificate_min_attendance_percent';
    public const DEFAULT_MIN_GRADE = 60;
    public const DEFAULT_MIN_ATTENDANCE = 75;

    /** Letter bands, highest first: [inclusive percent floor, label]. */
    private const SCALE = [[90, 'A+'], [80, 'A'], [70, 'B+'], [60, 'B'], [50, 'C'], [0, 'F']];

    private PDO $db;

    /** @var array<string,int>|null */
    private ?array $weights = null;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /** Recompute and persist one student's grade for one course, and upgrade access to completed when earned. */
    public function recompute(int $studentId, int $courseId): void
    {
        if ($studentId < 1 || $courseId < 1) {
            return;
        }
        try {
            $components = [
                self::VIDEO => $this->videoPercent($studentId, $courseId),
                self::QUIZ => $this->quizPercent($studentId, $courseId, ['lesson', 'module']),
                self::EXERCISE => $this->assignmentPercent($studentId, $courseId, 'exercise'),
                self::PROJECT => $this->assignmentPercent($studentId, $courseId, 'mini_project'),
                self::FINAL => $this->quizPercent($studentId, $courseId, ['final']),
            ];

            $weights = $this->weights();
            $weighted = 0.0;
            $presentSum = 0.0;
            $mask = 0;
            foreach ($components as $name => $value) {
                if ($value === null) {
                    continue; // component not offered by this course
                }
                $mask |= self::MASK[$name];
                $weight = (float) $weights[$name];
                $presentSum += $weight;
                $weighted += $value * $weight;
            }

            $overall = $presentSum > 0 ? max(0.0, min(100.0, round($weighted / $presentSum, 2))) : 0.0;
            $letter = self::letter($overall);

            $this->db->prepare(
                'INSERT INTO lms_course_grades
                    (student_id, course_id, video_pct, quiz_pct, exercise_pct, project_pct, final_pct, overall_pct, letter_grade, components_mask, computed_at)
                 VALUES(?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE
                    video_pct = VALUES(video_pct), quiz_pct = VALUES(quiz_pct), exercise_pct = VALUES(exercise_pct),
                    project_pct = VALUES(project_pct), final_pct = VALUES(final_pct), overall_pct = VALUES(overall_pct),
                    letter_grade = VALUES(letter_grade), components_mask = VALUES(components_mask), computed_at = VALUES(computed_at)'
            )->execute([
                $studentId, $courseId,
                round((float) ($components[self::VIDEO] ?? 0), 2),
                round((float) ($components[self::QUIZ] ?? 0), 2),
                round((float) ($components[self::EXERCISE] ?? 0), 2),
                round((float) ($components[self::PROJECT] ?? 0), 2),
                round((float) ($components[self::FINAL] ?? 0), 2),
                $overall, $letter, $mask,
            ]);

            $this->maybeMarkCompleted($studentId, $courseId, $overall);
        } catch (\PDOException $exception) {
            // A missing course_grades table or column must not break the action that triggered this.
            error_log('[COURSE GRADING] Recompute failed for student ' . $studentId . ' course ' . $courseId . ': ' . $exception->getMessage());
        }
    }

    /** Read the stored matrix without recomputing. */
    public function forStudentCourse(int $studentId, int $courseId): ?array
    {
        try {
            $stmt = $this->db->prepare('SELECT * FROM lms_course_grades WHERE student_id = ? AND course_id = ? LIMIT 1');
            $stmt->execute([$studentId, $courseId]);
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        } catch (\PDOException $exception) {
            error_log('[COURSE GRADING] Read failed: ' . $exception->getMessage());
            return null;
        }
    }

    /** Recompute then read, so a caller always sees current numbers. */
    public function refresh(int $studentId, int $courseId): ?array
    {
        $this->recompute($studentId, $courseId);
        return $this->forStudentCourse($studentId, $courseId);
    }

    /**
     * Attendance as a share of decided scheduled live classes since the student got access.
     * Returns null while nothing is decided yet, so eligibility does not punish a brand-new joiner.
     */
    public function attendancePercent(int $studentId, int $courseId, ?string $sinceUtc): ?float
    {
        if ($sinceUtc === null) {
            return null;
        }
        try {
            (new AttendanceService($this->db))->closeExpired();
            $stmt = $this->db->prepare(
                "SELECT SUM(status='present') AS present,COUNT(*) AS decided
                 FROM lms_attendance_records
                 WHERE student_id=? AND course_id=? AND lesson_id IS NOT NULL
                   AND attendance_date>=DATE(?) AND status IN ('present','absent')"
            );
            $stmt->execute([$studentId, $courseId, $sinceUtc]);
            $row = $stmt->fetch();
            $present = (int) ($row['present'] ?? 0);
            $decided = (int) ($row['decided'] ?? 0);
            return $decided < 1 ? null : round($present / $decided * 100, 2);
        } catch (\PDOException $exception) {
            error_log('[COURSE GRADING] Attendance read failed: ' . $exception->getMessage());
            return null;
        }
    }

    /**
     * Whether a certificate may be requested now, with the exact shortfalls for the student copy.
     * Recomputes first so a just-finished quiz counts.
     *
     * @return array{ok:bool,overall:float,letter:string,attendance:?float,attendanceRequired:bool,minGrade:int,minAttendance:int,missing:array<int,string>}
     */
    public function eligibility(int $studentId, int $courseId): array
    {
        $grade = $this->refresh($studentId, $courseId);
        $overall = $grade === null ? 0.0 : (float) $grade['overall_pct'];
        $letter = $grade === null ? 'F' : (string) $grade['letter_grade'];

        $since = null;
        $requiresAttendance = false;
        try {
            $planColumn = AccessPlan::columnStored($this->db, 'student_course_access.plan_code') ? ', plan_code' : '';
            $stmt = $this->db->prepare('SELECT granted_at' . $planColumn . ' FROM lms_student_course_access WHERE student_id = ? AND course_id = ? LIMIT 1');
            $stmt->execute([$studentId, $courseId]);
            $access = $stmt->fetch();
            if (is_array($access)) {
                $since = (string) ($access['granted_at'] ?? '');
                $requiresAttendance = AccessPlan::allowsLive(isset($access['plan_code']) ? (string) $access['plan_code'] : null);
            }
        } catch (\PDOException $exception) {
            error_log('[COURSE GRADING] Access lookup failed: ' . $exception->getMessage());
        }
        $attendance = $requiresAttendance ? $this->attendancePercent($studentId, $courseId, $since) : null;

        $settings = $this->thresholds();
        $minGrade = $settings['grade'];
        $minAttendance = $settings['attendance'];

        $missing = [];
        if ($overall < $minGrade) {
            $missing[] = sprintf('Overall grade is %s%% (%s); you need at least %d%%.', rtrim(rtrim(number_format($overall, 2, '.', ''), '0'), '.'), $letter, $minGrade);
        }
        if ($requiresAttendance && $attendance !== null && $attendance < $minAttendance) {
            $missing[] = sprintf('Attendance is %s%%; you need at least %d%%.', rtrim(rtrim(number_format($attendance, 2, '.', ''), '0'), '.'), $minAttendance);
        }

        return [
            'ok' => $missing === [],
            'overall' => $overall,
            'letter' => $letter,
            'attendance' => $attendance,
            'attendanceRequired' => $requiresAttendance,
            'minGrade' => $minGrade,
            'minAttendance' => $minAttendance,
            'missing' => $missing,
        ];
    }

    /** @return array<string,int> resolved component weights */
    public function weights(): array
    {
        if ($this->weights !== null) {
            return $this->weights;
        }
        $resolved = [];
        $stored = $this->settingsRows(array_column(self::WEIGHT_KEYS, 0));
        foreach (self::WEIGHT_KEYS as $name => [$key, $default]) {
            $value = isset($stored[$key]) ? (int) $stored[$key] : $default;
            $resolved[$name] = $value >= 0 && $value <= 100 ? $value : $default;
        }
        // Guard against an all-zero configuration collapsing the divisor.
        if (array_sum($resolved) <= 0) {
            foreach (self::WEIGHT_KEYS as $name => [$key, $default]) {
                $resolved[$name] = $default;
            }
        }
        return $this->weights = $resolved;
    }

    /** @return array{grade:int,attendance:int} */
    public function thresholds(): array
    {
        $stored = $this->settingsRows([self::MIN_GRADE_KEY, self::MIN_ATTENDANCE_KEY]);
        $grade = isset($stored[self::MIN_GRADE_KEY]) ? (int) $stored[self::MIN_GRADE_KEY] : self::DEFAULT_MIN_GRADE;
        $attendance = isset($stored[self::MIN_ATTENDANCE_KEY]) ? (int) $stored[self::MIN_ATTENDANCE_KEY] : self::DEFAULT_MIN_ATTENDANCE;
        return [
            'grade' => $grade < self::DEFAULT_MIN_GRADE || $grade > 100 ? self::DEFAULT_MIN_GRADE : $grade,
            'attendance' => $attendance < 0 || $attendance > 100 ? self::DEFAULT_MIN_ATTENDANCE : $attendance,
        ];
    }

    public static function letter(float $overall): string
    {
        foreach (self::SCALE as [$floor, $label]) {
            if ($overall >= $floor) {
                return $label;
            }
        }
        return 'F';
    }

    /**
     * @param array<int,string> $types quiz_type values to include
     * @return float|null average best percentage, or null when the course offers none of these
     */
    private function quizPercent(int $studentId, int $courseId, array $types): ?float
    {
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        try {
            $stmt = $this->db->prepare(
                "SELECT q.id,
                        (SELECT MAX(qa.percentage) FROM lms_quiz_attempts qa
                         WHERE qa.quiz_id = q.id AND qa.student_id = ? AND qa.status <> 'in_progress' AND qa.percentage IS NOT NULL) AS best
                 FROM lms_quizzes q
                 WHERE q.course_id = ? AND q.quiz_type IN ($placeholders) AND q.status IN ('released', 'closed')"
            );
            $stmt->execute(array_merge([$studentId, $courseId], $types));
            $rows = $stmt->fetchAll();
        } catch (\PDOException $exception) {
            error_log('[COURSE GRADING] Quiz component failed (un-migrated quiz_type?): ' . $exception->getMessage());
            return null;
        }
        if ($rows === []) {
            return null;
        }
        $total = 0.0;
        foreach ($rows as $row) {
            $total += (float) ($row['best'] ?? 0);
        }
        return $total / count($rows);
    }

    /** @return float|null average reviewed score across the type's assignments, or null when none exist */
    private function assignmentPercent(int $studentId, int $courseId, string $type): ?float
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT a.max_marks,
                        (SELECT MAX(s.marks_awarded) FROM lms_assignment_submissions s
                         WHERE s.assignment_id = a.id AND s.student_id = ? AND s.status = 'reviewed' AND s.marks_awarded IS NOT NULL) AS awarded
                 FROM lms_assignments a
                 WHERE a.course_id = ? AND a.assignment_type = ? AND a.status IN ('published', 'closed')"
            );
            $stmt->execute([$studentId, $courseId, $type]);
            $rows = $stmt->fetchAll();
        } catch (\PDOException $exception) {
            error_log('[COURSE GRADING] Assignment component failed (un-migrated assignment_type?): ' . $exception->getMessage());
            return null;
        }
        if ($rows === []) {
            return null;
        }
        $total = 0.0;
        $count = 0;
        foreach ($rows as $row) {
            $max = (float) $row['max_marks'];
            if ($max <= 0) {
                continue; // an un-scored rubric cannot contribute a share
            }
            $total += min(100.0, ((float) ($row['awarded'] ?? 0)) / $max * 100);
            $count++;
        }
        return $count > 0 ? $total / $count : 0.0;
    }

    /** @return float|null watched share across published lessons, or null when the course has no lessons */
    private function videoPercent(int $studentId, int $courseId): ?float
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT l.video_duration_seconds, lp.watched_seconds, lp.completed_at
                 FROM lms_lessons l
                 JOIN lms_course_modules cm ON cm.id = l.module_id
                 LEFT JOIN lms_lesson_progress lp ON lp.lesson_id = l.id AND lp.student_id = ?
                 WHERE cm.course_id = ? AND l.status = \'published\''
            );
            $stmt->execute([$studentId, $courseId]);
            $rows = $stmt->fetchAll();
        } catch (\PDOException $exception) {
            // Fall back to a completion-only share when the video columns are not imported yet.
            error_log('[COURSE GRADING] Video watched read failed, using completion ratio: ' . $exception->getMessage());
            return $this->videoCompletionOnly($studentId, $courseId);
        }
        if ($rows === []) {
            return null;
        }
        $total = 0.0;
        foreach ($rows as $row) {
            $duration = (int) ($row['video_duration_seconds'] ?? 0);
            if ($duration > 0) {
                $total += min(100.0, (float) ($row['watched_seconds'] ?? 0) / $duration * 100);
            } else {
                $total += $row['completed_at'] !== null ? 100.0 : 0.0;
            }
        }
        return $total / count($rows);
    }

    /** @return float|null completion ratio when watch columns are absent, or null with no lessons */
    private function videoCompletionOnly(int $studentId, int $courseId): ?float
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) AS total,
                        SUM(lp.completed_at IS NOT NULL) AS done
                 FROM lms_lessons l
                 JOIN lms_course_modules cm ON cm.id = l.module_id
                 LEFT JOIN lms_lesson_progress lp ON lp.lesson_id = l.id AND lp.student_id = ?
                 WHERE cm.course_id = ? AND l.status = \'published\''
            );
            $stmt->execute([$studentId, $courseId]);
            $row = $stmt->fetch();
        } catch (\PDOException $exception) {
            error_log('[COURSE GRADING] Video fallback failed: ' . $exception->getMessage());
            return null;
        }
        $total = (int) ($row['total'] ?? 0);
        if ($total < 1) {
            return null;
        }
        return round((int) ($row['done'] ?? 0) / $total * 100, 2);
    }

    /** Upgrade an active enrollment to completed once the pass bar and full lesson completion are met. */
    private function maybeMarkCompleted(int $studentId, int $courseId, float $overall): void
    {
        $threshold = $this->thresholds()['grade'];
        if ($overall < $threshold) {
            return;
        }
        try {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) AS total, SUM(lp.completed_at IS NOT NULL) AS done
                 FROM lms_lessons l
                 JOIN lms_course_modules cm ON cm.id = l.module_id
                 LEFT JOIN lms_lesson_progress lp ON lp.lesson_id = l.id AND lp.student_id = ?
                 WHERE cm.course_id = ? AND l.status = \'published\''
            );
            $stmt->execute([$studentId, $courseId]);
            $row = $stmt->fetch();
            if ((int) ($row['total'] ?? 0) < 1 || (int) ($row['done'] ?? 0) < (int) $row['total']) {
                return;
            }
            // Only ever lift a live enrollment; certified/revoked/completed rows are left alone.
            $this->db->prepare("UPDATE lms_student_course_access SET status = 'completed' WHERE student_id = ? AND course_id = ? AND status = 'active'")
                ->execute([$studentId, $courseId]);
        } catch (\PDOException $exception) {
            error_log('[COURSE GRADING] Completion check failed: ' . $exception->getMessage());
        }
    }

    /** @param array<int,string> $keys @return array<string,string> */
    private function settingsRows(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        try {
            $stmt = $this->db->prepare("SELECT setting_key, setting_value FROM lms_app_settings WHERE setting_key IN ($placeholders)");
            $stmt->execute($keys);
            $out = [];
            foreach ($stmt->fetchAll() as $row) {
                $out[(string) $row['setting_key']] = (string) $row['setting_value'];
            }
            return $out;
        } catch (\PDOException $exception) {
            error_log('[COURSE GRADING] Settings read failed: ' . $exception->getMessage());
            return [];
        }
    }
}
