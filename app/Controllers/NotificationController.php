<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\PurgeGuard;
use App\Core\Security;
use App\Core\View;
use App\Services\NotificationService;
use PDO;
use RuntimeException;

final class NotificationController
{
    public function adminIndex(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $db = Database::connection();
        $students = $db->query("SELECT id,full_name,email FROM users WHERE role='student' AND status='active' AND email_verified_at IS NOT NULL ORDER BY full_name")->fetchAll();
        $items = $db->query("SELECT n.id,n.title,n.body,n.category,n.audience,n.published_at,n.created_at,(SELECT COUNT(*) FROM notification_recipients nr WHERE nr.notification_id=n.id) AS recipient_count,(SELECT COUNT(*) FROM notification_replies r WHERE r.notification_id=n.id) AS reply_count FROM notifications n WHERE n.category = 'announcement' OR n.category = '' OR n.category IS NULL ORDER BY n.created_at DESC,n.id DESC LIMIT 100")->fetchAll();
        // The board keeps only what an admin published here. The automated fan-out and the mail log
        // moved to their own desk, because they report on the platform rather than on this page.
        $notificationTotal = (int) $db->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
        View::render('admin/notifications', [
            'title' => 'Announcements',
            'user' => $admin,
            'students' => $students,
            'notifications' => $items,
            'notificationTotal' => $notificationTotal,
            'categories' => NotificationService::AREAS,
            'error' => $_SESSION['_flash_error'] ?? null,
            'message' => $_SESSION['_flash'] ?? null,
        ]);
        unset($_SESSION['_flash'], $_SESSION['_flash_error']);
    }

    /**
     * Recent activity: everything the platform sent on its own, and every e-mail it handed to the
     * mail server.
     *
     * Both lists are read-only reports over data the workspace produces, so the desk is the last
     * entry in the sidebar and the only action on it is the one that clears it for the next test
     * round.
     */
    public function activity(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $db = Database::connection();
        $automated = $db->query("SELECT n.id,n.title,n.category,n.link,n.published_at,(SELECT COUNT(*) FROM notification_recipients nr WHERE nr.notification_id=n.id) AS recipient_count,(SELECT COUNT(*) FROM notification_recipients nr WHERE nr.notification_id=n.id AND nr.read_at IS NULL) AS unread_count FROM notifications n WHERE n.category <> 'announcement' AND n.category <> '' AND n.category IS NOT NULL ORDER BY n.published_at DESC,n.id DESC LIMIT 60")->fetchAll();
        $emailLog = [];
        try {
            $emailLog = $db->query('SELECT template_key,recipient_email,subject,status,error_message,created_at FROM email_logs ORDER BY id DESC LIMIT 50')->fetchAll();
        } catch (\Throwable $exception) {
            // A database imported before the email_logs table simply shows no delivery history.
        }
        $unreadTotal = 0;
        try {
            $unreadTotal = (int) $db->query('SELECT COUNT(*) FROM notification_recipients nr JOIN notifications n ON n.id=nr.notification_id WHERE nr.read_at IS NULL AND n.published_at IS NOT NULL')->fetchColumn();
        } catch (\Throwable $exception) {
            $unreadTotal = 0;
        }
        // Both lists above are capped, so the clearing card counts the whole table instead.
        $automatedTotal = (int) $db->query("SELECT COUNT(*) FROM notifications WHERE category <> 'announcement' AND category <> '' AND category IS NOT NULL")->fetchColumn();
        $emailTotal = 0;
        try {
            $emailTotal = (int) $db->query('SELECT COUNT(*) FROM email_logs')->fetchColumn();
        } catch (\Throwable $exception) {
            $emailTotal = 0;
        }
        View::render('admin/activity', [
            'title' => 'Recent activity',
            'user' => $admin,
            'automated' => $automated,
            'emailLog' => $emailLog,
            'unreadTotal' => $unreadTotal,
            'automatedTotal' => $automatedTotal,
            'emailTotal' => $emailTotal,
            'error' => $_SESSION['_flash_error'] ?? null,
            'message' => $_SESSION['_flash'] ?? null,
        ]);
        unset($_SESSION['_flash'], $_SESSION['_flash_error']);
    }

