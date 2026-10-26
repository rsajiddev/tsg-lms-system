<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\View;

/**
 * Dedicated workspace pages for signed-in students.
 *
 * The dashboard overview only previews a few rows per area. Each sidebar
 * entry now opens its own page holding the complete list, mirroring the way
 * every admin sidebar entry already maps to its own admin page.
 */
final class StudentPortalController
{
    public function courses(): void
    {
        $user = AuthMiddleware::requireRole('student');
        $db = Database::connection();
        $imageColumn = $this->courseImageColumnAvailable($db) ? 'c.image_path' : 'NULL AS image_path';
        $stmt = $db->prepare(
            "SELECT c.id, c.title, c.slug, c.category, c.level_label, c.duration_label, $imageColumn, sca.granted_at, sca.expires_at,
                    (SELECT COUNT(*) FROM course_modules cm JOIN lessons l ON l.module_id = cm.id
                     WHERE cm.course_id = c.id AND l.status = 'published') AS lesson_count,
                    (SELECT COUNT(*) FROM lesson_progress lp JOIN lessons l ON l.id = lp.lesson_id
                     JOIN course_modules cm ON cm.id = l.module_id
                     WHERE lp.student_id = sca.student_id AND cm.course_id = c.id AND lp.completed_at IS NOT NULL) AS completed_lessons
             FROM student_course_access sca
             JOIN courses c ON c.id = sca.course_id
             WHERE sca.student_id = ? AND sca.status = 'active' AND (sca.expires_at IS NULL OR sca.expires_at > UTC_TIMESTAMP())
             ORDER BY sca.granted_at DESC, c.title ASC"
        );
        $stmt->execute([(int) $user['id']]);

        View::render('student/courses', [
            'title' => 'My courses',
            'user' => $user,
            'courses' => $stmt->fetchAll(),
        ]);
    }

    public function payments(): void
    {
        $user = AuthMiddleware::requireRole('student');
        $db = Database::connection();

        $payments = $db->prepare(
            'SELECT p.id, p.amount, p.currency, p.status, p.transaction_reference, p.review_note,
                    p.payment_proof_path, p.receipt_number, p.receipt_issued_at, p.created_at, p.reviewed_at, c.title AS course_title
             FROM payments p
             JOIN courses c ON c.id = p.course_id
             WHERE p.student_id = ?
             ORDER BY p.created_at DESC, p.id DESC'
        );
        $payments->execute([(int) $user['id']]);

        View::render('student/payments', [
            'title' => 'Payments',
            'user' => $user,
            'payments' => $payments->fetchAll(),
            'pendingCount' => $this->count($db, "SELECT COUNT(*) FROM payments WHERE student_id = ? AND status = 'pending'", [(int) $user['id']]),
            'approvedCount' => $this->count($db, "SELECT COUNT(*) FROM payments WHERE student_id = ? AND status = 'approved'", [(int) $user['id']]),
            'rejectedCount' => $this->count($db, "SELECT COUNT(*) FROM payments WHERE student_id = ? AND status = 'rejected'", [(int) $user['id']]),
        ]);
    }

    public function quizzes(): void
    {
        $user = AuthMiddleware::requireRole('student');
        $studentId = (int) $user['id'];
        $db = Database::connection();

        $released = "AND (q.status = 'released' OR (q.status = 'scheduled' AND q.unlock_at IS NOT NULL AND q.unlock_at <= UTC_TIMESTAMP()))
                     AND (q.unlock_at IS NULL OR q.unlock_at <= UTC_TIMESTAMP())
                     AND (q.close_at IS NULL OR q.close_at > UTC_TIMESTAMP())";

        $quizzes = $db->prepare(
            "SELECT q.id, q.title, q.week_number, q.time_limit_seconds, q.question_count, q.attempt_limit,
                    q.unlock_at, q.close_at, c.id AS course_id, c.title AS course_title,
                    (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id = q.id AND qa.student_id = ?) AS used_attempts
             FROM quizzes q
             JOIN courses c ON c.id = q.course_id
             JOIN student_course_access sca ON sca.course_id = c.id AND sca.student_id = ? AND sca.status = 'active' AND (sca.expires_at IS NULL OR sca.expires_at > UTC_TIMESTAMP())
             WHERE 1=1 {$released}
             ORDER BY q.course_id ASC, q.week_number ASC, q.id DESC"
        );
        $quizzes->execute([$studentId, $studentId]);

        $attempts = $db->prepare(
            'SELECT qa.id, qa.status, qa.submitted_at, qa.percentage, qa.result, qa.score, qa.total_marks,
                    q.title AS quiz_title, q.week_number, c.title AS course_title
             FROM quiz_attempts qa
             JOIN quizzes q ON q.id = qa.quiz_id
             JOIN courses c ON c.id = q.course_id
             WHERE qa.student_id = ?
             ORDER BY COALESCE(qa.submitted_at, qa.started_at) DESC, qa.id DESC'
        );
        $attempts->execute([$studentId]);

        View::render('student/quizzes', [
            'title' => 'Quizzes and results',
            'user' => $user,
            'quizzes' => $quizzes->fetchAll(),
            'attempts' => $attempts->fetchAll(),
        ]);
    }

    public function assignments(): void
    {
        $user = AuthMiddleware::requireRole('student');
        $studentId = (int) $user['id'];

        $stmt = Database::connection()->prepare(
            "SELECT a.id, a.title, a.week_number, a.due_at, a.max_marks, a.status,
                    c.id AS course_id, c.title AS course_title,
                    s.id AS submission_id, s.status AS submission_status,
                    s.marks_awarded, s.feedback, s.submitted_at
             FROM assignments a
             JOIN courses c ON c.id = a.course_id
             JOIN student_course_access sca ON sca.course_id = c.id AND sca.student_id = ? AND sca.status = 'active' AND (sca.expires_at IS NULL OR sca.expires_at > UTC_TIMESTAMP())
             LEFT JOIN assignment_submissions s ON s.id = (
                 SELECT latest.id FROM assignment_submissions latest
                 WHERE latest.assignment_id = a.id AND latest.student_id = ?
                 ORDER BY latest.id DESC LIMIT 1)
             WHERE a.status IN ('published', 'closed')
             ORDER BY a.due_at IS NULL, a.due_at ASC, a.id DESC"
        );
        $stmt->execute([$studentId, $studentId]);

        View::render('student/assignments', [
            'title' => 'Assignments',
            'user' => $user,
            'assignments' => $stmt->fetchAll(),
        ]);
    }

    private function count(\PDO $db, string $sql, array $params): int
    {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    private function courseImageColumnAvailable(\PDO $db): bool
    {
        try {
            $db->query('SELECT image_path FROM courses LIMIT 0');
            return true;
        } catch (\PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1054) return false;
            throw $exception;
        }
    }
}
