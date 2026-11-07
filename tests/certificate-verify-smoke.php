<?php
declare(strict_types=1);

/**
 * Public certificate verification smoke test.
 *
 * A certificate is issued under its owner's account ID, so one ID legitimately opens several
 * certificates - one per course - and the verification page has to list every one of them, keep
 * its own expiry/revocation state per row, and point its download at the right document. This boots
 * the schema on a scratch database and renders the public page through the controller as a visitor.
 *
 *   /Applications/XAMPP/xamppfiles/bin/php tests/certificate-verify-smoke.php --database=tsg_verify_check_tmp
 *
 * Add --drop=1 to remove the scratch database afterwards.
 */

define('BASE_PATH', dirname(__DIR__));
foreach (
    [
        '/app/Core/Env.php',
        '/app/Core/View.php',
        '/app/Core/Security.php',
        '/app/Core/Database.php',
        '/app/Core/AuthMiddleware.php',
        '/app/Core/RateLimiter.php',
        '/app/Services/EmailLayout.php',
        '/app/Services/EmailMessages.php',
        '/app/Services/AccountIdentity.php',
        '/app/Services/AccessPlan.php',
        '/app/Services/AdminAttention.php',
        '/app/Services/MailService.php',
        '/app/Services/NotificationService.php',
        '/app/Services/CourseDuration.php',
        '/app/Services/CourseGrading.php',
        '/app/Controllers/CertificateController.php',
    ] as $file
) {
    require BASE_PATH . $file;
}

use App\Core\Database;
use App\Core\Env;
use App\Services\AccountIdentity;
use App\Services\CourseGrading;
use App\Controllers\CertificateController;

Env::load(BASE_PATH . '/.env');
$options = getopt('', ['database::', 'drop::']);
if (($options['database'] ?? '') !== '') {
    putenv('DB_NAME=' . $options['database']);
}
$dbName = (string) (getenv('DB_NAME') ?: '');

$failures = [];
$assert = static function (bool $condition, string $passMessage, string $failMessage) use (&$failures): void {
    if ($condition) {
        echo '  ok ' . $passMessage . "\n";
        return;
    }
    $failures[] = $failMessage;
    echo '  FAIL ' . $failMessage . "\n";
};

// Refusing to run has to be final: a recorded failure would let the suite write certificates
// into the database it was supposed to stay out of.
if (preg_match('/(tmp|check|scratch|test)/i', $dbName) !== 1) {
    fwrite(STDERR, 'Refusing to run: --database=' . $dbName . " does not look like a scratch database.\n");
    exit(1);
}
echo "  ok Running against the scratch database " . $dbName . "\.\n";

$mysql = '/Applications/XAMPP/xamppfiles/bin/mysql';
$user = (string) (getenv('DB_USER') ?: 'root');
$password = (string) (getenv('DB_PASS') ?: '');
$host = (string) (getenv('DB_HOST') ?: '127.0.0.1');
$port = (string) (getenv('DB_PORT') ?: '3306');
$credentials = '-u ' . escapeshellarg($user) . ($password === '' ? '' : ' -p' . escapeshellarg($password))
    . ' -h ' . escapeshellarg($host) . ' -P ' . escapeshellarg($port);
$exists = trim((string) shell_exec($mysql . ' ' . $credentials . ' -N -B -e ' .
    escapeshellarg("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='" . $dbName . "'")));
if ($exists !== '1') {
    shell_exec($mysql . ' ' . $credentials . ' -e ' . escapeshellarg('CREATE DATABASE `' . $dbName . '`'));
    shell_exec($mysql . ' ' . $credentials . ' ' . escapeshellarg($dbName) . ' < ' . escapeshellarg(BASE_PATH . '/database/migrations/001_initial_schema.sql'));
    echo 'bootstrapped scratch schema into ' . $dbName . "\n";
}

$db = Database::connection();

