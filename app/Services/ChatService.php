<?php
declare(strict_types=1);
namespace App\Services;
use PDO;
use RuntimeException;

/** Private, standalone student-to-Academy conversations. Notifications never enter this service. */
final class ChatService {
    public const LIST_LIMIT = 100;
    /* Clearing a chat is a stamp kept per side, never a deletion: a side stops seeing what was written
       before its own mark while the other side keeps reading the same conversation. Both names come
       from these fixed lists, so no request can steer the SQL it is built into. */
    public const SIDES = ['student', 'admin'];
    private const CLEARED_COLUMN = ['student' => 'student_cleared_at', 'admin' => 'admin_cleared_at'];
    public function __construct(private PDO $db) {}
    /** @return list<array<string,mixed>> */
    public function studentThreads(int $studentId): array { return $this->threads('t.student_id = ?', [$studentId], 'student'); }
    /** @return list<array<string,mixed>> */
    public function adminThreads(bool $waitingOnly = false): array { return $this->threads($waitingOnly ? 'm.sender_id = t.student_id' : '1=1', [], 'admin'); }
    /** @return list<array<string,mixed>> */
    private function threads(string $where, array $params, string $side): array {
        // The preview, the count and the unread flag are all read over the messages this side can still
        // see, so a cleared chat leaves the list instead of sitting in it looking unanswered.
        $cleared = 't.' . self::CLEARED_COLUMN[$side];
        $sql = 'SELECT t.id,t.student_id,t.student_seen_at,t.admin_seen_at,u.full_name AS student_name,u.email AS student_email,m.message AS last_message,m.created_at AS last_at,m.sender_id AS last_author_id,(SELECT COUNT(*) FROM chat_messages cm WHERE cm.thread_id=t.id AND (' . $cleared . ' IS NULL OR cm.created_at>' . $cleared . ')) AS message_count,(SELECT COUNT(*) FROM chat_messages unseen WHERE unseen.thread_id=t.id AND unseen.sender_id<>t.student_id AND (t.student_seen_at IS NULL OR unseen.created_at>t.student_seen_at) AND (' . $cleared . ' IS NULL OR unseen.created_at>' . $cleared . ')) AS unseen_count FROM chat_threads t JOIN users u ON u.id=t.student_id JOIN chat_messages m ON m.id=(SELECT cm2.id FROM chat_messages cm2 WHERE cm2.thread_id=t.id AND (' . $cleared . ' IS NULL OR cm2.created_at>' . $cleared . ') ORDER BY cm2.created_at DESC,cm2.id DESC LIMIT 1) WHERE ' . $where . ' ORDER BY m.created_at DESC,m.id DESC LIMIT ' . self::LIST_LIMIT;
        $stmt=$this->db->prepare($sql); $stmt->execute($params); return $stmt->fetchAll();
    }
    public function unreadCount(int $studentId): int { $s=$this->db->prepare('SELECT COUNT(*) FROM chat_threads t WHERE t.student_id=? AND EXISTS (SELECT 1 FROM chat_messages m WHERE m.thread_id=t.id AND m.sender_id<>t.student_id AND (t.student_seen_at IS NULL OR m.created_at>t.student_seen_at) AND (t.student_cleared_at IS NULL OR m.created_at>t.student_cleared_at))'); $s->execute([$studentId]); return (int)$s->fetchColumn(); }
    public function adminThreadCount(): int { return (int)$this->db->query('SELECT COUNT(*) FROM chat_threads')->fetchColumn(); }
    public function waitingCount(): int { return (int)$this->db->query('SELECT COUNT(*) FROM chat_threads t JOIN chat_messages m ON m.id=(SELECT cm.id FROM chat_messages cm WHERE cm.thread_id=t.id AND (t.admin_cleared_at IS NULL OR cm.created_at>t.admin_cleared_at) ORDER BY cm.created_at DESC,cm.id DESC LIMIT 1) WHERE m.sender_id=t.student_id')->fetchColumn(); }
    public function studentThread(int $studentId): int { $owner=$this->db->prepare("SELECT id FROM users WHERE id=? AND role='student' AND status='active'"); $owner->execute([$studentId]); if(!$owner->fetchColumn()) throw new RuntimeException('Select an active student.'); $s=$this->db->prepare('SELECT id FROM chat_threads WHERE student_id=? LIMIT 1'); $s->execute([$studentId]); $id=(int)$s->fetchColumn(); if($id)return $id; $this->db->prepare('INSERT INTO chat_threads(student_id) VALUES(?)')->execute([$studentId]); return (int)$this->db->lastInsertId(); }
    /** @return array<string,mixed>|null */
    public function thread(int $threadId,int $viewerId,bool $isAdmin): ?array { $s=$this->db->prepare('SELECT t.*,u.full_name AS student_name,u.email AS student_email FROM chat_threads t JOIN users u ON u.id=t.student_id WHERE t.id=?'.($isAdmin?'':' AND t.student_id=?')); $s->execute($isAdmin?[$threadId]:[$threadId,$viewerId]); $r=$s->fetch(); return $r?:null; }
    /** @return list<array<string,mixed>> */
    public function messages(int $threadId,string $side='student'): array { $cleared='t.'.self::clearedColumn($side); $s=$this->db->prepare('SELECT m.id,m.message,m.created_at,m.sender_id AS author_id,u.full_name AS author_name,u.role AS author_role FROM chat_messages m JOIN chat_threads t ON t.id=m.thread_id JOIN users u ON u.id=m.sender_id WHERE m.thread_id=? AND ('.$cleared.' IS NULL OR m.created_at>'.$cleared.') ORDER BY m.created_at,m.id'); $s->execute([$threadId]); return $s->fetchAll(); }
    public function markSeen(int $threadId,bool $isAdmin): void { $this->db->prepare('UPDATE chat_threads SET '.($isAdmin?'admin_seen_at':'student_seen_at').' = UTC_TIMESTAMP() WHERE id=?')->execute([$threadId]); }
    /**
     * Hides everything this side has already been shown, and reports how much went out of sight. The
     * rows stay: the other side is still reading them, and the next message reopens the chat here too.
     */
    public function clear(int $threadId,string $side): int {
        $cleared='t.'.self::clearedColumn($side);
        $s=$this->db->prepare('SELECT COUNT(*) FROM chat_messages m JOIN chat_threads t ON t.id=m.thread_id WHERE m.thread_id=? AND ('.$cleared.' IS NULL OR m.created_at>'.$cleared.')');
        $s->execute([$threadId]); $hidden=(int)$s->fetchColumn();
        if($hidden<1) return 0;
        $this->db->prepare('UPDATE chat_threads SET '.self::clearedColumn($side).' = UTC_TIMESTAMP() WHERE id=?')->execute([$threadId]);
        return $hidden;
    }
    /**
     * Removes the messages of one conversation for everybody. The thread row survives, so the student
     * can still write and the next message starts a clean page rather than a dead link.
     */
    public function deleteMessages(int $threadId): int {
        $s=$this->db->prepare('DELETE FROM chat_messages WHERE thread_id=?'); $s->execute([$threadId]);
        return $s->rowCount();
    }
    public function send(int $threadId,int $senderId,string $message): void { $message=trim($message); if($threadId<1||$senderId<1||$message===''||mb_strlen($message)>3000)throw new RuntimeException('Write a message of up to 3,000 characters.'); $this->db->prepare('INSERT INTO chat_messages(thread_id,sender_id,message) VALUES(?,?,?)')->execute([$threadId,$senderId,$message]); }
    /** @return list<array<string,mixed>> */
    public function students(): array { return $this->db->query("SELECT id,full_name,email FROM users WHERE role='student' AND status='active' ORDER BY full_name")->fetchAll(); }
    public static function awaitsReply(array $thread): bool { return (int)($thread['last_author_id']??0)===(int)($thread['student_id']??-1); }
    /** The one column a side is allowed to be measured against, or nothing else is ever asked for. */
    private static function clearedColumn(string $side): string {
        if(!in_array($side,self::SIDES,true)) throw new RuntimeException('Unknown chat side.');
        return self::CLEARED_COLUMN[$side];
    }
}
