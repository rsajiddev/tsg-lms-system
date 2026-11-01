<?php
declare(strict_types=1);
namespace App\Services;
use PDO;
use RuntimeException;

/** Private, standalone student-to-Academy conversations. Notifications never enter this service. */
final class ChatService {
    public const LIST_LIMIT = 100;
    public function __construct(private PDO $db) {}
    /** @return list<array<string,mixed>> */
    public function studentThreads(int $studentId): array { return $this->threads('t.student_id = ?', [$studentId]); }
    /** @return list<array<string,mixed>> */
    public function adminThreads(bool $waitingOnly = false): array { return $this->threads($waitingOnly ? 'm.sender_id = t.student_id' : '1=1', []); }
    /** @return list<array<string,mixed>> */
    private function threads(string $where, array $params): array {
        $sql = 'SELECT t.id,t.student_id,t.student_seen_at,t.admin_seen_at,u.full_name AS student_name,u.email AS student_email,m.message AS last_message,m.created_at AS last_at,m.sender_id AS last_author_id,(SELECT COUNT(*) FROM chat_messages cm WHERE cm.thread_id=t.id) AS message_count,(SELECT COUNT(*) FROM chat_messages unseen WHERE unseen.thread_id=t.id AND unseen.sender_id<>t.student_id AND (t.student_seen_at IS NULL OR unseen.created_at>t.student_seen_at)) AS unseen_count FROM chat_threads t JOIN users u ON u.id=t.student_id JOIN chat_messages m ON m.id=(SELECT cm.id FROM chat_messages cm WHERE cm.thread_id=t.id ORDER BY cm.created_at DESC,cm.id DESC LIMIT 1) WHERE ' . $where . ' ORDER BY m.created_at DESC,m.id DESC LIMIT ' . self::LIST_LIMIT;
        $stmt=$this->db->prepare($sql); $stmt->execute($params); return $stmt->fetchAll();
    }
    public function unreadCount(int $studentId): int { $s=$this->db->prepare('SELECT COUNT(*) FROM chat_threads t WHERE t.student_id=? AND EXISTS (SELECT 1 FROM chat_messages m WHERE m.thread_id=t.id AND m.sender_id<>t.student_id AND (t.student_seen_at IS NULL OR m.created_at>t.student_seen_at))'); $s->execute([$studentId]); return (int)$s->fetchColumn(); }
    public function adminThreadCount(): int { return (int)$this->db->query('SELECT COUNT(*) FROM chat_threads')->fetchColumn(); }
    public function waitingCount(): int { return (int)$this->db->query('SELECT COUNT(*) FROM chat_threads t JOIN chat_messages m ON m.id=(SELECT cm.id FROM chat_messages cm WHERE cm.thread_id=t.id ORDER BY cm.created_at DESC,cm.id DESC LIMIT 1) WHERE m.sender_id=t.student_id')->fetchColumn(); }
    public function studentThread(int $studentId): int { $owner=$this->db->prepare("SELECT id FROM users WHERE id=? AND role='student' AND status='active'"); $owner->execute([$studentId]); if(!$owner->fetchColumn()) throw new RuntimeException('Select an active student.'); $s=$this->db->prepare('SELECT id FROM chat_threads WHERE student_id=? LIMIT 1'); $s->execute([$studentId]); $id=(int)$s->fetchColumn(); if($id)return $id; $this->db->prepare('INSERT INTO chat_threads(student_id) VALUES(?)')->execute([$studentId]); return (int)$this->db->lastInsertId(); }
    /** @return array<string,mixed>|null */
    public function thread(int $threadId,int $viewerId,bool $isAdmin): ?array { $s=$this->db->prepare('SELECT t.*,u.full_name AS student_name,u.email AS student_email FROM chat_threads t JOIN users u ON u.id=t.student_id WHERE t.id=?'.($isAdmin?'':' AND t.student_id=?')); $s->execute($isAdmin?[$threadId]:[$threadId,$viewerId]); $r=$s->fetch(); return $r?:null; }
    /** @return list<array<string,mixed>> */
    public function messages(int $threadId): array { $s=$this->db->prepare('SELECT m.id,m.message,m.created_at,m.sender_id AS author_id,u.full_name AS author_name,u.role AS author_role FROM chat_messages m JOIN users u ON u.id=m.sender_id WHERE m.thread_id=? ORDER BY m.created_at,m.id'); $s->execute([$threadId]); return $s->fetchAll(); }
    public function markSeen(int $threadId,bool $isAdmin): void { $this->db->prepare('UPDATE chat_threads SET '.($isAdmin?'admin_seen_at':'student_seen_at').' = UTC_TIMESTAMP() WHERE id=?')->execute([$threadId]); }
    public function send(int $threadId,int $senderId,string $message): void { $message=trim($message); if($threadId<1||$senderId<1||$message===''||mb_strlen($message)>3000)throw new RuntimeException('Write a message of up to 3,000 characters.'); $this->db->prepare('INSERT INTO chat_messages(thread_id,sender_id,message) VALUES(?,?,?)')->execute([$threadId,$senderId,$message]); }
    /** @return list<array<string,mixed>> */
    public function students(): array { return $this->db->query("SELECT id,full_name,email FROM users WHERE role='student' AND status='active' ORDER BY full_name")->fetchAll(); }
    public static function awaitsReply(array $thread): bool { return (int)($thread['last_author_id']??0)===(int)($thread['student_id']??-1); }
}
