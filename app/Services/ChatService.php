<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * The conversation side of the notification board.
 *
 * A notification is something the Academy published; a chat is the thread of messages that grew
 * under it. Both live on the same rows, so this service reads only the pairs that already carry a
 * message and treats the rest as plain news. Nothing here deletes a notification or a reply: a
 * student who clears a chat is hiding it from their own list, which is why the flag sits on the
 * recipient row and never on the shared notification.
 *
 * Two rules make hiding safe:
 *  - a hidden thread reappears as soon as the other person writes in it, so clearing a chat can
 *    never swallow the Academy's answer to a question the student asked;
 *  - opening a thread clears the hide and stamps chat_seen_at, which is what the Chats badge and
 *    the "Read all" action count against.
 */
final class ChatService
{
    /** How many threads one page lists. Older conversations stay reachable, just not on page one. */
    public const LIST_LIMIT = 100;

    public function __construct(private PDO $db)
    {
    }

    /**
     * Every thread this student has messages in, newest activity first.
     *
     * @return list<array<string,mixed>>
     */
    public function studentThreads(int $studentId): array
    {
        if ($studentId < 1) return [];
        $stmt = $this->db->prepare(
            'SELECT nr.notification_id AS id, n.title, n.published_at, nr.chat_seen_at,
                    (SELECT COUNT(*) FROM notification_replies mine
                      WHERE mine.notification_id = nr.notification_id AND mine.recipient_student_id = nr.student_id) AS message_count,
                    (SELECT latest.created_at FROM notification_replies latest
                      WHERE latest.notification_id = nr.notification_id AND latest.recipient_student_id = nr.student_id
                      ORDER BY latest.created_at DESC, latest.id DESC LIMIT 1) AS last_at,
                    (SELECT latest2.student_id FROM notification_replies latest2
                      WHERE latest2.notification_id = nr.notification_id AND latest2.recipient_student_id = nr.student_id
                      ORDER BY latest2.created_at DESC, latest2.id DESC LIMIT 1) AS last_author_id,
                    (SELECT latest3.message FROM notification_replies latest3
                      WHERE latest3.notification_id = nr.notification_id AND latest3.recipient_student_id = nr.student_id
                      ORDER BY latest3.created_at DESC, latest3.id DESC LIMIT 1) AS last_message,
                    (SELECT COUNT(*) FROM notification_replies incoming
                      WHERE incoming.notification_id = nr.notification_id
                        AND incoming.recipient_student_id = nr.student_id
                        AND incoming.student_id <> nr.student_id
                        AND (nr.chat_seen_at IS NULL OR incoming.created_at > nr.chat_seen_at)) AS unseen_count
             FROM notification_recipients nr
             JOIN notifications n ON n.id = nr.notification_id
             WHERE nr.student_id = ? AND n.published_at IS NOT NULL
               AND EXISTS (SELECT 1 FROM notification_replies r
                            WHERE r.notification_id = nr.notification_id AND r.recipient_student_id = nr.student_id)
               AND (nr.chat_deleted_at IS NULL OR EXISTS (
                     SELECT 1 FROM notification_replies revived
                      WHERE revived.notification_id = nr.notification_id
                        AND revived.recipient_student_id = nr.student_id
                        AND revived.student_id <> nr.student_id
                        AND revived.created_at > nr.chat_deleted_at))
             ORDER BY last_at DESC, nr.notification_id DESC
             LIMIT ' . self::LIST_LIMIT
        );
        $stmt->execute([$studentId]);
        return $stmt->fetchAll();
    }