/* ---------------------------------------------------------------------------- fixtures */
// The fixture rows carry their own ids so a rerun replaces them instead of stacking up, and the
// student's ID is the one number this whole page is searched by.
$studentEmail = 'verify-smoke-student@example.test';
$courseOne = 'verify-smoke-full-stack';
$courseTwo = 'verify-smoke-graphic-design';
$courseThree = 'verify-smoke-revoked';
$existingStudentIds = "(SELECT id FROM users WHERE email = " . $db->quote($studentEmail) . ')';
$db->exec('DELETE FROM certificate_test_requests WHERE student_id IN (SELECT id FROM users WHERE email = ' . $db->quote($studentEmail) . ')');
$db->exec('DELETE FROM certificates WHERE student_id IN (SELECT id FROM users WHERE email = ' . $db->quote($studentEmail) . ')');
$db->exec('DELETE FROM course_grades WHERE student_id IN ' . $existingStudentIds);
$db->exec('DELETE FROM attendance_records WHERE student_id IN ' . $existingStudentIds);
$db->exec('DELETE FROM student_course_access WHERE student_id IN ' . $existingStudentIds);
$db->exec('DELETE FROM courses WHERE slug IN (' . $db->quote($courseOne) . ',' . $db->quote($courseTwo) . ',' . $db->quote($courseThree) . ')');
$db->exec('DELETE FROM users WHERE email = ' . $db->quote($studentEmail));
$db->exec("INSERT INTO users(full_name,email,password_hash,role,status,email_verified_at,account_reference)
    VALUES('Verify Smoke Student','" . $studentEmail . "','unusable-hash','student','active',UTC_TIMESTAMP(),NULL)");
$studentId = (int) $db->lastInsertId();
$courseInsert = $db->prepare("INSERT INTO courses(title,slug,visibility,created_by,duration_label,level_label) VALUES(?,?,'published',?,?,?)");
$courseInsert->execute(['Verify Smoke Full Stack', $courseOne, $studentId, '12 weeks', 'Beginner']);
$firstCourseId = (int) $db->lastInsertId();
$courseInsert->execute(['Verify Smoke Graphic Design', $courseTwo, $studentId, '8 weeks', 'All levels']);
$secondCourseId = (int) $db->lastInsertId();
$courseInsert->execute(['Verify Smoke Revoked', $courseThree, $studentId, '4 weeks', 'Beginner']);
$thirdCourseId = (int) $db->lastInsertId();
$db->prepare("INSERT INTO student_course_access(student_id,course_id,status,plan_code,granted_by) VALUES(?,?,'completed','live_recorded',?), (?,?,'completed','recorded_only',?)")
    ->execute([$studentId, $firstCourseId, $studentId, $studentId, $secondCourseId, $studentId]);
$db->prepare("INSERT INTO attendance_records(student_id,attendance_date,status,first_login_at,mark_deadline_at) VALUES(?,UTC_DATE(),'absent',UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE))")
    ->execute([$studentId]);

echo "\n-- one account, one ID\n";
// This is the number the certificate is printed with, so it has to exist before anything issues.
$accountReference = AccountIdentity::ensure($db, $studentId);
$assert(str_starts_with($accountReference, AccountIdentity::scheme($db)['prefix'] . '-'),
    'The student is issued ' . $accountReference . ' from the saved scheme.',
    'An account with no ID was not given one from the scheme: ' . $accountReference . '.');
$grading = new CourseGrading($db);
$assert($grading->thresholds()['grade'] === 60, 'Certificate grade threshold defaults to the company 60% pass mark.',
    'Certificate grade threshold is not 60%.');
$liveEligibility = $grading->eligibility($studentId, $firstCourseId);
$recordedEligibility = $grading->eligibility($studentId, $secondCourseId);
$assert($liveEligibility['attendanceRequired'] && $liveEligibility['attendance'] === 0.0
    && count(array_filter($liveEligibility['missing'], static fn(string $item): bool => str_contains($item, 'Attendance'))) === 1,
    'Live + Recorded certificates enforce the attendance threshold.', 'Live + Recorded attendance was not enforced.');
$assert(!$recordedEligibility['attendanceRequired'] && $recordedEligibility['attendance'] === null
    && count(array_filter($recordedEligibility['missing'], static fn(string $item): bool => str_contains($item, 'Attendance'))) === 0,
    'Recorded-only certificates do not require live attendance.', 'Recorded-only eligibility was blocked by attendance.');

// One certificate is live, one has expired, and one was revoked.
$db->prepare("INSERT INTO certificates(certificate_id,student_id,course_id,status,issued_at,expires_at,file_path)
    VALUES(?,?,?,'issued',UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 DAY),CONCAT('certificates/', REPEAT('ab', 24), '.jpg'))")
    ->execute([$accountReference, $studentId, $firstCourseId]);
$db->prepare("INSERT INTO certificates(certificate_id,student_id,course_id,status,issued_at,expires_at)
    VALUES(?,?,?,'issued',UTC_TIMESTAMP(),DATE_SUB(UTC_TIMESTAMP(),INTERVAL 5 DAY))")
    ->execute([$accountReference, $studentId, $secondCourseId]);
$db->prepare("INSERT INTO certificates(certificate_id,student_id,course_id,status,issued_at,expires_at,revoked_by,revoked_at,revoked_reason)
    VALUES(?,?,?,'revoked',UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 DAY),?,UTC_TIMESTAMP(),'Smoke test revocation')")
    ->execute([$accountReference, $studentId, $thirdCourseId, $studentId]);
$db->prepare("INSERT INTO certificate_test_requests(student_id,course_id,status,file_path,file_type,issued_at)
    VALUES(?,?,'issued',CONCAT('certificate-tests/', REPEAT('cd', 24), '.jpg'),'jpg',UTC_TIMESTAMP())")
    ->execute([$studentId, $thirdCourseId]);

$render = static function (string $id): string {
    $_SERVER['REQUEST_URI'] = '/TSG-Academy-LMS/verify';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_GET = ['cert_id' => $id];
    ob_start();
    (new CertificateController())->verify();
    return (string) ob_get_clean();
};

echo "\n-- every certificate behind one ID\n";
$html = $render($accountReference);
$cards = substr_count($html, '<article class="verify-card ');
$assert($cards === 4, 'The page lists every official certificate and test sample behind ' . $accountReference . '.',
    'Expected three official cards and one test sample, found ' . $cards . '.');
$assert(str_contains($html, 'including <strong>1</strong> TEST ONLY sample'),
    'The page separates test samples from official certificate records.', 'The mixed-result banner is missing.');
$assert(str_contains($html, 'Verify Smoke Full Stack') && str_contains($html, 'Verify Smoke Graphic Design') && str_contains($html, 'Verify Smoke Revoked'),
    'All three course titles are on the page.', 'A course title is missing from the listing.');
$assert(str_contains($html, 'verify-card-expired') && str_contains($html, 'verify-lapsed'),
    'The expired certificate is marked as expired and the live one is not.', 'The per-certificate expiry state did not render.');
$assert(str_contains($html, 'Certificate verified') && str_contains($html, 'Certificate expired'),
    'One card reads verified and the other reads expired.', 'The card headings do not describe both states.');
$assert(str_contains($html, 'Certificate revoked') && str_contains($html, 'Smoke test revocation') && str_contains($html, 'verify-card-revoked'),
    'A revoked certificate is clearly identified with its reason.', 'The revoked certificate was not clearly identified.');
$assert(str_contains($html, 'TEST ONLY — Sample certificate') && str_contains($html, 'not an official certificate'),
    'Test samples are visibly marked as not official in public verification.', 'The public test sample is not clearly marked.');
preg_match_all('/certificate\/download\?cert_id=[^&"]*&course_id=(\d+)/', $html, $links);
$assert($links[1] === [(string) $firstCourseId], 'The single download link names the live course, not the expired one.',
    'The download links point at ' . json_encode($links[1]) . ' instead of course ' . $firstCourseId . '.');

echo "\n-- what a visitor can type\n";
$assert(substr_count($render(strtolower($accountReference)), '<article class="verify-card ') === 4,
    'A lower-case ID still opens the account.', 'Typing the ID in lower case found nothing.');
$nameResult = $render('Verify Smoke Student');
$assert(substr_count($nameResult, '<article class="verify-card ') === 1 && str_contains($nameResult, 'TEST ONLY — Sample certificate'),
    'An exact student name opens only its clearly marked test sample.', 'Exact-name search leaked official rows or missed its test sample.');
$assert(str_contains($render('TSG-CER-999999'), 'Certificate not found'),
    'An ID with no certificate is refused plainly.', 'An unknown ID did not produce the not-found card.');
$assert(str_contains($render('TSG_CER_001'), 'Certificate not found'),
    'Malformed IDs do not return a certificate.', 'A malformed ID unexpectedly returned a record.');

echo "\n";
$db->exec('DELETE FROM certificate_test_requests WHERE student_id = ' . $studentId);
$db->exec('DELETE FROM certificates WHERE student_id = ' . $studentId);
$db->exec('DELETE FROM course_grades WHERE student_id = ' . $studentId);
$db->exec('DELETE FROM attendance_records WHERE student_id = ' . $studentId);
$db->exec('DELETE FROM student_course_access WHERE student_id = ' . $studentId);
$db->exec('DELETE FROM courses WHERE slug IN (' . $db->quote($courseOne) . ',' . $db->quote($courseTwo) . ',' . $db->quote($courseThree) . ')');
$db->exec('DELETE FROM users WHERE id = ' . $studentId);
if (($options['drop'] ?? '0') === '1') {
    shell_exec($mysql . ' ' . $credentials . ' -e ' . escapeshellarg('DROP DATABASE IF EXISTS `' . $dbName . '`'));
    echo 'dropped ' . $dbName . "\n";
}

if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " problem(s)\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "PASS: Academy ID and exact-name lookups show official records and marked test samples on " . $dbName . "\n";
exit(0);
