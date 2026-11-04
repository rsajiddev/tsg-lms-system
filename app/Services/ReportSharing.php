<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Report sharing between the Academy desk and one student.
 *
 * An admin sends one desk for one period to one student. Only the metadata is stored: who sent it,
 * who receives it and — the part that cannot be recomputed later — the period boundaries as they
 * were at the moment of sending, because a report opened in December must still describe November.
 * The document itself is never written to disk; it is rebuilt on every download from those frozen
 * bounds, scoped in SQL to that student's own records so no other name can ever reach the page.
 */
final class ReportSharing
{
    /** The desks a student may be sent. The consolidated academy pack stays with the admin. */
    public const DESKS = ['students', 'attendance', 'quizzes', 'assignments'];

    /** How a desk is named to a student, on their page and in the notification. */
    public static function label(string $type): string
    {
        return match ($type) {
            'attendance' => 'Attendance',
            'quizzes' => 'Quiz performance',
            'assignments' => 'Assignment submissions',
            'students' => 'Activity and attendance',
            default => 'Report',
        };
    }

    /**
     * The same array shape ReportsController::period() produces, but built from stored boundaries
     * instead of "now". The end stays exclusive, exactly as period() returns it, so the queries
     * behave identically whether the window came from the clock or from a share row.
     */
    public static function periodFromBounds(string $key, string $start, string $end): array
    {
        $key = $key === 'weekly' ? 'weekly' : 'monthly';
        $startTs = strtotime($start) ?: time();
        $endTs = strtotime($end) ?: time();
        return [
            'key' => $key,
            'label' => ucfirst($key),
            'start' => date('Y-m-d H:i:s', $startTs),
            'end' => date('Y-m-d H:i:s', $endTs),
            'start_date' => date('M j, Y', $startTs),
            'end_date' => date('M j, Y', $endTs - 1),
        ];
    }

