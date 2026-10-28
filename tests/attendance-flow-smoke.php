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
$graceRestore = null;

try {
    $db->prepare("INSERT INTO users(full_name,email,password_hash,role,status,email_verified_at) VALUES(?,?,?,?, 'active', UTC_TIMESTAMP())")
        ->execute(['Attendance Smoke Test', 'attendance-smoke-' . bin2hex(random_bytes(6)) . '@example.test', 'unusable-hash', 'student']);
    $studentId = (int) $db->lastInsertId();
    $_SERVER['REMOTE_ADDR'] = '203.0.113.44';

    // 1 — a sign-in opens exactly one pending record for the UTC day.
    $service->openDailySession($studentId);
    $service->openDailySession($studentId);
    $today = $service->today($studentId);
    $assert(is_array($today) && $today['status'] === 'pending', 'Sign-in should open one pending record.');
    $assert((int) $db->query('SELECT COUNT(*) FROM attendance_records WHERE student_id = ' . $studentId)->fetchColumn() === 1, 'A repeated sign-in must not duplicate today\'s record.');
    $deadline = strtotime(((string) $today['mark_deadline_at']) . ' UTC');
    $assert(abs($deadline - (time() + $grace * 60)) < 70, 'The deadline must be the configured grace window after the first sign-in.');

    // 2 — marking inside the window succeeds once and is idempotent afterwards.
    $first = $service->markPresent($studentId);
    $assert($first['ok'] === true, 'Marking present inside the window must succeed.');
    $today = $service->today($studentId);
    $assert($today['status'] === 'present' && $today['marked_at'] !== null, 'The record must store present with a marked-at time.');
    $assert($service->markPresent($studentId)['ok'] === true, 'Re-marking an already present day must not error.');
    $assert($service->today($studentId)['marked_at'] === $today['marked_at'], 'A second mark must not overwrite the original time.');

    // 3 — an elapsed window is closed as an absence and can never be marked present again.
    $db->prepare("UPDATE attendance_records SET status='pending', marked_at=NULL, closed_at=NULL, mark_deadline_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE student_id = ?")
        ->execute([$studentId]);
    $late = $service->markPresent($studentId);
    $assert($late['ok'] === false, 'An expired window must refuse a present mark.');
    $assert($service->today($studentId)['status'] === 'absent', 'The expired record must be swept to absent.');

    // 4 — the lazy sweep also finalises rows left pending from an earlier day.
    $db->prepare("INSERT INTO attendance_records(student_id,attendance_date,status,first_login_at,mark_deadline_at)
                  VALUES(?, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY), 'pending', DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY), DATE_SUB(UTC_TIMESTAMP(), INTERVAL 23 HOUR))")
        ->execute([$studentId]);
    $assert($service->closeExpired() >= 1, 'closeExpired must finalise older pending rows.');
    $closed = $db->prepare("SELECT status, closed_at FROM attendance_records WHERE student_id = ? AND attendance_date = DATE(DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY))");
    $closed->execute([$studentId]);
    $row = $closed->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($row) && $row['status'] === 'absent' && $row['closed_at'] !== null, 'Swept rows must be absent with a closed-at time.');

    // 5 — the unique key makes a hand-inserted duplicate for the same day impossible.
    $duplicateRejected = false;
    try {
        $db->prepare("INSERT INTO attendance_records(student_id,attendance_date,status,first_login_at,mark_deadline_at)
                      VALUES(?, DATE(UTC_TIMESTAMP()), 'present', UTC_TIMESTAMP(), UTC_TIMESTAMP())")
            ->execute([$studentId]);
    } catch (PDOException $exception) {
        $duplicateRejected = (string) $exception->getCode() === '23000';
    }
    $assert($duplicateRejected, 'One student must not be able to hold two records for the same day.');

    // 6 — student and admin summaries stay internally consistent.
    $summary = $service->studentSummary($studentId, 30);
    $assert($summary['available'] === true && count($summary['days']) === 30, 'The day strip must always cover the full window.');
    $assert($summary['present'] === 0 && $summary['absent'] === 2, 'The window counts must match the rows written above.');
    $assert($summary['rate'] === 0.0 && $summary['streak'] === 0, 'Rate and streak must follow the recorded absences.');
    $assert(count($summary['history']) === 2, 'History should list the two recorded days.');
    $db->prepare("UPDATE attendance_records SET status='present', marked_at=UTC_TIMESTAMP() WHERE student_id = ? AND attendance_date = DATE(UTC_TIMESTAMP())")
        ->execute([$studentId]);
    $summary = $service->studentSummary($studentId, 30);
    $assert($summary['present'] === 1 && abs($summary['rate'] - 50.0) < 0.01, 'A single present day out of two must read as a 50% rate.');

    $admin = $service->adminSummary(14);
    $assert($admin['available'] === true && count($admin['days']) === 14, 'The admin trend must cover the requested window.');
    $assert($admin['today']['total'] >= 1 && $admin['today']['rate'] >= 0 && $admin['today']['rate'] <= 100, 'Today split must stay inside 0-100%.');
    $assert($admin['window']['present'] + $admin['window']['absent'] >= 2, 'The window totals must include this test\'s rows.');

    // 7 — the grace setting is honoured and clamped. The stored value is restored below.
    $current = $db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
    $current->execute([\App\Services\AttendanceService::GRACE_KEY]);
    $graceRestore = $current->fetchColumn();
    $graceRestore = $graceRestore === false ? null : (string) $graceRestore;
    $db->prepare('INSERT INTO app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute([\App\Services\AttendanceService::GRACE_KEY, '1']);
    $assert($service->graceMinutes() === 1, 'A valid admin value must be used as-is.');
    $db->prepare('UPDATE app_settings SET setting_value = ? WHERE setting_key = ?')->execute(['999', \App\Services\AttendanceService::GRACE_KEY]);
    $assert($service->graceMinutes() === \App\Services\AttendanceService::DEFAULT_GRACE_MINUTES, 'An out-of-range grace value must fall back to the default.');

    print("PASS: attendance sign-in window, present mark, expiry sweep, duplicates, summaries and settings.\n");
    print('       Test student #' . $studentId . ' removed.' . PHP_EOL);
} finally {
    if ($studentId > 0) {
        $db->prepare('DELETE FROM attendance_records WHERE student_id = ?')->execute([$studentId]);
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([$studentId]);
    }
    // Put the academy's own grace window back exactly as it was found.
    if ($graceRestore === null) {
        $db->prepare('DELETE FROM app_settings WHERE setting_key = ?')->execute([\App\Services\AttendanceService::GRACE_KEY]);
    } else {
        $db->prepare('UPDATE app_settings SET setting_value = ? WHERE setting_key = ?')
            ->execute([$graceRestore, \App\Services\AttendanceService::GRACE_KEY]);
    }
}
