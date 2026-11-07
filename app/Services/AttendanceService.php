<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;

/**
 * Student attendance.
 *
 * A student with current course access gets one pending row for the current UTC day on sign-in. The student has a
 * configurable grace window (app_settings.attendance_grace_minutes, default 5) to confirm presence
 * from the dashboard; a pending row whose window has elapsed is closed as an absence. There is no
 * cron in this environment, so expired rows are finalised lazily before any attendance read.
 */
final class AttendanceService
{
    public const GRACE_KEY = 'attendance_grace_minutes';
    public const DEFAULT_GRACE_MINUTES = 5;
    public const MIN_GRACE_MINUTES = 1;
    public const MAX_GRACE_MINUTES = 120;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /** True when the attendance schema has been imported. */
    public function available(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM attendance_records LIMIT 0');
            return true;
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() === '42S02') return false;
            error_log('[ATTENDANCE] Availability check failed: ' . $exception->getMessage());
            return false;
        }
    }

    public function graceMinutes(): int
    {
        try {
            $stmt = $this->db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
            $stmt->execute([self::GRACE_KEY]);
            $stored = (int) $stmt->fetchColumn();
        } catch (\PDOException $exception) {
            $stored = 0;
        }
        if ($stored < self::MIN_GRACE_MINUTES || $stored > self::MAX_GRACE_MINUTES) {
            return self::DEFAULT_GRACE_MINUTES;
        }
        return $stored;
    }

    /** Earliest grant date for a course the student can currently access. */
    public function activeAccessStartDate(int $studentId): ?string
    {
        if ($studentId < 1) return null;
        try {
            $stmt = $this->db->prepare(
                "SELECT DATE(MIN(granted_at))
                 FROM student_course_access
                 WHERE student_id = ? AND status = 'active'
                   AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())"
            );
            $stmt->execute([$studentId]);
            $date = $stmt->fetchColumn();
            return is_string($date) && $date !== '' ? $date : null;
        } catch (\PDOException $exception) {
            error_log('[ATTENDANCE] Course access could not be verified: ' . $exception->getMessage());
            return null;
        }
    }

    /**
     * Opens today's attendance row on sign-in. Never blocks authentication: a missing table or a
     * transient failure is logged and ignored so the student still reaches the dashboard.
     */
    public function openDailySession(int $studentId): void
    {
        if ($this->activeAccessStartDate($studentId) === null) return;
        $ip = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ? (string) $_SERVER['REMOTE_ADDR'] : null;
        try {
            $stmt = $this->db->prepare(
                'INSERT IGNORE INTO attendance_records(student_id, attendance_date, status, first_login_at, mark_deadline_at, login_ip)
                 VALUES(?, DATE(UTC_TIMESTAMP()), \'pending\', UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE), ?)'
            );
            $stmt->execute([$studentId, $this->graceMinutes(), $ip]);
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() === '42S02') return;
            error_log('[ATTENDANCE] Today\'s record could not be opened: ' . $exception->getMessage());
        }
    }

    /** Closes every pending row whose grace window has elapsed as an absence. */
    public function closeExpired(): int
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE attendance_records
                 SET status = 'absent', closed_at = UTC_TIMESTAMP()
                 WHERE status = 'pending' AND mark_deadline_at <= UTC_TIMESTAMP()"
            );
            $stmt->execute();
            return $stmt->rowCount();
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() !== '42S02') {
                error_log('[ATTENDANCE] Expired records could not be closed: ' . $exception->getMessage());
            }
            return 0;
        }
    }

    public function today(int $studentId): ?array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT id, attendance_date, status, first_login_at, mark_deadline_at, marked_at
                 FROM attendance_records WHERE student_id = ? AND attendance_date = DATE(UTC_TIMESTAMP()) LIMIT 1'
            );
            $stmt->execute([$studentId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $exception) {
            return null;
        }
        return is_array($row) ? $row : null;
    }

    /**
     * Confirms presence for today while the grace window is still open.
     *
     * @return array{ok: bool, message: string}
     */
    public function markPresent(int $studentId): array
    {
        if ($this->activeAccessStartDate($studentId) === null) {
            return ['ok' => false, 'message' => 'Attendance becomes available after your course access is approved.'];
        }
        $this->closeExpired();
        $row = $this->today($studentId);
        if ($row === null) {
            return ['ok' => false, 'message' => 'Attendance is recorded when you sign in. Please reload this page and try again.'];
        }
        if ($row['status'] === 'present') {
            return ['ok' => true, 'message' => 'Your attendance for today is already marked present.'];
        }
        if ($row['status'] === 'absent') {
            return ['ok' => false, 'message' => 'The attendance window for today has closed, so this day is recorded as absent. Contact the Academy if you were present.'];
        }
        try {
            $stmt = $this->db->prepare(
                "UPDATE attendance_records SET status = 'present', marked_at = UTC_TIMESTAMP()
                 WHERE id = ? AND student_id = ? AND status = 'pending' AND mark_deadline_at > UTC_TIMESTAMP()"
            );
            $stmt->execute([(int) $row['id'], $studentId]);
            if ($stmt->rowCount() !== 1) {
                return ['ok' => false, 'message' => 'The attendance window closed just now, so this day is recorded as absent.'];
            }
        } catch (\PDOException $exception) {
            error_log('[ATTENDANCE] Present mark failed: ' . $exception->getMessage());
            throw new RuntimeException('Your attendance could not be saved right now. Please try again.');
        }
        return ['ok' => true, 'message' => 'Attendance marked present for today.'];
    }

    /**
     * Calendar days a student was expected to attend in a half-open date range.
     * Today counts only after its attendance row is decided; future dates never count.
     */
    public static function expectedDays(string $startDate, string $endExclusive, ?string $eligibleStartDate = null, ?string $todayStatus = null): int
    {
        $utc = new \DateTimeZone('UTC');
        $start = new \DateTimeImmutable(substr($startDate, 0, 10), $utc);
        $end = new \DateTimeImmutable(substr($endExclusive, 0, 10), $utc);
        if ($eligibleStartDate !== null && trim($eligibleStartDate) !== '') {
            $eligibleStart = new \DateTimeImmutable(substr($eligibleStartDate, 0, 10), $utc);
            if ($eligibleStart > $start) $start = $eligibleStart;
        }

        $today = new \DateTimeImmutable('today', $utc);
        $completedEnd = $end < $today ? $end : $today;
        $days = $start < $completedEnd ? (int) $start->diff($completedEnd)->format('%a') : 0;
        $status = strtolower((string) $todayStatus);
        if ($start <= $today && $today < $end && in_array($status, ['present', 'absent'], true)) {
            $days++;
        }
        return $days;
    }

    /**
     * Per-student attendance figures and the recent day strip used on the student dashboard.
     * The student must have at least one currently accessible course, and expected days begin
     * on the earliest active course grant rather than on account registration.
     *
     * @return array{available: bool, eligible: bool, grace_minutes: int, today: ?array, present: int, absent: int, pending: int,
     *               rate: float, streak: int, days: array<int, array{date: string, status: string}>,
     *               history: array<int, array<string, mixed>>}
     */
    public function studentSummary(int $studentId, int $windowDays = 30): array
    {
        $summary = [
            'available' => false,
            'eligible' => false,
            'grace_minutes' => $this->graceMinutes(),
            'today' => null,
            'present' => 0,
            'absent' => 0,
            'pending' => 0,
            'rate' => 0.0,
            'streak' => 0,
            'days' => [],
            'history' => [],
        ];
        $accessStartDate = $this->activeAccessStartDate($studentId);
        if ($accessStartDate === null) return $summary;
        $summary['eligible'] = true;
        if (!$this->available()) return $summary;

        $this->closeExpired();
        $summary['available'] = true;
        $summary['today'] = $this->today($studentId);
        $windowDays = max(1, $windowDays);
        $todayDate = gmdate('Y-m-d');
        $windowStart = (new \DateTimeImmutable($todayDate, new \DateTimeZone('UTC')))
            ->modify('-' . ($windowDays - 1) . ' days')->format('Y-m-d');
        $windowEnd = (new \DateTimeImmutable($todayDate, new \DateTimeZone('UTC')))
            ->modify('+1 day')->format('Y-m-d');
        $effectiveStart = max($windowStart, $accessStartDate);

        try {
            $stmt = $this->db->prepare(
                'SELECT status, COUNT(*) AS total FROM attendance_records
                 WHERE student_id = ? AND attendance_date >= ? AND attendance_date < ?
                 GROUP BY status'
            );
            $stmt->execute([$studentId, $effectiveStart, $windowEnd]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = strtolower((string) $row['status']);
                if (array_key_exists($key, $summary)) $summary[$key] = (int) $row['total'];
            }
        } catch (\PDOException $exception) {
            error_log('[ATTENDANCE] Student window counts could not be read: ' . $exception->getMessage());
            $summary['available'] = false;
            return $summary;
        }

        $expectedDays = self::expectedDays($windowStart, $windowEnd, $accessStartDate, $summary['today']['status'] ?? null);
        $summary['absent'] = max(0, $expectedDays - $summary['present']);
        $summary['rate'] = $expectedDays > 0 ? round($summary['present'] / $expectedDays * 100, 1) : 0.0;

        $byDate = [];
        try {
            $stmt = $this->db->prepare(
                'SELECT attendance_date, status FROM attendance_records
                 WHERE student_id = ? AND attendance_date >= ? AND attendance_date < ?
                 ORDER BY attendance_date DESC'
            );
            $stmt->execute([$studentId, $effectiveStart, $windowEnd]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $byDate[(string) $row['attendance_date']] = (string) $row['status'];
            }
        } catch (\PDOException $exception) {
            // The day strip simply renders as "no record" days.
        }

        // Oldest first so the strip reads left to right, with empty days included.
        for ($offset = $windowDays - 1; $offset >= 0; $offset--) {
            $date = gmdate('Y-m-d', strtotime('-' . $offset . ' days'));
            $status = $byDate[$date] ?? 'none';
            if ($status === 'none' && $date < $todayDate && $date >= $accessStartDate) {
                $status = 'absent';
            }
            $summary['days'][] = ['date' => $date, 'status' => $status];
        }

        // Consecutive present days ending today or yesterday.
        $cursor = time();
        if (($summary['today']['status'] ?? 'none') !== 'present') $cursor = strtotime('-1 day');
        while (isset($byDate[gmdate('Y-m-d', $cursor)]) && $byDate[gmdate('Y-m-d', $cursor)] === 'present') {
            $summary['streak']++;
            $cursor = strtotime('-1 day', $cursor);
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT attendance_date, status, first_login_at, marked_at FROM attendance_records
                 WHERE student_id = ? AND attendance_date >= ? ORDER BY attendance_date DESC LIMIT 7'
            );
            $stmt->execute([$studentId, $accessStartDate]);
            $summary['history'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $exception) {
            $summary['history'] = [];
        }

        return $summary;
    }

    /**
     * Academy-wide attendance figures for the admin overview.
     *
     * @return array{available: bool, today: array{present: int, absent: int, pending: int, total: int, rate: float},
     *               window: array{present: int, absent: int, rate: int}, days: array<int, array{date: string, present: int, absent: int}>,
     *               attention: array<int, array<string, mixed>>}
     */
    public function adminSummary(int $windowDays = 14): array
    {
        $snapshot = [
            'available' => false,
            'today' => ['present' => 0, 'absent' => 0, 'pending' => 0, 'total' => 0, 'rate' => 0.0],
            'window' => ['present' => 0, 'absent' => 0, 'rate' => 0],
            'days' => [],
            'studentAttendanceAvailable' => false,
            'studentAttendance' => [],
            'attention' => [],
        ];
        if (!$this->available()) return $snapshot;

        $this->closeExpired();
        $snapshot['available'] = true;

        try {
            $stmt = $this->db->query(
                "SELECT a.status, COUNT(*) AS total
                 FROM attendance_records a
                 WHERE a.attendance_date = DATE(UTC_TIMESTAMP())
                   AND EXISTS (
                       SELECT 1 FROM student_course_access sca
                       WHERE sca.student_id = a.student_id AND sca.status = 'active'
                         AND (sca.expires_at IS NULL OR sca.expires_at > UTC_TIMESTAMP())
                         AND DATE(sca.granted_at) <= a.attendance_date
                   )
                 GROUP BY a.status"
            );
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = strtolower((string) $row['status']);
                if (isset($snapshot['today'][$key])) $snapshot['today'][$key] = (int) $row['total'];
            }
        } catch (\PDOException $exception) {
            // Today's counters remain zero.
        }
        $today = $snapshot['today'];
        $countedToday = $today['present'] + $today['absent'];
        $snapshot['today']['total'] = $countedToday + $today['pending'];
        $snapshot['today']['rate'] = $countedToday > 0 ? round($today['present'] / $countedToday * 100, 1) : 0.0;

        $windowDays = max(2, min(60, $windowDays));
        $todayDate = gmdate('Y-m-d');
        $windowStart = (new \DateTimeImmutable($todayDate, new \DateTimeZone('UTC')))
            ->modify('-' . ($windowDays - 1) . ' days')->format('Y-m-d');
        $windowEnd = (new \DateTimeImmutable($todayDate, new \DateTimeZone('UTC')))
            ->modify('+1 day')->format('Y-m-d');
        $byDate = [];
        try {
            $stmt = $this->db->prepare(
                "SELECT attendance_date,
                        SUM(status = 'present') AS present_total,
                        SUM(status = 'absent') AS absent_total
                 FROM attendance_records a
                 WHERE a.attendance_date >= DATE_SUB(DATE(UTC_TIMESTAMP()), INTERVAL ? DAY)
                   AND EXISTS (
                       SELECT 1 FROM student_course_access sca
                       WHERE sca.student_id = a.student_id AND sca.status = 'active'
                         AND (sca.expires_at IS NULL OR sca.expires_at > UTC_TIMESTAMP())
                         AND DATE(sca.granted_at) <= a.attendance_date
                   )
                 GROUP BY a.attendance_date"
            );
            $stmt->execute([$windowDays - 1]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $byDate[(string) $row['attendance_date']] = [(int) $row['present_total'], (int) $row['absent_total']];
            }
        } catch (\PDOException $exception) {
            error_log('[ATTENDANCE] Attendance trend could not be read: ' . $exception->getMessage());
            $snapshot['available'] = false;
            return $snapshot;
        }
        $createdByDate = [];
        try {
            $students = $this->db->query(
                "SELECT DATE(access_start) AS created_date, COUNT(*) AS total
                 FROM (
                    SELECT student_id, MIN(granted_at) AS access_start
                    FROM student_course_access
                    WHERE status = 'active' AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
                    GROUP BY student_id
                 ) eligible_students
                 GROUP BY DATE(access_start)
                 ORDER BY created_date"
            );
            foreach ($students->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $createdByDate[(string) $row['created_date']] = (int) $row['total'];
            }
        } catch (\PDOException $exception) {
            error_log('[ATTENDANCE] Student start dates could not be read: ' . $exception->getMessage());
            $snapshot['available'] = false;
            return $snapshot;
        }
        $registered = 0;
        $creationDates = array_keys($createdByDate);
        $creationIndex = 0;
        for ($offset = $windowDays - 1; $offset >= 0; $offset--) {
            $date = gmdate('Y-m-d', strtotime('-' . $offset . ' days'));
            $pair = $byDate[$date] ?? [0, 0];
            while (isset($creationDates[$creationIndex]) && $creationDates[$creationIndex] <= $date) {
                $registered += $createdByDate[$creationDates[$creationIndex]];
                $creationIndex++;
            }
            $absent = $date < $todayDate ? max($pair[1], $registered - $pair[0]) : $pair[1];
            $snapshot['days'][] = ['date' => $date, 'present' => $pair[0], 'absent' => $absent];
            $snapshot['window']['present'] += $pair[0];
            $snapshot['window']['absent'] += $absent;
        }
        $countedWindow = $snapshot['window']['present'] + $snapshot['window']['absent'];
        $snapshot['window']['rate'] = $countedWindow > 0 ? (int) round($snapshot['window']['present'] / $countedWindow * 100) : 0;

        try {
            $stmt = $this->db->prepare(
                "SELECT u.id, u.full_name, DATE(access_grants.access_start) AS access_date,
                        COALESCE(SUM(a.status = 'present'), 0) AS present_total,
                        MAX(CASE WHEN a.attendance_date = ? THEN a.status ELSE NULL END) AS today_status
                 FROM users u
                 JOIN (
                    SELECT student_id, MIN(granted_at) AS access_start
                    FROM student_course_access
                    WHERE status = 'active' AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
                    GROUP BY student_id
                 ) access_grants ON access_grants.student_id = u.id
                 LEFT JOIN attendance_records a
                    ON a.student_id = u.id AND a.attendance_date >= GREATEST(?, DATE(access_grants.access_start))
                       AND a.attendance_date < ?
                 WHERE u.role = 'student'
                 GROUP BY u.id, u.full_name, DATE(access_grants.access_start)
                 ORDER BY u.full_name ASC, u.id ASC"
            );
            $stmt->execute([$todayDate, $windowStart, $windowEnd]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $student) {
                $expected = self::expectedDays(
                    $windowStart,
                    $windowEnd,
                    (string) $student['access_date'],
                    is_string($student['today_status']) ? $student['today_status'] : null
                );
                $present = min($expected, (int) $student['present_total']);
                $snapshot['studentAttendance'][] = [
                    'id' => (int) $student['id'],
                    'full_name' => (string) $student['full_name'],
                    'present' => $present,
                    'expected' => $expected,
                    'rate' => $expected > 0 ? round($present / $expected * 100, 1) : 0.0,
                    'today_status' => is_string($student['today_status']) ? $student['today_status'] : 'none',
                ];
            }
            $snapshot['studentAttendanceAvailable'] = true;
        } catch (\PDOException $exception) {
            error_log('[ATTENDANCE] Student attendance breakdown could not be read: ' . $exception->getMessage());
        }

        try {
            $stmt = $this->db->prepare(
                "SELECT u.id, u.full_name, COUNT(*) AS absent_total
                 FROM attendance_records a
                 JOIN users u ON u.id = a.student_id
                 WHERE a.status = 'absent' AND a.attendance_date >= DATE_SUB(DATE(UTC_TIMESTAMP()), INTERVAL 30 DAY)
                   AND EXISTS (
                       SELECT 1 FROM student_course_access sca
                       WHERE sca.student_id = a.student_id AND sca.status = 'active'
                         AND (sca.expires_at IS NULL OR sca.expires_at > UTC_TIMESTAMP())
                         AND DATE(sca.granted_at) <= a.attendance_date
                   )
                 GROUP BY u.id, u.full_name
                 ORDER BY absent_total DESC, u.full_name ASC LIMIT 5"
            );
            $stmt->execute();
            $snapshot['attention'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $exception) {
            $snapshot['attention'] = [];
        }

        return $snapshot;
    }
}
