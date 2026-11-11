<?php
declare(strict_types=1);

/**
 * Live class link smoke test.
 *
 * Run against a scratch database:
 *   /Applications/XAMPP/xamppfiles/bin/php tests/live-class-flow-smoke.php --database=tsg_live_class_check_tmp
 *
 * Add --drop=1 to remove the scratch database afterwards.
 *
 * The feature has two halves, and they can only be proven in two different ways. Which plan an
 * enrolment sits on is a fact about a row, so the purchase, the approval and the admin's "Set plan
 * only" are walked over real HTTP against PHP's own web server, one cookie jar per account, and read
 * back from the database. Whether a meeting address reaches a browser is a fact about rendered HTML,
 * so the gate is measured on the raw response body: a Recorded Only student's page must not contain
 * the address in any form. That is the one failure this feature is allowed to have and cannot be
 * taken back, which is why the check is written as "the string is not in the bytes".
 *
 * The gate is deliberately the opposite of the session guard's. There, an unreadable state meant a
 * student could be locked out of a lesson they had paid for, so the site fails open. Here an
 * unreadable plan would hand a class address to somebody who never bought one, so every branch below
 * that cannot decide says nothing. The last section proves it by dropping the columns entirely and
 * expecting the site to keep working - lesson page, purchase, admin desks - with no card anywhere.
 */

define('BASE_PATH', dirname(__DIR__));
foreach (
    [
        '/app/Core/Env.php',
        '/app/Core/Security.php',
        '/app/Core/Database.php',
        '/app/Services/CourseDuration.php',
        '/app/Services/LessonMedia.php',
        '/app/Services/AccessPlan.php',
    ] as $file
) {
    require BASE_PATH . $file;
}

use App\Core\Database;
use App\Core\Env;
use App\Core\Security;
use App\Services\AccessPlan;
use App\Services\CourseDuration;
use App\Services\LessonMedia;

Env::load(BASE_PATH . '/.env');
$options = getopt('', ['database::', 'drop::']);
if (($options['database'] ?? '') !== '') {
    putenv('DB_NAME=' . $options['database']);
}
$dbName = (string) (getenv('DB_NAME') ?: '');
$token = bin2hex(random_bytes(6));
$failures = [];

// Nothing is printed while the run is alive: PHP refuses session work once output has been sent and
// every line is drained at the end - including by the shutdown handler, so a fatal still reports how
// far the run got before it stopped.
$report = '';
$printed = false;
$emit = static function (string $line) use (&$report): void {
    $report .= $line . "\n";
};
$drain = static function () use (&$report, &$printed): void {
    if ($printed) return;
    $printed = true;
    echo rtrim($report, "\n") . "\n";
};
register_shutdown_function($drain);
$assert = static function (bool $condition, string $passMessage, string $failMessage) use (&$failures, $emit): void {
    if ($condition) {
        $emit('  ok ' . $passMessage);
        return;
    }
    $failures[] = $failMessage;
    $emit('  FAIL ' . $failMessage);
};
/** Fatal, never a recorded failure: this suite writes real payments and real enrolments, and a soft
 *  guard is how fixtures end up in somebody's live database. */
if (preg_match('/(tmp|check|scratch|test)/i', $dbName) !== 1) {
    fwrite(STDERR, 'Refusing to run: --database=' . $dbName . " does not look like a scratch database.\n");
    exit(1);
}
$emit('  ok Running against the scratch database ' . $dbName . '.');

$mysql = '/Applications/XAMPP/xamppfiles/bin/mysql';
$dbUser = (string) (getenv('DB_USER') ?: 'root');
$dbPassword = (string) (getenv('DB_PASS') ?: '');
$dbHost = (string) (getenv('DB_HOST') ?: '127.0.0.1');
$dbPort = (string) (getenv('DB_PORT') ?: '3306');
$credentials = '-u ' . escapeshellarg($dbUser) . ($dbPassword === '' ? '' : ' -p' . escapeshellarg($dbPassword))
    . ' -h ' . escapeshellarg($dbHost) . ' -P ' . escapeshellarg($dbPort);
$exists = trim((string) shell_exec($mysql . ' ' . $credentials . ' -N -B -e '
    . escapeshellarg("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='" . $dbName . "'")));
if ($exists !== '1') {
    shell_exec($mysql . ' ' . $credentials . ' -e ' . escapeshellarg('CREATE DATABASE `' . $dbName . '`'));
    shell_exec($mysql . ' ' . $credentials . ' ' . escapeshellarg($dbName) . ' < '
        . escapeshellarg(BASE_PATH . '/database/migrations/001_initial_schema.sql'));
    $emit('bootstrapped scratch schema into ' . $dbName);
}
$tableCount = (int) shell_exec($mysql . ' ' . $credentials . ' -N -B -e '
    . escapeshellarg("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='" . $dbName . "'"));
if ($tableCount < 20) {
    fwrite(STDERR, 'FAIL: the scratch database has no schema; import database/migrations/001_initial_schema.sql and rerun.' . "\n");
    exit(1);
}
// DDL the run needs for its last section, and a reader for the columns it drops and puts back.
$runSql = static function (string $sql) use ($mysql, $credentials, $dbName): void {
    shell_exec($mysql . ' ' . $credentials . ' ' . escapeshellarg($dbName) . ' -e ' . escapeshellarg($sql));
};
$columnPresent = static function (string $table, string $column) use ($mysql, $credentials, $dbName): bool {
    return trim((string) shell_exec($mysql . ' ' . $credentials . ' -N -B -e ' . escapeshellarg(
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='" . $dbName
        . "' AND table_name='" . $table . "' AND column_name='" . $column . "'"
    ))) === '1';
};
/** Import the schema file the way the README tells an admin to, and hand back only what the client
 *  complained about on stderr - the file is idempotent, so a second import over a database it already
 *  built is a normal thing to do and not an error. */
$reimportSchema = static function () use ($mysql, $credentials, $dbName): string {
    return (string) shell_exec($mysql . ' ' . $credentials . ' ' . escapeshellarg($dbName) . ' < '
        . escapeshellarg(BASE_PATH . '/database/migrations/001_initial_schema.sql') . ' 2>&1 1>/dev/null');
};

$db = Database::connection();
$studentPassword = 'Live Class Smoke 1!';
$adminPassword = 'Live Class Admin 1!';
$emails = [
    'admin' => 'live-class-admin-' . $token . '@example.test',
    'live' => 'live-class-live-' . $token . '@example.test',
    'recorded' => 'live-class-recorded-' . $token . '@example.test',
    'buyer' => 'live-class-buyer-' . $token . '@example.test',
    'upgrade' => 'live-class-upgrade-' . $token . '@example.test',
    'legacy' => 'live-class-legacy-' . $token . '@example.test',
    'lonely' => 'live-class-lonely-' . $token . '@example.test',
    'stray' => 'live-class-stray-' . $token . '@example.test',
];
$slug = 'live-class-smoke-' . $token;
// Two addresses, so a check can prove the page is reading the row and not remembering an old render.
$meetingUrl = 'https://meet.google.com/' . substr($token, 0, 3) . 'bc-defg-' . substr($token, 0, 4);
$meetingUrl2 = 'https://zoom.us/j/4005006007?pwd=smoke' . substr($token, 0, 4);
$futureAt = gmdate('Y-m-d H:i:00', time() + 30 * 86400);
$pastAt = gmdate('Y-m-d H:i:00', time() - 5 * 86400);
$classLabel = static fn(?string $value): string => $value === null || $value === ''
    ? ''
    : gmdate('D, M j, Y, g:i A', strtotime($value . ' UTC')) . ' UTC';
$ids = ['admin' => 0, 'live' => 0, 'recorded' => 0, 'buyer' => 0, 'upgrade' => 0, 'legacy' => 0, 'lonely' => 0, 'stray' => 0];
$courseId = 0;
$moduleId = 0;
$lessonIds = ['live' => 0, 'past' => 0, 'plain' => 0, 'editable' => 0];
$proofFiles = [];
$serverPort = 0;
$server = null;
$scratchFiles = [];
$columnsDropped = false;

