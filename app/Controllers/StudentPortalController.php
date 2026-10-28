<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\View;
use App\Services\LessonMedia;

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
        // Certificates are optional schema on older imports, so degrade to "not installed" instead of failing the page.
        $certificatesAvailable = $this->certificateTableAvailable($db);
        $certificateColumns = $certificatesAvailable
            ? 'cert.status AS certificate_status, cert.certificate_id, cert.admin_remarks, cert.expires_at AS certificate_expires_at'
            : 'NULL AS certificate_status, NULL AS certificate_id, NULL AS admin_remarks, NULL AS certificate_expires_at';
        $certificateJoin = $certificatesAvailable
            ? 'LEFT JOIN certificates cert ON cert.student_id = sca.student_id AND cert.course_id = c.id'
            : '';
        $stmt = $db->prepare(
            "SELECT c.id, c.title, c.slug, c.category, c.level_label, c.duration_label, $imageColumn, sca.granted_at, sca.expires_at, $certificateColumns,
                    (SELECT COUNT(*) FROM course_modules cm JOIN lessons l ON l.module_id = cm.id
                     WHERE cm.course_id = c.id AND l.status = 'published') AS lesson_count,
                    (SELECT COUNT(*) FROM lesson_progress lp JOIN lessons l ON l.id = lp.lesson_id
                     JOIN course_modules cm ON cm.id = l.module_id
                     WHERE lp.student_id = sca.student_id AND cm.course_id = c.id AND lp.completed_at IS NOT NULL) AS completed_lessons
             FROM student_course_access sca
             JOIN courses c ON c.id = sca.course_id
             $certificateJoin
             WHERE sca.student_id = ? AND sca.status = 'active' AND (sca.expires_at IS NULL OR sca.expires_at > UTC_TIMESTAMP())
             ORDER BY sca.granted_at DESC, c.title ASC"
        );
        $stmt->execute([(int) $user['id']]);

        View::render('student/courses', [
            'title' => 'My courses',
            'user' => $user,
            'courses' => $stmt->fetchAll(),
            'certificatesAvailable' => $certificatesAvailable,
            'message' => $_SESSION['_flash'] ?? null,
            'error' => $_SESSION['_flash_error'] ?? null,
        ]);
        unset($_SESSION['_flash'], $_SESSION['_flash_error']);
    }

    /**
     * Every published lesson in the student's active courses, on one filterable page.
     *
     * The course page only shows one curriculum at a time, so comparing progress across
     * several courses meant opening each of them. This list also surfaces how much of a
     * lesson video the student has actually watched.
     */
    public function lessons(): void
    {
        $user = AuthMiddleware::requireRole('student');
        $db = Database::connection();
        $media = new LessonMedia($db);
        // The video and watch columns ship in the same schema file, so one flag decides
        // whether this page may talk about watch progress at all; without them the list works.
        $watchAvailable = $media->available();
        $studentId = (int) $user['id'];
        $search = substr(trim((string) ($_GET['q'] ?? '')), 0, 120);
        $courseId = max(0, (int) ($_GET['course_id'] ?? 0));
        $status = (string) ($_GET['status'] ?? 'all');
        if (!in_array($status, ['all', 'completed', 'in-progress', 'not-started'], true)) {
            $status = 'all';
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 25;

        $access = "sca.student_id = ? AND sca.status = 'active' AND (sca.expires_at IS NULL OR sca.expires_at > UTC_TIMESTAMP())";
        $touched = $watchAvailable ? 'COALESCE(lp.watched_seconds, 0) > 0' : 'lp.id IS NOT NULL';
        $conditions = ["l.status = 'published'", $access];
        $params = [$studentId];
        if ($search !== '') {
            $conditions[] = '(l.title LIKE ? OR cm.title LIKE ? OR c.title LIKE ?)';
            $needle = '%' . $search . '%';
            array_push($params, $needle, $needle, $needle);
        }
        if ($courseId > 0) {
            $conditions[] = 'c.id = ?';
            $params[] = $courseId;
        }
        if ($status === 'completed') {
            $conditions[] = 'lp.completed_at IS NOT NULL';
        } elseif ($status === 'in-progress') {
            $conditions[] = "(lp.completed_at IS NULL AND $touched)";
        } elseif ($status === 'not-started') {
            $conditions[] = "(lp.id IS NULL OR (lp.completed_at IS NULL AND NOT $touched))";
        }
        $where = ' WHERE ' . implode(' AND ', $conditions);
        $joins = "FROM lessons l
             JOIN course_modules cm ON cm.id = l.module_id
             JOIN courses c ON c.id = cm.course_id
             JOIN student_course_access sca ON sca.course_id = c.id
             LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.student_id = sca.student_id";

        $count = $db->prepare("SELECT COUNT(*) $joins$where");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        // The summary ignores the status filter so it always describes the whole enrolment.
        $summaryStmt = $db->prepare("SELECT COUNT(*) AS total,
                    COALESCE(SUM(lp.completed_at IS NOT NULL), 0) AS completed,
                    COALESCE(SUM(lp.completed_at IS NULL AND $touched), 0) AS in_progress
             $joins
             WHERE l.status = 'published' AND $access");
        $summaryStmt->execute([$studentId]);
        $summary = $summaryStmt->fetch() ?: [];

        $watchColumns = $watchAvailable
            ? 'COALESCE(lp.watched_seconds, 0) AS watched_seconds,lp.updated_at AS last_watched'
            : '0 AS watched_seconds,NULL AS last_watched';
        $stmt = $db->prepare("SELECT l.id,l.title,l.status," . ($watchAvailable ? 'l.video_duration_seconds' : 'NULL AS video_duration_seconds') . ",
                    cm.id AS module_id,cm.title AS module_title,
                    c.id AS course_id,c.title AS course_title,sca.expires_at AS access_expires_at,
                    lp.completed_at,$watchColumns
             $joins$where
             ORDER BY c.title,cm.sort_order,cm.id,l.sort_order,l.id
             LIMIT $perPage OFFSET $offset");
        $stmt->execute($params);
        $courses = $db->prepare("SELECT c.id,c.title $joins WHERE $access GROUP BY c.id,c.title ORDER BY c.title");
        $courses->execute([$studentId]);

        View::render('student/lessons', [
            'title' => 'My lessons',
            'user' => $user,
            'lessons' => $stmt->fetchAll(),
            'courses' => $courses->fetchAll(),
            'summary' => $summary,
            'watchAvailable' => $watchAvailable,
            'watchPercent' => $media->watchPercent(),
            'search' => $search,
            'courseId' => $courseId,
            'status' => $status,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'message' => $_SESSION['_flash'] ?? null,
            'error' => $_SESSION['_flash_error'] ?? null,
        ]);
        unset($_SESSION['_flash'], $_SESSION['_flash_error']);
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

    private function certificateTableAvailable(\PDO $db): bool
    {
        try {
            $db->query('SELECT status FROM certificates LIMIT 0');
            return true;
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() !== '42S02') throw $exception;
            return false;
        }
    }
}
