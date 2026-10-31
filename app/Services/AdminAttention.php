<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * What is waiting for an administrator.
 *
 * The student sidebar counts unread notifications, because that is the queue a student works
 * through. An administrator has no inbox of their own, so the same idea is built from the open
 * states a student can leave behind: a message with no Academy answer yet, a certificate request
 * nobody has decided, an assignment submission nobody has reviewed, a quiz that could not be
 * graded or is waiting on a retake decision, a payment proof nobody has checked, and a review
 * nobody has moderated.
 *
 * Every figure counts rows that are still open, so a badge clears when the work is done rather
 * than when a page happens to be looked at, and a reload cannot lose it. Each query is guarded on
 * its own: a database that has not imported one of these tables still renders its navigation, just
 * without that badge.
 */
final class AdminAttention
{
    /** Sidebar keys, in the order the navigation lists them. */
    public const AREAS = ['messages', 'certificates', 'assignments', 'quizzes', 'payments', 'reviews'];

    public function __construct(private PDO $db)
    {
    }

    /**
     * Open work per sidebar area, plus a `total` for the Overview link.
     *
     * @return array<string,int>
     */
    public function pendingByArea(): array
    {
        $counts = array_fill_keys(self::AREAS, 0);
        foreach (self::queries() as $area => $sql) {
            try {
                $counts[$area] += (int) $this->db->query($sql)->fetchColumn();
            } catch (\Throwable $exception) {
                // Navigation is not the place to report a missing table.
                $counts[$area] = 0;
            }
        }
        $counts['total'] = array_sum($counts);
        return $counts;
    }

    /**
     * A thread is unanswered when its newest message was written by the student it belongs to: the
     * reply row stores its author in `student_id` and the thread in `recipient_student_id`, so the
     * two being equal means the student wrote it.
     *
     * @return array<string,string>
     */
    private static function queries(): array
    {
        return [
            'messages' => "SELECT COUNT(DISTINCT r.notification_id, r.recipient_student_id) FROM notification_replies r
                WHERE r.student_id = r.recipient_student_id AND NOT EXISTS (
                    SELECT 1 FROM notification_replies answered
                    WHERE answered.notification_id = r.notification_id
                      AND answered.recipient_student_id = r.recipient_student_id
                      AND answered.student_id <> answered.recipient_student_id
                      AND (answered.created_at > r.created_at
                           OR (answered.created_at = r.created_at AND answered.id > r.id)))",
            'certificates' => "SELECT COUNT(*) FROM certificates WHERE status = 'requested'",
            'assignments' => "SELECT COUNT(*) FROM assignment_submissions WHERE status = 'submitted'",
            // A finished attempt with no result is one the automatic grading could not settle, and
            // a retake request sits on the same desk until an administrator decides it.
            'quizzes' => "SELECT (SELECT COUNT(*) FROM quiz_retake_approvals WHERE request_status = 'pending')
                + (SELECT COUNT(*) FROM quiz_attempts WHERE status <> 'in_progress'
                       AND submitted_at IS NOT NULL AND result IS NULL)",
            'payments' => "SELECT COUNT(*) FROM payments WHERE status = 'pending'",
            'reviews' => "SELECT COUNT(*) FROM reviews WHERE status = 'pending'",
        ];
    }
}
