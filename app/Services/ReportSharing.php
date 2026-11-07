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

    /**
     * app_settings key holding the highest share id the admin has already cleared away. The desk
     * list shows shares above it only, so clearing is a view change rather than a deletion.
     */
    public const RECENT_CEILING_KEY = 'reports_recent_cleared_share_id';

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
        $stmt = $db->prepare('SELECT rs.*, u.full_name AS student_name, u.email AS student_email, s.full_name AS sender_name FROM report_shares rs JOIN users u ON u.id = rs.student_id JOIN users s ON s.id = rs.sent_by WHERE rs.id > ? ORDER BY rs.created_at DESC, rs.id DESC LIMIT ' . max(1, min(20, $limit)));
        $stmt->execute([self::recentCeiling($db)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Push the admin's Recently sent list past everything that already exists.
     *
     * Only the ceiling moves - the share rows stay, so each student still finds the report that was
     * sent to them and their download link keeps working. A share created after the click carries a
     * higher id and comes straight back onto the desk.
     *
     * @return int how many shares the desk stopped showing
     */
    public static function clearRecent(PDO $db, int $adminId): int
    {
        $ceiling = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM report_shares')->fetchColumn();
        $current = self::recentCeiling($db);
        if ($ceiling <= $current) {
            return 0;
        }
        $db->prepare('INSERT INTO app_settings(setting_key,setting_value,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)')
            ->execute([self::RECENT_CEILING_KEY, (string) $ceiling, $adminId]);
        return $ceiling - $current;
    }

    /** The id the desk list starts above, or zero while nothing has ever been cleared. */
    public static function recentCeiling(PDO $db): int
    {
        try {
            $stmt = $db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
            $stmt->execute([self::RECENT_CEILING_KEY]);
            $value = $stmt->fetchColumn();
        } catch (\PDOException $exception) {
            // A database that predates app_settings still has shares to list.
            if ((string) $exception->getCode() !== '42S02') {
                throw $exception;
            }
            return 0;
        }
        return $value === false ? 0 : max(0, (int) $value);
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
        $stampTs = strtotime($period['start']) ?: time();
        $report = [
            'type' => $type,
            'period' => $period['label'],
            'period_range' => $range,
            'title' => 'Your ' . strtolower(self::label($type)) . ' report',
            'generated_at' => date('M j, Y · g:i A T'),
            // The printed copy names its reader and the admin who sent it, and carries the share id, so a
            // page on a student's desk can be matched to the exact row the admin created.
            'audience' => [
                'name' => (string) ($share['student_name'] ?? 'Student'),
                'detail' => 'Shared by ' . (($share['sender_name'] ?? '') !== '' ? (string) $share['sender_name'] : 'the Academy') . ' from the admin Reports desk',
            ],
            'reference' => 'TSG-REP-' . strtoupper(substr($type, 0, 3))
                . '-' . ($period['key'] === 'weekly' ? 'W' : 'M')
                . '-' . date($period['key'] === 'weekly' ? 'o-\WW' : 'Y-m', $stampTs)
                . '-' . str_pad((string) (int) ($share['id'] ?? 0), 4, '0', STR_PAD_LEFT),
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
        (new AttendanceService($db))->closeExpired();
        $stmt = $db->prepare("SELECT u.full_name,u.email,u.status,u.last_login_at,u.account_reference,DATE(u.created_at) AS created_date,COUNT(a.id) AS marked_days,COALESCE(SUM(a.status='present'),0) AS present_days,MAX(CASE WHEN a.attendance_date=UTC_DATE() THEN a.status ELSE NULL END) AS today_status FROM users u LEFT JOIN attendance_records a ON a.student_id=u.id AND a.attendance_date>=DATE(?) AND a.attendance_date<DATE(?) WHERE u.role='student' AND u.id=? GROUP BY u.id");
        $stmt->execute([$period['start'], $period['end'], $studentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $marked = (int) ($row['marked_days'] ?? 0);
        $present = (int) ($row['present_days'] ?? 0);
        $expected = $row === [] ? 0 : AttendanceService::expectedDays(
            substr((string) $period['start'], 0, 10),
            substr((string) $period['end'], 0, 10),
            (string) $row['created_date'],
            is_string($row['today_status'] ?? null) ? $row['today_status'] : null
        );
        $absent = max(0, $expected - $present);
        $reference = $row !== [] ? trim((string) ($row['account_reference'] ?? '')) : '';
        if ($row !== [] && $reference === '') $reference = AccountIdentity::ensure($db, $studentId);
        $rate = $expected > 0 ? round($present / $expected * 100, 1) : null;
        $report['columns'] = ['Student', 'Student ID', 'Email', 'State', 'Present days', 'Absent days', 'Attendance'];
        $report['rows'] = $row === [] ? [] : [[
            (string) ($row['full_name'] ?? '—'),
            $reference,
            (string) ($row['email'] ?? '—'),
            (string) ($row['status'] ?? '—'),
            (string) $present,
            (string) $absent,
            $rate !== null ? $rate . '%' : '—',
        ]];
        $report['metrics'] = [
            ['label' => 'Days marked', 'value' => (string) $marked],
            ['label' => 'Present', 'value' => (string) $present],
            ['label' => 'Absent', 'value' => (string) $absent],
            ['label' => 'Attendance rate', 'value' => $rate !== null ? number_format($rate, 1) . '%' : '—'],
        ];
        $report['chart'] = [
            ['label' => 'Present days', 'value' => (float) $present],
            ['label' => 'Absent days', 'value' => (float) $absent],
        ];
        return $report;
    }

    private static function attendanceReport(PDO $db, array $report, int $studentId, array $period): array
    {
        (new AttendanceService($db))->closeExpired();
        $account = $db->prepare('SELECT full_name,account_reference,DATE(created_at) AS created_date FROM users WHERE id=? AND role=\'student\' LIMIT 1');
        $account->execute([$studentId]);
        $student = $account->fetch(PDO::FETCH_ASSOC) ?: [];
        $reference = $student !== [] ? trim((string) ($student['account_reference'] ?? '')) : '';
        if ($student !== [] && $reference === '') $reference = AccountIdentity::ensure($db, $studentId);
        $stmt = $db->prepare('SELECT attendance_date,status,first_login_at,marked_at FROM attendance_records WHERE student_id=? AND attendance_date>=DATE(?) AND attendance_date<DATE(?) ORDER BY attendance_date DESC LIMIT 60');
        $stmt->execute([$studentId, $period['start'], $period['end']]);
        $byDate = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byDate[(string) $row['attendance_date']] = $row;
        }
        $utc = new \DateTimeZone('UTC');
        $start = new \DateTimeImmutable(substr((string) $period['start'], 0, 10), $utc);
        if (!empty($student['created_date'])) {
            $created = new \DateTimeImmutable(substr((string) $student['created_date'], 0, 10), $utc);
            if ($created > $start) $start = $created;
        }
        $today = new \DateTimeImmutable('today', $utc);
        $end = new \DateTimeImmutable(substr((string) $period['end'], 0, 10), $utc);
        $tomorrow = $today->modify('+1 day');
        $displayEnd = $end < $tomorrow ? $end : $tomorrow;
        $generated = [];
        $present = 0;
        $absent = 0;
        $pending = 0;
        for ($date = $start; $date < $displayEnd; $date = $date->modify('+1 day')) {
            $key = $date->format('Y-m-d');
            $record = $byDate[$key] ?? null;
            $status = is_array($record)
                ? (string) $record['status']
                : ($date < $today ? 'absent' : 'pending');
            if ($status === 'present') $present++;
            elseif ($status === 'absent') $absent++;
            else $pending++;
            $generated[] = [
                $key,
                (string) ($student['full_name'] ?? '—'),
                $reference !== '' ? $reference : '—',
                ucfirst($status),
                is_array($record) ? (string) ($record['first_login_at'] ?? '—') : '—',
                is_array($record) && ($record['marked_at'] ?? '') !== '' ? (string) $record['marked_at'] : '—',
            ];
        }
        $expected = AttendanceService::expectedDays(
            substr((string) $period['start'], 0, 10),
            substr((string) $period['end'], 0, 10),
            (string) ($student['created_date'] ?? ''),
            is_string($byDate[$today->format('Y-m-d')]['status'] ?? null) ? $byDate[$today->format('Y-m-d')]['status'] : null
        );
        $rate = $expected > 0 ? round($present / $expected * 100, 1) : null;
        $report['columns'] = ['Date', 'Student', 'Student ID', 'Status', 'First login', 'Confirmed'];
        $report['rows'] = array_reverse($generated);
        $report['metrics'] = [
            ['label' => 'Expected days', 'value' => (string) $expected],
            ['label' => 'Present', 'value' => (string) $present],
            ['label' => 'Absent', 'value' => (string) $absent],
            ['label' => 'Attendance rate', 'value' => $rate !== null ? number_format($rate, 1) . '%' : '—'],
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
        $stmt = $db->prepare("SELECT u.full_name,u.account_reference,c.title AS course_title,q.title AS quiz_title,qa.score,qa.total_marks,qa.percentage,qa.result,qa.submitted_at FROM quiz_attempts qa JOIN users u ON u.id=qa.student_id JOIN quizzes q ON q.id=qa.quiz_id JOIN courses c ON c.id=q.course_id WHERE qa.student_id=? AND qa.submitted_at>=? AND qa.submitted_at<? AND qa.status IN ('submitted','auto_submitted','timed_out') ORDER BY qa.submitted_at DESC LIMIT 60");
        $stmt->execute([$studentId, $period['start'], $period['end']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $reference = AccountIdentity::reference($studentId);
        $passed = count(array_filter($rows, static fn(array $r): bool => ($r['result'] ?? '') === 'pass'));
        $report['columns'] = ['Student', 'Student ID', 'Course', 'Quiz', 'Score', 'Result'];
        $report['rows'] = array_map(static fn(array $r): array => [
            (string) $r['full_name'],
            $reference,
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
        $stmt = $db->prepare('SELECT u.full_name,u.account_reference,c.title AS course_title,a.title AS assignment_title,s.status,s.marks_awarded,a.max_marks,s.submitted_at FROM assignment_submissions s JOIN users u ON u.id=s.student_id JOIN assignments a ON a.id=s.assignment_id JOIN courses c ON c.id=a.course_id WHERE s.student_id=? AND s.submitted_at>=? AND s.submitted_at<? ORDER BY s.submitted_at DESC LIMIT 60');
        $stmt->execute([$studentId, $period['start'], $period['end']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $reference = AccountIdentity::reference($studentId);
        $reviewed = count(array_filter($rows, static fn(array $r): bool => ($r['status'] ?? '') === 'reviewed'));
        $report['columns'] = ['Student', 'Student ID', 'Course', 'Assignment', 'Status', 'Marks'];
        $report['rows'] = array_map(static fn(array $r): array => [
            (string) $r['full_name'],
            $reference,
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
