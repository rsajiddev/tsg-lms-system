<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Creates the in-app notifications that students see in their workspace, so anything an
 * admin publishes or changes reaches the students it concerns and lights up their sidebar
 * badge. Announcements have always done this; this service extends the same mechanism to
 * lessons, quizzes, assignments, course changes, payment decisions, grades and certificates.
 *
 * Notifications for the same object are coalesced for a short window: repeated edits while
 * an admin is working refresh one notification instead of burying the student in duplicates,
 * and refreshing marks it unread again because there genuinely is something new to read.
 *
 * Every method is fail-soft: notification delivery is derived data and must never roll back
 * or block the admin action that triggered it.
 */
final class NotificationService
{
    public const ANNOUNCEMENT = 'announcement';
    public const LESSON = 'lesson';
    public const QUIZ = 'quiz';
    public const ASSIGNMENT = 'assignment';
    public const COURSE = 'course';
    public const PAYMENT = 'payment';
    public const GRADE = 'grade';
    public const CERTIFICATE = 'certificate';

    /** How the sidebar groups notification categories under its navigation links. */
    public const AREAS = [
        'notifications' => [self::ANNOUNCEMENT],
        'lessons' => [self::LESSON],
        'courses' => [self::COURSE, self::CERTIFICATE],
        'assignments' => [self::ASSIGNMENT],
        'quizzes' => [self::QUIZ, self::GRADE],
        'payments' => [self::PAYMENT],
    ];

    /** Repeat edits inside this window refresh the existing notification instead of adding one. */
    public const COALESCE_MINUTES = 30;

    public function __construct(private PDO $db)
    {
    }

