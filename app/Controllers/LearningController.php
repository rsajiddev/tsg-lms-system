<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\Security;
use App\Core\View;
use PDO;
use RuntimeException;

final class LearningController
{
    public function course(): void
    {
        $student = AuthMiddleware::requireRole('student');
        $courseId = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT) ?: 0;
        $db = Database::connection();
        $course = $db->prepare("SELECT c.id,c.title,c.category,c.duration_label,c.level_label,c.short_description FROM courses c JOIN student_course_access a ON a.course_id=c.id WHERE c.id=? AND a.student_id=? AND a.status='active' LIMIT 1");
        $course->execute([$courseId, (int) $student['id']]);
        $courseRow = $course->fetch();
        if (!$courseRow) {
            http_response_code(404);
            View::render('errors/404', ['title' => 'Course unavailable', 'user' => $student]);
            return;
        }
        $lessons = $db->prepare("SELECT cm.id AS module_id,cm.title AS module_title,cm.sort_order AS module_order,l.id AS lesson_id,l.title AS lesson_title,l.sort_order AS lesson_order,CASE WHEN lp.completed_at IS NULL THEN 0 ELSE 1 END AS completed FROM course_modules cm JOIN lessons l ON l.module_id=cm.id AND l.status='published' LEFT JOIN lesson_progress lp ON lp.lesson_id=l.id AND lp.student_id=? WHERE cm.course_id=? ORDER BY cm.sort_order,cm.id,l.sort_order,l.id");
        $lessons->execute([(int) $student['id'], $courseId]);
        View::render('learning/course', ['title' => $courseRow['title'], 'user' => $student, 'course' => $courseRow, 'lessons' => $lessons->fetchAll()]);
    }

    public function lesson(): void
    {
        $student = AuthMiddleware::requireRole('student');
        $lessonId = filter_input(INPUT_GET, 'lesson_id', FILTER_VALIDATE_INT) ?: 0;
        $lesson = $this->accessibleLesson(Database::connection(), (int) $student['id'], $lessonId);
        if (!$lesson) {
            http_response_code(404);
            View::render('errors/404', ['title' => 'Lesson unavailable', 'user' => $student]);
            return;
        }
        View::render('learning/lesson', ['title' => $lesson['lesson_title'], 'user' => $student, 'lesson' => $lesson]);
    }

    public function complete(): void
    {
        $student = AuthMiddleware::requireRole('student');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) throw new RuntimeException('Your session expired. Refresh and try again.');
        $lessonId = filter_var($_POST['lesson_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $db = Database::connection();
        if (!$this->accessibleLesson($db, (int) $student['id'], $lessonId)) {
            http_response_code(404);
            View::render('errors/404', ['title' => 'Lesson unavailable', 'user' => $student]);
            return;
        }
        $stmt = $db->prepare('INSERT INTO lesson_progress(student_id,lesson_id,completed_at) VALUES(?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE completed_at=COALESCE(completed_at,VALUES(completed_at))');
        $stmt->execute([(int) $student['id'], $lessonId]);
        $this->redirect('/learning/lesson?lesson_id=' . $lessonId . '&completed=1');
    }

    private function accessibleLesson(PDO $db, int $studentId, int $lessonId): ?array
    {
        $stmt = $db->prepare("SELECT l.id AS lesson_id,l.title AS lesson_title,l.body,cm.title AS module_title,c.id AS course_id,c.title AS course_title,EXISTS(SELECT 1 FROM lesson_progress lp WHERE lp.student_id=? AND lp.lesson_id=l.id AND lp.completed_at IS NOT NULL) AS completed FROM lessons l JOIN course_modules cm ON cm.id=l.module_id JOIN courses c ON c.id=cm.course_id JOIN student_course_access a ON a.course_id=c.id AND a.student_id=? AND a.status='active' WHERE l.id=? AND l.status='published' LIMIT 1");
        $stmt->execute([$studentId, $studentId, $lessonId]);
        return $stmt->fetch() ?: null;
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim((require BASE_PATH . '/config/app.php')['url'], '/') . $path);
        exit;
    }
}
