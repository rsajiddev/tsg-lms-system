<?php
declare(strict_types=1);

/**
 * Public certificate verification smoke test.
 *
 * A certificate is issued under its owner's account ID, so one ID legitimately opens several
 * certificates - one per course - and the verification page has to list every one of them, keep
 * its own expiry state per row, and point its download at the right document. This boots the
 * schema on a scratch database, gives one student a live and an expired certificate, and renders
 * the public page through the controller exactly as a visitor does.
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

$assert(preg_match('/(tmp|check|scratch|test)/i', $dbName) === 1, 'Running against the scratch database ' . $dbName . '.',
    'Refusing to run: --database=' . $dbName . ' does not look like a scratch database.');

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
$db->exec('DELETE FROM certificates WHERE student_id IN (SELECT id FROM users WHERE email = ' . $db->quote($studentEmail) . ')');
$db->exec('DELETE FROM courses WHERE slug IN (' . $db->quote($courseOne) . ',' . $db->quote($courseTwo) . ')');
$db->exec('DELETE FROM users WHERE email = ' . $db->quote($studentEmail));
$db->exec("INSERT INTO users(full_name,email,password_hash,role,status,email_verified_at,account_reference)
    VALUES('Verify Smoke Student','" . $studentEmail . "','unusable-hash','student','active',UTC_TIMESTAMP(),NULL)");
$studentId = (int) $db->lastInsertId();
$courseInsert = $db->prepare("INSERT INTO courses(title,slug,visibility,created_by,duration_label,level_label) VALUES(?,?,'published',?,?,?)");
$courseInsert->execute(['Verify Smoke Full Stack', $courseOne, $studentId, '12 weeks', 'Beginner']);
$firstCourseId = (int) $db->lastInsertId();
$courseInsert->execute(['Verify Smoke Graphic Design', $courseTwo, $studentId, '8 weeks', 'All levels']);
$secondCourseId = (int) $db->lastInsertId();

echo "\n-- one account, one ID\n";
// This is the number the certificate is printed with, so it has to exist before anything issues.
$accountReference = AccountIdentity::ensure($db, $studentId);
$assert(str_starts_with($accountReference, AccountIdentity::scheme($db)['prefix'] . '-'),
    'The student is issued ' . $accountReference . ' from the saved scheme.',
    'An account with no ID was not given one from the scheme: ' . $accountReference . '.');

// One certificate still inside its window, with a file behind it, and one that has run out.
$db->prepare("INSERT INTO certificates(certificate_id,student_id,course_id,status,issued_at,expires_at,file_path)
    VALUES(?,?,?,'issued',UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 DAY),CONCAT('certificates/', REPEAT('ab', 24), '.jpg'))")
    ->execute([$accountReference, $studentId, $firstCourseId]);
$db->prepare("INSERT INTO certificates(certificate_id,student_id,course_id,status,issued_at,expires_at)
    VALUES(?,?,?,'issued',UTC_TIMESTAMP(),DATE_SUB(UTC_TIMESTAMP(),INTERVAL 5 DAY))")
    ->execute([$accountReference, $studentId, $secondCourseId]);

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
$assert($cards === 2, 'The page lists both certificates issued to ' . $accountReference . '.',
    'Expected two certificate cards for one ID, found ' . $cards . '.');
$assert(str_contains($html, 'issued for <strong>2</strong> certificates'),
    'The page says out loud that one ID covers two certificates.', 'The multi-certificate banner is missing.');
$assert(str_contains($html, 'Verify Smoke Full Stack') && str_contains($html, 'Verify Smoke Graphic Design'),
    'Both course titles are on the page.', 'One of the two certificates is missing from the listing.');
$assert(str_contains($html, 'verify-card-expired') && str_contains($html, 'verify-lapsed'),
    'The expired certificate is marked as expired and the live one is not.', 'The per-certificate expiry state did not render.');
$assert(str_contains($html, 'Certificate verified') && str_contains($html, 'Certificate expired'),
    'One card reads verified and the other reads expired.', 'The card headings do not describe both states.');
preg_match_all('/certificate\/download\?cert_id=[^&"]*&course_id=(\d+)/', $html, $links);
$assert($links[1] === [(string) $firstCourseId], 'The single download link names the live course, not the expired one.',
    'The download links point at ' . json_encode($links[1]) . ' instead of course ' . $firstCourseId . '.');

echo "\n-- what a visitor can type\n";
$assert(substr_count($render(strtolower($accountReference)), '<article class="verify-card ') === 2,
    'A lower-case ID still opens the account.', 'Typing the ID in lower case found nothing.');
$assert(str_contains($render('TSG-CER-999999'), 'Certificate not found'),
    'An ID with no certificate is refused plainly.', 'An unknown ID did not produce the not-found card.');
$assert(str_contains($render('TSG_CER_001'), 'does not look like a certificate ID'),
    'A malformed ID is refused before the database is asked.', 'A malformed ID was treated as a lookup.');

echo "\n";
$db->exec('DELETE FROM certificates WHERE student_id = ' . $studentId);
$db->exec('DELETE FROM courses WHERE slug IN (' . $db->quote($courseOne) . ',' . $db->quote($courseTwo) . ')');
$db->exec('DELETE FROM users WHERE id = ' . $studentId);
if (($options['drop'] ?? '0') === '1') {
    shell_exec($mysql . ' ' . $credentials . ' -e ' . escapeshellarg('DROP DATABASE IF EXISTS `' . $dbName . '`'));
    echo 'dropped ' . $dbName . "\n";
}

if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " problem(s)\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "PASS: one Academy ID opens every certificate behind it on " . $dbName . "\n";
exit(0);
