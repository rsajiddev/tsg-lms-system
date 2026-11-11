<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\View;
use App\Services\CourseGrading;
use App\Services\AccessPlan;
use App\Services\DocumentPdf;
use App\Services\LessonMedia;
use App\Services\ReportSharing;
use App\Services\StudentProgress;

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
        $accessPlanColumn = AccessPlan::columnStored($db, 'student_course_access.plan_code') ? 'sca.plan_code' : "'recorded_only' AS plan_code";
        // Certificates are optional schema on older imports, so degrade to "not installed" instead of failing the page.
        $certificatesAvailable = $this->certificateTableAvailable($db);
        $certificateColumns = $certificatesAvailable
            ? 'cert.status AS certificate_status, cert.certificate_id, cert.admin_remarks, cert.revoked_reason AS certificate_revoked_reason, cert.expires_at AS certificate_expires_at'
            : 'NULL AS certificate_status, NULL AS certificate_id, NULL AS admin_remarks, NULL AS certificate_revoked_reason, NULL AS certificate_expires_at';
        $certificateJoin = $certificatesAvailable
            ? 'LEFT JOIN lms_certificates cert ON cert.student_id = sca.student_id AND cert.course_id = c.id'
            : '';
        // Course grades come from the same migration; probe so an un-imported schema still renders.
        $gradesAvailable = $this->courseGradesAvailable($db);
        $gradeColumns = $gradesAvailable
            ? 'cg.overall_pct, cg.letter_grade'
            : 'NULL AS overall_pct, NULL AS letter_grade';
        $gradeJoin = $gradesAvailable
            ? 'LEFT JOIN lms_course_grades cg ON cg.student_id = sca.student_id AND cg.course_id = c.id'
            : '';
        $stmt = $db->prepare(
            "SELECT c.id, c.title, c.slug, c.category, c.level_label, c.duration_label, $imageColumn, sca.granted_at, sca.expires_at, sca.status AS access_status, $accessPlanColumn, u.account_reference AS student_reference, $gradeColumns, $certificateColumns,
                    (SELECT COUNT(*) FROM lms_course_modules cm JOIN lms_lessons l ON l.module_id = cm.id
                     WHERE cm.course_id = c.id AND l.status = 'published') AS lesson_count,
                    (SELECT COUNT(*) FROM lms_lesson_progress lp JOIN lms_lessons l ON l.id = lp.lesson_id
                     JOIN lms_course_modules cm ON cm.id = l.module_id
                     WHERE lp.student_id = sca.student_id AND cm.course_id = c.id AND lp.completed_at IS NOT NULL) AS completed_lessons
             FROM lms_student_course_access sca
             JOIN lms_courses c ON c.id = sca.course_id
             JOIN lms_users u ON u.id = sca.student_id
             $gradeJoin
             $certificateJoin
             WHERE sca.student_id = ? AND sca.status IN ('active','completed','certified') AND (sca.expires_at IS NULL OR sca.expires_at > UTC_TIMESTAMP())
             ORDER BY sca.granted_at DESC, c.title ASC"
        );
        $stmt->execute([(int) $user['id']]);

        View::render('student/courses', [
            'title' => 'My courses',
            'user' => $user,
            'courses' => $stmt->fetchAll(),
            'certificatesAvailable' => $certificatesAvailable,
            'gradesAvailable' => $gradesAvailable,
            // Quoted live from the same settings the request gate uses, so the student always sees
            // the bar they are actually measured against.
            'certificateCriteria' => (new CourseGrading($db))->thresholds(),
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

        $access = "sca.student_id = ? AND sca.status IN ('active','completed','certified') AND (sca.expires_at IS NULL OR sca.expires_at > UTC_TIMESTAMP())";
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
        $joins = "FROM lms_lessons l
             JOIN lms_course_modules cm ON cm.id = l.module_id
             JOIN lms_courses c ON c.id = cm.course_id
             JOIN lms_student_course_access sca ON sca.course_id = c.id
             LEFT JOIN lms_lesson_progress lp ON lp.lesson_id = l.id AND lp.student_id = sca.student_id";

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
        // Whether a lesson holds a live class, and the plan this row's enrolment sits on. The list
        // never carries the address itself - only the flag - and the view prints the marker for the
        // plan that may open one. Without the columns imported, both read as absent.
        $liveColumns = AccessPlan::columnStored($db, 'lessons.meeting_url') && AccessPlan::columnStored($db, 'student_course_access.plan_code')
            ? "(l.meeting_url IS NOT NULL AND l.meeting_url <> '') AS has_live,sca.plan_code"
            : '0 AS has_live,NULL AS plan_code';
        $stmt = $db->prepare("SELECT l.id,l.title,l.status," . ($watchAvailable ? 'l.video_duration_seconds' : 'NULL AS video_duration_seconds') . ",
                    cm.id AS module_id,cm.title AS module_title,
                    c.id AS course_id,c.title AS course_title,sca.expires_at AS access_expires_at,
                    lp.completed_at,$watchColumns,$liveColumns
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

        // The plan a payment was made on, when this database has been re-imported far enough to
        // hold it. A legacy row reports no plan rather than a guessed one, and the view leaves the
        // label out instead of printing "Recorded Only" over a purchase nobody recorded.
        $planColumn = AccessPlan::columnStored($db, 'payments.plan_code') ? 'p.plan_code' : 'NULL AS plan_code';
        $payments = $db->prepare(
            'SELECT p.id, p.amount, p.currency, p.status, p.transaction_reference, p.review_note,
                    p.payment_proof_path, p.receipt_number, p.receipt_issued_at, p.created_at, p.reviewed_at, c.title AS course_title,
                    ' . $planColumn . '
             FROM lms_payments p
             JOIN lms_courses c ON c.id = p.course_id
             WHERE p.student_id = ?
             ORDER BY p.created_at DESC, p.id DESC'
        );
        $payments->execute([(int) $user['id']]);

        View::render('student/payments', [
            'title' => 'Payments',
            'user' => $user,
            'payments' => $payments->fetchAll(),
            'pendingCount' => $this->count($db, "SELECT COUNT(*) FROM lms_payments WHERE student_id = ? AND status = 'pending'", [(int) $user['id']]),
            'approvedCount' => $this->count($db, "SELECT COUNT(*) FROM lms_payments WHERE student_id = ? AND status = 'approved'", [(int) $user['id']]),
            'rejectedCount' => $this->count($db, "SELECT COUNT(*) FROM lms_payments WHERE student_id = ? AND status = 'rejected'", [(int) $user['id']]),
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
                    (SELECT COUNT(*) FROM lms_quiz_attempts qa WHERE qa.quiz_id = q.id AND qa.student_id = ?) AS used_attempts
             FROM lms_quizzes q
             JOIN lms_courses c ON c.id = q.course_id
             JOIN lms_student_course_access sca ON sca.course_id = c.id AND sca.student_id = ? AND sca.status IN ('active','completed','certified') AND (sca.expires_at IS NULL OR sca.expires_at > UTC_TIMESTAMP())
             WHERE 1=1 {$released}
             ORDER BY q.course_id ASC, q.week_number ASC, q.id DESC"
        );
        $quizzes->execute([$studentId, $studentId]);

        $attempts = $db->prepare(
            'SELECT qa.id, qa.status, qa.submitted_at, qa.percentage, qa.result, qa.score, qa.total_marks,
                    q.title AS quiz_title, q.week_number, c.title AS course_title
             FROM lms_quiz_attempts qa
             JOIN lms_quizzes q ON q.id = qa.quiz_id
             JOIN lms_courses c ON c.id = q.course_id
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
             FROM lms_assignments a
             JOIN lms_courses c ON c.id = a.course_id
             JOIN lms_student_course_access sca ON sca.course_id = c.id AND sca.student_id = ? AND sca.status IN ('active','completed','certified') AND (sca.expires_at IS NULL OR sca.expires_at > UTC_TIMESTAMP())
             LEFT JOIN lms_assignment_submissions s ON s.id = (
                 SELECT latest.id FROM lms_assignment_submissions latest
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

    /**
     * The reports an administrator has shared with this student.
     *
     * The page lists metadata only. The document is rebuilt when the student downloads it, so a
     * share can never hand over another student's rows and nothing has to be kept in storage.
     */
    public function reports(): void
    {
        $user = AuthMiddleware::requireRole('student');
        $shares = ReportSharing::forStudent(Database::connection(), (int) $user['id']);

        View::render('student/reports', [
            'title' => 'Reports',
            'user' => $user,
            'shares' => $shares,
        ]);
    }

    public function reportDownload(): void
    {
        $user = AuthMiddleware::requireRole('student');
        $studentId = (int) $user['id'];
        $db = Database::connection();
        $share = ReportSharing::find($db, (int) ($_GET['share_id'] ?? 0));
        // A share id is not a permission. The row has to belong to whoever is signed in before
        // anything at all is built from it, so guessing an id reveals nothing.
        if ($share === null || (int) $share['student_id'] !== $studentId) {
            http_response_code(404);
            return;
        }
        $report = ReportSharing::studentReport($db, $share, $studentId);
        $pdf = DocumentPdf::report($report);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="techsg-' . $report['type'] . '-' . strtolower((string) $share['period_key']) . '-' . $studentId . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
    }

    /**
     * The Certificate & Learning Progress dashboard.
     *
     * Everything a student needs to see about how far they have come and how far a certificate still
     * sits: an overall picture, then per course the module-by-module progress, watched-versus-remaining
     * videos, quiz scores, assignment status, and the exact certificate requirements still open. The
     * figures are the same weighted ones the certificate gate uses, so this page and the request button
     * never disagree.
     */
    public function progress(): void
    {
        $user = AuthMiddleware::requireRole('student');
        $summary = (new StudentProgress(Database::connection()))->summary((int) $user['id']);

        View::render('student/progress', [
            'title' => 'Certificate & progress',
            'user' => $user,
            'summary' => $summary,
            'message' => $_SESSION['_flash'] ?? null,
            'error' => $_SESSION['_flash_error'] ?? null,
        ]);
        unset($_SESSION['_flash'], $_SESSION['_flash_error']);
    }

    /**
     * The same progress picture, exported as a branded PDF the student keeps. Rebuilt on request from
     * live records - nothing about the document is cached or stored.
     */
    public function progressReport(): void
    {
        $user = AuthMiddleware::requireRole('student');
        $summary = (new StudentProgress(Database::connection()))->summary((int) $user['id']);
        $pdf = DocumentPdf::progressReport($summary);

        $name = preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($summary['student']['name'] ?: 'student')) ?: 'student';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="techsg-progress-' . strtolower($name) . '-' . (int) $user['id'] . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        header('X-Content-Type-Options: nosniff');
        echo $pdf;
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
            $db->query('SELECT image_path FROM lms_courses LIMIT 0');
            return true;
        } catch (\PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1054) return false;
            throw $exception;
        }
    }

    private function certificateTableAvailable(\PDO $db): bool
    {
        try {
            $db->query('SELECT status FROM lms_certificates LIMIT 0');
            return true;
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() !== '42S02') throw $exception;
            return false;
        }
    }

    private function courseGradesAvailable(\PDO $db): bool
    {
        try {
            $db->query('SELECT overall_pct FROM lms_course_grades LIMIT 0');
            return true;
        } catch (\PDOException $exception) {
            // 42S02 = the grade table is not imported yet, 1054 = it exists without this column.
            if ((string) $exception->getCode() === '42S02' || ($exception->errorInfo[1] ?? null) === 1054) return false;
            throw $exception;
        }
    }
}
