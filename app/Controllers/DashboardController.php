<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\View;
use App\Services\AdminAttention;
use App\Services\AttendanceService;
use PDO;

/**
 * Dashboard overview for both roles.
 *
 * The admin view is an analytics surface: every figure is derived from records already stored in
 * MySQL, and each read fails open so a partially imported schema degrades to a zero instead of an
 * error page.
 */
final class DashboardController
{
    private const RANGES = [7, 14, 30];
    private bool $schemaNotice = false;

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
        try {
            $taskStmt=$db->prepare('SELECT id,title,is_done,reminder_at FROM dashboard_tasks WHERE user_id=? ORDER BY is_done ASC,created_at DESC,id DESC');
            $taskStmt->execute([(int)$user['id']]);
            $data['dashboardTasks']=$taskStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $exception) { $data['dashboardTasks']=[]; }

        View::render('dashboard/index', array_merge([
            'title' => ucfirst($user['role']) . ' Dashboard',
            'user' => $user,
            'message' => $_SESSION['_flash'] ?? null,
            'error' => $_SESSION['_flash_error'] ?? null,
        ], $data));
        unset($_SESSION['_flash'], $_SESSION['_flash_error']);
    }

    private function adminOverview(PDO $db): array
    {
        $range = (int) ($_GET['range'] ?? 14);
        if (!in_array($range, self::RANGES, true)) $range = 14;

        $overview = [
            'range' => $range,
            'ranges' => self::RANGES,
            'students' => $this->students($db),
            'courses' => $this->courses($db),
            'payments' => $this->payments($db),
            'quizzes' => $this->quizzes($db, $range),
            'assignments' => $this->assignments($db),
            'certificates' => $this->certificates($db),
            'attendance' => (new AttendanceService($db))->adminSummary($range),
            'topCourses' => $this->topCourses($db),
            'enrolmentSeries' => $this->enrolmentSeries($db),
            'snapshotTrend' => $this->snapshotTrend($db, $range),
            'revenue' => $this->revenue($db),
            'alerts' => $this->alerts($db),
            'recentPayments' => $this->rows($db,
                'SELECT p.id, p.amount, p.currency, p.status, p.transaction_reference, p.created_at,
                        u.full_name AS student_name, c.title AS course_title
                 FROM payments p
                 JOIN users u ON u.id = p.student_id
                 JOIN courses c ON c.id = p.course_id
                 ORDER BY p.created_at DESC, p.id DESC LIMIT 6'),
            'recentAttempts' => $this->rows($db,
                'SELECT qa.id, qa.status, qa.result, qa.percentage, qa.submitted_at,
                        u.full_name AS student_name, q.title AS quiz_title, c.title AS course_title
                 FROM quiz_attempts qa
                 JOIN users u ON u.id = qa.student_id
                 JOIN quizzes q ON q.id = qa.quiz_id
                 JOIN courses c ON c.id = q.course_id
                 ORDER BY COALESCE(qa.submitted_at, qa.started_at) DESC, qa.id DESC LIMIT 6'),
        ];

        // Set last so a failed read anywhere above still surfaces the import notice.
        $overview['schemaNotice'] = $this->schemaNotice;
        return $overview;
    }

    private function students(PDO $db): array
    {
        return [
            'total' => $this->value($db, "SELECT COUNT(*) FROM users WHERE role = 'student'"),
            'active' => $this->value($db, "SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active' AND email_verified_at IS NOT NULL"),
            'blocked' => $this->value($db, "SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'suspended'"),
            'newThisMonth' => $this->value($db, "SELECT COUNT(*) FROM users WHERE role = 'student' AND created_at >= DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01')"),
            'withAccess' => $this->value($db, "SELECT COUNT(DISTINCT student_id) FROM student_course_access WHERE status = 'active' AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())"),
        ];
    }

    private function courses(PDO $db): array
    {
        return [
            'total' => $this->value($db, 'SELECT COUNT(*) FROM courses'),
            'published' => $this->value($db, "SELECT COUNT(*) FROM courses WHERE visibility = 'published'"),
            'draft' => $this->value($db, "SELECT COUNT(*) FROM courses WHERE visibility = 'draft'"),
            'hidden' => $this->value($db, "SELECT COUNT(*) FROM courses WHERE visibility IN ('hidden', 'archived')"),
            'lessonsPublished' => $this->value($db, "SELECT COUNT(*) FROM lessons WHERE status = 'published'"),
            'lessonsCompleted' => $this->value($db, 'SELECT COUNT(*) FROM lesson_progress WHERE completed_at IS NOT NULL'),
        ];
    }

    private function payments(PDO $db): array
    {
        $split = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
        foreach ($this->rows($db, 'SELECT status, COUNT(*) AS total FROM payments GROUP BY status') as $row) {
            $key = (string) $row['status'];
            if (array_key_exists($key, $split)) $split[$key] = (int) $row['total'];
        }
        return [
            'split' => $split,
            'total' => array_sum($split),
            'pending' => $split['pending'],
            'pendingOver24h' => $this->value($db, "SELECT COUNT(*) FROM payments WHERE status = 'pending' AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)"),
            'approvedThisMonth' => $this->value($db, "SELECT COUNT(*) FROM payments WHERE status = 'approved' AND COALESCE(reviewed_at, created_at) >= DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01')"),
            'submissionsThisMonth' => $this->value($db, "SELECT COUNT(*) FROM payments WHERE created_at >= DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01')"),
        ];
    }

    private function quizzes(PDO $db, int $range): array
    {
        $row = $this->row($db,
            'SELECT COUNT(*) AS total,
                    COALESCE(SUM(result = \'pass\'), 0) AS passed,
                    COALESCE(SUM(result = \'fail\'), 0) AS failed,
                    COALESCE(SUM(status = \'in_progress\'), 0) AS in_progress,
                    ROUND(AVG(percentage), 1) AS average_percentage
             FROM quiz_attempts
             WHERE started_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)', [$range]);
        return [
            'total' => $this->value($db, 'SELECT COUNT(*) FROM quizzes'),
            'released' => $this->value($db, "SELECT COUNT(*) FROM quizzes WHERE status = 'released'"),
            'awaiting' => $this->value($db, "SELECT COUNT(*) FROM quizzes WHERE status IN ('draft', 'scheduled')"),
            'questions' => $this->value($db, 'SELECT COUNT(*) FROM quiz_questions'),
            'attempts' => (int) ($row['total'] ?? 0),
            'passed' => (int) ($row['passed'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'inProgress' => (int) ($row['in_progress'] ?? 0),
            'average' => $row['average_percentage'] === null ? null : (float) $row['average_percentage'],
            'retakeRequests' => $this->value($db, "SELECT COUNT(*) FROM quiz_retake_approvals WHERE request_status = 'pending'"),
        ];
    }

    private function assignments(PDO $db): array
    {
        $row = $this->row($db,
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(status = 'submitted'), 0) AS pending_review,
                    COALESCE(SUM(status = 'reviewed'), 0) AS reviewed,
                    COALESCE(SUM(submitted_late = 1), 0) AS late
             FROM assignment_submissions");
        $expected = $this->value($db,
            "SELECT COUNT(*) FROM assignments a
             JOIN student_course_access sca ON sca.course_id = a.course_id
             WHERE a.status = 'published' AND sca.status = 'active' AND (sca.expires_at IS NULL OR sca.expires_at > UTC_TIMESTAMP())");
        $submitted = (int) ($row['total'] ?? 0);
        return [
            'published' => $this->value($db, "SELECT COUNT(*) FROM assignments WHERE status = 'published'"),
            'expected' => $expected,
            'submitted' => $submitted,
            'notSubmitted' => max(0, $expected - $submitted),
            'pendingReview' => (int) ($row['pending_review'] ?? 0),
            'reviewed' => (int) ($row['reviewed'] ?? 0),
            'late' => (int) ($row['late'] ?? 0),
        ];
    }

    private function certificates(PDO $db): array
    {
        $split = ['requested' => 0, 'issued' => 0, 'rejected' => 0, 'revoked' => 0];
        foreach ($this->rows($db, 'SELECT status, COUNT(*) AS total FROM certificates GROUP BY status') as $row) {
            $key = (string) $row['status'];
            if (array_key_exists($key, $split)) $split[$key] = (int) $row['total'];
        }
        return [
            'split' => $split,
            'pending' => $split['requested'],
            'issued' => $split['issued'],
            'expired' => $this->value($db, "SELECT COUNT(*) FROM certificates WHERE status = 'issued' AND expires_at IS NOT NULL AND expires_at <= UTC_TIMESTAMP()"),
        ];
    }

    private function topCourses(PDO $db): array
    {
        return $this->rows($db,
            "SELECT c.title, COUNT(sca.id) AS students
             FROM courses c
             LEFT JOIN student_course_access sca ON sca.course_id = c.id AND sca.status = 'active'
                  AND (sca.expires_at IS NULL OR sca.expires_at > UTC_TIMESTAMP())
             GROUP BY c.id, c.title
             ORDER BY students DESC, c.title ASC LIMIT 5");
    }

    /** New course access grants per month, oldest first, for the last six months. */
    private function enrolmentSeries(PDO $db): array
    {
        $byMonth = [];
        foreach ($this->rows($db,
            "SELECT DATE_FORMAT(granted_at, '%Y-%m') AS bucket, COUNT(*) AS total
             FROM student_course_access
             WHERE granted_at >= DATE_SUB(DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01'), INTERVAL 5 MONTH)
             GROUP BY bucket") as $row) {
            $byMonth[(string) $row['bucket']] = (int) $row['total'];
        }
        $series = [];
        for ($offset = 5; $offset >= 0; $offset--) {
            $stamp = strtotime(date('Y-m-01') . ' -' . $offset . ' months');
            $key = date('Y-m', $stamp);
            $series[] = ['key' => $key, 'label' => strtoupper(date('M', $stamp)), 'total' => $byMonth[$key] ?? 0];
        }
        return $series;
    }

    /**
     * Per-day activity counts behind the "Academy at a glance" trend lines, oldest day first, over
     * the selected range. Each metric is the number of that event recorded on the day, so every line
     * reads on one shared scale. Every read fails open to zeros so a partially imported schema still
     * renders a flat line instead of an error.
     *
     * @return array<string, mixed>
     */
    private function snapshotTrend(PDO $db, int $range): array
    {
        $range = max(2, min(60, $range));
        $dates = [];
        for ($offset = $range - 1; $offset >= 0; $offset--) {
            $dates[] = gmdate('Y-m-d', strtotime('-' . $offset . ' days'));
        }
        $since = $dates[0];

        $bucketize = function (string $sql, array $params) use ($db, $dates): array {
            $counts = array_fill_keys($dates, 0);
            foreach ($this->rows($db, $sql, $params) as $row) {
                $bucket = (string) ($row['bucket'] ?? '');
                if (array_key_exists($bucket, $counts)) $counts[$bucket] = (int) ($row['total'] ?? 0);
            }
            return array_values($counts);
        };

        return [
            'dates' => $dates,
            'students' => $bucketize(
                "SELECT DATE(created_at) AS bucket, COUNT(*) AS total FROM users
                 WHERE role = 'student' AND created_at >= ? GROUP BY bucket", [$since]),
            'present' => $bucketize(
                "SELECT attendance_date AS bucket, COUNT(*) AS total FROM attendance_records
                 WHERE status = 'present' AND attendance_date >= ? GROUP BY bucket", [$since]),
            'absent' => $bucketize(
                "SELECT attendance_date AS bucket, COUNT(*) AS total FROM attendance_records
                 WHERE status = 'absent' AND attendance_date >= ? GROUP BY bucket", [$since]),
            'payments' => $bucketize(
                "SELECT DATE(created_at) AS bucket, COUNT(*) AS total FROM payments
                 WHERE created_at >= ? GROUP BY bucket", [$since]),
            'quizzes' => $bucketize(
                "SELECT DATE(started_at) AS bucket, COUNT(*) AS total FROM quiz_attempts
                 WHERE started_at >= ? GROUP BY bucket", [$since]),
            'assignments' => $bucketize(
                "SELECT DATE(submitted_at) AS bucket, COUNT(*) AS total FROM assignment_submissions
                 WHERE submitted_at >= ? GROUP BY bucket", [$since]),
        ];
    }

    /** Approved revenue by currency so mixed PKR/USD records are never summed together. */
    private function revenue(PDO $db): array
    {
        $rows = $this->rows($db,
            "SELECT currency,
                    COALESCE(SUM(CASE WHEN COALESCE(reviewed_at, created_at) >= DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01') THEN amount ELSE 0 END), 0) AS month_total,
                    COALESCE(SUM(amount), 0) AS lifetime_total
             FROM payments WHERE status = 'approved' GROUP BY currency ORDER BY lifetime_total DESC");
        if ($rows === []) {
            return ['currency' => 'PKR', 'month' => 0.0, 'lifetime' => 0.0, 'series' => [], 'currencies' => []];
        }
        $primary = $rows[0];
        $currency = (string) $primary['currency'];

        $byMonth = [];
        foreach ($this->rows($db,
            "SELECT DATE_FORMAT(COALESCE(reviewed_at, created_at), '%Y-%m') AS bucket, COALESCE(SUM(amount), 0) AS total
             FROM payments WHERE status = 'approved' AND currency = ?
                  AND COALESCE(reviewed_at, created_at) >= DATE_SUB(DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01'), INTERVAL 5 MONTH)
             GROUP BY bucket", [$currency]) as $row) {
            $byMonth[(string) $row['bucket']] = (float) $row['total'];
        }
        $series = [];
        for ($offset = 5; $offset >= 0; $offset--) {
            $stamp = strtotime(date('Y-m-01') . ' -' . $offset . ' months');
            $key = date('Y-m', $stamp);
            $series[] = ['key' => $key, 'label' => strtoupper(date('M', $stamp)), 'total' => $byMonth[$key] ?? 0.0];
        }

        return [
            'currency' => $currency,
            'month' => (float) $primary['month_total'],
            'lifetime' => (float) $primary['lifetime_total'],
            'series' => $series,
            'currencies' => array_map(static fn(array $row): array => [
                'currency' => (string) $row['currency'],
                'lifetime' => (float) $row['lifetime_total'],
            ], $rows),
        ];
    }

    /** Actionable alerts for the academy operations panel. */
    private function alerts(PDO $db): array
    {
        $alerts = [];
        $push = static function (string $level, string $title, string $detail, string $href) use (&$alerts): void {
            $alerts[] = ['level' => $level, 'title' => $title, 'detail' => $detail, 'href' => $href];
        };

        $stalePayments = $this->value($db, "SELECT COUNT(*) FROM payments WHERE status = 'pending' AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)");
        if ($stalePayments > 0) {
            $push('danger', 'Payments waiting over 24 hours', number_format($stalePayments) . ' submission' . ($stalePayments === 1 ? '' : 's') . ' to verify', '/admin/payments');
        }
        // A student who wrote in and got no answer is the queue that should be read first, and the
        // number is the same one the Chats entry in the sidebar carries.
        $awaitingReplies = (new AdminAttention($db))->pendingByArea()['messages'] ?? 0;
        if ($awaitingReplies > 0) {
            $push('danger', 'Student messages waiting', number_format((int) $awaitingReplies) . ' conversation' . ((int) $awaitingReplies === 1 ? '' : 's') . ' without an Academy reply', '/admin/chats');
        }
        $pendingCertificates = $this->value($db, "SELECT COUNT(*) FROM certificates WHERE status = 'requested'");
        if ($pendingCertificates > 0) {
            $push('warning', 'Certificate requests', number_format($pendingCertificates) . ' awaiting review', '/admin/certificates');
        }
        $emptyCourses = $this->value($db,
            "SELECT COUNT(*) FROM courses c WHERE c.visibility = 'published'
              AND NOT EXISTS (SELECT 1 FROM course_modules cm JOIN lessons l ON l.module_id = cm.id WHERE cm.course_id = c.id AND l.status = 'published')");
        if ($emptyCourses > 0) {
            $push('warning', 'Published courses without lessons', number_format($emptyCourses) . ' to review', '/admin/courses');
        }
        $expiring = $this->value($db,
            "SELECT COUNT(DISTINCT student_id) FROM student_course_access
             WHERE status = 'active' AND expires_at IS NOT NULL
                  AND expires_at BETWEEN UTC_TIMESTAMP() AND DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY)");
        if ($expiring > 0) {
            $push('info', 'Access expiring within 7 days', number_format($expiring) . ' student' . ($expiring === 1 ? '' : 's'), '/admin/students');
        }
        $awaitingQuiz = $this->value($db, "SELECT COUNT(*) FROM quizzes WHERE status IN ('draft', 'scheduled')");
        if ($awaitingQuiz > 0) {
            $push('info', 'Quizzes not released yet', number_format($awaitingQuiz) . ' draft or scheduled', '/admin/quizzes');
        }
        $pendingReviews = $this->value($db, "SELECT COUNT(*) FROM reviews WHERE status = 'pending'");
        if ($pendingReviews > 0) {
            $push('info', 'Student reviews to moderate', number_format($pendingReviews) . ' pending', '/admin/reviews');
        }
        if ($alerts === []) {
            $alerts[] = ['level' => 'success', 'title' => 'Nothing needs attention', 'detail' => 'Queues are clear right now.', 'href' => '/admin/payments'];
        }
        return $alerts;
    }

    private function studentOverview(PDO $db, int $studentId): array
    {
        $attendanceService = new AttendanceService($db);
        // A session can outlive the day it started on; the service verifies current course access
        // before opening today's record.
        $attendanceService->openDailySession($studentId);
        $attendance = $attendanceService->studentSummary($studentId, 30);
        $imageColumn = $this->courseImageColumnAvailable($db) ? 'c.image_path' : 'NULL AS image_path';
        $courses = $this->rows($db,
            "SELECT c.id, c.title, c.slug, c.category, c.level_label, c.duration_label, $imageColumn, sca.granted_at,
                    (SELECT COUNT(*) FROM course_modules cm JOIN lessons l ON l.module_id = cm.id
                     WHERE cm.course_id = c.id AND l.status = 'published') AS lesson_count,
                    (SELECT COUNT(*) FROM lesson_progress lp JOIN lessons l ON l.id = lp.lesson_id
                     JOIN course_modules cm ON cm.id = l.module_id
                     WHERE lp.student_id = sca.student_id AND cm.course_id = c.id AND lp.completed_at IS NOT NULL) AS completed_lessons
             FROM student_course_access sca
             JOIN courses c ON c.id = sca.course_id
             WHERE sca.student_id = ? AND sca.status = 'active' AND (sca.expires_at IS NULL OR sca.expires_at > UTC_TIMESTAMP())
             ORDER BY sca.granted_at DESC, c.title ASC LIMIT 6", [$studentId]);

        return [
            'activeCourseCount' => $this->value($db, "SELECT COUNT(*) FROM student_course_access WHERE student_id = ? AND status = 'active' AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())", [$studentId]),
            'pendingPaymentCount' => $this->value($db, "SELECT COUNT(*) FROM payments WHERE student_id = ? AND status = 'pending'", [$studentId]),
            'completedLessonCount' => $this->value($db, 'SELECT COUNT(*) FROM lesson_progress WHERE student_id = ? AND completed_at IS NOT NULL', [$studentId]),
            'unreadNotificationCount' => $this->value($db, 'SELECT COUNT(*) FROM notification_recipients WHERE student_id = ? AND read_at IS NULL', [$studentId]),
            'quizAttempts' => $this->value($db, 'SELECT COUNT(*) FROM quiz_attempts WHERE student_id = ?', [$studentId]),
            'assignmentSubmissions' => $this->value($db, 'SELECT COUNT(*) FROM assignment_submissions WHERE student_id = ?', [$studentId]),
            'courses' => $courses,
            'attendance' => $attendance,
        ];
    }

    private function courseImageColumnAvailable(PDO $db): bool
    {
        try {
            $db->query('SELECT image_path FROM courses LIMIT 0');
            return true;
        } catch (\PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1054) return false;
            throw $exception;
        }
    }

    private function value(PDO $db, string $sql, array $params = []): int
    {
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (\PDOException $exception) {
            $this->schemaNotice = true;
            error_log('[DASHBOARD] Count read failed: ' . $exception->getMessage());
            return 0;
        }
    }

    /** @return array<string, mixed> */
    private function row(PDO $db, string $sql, array $params = []): array
    {
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : [];
        } catch (\PDOException $exception) {
            $this->schemaNotice = true;
            error_log('[DASHBOARD] Row read failed: ' . $exception->getMessage());
            return [];
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(PDO $db, string $sql, array $params = []): array
    {
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $exception) {
            $this->schemaNotice = true;
            error_log('[DASHBOARD] List read failed: ' . $exception->getMessage());
            return [];
        }
    }
}
