<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Core\Security;
use App\Core\View;
use App\Services\LessonMedia;
use App\Services\LessonProtection;
use App\Services\CourseGrading;
use App\Services\AccessPlan;
use PDO;
use RuntimeException;

final class LearningController
{
    public function course(): void
    {
        $student = AuthMiddleware::requireRole('student');
        $courseId = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT) ?: 0;
        $db = Database::connection();
        $planSelect = AccessPlan::columnStored($db, 'student_course_access.plan_code') ? ',a.plan_code' : ',NULL AS plan_code';
        $course = $db->prepare("SELECT c.id,c.title,c.category,c.duration_label,c.level_label,c.short_description,a.expires_at,a.status AS access_status$planSelect FROM courses c JOIN student_course_access a ON a.course_id=c.id WHERE c.id=? AND a.student_id=? AND a.status IN ('active','completed','certified') AND (a.expires_at IS NULL OR a.expires_at>UTC_TIMESTAMP()) LIMIT 1");
        $course->execute([$courseId, (int) $student['id']]);
        $courseRow = $course->fetch();
        if (!$courseRow) {
            http_response_code(404);
            View::render('errors/404', ['title' => 'Course unavailable', 'user' => $student]);
            return;
        }
        // The course page itself stays open for a certified enrolment - the grade, quizzes and the
        // certificate all live behind it - but when the completion lock is on the lesson videos are
        // hidden, so the outline is marked closed rather than 404. AccessibleLesson is what actually
        // refuses playback; this flag only decides what the page shows.
        $videosLocked = (string) ($courseRow['access_status'] ?? '') === 'certified'
            && (new LessonProtection($db))->lockOnCompletion();
        // Which lessons carry a class is listed only for the plan that may open one, so the outline
        // cannot hint at a live session a student has no claim on.
        $liveColumn = AccessPlan::columnStored($db, 'lessons.meeting_url');
        $liveAllowed = $liveColumn && AccessPlan::allowsLive($courseRow['plan_code'] ?? null);
        $liveFlag = $liveAllowed ? "(l.meeting_url IS NOT NULL AND l.meeting_url <> '')" : '0';
        // Whether this course holds any class at all. The Recorded Only note the course page prints
        // is switched on only when it does, so it never talks about a plan on a course that has no
        // live sessions, and it never names which lesson holds one - that stays inside the lesson
        // page of a student whose enrolment paid for it.
        $courseHasClasses = false;
        if ($liveColumn) {
            $probe = $db->prepare("SELECT 1 FROM lessons l JOIN course_modules cm ON cm.id=l.module_id WHERE cm.course_id=? AND l.status='published' AND l.meeting_url IS NOT NULL AND l.meeting_url <> '' LIMIT 1");
            $probe->execute([$courseId]);
            $courseHasClasses = (bool) $probe->fetchColumn();
        }
        $lessons = $db->prepare("SELECT cm.id AS module_id,cm.title AS module_title,cm.sort_order AS module_order,l.id AS lesson_id,l.title AS lesson_title,l.sort_order AS lesson_order,$liveFlag AS has_live,CASE WHEN lp.completed_at IS NULL THEN 0 ELSE 1 END AS completed FROM course_modules cm JOIN lessons l ON l.module_id=cm.id AND l.status='published' LEFT JOIN lesson_progress lp ON lp.lesson_id=l.id AND lp.student_id=? WHERE cm.course_id=? ORDER BY cm.sort_order,cm.id,l.sort_order,l.id");
        $lessons->execute([(int) $student['id'], $courseId]);
        $grading = new CourseGrading($db);
        $grade = $grading->refresh((int) $student['id'], $courseId);
        View::render('learning/course', ['title' => $courseRow['title'], 'user' => $student, 'course' => $courseRow, 'lessons' => $lessons->fetchAll(), 'liveAllowed' => $liveAllowed, 'liveNotice' => $courseHasClasses && !$liveAllowed, 'videosLocked' => $videosLocked, 'grade' => $grade, 'weights' => $grading->weights(), 'eligibility' => $grading->eligibility((int) $student['id'], $courseId)]);
    }

    public function lesson(): void
    {
        $student = AuthMiddleware::requireRole('student');
        $lessonId = filter_input(INPUT_GET, 'lesson_id', FILTER_VALIDATE_INT) ?: 0;
        $db = Database::connection();
        $lesson = $this->accessibleLesson($db, (int) $student['id'], $lessonId);
        if (!$lesson) {
            http_response_code(404);
            View::render('errors/404', ['title' => 'Lesson unavailable', 'user' => $student]);
            return;
        }
        // Watch state comes from the service, so the player and the completion rule read the same row.
        $state = (new LessonMedia($db))->state((int) $student['id'], $lessonId);
        // The watermark is computed server-side: a name and an Academy ID that the browser made up
        // would be worth nothing as evidence. The page still draws it even when the view log has not
        // been imported, because the mark costs nothing beyond this one call.
        $protection = new LessonProtection($db);
        // The live class gate is decided before the template runs. A Recorded Only student does not
        // get the address hidden by CSS - it is never in the response at all, so there is nothing on
        // the page to inspect. The plan arrives from the same query that fetched the lesson.
        $liveClass = AccessPlan::allowsLive($lesson['plan_code'] ?? null)
            && is_string($lesson['meeting_url'] ?? null) && $lesson['meeting_url'] !== ''
                ? ['url' => (string) $lesson['meeting_url'], 'at' => is_string($lesson['meeting_at'] ?? null) ? $lesson['meeting_at'] : null]
                : null;
        View::render('learning/lesson', [
            'title' => $lesson['lesson_title'],
            'user' => $student,
            'lesson' => $lesson,
            'media' => $state,
            'liveClass' => $liveClass,
            'watermark' => $protection->watermark((int) $student['id'], (string) $student['name']),
            'message' => $_SESSION['_flash'] ?? null,
            'error' => $_SESSION['_flash_error'] ?? null,
        ]);
        unset($_SESSION['_flash'], $_SESSION['_flash_error']);
    }

    /**
     * Player heartbeat: credits forward playhead movement and completes the lesson when the
     * watch-time rule is satisfied. Answers JSON for the player script in public/assets/js.
     */
    public function watch(): void
    {
        $student = AuthMiddleware::requireRole('student');
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            echo json_encode(['ok' => false, 'error' => 'session'], JSON_UNESCAPED_SLASHES);
            return;
        }
        $lessonId = filter_var($_POST['lesson_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $position = filter_var($_POST['position'] ?? null, FILTER_VALIDATE_INT);
        if ($position === false || $position < 0 || $position > 86400) {
            echo json_encode(['ok' => false, 'error' => 'position'], JSON_UNESCAPED_SLASHES);
            return;
        }
        $db = Database::connection();
        if (!$this->accessibleLesson($db, (int) $student['id'], $lessonId)) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'unavailable'], JSON_UNESCAPED_SLASHES);
            return;
        }
        $media = new LessonMedia($db);
        $state = $media->record((int) $student['id'], $lessonId, (int) $position);
        $payload = [
            'ok' => true,
            'watched' => (int) $state['watched'],
            'watched_label' => $state['watched_label'],
            'required' => (int) $state['required'],
            'required_label' => $state['required_label'],
            'duration' => $state['duration'],
            'duration_label' => $state['duration_label'],
            'ratio' => (float) $state['ratio'],
            'percent' => (int) $state['percent'],
            'completed' => (bool) $state['completed'],
            'just_completed' => (bool) ($state['just_completed'] ?? false),
            'rule_applies' => (bool) $state['rule_applies'],
        ];
        // The overlay's rotating trace code rides the heartbeat it is already making, so the mark on
        // screen cannot go stale during a long recording and no extra request is spent on it.
        $protection = new LessonProtection($db);
        if ($protection->watermarkEnabled()) {
            $payload['watermark'] = $protection->refresh((int) $student['id']);
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Player protection reports: a blocked fullscreen, a right-click, an unusually large jump.
     *
     * Answers JSON like watch(), and never touches watch time or completion. The event list is
     * fixed on the server, so a request can only name something the Academy already decided is
     * worth reading about; the two account-level events are filed by the session registry itself
     * and are refused here.
     */
    public function view(): void
    {
        $student = AuthMiddleware::requireRole('student');
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $refuse = static function (string $error): void {
            echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_SLASHES);
        };
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            $refuse('session');
            return;
        }
        $event = (string) ($_POST['event'] ?? '');
        $lessonId = filter_var($_POST['lesson_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        if (!in_array($event, LessonProtection::CLIENT_EVENTS, true) || $lessonId < 1) {
            $refuse('event');
            return;
        }
        $db = Database::connection();
        if (!$this->accessibleLesson($db, (int) $student['id'], $lessonId)) {
            http_response_code(404);
            $refuse('unavailable');
            return;
        }
        $protection = new LessonProtection($db);
        if (!$protection->available()) {
            // The player has no way to know the log is missing, and nothing here is worth failing a
            // lesson page over: answer as if it were recorded and move on.
            $refuse('unavailable');
            return;
        }
        $subjects = ['user:' . (int) $student['id']];
        try {
            (new RateLimiter($db))->assertAllowed('lesson_view_event', $subjects, 40, 300);
        } catch (RuntimeException $exception) {
            $refuse('slow');
            return;
        }
        (new RateLimiter($db))->record('lesson_view_event', $subjects);
        $detail = mb_substr(trim((string) ($_POST['detail'] ?? '')), 0, 120);
        $protection->log((int) $student['id'], $lessonId, $event, $detail === '' ? null : $detail);
        echo json_encode(['ok' => true], JSON_UNESCAPED_SLASHES);
    }

    public function complete(): void
    {
        $student = AuthMiddleware::requireRole('student');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) throw new RuntimeException('Your session expired. Refresh and try again.');
        $lessonId = filter_var($_POST['lesson_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $db = Database::connection();
        $lesson = $this->accessibleLesson($db, (int) $student['id'], $lessonId);
        if (!$lesson) {
            http_response_code(404);
            View::render('errors/404', ['title' => 'Lesson unavailable', 'user' => $student]);
            return;
        }
        $media = new LessonMedia($db);
        $blocked = $media->completionBlock((int) $student['id'], $lessonId);
        if ($blocked !== null) {
            $_SESSION['_flash_error'] = $blocked;
            $this->redirect('/learning/lesson?lesson_id=' . $lessonId);
            return;
        }
        $stmt = $db->prepare('INSERT INTO lesson_progress(student_id,lesson_id,completed_at) VALUES(?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE completed_at=COALESCE(completed_at,VALUES(completed_at))');
        $stmt->execute([(int) $student['id'], $lessonId]);
        // Keep the course grade matrix current; grading must never block the completion itself.
        try {
            (new CourseGrading($db))->recompute((int) $student['id'], (int) $lesson['course_id']);
        } catch (\Throwable $exception) {
            error_log('[COURSE GRADING] Completion hook skipped: ' . $exception->getMessage());
        }
        $this->redirect('/learning/lesson?lesson_id=' . $lessonId . '&completed=1');
    }

    private function accessibleLesson(PDO $db, int $studentId, int $lessonId): ?array
    {
        // The class link and the enrolment plan travel in this one query, because the page must never
        // fetch an address and then decide whether to hide it. Both pieces are composed conditionally:
        // a database that has not been re-imported has neither column, and the lesson still opens - it
        // simply reports no plan, which the gate reads as Recorded Only and keeps the link closed.
        $liveColumns = AccessPlan::columnStored($db, 'lessons.meeting_url') && AccessPlan::columnStored($db, 'lessons.meeting_at')
            ? 'l.meeting_url,l.meeting_at'
            : 'NULL AS meeting_url,NULL AS meeting_at';
        $planColumn = AccessPlan::columnStored($db, 'student_course_access.plan_code') ? 'a.plan_code' : 'NULL AS plan_code';
        // The completion lock (proposal 5.7) hides recordings from an enrolment whose certificate is
        // already issued, but only when the Academy has switched it on. The status list is composed
        // here rather than hard-coded, so playback and the heartbeat gate below fail the same way;
        // off by default, so a certified student keeps watching until expires_at as they always did.
        $videoStatuses = (new LessonProtection($db))->lockOnCompletion()
            ? "('active','completed')"
            : "('active','completed','certified')";
        $stmt = $db->prepare("SELECT l.id AS lesson_id,l.title AS lesson_title,l.body,cm.title AS module_title,c.id AS course_id,c.title AS course_title,$liveColumns,$planColumn,EXISTS(SELECT 1 FROM lesson_progress lp WHERE lp.student_id=? AND lp.lesson_id=l.id AND lp.completed_at IS NOT NULL) AS completed FROM lessons l JOIN course_modules cm ON cm.id=l.module_id JOIN courses c ON c.id=cm.course_id JOIN student_course_access a ON a.course_id=c.id AND a.student_id=? AND a.status IN $videoStatuses AND (a.expires_at IS NULL OR a.expires_at>UTC_TIMESTAMP()) WHERE l.id=? AND l.status='published' LIMIT 1");
        $stmt->execute([$studentId, $studentId, $lessonId]);
        return $stmt->fetch() ?: null;
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim((require BASE_PATH . '/config/app.php')['url'], '/') . $path);
        exit;
    }
}
