<?php
declare(strict_types=1);

/**
 * Attendance smoke test.
 *
 * Run against the local development database:  /Applications/XAMPP/xamppfiles/bin/php tests/attendance-flow-smoke.php
 * A scratch database can be targeted with:     ... --database=tsg_att_check_tmp
 *
 * The script provisions its own test-only student, exercises the whole grace-window lifecycle, and
 * removes every row it created. It never touches another student's attendance.
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Core/Env.php';
require BASE_PATH . '/app/Core/Database.php';
require BASE_PATH . '/app/Services/AttendanceService.php';

\App\Core\Env::load(BASE_PATH . '/.env');
$options = getopt('', ['database::']);
if (isset($options['database']) && is_string($options['database']) && $options['database'] !== '') {
    putenv('DB_NAME=' . $options['database']);
}

$db = \App\Core\Database::connection();
$service = new \App\Services\AttendanceService($db);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

if (!$service->available()) {
    fwrite(STDERR, "SKIP: attendance_records is missing. Import database/migrations/001_initial_schema.sql first.\n");
    exit(1);
}

$grace = $service->graceMinutes();
$studentId = 0;
$courseId = 0;
$graceRestore = null;

try {
    $db->prepare("INSERT INTO lms_users(full_name,email,password_hash,role,status,email_verified_at) VALUES(?,?,?,?, 'active', UTC_TIMESTAMP())")
        ->execute(['Attendance Smoke Test', 'attendance-smoke-' . bin2hex(random_bytes(6)) . '@example.test', 'unusable-hash', 'student']);
    $studentId = (int) $db->lastInsertId();
    $db->prepare('UPDATE lms_users SET created_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 31 DAY) WHERE id=?')->execute([$studentId]);
    $_SERVER['REMOTE_ADDR'] = '203.0.113.44';

    // 1 — attendance remains unavailable until course access is granted.
    $service->openDailySession($studentId);
    $assert($service->today($studentId) === null, 'A student without course access must not get an attendance record.');
    $assert($service->studentSummary($studentId, 30)['eligible'] === false, 'Attendance summary must remain unavailable before access is granted.');
    $assert($service->markPresent($studentId)['ok'] === false, 'A student without course access must not be able to mark attendance.');

    $db->prepare("INSERT INTO lms_courses(title,slug,visibility) VALUES(?,?,'published')")
        ->execute(['Attendance Smoke Course ' . $studentId, 'attendance-smoke-course-' . $studentId]);
    $courseId = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO lms_student_course_access(student_id,course_id,status,granted_by,granted_at) VALUES(?,?,'active',?,DATE_SUB(UTC_TIMESTAMP(), INTERVAL 31 DAY))")
        ->execute([$studentId, $courseId, $studentId]);
    $assert($service->activeAccessStartDate($studentId) === gmdate('Y-m-d', strtotime('-31 days UTC')),
        'Attendance eligibility must begin on the course grant date, not registration date.');

    // 2 — after course access is granted, sign-in opens one pending record for the UTC day.
    $service->openDailySession($studentId);
    $service->openDailySession($studentId);
    $today = $service->today($studentId);
    $assert(is_array($today) && $today['status'] === 'pending', 'Sign-in should open one pending record.');
    $assert((int) $db->query('SELECT COUNT(*) FROM lms_attendance_records WHERE student_id = ' . $studentId)->fetchColumn() === 1, 'A repeated sign-in must not duplicate today\'s record.');
    $deadline = strtotime(((string) $today['mark_deadline_at']) . ' UTC');
    $assert(abs($deadline - (time() + $grace * 60)) < 70, 'The deadline must be the configured grace window after the first sign-in.');

    // 3 — marking inside the window succeeds once and is idempotent afterwards.
    $first = $service->markPresent($studentId);
    $assert($first['ok'] === true, 'Marking present inside the window must succeed.');
    $today = $service->today($studentId);
    $assert($today['status'] === 'present' && $today['marked_at'] !== null, 'The record must store present with a marked-at time.');
    $admin = $service->adminSummary(14);
    $studentAttendance = array_values(array_filter(
        $admin['studentAttendance'],
        static fn(array $row): bool => (int) $row['id'] === $studentId
    ));
    $assert($admin['studentAttendanceAvailable'] === true, 'The admin attendance breakdown must load.');
    $assert($studentAttendance !== [] && $studentAttendance[0]['today_status'] === 'present',
        'The admin attendance breakdown must identify a student who is present today.');
    $assert($studentAttendance[0]['present'] === 1 && $studentAttendance[0]['expected'] === 14
        && abs($studentAttendance[0]['rate'] - (100 / 14)) < 0.1,
        'The admin attendance breakdown must calculate each student\'s rate for the selected window.');
    $assert($service->markPresent($studentId)['ok'] === true, 'Re-marking an already present day must not error.');
    $assert($service->today($studentId)['marked_at'] === $today['marked_at'], 'A second mark must not overwrite the original time.');

    // 4 — an elapsed window is closed as an absence and can never be marked present again.
    $db->prepare("UPDATE lms_attendance_records SET status='pending', marked_at=NULL, closed_at=NULL, mark_deadline_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE student_id = ?")
        ->execute([$studentId]);
    $late = $service->markPresent($studentId);
    $assert($late['ok'] === false, 'An expired window must refuse a present mark.');
    $assert($service->today($studentId)['status'] === 'absent', 'The expired record must be swept to absent.');
    $admin = $service->adminSummary(14);
    $studentAttendance = array_values(array_filter(
        $admin['studentAttendance'],
        static fn(array $row): bool => (int) $row['id'] === $studentId
    ));
    $assert($studentAttendance !== [] && $studentAttendance[0]['today_status'] === 'absent',
        'The admin breakdown must show a student\'s current absent status.');

    // 5 — the lazy sweep also finalises rows left pending from an earlier day.
    $db->prepare("INSERT INTO lms_attendance_records(student_id,attendance_date,status,first_login_at,mark_deadline_at)
                  VALUES(?, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY), 'pending', DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY), DATE_SUB(UTC_TIMESTAMP(), INTERVAL 23 HOUR))")
        ->execute([$studentId]);
    $assert($service->closeExpired() >= 1, 'closeExpired must finalise older pending rows.');
    $closed = $db->prepare("SELECT status, closed_at FROM lms_attendance_records WHERE student_id = ? AND attendance_date = DATE(DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY))");
    $closed->execute([$studentId]);
    $row = $closed->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($row) && $row['status'] === 'absent' && $row['closed_at'] !== null, 'Swept rows must be absent with a closed-at time.');

    // 6 — the unique key makes a hand-inserted duplicate for the same day impossible.
    $duplicateRejected = false;
    try {
        $db->prepare("INSERT INTO lms_attendance_records(student_id,attendance_date,status,first_login_at,mark_deadline_at)
                      VALUES(?, DATE(UTC_TIMESTAMP()), 'present', UTC_TIMESTAMP(), UTC_TIMESTAMP())")
            ->execute([$studentId]);
    } catch (PDOException $exception) {
        $duplicateRejected = (string) $exception->getCode() === '23000';
    }
    $assert($duplicateRejected, 'One student must not be able to hold two records for the same day.');

    // 7 — student and admin summaries stay internally consistent.
    $summary = $service->studentSummary($studentId, 30);
    $assert($summary['available'] === true && count($summary['days']) === 30, 'The day strip must always cover the full window.');
    $assert($summary['present'] === 0 && $summary['absent'] === 30, 'The window must count all elapsed calendar days as present or absent.');
    $assert($summary['days'][0]['status'] === 'absent', 'A past day without a record must be shown as absent.');
    $assert($summary['rate'] === 0.0 && $summary['streak'] === 0, 'Rate and streak must follow the recorded absences.');
    $assert(count($summary['history']) === 2, 'History should list the two recorded days.');
    $db->prepare("UPDATE lms_attendance_records SET status='present', marked_at=UTC_TIMESTAMP() WHERE student_id = ? AND attendance_date = DATE(UTC_TIMESTAMP())")
        ->execute([$studentId]);
    $summary = $service->studentSummary($studentId, 30);
    $assert($summary['present'] === 1 && $summary['absent'] === 29 && abs($summary['rate'] - (100 / 30)) < 0.1, 'One present day in a 30-day window must not read as 100%.');
    $assert(\App\Services\AttendanceService::expectedDays('2026-10-01', '2026-11-01') === 31, 'A completed 31-day month must use all 31 calendar days.');
    $assert(\App\Services\AttendanceService::expectedDays(gmdate('Y-m-01'), gmdate('Y-m-d', strtotime('tomorrow UTC')), null, 'pending') === (int) gmdate('j') - 1, 'A pending mark today must not count until it is decided.');

    $admin = $service->adminSummary(14);
    $assert($admin['available'] === true && count($admin['days']) === 14, 'The admin trend must cover the requested window.');
    $studentAttendance = array_values(array_filter(
        $admin['studentAttendance'],
        static fn(array $row): bool => (int) $row['id'] === $studentId
    ));
    $assert($studentAttendance !== [] && $studentAttendance[0]['today_status'] === 'present',
        'The admin breakdown must reflect a student\'s current present status.');
    $assert($admin['today']['total'] >= 1 && $admin['today']['rate'] >= 0 && $admin['today']['rate'] <= 100, 'Today split must stay inside 0-100%.');
    $assert($admin['window']['present'] + $admin['window']['absent'] >= 2, 'The window totals must include this test\'s rows.');
    $assert($admin['window']['absent'] >= 13, 'The admin window must count unmarked past calendar days as absences.');

    // 8 — revoked or expired access disables the attendance feature and write endpoint.
    $db->prepare('DELETE FROM lms_attendance_records WHERE student_id=?')->execute([$studentId]);
    $db->prepare("UPDATE lms_student_course_access SET status='revoked', revoked_at=UTC_TIMESTAMP() WHERE student_id=? AND course_id=?")
        ->execute([$studentId, $courseId]);
    $service->openDailySession($studentId);
    $assert($service->today($studentId) === null, 'Revoked course access must stop new attendance records.');
    $assert($service->studentSummary($studentId, 30)['eligible'] === false, 'Revoked course access must hide attendance from the student.');
    $assert($service->markPresent($studentId)['ok'] === false, 'Revoked course access must prevent attendance confirmation.');
    $db->prepare("UPDATE lms_student_course_access SET status='active', revoked_at=NULL, expires_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE student_id=? AND course_id=?")
        ->execute([$studentId, $courseId]);
    $service->openDailySession($studentId);
    $assert($service->today($studentId) === null, 'Expired course access must stop new attendance records.');
    $assert($service->studentSummary($studentId, 30)['eligible'] === false, 'Expired course access must hide attendance from the student.');
    $assert($service->markPresent($studentId)['ok'] === false, 'Expired course access must prevent attendance confirmation.');

    // 9 — the grace setting is honoured and clamped. The stored value is restored below.
    $current = $db->prepare('SELECT setting_value FROM lms_app_settings WHERE setting_key = ? LIMIT 1');
    $current->execute([\App\Services\AttendanceService::GRACE_KEY]);
    $graceRestore = $current->fetchColumn();
    $graceRestore = $graceRestore === false ? null : (string) $graceRestore;
    $db->prepare('INSERT INTO lms_app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute([\App\Services\AttendanceService::GRACE_KEY, '1']);
    $assert($service->graceMinutes() === 1, 'A valid admin value must be used as-is.');
    $db->prepare('UPDATE lms_app_settings SET setting_value = ? WHERE setting_key = ?')->execute(['999', \App\Services\AttendanceService::GRACE_KEY]);
    $assert($service->graceMinutes() === \App\Services\AttendanceService::DEFAULT_GRACE_MINUTES, 'An out-of-range grace value must fall back to the default.');

    print("PASS: attendance sign-in window, present mark, expiry sweep, duplicates, summaries and settings.\n");
    print('       Test student #' . $studentId . ' removed.' . PHP_EOL);
} finally {
    if ($studentId > 0) {
        $db->prepare('DELETE FROM lms_attendance_records WHERE student_id = ?')->execute([$studentId]);
        $db->prepare('DELETE FROM lms_student_course_access WHERE student_id = ?')->execute([$studentId]);
        $db->prepare('DELETE FROM lms_users WHERE id = ?')->execute([$studentId]);
    }
    if ($courseId > 0) $db->prepare('DELETE FROM lms_courses WHERE id = ?')->execute([$courseId]);
    // Put the academy's own grace window back exactly as it was found.
    if ($graceRestore === null) {
        $db->prepare('DELETE FROM lms_app_settings WHERE setting_key = ?')->execute([\App\Services\AttendanceService::GRACE_KEY]);
    } else {
        $db->prepare('UPDATE lms_app_settings SET setting_value = ? WHERE setting_key = ?')
            ->execute([$graceRestore, \App\Services\AttendanceService::GRACE_KEY]);
    }
}