/* --------------------------------------------------------------------------- fixtures */
foreach ($emails as $email) $db->prepare('DELETE FROM lms_users WHERE email = ?')->execute([$email]);
$db->prepare('DELETE FROM lms_courses WHERE slug = ?')->execute([$slug]);

$insertUser = $db->prepare("INSERT INTO lms_users(full_name,email,password_hash,role,status,email_verified_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())");
$insertUser->execute(['Live Class Smoke Admin', $emails['admin'], password_hash($adminPassword, PASSWORD_BCRYPT), 'admin', 'active']);
$ids['admin'] = (int) $db->lastInsertId();
foreach (['live', 'recorded', 'buyer', 'upgrade', 'legacy', 'lonely', 'stray'] as $key) {
    $insertUser->execute(['Live Class Smoke ' . ucfirst($key) . ' Student', $emails[$key], password_hash($studentPassword, PASSWORD_BCRYPT), 'student', 'active']);
    $ids[$key] = (int) $db->lastInsertId();
}
$db->prepare("INSERT INTO lms_courses(title,slug,visibility,duration_label,created_by) VALUES(?,?,'published','3 months',?)")
    ->execute(['Live Class Smoke Course', $slug, $ids['admin']]);
$courseId = (int) $db->lastInsertId();
foreach ([AccessPlan::RECORDED => 25000, AccessPlan::LIVE => 45000] as $code => $price) {
    $db->prepare("INSERT INTO lms_course_pricing_options(course_id,option_code,display_label,price,currency) VALUES(?,?,?,?,'PKR')")
        ->execute([$courseId, $code, AccessPlan::label($code), $price]);
}
$db->prepare('INSERT INTO lms_course_modules(course_id,title,sort_order) VALUES(?,?,1)')->execute([$courseId, 'Smoke module']);
$moduleId = (int) $db->lastInsertId();
$insertLesson = $db->prepare("INSERT INTO lms_lessons(module_id,title,body,video_url,video_provider,video_duration_seconds,sort_order,status,meeting_url,meeting_platform,meeting_at) VALUES(?,?,?,?,?,180,?,'published',?,?,?)");
$insertLesson->execute([$moduleId, 'Live class lesson', 'Body', 'https://cdn.example.com/live-' . $token . '.mp4', 'file', 1, $meetingUrl, 'google_meet', $futureAt]);
$lessonIds['live'] = (int) $db->lastInsertId();
$insertLesson->execute([$moduleId, 'Passed class lesson', 'Body', 'https://cdn.example.com/past-' . $token . '.mp4', 'file', 2, $meetingUrl, 'google_meet', $pastAt]);
$lessonIds['past'] = (int) $db->lastInsertId();
$insertPlain = $db->prepare("INSERT INTO lms_lessons(module_id,title,body,video_url,video_provider,video_duration_seconds,sort_order,status) VALUES(?,?,?,?,?,180,?,'published')");
$insertPlain->execute([$moduleId, 'Recorded only lesson', 'Body', 'https://cdn.example.com/plain-' . $token . '.mp4', 'file', 3]);
$lessonIds['plain'] = (int) $db->lastInsertId();
$insertPlain->execute([$moduleId, 'Editable lesson', 'Body', 'https://cdn.example.com/edit-' . $token . '.mp4', 'file', 4]);
$lessonIds['editable'] = (int) $db->lastInsertId();

// Enrolments: the two students whose lesson pages are compared below, and the upgrade case, which
// starts on Recorded Only and is moved by a second approved payment.
$insertAccess = $db->prepare("INSERT INTO lms_student_course_access(student_id,course_id,status,plan_code,granted_by,granted_at,expires_at) VALUES(?,?,'active',?,?,UTC_TIMESTAMP(),?)");
$expiresAt = CourseDuration::expiry('3 months');
foreach (['live' => AccessPlan::LIVE, 'recorded' => AccessPlan::RECORDED, 'upgrade' => AccessPlan::RECORDED, 'legacy' => AccessPlan::LIVE] as $key => $plan) {
    $insertAccess->execute([$ids[$key], $courseId, $plan, $ids['admin'], $expiresAt]);
}