    public function publish(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $this->csrf();
        $title = trim((string)($_POST['title'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));
        $audience = (string)($_POST['audience'] ?? 'all_students');
        $selected = $_POST['student_ids'] ?? [];
        if (mb_strlen($title) < 3 || mb_strlen($title) > 200 || mb_strlen($body) < 2 || mb_strlen($body) > 10000 || !in_array($audience, ['all_students', 'selected_students'], true) || !is_array($selected)) throw new RuntimeException('Enter a title, message, and valid recipient group.');
        foreach ($selected as $item) if (!is_scalar($item)) throw new RuntimeException('Recipient selection is invalid.');
        $ids = array_values(array_unique(array_filter(array_map('intval', $selected), static fn(int $id): bool => $id > 0)));
        if (count($ids) > 500) throw new RuntimeException('Select no more than 500 students per announcement.');
        $db = Database::connection();
        $db->beginTransaction();
        try {
            if ($audience === 'selected_students' && $ids === []) throw new RuntimeException('Select at least one verified student.');
            $insert = $db->prepare("INSERT INTO notifications(created_by,title,body,audience,category,published_at) VALUES(?,?,?,?, 'announcement',UTC_TIMESTAMP())");
            $insert->execute([(int)$admin['id'], $title, $body, $audience]);
            $notificationId = (int)$db->lastInsertId();
            if ($audience === 'all_students') {
                $db->prepare("INSERT INTO notification_recipients(notification_id,student_id) SELECT ?,id FROM users WHERE role='student' AND status='active' AND email_verified_at IS NOT NULL")->execute([$notificationId]);
            } else {
                $marks = implode(',', array_fill(0, count($ids), '?'));
                $lookup = $db->prepare("SELECT id FROM users WHERE role='student' AND status='active' AND email_verified_at IS NOT NULL AND id IN ($marks)");
                $lookup->execute($ids);
                $valid = array_map('intval', $lookup->fetchAll(PDO::FETCH_COLUMN));
                if (count($valid) !== count($ids)) throw new RuntimeException('One or more selected accounts are not active verified students.');
                $recipient = $db->prepare('INSERT INTO notification_recipients(notification_id,student_id) VALUES(?,?)');
                foreach ($valid as $studentId) $recipient->execute([$notificationId, $studentId]);
            }
            $db->prepare("INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,metadata) VALUES(?,'announcement.published','notification',?,JSON_OBJECT('audience',?,'recipients',?))")->execute([(int)$admin['id'], $notificationId, $audience, $audience === 'all_students' ? 'all' : count($ids)]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        $_SESSION['_flash'] = 'Announcement published to its selected recipients.';
        $this->redirect('/admin/announcements');
    }

    /**
     * Empties the delivery log shown on the Recent activity desk.
     *
     * Only the history rows go: an account that was emailed keeps its account, and the log is
     * rebuilt by the very next message, which is exactly what makes it useful while testing the
     * student, company and developer notifications on a clean slate.
     */
    public function clearEmailLog(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        try {
            $this->performClearEmailLog($admin);
        } catch (\Throwable $exception) {
            $_SESSION['_flash_error'] = $exception->getMessage();
            $this->redirect('/admin/activity');
        }
    }

    private function performClearEmailLog(array $admin): void
    {
        $this->csrf();
        $db = Database::connection();
        try {
            $deleted = (int) $db->exec('DELETE FROM email_logs');
        } catch (\Throwable $exception) {
            throw new RuntimeException('The email delivery log is not available in this database yet. Import the updated schema first.');
        }
        $db->prepare("INSERT INTO audit_logs(actor_user_id,action,entity_type,metadata) VALUES(?, 'email_log.cleared', 'email_log', JSON_OBJECT('deleted_rows', ?))")
            ->execute([(int) $admin['id'], $deleted]);
        $_SESSION['_flash'] = $deleted > 0
            ? 'Cleared ' . number_format($deleted) . ' e-mail delivery record(s).'
            : 'The delivery log was already empty.';
        $this->redirect('/admin/activity');
    }

    /**
     * Clears the Recent activity desk: the automated student updates and the e-mail delivery log.
     *
     * Announcements an admin wrote by hand are not activity the platform produced itself, so they
     * stay on the board and only the typed phrase below retires the machine-made notices. Removing
     * those rows also lowers the unread numbers in student sidebars, because those count exactly
     * these recipient records.
     */
    public function clearActivity(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        try {
            $this->performClearActivity($admin);
        } catch (\Throwable $exception) {
            $_SESSION['_flash_error'] = $exception->getMessage();
            $this->redirect('/admin/activity');
        }
    }

    private function performClearActivity(array $admin): void
    {
        $this->csrf();
        PurgeGuard::assertConfirmed('CLEAR RECENT ACTIVITY');
        $db = Database::connection();
        $ids = array_map('intval', $db->query("SELECT id FROM notifications WHERE category <> 'announcement' AND category <> '' AND category IS NOT NULL ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
        if ($ids === []) {
            $emails = 0;
            try {
                $emailStmt = $db->prepare('DELETE FROM email_logs');
                $emailStmt->execute();
                $emails = $emailStmt->rowCount();
            } catch (\Throwable $exception) {
                throw new RuntimeException('The e-mail delivery log is not available in this database yet. Import the updated schema first.');
            }
            $_SESSION['_flash'] = $emails > 0
                ? 'Cleared ' . number_format($emails) . ' e-mail delivery record(s).'
                : 'Recent activity was already empty.';
            $this->redirect('/admin/activity');
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $db->beginTransaction();
        try {
            // Same order as the board purge: the child rows hold the foreign keys.
            $replyStmt = $db->prepare('DELETE FROM notification_replies WHERE notification_id IN (' . $in . ')');
            $replyStmt->execute($ids);
            $replies = $replyStmt->rowCount();
            $recipientStmt = $db->prepare('DELETE FROM notification_recipients WHERE notification_id IN (' . $in . ')');
            $recipientStmt->execute($ids);
            $recipients = $recipientStmt->rowCount();
            $db->prepare('DELETE FROM notifications WHERE id IN (' . $in . ')')->execute($ids);
            try {
                $emailStmt = $db->prepare('DELETE FROM email_logs');
                $emailStmt->execute();
                $emails = $emailStmt->rowCount();
            } catch (\Throwable $exception) {
                throw new RuntimeException('The e-mail delivery log is not available in this database yet. Import the updated schema first.');
            }
            $db->prepare("INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,metadata) VALUES(?, 'activity.cleared', 'notification', NULL, ?)")
                ->execute([(int) $admin['id'], json_encode([
                    'automated_deleted' => count($ids),
                    'recipients_deleted' => $recipients,
                    'replies_deleted' => $replies,
                    'email_logs_deleted' => $emails,
                ], JSON_THROW_ON_ERROR)]);
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
        $_SESSION['_flash'] = sprintf(
            'Cleared %d automated update(s) with %d recipient record(s), %d private reply/replies and %d e-mail delivery record(s). Published announcements were left on the board.',
            count($ids),
            $recipients,
            $replies,
            $emails
        );
        $this->redirect('/admin/activity');
    }

    /**
     * Empties the announcement board: every notification, its recipient rows and the private
     * replies filed under it.
     *
     * Like the other bulk deletes this is a testing tool, so it stays on the page where it can be
     * found and leans on the typed phrase, the acknowledgement and a server-side re-check instead
     * of on being hidden. Removing the rows also clears the student badges, because those count
     * exactly these recipient rows.
     */
    public function purgeAll(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        try {
            $this->performPurgeAll($admin);
        } catch (\Throwable $exception) {
            $_SESSION['_flash_error'] = $exception->getMessage();
            $this->redirect('/admin/announcements');
        }
    }

    private function performPurgeAll(array $admin): void
    {
        $this->csrf();
        PurgeGuard::assertConfirmed('DELETE ALL ANNOUNCEMENTS');
        $db = Database::connection();
        $ids = array_map('intval', $db->query('SELECT id FROM notifications ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        if ($ids === []) {
            $_SESSION['_flash'] = 'The announcement board is already empty.';
            $this->redirect('/admin/announcements');
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $db->beginTransaction();
        try {
            // Replies first, then the delivery records, then the board itself: the child rows hold
            // the foreign keys, and a half-cleared board would leave notifications nobody can read.
            $replyStmt = $db->prepare('DELETE FROM notification_replies WHERE notification_id IN (' . $in . ')');
            $replyStmt->execute($ids);
            $replies = $replyStmt->rowCount();
            $recipientStmt = $db->prepare('DELETE FROM notification_recipients WHERE notification_id IN (' . $in . ')');
            $recipientStmt->execute($ids);
            $recipients = $recipientStmt->rowCount();
            $db->prepare('DELETE FROM notifications WHERE id IN (' . $in . ')')->execute($ids);
            $db->prepare("INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,metadata) VALUES(?, 'announcement.bulk_deleted', 'notification', NULL, ?)")
                ->execute([(int) $admin['id'], json_encode([
                    'notifications_deleted' => count($ids),
                    'recipients_deleted' => $recipients,
                    'replies_deleted' => $replies,
                ], JSON_THROW_ON_ERROR)]);
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
        $_SESSION['_flash'] = sprintf(
            'Deleted %d announcement(s) with %d recipient record(s) and %d private reply/replies. Student badges that counted them are clear.',
            count($ids),
            $recipients,
            $replies
        );
        $this->redirect('/admin/announcements');
    }

    public function studentIndex(): void
    {
        $student = AuthMiddleware::requireRole('student');
        $stmt = Database::connection()->prepare('SELECT n.id,n.title,n.body,n.category,n.link,n.published_at,nr.read_at FROM notification_recipients nr JOIN notifications n ON n.id=nr.notification_id WHERE nr.student_id=? AND n.published_at IS NOT NULL ORDER BY n.published_at DESC,n.id DESC LIMIT 100');
        $stmt->execute([(int)$student['id']]);
        View::render('notifications/index', ['title' => 'My notifications', 'user' => $student, 'notifications' => $stmt->fetchAll(), 'message' => $_SESSION['_flash'] ?? null, 'error' => $_SESSION['_flash_error'] ?? null]);
        unset($_SESSION['_flash'], $_SESSION['_flash_error']);
    }

    /**
     * Marks the whole inbox read in one action.
     *
     * Every badge in the student sidebar counts rows whose read_at is still NULL, so a student who
     * has read everything still has to open each page to prove it. This is the one action that
     * empties the counters, and it touches only this student's own rows: a shared announcement stays
     * unread for everyone else until they read it themselves.
     */
    public function markAllRead(): void
    {
        $student = AuthMiddleware::requireRole('student');
        try {
            $this->performMarkAllRead($student);
        } catch (\Throwable $exception) {
            // An expired session should land the student back on the inbox with a reason, not on an
            // error page they have to navigate out of.
            $_SESSION['_flash_error'] = $exception->getMessage();
            $this->redirect('/notifications');
        }
    }

    private function performMarkAllRead(array $student): void
    {
        $this->csrf();
        $stmt = Database::connection()->prepare('UPDATE notification_recipients SET read_at = UTC_TIMESTAMP() WHERE student_id = ? AND read_at IS NULL');
        $stmt->execute([(int) $student['id']]);
        $marked = $stmt->rowCount();
        $_SESSION['_flash'] = $marked > 0
            ? 'Marked ' . number_format($marked) . ' notification(s) as read.'
            : 'Nothing was unread.';
        $this->redirect('/notifications');
    }

    public function show(): void
    {
        $user = $this->currentUser();
        // Read through $_GET rather than filter_input(): under HTTP the two are the same, and the
        // render harness can then open a thread without a web server.
        $id = filter_var($_GET['notification_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $db = Database::connection();
        $recipientId = (int)$user['id'];
        $recipients = [];
        if ($user['role'] === 'student') {
            $check = $db->prepare('SELECT 1 FROM notification_recipients nr JOIN notifications n ON n.id=nr.notification_id WHERE nr.notification_id=? AND nr.student_id=? AND n.published_at IS NOT NULL');
            $check->execute([$id, $recipientId]);
            if (!$check->fetchColumn()) {
                $this->notFound();
                return;
            }
            $db->prepare('UPDATE notification_recipients SET read_at=COALESCE(read_at,UTC_TIMESTAMP()) WHERE notification_id=? AND student_id=?')->execute([$id, $recipientId]);
        }
        $stmt = $db->prepare('SELECT n.id,n.title,n.body,n.category,n.link,n.published_at,u.full_name AS author_name FROM notifications n JOIN users u ON u.id=n.created_by WHERE n.id=? AND n.published_at IS NOT NULL');
        $stmt->execute([$id]);
        $notification = $stmt->fetch();
        if (!$notification) {
            $this->notFound();
            return;
        }
        if ($user['role'] === 'admin') {
            $recipientsStmt = $db->prepare('SELECT u.id,u.full_name,u.email,u.account_reference FROM notification_recipients nr JOIN users u ON u.id=nr.student_id WHERE nr.notification_id=? ORDER BY u.full_name');
            $recipientsStmt->execute([$id]);
            $recipients = $recipientsStmt->fetchAll();
            $recipientId = filter_var($_GET['student_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
            if ($recipientId > 0) {
                $allowed = $db->prepare('SELECT 1 FROM notification_recipients WHERE notification_id=? AND student_id=?');
                $allowed->execute([$id, $recipientId]);
                if (!$allowed->fetchColumn()) {
                    $this->notFound();
                    return;
                }
            } else $recipientId = 0;
        } else {
            $recipientId = (int)$user['id'];
        }
        $replies = [];
        if ($recipientId > 0) {
            // author_id is the writer's own account id, so the thread can tell the reader's messages
            // apart from the Academy's without comparing names.
            $replyStmt = $db->prepare('SELECT r.id,r.message,r.created_at,r.student_id AS author_id,u.full_name AS author_name,u.role AS author_role FROM notification_replies r JOIN users u ON u.id=r.student_id WHERE r.notification_id=? AND r.recipient_student_id=? ORDER BY r.created_at,r.id');
            $replyStmt->execute([$id, $recipientId]);
            $replies = $replyStmt->fetchAll();
        }
        $recipient = null;
        foreach ($recipients as $item) if ((int)$item['id'] === $recipientId) {
            $recipient = $item;
            break;
        }
        View::render('notifications/read', ['title' => 'Announcement', 'user' => $user, 'notification' => $notification]);
        unset($_SESSION['_flash'], $_SESSION['_flash_error']);
    }

    public function reply(): void
    {
        $user = $this->currentUser();
        $this->csrf();
        $id = (int)($_POST['notification_id'] ?? 0);
        $message = trim((string)($_POST['message'] ?? ''));
        if ($id < 1 || mb_strlen($message) < 1 || mb_strlen($message) > 3000) throw new RuntimeException('Write a reply of up to 3,000 characters.');
        $db = Database::connection();
        $notification = $db->prepare('SELECT id FROM notifications WHERE id=? AND published_at IS NOT NULL');
        $notification->execute([$id]);
        if (!$notification->fetchColumn()) {
            $this->notFound();
            return;
        }
        $recipientId = (int)$user['id'];
        if ($user['role'] === 'admin') {
            $recipientId = (int)($_POST['recipient_student_id'] ?? 0);
            $scope = $db->prepare('SELECT 1 FROM notification_recipients WHERE notification_id=? AND student_id=?');
            $scope->execute([$id, $recipientId]);
            if ($recipientId < 1 || !$scope->fetchColumn()) {
                $this->notFound();
                return;
            }
        } else {
            $scope = $db->prepare('SELECT 1 FROM notification_recipients WHERE notification_id=? AND student_id=?');
            $scope->execute([$id, $recipientId]);
            if (!$scope->fetchColumn()) {
                $this->notFound();
                return;
            }
        }
        $db->prepare('INSERT INTO notification_replies(notification_id,student_id,recipient_student_id,message) VALUES(?,?,?,?)')->execute([$id, (int)$user['id'], $recipientId, $message]);
        $db->prepare("INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id) VALUES(?,'notification.replied','notification',?)")->execute([(int)$user['id'], $id]);
        $this->redirect('/notifications/view?notification_id=' . $id . ($user['role'] === 'admin' ? '&student_id=' . $recipientId : ''));
    }

    private function currentUser(): array
    {
        $session = $_SESSION['user'] ?? null;
        if (!is_array($session) || !in_array($session['role'] ?? '', ['admin', 'student'], true)) $this->redirect('/login');
        return AuthMiddleware::requireRole((string)$session['role']);
    }
    private function csrf(): void
    {
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) throw new RuntimeException('Your session expired. Refresh and try again.');
    }
    private function notFound(): void
    {
        http_response_code(404);
        View::render('errors/404', ['title' => 'Notification unavailable', 'user' => $_SESSION['user'] ?? []]);
    }
    private function redirect(string $path): never
    {
        header('Location: ' . rtrim((require BASE_PATH . '/config/app.php')['url'], '/') . $path);
        exit;
    }
}
