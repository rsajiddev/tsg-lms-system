<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\PurgeGuard;
use App\Core\Security;
use App\Core\View;
use App\Services\AttendanceService;
use App\Services\NotificationService;
use RuntimeException;
use PDO;

/**
 * Student directory, account actions and the attendance window students confirm inside.
 *
 * The attendance grace window used to sit in the security settings form. It is a student-facing
 * rule with no place on that page any more, so it is read and written here, next to the roster
 * it applies to, through the same AttendanceService the sign-in flow uses.
 */
final class StudentManagementController
{
    private const PAGE_SIZE = 20;

    public function index(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $db = Database::connection();
        $loginHistoryAvailable = true;
        try {
            $db->query('SELECT 1 FROM login_events LIMIT 0');
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() !== '42S02') throw $exception;
            $loginHistoryAvailable = false;
        }
        $search = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($search) > 100) $search = mb_substr($search, 0, 100);
        $status = (string) ($_GET['status'] ?? 'all');
        if (!in_array($status, ['all', 'active', 'pending', 'suspended'], true)) $status = 'all';
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $statusCounts = $db->query("SELECT COUNT(*) AS total, SUM(status='active') AS active, SUM(status='pending') AS pending, SUM(status='suspended') AS suspended FROM users WHERE role='student'")->fetch() ?: [];

        $where = ["u.role = 'student'"];
        $params = [];
        if ($search !== '') {
            if (ctype_digit($search)) {
                $where[] = '(u.id = ? OR u.full_name LIKE ? OR u.email LIKE ?)';
                $params[] = (int) $search;
            } else {
                $where[] = '(u.full_name LIKE ? OR u.email LIKE ?)';
            }
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        if ($status !== 'all') {
            $where[] = 'u.status = ?';
            $params[] = $status;
        }
        $whereSql = implode(' AND ', $where);

        $count = $db->prepare("SELECT COUNT(*) FROM users u WHERE $whereSql");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($page, $pages);
        $offset = ($page - 1) * self::PAGE_SIZE;
        $loginCounts = $loginHistoryAvailable
            ? "(SELECT COUNT(*) FROM login_events le WHERE le.user_id=u.id AND le.event_type='success') AS successful_logins,
                (SELECT COUNT(*) FROM login_events le WHERE le.user_id=u.id AND le.event_type IN ('failed','blocked')) AS failed_logins"
            : 'NULL AS successful_logins, NULL AS failed_logins';
        $studentsQuery = $db->prepare(
            "SELECT u.id,u.full_name,u.email,u.status,u.email_verified_at,u.created_at,u.last_login_at,$loginCounts
             FROM users u WHERE $whereSql ORDER BY u.created_at DESC,u.id DESC LIMIT " . self::PAGE_SIZE . ' OFFSET ' . $offset
        );
        $studentsQuery->execute($params);

        $studentId = filter_var($_GET['student_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $selectedStudent = null;
        $activity = [];
        $selectedLoginCounts = ['successful' => 0, 'failed' => 0];
        if ($studentId !== null) {
            $selectedQuery = $db->prepare("SELECT id,full_name,email,status,email_verified_at,created_at,last_login_at FROM users WHERE id=? AND role='student' LIMIT 1");
            $selectedQuery->execute([$studentId]);
            $selectedStudent = $selectedQuery->fetch() ?: null;
            if ($selectedStudent && $loginHistoryAvailable) {
                $countsQuery = $db->prepare("SELECT SUM(event_type='success') AS successful, SUM(event_type IN ('failed','blocked')) AS failed FROM login_events WHERE user_id=?");
                $countsQuery->execute([$studentId]);
                $selectedLoginCounts = $countsQuery->fetch() ?: $selectedLoginCounts;
                $eventsQuery = $db->prepare('SELECT event_type,ip_address,created_at FROM login_events WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 50');
                $eventsQuery->execute([$studentId]);
                $activity = $eventsQuery->fetchAll();
            }
        }

        View::render('admin/students', [
            'title' => 'Student management',
            'user' => $admin,
            'students' => $studentsQuery->fetchAll(),
            'search' => $search,
            'statusFilter' => $status,
            'page' => $page,
            'pages' => $pages,
            'totalStudents' => $total,
            'statusCounts' => $statusCounts,
            'selectedStudent' => $selectedStudent,
            'selectedLoginCounts' => $selectedLoginCounts,
            'activity' => $activity,
            'loginHistoryAvailable' => $loginHistoryAvailable,
            'graceMinutes' => (new AttendanceService($db))->graceMinutes(),
            'graceMinMinutes' => AttendanceService::MIN_GRACE_MINUTES,
            'graceMaxMinutes' => AttendanceService::MAX_GRACE_MINUTES,
            'error' => $_SESSION['_flash_error'] ?? null,
            'message' => $_SESSION['_flash'] ?? null,
        ]);
        unset($_SESSION['_flash'], $_SESSION['_flash_error']);
    }

    /** Stores the attendance confirmation window. Validation is server-side, never in the browser alone. */
    public function saveAttendance(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        try {
            $this->performSaveAttendance($admin);
        } catch (\Throwable $exception) {
            $_SESSION['_flash_error'] = $exception->getMessage();
            $this->redirect('/admin/students');
        }
    }

    private function performSaveAttendance(array $admin): void
    {
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            throw new RuntimeException('Your session expired. Refresh the page and try again.');
        }
        $raw = trim((string) ($_POST[AttendanceService::GRACE_KEY] ?? ''));
        if (!ctype_digit($raw) || (int) $raw < AttendanceService::MIN_GRACE_MINUTES || (int) $raw > AttendanceService::MAX_GRACE_MINUTES) {
            throw new RuntimeException('Attendance grace window must be between ' . AttendanceService::MIN_GRACE_MINUTES . ' and ' . AttendanceService::MAX_GRACE_MINUTES . ' minutes.');
        }
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $db->prepare('INSERT INTO app_settings(setting_key,setting_value,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)')
                ->execute([AttendanceService::GRACE_KEY, $raw, (int) $admin['id']]);
            $db->prepare("INSERT INTO audit_logs(actor_user_id,action,entity_type,metadata) VALUES(?,'attendance.grace_updated','app_settings',JSON_OBJECT('minutes',?))")
                ->execute([(int) $admin['id'], (int) $raw]);
            $db->commit();
            $_SESSION['_flash'] = 'Attendance window saved. New sign-ins use ' . (int) $raw . ' minutes.';
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
        $this->redirect('/admin/students');
    }

    public function setStatus(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) throw new RuntimeException('Your session expired. Refresh and try again.');
        $studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        $status = (string) ($_POST['status'] ?? '');
        if ($studentId < 1 || !in_array($status, ['active', 'suspended'], true)) throw new RuntimeException('Choose a valid student and account action.');
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $lock = $db->prepare("SELECT id,status FROM users WHERE id=? AND role='student' FOR UPDATE");
            $lock->execute([$studentId]);
            $student = $lock->fetch();
            if (!$student || !in_array($student['status'], ['active', 'suspended'], true)) throw new RuntimeException('Only active or suspended student accounts can be changed here.');
            if ($student['status'] !== $status) {
                $update = $db->prepare("UPDATE users SET status=? WHERE id=? AND role='student'");
                $update->execute([$status, $studentId]);
                $audit = $db->prepare('INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,metadata) VALUES(?,?,?,?,?)');
                $audit->execute([(int) $admin['id'], $status === 'suspended' ? 'student.blocked' : 'student.unblocked', 'user', $studentId, json_encode(['previous_status' => $student['status'], 'status' => $status], JSON_THROW_ON_ERROR)]);
            }
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
        $_SESSION['_flash'] = $status === 'suspended'
            ? 'Student blocked. Their next request will end any active session, and sign-in is disabled.'
            : 'Student unblocked. They can sign in again.';
        $this->redirect('/admin/students?student_id=' . $studentId);
    }

    public function clearActivity(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) throw new RuntimeException('Your session expired. Refresh and try again.');
        $studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        if ($studentId < 1) throw new RuntimeException('Choose a valid student.');
        $db = Database::connection();
        $check = $db->prepare("SELECT id FROM users WHERE id=? AND role='student'");
        $check->execute([$studentId]);
        if (!$check->fetch()) throw new RuntimeException('Student was not found.');
        try {
            $delete = $db->prepare('DELETE FROM login_events WHERE user_id=?');
            $delete->execute([$studentId]);
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() !== '42S02') throw $exception;
            throw new RuntimeException('Login history table is not available yet. Import the updated schema first.');
        }
        $deleted = $delete->rowCount();
        $audit = $db->prepare('INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,metadata) VALUES(?,?,?,?,?)');
        $audit->execute([(int) $admin['id'], 'student.activity_cleared', 'user', $studentId, json_encode(['deleted_events' => $deleted], JSON_THROW_ON_ERROR)]);
        $_SESSION['_flash'] = $deleted > 0
            ? 'Cleared ' . number_format($deleted) . ' sign-in record(s) for this student.'
            : 'There was no sign-in activity to clear.';
        $this->redirect('/admin/students?student_id=' . $studentId);
    }

    /**
     * Testing tool: removes every student account together with the work filed under it, so a
     * clean re-registration can be watched from the very first e-mail.
     *
     * Admin accounts, courses and content are untouched, and the delivery log stays: it is the
     * record of what the platform sent, not part of the student's data. The ID counter keeps
     * running because an identity that was printed on a document is never handed to somebody else.
     */
    public function purgeAll(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        try {
            $this->performPurgeAll($admin);
        } catch (\Throwable $exception) {
            $_SESSION['_flash_error'] = $exception->getMessage();
            $this->redirect('/admin/students');
        }
    }

    private function performPurgeAll(array $admin): void
    {
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            throw new RuntimeException('Your session expired. Refresh the page and try again.');
        }
        PurgeGuard::assertConfirmed('DELETE ALL STUDENTS');
        $db = Database::connection();
        $total = (int) $db->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn();
        if ($total === 0) {
            $_SESSION['_flash'] = 'There are no student accounts to delete.';
            $this->redirect('/admin/students');
        }
        // Every child row is written against this subquery instead of a placeholder list, so a
        // directory with thousands of test accounts purges in one statement per table.
        $students = "(SELECT id FROM users WHERE role='student')";
        $paths = $db->query("SELECT file_path FROM assignment_submissions WHERE student_id IN $students AND file_path IS NOT NULL
            UNION SELECT payment_proof_path FROM payments WHERE student_id IN $students AND payment_proof_path IS NOT NULL
            UNION SELECT file_path FROM certificates WHERE student_id IN $students AND file_path IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
        $sources = [
            'assignment_submission' => array_map('intval', $db->query("SELECT id FROM assignment_submissions WHERE student_id IN $students")->fetchAll(PDO::FETCH_COLUMN)),
            'payment' => array_map('intval', $db->query("SELECT id FROM payments WHERE student_id IN $students")->fetchAll(PDO::FETCH_COLUMN)),
            'certificate_request' => array_map('intval', $db->query("SELECT id FROM certificates WHERE student_id IN $students")->fetchAll(PDO::FETCH_COLUMN)),
        ];
        $files = PurgeGuard::removeStoredFiles(is_array($paths) ? $paths : []);
        $deleted = [];
        $db->beginTransaction();
        try {
            $statements = [
                'assignment_submissions' => 'DELETE FROM assignment_submissions WHERE student_id IN ' . $students,
                'attendance_records' => 'DELETE FROM attendance_records WHERE student_id IN ' . $students,
                'certificates' => 'DELETE FROM certificates WHERE student_id IN ' . $students,
                'course_grades' => 'DELETE FROM course_grades WHERE student_id IN ' . $students,
                'email_otps' => 'DELETE FROM email_otps WHERE user_id IN ' . $students,
                'lesson_progress' => 'DELETE FROM lesson_progress WHERE student_id IN ' . $students,
                'notification_replies' => 'DELETE FROM notification_replies WHERE student_id IN ' . $students . ' OR recipient_student_id IN ' . $students,
                'notification_recipients' => 'DELETE FROM notification_recipients WHERE student_id IN ' . $students,
                'payments' => 'DELETE FROM payments WHERE student_id IN ' . $students,
                'quiz_attempts' => 'DELETE FROM quiz_attempts WHERE student_id IN ' . $students,
                'coding_challenge_attempts' => 'DELETE FROM coding_challenge_attempts WHERE student_id IN ' . $students,
                'quiz_retake_approvals' => 'DELETE FROM quiz_retake_approvals WHERE student_id IN ' . $students,
                'student_course_access' => 'DELETE FROM student_course_access WHERE student_id IN ' . $students,
                'login_events' => 'DELETE FROM login_events WHERE user_id IN ' . $students,
                'audit_logs' => 'DELETE FROM audit_logs WHERE actor_user_id IN ' . $students,
            ];
            foreach ($statements as $table => $sql) {
                $deleted[$table] = (int) $db->exec($sql);
            }
            // The delivery log keeps its rows and only loses the link to the removed account,
            // because the operator still has to see which message went to whom.
            $deleted['email_logs_unlinked'] = (int) $db->exec("UPDATE email_logs SET related_user_id=NULL WHERE related_user_id IN $students");
            $deleted['accounts'] = (int) $db->exec("DELETE FROM users WHERE role='student'");
            $db->prepare("INSERT INTO audit_logs(actor_user_id,action,entity_type,metadata) VALUES(?, 'student.bulk_deleted', 'user', JSON_OBJECT('accounts',?, 'payments',?, 'submissions',?, 'certificates',?, 'files_removed',?))")
                ->execute([(int) $admin['id'], $deleted['accounts'], $deleted['payments'], $deleted['assignment_submissions'], $deleted['certificates'], $files]);
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
        // Announcements that pointed at the removed requests would lead an admin to a dead page.
        // An empty id list is skipped on purpose: forgetSource() reads that as “every notification
        // of this type”, and content raised for other objects has to survive a student purge.
        $notifier = new NotificationService($db);
        foreach ($sources as $sourceType => $sourceIds) {
            if ($sourceIds !== []) {
                $notifier->forgetSource((string) $sourceType, $sourceIds);
            }
        }
        $_SESSION['_flash'] = sprintf(
            'Deleted %d student account(s) with their enrolments, payments, submissions, grades and certificates.%s New sign-ups keep running the ID sequence, so no reference is ever reused.',
            $deleted['accounts'],
            $files > 0 ? ' ' . $files . ' uploaded file(s) were removed from storage.' : ''
        );
        $this->redirect('/admin/students');
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim((require BASE_PATH . '/config/app.php')['url'], '/') . $path);
        exit;
    }
}