    /** Record a share and return its id, so the caller can point a notification at it. */
    public static function create(PDO $db, int $studentId, string $type, array $period, int $adminId): int
    {
        $stmt = $db->prepare('INSERT INTO report_shares (student_id, report_type, period_key, period_start, period_end, sent_by) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$studentId, $type, $period['key'], $period['start'], $period['end'], $adminId]);
        return (int) $db->lastInsertId();
    }

    public static function find(PDO $db, int $shareId): ?array
    {
        if ($shareId < 1) {
            return null;
        }
        $stmt = $db->prepare('SELECT rs.*, u.full_name AS student_name, u.email AS student_email, s.full_name AS sender_name FROM report_shares rs JOIN users u ON u.id = rs.student_id JOIN users s ON s.id = rs.sent_by WHERE rs.id = ? LIMIT 1');
        $stmt->execute([$shareId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** The shares one student owns, newest first — the list their Reports page shows. */
    public static function forStudent(PDO $db, int $studentId): array
    {
        if ($studentId < 1) {
            return [];
        }
        $stmt = $db->prepare('SELECT rs.*, s.full_name AS sender_name FROM report_shares rs JOIN users s ON s.id = rs.sent_by WHERE rs.student_id = ? ORDER BY rs.created_at DESC, rs.id DESC LIMIT 60');
        $stmt->execute([$studentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** The most recent shares across the academy, so an admin can see what already went out. */
    public static function recent(PDO $db, int $limit = 5): array
    {
        $stmt = $db->prepare('SELECT rs.*, u.full_name AS student_name, u.email AS student_email, s.full_name AS sender_name FROM report_shares rs JOIN users u ON u.id = rs.student_id JOIN users s ON s.id = rs.sent_by ORDER BY rs.created_at DESC, rs.id DESC LIMIT ' . max(1, min(20, $limit)));
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Build one student's copy of a desk. The array is the exact shape DocumentPdf::report()
     * already consumes, and every query carries the student filter, so the rows can only ever be
     * that student's own.
     */
    public static function studentReport(PDO $db, array $share, int $studentId): array
    {
        $type = (string) ($share['report_type'] ?? 'students');
        if (!in_array($type, self::DESKS, true)) {
            $type = 'students';
        }
        $period = self::periodFromBounds(
            (string) ($share['period_key'] ?? 'monthly'),
            (string) ($share['period_start'] ?? 'now'),
            (string) ($share['period_end'] ?? 'now')
        );
        $range = $period['start_date'] . ' – ' . $period['end_date'];
        $report = [
            'type' => $type,
            'period' => $period['label'],
            'period_range' => $range,
            'title' => 'Your ' . strtolower(self::label($type)) . ' report',
            'generated_at' => date('M j, Y · g:i A T'),
            'metrics' => [],
            'chart' => [],
            'columns' => [],
            'rows' => [],
        ];

        return match ($type) {
            'attendance' => self::attendanceReport($db, $report, $studentId, $period),
            'quizzes' => self::quizReport($db, $report, $studentId, $period),
            'assignments' => self::assignmentReport($db, $report, $studentId, $period),
            default => self::activityReport($db, $report, $studentId, $period),
        };
    }

    /** The student's own regularity row: the single aggregate the admin desk shows per student. */
    private static function activityReport(PDO $db, array $report, int $studentId, array $period): array
    {
        $stmt = $db->prepare("SELECT u.full_name,u.email,u.status,u.last_login_at,COUNT(a.id) AS marked_days,SUM(a.status='present') AS present_days,ROUND(100*SUM(a.status='present')/NULLIF(COUNT(a.id),0),1) AS attendance_rate FROM users u LEFT JOIN attendance_records a ON a.student_id=u.id AND a.attendance_date>=DATE(?) AND a.attendance_date<DATE(?) WHERE u.role='student' AND u.id=? GROUP BY u.id");
        $stmt->execute([$period['start'], $period['end'], $studentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $marked = (int) ($row['marked_days'] ?? 0);
        $present = (int) ($row['present_days'] ?? 0);
        $statusCounts = [];
        $countStmt = $db->prepare("SELECT status,COUNT(*) AS total FROM attendance_records WHERE student_id=? AND attendance_date>=DATE(?) AND attendance_date<DATE(?) GROUP BY status");
        $countStmt->execute([$studentId, $period['start'], $period['end']]);
        foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $bucket) {
            $statusCounts[(string) $bucket['status']] = (int) $bucket['total'];
        }
        $absent = (int) ($statusCounts['absent'] ?? 0);
        $report['columns'] = ['Student', 'Email', 'State', 'Present days', 'Attendance'];
        $report['rows'] = $row === [] ? [] : [[
            (string) ($row['full_name'] ?? '—'),
            (string) ($row['email'] ?? '—'),
            (string) ($row['status'] ?? '—'),
            (string) $present,
            ($row['attendance_rate'] ?? null) !== null ? $row['attendance_rate'] . '%' : '—',
        ]];
        $report['metrics'] = [
            ['label' => 'Days marked', 'value' => (string) $marked],
            ['label' => 'Present', 'value' => (string) $present],
            ['label' => 'Absent', 'value' => (string) $absent],
            ['label' => 'Attendance rate', 'value' => $marked > 0 ? number_format(100 * $present / $marked, 1) . '%' : '—'],
        ];
        $report['chart'] = [
            ['label' => 'Present days', 'value' => (float) $present],
            ['label' => 'Absent days', 'value' => (float) $absent],
        ];
        return $report;
    }

    private static function attendanceReport(PDO $db, array $report, int $studentId, array $period): array
    {
        $stmt = $db->prepare('SELECT a.attendance_date,u.full_name,a.status,a.first_login_at,a.marked_at FROM attendance_records a JOIN users u ON u.id=a.student_id WHERE a.student_id=? AND a.attendance_date>=DATE(?) AND a.attendance_date<DATE(?) ORDER BY a.attendance_date DESC LIMIT 60');
        $stmt->execute([$studentId, $period['start'], $period['end']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $present = count(array_filter($rows, static fn(array $r): bool => ($r['status'] ?? '') === 'present'));
        $absent = count(array_filter($rows, static fn(array $r): bool => ($r['status'] ?? '') === 'absent'));
        $pending = count($rows) - $present - $absent;
        $report['columns'] = ['Date', 'Student', 'Status', 'First login', 'Confirmed'];
        $report['rows'] = array_map(static fn(array $r): array => [
            (string) $r['attendance_date'],
            (string) $r['full_name'],
            ucfirst((string) $r['status']),
            (string) ($r['first_login_at'] ?? '—'),
            ($r['marked_at'] ?? '') !== '' ? (string) $r['marked_at'] : '—',
        ], $rows);
        $report['metrics'] = [
            ['label' => 'Records', 'value' => (string) count($rows)],
            ['label' => 'Present', 'value' => (string) $present],
            ['label' => 'Absent', 'value' => (string) $absent],
        ];
        $report['chart'] = [
            ['label' => 'Present', 'value' => (float) $present],
            ['label' => 'Absent', 'value' => (float) $absent],
            ['label' => 'Pending', 'value' => (float) $pending],
        ];
        return $report;
    }

    private static function quizReport(PDO $db, array $report, int $studentId, array $period): array
    {
        $stmt = $db->prepare("SELECT u.full_name,c.title AS course_title,q.title AS quiz_title,qa.score,qa.total_marks,qa.percentage,qa.result,qa.submitted_at FROM quiz_attempts qa JOIN users u ON u.id=qa.student_id JOIN quizzes q ON q.id=qa.quiz_id JOIN courses c ON c.id=q.course_id WHERE qa.student_id=? AND qa.submitted_at>=? AND qa.submitted_at<? AND qa.status IN ('submitted','auto_submitted','timed_out') ORDER BY qa.submitted_at DESC LIMIT 60");
        $stmt->execute([$studentId, $period['start'], $period['end']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $passed = count(array_filter($rows, static fn(array $r): bool => ($r['result'] ?? '') === 'pass'));
        $report['columns'] = ['Student', 'Course', 'Quiz', 'Score', 'Result'];
        $report['rows'] = array_map(static fn(array $r): array => [
            (string) $r['full_name'],
            (string) $r['course_title'],
            (string) $r['quiz_title'],
            ($r['score'] ?? '—') . '/' . ($r['total_marks'] ?? '—'),
            strtoupper((string) ($r['result'] ?? 'pending')),
        ], $rows);
        $report['metrics'] = [
            ['label' => 'Submitted attempts', 'value' => (string) count($rows)],
            ['label' => 'Passed', 'value' => (string) $passed],
            ['label' => 'Failed', 'value' => (string) (count($rows) - $passed)],
        ];
        $report['chart'] = [
            ['label' => 'Passed attempts', 'value' => (float) $passed],
            ['label' => 'Failed attempts', 'value' => (float) (count($rows) - $passed)],
        ];
        return $report;
    }

    private static function assignmentReport(PDO $db, array $report, int $studentId, array $period): array
    {
        $stmt = $db->prepare('SELECT u.full_name,c.title AS course_title,a.title AS assignment_title,s.status,s.marks_awarded,a.max_marks,s.submitted_at FROM assignment_submissions s JOIN users u ON u.id=s.student_id JOIN assignments a ON a.id=s.assignment_id JOIN courses c ON c.id=a.course_id WHERE s.student_id=? AND s.submitted_at>=? AND s.submitted_at<? ORDER BY s.submitted_at DESC LIMIT 60');
        $stmt->execute([$studentId, $period['start'], $period['end']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $reviewed = count(array_filter($rows, static fn(array $r): bool => ($r['status'] ?? '') === 'reviewed'));
        $report['columns'] = ['Student', 'Course', 'Assignment', 'Status', 'Marks'];
        $report['rows'] = array_map(static fn(array $r): array => [
            (string) $r['full_name'],
            (string) $r['course_title'],
            (string) $r['assignment_title'],
            ucwords(str_replace('_', ' ', (string) $r['status'])),
            ($r['marks_awarded'] ?? '—') . '/' . ($r['max_marks'] ?? '—'),
        ], $rows);
        $report['metrics'] = [
            ['label' => 'Submissions', 'value' => (string) count($rows)],
            ['label' => 'Reviewed', 'value' => (string) $reviewed],
            ['label' => 'Awaiting review', 'value' => (string) (count($rows) - $reviewed)],
        ];
        $report['chart'] = [
            ['label' => 'Reviewed', 'value' => (float) $reviewed],
            ['label' => 'Awaiting review', 'value' => (float) (count($rows) - $reviewed)],
        ];
        return $report;
    }
}