    /**
     * Notify every student with live access to a course. Returns the notification id, or 0
     * when there was nothing to deliver.
     */
    public function notifyCourse(
        int $courseId,
        string $category,
        string $title,
        string $body,
        string $sourceType,
        int $sourceId,
        ?string $link = null,
        int $actorId = 0
    ): int {
        if ($courseId < 1 || $sourceId < 1) return 0;
        try {
            $stmt = $this->db->prepare("SELECT student_id FROM student_course_access WHERE course_id = ? AND status IN ('active','completed','certified') AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())");
            $stmt->execute([$courseId]);
            $studentIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (\Throwable $exception) {
            return 0;
        }
        return $this->publish($studentIds, $category, $title, $body, $sourceType, $sourceId, $link, $actorId);
    }

    /** Notify one or more specific students (their own payment, grade or certificate). */
    public function notifyStudents(
        array $studentIds,
        string $category,
        string $title,
        string $body,
        string $sourceType,
        int $sourceId,
        ?string $link = null,
        int $actorId = 0
    ): int {
        $ids = array_values(array_unique(array_filter(array_map('intval', $studentIds), static fn(int $id): bool => $id > 0)));
        return $this->publish($ids, $category, $title, $body, $sourceType, $sourceId, $link, $actorId);
    }

    /**
     * Notify every active, verified student — used when a course is published and the
     * catalogue itself changed.
     */
    public function notifyAllStudents(
        string $category,
        string $title,
        string $body,
        string $sourceType,
        int $sourceId,
        ?string $link = null,
        int $actorId = 0
    ): int {
        return $this->publish([], $category, $title, $body, $sourceType, $sourceId, $link, $actorId, true);
    }

    /**
     * Count unread notifications per workspace area for one student. Drives the sidebar
     * badges, so it must never throw when the notification metadata columns are missing.
     *
     * @return array<string,int> area key => unread count
     */
    public function unreadByArea(int $studentId): array
    {
        $counts = array_fill_keys(array_keys(self::AREAS), 0);
        if ($studentId < 1) return $counts;
        try {
            $stmt = $this->db->prepare('SELECT n.category, COUNT(*) AS total FROM notification_recipients nr JOIN notifications n ON n.id = nr.notification_id WHERE nr.student_id = ? AND nr.read_at IS NULL AND n.published_at IS NOT NULL GROUP BY n.category');
            $stmt->execute([$studentId]);
            $byCategory = [];
            foreach ($stmt->fetchAll() as $row) $byCategory[(string) $row['category']] = (int) $row['total'];
            foreach (self::AREAS as $area => $categories) {
                foreach ($categories as $category) $counts[$area] += (int) ($byCategory[$category] ?? 0);
            }
        } catch (\Throwable $exception) {
            // An older schema without the category column simply shows no badges.
            return $counts;
        }
        return $counts;
    }

    /** Total unread notifications for one student (used when the category column is absent). */
    public function unreadTotal(int $studentId): int
    {
        if ($studentId < 1) return 0;
        try {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM notification_recipients nr JOIN notifications n ON n.id = nr.notification_id WHERE nr.student_id = ? AND nr.read_at IS NULL AND n.published_at IS NOT NULL');
            $stmt->execute([$studentId]);
            return (int) $stmt->fetchColumn();
        } catch (\Throwable $exception) {
            return 0;
        }
    }

    /**
     * Remove the automated notifications raised for one object, so a purge or a deletion
     * does not leave dead links in a student's workspace.
     */
    public function forgetSource(string $sourceType, array $sourceIds = []): int
    {
        if ($sourceType === '') return 0;
        try {
            if ($sourceIds === []) {
                $stmt = $this->db->prepare('SELECT id FROM notifications WHERE source_type = ?');
                $stmt->execute([$sourceType]);
            } else {
                $marks = implode(',', array_fill(0, count($sourceIds), '?'));
                $stmt = $this->db->prepare('SELECT id FROM notifications WHERE source_type = ? AND source_id IN (' . $marks . ')');
                $stmt->execute(array_merge([$sourceType], array_map('intval', $sourceIds)));
            }
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            if ($ids === []) return 0;
            $deleteMarks = implode(',', array_fill(0, count($ids), '?'));
            $this->db->prepare('DELETE FROM notification_replies WHERE notification_id IN (' . $deleteMarks . ')')->execute($ids);
            $this->db->prepare('DELETE FROM notification_recipients WHERE notification_id IN (' . $deleteMarks . ')')->execute($ids);
            $this->db->prepare('DELETE FROM notifications WHERE id IN (' . $deleteMarks . ')')->execute($ids);
            return count($ids);
        } catch (\Throwable $exception) {
            return 0;
        }
    }

    /**
     * Writes one notification plus its recipient rows, reusing a recent notification for the
     * same source when one exists.
     */
    private function publish(
        array $studentIds,
        string $category,
        string $title,
        string $body,
        string $sourceType,
        int $sourceId,
        ?string $link,
        int $actorId,
        bool $allStudents = false
    ): int {
        $title = trim($title);
        $body = trim($body);
        if ($title === '' || $body === '') return 0;
        if (!$allStudents && $studentIds === []) return 0;
        $title = mb_substr($title, 0, 200);
        $body = mb_substr($body, 0, 10000);
        $actor = $this->resolveActor($actorId);
        if ($actor < 1) return 0;

        try {
            $this->db->beginTransaction();
            $existing = null;
            if ($sourceType !== '' && $sourceId > 0) {
                $lookup = $this->db->prepare('SELECT id FROM notifications WHERE source_type = ? AND source_id = ? AND category = ? AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . self::COALESCE_MINUTES . ' MINUTE) ORDER BY id DESC LIMIT 1');
                $lookup->execute([$sourceType, $sourceId, $category]);
                $existing = (int) ($lookup->fetchColumn() ?: 0);
            }
            if ($existing > 0) {
                $this->db->prepare('UPDATE notifications SET title = ?, body = ?, published_at = UTC_TIMESTAMP(), created_by = ? WHERE id = ?')->execute([$title, $body, $actor, $existing]);
                // The content changed again, so the item is worth reading a second time.
                $this->db->prepare('UPDATE notification_recipients SET read_at = NULL WHERE notification_id = ?')->execute([$existing]);
                $this->addRecipients($existing, $studentIds, $allStudents);
                $this->db->commit();
                return $existing;
            }
            $insert = $this->db->prepare('INSERT INTO notifications(created_by, category, title, body, audience, source_type, source_id, link, published_at) VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP())');
            $insert->execute([$actor, $category, $title, $body, $allStudents ? 'all_students' : 'selected_students', $sourceType !== '' ? $sourceType : null, $sourceId > 0 ? $sourceId : null, $link]);
            $notificationId = (int) $this->db->lastInsertId();
            $this->addRecipients($notificationId, $studentIds, $allStudents);
            $this->db->commit();
            return $notificationId;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('[NOTIFY] Automated notification could not be published (' . $exception::class . ').');
            return 0;
        }
    }

    /** Inserts recipients, skipping anyone who already has the row. */
    private function addRecipients(int $notificationId, array $studentIds, bool $allStudents = false): void
    {
        $scope = "u.role = 'student' AND u.status = 'active' AND u.email_verified_at IS NOT NULL";
        if ($allStudents) {
            $this->db->prepare('INSERT INTO notification_recipients(notification_id, student_id) SELECT ?, u.id FROM users u WHERE ' . $scope . ' AND NOT EXISTS (SELECT 1 FROM notification_recipients nr WHERE nr.notification_id = ? AND nr.student_id = u.id)')
                ->execute([$notificationId, $notificationId]);
            return;
        }
        $marks = implode(',', array_fill(0, count($studentIds), '?'));
        $stmt = $this->db->prepare('INSERT INTO notification_recipients(notification_id, student_id) SELECT ?, u.id FROM users u WHERE u.id IN (' . $marks . ') AND ' . $scope . ' AND NOT EXISTS (SELECT 1 FROM notification_recipients nr WHERE nr.notification_id = ? AND nr.student_id = u.id)');
        $stmt->execute(array_merge([$notificationId], $studentIds, [$notificationId]));
    }

    /** Falls back to the senior admin when an action has no recorded actor. */
    private function resolveActor(int $actorId): int
    {
        if ($actorId > 0) return $actorId;
        try {
            $stmt = $this->db->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active' AND email_verified_at IS NOT NULL ORDER BY id LIMIT 1");
            return (int) ($stmt->fetchColumn() ?: 0);
        } catch (\Throwable $exception) {
            return 0;
        }
    }
}
