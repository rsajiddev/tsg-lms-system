<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

/**
 * The learner-facing progress picture behind the Certificate & Progress page and its PDF report.
 *
 * CourseGrading already owns the single weighted score used for certificates. This service does not
 * re-invent that number: it reuses it (and the same thresholds and eligibility gate) and surrounds it
 * with the breakdown a student asks to see - module-by-module lesson progress, watched-versus-remaining
 * videos, quiz attempts and scores, assignment status, and the exact certificate requirements still open.
 *
 * Every query is fail-open in the same spirit as CourseGrading: a schema that has not been re-imported
 * yet (no video watch columns, no quiz_type, no assignment_type) simply degrades that one figure instead
 * of breaking the whole page. A student with no courses gets an empty, well-shaped summary rather than
 * an error.
 */
final class StudentProgress
{
    private PDO $db;
    private CourseGrading $grading;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        $this->grading = new CourseGrading($this->db);
    }

    /**
     * @return array{
     *     student: array{name:string,id:string,email:string},
     *     overall: array{enrolled:int,completed:int,in_progress:int,completion_percent:float,weighted_percent:float,certificates_issued:int,courses_eligible:int},
     *     criteria: array{grade:int,attendance:int},
     *     courses: array<int, array<string, mixed>>
     * }
     */
    public function summary(int $studentId): array
    {
        $student = $this->student($studentId);
        $criteria = $this->grading->thresholds();

        $courses = $this->enrolledCourses($studentId);
        $detail = [];
        $totalLessons = 0;
        $doneLessons = 0;
        $weightedSum = 0.0;
        $weightedCount = 0;
        $issued = 0;
        $eligibleCourses = 0;
        $completedCourses = 0;

        foreach ($courses as $course) {
            $row = $this->courseDetail($studentId, (int) $course['course_id'], $course);
            $detail[] = $row;

            $totalLessons += $row['video']['total'];
            $doneLessons += $row['video']['watched'];
            if ($row['grade']['overall_percent'] !== null) {
                $weightedSum += (float) $row['grade']['overall_percent'];
                $weightedCount++;
            }
            if ($row['certificate']['status'] === 'issued') {
                $issued++;
            }
            if ($row['eligibility']['ok']) {
                $eligibleCourses++;
            }
            if ($row['state'] === 'completed') {
                $completedCourses++;
            }
        }

        $enrolled = count($detail);

        return [
            'student' => $student,
            'overall' => [
                'enrolled' => $enrolled,
                'completed' => $completedCourses,
                'in_progress' => max(0, $enrolled - $completedCourses),
                'completion_percent' => $totalLessons > 0 ? round($doneLessons / $totalLessons * 100, 1) : 0.0,
                'weighted_percent' => $weightedCount > 0 ? round($weightedSum / $weightedCount, 1) : 0.0,
                'certificates_issued' => $issued,
                'courses_eligible' => $eligibleCourses,
            ],
            'criteria' => $criteria,
            'courses' => $detail,
        ];
    }

    /* ------------------------------------------------------------------ building blocks */

    /** @return array{name:string,id:string,email:string} */
    private function student(int $studentId): array
    {
        $reference = $this->columnExists('users', 'account_reference') ? 'account_reference' : 'NULL';
        try {
            $stmt = $this->db->prepare("SELECT full_name, email, $reference AS account_reference FROM lms_users WHERE id = ? LIMIT 1");
            $stmt->execute([$studentId]);
            $row = $stmt->fetch();
        } catch (\PDOException $exception) {
            error_log('[STUDENT PROGRESS] Student read failed: ' . $exception->getMessage());
            $row = false;
        }
        return [
            'name' => (string) ($row['full_name'] ?? 'Student'),
            'id' => trim((string) ($row['account_reference'] ?? '')),
            'email' => (string) ($row['email'] ?? ''),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function enrolledCourses(int $studentId): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT c.id AS course_id, c.title, c.level_label, c.duration_label,
                        sca.status AS access_status, sca.granted_at, sca.expires_at
                 FROM lms_student_course_access sca
                 JOIN lms_courses c ON c.id = sca.course_id
                 WHERE sca.student_id = ? AND sca.status IN ('active','completed','certified')
                       AND (sca.expires_at IS NULL OR sca.expires_at > UTC_TIMESTAMP())
                 ORDER BY c.title ASC"
            );
            $stmt->execute([$studentId]);
            return array_values(array_filter((array) $stmt->fetchAll(), 'is_array'));
        } catch (\PDOException $exception) {
            error_log('[STUDENT PROGRESS] Course list failed: ' . $exception->getMessage());
            return [];
        }
    }

    /** @return array<string, mixed> */
    private function courseDetail(int $studentId, int $courseId, array $course): array
    {
        $modules = $this->modules($studentId, $courseId);
        $video = $this->videoTotals($modules);
        $quizzes = $this->quizzes($studentId, $courseId);
        $assignments = $this->assignments($studentId, $courseId);
        $grade = $this->grade($studentId, $courseId);
        $certificate = $this->certificate($studentId, $courseId);

        // The gate reuses the exact certificate rules the request path applies, so the page and the
        // request button can never disagree about whether a certificate is available.
        $gate = $this->grading->eligibility($studentId, $courseId);

        $lessonCount = $video['total'];
        $allLessonsDone = $lessonCount > 0 && $video['watched'] >= $lessonCount;
        $accessStatus = (string) ($course['access_status'] ?? 'active');
        $state = ($accessStatus === 'completed' || $accessStatus === 'certified' || $allLessonsDone) ? 'completed' : 'in-progress';

        return [
            'course_id' => $courseId,
            'title' => (string) ($course['title'] ?? ''),
            'level_label' => (string) ($course['level_label'] ?? ''),
            'duration_label' => (string) ($course['duration_label'] ?? ''),
            'access_status' => $accessStatus,
            'granted_at' => $course['granted_at'] ?? null,
            'expires_at' => $course['expires_at'] ?? null,
            'state' => $state,
            'modules' => $modules,
            'video' => $video,
            'quizzes' => $quizzes,
            'assignments' => $assignments,
            'grade' => $grade,
            'certificate' => $certificate,
            'eligibility' => $this->eligibility($gate, $video, $quizzes, $allLessonsDone),
        ];
    }

    /**
     * Module-wise lesson progress. A lesson with a video counts by watched share; one without a video
     * counts as complete once it is marked done - the same rule CourseGrading uses for its video score.
     *
     * @return array<int, array<string, mixed>>
     */
    private function modules(int $studentId, int $courseId): array
    {
        $watch = $this->columnExists('lesson_progress', 'watched_seconds') && $this->columnExists('lessons', 'video_duration_seconds');
        $watchColumns = $watch
            ? 'l.video_duration_seconds, COALESCE(lp.watched_seconds, 0) AS watched_seconds'
            : 'NULL AS video_duration_seconds, 0 AS watched_seconds';
        try {
            $stmt = $this->db->prepare(
                "SELECT cm.id AS module_id, cm.title AS module_title, cm.sort_order,
                        l.id AS lesson_id, lp.completed_at, $watchColumns
                 FROM lms_course_modules cm
                 LEFT JOIN lms_lessons l ON l.module_id = cm.id AND l.status = 'published'
                 LEFT JOIN lms_lesson_progress lp ON lp.lesson_id = l.id AND lp.student_id = ?
                 WHERE cm.course_id = ?
                 ORDER BY cm.sort_order ASC, cm.id ASC, l.sort_order ASC, l.id ASC"
            );
            $stmt->execute([$studentId, $courseId]);
            $rows = array_filter((array) $stmt->fetchAll(), 'is_array');
        } catch (\PDOException $exception) {
            error_log('[STUDENT PROGRESS] Module read failed: ' . $exception->getMessage());
            return [];
        }

        $grouped = [];
        foreach ($rows as $row) {
            $mid = (int) $row['module_id'];
            if (!isset($grouped[$mid])) {
                $grouped[$mid] = [
                    'module_id' => $mid,
                    'title' => (string) $row['module_title'],
                    'lessons' => 0,
                    'watched' => 0,
                    'percent' => 0.0,
                    '_sum' => 0.0,
                ];
            }
            if ($row['lesson_id'] === null) {
                continue; // a module still without any published lesson
            }
            $duration = (int) ($row['video_duration_seconds'] ?? 0);
            $completed = $row['completed_at'] !== null;
            if ($duration > 0) {
                $share = min(100.0, ((int) $row['watched_seconds']) / $duration * 100);
            } else {
                $share = $completed ? 100.0 : 0.0;
            }
            $grouped[$mid]['lessons']++;
            $grouped[$mid]['_sum'] += $share;
            if ($completed || $share >= 95.0) {
                $grouped[$mid]['watched']++;
            }
        }

        $out = [];
        foreach ($grouped as $module) {
            $module['percent'] = $module['lessons'] > 0 ? round($module['_sum'] / $module['lessons'], 1) : 0.0;
            unset($module['_sum']);
            $out[] = $module;
        }
        return $out;
    }

    /** @return array{total:int,watched:int,remaining:int,percent:float} */
    private function videoTotals(array $modules): array
    {
        $total = 0;
        $watched = 0;
        foreach ($modules as $module) {
            $total += (int) $module['lessons'];
            $watched += (int) $module['watched'];
        }
        return [
            'total' => $total,
            'watched' => $watched,
            'remaining' => max(0, $total - $watched),
            'percent' => $total > 0 ? round($watched / $total * 100, 1) : 0.0,
        ];
    }

    /**
     * Quiz performance for one course: attempts, scores and pass status. A course whose quiz_type column
     * is not imported yet is treated as holding only lesson quizzes, which keeps the totals correct.
     *
     * @return array<string, mixed>
     */
    private function quizzes(int $studentId, int $courseId): array
    {
        $typeColumn = $this->columnExists('quizzes', 'quiz_type');
        $typeSelect = $typeColumn ? 'q.quiz_type' : "'lesson' AS quiz_type";
        try {
            $stmt = $this->db->prepare(
                "SELECT q.id, q.title, $typeSelect, q.pass_mark_percent,
                        (SELECT MAX(qa.percentage) FROM lms_quiz_attempts qa
                         WHERE qa.quiz_id = q.id AND qa.student_id = ? AND qa.status <> 'in_progress' AND qa.percentage IS NOT NULL) AS best,
                        (SELECT COUNT(*) FROM lms_quiz_attempts qa
                         WHERE qa.quiz_id = q.id AND qa.student_id = ? AND qa.status <> 'in_progress') AS attempts
                 FROM lms_quizzes q
                 WHERE q.course_id = ? AND q.status IN ('released','closed')
                 ORDER BY q.week_number ASC, q.id ASC"
            );
            $stmt->execute([$studentId, $studentId, $courseId]);
            $rows = array_filter((array) $stmt->fetchAll(), 'is_array');
        } catch (\PDOException $exception) {
            error_log('[STUDENT PROGRESS] Quiz read failed: ' . $exception->getMessage());
            return $this->emptyQuizzes();
        }

        $attempted = 0;
        $passed = 0;
        $scoreSum = 0.0;
        $passSum = 0.0;
        $finalBest = null;
        $finalPass = null;
        $list = [];
        foreach ($rows as $row) {
            $best = $row['best'] === null ? null : (float) $row['best'];
            $passMark = (float) ($row['pass_mark_percent'] ?? 50);
            $isAttempted = $best !== null;
            $isPassed = $isAttempted && $best >= $passMark;
            if ($isAttempted) {
                $attempted++;
                $scoreSum += $best;
                $passSum += $passMark;
            }
            if ($isPassed) {
                $passed++;
            }
            if ((string) $row['quiz_type'] === 'final') {
                $finalBest = $finalBest === null ? $best : max($finalBest, $best);
                $finalPass = $best !== null && $best >= $passMark;
            }
            $list[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'type' => (string) $row['quiz_type'],
                'best' => $best,
                'pass_mark' => round($passMark, 1),
                'attempted' => $isAttempted,
                'passed' => $isPassed,
            ];
        }

        $total = count($rows);
        return [
            'total' => $total,
            'attempted' => $attempted,
            'pending' => max(0, $total - $attempted),
            'passed' => $passed,
            'obtained_percent' => $attempted > 0 ? round($scoreSum / $attempted, 1) : 0.0,
            'pass_percent' => $attempted > 0 ? round($passSum / $attempted, 1) : 0.0,
            'final_best' => $finalBest,
            'final_passed' => $finalPass,
            'items' => $list,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyQuizzes(): array
    {
        return [
            'total' => 0, 'attempted' => 0, 'pending' => 0, 'passed' => 0,
            'obtained_percent' => 0.0, 'pass_percent' => 0.0,
            'final_best' => null, 'final_passed' => null, 'items' => [],
        ];
    }

    /**
     * Assignment status for one course. Without the assignment_type column imported, every published
     * assignment is counted without a category split.
     *
     * @return array<string, mixed>
     */
    private function assignments(int $studentId, int $courseId): array
    {
        $typeColumn = $this->columnExists('assignments', 'assignment_type');
        $typeSelect = $typeColumn ? 'a.assignment_type' : "'exercise' AS assignment_type";
        try {
            $stmt = $this->db->prepare(
                "SELECT a.id, a.title, $typeSelect,
                        (SELECT MAX(s.marks_awarded) FROM lms_assignment_submissions s
                         WHERE s.assignment_id = a.id AND s.student_id = ? AND s.status = 'reviewed' AND s.marks_awarded IS NOT NULL) AS awarded,
                        (SELECT COUNT(*) FROM lms_assignment_submissions s
                         WHERE s.assignment_id = a.id AND s.student_id = ?) AS submissions
                 FROM lms_assignments a
                 WHERE a.course_id = ? AND a.status IN ('published','closed')
                 ORDER BY a.week_number ASC, a.id ASC"
            );
            $stmt->execute([$studentId, $studentId, $courseId]);
            $rows = array_filter((array) $stmt->fetchAll(), 'is_array');
        } catch (\PDOException $exception) {
            error_log('[STUDENT PROGRESS] Assignment read failed: ' . $exception->getMessage());
            return ['total' => 0, 'submitted' => 0, 'reviewed' => 0, 'pending' => 0, 'items' => []];
        }

        $submitted = 0;
        $reviewed = 0;
        $items = [];
        foreach ($rows as $row) {
            $hasSubmission = (int) $row['submissions'] > 0;
            $isReviewed = $row['awarded'] !== null;
            if ($hasSubmission) {
                $submitted++;
            }
            if ($isReviewed) {
                $reviewed++;
            }
            $items[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'type' => (string) $row['assignment_type'],
                'submitted' => $hasSubmission,
                'reviewed' => $isReviewed,
            ];
        }
        $total = count($rows);
        return [
            'total' => $total,
            'submitted' => $submitted,
            'reviewed' => $reviewed,
            'pending' => max(0, $total - $submitted),
            'items' => $items,
        ];
    }

    /** @return array{overall_percent:?float,letter:string} */
    private function grade(int $studentId, int $courseId): array
    {
        $stored = $this->grading->forStudentCourse($studentId, $courseId);
        if ($stored === null) {
            return ['overall_percent' => null, 'letter' => '—'];
        }
        return [
            'overall_percent' => $stored['overall_pct'] === null ? null : (float) $stored['overall_pct'],
            'letter' => (string) ($stored['letter_grade'] ?? '—'),
        ];
    }

    /** @return array{status:string,id:string,expires_at:?string,format:?string,revoked_reason:string} */
    private function certificate(int $studentId, int $courseId): array
    {
        if (!$this->tableExists('certificates')) {
            return ['status' => '', 'id' => '', 'expires_at' => null, 'format' => null, 'revoked_reason' => ''];
        }
        $fileType = $this->columnExists('certificates', 'file_type') ? 'file_type' : 'NULL AS file_type';
        try {
            $stmt = $this->db->prepare(
                "SELECT status, certificate_id, expires_at, file_path, revoked_reason, $fileType
                 FROM lms_certificates WHERE student_id = ? AND course_id = ? LIMIT 1"
            );
            $stmt->execute([$studentId, $courseId]);
            $row = $stmt->fetch();
        } catch (\PDOException $exception) {
            error_log('[STUDENT PROGRESS] Certificate read failed: ' . $exception->getMessage());
            $row = false;
        }
        $format = null;
        if (is_array($row)) {
            $declared = strtolower(trim((string) ($row['file_type'] ?? '')));
            if ($declared === '') {
                $declared = strtolower((string) pathinfo((string) ($row['file_path'] ?? ''), PATHINFO_EXTENSION));
            }
            $format = $declared === 'pdf' ? 'PDF' : ($declared === 'jpg' ? 'JPG' : null);
        }
        return [
            'status' => (string) ($row['status'] ?? ''),
            'id' => (string) ($row['certificate_id'] ?? ''),
            'expires_at' => $row['expires_at'] ?? null,
            'format' => $format,
            'revoked_reason' => (string) ($row['revoked_reason'] ?? ''),
        ];
    }

    /**
     * The certificate requirements, shown the way the request gate actually measures them so a student
     * always reads the same bars an admin enforces.
     *
     * @param array{ok:bool,overall:float,letter:string,attendance:?float,attendanceRequired:bool,minGrade:int,minAttendance:int,missing:array<int,string>} $gate
     * @return array<string, mixed>
     */
    private function eligibility(array $gate, array $video, array $quizzes, bool $allLessonsDone): array
    {
        $requirements = [];
        $requirements[] = [
            'label' => 'Watch every course video',
            'met' => $video['total'] > 0 && $video['remaining'] === 0,
            'detail' => $video['total'] > 0
                ? sprintf('%d of %d watched', $video['watched'], $video['total'])
                : 'No lessons published yet',
        ];
        $requirements[] = [
            'label' => 'Complete every required module',
            'met' => $allLessonsDone,
            'detail' => $allLessonsDone ? 'All lessons complete' : sprintf('%d lesson(s) remaining', $video['remaining']),
        ];
        $requirements[] = [
            'label' => 'Attempt every required quiz',
            'met' => $quizzes['total'] > 0 ? $quizzes['pending'] === 0 : true,
            'detail' => $quizzes['total'] > 0
                ? sprintf('%d of %d attempted', $quizzes['attempted'], $quizzes['total'])
                : 'This course has no quizzes',
        ];
        $requirements[] = [
            'label' => sprintf('Achieve at least %d%% overall grade', $gate['minGrade']),
            'met' => $gate['overall'] >= $gate['minGrade'],
            'detail' => sprintf('You are at %s%%', $this->num($gate['overall'])),
        ];
        if ($gate['attendance'] !== null) {
            $requirements[] = [
                'label' => sprintf('Keep attendance at %d%% or above', $gate['minAttendance']),
                'met' => $gate['attendance'] >= $gate['minAttendance'],
                'detail' => sprintf('You are at %s%%', $this->num($gate['attendance'])),
            ];
        }
        if ($quizzes['final_best'] !== null || $quizzes['final_passed'] !== null) {
            $requirements[] = [
                'label' => 'Pass the final assessment',
                'met' => $quizzes['final_passed'] === true,
                'detail' => $quizzes['final_best'] === null
                    ? 'Not attempted yet'
                    : sprintf('Best score %s%%', $this->num((float) $quizzes['final_best'])),
            ];
        }

        return [
            'ok' => $gate['ok'],
            'overall' => $gate['overall'],
            'letter' => $gate['letter'],
            'attendance' => $gate['attendance'],
            'min_grade' => $gate['minGrade'],
            'min_attendance' => $gate['minAttendance'],
            'attendance_required' => $gate['attendanceRequired'],
            'missing' => $gate['missing'],
            'requirements' => $requirements,
            'remaining' => count(array_filter($requirements, static fn(array $r): bool => !$r['met'])),
        ];
    }

    /* ------------------------------------------------------------------ small helpers */

    private function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }

    /** @var array<string,bool> */
    private array $columnCache = [];

    private function columnExists(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (isset($this->columnCache[$key])) {
            return $this->columnCache[$key];
        }
        try {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $stmt->execute([$table, $column]);
            return $this->columnCache[$key] = ((int) $stmt->fetchColumn() > 0);
        } catch (\PDOException $exception) {
            return $this->columnCache[$key] = false;
        }
    }

    /** @var array<string,bool> */
    private array $tableCache = [];

    private function tableExists(string $table): bool
    {
        if (isset($this->tableCache[$table])) {
            return $this->tableCache[$table];
        }
        try {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $stmt->execute([$table]);
            return $this->tableCache[$table] = ((int) $stmt->fetchColumn() > 0);
        } catch (\PDOException $exception) {
            return $this->tableCache[$table] = false;
        }
    }
}
