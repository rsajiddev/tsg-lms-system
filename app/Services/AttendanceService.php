<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use PDOException;
use RuntimeException;

/** Attendance is recorded for each scheduled live class, not for calendar-day logins. */
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

    public function available(): bool
    {
        try {
            $this->db->query('SELECT course_id,lesson_id FROM attendance_records LIMIT 0');
            return true;
        } catch (PDOException $exception) {
            if (in_array((string) $exception->getCode(), ['42S02', '42S22'], true)) return false;
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
        } catch (PDOException) {
            $stored = 0;
        }
        return $stored >= self::MIN_GRACE_MINUTES && $stored <= self::MAX_GRACE_MINUTES ? $stored : self::DEFAULT_GRACE_MINUTES;
    }

    /** Start of the student's Live + Recorded attendance history; expired courses remain visible. */
    public function activeAccessStartDate(int $studentId): ?string
    {
        if ($studentId < 1 || !AccessPlan::columnStored($this->db, 'student_course_access.plan_code')) return null;
        try {
            $stmt = $this->db->prepare(
                "SELECT DATE(MIN(granted_at)) FROM student_course_access
                 WHERE student_id=? AND status IN ('active','completed','certified') AND plan_code='live_recorded'"
            );
            $stmt->execute([$studentId]);
            $date = $stmt->fetchColumn();
            return is_string($date) && $date !== '' ? $date : null;
        } catch (PDOException $exception) {
            error_log('[ATTENDANCE] Course access could not be verified: ' . $exception->getMessage());
            return null;
        }
    }

    /** Keep the existing login hook, but open records for live sessions that have started. */
    public function openDailySession(int $studentId): void
    {
        if ($studentId > 0) {
            $this->ensureScheduledAttendance($studentId);
            $this->closeExpiredRows($studentId);
        }
    }

    /** Materialise a row for every eligible student at every started, scheduled live class. */
    private function ensureScheduledAttendance(?int $studentId = null): void
    {
        if (!$this->available()
            || !AccessPlan::columnStored($this->db, 'student_course_access.plan_code')
            || !AccessPlan::columnStored($this->db, 'lessons.meeting_url')
            || !AccessPlan::columnStored($this->db, 'lessons.meeting_at')) return;
        try {
            $stmt = $this->db->prepare(
                "INSERT IGNORE INTO attendance_records(student_id,course_id,lesson_id,attendance_date,status,first_login_at,mark_deadline_at)
                 SELECT sca.student_id,cm.course_id,l.id,DATE(l.meeting_at),'pending',NULL,
                        DATE_ADD(l.meeting_at, INTERVAL ? MINUTE)
                 FROM lessons l
                 JOIN course_modules cm ON cm.id=l.module_id
                 JOIN student_course_access sca ON sca.course_id=cm.course_id
                 WHERE l.status='published' AND l.meeting_url IS NOT NULL AND l.meeting_url<>''
                   AND l.meeting_at IS NOT NULL AND l.meeting_at<=UTC_TIMESTAMP()
                   AND sca.plan_code='live_recorded' AND sca.status IN ('active','completed','certified')
                   AND sca.granted_at<=l.meeting_at
                   AND (sca.expires_at IS NULL OR sca.expires_at>=l.meeting_at)" . ($studentId !== null ? ' AND sca.student_id=?' : '')
            );
            $params = [$this->graceMinutes()];
            if ($studentId !== null) $params[] = $studentId;
            $stmt->execute($params);
        } catch (PDOException $exception) {
            error_log('[ATTENDANCE] Scheduled class rows could not be opened: ' . $exception->getMessage());
        }
    }

    /** Close missed class windows. Called on student/admin dashboard and report reads. */
    public function closeExpired(?int $studentId = null): int
    {
        $this->ensureScheduledAttendance($studentId);
        return $this->closeExpiredRows($studentId);
    }

    private function closeExpiredRows(?int $studentId = null): int
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE attendance_records SET status='absent',closed_at=UTC_TIMESTAMP()
                 WHERE course_id IS NOT NULL AND lesson_id IS NOT NULL AND status='pending'
                   AND mark_deadline_at<=UTC_TIMESTAMP()" . ($studentId !== null ? ' AND student_id=?' : '')
            );
            $stmt->execute($studentId !== null ? [$studentId] : []);
            return $stmt->rowCount();
        } catch (PDOException $exception) {
            if (!in_array((string) $exception->getCode(), ['42S02', '42S22'], true)) {
                error_log('[ATTENDANCE] Expired class rows could not be closed: ' . $exception->getMessage());
            }
            return 0;
        }
    }

    /** Today's live class and its saved attendance, or the upcoming class before its start. */
    public function today(int $studentId): ?array
    {
        if ($studentId < 1 || $this->activeAccessStartDate($studentId) === null) return null;
        try {
            $this->ensureScheduledAttendance($studentId);
            $stmt = $this->db->prepare(
                "SELECT ar.id,DATE(l.meeting_at) AS attendance_date,
                        COALESCE(ar.status,IF(l.meeting_at<=UTC_TIMESTAMP(),'pending','scheduled')) AS status,
                        ar.first_login_at,ar.mark_deadline_at,ar.marked_at,l.meeting_at,
                        l.id AS lesson_id,cm.course_id,c.title AS course_title,l.title AS lesson_title
                 FROM lessons l JOIN course_modules cm ON cm.id=l.module_id
                 JOIN courses c ON c.id=cm.course_id
                 JOIN student_course_access sca ON sca.course_id=cm.course_id AND sca.student_id=?
                 LEFT JOIN attendance_records ar ON ar.student_id=sca.student_id AND ar.lesson_id=l.id
                 WHERE l.status='published' AND l.meeting_url IS NOT NULL AND l.meeting_url<>''
                   AND DATE(l.meeting_at)=UTC_DATE() AND sca.plan_code='live_recorded'
                   AND sca.status IN ('active','completed','certified')
                   AND sca.granted_at<=l.meeting_at AND (sca.expires_at IS NULL OR sca.expires_at>=l.meeting_at)
                 ORDER BY l.meeting_at ASC LIMIT 1"
            );
            $stmt->execute([$studentId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (PDOException $exception) {
            error_log('[ATTENDANCE] Today\'s live class could not be read: ' . $exception->getMessage());
            return null;
        }
    }

    /** Confirms presence for today's class only, after it starts and before its mark deadline. */
    public function markPresent(int $studentId): array
    {
        if ($this->activeAccessStartDate($studentId) === null) {
            return ['ok' => false, 'message' => 'Attendance is available for scheduled Live + Recorded classes only.'];
        }
        $this->closeExpired($studentId);
        $row = $this->today($studentId);
        if ($row === null) return ['ok' => false, 'message' => 'There is no scheduled live class for you today.'];
        if ($row['status'] === 'scheduled') return ['ok' => false, 'message' => 'You can mark attendance once the live class starts.'];
        if ($row['status'] === 'present') return ['ok' => true, 'message' => 'Your attendance for today\'s live class is already marked present.'];
        if ($row['status'] === 'absent') return ['ok' => false, 'message' => 'The attendance window for today\'s class has closed. Contact the Academy if you were present.'];
        try {
            $stmt = $this->db->prepare(
                "UPDATE attendance_records SET status='present',marked_at=UTC_TIMESTAMP()
                 WHERE id=? AND student_id=? AND status='pending' AND mark_deadline_at>UTC_TIMESTAMP()"
            );
            $stmt->execute([(int) $row['id'], $studentId]);
            if ($stmt->rowCount() !== 1) return ['ok' => false, 'message' => 'The attendance window closed just now, so this class is recorded as absent.'];
        } catch (PDOException $exception) {
            error_log('[ATTENDANCE] Present mark failed: ' . $exception->getMessage());
            throw new RuntimeException('Your attendance could not be saved right now. Please try again.');
        }
        return ['ok' => true, 'message' => 'Attendance marked present for today\'s live class.'];
    }

    /** Retained for compatibility with older report helpers; attendance now uses scheduled sessions. */
    public static function expectedDays(string $startDate, string $endExclusive, ?string $eligibleStartDate = null, ?string $todayStatus = null): int
    {
        $utc = new \DateTimeZone('UTC');
        $start = new \DateTimeImmutable(substr($startDate, 0, 10), $utc);
        $end = new \DateTimeImmutable(substr($endExclusive, 0, 10), $utc);
        if ($eligibleStartDate !== null && $eligibleStartDate !== '') {
            $eligibleStart = new \DateTimeImmutable(substr($eligibleStartDate, 0, 10), $utc);
            if ($eligibleStart > $start) $start = $eligibleStart;
        }
        $today = new \DateTimeImmutable('today', $utc);
        $days = $start < min($end, $today) ? (int) $start->diff(min($end, $today))->format('%a') : 0;
        if ($start <= $today && $today < $end && in_array(strtolower((string) $todayStatus), ['present','absent'], true)) $days++;
        return $days;
    }

    /** @return array<string,mixed> */
    public function studentSummary(int $studentId, int $windowDays = 30): array
    {
        $summary = [
            'available' => false, 'eligible' => false, 'grace_minutes' => $this->graceMinutes(), 'today' => null,
            'present' => 0, 'absent' => 0, 'pending' => 0, 'rate' => 0.0, 'streak' => 0,
            'coverage_percent' => 0.0, 'total_sessions' => 0, 'decided_sessions' => 0,
            'days' => [], 'history' => [],
        ];
        if ($this->activeAccessStartDate($studentId) === null) return $summary;
        $summary['eligible'] = true;
        if (!$this->available()) return $summary;
        $this->closeExpired($studentId);
        $summary['available'] = true;
        $summary['today'] = $this->today($studentId);
        $windowDays = max(1, min(90, $windowDays));
        $todayDate = gmdate('Y-m-d');
        $windowStart = gmdate('Y-m-d', strtotime('-' . ($windowDays - 1) . ' days UTC'));
        $windowEnd = gmdate('Y-m-d', strtotime('+1 day UTC'));
        try {
            $stmt = $this->db->prepare(
                "SELECT ar.attendance_date,ar.status,ar.first_login_at,ar.marked_at,ar.mark_deadline_at,
                        ar.course_id,ar.lesson_id,c.title AS course_title,l.title AS lesson_title,l.meeting_at
                 FROM attendance_records ar
                 JOIN courses c ON c.id=ar.course_id
                 JOIN lessons l ON l.id=ar.lesson_id
                 JOIN student_course_access sca ON sca.student_id=ar.student_id AND sca.course_id=ar.course_id
                 WHERE ar.student_id=? AND ar.course_id IS NOT NULL
                   AND sca.plan_code='live_recorded' AND sca.status IN ('active','completed','certified')
                   AND l.meeting_at>=sca.granted_at AND (sca.expires_at IS NULL OR l.meeting_at<=sca.expires_at)
                 ORDER BY l.meeting_at DESC"
            );
            $stmt->execute([$studentId]);
            $allSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            error_log('[ATTENDANCE] Session history could not be loaded: ' . $exception->getMessage());
            $summary['available'] = false;
            return $summary;
        }

        $sessionByDate = [];
        foreach ($allSessions as $session) {
            $status = (string) $session['status'];
            if (isset($summary[$status])) $summary[$status]++;
            $summary['total_sessions']++;
            if (in_array($status, ['present','absent'], true)) $summary['decided_sessions']++;
            $date = (string) $session['attendance_date'];
            if ($date >= $windowStart && $date < $windowEnd) $sessionByDate[$date] = $session;
        }
        try {
            $planned = $this->db->prepare(
                "SELECT COUNT(*) FROM lessons l JOIN course_modules cm ON cm.id=l.module_id
                 JOIN student_course_access sca ON sca.course_id=cm.course_id AND sca.student_id=?
                 WHERE l.status='published' AND l.meeting_url IS NOT NULL AND l.meeting_url<>'' AND l.meeting_at IS NOT NULL
                   AND sca.plan_code='live_recorded' AND sca.status IN ('active','completed','certified')
                   AND l.meeting_at>=sca.granted_at AND (sca.expires_at IS NULL OR l.meeting_at<=sca.expires_at)"
            );
            $planned->execute([$studentId]);
            $summary['total_sessions'] = (int) $planned->fetchColumn();
        } catch (PDOException $exception) {
            error_log('[ATTENDANCE] Scheduled session total could not be read: ' . $exception->getMessage());
        }
        $counted = $summary['present'] + $summary['absent'];
        $summary['rate'] = $counted > 0 ? round($summary['present'] / $counted * 100, 1) : 0.0;
        $summary['coverage_percent'] = $summary['total_sessions'] > 0
            ? round($summary['decided_sessions'] / $summary['total_sessions'] * 100, 1) : 0.0;

        for ($offset = $windowDays - 1; $offset >= 0; $offset--) {
            $date = gmdate('Y-m-d', strtotime('-' . $offset . ' days UTC'));
            $session = $sessionByDate[$date] ?? null;
            $summary['days'][] = ['date' => $date, 'status' => $session['status'] ?? 'none'];
        }

        foreach ($allSessions as $session) {
            if ((string) $session['meeting_at'] > gmdate('Y-m-d H:i:s')) continue;
            if ($session['status'] === 'pending') continue;
            if ($session['status'] === 'present') $summary['streak']++;
            else break;
        }
        $summary['history'] = array_slice($allSessions, 0, 7);
        return $summary;
    }

    /** @return array<string,mixed> */
    public function adminSummary(int $windowDays = 14): array
    {
        $snapshot = [
            'available' => false,
            'today' => ['present'=>0,'absent'=>0,'pending'=>0,'total'=>0,'rate'=>0.0],
            'window' => ['present'=>0,'absent'=>0,'rate'=>0], 'days'=>[],
            'studentAttendanceAvailable'=>false,'studentAttendance'=>[],'attention'=>[],
        ];
        if (!$this->available()) return $snapshot;
        $this->closeExpired();
        $snapshot['available'] = true;
        $today = gmdate('Y-m-d');
        $windowDays = max(2, min(60, $windowDays));
        $windowStart = gmdate('Y-m-d', strtotime('-' . ($windowDays - 1) . ' days UTC'));
        $windowEnd = gmdate('Y-m-d', strtotime('+1 day UTC'));
        try {
            $stmt = $this->db->prepare(
                'SELECT status,COUNT(*) AS total FROM attendance_records
                 WHERE course_id IS NOT NULL AND attendance_date=? GROUP BY status'
            );
            $stmt->execute([$today]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($snapshot['today'][$row['status']])) $snapshot['today'][$row['status']] = (int) $row['total'];
            }
            $stmt = $this->db->prepare(
                'SELECT attendance_date,SUM(status=\'present\') AS present_total,SUM(status=\'absent\') AS absent_total
                 FROM attendance_records WHERE course_id IS NOT NULL AND attendance_date>=? AND attendance_date<? GROUP BY attendance_date'
            );
            $stmt->execute([$windowStart,$windowEnd]);
            $byDate = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $byDate[(string) $row['attendance_date']] = [(int)$row['present_total'],(int)$row['absent_total']];
            for ($offset=$windowDays-1;$offset>=0;$offset--) {
                $date=gmdate('Y-m-d',strtotime('-'.$offset.' days UTC'));
                $pair=$byDate[$date] ?? [0,0];
                $snapshot['days'][]=['date'=>$date,'present'=>$pair[0],'absent'=>$pair[1]];
                $snapshot['window']['present'] += $pair[0];
                $snapshot['window']['absent'] += $pair[1];
            }
            $counted=$snapshot['window']['present']+$snapshot['window']['absent'];
            $snapshot['window']['rate']=$counted>0?(int)round($snapshot['window']['present']/$counted*100):0;
            $todayCount=$snapshot['today']['present']+$snapshot['today']['absent'];
            $snapshot['today']['total']=$todayCount+$snapshot['today']['pending'];
            $snapshot['today']['rate']=$todayCount>0?round($snapshot['today']['present']/$todayCount*100,1):0.0;

            $stmt=$this->db->prepare(
                "SELECT u.id,u.full_name,COUNT(ar.id) AS expected,
                        COALESCE(SUM(ar.status='present'),0) AS present,
                        COALESCE(SUM(ar.status='absent'),0) AS absent,
                        COALESCE(SUM(ar.status='pending'),0) AS pending,
                        MAX(CASE WHEN ar.attendance_date=? THEN ar.status ELSE NULL END) AS today_status
                 FROM attendance_records ar JOIN users u ON u.id=ar.student_id
                 WHERE ar.course_id IS NOT NULL AND ar.attendance_date>=? AND ar.attendance_date<?
                 GROUP BY u.id,u.full_name ORDER BY u.full_name"
            );
            $stmt->execute([$today,$windowStart,$windowEnd]);
            foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){
                $decided=(int)$row['present']+(int)$row['absent'];
                $snapshot['studentAttendance'][]=[
                    'id'=>(int)$row['id'],'full_name'=>(string)$row['full_name'],
                    'present'=>(int)$row['present'],'expected'=>(int)$row['expected'],
                    'rate'=>$decided>0?round((int)$row['present']/$decided*100,1):0.0,
                    'today_status'=>(string)($row['today_status']??'none'),
                ];
            }
            $snapshot['studentAttendanceAvailable']=true;
            $stmt=$this->db->query(
                "SELECT u.id,u.full_name,COUNT(*) AS absent_total FROM attendance_records ar
                 JOIN users u ON u.id=ar.student_id WHERE ar.course_id IS NOT NULL AND ar.status='absent'
                   AND ar.attendance_date>=DATE_SUB(UTC_DATE(),INTERVAL 30 DAY)
                 GROUP BY u.id,u.full_name ORDER BY absent_total DESC,u.full_name LIMIT 5"
            );
            $snapshot['attention']=$stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $exception) {
            error_log('[ATTENDANCE] Admin session summary failed: '.$exception->getMessage());
            $snapshot['available']=false;
        }
        return $snapshot;
    }
}
