<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\PurgeGuard;
use App\Core\Security;
use App\Core\View;
use App\Services\AccountIdentity;
use App\Services\AttendanceService;
use App\Services\LessonProtection;
use App\Services\NotificationService;
use App\Services\SessionRegistry;
use RuntimeException;
use PDO;
use PDOException;

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
            $db->query('SELECT 1 FROM lms_login_events LIMIT 0');
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() !== '42S02') throw $exception;
            $loginHistoryAvailable = false;
        }
        $search = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($search) > 100) $search = mb_substr($search, 0, 100);
        $status = (string) ($_GET['status'] ?? 'all');
        if (!in_array($status, ['all', 'active', 'pending', 'suspended'], true)) $status = 'all';
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $statusCounts = $db->query("SELECT COUNT(*) AS total, SUM(status='active') AS active, SUM(status='pending') AS pending, SUM(status='suspended') AS suspended FROM lms_users WHERE role='student'")->fetch() ?: [];

        $where = ["u.role = 'student'"];
        $params = [];
        if ($search !== '') {
            // The directory prints each account's Academy ID, so that is the number an admin pastes
            // here. The internal row id still resolves for anyone holding an older screenshot.
            if (ctype_digit($search)) {
                $where[] = '(u.id = ? OR u.full_name LIKE ? OR u.email LIKE ? OR u.account_reference LIKE ?)';
                $params[] = (int) $search;
            } else {
                $where[] = '(u.full_name LIKE ? OR u.email LIKE ? OR u.account_reference LIKE ?)';
            }
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        if ($status !== 'all') {
            $where[] = 'u.status = ?';
            $params[] = $status;
        }
        $whereSql = implode(' AND ', $where);

        $count = $db->prepare("SELECT COUNT(*) FROM lms_users u WHERE $whereSql");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($page, $pages);
        $offset = ($page - 1) * self::PAGE_SIZE;
        $loginCounts = $loginHistoryAvailable
            ? "(SELECT COUNT(*) FROM lms_login_events le WHERE le.user_id=u.id AND le.event_type='success') AS successful_logins,
                (SELECT COUNT(*) FROM lms_login_events le WHERE le.user_id=u.id AND le.event_type IN ('failed','blocked')) AS failed_logins"
            : 'NULL AS successful_logins, NULL AS failed_logins';
        $studentsQuery = $db->prepare(
            "SELECT u.id,u.full_name,u.email,u.status,u.account_reference,u.email_verified_at,u.created_at,u.last_login_at,$loginCounts
             FROM lms_users u WHERE $whereSql ORDER BY u.created_at DESC,u.id DESC LIMIT " . self::PAGE_SIZE . ' OFFSET ' . $offset
        );
        $studentsQuery->execute($params);
        $studentRows = $studentsQuery->fetchAll(PDO::FETCH_ASSOC);
        foreach ($studentRows as &$studentRow) {
            if (trim((string) ($studentRow['account_reference'] ?? '')) === '') {
                $studentRow['account_reference'] = AccountIdentity::ensure($db, (int) $studentRow['id']);
            }
        }
        unset($studentRow);

        $studentId = filter_var($_GET['student_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $selectedStudent = null;
        $studentCourses = [];
        $activity = [];
        $sessions = [];
        $registry = new SessionRegistry($db);
        $selectedLoginCounts = ['successful' => 0, 'failed' => 0];
        if ($studentId !== null) {
            $selectedQuery = $db->prepare("SELECT id,full_name,email,status,account_reference,email_verified_at,created_at,last_login_at FROM lms_users WHERE id=? AND role='student' LIMIT 1");
            $selectedQuery->execute([$studentId]);
            $selectedStudent = $selectedQuery->fetch() ?: null;
            if ($selectedStudent && trim((string) ($selectedStudent['account_reference'] ?? '')) === '') {
                $selectedStudent['account_reference'] = AccountIdentity::ensure($db, (int) $selectedStudent['id']);
            }
            if ($selectedStudent) {
                $planColumn = \App\Services\AccessPlan::columnStored($db, 'student_course_access.plan_code') ? 'sca.plan_code' : "'recorded_only' AS plan_code";
                $paidPlanColumn = \App\Services\AccessPlan::columnStored($db, 'payments.plan_code')
                    ? "(SELECT p.plan_code FROM lms_payments p WHERE p.student_id=sca.student_id AND p.course_id=sca.course_id AND p.status='approved' ORDER BY p.reviewed_at DESC,p.id DESC LIMIT 1) AS paid_plan_code"
                    : 'NULL AS paid_plan_code';
                $courseRows = $db->prepare("SELECT c.title,sca.status,sca.granted_at,sca.expires_at,$planColumn,$paidPlanColumn FROM lms_student_course_access sca JOIN lms_courses c ON c.id=sca.course_id WHERE sca.student_id=? ORDER BY sca.granted_at DESC,c.title");
                $courseRows->execute([$studentId]);
                $studentCourses = $courseRows->fetchAll(PDO::FETCH_ASSOC);
            }
            // Read whether or not the login history exists: an open session is a live fact, not a
            // historical one, and it is the thing an admin needs when a student says the lesson will
            // not play on their laptop.
            if ($selectedStudent) {
                $sessions = $registry->activeFor($studentId);
            }
            if ($selectedStudent && $loginHistoryAvailable) {
                $countsQuery = $db->prepare("SELECT SUM(event_type='success') AS successful, SUM(event_type IN ('failed','blocked')) AS failed FROM lms_login_events WHERE user_id=?");
                $countsQuery->execute([$studentId]);
                $selectedLoginCounts = $countsQuery->fetch() ?: $selectedLoginCounts;
                $eventsQuery = $db->prepare('SELECT event_type,ip_address,created_at FROM lms_login_events WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 50');
                $eventsQuery->execute([$studentId]);
                $activity = $eventsQuery->fetchAll();
            }
        }

        View::render('admin/students', [
            'title' => 'Student management',
            'user' => $admin,
            'students' => $studentRows,
            'search' => $search,
            'statusFilter' => $status,
            'page' => $page,
            'pages' => $pages,
            'totalStudents' => $total,
            'statusCounts' => $statusCounts,
            'selectedStudent' => $selectedStudent,
            'studentCourses' => $studentCourses,
            'selectedLoginCounts' => $selectedLoginCounts,
            'activity' => $activity,
            'sessions' => $sessions,
            'sessionCap' => $registry->maxActive(),
            'sessionsAvailable' => $registry->available(),
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
            $db->prepare('INSERT INTO lms_app_settings(setting_key,setting_value,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)')
                ->execute([AttendanceService::GRACE_KEY, $raw, (int) $admin['id']]);
            $db->prepare("INSERT INTO lms_audit_logs(actor_user_id,action,entity_type,metadata) VALUES(?,'attendance.grace_updated','app_settings',JSON_OBJECT('minutes',?))")
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
            $lock = $db->prepare("SELECT id,status FROM lms_users WHERE id=? AND role='student' FOR UPDATE");
            $lock->execute([$studentId]);
            $student = $lock->fetch();
            if (!$student || !in_array($student['status'], ['active', 'suspended'], true)) throw new RuntimeException('Only active or suspended student accounts can be changed here.');
            if ($student['status'] !== $status) {
                $update = $db->prepare("UPDATE lms_users SET status=? WHERE id=? AND role='student'");
                $update->execute([$status, $studentId]);
                $audit = $db->prepare('INSERT INTO lms_audit_logs(actor_user_id,action,entity_type,entity_id,metadata) VALUES(?,?,?,?,?)');
                $audit->execute([(int) $admin['id'], $status === 'suspended' ? 'student.blocked' : 'student.unblocked', 'user', $studentId, json_encode(['previous_status' => $student['status'], 'status' => $status], JSON_THROW_ON_ERROR)]);
            }
            $db->commit();
            // A block has to reach accounts that are already sitting in a dashboard, not only stop
            // the next sign-in: requireRole() finds the closed session rows on their next request.
            if ($status === 'suspended') {
                (new SessionRegistry($db))->revokeAll($studentId, 'admin_revoked');
            }
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
        $_SESSION['_flash'] = $status === 'suspended'
            ? 'Student blocked. Their next request will end any active session, and sign-in is disabled.'
            : 'Student unblocked. They can sign in again.';
        $this->redirect('/admin/students?student_id=' . $studentId);
    }

    /**
     * Signs one account out of every device it is currently open on.
     *
     * Deliberately not the same thing as a block: a laptop left on a shared desk should stop holding
     * the account's one session without stopping the student, who may be somewhere else entirely.
     * The account stays active, and its next sign-in opens a clean registered session.
     */
    public function revokeSessions(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) throw new RuntimeException('Your session expired. Refresh and try again.');
        $studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        if ($studentId < 1) throw new RuntimeException('Choose a valid student.');
        $db = Database::connection();
        $check = $db->prepare("SELECT id FROM lms_users WHERE id=? AND role='student'");
        $check->execute([$studentId]);
        if (!$check->fetch()) throw new RuntimeException('That account is not a student.');
        $registry = new SessionRegistry($db);
        if (!$registry->available()) {
            $_SESSION['_flash_error'] = 'The session register is not available in this database yet. Import the updated schema first.';
            $this->redirect('/admin/students?student_id=' . $studentId);
        }
        $closed = (int) ($registry->revokeAll($studentId, 'admin_revoked')['closed'] ?? 0);
        $db->prepare('INSERT INTO lms_audit_logs(actor_user_id,action,entity_type,entity_id,metadata) VALUES(?,?,?,?,?)')
            ->execute([(int) $admin['id'], 'session.revoked_all', 'user', $studentId, json_encode(['closed_sessions' => $closed], JSON_THROW_ON_ERROR)]);
        $_SESSION['_flash'] = $closed > 0
            ? 'Signed the account out of ' . $closed . ' device(s). Its next sign-in opens a clean session.'
            : 'That account had no open session to close.';
        $this->redirect('/admin/students?student_id=' . $studentId);
    }

    public function clearActivity(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) throw new RuntimeException('Your session expired. Refresh and try again.');
        $studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        if ($studentId < 1) throw new RuntimeException('Choose a valid student.');
        $db = Database::connection();
        $check = $db->prepare("SELECT id FROM lms_users WHERE id=? AND role='student'");
        $check->execute([$studentId]);
        if (!$check->fetch()) throw new RuntimeException('Student was not found.');
        try {
            $delete = $db->prepare('DELETE FROM lms_login_events WHERE user_id=?');
            $delete->execute([$studentId]);
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() !== '42S02') throw $exception;
            throw new RuntimeException('Login history table is not available yet. Import the updated schema first.');
        }
        $deleted = $delete->rowCount();
        $audit = $db->prepare('INSERT INTO lms_audit_logs(actor_user_id,action,entity_type,entity_id,metadata) VALUES(?,?,?,?,?)');
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
            $this->redirect(PurgeGuard::returnPath('/admin/students'));
        }
    }

    private function performPurgeAll(array $admin): void
    {
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            throw new RuntimeException('Your session expired. Refresh the page and try again.');
        }
        PurgeGuard::assertConfirmed('DELETE ALL STUDENTS');
        $db = Database::connection();
        $total = (int) $db->query("SELECT COUNT(*) FROM lms_users WHERE role='student'")->fetchColumn();
        if ($total === 0) {
            $_SESSION['_flash'] = 'There are no student accounts to delete.';
            $this->redirect(PurgeGuard::returnPath('/admin/students'));
        }
        // Every child row is written against this subquery instead of a placeholder list, so a
        // directory with thousands of test accounts purges in one statement per table.
        $students = "(SELECT id FROM lms_users WHERE role='student')";
        $paths = $db->query("SELECT file_path FROM lms_assignment_submissions WHERE student_id IN $students AND file_path IS NOT NULL
            UNION SELECT payment_proof_path FROM lms_payments WHERE student_id IN $students AND payment_proof_path IS NOT NULL
            UNION SELECT file_path FROM lms_certificates WHERE student_id IN $students AND file_path IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
        $sources = [
            'assignment_submission' => array_map('intval', $db->query("SELECT id FROM lms_assignment_submissions WHERE student_id IN $students")->fetchAll(PDO::FETCH_COLUMN)),
            'payment' => array_map('intval', $db->query("SELECT id FROM lms_payments WHERE student_id IN $students")->fetchAll(PDO::FETCH_COLUMN)),
            'certificate_request' => array_map('intval', $db->query("SELECT id FROM lms_certificates WHERE student_id IN $students")->fetchAll(PDO::FETCH_COLUMN)),
        ];
        $files = PurgeGuard::removeStoredFiles(is_array($paths) ? $paths : []);
        $deleted = [];
        $db->beginTransaction();
        try {
            $statements = [
                'assignment_submissions' => 'DELETE FROM lms_assignment_submissions WHERE student_id IN ' . $students,
                'attendance_records' => 'DELETE FROM lms_attendance_records WHERE student_id IN ' . $students,
                'certificates' => 'DELETE FROM lms_certificates WHERE student_id IN ' . $students,
                'course_grades' => 'DELETE FROM lms_course_grades WHERE student_id IN ' . $students,
                'email_otps' => 'DELETE FROM lms_email_otps WHERE user_id IN ' . $students,
                'lesson_progress' => 'DELETE FROM lms_lesson_progress WHERE student_id IN ' . $students,
                'notification_replies' => 'DELETE FROM lms_notification_replies WHERE student_id IN ' . $students . ' OR recipient_student_id IN ' . $students,
                'notification_recipients' => 'DELETE FROM lms_notification_recipients WHERE student_id IN ' . $students,
                'payments' => 'DELETE FROM lms_payments WHERE student_id IN ' . $students,
                'quiz_attempts' => 'DELETE FROM lms_quiz_attempts WHERE student_id IN ' . $students,
                'coding_challenge_attempts' => 'DELETE FROM lms_coding_challenge_attempts WHERE student_id IN ' . $students,
                'quiz_retake_approvals' => 'DELETE FROM lms_quiz_retake_approvals WHERE student_id IN ' . $students,
                // A report an admin shared with the student holds the last key back: report_shares
                // points at users(id) without a cascade on both student_id and sent_by, so the
                // account delete fails with FK 1451 until the shares filed under these students go.
                'report_shares' => 'DELETE FROM lms_report_shares WHERE student_id IN ' . $students,
                'student_course_access' => 'DELETE FROM lms_student_course_access WHERE student_id IN ' . $students,
                'login_events' => 'DELETE FROM lms_login_events WHERE user_id IN ' . $students,
                'audit_logs' => 'DELETE FROM lms_audit_logs WHERE actor_user_id IN ' . $students,
            ];
            foreach ($statements as $table => $sql) {
                $deleted[$table] = (int) $db->exec($sql);
            }
            // The protection and chat tables are tried separately. A database that has not been
            // re-imported with them is missing nothing a student purge depends on, and a
            // half-imported schema must not stop the directory from clearing. A student's chat is
            // deleted through its thread row: chat_messages hangs off chat_threads with ON DELETE
            // CASCADE, so removing the thread takes every message with it and clears both foreign
            // keys that point back at users (chat_threads.student_id and chat_messages.sender_id).
            // Deleting only the messages - as the admin chat desk does - would leave the thread row
            // holding the student_id key and block the account delete with an FK 1451.
            foreach ([
                'lesson_view_events' => 'DELETE FROM lms_lesson_view_events WHERE student_id IN ' . $students,
                'user_sessions' => 'DELETE FROM lms_user_sessions WHERE user_id IN ' . $students,
                'chat_threads' => 'DELETE FROM lms_chat_threads WHERE student_id IN ' . $students,
            ] as $table => $sql) {
                try {
                    $deleted[$table] = (int) $db->exec($sql);
                } catch (\PDOException $exception) {
                    if ((string) $exception->getCode() !== '42S02') throw $exception;
                    $deleted[$table] = 0;
                }
            }
            // The delivery log keeps its rows and only loses the link to the removed account,
            // because the operator still has to see which message went to whom.
            $deleted['email_logs_unlinked'] = (int) $db->exec("UPDATE lms_email_logs SET related_user_id=NULL WHERE related_user_id IN $students");
            $deleted['accounts'] = (int) $db->exec("DELETE FROM lms_users WHERE role='student'");
            $db->prepare("INSERT INTO lms_audit_logs(actor_user_id,action,entity_type,metadata) VALUES(?, 'student.bulk_deleted', 'user', JSON_OBJECT('accounts',?, 'payments',?, 'submissions',?, 'certificates',?, 'files_removed',?))")
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
            'Deleted %d student account(s) with their enrollments, payments, submissions, grades, sessions, chats and certificates.%s New sign-ups keep running the ID sequence, so no reference is ever reused.',
            $deleted['accounts'],
            $files > 0 ? ' ' . $files . ' uploaded file(s) were removed from storage.' : ''
        );
        $this->redirect(PurgeGuard::returnPath('/admin/students'));
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim((require BASE_PATH . '/config/app.php')['url'], '/') . $path);
        exit;
    }
}
