<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\View;
use PDO;

final class DashboardController
{
    public function show(): void
    {
        $sessionUser = $_SESSION['user'] ?? null;
        if (!is_array($sessionUser) || !in_array($sessionUser['role'] ?? '', ['student', 'admin'], true)) {
            header('Location: ' . rtrim((require BASE_PATH . '/config/app.php')['url'], '/') . '/login');
            return;
        }

        // Refresh identity and role from the database on every protected request.
        $user = AuthMiddleware::requireRole((string) $sessionUser['role']);
        $db = Database::connection();
        $data = $user['role'] === 'admin'
            ? ['admin' => $this->adminOverview($db)]
            : ['student' => $this->studentOverview($db, (int) $user['id'])];

        View::render('dashboard/index', array_merge([
            'title' => ucfirst($user['role']) . ' Dashboard',
            'user' => $user,
        ], $data));
    }

    private function adminOverview(PDO $db): array
    {
        return [
            'studentCount' => $this->count($db, "SELECT COUNT(*) FROM users WHERE role = 'student'"),
            'publishedCourseCount' => $this->count($db, "SELECT COUNT(*) FROM courses WHERE visibility = 'published'"),
            'pendingPaymentCount' => $this->count($db, "SELECT COUNT(*) FROM payments WHERE status = 'pending'"),
            'awaitingQuizCount' => $this->count($db, "SELECT COUNT(*) FROM quizzes WHERE status IN ('draft', 'scheduled')"),
            'pendingReviewCount' => $this->count($db, "SELECT COUNT(*) FROM reviews WHERE status='pending'"),
            'recentPayments' => $db->query(
                'SELECT p.id, p.amount, p.currency, p.status, p.transaction_reference, p.created_at,
                        u.full_name AS student_name, c.title AS course_title
                 FROM payments p
                 JOIN users u ON u.id = p.student_id
                 JOIN courses c ON c.id = p.course_id
                 ORDER BY p.created_at DESC, p.id DESC LIMIT 6'
            )->fetchAll(),
            'recentAttempts' => $db->query(
                'SELECT qa.id, qa.status, qa.submitted_at, qa.percentage,
                        u.full_name AS student_name, q.title AS quiz_title, c.title AS course_title
                 FROM quiz_attempts qa
                 JOIN users u ON u.id = qa.student_id
                 JOIN quizzes q ON q.id = qa.quiz_id
                 JOIN courses c ON c.id = q.course_id
                 ORDER BY COALESCE(qa.submitted_at, qa.started_at) DESC, qa.id DESC LIMIT 6'
            )->fetchAll(),
        ];
    }

    private function studentOverview(PDO $db, int $studentId): array
    {
        $courses = $db->prepare(
            'SELECT c.id, c.title, c.slug, c.category, c.level_label, c.duration_label, sca.granted_at,
                    (SELECT COUNT(*) FROM course_modules cm JOIN lessons l ON l.module_id = cm.id
                     WHERE cm.course_id = c.id AND l.status = \'published\') AS lesson_count,
                    (SELECT COUNT(*) FROM lesson_progress lp JOIN lessons l ON l.id = lp.lesson_id
                     JOIN course_modules cm ON cm.id = l.module_id
                     WHERE lp.student_id = sca.student_id AND cm.course_id = c.id AND lp.completed_at IS NOT NULL) AS completed_lessons
             FROM student_course_access sca
             JOIN courses c ON c.id = sca.course_id
             WHERE sca.student_id = ? AND sca.status = \'active\' AND (sca.expires_at IS NULL OR sca.expires_at > UTC_TIMESTAMP())
             ORDER BY sca.granted_at DESC, c.title ASC LIMIT 6'
        );
        $courses->execute([$studentId]);

        return [
            'activeCourseCount' => $this->count($db, "SELECT COUNT(*) FROM student_course_access WHERE student_id = ? AND status = 'active' AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())", [$studentId]),
            'pendingPaymentCount' => $this->count($db, "SELECT COUNT(*) FROM payments WHERE student_id = ? AND status = 'pending'", [$studentId]),
            'completedLessonCount' => $this->count($db, 'SELECT COUNT(*) FROM lesson_progress WHERE student_id = ? AND completed_at IS NOT NULL', [$studentId]),
            'courses' => $courses->fetchAll(),
        ];
    }

    private function count(PDO $db, string $sql, array $params = []): int
    {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
}
