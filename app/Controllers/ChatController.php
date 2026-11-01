<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\Security;
use App\Core\View;
use App\Services\AdminAttention;
use App\Services\ChatService;

/**
 * The chat surfaces of the workspace, kept apart from the announcement board.
 *
 * Students get one page holding only their conversations, with the three actions a chat list is
 * expected to offer: open a thread, clear one, clear all, and mark everything read. Administrators
 * get the desk the sidebar badge has always pointed at: every conversation on the board, newest
 * first, with the ones still waiting on an Academy answer called out - and the same two clearing
 * actions, because a desk you cannot tidy is not a desk.
 *
 * Neither side can destroy a message. Hiding is a flag on the student's own recipient row (the
 * administrator's half of it sits in its own column), so the thread keeps existing for the other
 * party and reappears the moment someone writes in it again - which is why both actions are allowed
 * to be this casual, and why real deleting stays behind the typed-phrase testing cards.
 */
final class ChatController
{
    public function studentIndex(): void
    {
        $student = AuthMiddleware::requireRole('student');
        $chats = new ChatService(Database::connection());
        $threads = $chats->studentThreads((int) $student['id']);
        View::render('student/chats', [
            'title' => 'Chats',
            'user' => $student,
            'threads' => $threads,
            'unreadTotal' => $chats->unreadCount((int) $student['id']),
            'message' => $_SESSION['_flash'] ?? null,
            'error' => $_SESSION['_flash_error'] ?? null,
        ]);
        unset($_SESSION['_flash'], $_SESSION['_flash_error']);
    }

    /**
     * Marks every conversation on this account read in one action.
     *
     * It stamps only chat_seen_at, so the unread news in the inbox is untouched - the two counters
     * answer for different things and a student should be able to clear one without the other.
     */
    public function markAllSeen(): void
    {
        $student = AuthMiddleware::requireRole('student');
        try {
            $this->csrf();
            $marked = (new ChatService(Database::connection()))->markAllSeen((int) $student['id']);
            $_SESSION['_flash'] = $marked > 0
                ? 'Marked ' . number_format($marked) . ' conversation(s) as read.'
                : 'Every conversation was already read.';
        } catch (\Throwable $exception) {
            $_SESSION['_flash_error'] = $exception->getMessage();
        }
        $this->redirect('/student/chats');
    }

    /** Hides one conversation from this student's chat list. */
    public function deleteThread(): void
    {
        $student = AuthMiddleware::requireRole('student');
        $notificationId = (int) ($_POST['notification_id'] ?? 0);
        try {
            $this->csrf();
            $chats = new ChatService(Database::connection());
            $chats->hideThread((int) $student['id'], $notificationId);
            $this->audit((int) $student['id'], 'chat.hidden', $notificationId);
            $_SESSION['_flash'] = 'Conversation removed from your chats. A new message from the Academy brings it back.';
        } catch (\Throwable $exception) {
            $_SESSION['_flash_error'] = $exception->getMessage();
        }
        $this->redirect('/student/chats');
    }

    /** Hides every conversation on this account, leaving the news in the inbox alone. */
    public function deleteAll(): void
    {
        $student = AuthMiddleware::requireRole('student');
        try {
            $this->csrf();
            $hidden = (new ChatService(Database::connection()))->hideAllThreads((int) $student['id']);
            $this->audit((int) $student['id'], 'chat.hidden_all', null);
            $_SESSION['_flash'] = $hidden > 0
                ? 'Cleared ' . number_format($hidden) . ' conversation(s) from your chats. Nothing was deleted from the Academy records.'
                : 'Your chat list was already empty.';
        } catch (\Throwable $exception) {
            $_SESSION['_flash_error'] = $exception->getMessage();
        }
        $this->redirect('/student/chats');
    }

    public function adminIndex(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $filter = (string) ($_GET['filter'] ?? '');
        $waitingOnly = $filter === 'waiting';
        $chats = new ChatService(Database::connection());
        $threads = $chats->adminThreads($waitingOnly);
        $waitingTotal = 0;
        try {
            $waitingTotal = (new AdminAttention(Database::connection()))->pendingByArea()['messages'] ?? 0;
        } catch (\Throwable $exception) {
            // The header count is a courtesy; the list below is the real answer.
            $waitingTotal = 0;
        }
        View::render('admin/chats', [
            'title' => 'Chats',
            'user' => $admin,
            'threads' => $threads,
            'threadTotal' => $chats->adminThreadCount(),
            'waitingOnly' => $waitingOnly,
            'waitingTotal' => (int) $waitingTotal,
            'message' => $_SESSION['_flash'] ?? null,
            'error' => $_SESSION['_flash_error'] ?? null,
        ]);
        unset($_SESSION['_flash'], $_SESSION['_flash_error']);
    }

    /**
     * Hides one conversation from the administrator's desk.
     *
     * The thread stays exactly where it is for the student, and the next message they send puts it
     * back on this desk and back on the badge, so clearing a list here is bookkeeping and not a
     * decision about somebody else's records.
     */
    public function adminDeleteThread(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $studentId = (int) ($_POST['student_id'] ?? 0);
        $notificationId = (int) ($_POST['notification_id'] ?? 0);
        try {
            $this->csrf();
            $chats = new ChatService(Database::connection());
            $chats->adminHideThread($studentId, $notificationId);
            $this->audit((int) $admin['id'], 'chat.admin_hidden', $notificationId);
            $_SESSION['_flash'] = 'Conversation cleared from the desk. The next message from that student brings it back.';
        } catch (\Throwable $exception) {
            $_SESSION['_flash_error'] = $exception->getMessage();
        }
        $this->redirect('/admin/chats');
    }

    /** Hides every conversation on the desk, leaving the announcement board and the students' own lists alone. */
    public function adminDeleteAll(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        try {
            $this->csrf();
            $hidden = (new ChatService(Database::connection()))->adminHideAllThreads();
            $this->audit((int) $admin['id'], 'chat.admin_hidden_all', null);
            $_SESSION['_flash'] = $hidden > 0
                ? 'Cleared ' . number_format($hidden) . ' conversation(s) from the desk. Nothing was deleted from student records.'
                : 'The desk was already empty.';
        } catch (\Throwable $exception) {
            $_SESSION['_flash_error'] = $exception->getMessage();
        }
        $this->redirect('/admin/chats');
    }

    /**
     * Records who cleared what. The message rows themselves are untouched, so the audit trail is
     * the only place a hidden conversation is ever mentioned.
     */
    private function audit(int $actorId, string $action, ?int $notificationId): void
    {
        try {
            Database::connection()
                ->prepare('INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id) VALUES(?,?,?,?)')
                ->execute([$actorId, $action, 'notification', $notificationId]);
        } catch (\Throwable $exception) {
            // An audit row is never worth failing a student's own action over.
        }
    }

    private function csrf(): void
    {
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) throw new \RuntimeException('Your session expired. Refresh and try again.');
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim((require BASE_PATH . '/config/app.php')['url'], '/') . $path);
        exit;
    }
}
