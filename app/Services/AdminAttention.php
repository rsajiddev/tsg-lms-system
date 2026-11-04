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
     * two being equal means the student wrote it. A conversation an administrator cleared off the
     * desk is left out too, because a badge that outlives the list it counts is only noise - the
     * student's next message brings both the thread and the number back.
     *
     * @return array<string,string>
     */
    private static function queries(): array
    {
        return [
            // Newest message wins, and only among the ones the desk can still see: a conversation an
            // administrator cleared is left out, because a badge that outlives the list it counts is
            // only noise. The student's next message brings the thread and the number back together.
            'messages' => "SELECT COUNT(*) FROM chat_threads t JOIN chat_messages m ON m.id=(SELECT cm.id FROM chat_messages cm WHERE cm.thread_id=t.id AND (t.admin_cleared_at IS NULL OR cm.created_at>t.admin_cleared_at) ORDER BY cm.created_at DESC,cm.id DESC LIMIT 1) WHERE m.sender_id=t.student_id",
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