    /**
     * Threads the Academy has written in since the student last opened them. Drives the sidebar
     * number, so it has to survive a schema that is mid-import.
     */
    public function unreadCount(int $studentId): int
    {
        if ($studentId < 1) return 0;
        try {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM notification_recipients nr
                 JOIN notifications n ON n.id = nr.notification_id
                 WHERE nr.student_id = ? AND n.published_at IS NOT NULL AND nr.chat_deleted_at IS NULL
                   AND EXISTS (SELECT 1 FROM notification_replies r
                                WHERE r.notification_id = nr.notification_id
                                  AND r.recipient_student_id = nr.student_id
                                  AND r.student_id <> nr.student_id
                                  AND (nr.chat_seen_at IS NULL OR r.created_at > nr.chat_seen_at))'
            );
            $stmt->execute([$studentId]);
            return (int) $stmt->fetchColumn();
        } catch (\Throwable $exception) {
            return 0;
        }
    }

    /**
     * The student has opened the thread: it is seen, and a thread they had hidden is un-hidden.
     * Only ever touches this student's own recipient row.
     */
    public function openThread(int $studentId, int $notificationId): void
    {
        if ($studentId < 1 || $notificationId < 1) return;
        $this->db->prepare('UPDATE notification_recipients SET chat_seen_at = UTC_TIMESTAMP(), chat_deleted_at = NULL WHERE student_id = ? AND notification_id = ?')
            ->execute([$studentId, $notificationId]);
    }

    /**
     * Opens every conversation that still has a message the student has not seen. Counting only
     * those rows is what makes the number in the confirmation equal the number on the badge.
     */
    public function markAllSeen(int $studentId): int
    {
        if ($studentId < 1) return 0;
        $stmt = $this->db->prepare(
            'UPDATE notification_recipients nr
             SET nr.chat_seen_at = UTC_TIMESTAMP()
             WHERE nr.student_id = ?
               AND EXISTS (SELECT 1 FROM notification_replies r
                            WHERE r.notification_id = nr.notification_id
                              AND r.recipient_student_id = nr.student_id
                              AND r.student_id <> nr.student_id
                              AND (nr.chat_seen_at IS NULL OR r.created_at > nr.chat_seen_at))'
        );
        $stmt->execute([$studentId]);
        return $stmt->rowCount();
    }

    /**
     * Hides one thread from the student's chat list.
     *
     * Throws when the pair is not a thread this student owns, so a forged id cannot hide somebody
     * else's conversation and the page can report why nothing happened.
     */
    public function hideThread(int $studentId, int $notificationId): void
    {
        if ($studentId < 1 || $notificationId < 1) {
            throw new RuntimeException('That conversation could not be found.');
        }
        $check = $this->db->prepare('SELECT 1 FROM notification_recipients WHERE student_id = ? AND notification_id = ?');
        $check->execute([$studentId, $notificationId]);
        if (!$check->fetchColumn()) {
            throw new RuntimeException('That conversation is not on your account.');
        }
        $this->db->prepare('UPDATE notification_recipients SET chat_deleted_at = UTC_TIMESTAMP() WHERE student_id = ? AND notification_id = ? AND chat_deleted_at IS NULL')
            ->execute([$studentId, $notificationId]);
    }

    /**
     * Hides every conversation on this account. A plain notification with no messages under it is
     * not a chat, so it is left alone and the count stays honest.
     */
    public function hideAllThreads(int $studentId): int
    {
        if ($studentId < 1) return 0;
        $stmt = $this->db->prepare(
            'UPDATE notification_recipients nr
             SET nr.chat_deleted_at = UTC_TIMESTAMP()
             WHERE nr.student_id = ? AND nr.chat_deleted_at IS NULL
               AND EXISTS (SELECT 1 FROM notification_replies r
                            WHERE r.notification_id = nr.notification_id AND r.recipient_student_id = nr.student_id)'
        );
        $stmt->execute([$studentId]);
        return $stmt->rowCount();
    }

    /**
     * Every conversation on the board, newest activity first, with the student it belongs to.
     *
     * @return list<array<string,mixed>>
     */
    public function adminThreads(bool $waitingOnly = false): array
    {
        $waiting = $waitingOnly
            ? 'AND (SELECT latest4.student_id FROM notification_replies latest4
                    WHERE latest4.notification_id = nr.notification_id AND latest4.recipient_student_id = nr.student_id
                    ORDER BY latest4.created_at DESC, latest4.id DESC LIMIT 1) = nr.student_id'
            : '';
        $stmt = $this->db->prepare(
            'SELECT nr.notification_id AS id, nr.student_id, nr.read_at, u.full_name AS student_name, u.email AS student_email,
                    n.title, n.category, n.published_at,
                    (SELECT COUNT(*) FROM notification_replies r
                      WHERE r.notification_id = nr.notification_id AND r.recipient_student_id = nr.student_id) AS message_count,
                    (SELECT latest.created_at FROM notification_replies latest
                      WHERE latest.notification_id = nr.notification_id AND latest.recipient_student_id = nr.student_id
                      ORDER BY latest.created_at DESC, latest.id DESC LIMIT 1) AS last_at,
                    (SELECT latest2.student_id FROM notification_replies latest2
                      WHERE latest2.notification_id = nr.notification_id AND latest2.recipient_student_id = nr.student_id
                      ORDER BY latest2.created_at DESC, latest2.id DESC LIMIT 1) AS last_author_id,
                    (SELECT latest3.message FROM notification_replies latest3
                      WHERE latest3.notification_id = nr.notification_id AND latest3.recipient_student_id = nr.student_id
                      ORDER BY latest3.created_at DESC, latest3.id DESC LIMIT 1) AS last_message
             FROM notification_recipients nr
             JOIN notifications n ON n.id = nr.notification_id
             JOIN users u ON u.id = nr.student_id
             WHERE n.published_at IS NOT NULL
               AND EXISTS (SELECT 1 FROM notification_replies r
                            WHERE r.notification_id = nr.notification_id AND r.recipient_student_id = nr.student_id)
               ' . $waiting . '
             ORDER BY last_at DESC, nr.notification_id DESC
             LIMIT ' . self::LIST_LIMIT
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * How many conversations exist at all. The list below it is capped, so the counter on the
     * filter chip has to come from its own query.
     */
    public function adminThreadCount(): int
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) FROM notification_recipients nr
             JOIN notifications n ON n.id = nr.notification_id
             WHERE n.published_at IS NOT NULL
               AND EXISTS (SELECT 1 FROM notification_replies r
                            WHERE r.notification_id = nr.notification_id AND r.recipient_student_id = nr.student_id)'
        )->fetchColumn();
    }

    /**
     * Whether the newest message in a listed thread came from the student, which is exactly the
     * work the "messages" badge counts: an answer from the Academy is still owed.
     *
     * @param array<string,mixed> $thread
     */
    public static function awaitsReply(array $thread): bool
    {
        return (int) ($thread['last_author_id'] ?? 0) === (int) ($thread['student_id'] ?? -1);
    }
}