$readAccess = static function (int $studentId) use ($db, $courseId): array {
    $stmt = $db->prepare('SELECT status,plan_code,granted_by,granted_at,expires_at,revoked_by,revoked_at FROM lms_student_course_access WHERE student_id=? AND course_id=?');
    $stmt->execute([$studentId, $courseId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};
$readPlan = static function (int $studentId) use ($db, $courseId): ?string {
    $stmt = $db->prepare('SELECT plan_code FROM lms_student_course_access WHERE student_id=? AND course_id=?');
    $stmt->execute([$studentId, $courseId]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (string) $value;
};
$readMeeting = static function (int $lessonId) use ($db): array {
    $stmt = $db->prepare('SELECT meeting_url,meeting_at FROM lms_lessons WHERE id=?');
    $stmt->execute([$lessonId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [$row['meeting_url'] ?? null, $row['meeting_at'] ?? null];
};

/* ------------------------------------------------------------------------- web server */
// Routes, cookies and the CSRF token a real form carries are only observable over HTTP, and the
// HTML a student's browser receives is the thing under test.
$probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!is_resource($probe)) {
    fwrite(STDERR, 'FAIL: no free loopback port for the test web server: ' . $errstr . "\n");
    exit(1);
}
$serverPort = (int) parse_url((string) stream_socket_get_name($probe, false), PHP_URL_PORT);
fclose($probe);
$appUrl = 'http://127.0.0.1:' . $serverPort;
putenv('APP_URL=' . $appUrl);
// Forced empty: nothing here should reach for an SMTP server.
putenv('MAIL_HOST=');
putenv('MAIL_PORT=25');

if (!is_dir(BASE_PATH . '/storage/tmp')) {
    @mkdir(BASE_PATH . '/storage/tmp', 0700, true);
}
$scratch = BASE_PATH . '/storage/tmp/live-class-smoke-' . $token;
$serverLog = $scratch . '.server.log';
$scratchFiles[] = $serverLog;
$proofPath = $scratch . '.proof.pdf';
$scratchFiles[] = $proofPath;
file_put_contents($proofPath, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
// The checkout demands a proof file, and the account that serves the site is not always the account
// running this command. A private folder one of them created is 0700 to the other, so the upload
// half below is measured only where the folder can actually be written - and says so out loud
// where it cannot, rather than passing quietly or failing the whole suite for a machine's owners.
$proofFolder = BASE_PATH . '/storage/uploads/private/payment-proofs';
$canUpload = is_dir($proofFolder) ? is_writable($proofFolder) : @mkdir($proofFolder, 0700, true);
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $serverPort, '-t', 'public', 'public/index.php'],
    [1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']],
    $pipes,
    BASE_PATH
);
if (!is_resource($server)) {
    $emit('FAIL: the test web server could not start.');
    $drain();
    exit(1);
}
$ready = false;
for ($attempt = 0; $attempt < 100; $attempt++) {
    $socket = @stream_socket_client('tcp://127.0.0.1:' . $serverPort, $errno, $errstr, 1);
    if (is_resource($socket)) {
        fclose($socket);
        $ready = true;
        break;
    }
    usleep(100000);
}
if (!$ready) {
    $emit('FAIL: the test web server never accepted a connection.');
    $drain();
    exit(1);
}

/** One request as a browser sends it. Redirects are never followed, because where a form lands is
 *  often the answer itself. `$upload` is the name of a file field sent as multipart, which the
 *  payment form requires. */
$open = static function (string $method, string $path, array $fields = [], string $jar = '', string $upload = '') use ($appUrl, &$failures, $proofPath): array {
    $handle = curl_init($appUrl . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
    ]);
    if ($jar !== '') {
        curl_setopt($handle, CURLOPT_COOKIEFILE, $jar);
        curl_setopt($handle, CURLOPT_COOKIEJAR, $jar);
    }
    if ($method === 'POST') {
        if ($upload !== '') {
            $fields[$upload] = new CURLFile($proofPath, 'application/pdf', 'proof.pdf');
        }
        curl_setopt($handle, CURLOPT_POSTFIELDS, $upload === '' ? http_build_query($fields) : $fields);
    }
    $response = curl_exec($handle);
    if ($response === false) {
        $failures[] = 'The request ' . $method . ' ' . $path . ' failed: ' . curl_error($handle);
        curl_close($handle);
        return ['status' => 0, 'body' => '', 'location' => null];
    }
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    curl_close($handle);
    $location = null;
    foreach (explode("\r\n", (string) substr((string) $response, 0, $headerSize)) as $line) {
        if (stripos($line, 'Location:') === 0) $location = trim(substr($line, 9));
    }
    return ['status' => $status, 'body' => (string) substr((string) $response, $headerSize), 'location' => $location];
};
$jarOf = static function (string $device) use ($scratch, &$scratchFiles): string {
    $path = $scratch . '.' . $device . '.cookies';
    $scratchFiles[] = $path;
    return $path;
};
$csrfOf = static function (string $html): string {
    return preg_match('/name="_csrf" value="([^"]+)"/', $html, $match) === 1 ? $match[1] : '';
};
$signIn = static function (string $jar, string $email, string $password) use ($open, $csrfOf, $appUrl): array {
    @unlink($jar);
    $form = $open('GET', '/login', [], $jar);
    $reply = $open('POST', '/login', ['_csrf' => $csrfOf($form['body']), 'email' => $email, 'password' => $password], $jar);
    return ['signed' => $reply['status'] === 302 && (string) $reply['location'] === $appUrl . '/dashboard', 'status' => $reply['status']];
};
/** A payment row written by the harness rather than the form, for the states the checkout itself can
 *  no longer produce: a second purchase after access is open, and a legacy row with no plan on it. */
$seedPayment = static function (int $studentId, ?string $plan, string $reference) use ($db, $courseId): int {
    $stmt = $db->prepare("INSERT INTO lms_payments(student_id,course_id,plan_code,amount,currency,transaction_reference,payment_proof_path,status) VALUES(?,?,?,25000,'PKR',?,'harness/seed.sql','pending')");
    $stmt->execute([$studentId, $courseId, $plan, $reference]);
    return (int) $db->lastInsertId();
};

$jarAdmin = $jarOf('admin');
$jarLive = $jarOf('live');
$jarRecorded = $jarOf('recorded');
$jarBuyer = $jarOf('buyer');
$jarLonely = $jarOf('lonely');
$jarStray = $jarOf('stray');

try {
    /* ---------------------------------------------------------------- the columns underneath */
    $emit("\n-- the schema the feature stands on");
    foreach ([
        ['lessons', 'meeting_url'], ['lessons', 'meeting_platform'], ['lessons', 'meeting_at'],
        ['payments', 'plan_code'], ['student_course_access', 'plan_code'],
    ] as [$table, $column]) {
        $assert($columnPresent($table, $column), $table . '.' . $column . ' arrived with the migration.', $table . '.' . $column . ' is missing; import database/migrations/001_initial_schema.sql.');
    }
    $indexed = (int) trim((string) shell_exec($mysql . ' ' . $credentials . ' -N -B -e ' . escapeshellarg(
        "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema='" . $dbName . "' AND table_name='student_course_access' AND index_name='idx_access_plan'")));
    $assert($indexed >= 1, 'Plans are indexed for the lookup every lesson page makes.', 'idx_access_plan is missing from lms_student_course_access.');
    $loader = (string) file_get_contents(BASE_PATH . '/public/index.php');
    $assert(str_contains($loader, "'/app/Services/AccessPlan.php'"), 'public/index.php loads the plan service.', 'AccessPlan.php is not in the loader, so no controller can see it.');

    /* ------------------------------------------------------------------ the plan, in code */
    $emit("\n-- the rule the gate is built out of");
    $assert(AccessPlan::allowsLive(AccessPlan::LIVE) === true && AccessPlan::allowsLive(AccessPlan::RECORDED) === false,
        'Live + Recorded is the only plan that opens a class.', 'allowsLive() answered for the two real plans wrongly.');
    foreach ([null, '', 'live-recorded', 'LIVE_RECORDED', 'live', 'recorded_only '] as $unreadable) {
        $assert(AccessPlan::allowsLive($unreadable) === false,
            var_export($unreadable, true) . ' does not open a class.', var_export($unreadable, true) . ' was allowed through the gate.');
    }
    $assert(AccessPlan::normalise('nonsense') === AccessPlan::RECORDED && AccessPlan::normalise(null) === AccessPlan::RECORDED
        && AccessPlan::normalise(0) === AccessPlan::RECORDED && AccessPlan::normalise(['live_recorded']) === AccessPlan::RECORDED
        && AccessPlan::normalise(AccessPlan::LIVE) === AccessPlan::LIVE,
        'Whatever cannot be read as Live is stored as Recorded Only.', 'normalise() returned ' . var_export(AccessPlan::normalise('nonsense'), true) . '.');
    $assert(AccessPlan::label(AccessPlan::LIVE) === 'Live + Recorded' && AccessPlan::label(null) === 'Recorded Only',
        'Both plans print a name a student recognises, and an unknown one prints the safe one.', 'label() read ' . AccessPlan::label(AccessPlan::LIVE) . ' / ' . AccessPlan::label(null) . '.');
    $assert(LessonMedia::normaliseMeetingUrl('https://meet.google.com/abc-defg-hij') === 'https://meet.google.com/abc-defg-hij'
        && LessonMedia::normaliseMeetingUrl('  ') === null && LessonMedia::normaliseMeetingUrl(null) === null,
        'A clean address is kept as typed, and an empty field is no address.', 'normaliseMeetingUrl() mishandled a valid or empty link.');
    foreach (['javascript:alert(1)//' => 'a script', 'http://zoom.us/j/1' => 'plain http', 'not a link' => 'a word', 'https://zoom.us@evil.test/' => 'credentials in the address'] as $bad => $what) {
        try {
            LessonMedia::normaliseMeetingUrl($bad);
            $assert(false, $what . ' is refused.', 'normaliseMeetingUrl() accepted ' . $bad . '.');
        } catch (RuntimeException $exception) {
            $assert($exception->getMessage() !== '', $what . ' is refused: ' . $exception->getMessage(), $what . ' was refused with no message.');
        }
    }
    $assert(LessonMedia::parseMeetingTime('2026-11-08T20:00') === '2026-11-08 20:00:00'
        && LessonMedia::parseMeetingTime('2026-11-08 20:00') === '2026-11-08 20:00:00'
        && LessonMedia::parseMeetingTime('') === null && LessonMedia::parseMeetingTime(null) === null,
        'A class time is stored on the UTC clock exactly as it was typed.', 'parseMeetingTime() read ' . var_export(LessonMedia::parseMeetingTime('2026-11-08T20:00'), true) . '.');
    foreach (['2026-13-08T20:00', '2026-11-31T20:00', '2026-11-08T25:00', 'tomorrow evening'] as $badTime) {
        try {
            LessonMedia::parseMeetingTime($badTime);
            $assert(false, 'A time that cannot be read (' . $badTime . ') is refused.', 'parseMeetingTime() accepted ' . $badTime . '.');
        } catch (RuntimeException $exception) {
            $assert($exception->getMessage() !== '', 'A time that cannot be read is refused: ' . $badTime . ' - ' . $exception->getMessage(), 'parseMeetingTime() refused ' . $badTime . ' with no message.');
        }
    }

    /* ------------------------------------------------------------- the purchase keeps its plan */
    $emit("\n-- what the checkout writes down");
    $signedBuyer = $signIn($jarBuyer, $emails['buyer'], $studentPassword);
    $assert($signedBuyer['signed'], 'The purchasing student signs in.', 'The buyer could not sign in (status ' . $signedBuyer['status'] . ').');
    $checkout = $open('GET', '/payments/new?course_id=' . $courseId, [], $jarBuyer);
    $assert($checkout['status'] === 200 && str_contains($checkout['body'], 'name="option_code"') && str_contains($checkout['body'], 'Live + Recorded'),
        'The checkout offers both learning options by name.', 'The payment form did not render its option select (status ' . $checkout['status'] . ').');
    $bought = $open('POST', '/payments', [
        '_csrf' => $csrfOf($checkout['body']),
        'course_id' => (string) $courseId,
        'option_code' => AccessPlan::LIVE,
        'transaction_reference' => 'LIVE-' . $token,
    ], $jarBuyer, 'payment_proof');
    $assert($bought['status'] === 302, 'The submitted payment is answered with a redirect either way.', 'The payment POST answered ' . $bought['status'] . '.');
    $buyerRows = $db->prepare('SELECT id,plan_code,status FROM lms_payments WHERE student_id=? AND course_id=?');
    $buyerRows->execute([$ids['buyer'], $courseId]);
    $purchase = $buyerRows->fetch(PDO::FETCH_ASSOC) ?: [];
    if ($canUpload) {
        $assert((int) ($purchase['id'] ?? 0) > 0, 'The submitted payment lands in the review queue.', 'The checkout left no payment row behind.');
        $assert((string) ($purchase['plan_code'] ?? '') === AccessPlan::LIVE,
            'The plan the student picked is now a column on the payment, not only a note in the audit log.',
            'payments.plan_code reads ' . var_export($purchase['plan_code'] ?? null, true) . ' for the submitted purchase.');
        $audited = $db->prepare("SELECT metadata FROM lms_audit_logs WHERE action='payment.submitted' AND entity_id=? LIMIT 1");
        $audited->execute([(int) $purchase['id']]);
        $auditMeta = (string) ($audited->fetchColumn() ?: '');
        $assert(str_contains($auditMeta, 'live_recorded'), 'The audit row still records the option it was given.', 'The submission audit row lost its option: ' . $auditMeta);
    } else {
        $assert($purchase === [] && str_contains($open('GET', '/payments/new?course_id=' . $courseId, [], $jarBuyer)['body'], 'not writable'),
            'A proof folder this account cannot write stops the purchase naming the folder, and leaves no half-written payment.',
            'The blocked checkout left ' . json_encode($purchase) . ' behind.');
        $emit('  NOTE ' . $proofFolder . ' belongs to another account, so the checkout INSERT could not be driven from this command line. Run step 1 of README "Testing live class links" in a browser to see it.');
        // The rows the rest of the run needs are the ones the form would have written, composed the
        // same way, so the approval and the gate stay measurable on a machine that cannot upload.
        $seededId = $seedPayment($ids['buyer'], AccessPlan::LIVE, 'LIVE-' . $token);
        $purchase = ['id' => $seededId, 'plan_code' => AccessPlan::LIVE, 'status' => 'pending'];
    }
    $wrongOption = $open('POST', '/payments', [
        '_csrf' => $csrfOf($open('GET', '/payments/new?course_id=' . $courseId, [], $jarBuyer)['body']),
        'course_id' => (string) $courseId,
        'option_code' => 'gold_plan',
        'transaction_reference' => 'GOLD-' . $token,
    ], $jarBuyer, 'payment_proof');
    $stillOne = (int) $db->query('SELECT COUNT(*) FROM lms_payments WHERE student_id = ' . (int) $ids['buyer'])->fetchColumn();
    $assert($wrongOption['status'] === 302 && $stillOne === 1,
        'An option nobody sells is refused before anything is written.', 'A forged option left ' . $stillOne . ' payment row(s) behind.');

    $signedAdmin = $signIn($jarAdmin, $emails['admin'], $adminPassword);
    $assert($signedAdmin['signed'], 'The administrator signs in to the review queue.', 'The admin could not sign in (status ' . $signedAdmin['status'] . ').');
    $approve = $open('POST', '/admin/payments/decision', [
        '_csrf' => $csrfOf($open('GET', '/admin/payments', [], $jarAdmin)['body']),
        'payment_id' => (string) (int) ($purchase['id'] ?? 0),
        'decision' => 'approved',
        'review_note' => 'Bank transfer seen.',
    ], $jarAdmin);
    $assert($approve['status'] === 302, 'Approving the payment answers with the queue again.', 'The decision answered ' . $approve['status'] . '.');
    $assert($readPlan($ids['buyer']) === AccessPlan::LIVE,
        'Granting the enrolment carries the purchased plan onto it.', 'The enrolment reads ' . var_export($readPlan($ids['buyer']), true) . ' after approval.');

    /* ------------------------------------------------------------------ the second approval */
    $emit("\n-- an upgrade moves the plan, and an unrecorded payment does not guess one");
    $upgradePayment = $seedPayment($ids['upgrade'], AccessPlan::LIVE, 'UPGRADE-' . $token);
    $approvedUpgrade = $open('POST', '/admin/payments/decision', [
        '_csrf' => $csrfOf($open('GET', '/admin/payments', [], $jarAdmin)['body']),
        'payment_id' => (string) $upgradePayment,
        'decision' => 'approved',
        'review_note' => '',
    ], $jarAdmin);
    $afterUpgrade = $readAccess($ids['upgrade']);
    $assert($approvedUpgrade['status'] === 302 && (string) ($afterUpgrade['plan_code'] ?? '') === AccessPlan::LIVE,
        'A second approved payment lifts an enrolment from Recorded Only to Live + Recorded on its own.',
        'The upgrade left the plan at ' . var_export($afterUpgrade['plan_code'] ?? null, true) . '.');
    $legacyPayment = $seedPayment($ids['legacy'], null, 'LEGACY-' . $token);
    $open('POST', '/admin/payments/decision', [
        '_csrf' => $csrfOf($open('GET', '/admin/payments', [], $jarAdmin)['body']),
        'payment_id' => (string) $legacyPayment,
        'decision' => 'approved',
        'review_note' => '',
    ], $jarAdmin);
    $assert($readPlan($ids['legacy']) === AccessPlan::RECORDED,
        'A payment with no plan recorded on it grants the safe plan rather than a guessed one.',
        'The unrecorded payment granted ' . var_export($readPlan($ids['legacy']), true) . '.');

    /* --------------------------------------------------------------- the admin's own control */
    $emit("\n-- the admin's own control");
    $beforePlan = $readAccess($ids['recorded']);
    $setPlan = $open('POST', '/admin/courses/access', [
        '_csrf' => $csrfOf($open('GET', '/admin/courses', [], $jarAdmin)['body']),
        'student_id' => (string) $ids['recorded'],
        'course_id' => (string) $courseId,
        'access_action' => 'plan',
        'plan_code' => AccessPlan::LIVE,
    ], $jarAdmin);
    $afterPlan = $readAccess($ids['recorded']);
    $assert($setPlan['status'] === 302 && (string) ($afterPlan['plan_code'] ?? '') === AccessPlan::LIVE,
        'The plan can be moved by hand, which is how a phone-in upgrade is applied.', 'The plan save answered ' . $setPlan['status'] . ' and left ' . var_export($afterPlan['plan_code'] ?? null, true) . '.');
    foreach (['status', 'expires_at', 'granted_at', 'granted_by', 'revoked_at', 'revoked_by'] as $untouched) {
        $assert((string) ($beforePlan[$untouched] ?? '') === (string) ($afterPlan[$untouched] ?? ''),
            'Setting the plan leaves ' . $untouched . ' exactly as it was.', $untouched . ' moved from ' . var_export($beforePlan[$untouched] ?? null, true) . ' to ' . var_export($afterPlan[$untouched] ?? null, true) . '.');
    }
    $open('POST', '/admin/courses/access', [
        '_csrf' => $csrfOf($open('GET', '/admin/courses', [], $jarAdmin)['body']),
        'student_id' => (string) $ids['recorded'],
        'course_id' => (string) $courseId,
        'access_action' => 'plan',
        'plan_code' => AccessPlan::LIVE,
    ], $jarAdmin);
    $assert($readPlan($ids['recorded']) === AccessPlan::LIVE,
        'Saving the plan a student already has is not reported as a failed enrolment.', 'Setting the same plan twice lost the enrolment.');
    $planAudit = (string) $db->query("SELECT metadata FROM lms_audit_logs WHERE action='course_access.plan' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $assert(str_contains($planAudit, 'live_recorded'), 'The plan change is audited with the plan it set.', 'The plan audit row reads ' . $planAudit . '.');

    $noEnrolment = $open('POST', '/admin/courses/access', [
        '_csrf' => $csrfOf($open('GET', '/admin/courses', [], $jarAdmin)['body']),
        'student_id' => (string) $ids['lonely'],
        'course_id' => (string) $courseId,
        'access_action' => 'plan',
        'plan_code' => AccessPlan::LIVE,
    ], $jarAdmin);
    $assert($noEnrolment['status'] >= 400 && str_contains($noEnrolment['body'], 'Grant access first'),
        'A plan cannot be hung on an enrolment that does not exist.', 'The lonely student plan save answered ' . $noEnrolment['status'] . ' with ' . substr($noEnrolment['body'], 0, 120) . '.');
    $assert($readPlan($ids['lonely']) === null, 'And nothing was written for it.', 'A row appeared for the student with no enrolment.');

    $forged = $open('POST', '/admin/courses/access', [
        '_csrf' => 'not-a-real-token',
        'student_id' => (string) $ids['recorded'],
        'course_id' => (string) $courseId,
        'access_action' => 'plan',
        'plan_code' => AccessPlan::RECORDED,
    ], $jarAdmin);
    $assert($forged['status'] >= 400 && $readPlan($ids['recorded']) === AccessPlan::LIVE,
        'A forged token moves no plan.', 'The forged save answered ' . $forged['status'] . ' and left the plan at ' . var_export($readPlan($ids['recorded']), true) . '.');
    $signedStray = $signIn($jarStray, $emails['stray'], $studentPassword);
    $assert($signedStray['signed'], 'A student with no enrolment at all signs in for the bounce check.', 'The stray student could not sign in.');
    $studentTried = $open('POST', '/admin/courses/access', [
        '_csrf' => $csrfOf($open('GET', '/dashboard', [], $jarStray)['body']),
        'student_id' => (string) $ids['recorded'],
        'course_id' => (string) $courseId,
        'access_action' => 'plan',
        'plan_code' => AccessPlan::LIVE,
    ], $jarStray);
    $assert($studentTried['status'] === 302 && (string) $studentTried['location'] === $appUrl . '/login'
        && $readPlan($ids['recorded']) === AccessPlan::LIVE,
        'A student who posts at the enrolment desk is bounced before the plan is touched.',
        'The student save answered ' . $studentTried['status'] . ' and left the plan at ' . var_export($readPlan($ids['recorded']), true) . '.');
    // Put the comparison pair back to the plans the fixture promised.
    $open('POST', '/admin/courses/access', [
        '_csrf' => $csrfOf($open('GET', '/admin/courses', [], $jarAdmin)['body']),
        'student_id' => (string) $ids['recorded'],
        'course_id' => (string) $courseId,
        'access_action' => 'plan',
        'plan_code' => AccessPlan::RECORDED,
    ], $jarAdmin);
    $assert($readPlan($ids['recorded']) === AccessPlan::RECORDED, 'The Recorded Only student is put back on their plan.', 'The reset left ' . var_export($readPlan($ids['recorded']), true) . '.');

    /* ---------------------------------------------------------------------- the gate itself */
    $emit("\n-- the lesson page: two students, two different documents");
    $signedLive = $signIn($jarLive, $emails['live'], $studentPassword);
    $signedRecorded = $signIn($jarRecorded, $emails['recorded'], $studentPassword);
    $assert($signedLive['signed'] && $signedRecorded['signed'], 'Both students sign in.', 'A student could not sign in (live ' . $signedLive['status'] . ', recorded ' . $signedRecorded['status'] . ').');
    $livePage = $open('GET', '/learning/lesson?lesson_id=' . $lessonIds['live'], [], $jarLive);
    $recordedPage = $open('GET', '/learning/lesson?lesson_id=' . $lessonIds['live'], [], $jarRecorded);
    $assert($livePage['status'] === 200 && $recordedPage['status'] === 200, 'Both lesson pages open.', 'The pages answered ' . $livePage['status'] . ' and ' . $recordedPage['status'] . '.');
    $assert(str_contains($livePage['body'], Security::escape($meetingUrl)) && str_contains($livePage['body'], 'Open live class')
        && str_contains($livePage['body'], 'LIVE CLASS') && str_contains($livePage['body'], $classLabel($futureAt)),
        'The Live + Recorded page carries the address, the button and the class time.', 'The live page is missing the card: ' . substr($livePage['body'], 0, 200) . '.');
    $assert(str_contains($livePage['body'], 'rel="noopener noreferrer"'), 'The meeting opens in a tab that cannot reach back into the lesson page.', 'The live link is not noopener.');
    foreach ([$meetingUrl, 'meet.google.com', 'Open live class', 'lesson-live-class', 'LIVE CLASS'] as $secret) {
        $assert(!str_contains($recordedPage['body'], $secret), 'The Recorded Only page holds no trace of "' . $secret . '".', 'The recorded page leaked ' . $secret . '.');
    }
    $assert(str_contains($recordedPage['body'], 'Live class lesson'), 'The Recorded Only student still sees the lesson they are enrolled in.', 'The recorded page lost the lesson itself.');

    $db->prepare('UPDATE lms_lessons SET meeting_url=?,meeting_at=? WHERE id=?')->execute([$meetingUrl2, $futureAt, $lessonIds['live']]);
    $liveAgain = $open('GET', '/learning/lesson?lesson_id=' . $lessonIds['live'], [], $jarLive);
    $recordedAgain = $open('GET', '/learning/lesson?lesson_id=' . $lessonIds['live'], [], $jarRecorded);
    $assert(str_contains($liveAgain['body'], Security::escape($meetingUrl2)), 'Change the address and the new one is what the live student reads: the card comes from the row.', 'The live page still printed the old address.');
    foreach ([$meetingUrl, $meetingUrl2, 'meet.google.com', 'zoom.us'] as $secret) {
        $assert(!str_contains($recordedAgain['body'], $secret), 'And the second address is still invisible to Recorded Only ("' . $secret . '" absent).', 'The recorded page leaked ' . $secret . ' after the change.');
    }

    $pastPage = $open('GET', '/learning/lesson?lesson_id=' . $lessonIds['past'], [], $jarLive);
    $assert(str_contains($pastPage['body'], 'its time has passed') && str_contains($pastPage['body'], Security::escape($meetingUrl)),
        'A class that already ran says so, and still offers the recording.', 'The passed class read: ' . substr((string) strstr($pastPage['body'], 'LIVE CLASS'), 0, 260));
    $plainPage = $open('GET', '/learning/lesson?lesson_id=' . $lessonIds['plain'], [], $jarLive);
    $assert($plainPage['status'] === 200 && !str_contains($plainPage['body'], 'lesson-live-class'),
        'A lesson with no class shows no card even to a live-plan student.', 'The plain lesson grew a live card.');

    /* ----------------------------------------------------------------- the course and lists */
    $emit("\n-- where else the mark appears, and where it must not");
    $liveCourse = $open('GET', '/learning?course_id=' . $courseId, [], $jarLive);
    $recordedCourse = $open('GET', '/learning?course_id=' . $courseId, [], $jarRecorded);
    $assert(str_contains($liveCourse['body'], ' · live class') && !str_contains($liveCourse['body'], 'learning-plan-note'),
        'The live student\'s outline marks the lessons that run live, and needs no explanation.', 'The live outline lost the marker or printed the note.');
    $assert(str_contains($recordedCourse['body'], 'learning-plan-note') && str_contains($recordedCourse['body'], 'Live + Recorded'),
        'The Recorded Only student is told once, on the course page, that live classes are a plan they do not hold.', 'The recorded outline printed no plan note.');
    $assert(!str_contains($recordedCourse['body'], ' · live class'), 'And that note does not list which lessons hold a class.', 'The recorded outline marked lessons as live.');
    foreach ([$meetingUrl2, 'meet.google.com', 'zoom.us'] as $secret) {
        $assert(!str_contains($recordedCourse['body'], $secret), 'The course page carries no address either ("' . $secret . '" absent).', 'The recorded course page leaked ' . $secret . '.');
    }
    $liveList = $open('GET', '/student/lessons', [], $jarLive);
    $recordedList = $open('GET', '/student/lessons', [], $jarRecorded);
    $assert($liveList['status'] === 200 && substr_count($liveList['body'], 'my-lesson-live') >= 2,
        'The live student\'s lesson list marks the lessons that hold a class.', 'The live lesson list printed no pill.');
    $assert($recordedList['status'] === 200 && !str_contains($recordedList['body'], 'my-lesson-live'),
        'The Recorded Only list prints none of those pills.', 'The recorded lesson list grew a pill.');
    foreach ([$meetingUrl2, 'meet.google.com', 'zoom.us'] as $secret) {
        $assert(!str_contains($liveList['body'], $secret) && !str_contains($recordedList['body'], $secret),
            'Neither list carries an address - the mark is a word, not a link ("' . $secret . '" absent).', 'A lesson list printed ' . $secret . '.');
    }
    $buyerPayments = $open('GET', '/student/payments', [], $jarBuyer);
    $assert($buyerPayments['status'] === 200 && str_contains($buyerPayments['body'], 'Live + Recorded'),
        'The student is shown the option they actually chose on their own payment list.', 'The payment list printed no plan label.');

    /* --------------------------------------------------------------------- the admin desks */
    $emit("\n-- the two desks that set all of this");
    $adminLessons = $open('GET', '/admin/lessons', [], $jarAdmin);
    $assert($adminLessons['status'] === 200 && str_contains($adminLessons['body'], 'name="meeting_url"')
        && str_contains($adminLessons['body'], 'name="meeting_platform"')
        && str_contains($adminLessons['body'], 'name="meeting_at"') && str_contains($adminLessons['body'], 'Live meeting URL (optional)'),
        'The lesson library offers the class link and its time, in the add card and in the editor.', 'The lesson library has no meeting fields (status ' . $adminLessons['status'] . ').');
    $assert(str_contains($adminLessons['body'], 'table-badge live') && str_contains($adminLessons['body'], 'Live + Recorded only'),
        'A lesson that holds a class says so in the table, and says whose plan it belongs to.', 'The library did not mark the live lesson.');
    $assert(str_contains($adminLessons['body'], $classLabel($futureAt)) || str_contains($adminLessons['body'], 'live class'),
        'The class time is printed on the UTC clock the admin typed it in.', 'The library printed no class time.');
    $adminCourses = $open('GET', '/admin/courses', [], $jarAdmin);
    $assert($adminCourses['status'] === 200 && str_contains($adminCourses['body'], 'name="plan_code"')
        && str_contains($adminCourses['body'], 'Set plan only') && str_contains($adminCourses['body'], 'Delivery plan'),
        'The enrolment desk carries the plan selector and the plan-only action.', 'The courses desk has no plan control (status ' . $adminCourses['status'] . ').');

    /* ----------------------------------------------------------------------- the save rules */
    $emit("\n-- a link that is not a link is refused, and the lesson keeps what it had");
    $csrf = $csrfOf($adminLessons['body']);
    $postLesson = static function (string $url, string $at, ?string $videoUrl = null, string $duration = '', string $platform = '') use ($open, $jarAdmin, $csrf, $courseId, $moduleId, $lessonIds, $token): array {
        return $open('POST', '/admin/courses/lessons/save', [
            '_csrf' => $csrf,
            'lesson_id' => (string) $lessonIds['editable'],
            'course_id' => (string) $courseId,
            'module_id' => (string) $moduleId,
            'module_title' => 'Smoke module',
            'lesson_title' => 'Editable lesson',
            'body' => 'Body',
            'status' => 'published',
            'video_url' => $videoUrl ?? 'https://cdn.example.com/edit-' . $token . '.mp4',
            'video_duration' => $videoUrl === '' ? '' : ($duration !== '' ? $duration : '3:00'),
            'meeting_platform' => $platform,
            'meeting_url' => $url,
            'meeting_at' => $at,
        ], $jarAdmin);
    };
    foreach ([
        ['javascript:alert(1)//', '', 'a script dressed as a link'],
        ['http://zoom.us/j/123456789', '', 'plain http'],
        ['https://zoom.us@evil.test/', '', 'an address with an @ in it'],
        ['https://' . str_repeat('a', 600) . '.test/j', '', 'a link over 600 characters'],
    ] as [$bad, $badAt, $label]) {
        $refused = $postLesson($bad, $badAt);
        $held = $readMeeting($lessonIds['editable']);
        $assert($refused['status'] >= 400 && $held[0] === null && $held[1] === null,
            $label . ' is refused, and the lesson is left exactly as it was.', $label . ' answered ' . $refused['status'] . ' and stored ' . var_export($held[0], true) . '.');
    }
    $timeless = $postLesson('', '2026-11-08T20:00');
    $assert($timeless['status'] >= 400 && str_contains($timeless['body'], 'needs a meeting link'),
        'A class time with no link is refused rather than kept as an appointment nobody can reach.', 'The time-only save answered ' . $timeless['status'] . '.');
    $saved = $postLesson('https://meet.google.com/abc-defg-hij', '2026-11-08T20:00');
    $stored = $readMeeting($lessonIds['editable']);
    $assert($saved['status'] === 302 && (string) $stored[0] === 'https://meet.google.com/abc-defg-hij' && (string) $stored[1] === '2026-11-08 20:00:00',
        'A real Meet address and its UTC time are stored as typed.', 'The save stored ' . json_encode($stored) . ' (status ' . $saved['status'] . ').');
    $videoOnly = $postLesson('', '', 'https://www.youtube.com/watch?v=abc123def45');
    $onlyVideo = $db->prepare('SELECT video_url,meeting_url FROM lms_lessons WHERE id=?');
    $onlyVideo->execute([$lessonIds['editable']]);
    $onlyVideoRow = $onlyVideo->fetch(PDO::FETCH_ASSOC) ?: [];
    $assert($videoOnly['status'] === 302 && str_contains((string) ($onlyVideoRow['video_url'] ?? ''), 'youtube.com/watch') && $onlyVideoRow['meeting_url'] === null,
        'A recorded video saves without requiring a live meeting link.', 'The video-only save answered ' . $videoOnly['status'] . ' and stored ' . json_encode($onlyVideoRow) . '.');
    $liveOnly = $postLesson('https://zoom.us/j/4005006007', '', '', '');
    $onlyLive = $db->prepare('SELECT video_url,video_duration_seconds,meeting_url,meeting_platform FROM lms_lessons WHERE id=?');
    $onlyLive->execute([$lessonIds['editable']]);
    $onlyLiveRow = $onlyLive->fetch(PDO::FETCH_ASSOC) ?: [];
    $assert($liveOnly['status'] === 302 && $onlyLiveRow['video_url'] === null && $onlyLiveRow['video_duration_seconds'] === null
        && $onlyLiveRow['meeting_url'] === 'https://zoom.us/j/4005006007' && $onlyLiveRow['meeting_platform'] === 'zoom',
        'A live meeting saves independently with no recorded video or duration.', 'The live-only save answered ' . $liveOnly['status'] . ' and stored ' . json_encode($onlyLiveRow) . '.');
    $noLinks = $postLesson('', '', '', '');
    $emptyLinks = $db->prepare('SELECT video_url,video_duration_seconds,meeting_url,meeting_platform,meeting_at FROM lms_lessons WHERE id=?');
    $emptyLinks->execute([$lessonIds['editable']]);
    $emptyLinksRow = $emptyLinks->fetch(PDO::FETCH_ASSOC) ?: [];
    $assert($noLinks['status'] === 302 && $emptyLinksRow === [
        'video_url' => null,
        'video_duration_seconds' => null,
        'meeting_url' => null,
        'meeting_platform' => null,
        'meeting_at' => null,
    ], 'A lesson with all optional media fields empty saves cleanly.', 'The empty-link save answered ' . $noLinks['status'] . ' and stored ' . json_encode($emptyLinksRow) . '.');
    $invalidPlatform = $postLesson('', '', '', '', 'teams');
    $assert($invalidPlatform['status'] >= 400, 'An unsupported platform is rejected even when its optional meeting URL is blank.',
        'The invalid platform was accepted with status ' . $invalidPlatform['status'] . '.');
    $cleared = $postLesson('', '');
    $afterClear = $readMeeting($lessonIds['editable']);
    $assert($cleared['status'] === 302 && $afterClear[0] === null && $afterClear[1] === null,
        'Removing the link removes the class time with it, so no orphan appointment is left to show.', 'The clear left ' . json_encode($afterClear) . '.');

    /* ---------------------------------------------------------- the plan a re-import can recover */
    $emit("\n-- what a re-import can still read out of an old database");
    // The two backfills are the only route a purchase made before payments.plan_code existed has to a
    // lesson page, and they read a place nothing above them touches: the audit blob the checkout used
    // to write. Two payments are put back into the state an old row sits in - the plan column empty -
    // one of them with the submission audit row an old checkout really wrote, the other without any
    // record at all, and then the very file the README hands the admin is imported over the top.
    $db->prepare("INSERT INTO lms_audit_logs(actor_user_id,action,entity_type,entity_id,metadata) VALUES(?, 'payment.submitted', 'payment', ?, JSON_OBJECT('course_id', ?, 'option_code', 'live_recorded'))")
        ->execute([$ids['legacy'], $legacyPayment, $courseId]);
    $db->prepare('UPDATE lms_payments SET plan_code = NULL WHERE id = ?')->execute([$upgradePayment]);
    $beforeLegacy = $readAccess($ids['legacy']);
    $legacyPlanBefore = $db->query('SELECT plan_code FROM lms_payments WHERE id = ' . (int) $legacyPayment)->fetchColumn();
    $assert($legacyPlanBefore === null && $readPlan($ids['legacy']) === AccessPlan::RECORDED,
        'Both accounts start this section as an old database holds them: an approved payment with no plan on it, and an enrolment on the safe side.',
        'The legacy fixture did not start empty: ' . json_encode([$legacyPlanBefore, $readPlan($ids['legacy'])]) . '.');
    $importReport = $reimportSchema();
    // Only what the client wrote to stderr is read back. Its stdout is meant to be full of ones:
    // every guarded ALTER block in the file answers 'SELECT 1' when the column it would add is
    // already there, which is the sound half of a re-import rather than a message to check.
    $assert(trim($importReport) === '', 'Re-importing the schema over a database that already has it reports no error and breaks nothing.',
        'The re-import reported: ' . str_replace("\n", ' / ', substr(trim($importReport), 0, 300)));
    $assert((string) $db->query('SELECT plan_code FROM lms_payments WHERE id = ' . (int) $legacyPayment)->fetchColumn() === AccessPlan::LIVE,
        'A purchase the audit log can explain is given its plan back.',
        'The legacy payment still reads ' . var_export($db->query('SELECT plan_code FROM lms_payments WHERE id = ' . (int) $legacyPayment)->fetchColumn(), true) . '.');
    $assert($readPlan($ids['legacy']) === AccessPlan::LIVE,
        'And its enrolment inherits that plan, so a Live + Recorded student bought before the column exists still reaches their classes.',
        'The enrolment the backfill left on ' . var_export($readPlan($ids['legacy']), true) . '.');
    $afterLegacy = $readAccess($ids['legacy']);
    $assert($afterLegacy['status'] === $beforeLegacy['status']
        && $afterLegacy['granted_by'] === $beforeLegacy['granted_by']
        && $afterLegacy['granted_at'] === $beforeLegacy['granted_at']
        && $afterLegacy['expires_at'] === $beforeLegacy['expires_at'],
        'The backfill moves the plan and nothing else - not the state, the grant, the actor or the expiry.',
        'The re-import also changed ' . json_encode(array_diff_assoc($afterLegacy, $beforeLegacy)) . '.');
    // The half that matters more: a payment no record explains is left unclaimed rather than guessed
    // at, and the enrolment falls back to the closed plan instead of keeping one nothing can account
    // for. An admin has to review those accounts - this proves the review starts from the safe side.
    $assert($db->query('SELECT plan_code FROM lms_payments WHERE id = ' . (int) $upgradePayment)->fetchColumn() === null
        && $readPlan($ids['upgrade']) === AccessPlan::RECORDED,
        'A payment the audit cannot explain keeps no plan, and its enrolment drops to Recorded Only.',
        'An unexplained purchase was left holding ' . var_export($readPlan($ids['upgrade']), true) . '.');
    $reimportSchema();
    $assert($readPlan($ids['legacy']) === AccessPlan::LIVE && $readPlan($ids['upgrade']) === AccessPlan::RECORDED,
        'A third import changes nothing, because a backfill only moves rows that disagree with the record.',
        'Re-running the import moved the plans to ' . json_encode([$readPlan($ids['legacy']), $readPlan($ids['upgrade'])]) . '.');
    // Put the upgrade fixture back the way the section above left it, so a reader of the failures
    // below is looking at the states those checks put there.
    $db->prepare('UPDATE lms_payments SET plan_code = ? WHERE id = ?')->execute([AccessPlan::LIVE, $upgradePayment]);
    $db->prepare('UPDATE lms_student_course_access SET plan_code = ? WHERE student_id = ? AND course_id = ?')
        ->execute([AccessPlan::LIVE, $ids['upgrade'], $courseId]);

    /* ----------------------------------------------------------- a schema that has not arrived */
    $emit("\n-- a database that has not been re-imported");
    $runSql('ALTER TABLE lms_lessons DROP COLUMN meeting_at, DROP COLUMN meeting_platform, DROP COLUMN meeting_url');
    $runSql('ALTER TABLE lms_student_course_access DROP INDEX idx_access_plan, DROP COLUMN plan_code');
    $runSql('ALTER TABLE lms_payments DROP COLUMN plan_code');
    $columnsDropped = true;
    AccessPlan::forgetColumnState();
    $bareLive = $open('GET', '/learning/lesson?lesson_id=' . $lessonIds['live'], [], $jarLive);
    $bareRecorded = $open('GET', '/learning/lesson?lesson_id=' . $lessonIds['live'], [], $jarRecorded);
    $assert($bareLive['status'] === 200 && !str_contains($bareLive['body'], 'lesson-live-class')
        && !str_contains($bareLive['body'], 'meet.google.com'),
        'A live-plan student still opens their lesson, and no card can be drawn from a column that is not there.', 'The lesson page broke or invented a card on a bare schema (status ' . $bareLive['status'] . ').');
    $assert($bareRecorded['status'] === 200 && !str_contains($bareRecorded['body'], 'meet.google.com'),
        'The Recorded Only page is unchanged.', 'The recorded page answered ' . $bareRecorded['status'] . '.');
    $bareCourse = $open('GET', '/learning?course_id=' . $courseId, [], $jarRecorded);
    $assert($bareCourse['status'] === 200 && !str_contains($bareCourse['body'], 'learning-plan-note'),
        'The course page says nothing about plans it cannot read.', 'The bare course page guessed about plans.');
    $bareLibrary = $open('GET', '/admin/lessons', [], $jarAdmin);
    $bareCourses = $open('GET', '/admin/courses', [], $jarAdmin);
    $assert($bareLibrary['status'] === 200 && !str_contains($bareLibrary['body'], 'name="meeting_url"'),
        'The lesson library hides the fields it cannot store.', 'The bare library still offered a meeting field.');
    $assert($bareCourses['status'] === 200 && !str_contains($bareCourses['body'], 'name="plan_code"')
        && str_contains($bareCourses['body'], 'need a database update'),
        'The enrolment desk hides the plan selector and tells the admin which file to import.', 'The bare courses desk offered a plan it cannot keep.');
    $bareSave = $postLesson('https://meet.google.com/abc-defg-hij', '');
    $assert($bareSave['status'] >= 400 && str_contains($bareSave['body'], 'Import it, then try again'),
        'Pasting a class link into an old database is refused in words, not dropped in silence.', 'The bare save answered ' . $bareSave['status'] . ' with ' . substr($bareSave['body'], 0, 120) . '.');
    $barePlan = $open('POST', '/admin/courses/access', [
        '_csrf' => $csrfOf($bareCourses['body']),
        'student_id' => (string) $ids['recorded'],
        'course_id' => (string) $courseId,
        'access_action' => 'plan',
        'plan_code' => AccessPlan::LIVE,
    ], $jarAdmin);
    $assert($barePlan['status'] >= 400, 'And a plan change on an old database is refused rather than lost.', 'The bare plan save answered ' . $barePlan['status'] . '.');
    $signedStray2 = $signIn($jarStray, $emails['stray'], $studentPassword);
    $assert($signedStray2['signed'], 'The bounced student signs in again to drive the bare checkout.', 'The stray student could not sign in (status ' . $signedStray2['status'] . ').');
    $barePurchase = $open('POST', '/payments', [
        '_csrf' => $csrfOf($open('GET', '/payments/new?course_id=' . $courseId, [], $jarStray)['body']),
        'course_id' => (string) $courseId,
        'option_code' => AccessPlan::LIVE,
        'transaction_reference' => 'BARE-' . $token,
    ], $jarStray, 'payment_proof');
    $bareStmt = $db->prepare('SELECT COUNT(*) FROM lms_payments WHERE transaction_reference = ?');
    $bareStmt->execute(['BARE-' . $token]);
    $barePayment = (int) $bareStmt->fetchColumn();
    $bareCheckout = $open('GET', '/payments/new?course_id=' . $courseId, [], $jarStray)['body'];
    if ($canUpload) {
        $assert($barePurchase['status'] === 302 && $barePayment === 1,
            'A purchase still lands on a database that has no column for its plan.', 'The bare checkout recorded ' . $barePayment . ' payment row(s).');
    } else {
        $assert($barePurchase['status'] === 302 && $barePayment === 0 && str_contains($bareCheckout, 'not writable'),
            'A database with no plan column does not break the checkout: the same proof folder is what stops it, and no schema complaint is invented.',
            'The bare checkout answered ' . $barePurchase['status'] . ' and left ' . $barePayment . ' row(s).');
    }
    $db->prepare('DELETE FROM lms_payments WHERE transaction_reference = ?')->execute(['BARE-' . $token]);
} catch (Throwable $exception) {
    $failures[] = 'The run stopped: ' . $exception->getMessage();
    $report .= '  x ' . $exception->getMessage() . "\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    // The scratch database outlives this run, and the next one expects the columns to be there.
    if ($columnsDropped) {
        $runSql('ALTER TABLE lms_lessons ADD COLUMN meeting_url VARCHAR(600) NULL AFTER video_duration_seconds, ADD COLUMN meeting_platform ENUM(\'google_meet\',\'zoom\') NULL AFTER meeting_url, ADD COLUMN meeting_at DATETIME NULL AFTER meeting_platform');
        $runSql('ALTER TABLE lms_payments ADD COLUMN plan_code ENUM(\'recorded_only\',\'live_recorded\') NULL AFTER course_id');
        $runSql('ALTER TABLE lms_student_course_access ADD COLUMN plan_code ENUM(\'recorded_only\',\'live_recorded\') NOT NULL DEFAULT \'recorded_only\' AFTER status, ADD INDEX idx_access_plan(student_id, plan_code)');
        $restored = $columnPresent('lessons', 'meeting_url') && $columnPresent('lessons', 'meeting_platform') && $columnPresent('payments', 'plan_code') && $columnPresent('student_course_access', 'plan_code');
        $emit(($restored ? '  ok ' : '  FAIL ') . 'the four live class columns are back for the next run.');
    }
    AccessPlan::forgetColumnState();
    // Every row this run made goes back the way it was found, in the order the schema stacks them.
    // The uploaded proofs are collected first, because their paths live on the payment rows.
    $allIds = array_values(array_diff($ids, [0]));
    $studentIds = array_values(array_diff($allIds, [$ids['admin']]));
    $idList = implode(',', $allIds);
    $proofStmt = $db->prepare('SELECT payment_proof_path FROM lms_payments WHERE student_id IN (' . $idList . ')');
    $proofStmt->execute();
    foreach ($proofStmt->fetchAll(PDO::FETCH_COLUMN) as $relative) {
        if (is_string($relative) && $relative !== '') $proofFiles[] = BASE_PATH . '/storage/uploads/private/' . $relative;
    }
    foreach ($studentIds as $id) {
        $db->prepare('DELETE FROM lms_lesson_progress WHERE student_id = ?')->execute([$id]);
    }
    // Opening a course page recalculates the stored grade, so the grading row is this run's own
    // doing and has to go before the course it points at.
    $db->exec('DELETE FROM lms_course_grades WHERE student_id IN (' . $idList . ')');
    $db->exec('DELETE FROM lms_attendance_records WHERE student_id IN (' . $idList . ')');
    $db->exec('DELETE FROM lms_notification_recipients WHERE student_id IN (' . $idList . ')');
    $db->exec('DELETE FROM lms_notifications WHERE created_by IN (' . $idList . ')');
    $db->exec('DELETE FROM lms_user_sessions WHERE user_id IN (' . $idList . ')');
    $db->exec('DELETE FROM lms_login_events WHERE user_id IN (' . $idList . ')');
    // Every mail this run tried to send is logged against the account it was meant for.
    $db->exec('DELETE FROM lms_email_logs WHERE related_user_id IN (' . $idList . ')');
    $db->exec('DELETE FROM lms_email_otps WHERE user_id IN (' . $idList . ')');
    $db->exec('DELETE FROM lms_certificates WHERE student_id IN (' . $idList . ')');
    $limitStmt = $db->prepare('DELETE FROM lms_auth_rate_limits WHERE subject_hash IN (' . implode(',', array_fill(0, count($allIds), '?')) . ')');
    $limitStmt->execute(array_map(static fn(int $id): string => hash('sha256', 'user:' . $id), $allIds));
    $db->exec('DELETE FROM lms_student_course_access WHERE student_id IN (' . $idList . ')');
    // Any payment this run left behind, whether the harness or the checkout wrote it: the account
    // rows below cannot go while a payment still points at them.
    $db->exec('DELETE FROM lms_payments WHERE student_id IN (' . $idList . ')');
    $db->exec('DELETE FROM lms_audit_logs WHERE actor_user_id IN (' . $idList . ')');
    $db->prepare('DELETE FROM lms_lessons WHERE module_id IN (SELECT id FROM lms_course_modules WHERE course_id = ?)')->execute([$courseId]);
    $db->prepare('DELETE FROM lms_course_pricing_options WHERE course_id = ?')->execute([$courseId]);
    $db->prepare('DELETE FROM lms_course_modules WHERE course_id = ?')->execute([$courseId]);
    $db->prepare('DELETE FROM lms_courses WHERE slug = ?')->execute([$slug]);
    $db->exec('DELETE FROM lms_users WHERE id IN (' . $idList . ')');
    foreach ($proofFiles as $path) {
        if (is_file($path)) @unlink($path);
    }
    foreach ($scratchFiles as $path) {
        @unlink($path);
    }
    if (($options['drop'] ?? '') === '1') {
        shell_exec($mysql . ' ' . $credentials . ' -e ' . escapeshellarg('DROP DATABASE `' . $dbName . '`'));
        $report .= 'dropped ' . $dbName;
    }
}

$status = $failures === [] ? 0 : 1;
$drain();
if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . ' check(s) failed, first: ' . $failures[0] . "\n");
} else {
    echo 'PASS: live class links reach the plan that paid for them and nobody else on ' . $dbName . "\n";
}
exit($status);
