<?php
declare(strict_types=1);

/**
 * Video protection smoke test (company documentation §9.3).
 *
 * Run against a scratch database:
 *   /Applications/XAMPP/xamppfiles/bin/php tests/video-protection-flow-smoke.php --database=tsg_video_protection_check_tmp
 *
 * Add --drop=1 to remove the scratch database afterwards.
 *
 * Two halves, because the feature has two halves. The first replays several browser sessions inside
 * this process and reads the registry directly: the cap arithmetic, the idle sweep, the migration
 * paths and the fail-open behaviour cannot be shown by one HTTP request, and a wrong answer there
 * locks students out of their lessons. The second drives PHP's own web server over real HTTP with a
 * cookie jar per device, because a session that is only enforced in theory is not enforced: the
 * sign-in has to leave a row, the displaced device has to bounce on its next click with a sentence
 * that explains itself, the player page has to carry the mark, and the admin has to be able to read
 * and clear what the player reported.
 *
 * Watch time is the regression this change could really cause, so the heartbeat is measured here
 * too: the protection layer rides the same request and must leave the credited seconds exactly as
 * lesson-watch-flow-smoke.php predicts them.
 */

define('BASE_PATH', dirname(__DIR__));
foreach (
    [
        '/app/Core/Env.php',
        '/app/Core/View.php',
        '/app/Core/Security.php',
        '/app/Core/Database.php',
        '/app/Core/RateLimiter.php',
        '/app/Core/AuthMiddleware.php',
        '/app/Services/EmailLayout.php',
        '/app/Services/EmailMessages.php',
        '/app/Services/AccountIdentity.php',
        '/app/Services/AdminAttention.php',
        '/app/Services/MailService.php',
        '/app/Services/NotificationService.php',
        '/app/Services/AttendanceService.php',
        '/app/Services/LessonMedia.php',
        '/app/Services/LessonProtection.php',
        '/app/Services/SessionRegistry.php',
        '/app/Services/AuthService.php',
    ] as $file
) {
    require BASE_PATH . $file;
}

use App\Core\Database;
use App\Core\Env;
use App\Core\Security;
use App\Services\AccountIdentity;
use App\Services\AuthService;
use App\Services\LessonMedia;
use App\Services\LessonProtection;
use App\Services\MailService;
use App\Services\SessionRegistry;

Env::load(BASE_PATH . '/.env');
$options = getopt('', ['database::', 'drop::']);
if (($options['database'] ?? '') !== '') {
    putenv('DB_NAME=' . $options['database']);
}
$dbName = (string) (getenv('DB_NAME') ?: '');
$token = bin2hex(random_bytes(6));
$failures = [];

/* Nothing is printed while the run is alive. PHP refuses session_id() once output has been sent,
   and this harness replays several browser sessions inside one process, so every line is collected
   and drained at the end - including by the shutdown handler, so a fatal still reports how far the
   run got. */
$report = '';
$printed = false;
$emit = static function (string $line) use (&$report): void {
    $report .= $line . "\n";
};
// Drained once, and by a shutdown handler as well as by the last line of the script: a run that
// dies half way through still has to say how far it got.
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
/** Fatal, never a recorded failure: this suite writes real rows, and a soft guard is how fixtures
    end up in somebody's database. */
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

$db = Database::connection();
$emptyName = $dbName . '_empty';
$studentEmail = 'video-protection-' . $token . '@example.test';
$resetEmail = 'video-protection-reset-' . $token . '@example.test';
$adminEmail = 'video-protection-admin-' . $token . '@example.test';
$studentPassword = 'Video Protection Smoke 1!';
$adminPassword = 'Video Protection Admin 1!';
// A hand-set Academy ID rather than a derived one: the assertions below compare the mark on the
// screen with the number the Academy would quote back to a student, so it has to be a known string.
$studentReference = 'TSG-VPS-' . strtoupper(substr($token, 0, 6));
$studentId = 0;
$resetStudentId = 0;
$adminId = 0;
$courseId = 0;
$otherCourseId = 0;
$lessonId = 0;
$embedLessonId = 0;
$foreignLessonId = 0;
$slugs = ['video-protection-smoke-' . $token, 'video-protection-other-' . $token];
$serverPort = 0;
$server = null;
$scratchFiles = [];
$cliSessions = [];

/* --------------------------------------------------------------------------- fixtures */
$putSetting = $db->prepare('INSERT INTO lms_app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
$readSetting = static function (string $key) use ($db): ?string {
    $stmt = $db->prepare('SELECT setting_value FROM lms_app_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (string) $value;
};
$resetSessions = static function (int $id) use ($db): void {
    $db->prepare('DELETE FROM lms_user_sessions WHERE user_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM lms_lesson_view_events WHERE student_id = ?')->execute([$id]);
};
$activeRows = static function (int $id) use ($db): array {
    $stmt = $db->prepare('SELECT session_hash,ip_address,revoked_at,revoked_reason,created_at,last_seen_at FROM lms_user_sessions WHERE user_id = ? ORDER BY id');
    $stmt->execute([$id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

foreach (['students' => $studentEmail, 'reset' => $resetEmail, 'admin' => $adminEmail] as $label => $email) {
    $db->prepare('DELETE FROM lms_users WHERE email = ?')->execute([$email]);
}
$db->prepare("DELETE FROM lms_courses WHERE slug IN (?,?)")->execute($slugs);

$insertUser = $db->prepare("INSERT INTO lms_users(full_name,email,password_hash,role,status,email_verified_at,account_reference) VALUES(?,?,?,?,\"active\",UTC_TIMESTAMP(),?)");
$insertUser->execute(['Video Protection Smoke Student', $studentEmail, password_hash($studentPassword, PASSWORD_BCRYPT), 'student', $studentReference]);
$studentId = (int) $db->lastInsertId();
$insertUser->execute(['Video Protection Smoke Reset Student', $resetEmail, password_hash($studentPassword, PASSWORD_BCRYPT), 'student', 'TSG-VPR-' . strtoupper(substr($token, 0, 6))]);
$resetStudentId = (int) $db->lastInsertId();
$insertUser->execute(['Video Protection Smoke Admin', $adminEmail, password_hash($adminPassword, PASSWORD_BCRYPT), 'admin', null]);
$adminId = (int) $db->lastInsertId();

$insertCourse = $db->prepare("INSERT INTO lms_courses(title,slug,visibility) VALUES(?,?,\"published\")");
$insertCourse->execute(['Video Protection Smoke Course', $slugs[0]]);
$courseId = (int) $db->lastInsertId();
$insertCourse->execute(['Video Protection Smoke Other Course', $slugs[1]]);
$otherCourseId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO lms_course_modules(course_id,title,sort_order) VALUES(?,?,1)')->execute([$courseId, 'Smoke module']);
$moduleId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO lms_course_modules(course_id,title,sort_order) VALUES(?,?,1)')->execute([$otherCourseId, 'Other module']);
$otherModuleId = (int) $db->lastInsertId();
$db->prepare("INSERT INTO lms_student_course_access(student_id,course_id,status,granted_by) VALUES(?,?,\"active\",?)")->execute([$studentId, $courseId, $adminId]);

$insertLesson = $db->prepare("INSERT INTO lms_lessons(module_id,title,body,video_url,video_provider,video_duration_seconds,sort_order,status) VALUES(?,?,?,?,?,?,1,'published')");
// A direct file with a known length: the only shape that is trackable, so the watch-time guard has
// something to measure. The values below are the ones lesson-watch-flow-smoke.php already proves.
$insertLesson->execute([$moduleId, 'Protection recording', 'Smoke lesson body', 'https://cdn.example.com/smoke-' . $token . '.mp4', 'file', 200]);
$lessonId = (int) $db->lastInsertId();
$insertLesson->execute([$moduleId, 'Protection embed', 'Smoke lesson body', 'https://www.youtube.com/watch?v=' . substr($token, 0, 11), 'youtube', null]);
$embedLessonId = (int) $db->lastInsertId();
$insertLesson->execute([$otherModuleId, 'Foreign recording', 'Not this student', 'https://cdn.example.com/other-' . $token . '.mp4', 'file', 200]);
$foreignLessonId = (int) $db->lastInsertId();

/* ------------------------------------------------------------------------- web server */
// Routes, cookies, session ids and the CSRF token a real form carries are only observable over
// HTTP. The front controller is the router script, and APP_URL is rebuilt around the port the
// server actually got, so the Location headers the app emits are the ones a browser follows.
$probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!is_resource($probe)) {
    fwrite(STDERR, 'FAIL: no free loopback port for the test web server: ' . $errstr . "\n");
    exit(1);
}
$serverPort = (int) parse_url((string) stream_socket_get_name($probe, false), PHP_URL_PORT);
fclose($probe);
$appUrl = 'http://127.0.0.1:' . $serverPort;
putenv('APP_URL=' . $appUrl);
// Forced empty: nothing in this suite sends mail, and a mail step that reaches for a server would
// only make the run slow and flaky.
putenv('MAIL_HOST=');
putenv('MAIL_PORT=25');

if (!is_dir(BASE_PATH . '/storage/tmp')) {
    @mkdir(BASE_PATH . '/storage/tmp', 0700, true);
}
$scratch = BASE_PATH . '/storage/tmp/video-protection-smoke-' . $token;
$serverLog = $scratch . '.server.log';
$scratchFiles[] = $serverLog;
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

$sessionName = (string) (require BASE_PATH . '/config/app.php')['session_name'];
/** One request, sent the way a browser sends it. No redirect is followed automatically, because
 *  where a bounced device is sent is exactly what is under test. */
$open = static function (string $method, string $path, array $fields = [], string $jar = '') use ($appUrl, &$failures, $sessionName): array {
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
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($fields));
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
    $headers = (string) substr((string) $response, 0, $headerSize);
    $location = null;
    foreach (explode("\r\n", $headers) as $line) {
        if (stripos($line, 'Location:') === 0) $location = trim(substr($line, 9));
    }
    return ['status' => $status, 'body' => (string) substr((string) $response, $headerSize), 'location' => $location];
};
$jarOf = static function (string $device) use ($scratch, &$scratchFiles): string {
    $path = $scratch . '.' . $device . '.cookies';
    $scratchFiles[] = $path;
    return $path;
};
/** The session id the jar is holding, which is what the registry must NOT store in the clear. */
$sessionIdOf = static function (string $jar) use ($sessionName): string {
    $found = '';
    foreach (explode("\n", (string) @file_get_contents($jar)) as $line) {
        $columns = explode("\t", $line);
        if (count($columns) >= 7 && $columns[5] === $sessionName) $found = (string) $columns[6];
    }
    return $found;
};
$csrfOf = static function (string $html): string {
    return preg_match('/name="_csrf" value="([^"]+)"/', $html, $match) === 1 ? $match[1] : '';
};
$csrfAttr = static function (string $html): string {
    return preg_match('/data-csrf="([0-9a-f]{64})"/', $html, $match) === 1 ? $match[1] : '';
};
$signIn = static function (string $jar, string $email, string $password) use ($open, $csrfOf, $appUrl): array {
    @unlink($jar);
    $form = $open('GET', '/login', [], $jar);
    $reply = $open('POST', '/login', [
        '_csrf' => $csrfOf($form['body']),
        'email' => $email,
        'password' => $password,
    ], $jar);
    return ['signed' => $reply['status'] === 302 && (string) $reply['location'] === $appUrl . '/dashboard', 'status' => $reply['status']];
};

$registry = new SessionRegistry($db);
$protection = new LessonProtection($db);
$auth = new AuthService($db, new MailService($db));
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

try {
    /* ------------------------------------------------------------ the imported schema */
    $emit("\n-- the schema the feature stands on");
    $tablePresent = static function (string $name) use ($mysql, $credentials, $dbName): bool {
        $counted = shell_exec($mysql . ' ' . $credentials . ' -N -B -e ' . escapeshellarg(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='" . $dbName . "' AND table_name='" . $name . "'"
        ));
        return trim((string) $counted) === '1';
    };
    foreach (['user_sessions', 'lesson_view_events'] as $table) {
        $assert($tablePresent($table), $table . ' is part of the single schema file.', $table . ' is missing; the schema was not re-imported.');
    }
    // A sign-in anomaly belongs to the account rather than to a recording, so the lesson reference
    // has to be nullable - and the cascade is what makes a lesson purge legal at all.
    $columnNullability = (string) shell_exec($mysql . ' ' . $credentials . ' -N -B -e ' . escapeshellarg(
        "SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema='" . $dbName . "' AND table_name='lesson_view_events' AND column_name='lesson_id'"
    ));
    $assert(trim($columnNullability) === 'YES', 'A view event may be filed without a lesson.', 'lesson_view_events.lesson_id is NOT NULL, so account-level reports cannot be stored.');
    $cascade = trim((string) shell_exec($mysql . ' ' . $credentials . ' -N -B -e ' . escapeshellarg(
        "SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='" . $dbName . "' AND TABLE_NAME='lms_lesson_view_events' AND CONSTRAINT_NAME='fk_view_event_lesson'"
    )));
    $assert($cascade === 'CASCADE', 'Removing a lesson takes its view events with it.', 'lesson_view_events.lesson_id does not cascade (got "' . $cascade . '"), so a lesson purge would fail on the foreign key.');
    foreach ([
        SessionRegistry::MAX_ACTIVE_KEY => '1',
        LessonProtection::WATERMARK_KEY => '1',
        LessonProtection::RETENTION_KEY => '30',
    ] as $key => $seeded) {
        $stored = $readSetting($key);
        $assert($stored !== null, 'app_settings seeds ' . $key . '.', $key . ' has no seeded row, so the feature runs on a fallback instead of a decision.');
        // The seed is an INSERT ... ON DUPLICATE KEY UPDATE that writes the key back to itself, so
        // re-importing the file must not undo whatever the admin set on the settings page.
        $assert($stored === $seeded, 'The default for ' . $key . ' is ' . $seeded . '.', $key . ' is seeded as ' . var_export($stored, true) . ', expected ' . $seeded . '.');
    }
    $assert($registry->available() && $protection->available(), 'Both services read the imported tables.', 'One of the two services still reports its table as missing.');
    $assert($registry->maxActive() === SessionRegistry::DEFAULT_MAX_ACTIVE, 'The registry reads the cap from the setting, at its default of 1.', 'maxActive() answered ' . $registry->maxActive() . '.');
    $assert($protection->retentionDays() === LessonProtection::DEFAULT_RETENTION_DAYS, 'The view log keeps reports for 30 days by default.', 'retentionDays() answered ' . $protection->retentionDays() . '.');

    /* ---------------------------------------------------------------- the mark itself */
    $emit("\n-- the watermark the player prints");
    // A session of its own, because the trace is keyed on the secret a session holds - and that is
    // the whole point: no new application secret, no new column, nothing to rotate by hand.
    session_id(md5('video-protection-smoke-marks'));
    session_start();
    $cliSessions['marks'] = md5('video-protection-smoke-marks');
    $_SESSION = [];
    $secret = Security::csrfToken();
    /** Both moving parts of the mark come out of one call, so the minute they were drawn in is read
     *  back off the stamp instead of guessed at a second clock that may have rolled over since. */
    $bucketOf = static fn(string $stamp): string => str_replace(['-', ' ', ':'], '', substr($stamp, 0, 16));
    $derive = static function (string $bucket, int $who, string $secret): string {
        return strtoupper(substr(hash_hmac('sha256', 'wm:' . $bucket . ':' . $who, $secret), 0, 6));
    };
    $pair = $protection->refresh($studentId);
    $trace = $pair['trace'];
    $assert(preg_match('/^[0-9A-F]{6}$/', $trace) === 1, 'The trace code is six upper-case hex characters.', 'The trace code was "' . $trace . '".');
    $assert($trace === $derive($bucketOf($pair['time']), $studentId, $secret),
        'The trace is the session CSRF secret keyed over the minute the mark printed, with no new secret to manage.',
        'The trace is not derived from the CSRF secret and the minute bucket as designed.');
    $assert($derive(gmdate('YmdHi', (int) strtotime($pair['time'] . ' UTC') + 60), $studentId, $secret) !== $trace,
        'The next minute gives a different code, which is what pins a leaked copy to a date.',
        'Two neighbouring minutes produce the same trace, so the code does not rotate.');
    $assert($protection->traceCode($resetStudentId) !== $trace, 'One minute of screen gives different codes for two students.',
        'Two students share a trace code, so a circulated copy would name the wrong person.');
    // The secret lives in the session, so a different session is a different guess - which is the
    // property that stops one student's code being reproducible by another.
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    $assert($protection->traceCode($studentId) !== $trace, 'A different session secret gives a different code, so one student cannot reproduce another\'s mark.',
        'The trace did not change with the session secret.');
    // An empty session still gets a mark rather than an exception: the overlay is drawn on the page
    // a student is watching, and a missing secret must never be what stops a lesson opening.
    unset($_SESSION['_csrf']);
    $assert($protection->traceCode($studentId) === '000000', 'A session with no secret falls back to a placeholder instead of failing the page.',
        'traceCode() did not fall back to the documented placeholder.');
    Security::csrfToken();

    $mark = $protection->watermark($studentId, 'Video Protection Smoke Student');
    $assert($mark['enabled'] === true, 'The mark is on unless an admin says otherwise.', 'watermark() reports the mark as off with the seed in place.');
    $assert($mark['label'] === 'Video Protection Smoke Student · ' . $studentReference,
        'The mark carries the name and the Academy ID the student is quoted back.',
        'The label was "' . $mark['label'] . '".');
    $assert(!str_contains($mark['label'], ' ' . $studentId), 'The mark prints no internal row id.', 'The label exposes the row id ' . $studentId . '.');
    $assert($mark['time'] === gmdate('Y-m-d H:i') . ' UTC', 'The printed time is UTC, labelled as such.', 'The time read "' . $mark['time'] . '".');
    $assert($mark['trace'] === $derive($bucketOf($mark['time']), $studentId, (string) $_SESSION['_csrf']),
        'The trace on the page is the one keyed on this session and this minute.', 'The page trace read "' . $mark['trace'] . '".');
    // The two parts that move are handed back together on the heartbeat, and nothing else: the mark
    // on screen has no reason to learn the student's address from this response.
    $refresh = $protection->refresh($studentId);
    $assert(array_keys($refresh) === ['trace', 'time']
        && $refresh['trace'] === $derive($bucketOf($refresh['time']), $studentId, (string) $_SESSION['_csrf']),
        'The heartbeat can hand back the two parts of the mark that move, and nothing else.',
        'refresh() returned ' . json_encode($refresh) . '.');

    // The browser may only report what the player can actually see. The two account-level events
    // describe the sign-in and are filed by the registry, never accepted over a POST.
    $assert(array_diff(LessonProtection::CLIENT_EVENTS, LessonProtection::EVENTS) === [],
        'Every browser-reportable event is one the log can hold.', 'CLIENT_EVENTS names something EVENTS does not.');
    $assert(!array_intersect(LessonProtection::CLIENT_EVENTS, ['session_cap', 'concurrent_ip']) && count(LessonProtection::CLIENT_EVENTS) === 5,
        'A student cannot write an accusation about their own sign-in into the evidence table.',
        'The browser may report ' . json_encode(LessonProtection::CLIENT_EVENTS) . '.');
    $assert(LessonProtection::label('seek_ahead') === 'Large jump forward' && LessonProtection::label('concurrent_ip') === 'Two addresses at once',
        'The desk prints an event as a sentence an admin can read.', 'label() answered ' . LessonProtection::label('seek_ahead') . '.');

    /* -------------------------------------------------------- the registry's arithmetic */
    $emit("\n-- one account, the devices that hold it");
    /** Replays one browser session: PHP's own session, its own id, its own address. */
    $device = static function (string $label, callable $body, ?string $ip = null) use (&$cliSessions): mixed {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        $id = md5('video-protection-smoke-' . $label);
        session_id($id);
        session_start();
        $cliSessions[$label] = $id;
        $_SESSION = [];
        Security::csrfToken();
        if ($ip !== null) $_SERVER['REMOTE_ADDR'] = $ip;
        try {
            return $body();
        } finally {
            session_write_close();
            if ($ip !== null) $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        }
    };
    // One PHP process answers one request in the real app, so a verdict is only ever read once per
    // session id. This harness replays several requests, so the memo has to fall away the way a
    // fresh request would bring a fresh one.
    $forgetVerdicts = static function (): void {
        $property = new ReflectionProperty(SessionRegistry::class, 'verdicts');
        $property->setAccessible(true);
        $property->setValue(null, []);
    };

    $resetSessions($studentId);
    $phoneIp = '203.0.113.21';
    $laptopIp = '203.0.113.22';
    $firstHash = '';
    $device('phone', static function () use ($registry, $studentId, &$firstHash) {
        $registry->register($studentId);
        $firstHash = $registry->currentHash();
    }, $phoneIp);
    $displaced = $device('laptop', static function () use ($registry, $studentId) {
        return $registry->register($studentId);
    }, $laptopIp);
    $forgetVerdicts();
    $rows = $activeRows($studentId);
    $assert($displaced['evicted'] === 1 && $displaced['concurrent_ip'] === true,
        'With the cap at 1, the new sign-in displaces one older session and notices a second address.',
        'register() reported ' . json_encode($displaced) . '.');
    $assert(count($rows) === 2, 'Two devices signed in, so the register holds two rows - one of them closed.',
        'The register holds ' . count($rows) . ' row(s) after two sign-ins: ' . json_encode($rows) . '.');
    $phoneRow = $rows[0]['session_hash'] === $firstHash ? $rows[0] : $rows[1];
    $laptopRow = $phoneRow === $rows[0] ? $rows[1] : $rows[0];
    $assert($phoneRow['revoked_at'] !== null && $phoneRow['revoked_reason'] === 'superseded',
        'The displaced session is closed as superseded, not deleted, so the desk can still say when.',
        'The phone row read ' . json_encode($phoneRow) . '.');
    $assert($laptopRow['revoked_at'] === null, 'The sign-in that won stays open.', 'The winning row was closed as ' . var_export($laptopRow['revoked_reason'], true) . '.');
    $assert($phoneRow['ip_address'] === $phoneIp && $laptopRow['ip_address'] === $laptopIp,
        'Each session records the address it came from.', 'Addresses: ' . json_encode(array_column($rows, 'ip_address')));
    $verdictPhone = $device('phone', static fn() => $registry->check($studentId), $phoneIp);
    $verdictLaptop = $device('laptop', static fn() => $registry->check($studentId), $laptopIp);
    $assert($verdictPhone === 'revoked' && $verdictLaptop === 'ok',
        'The bounced device is refused and the current one passes.', 'Verdicts were ' . $verdictPhone . ' / ' . $verdictLaptop . '.');
    $accountEvents = $db->query('SELECT event_type,detail FROM lms_lesson_view_events WHERE student_id = ' . (int) $studentId)->fetchAll(PDO::FETCH_ASSOC);
    $assert(in_array('session_cap', array_column($accountEvents, 'event_type'), true) && in_array('concurrent_ip', array_column($accountEvents, 'event_type'), true),
        'Both halves of the sign-in are filed for the admin: the eviction and the second address.',
        'The view log holds ' . json_encode(array_column($accountEvents, 'event_type')));
    $lessonless = (int) $db->query('SELECT COUNT(*) FROM lms_lesson_view_events WHERE student_id = ' . (int) $studentId . ' AND lesson_id IS NULL')->fetchColumn();
    $assert($lessonless === 2, 'Account-level reports are stored without a lesson, so the desk can print them honestly.',
        $lessonless . ' of 2 sign-in events carry no lesson reference.');

    $resetSessions($studentId);
    $putSetting->execute([SessionRegistry::MAX_ACTIVE_KEY, '3']);
    $registry = new SessionRegistry($db);
    $capHashes = [];
    foreach (['desk-one', 'desk-two', 'desk-three', 'desk-four'] as $index => $label) {
        $capHashes[$label] = $device($label, static function () use ($registry, $studentId) {
            $registry->register($studentId);
            return $registry->currentHash();
        }, '203.0.113.' . (30 + $index));
    }
    $forgetVerdicts();
    $open3 = count($registry->activeFor($studentId));
    $assert($open3 === 3, 'A cap of 3 lets a phone, a laptop and a tablet hold the account at once.', 'Three sign-ins left ' . $open3 . ' active session(s).');
    $reasons = array_map(static fn(array $r): string => (string) $r['revoked_reason'], array_values(array_filter($activeRows($studentId), static fn(array $r): bool => $r['revoked_at'] !== null)));
    $assert($reasons === ['superseded'], 'The fourth sign-in takes exactly the oldest session out.', 'The fourth sign-in closed: ' . json_encode($reasons));
    $assert($device('desk-four', static fn() => $registry->check($studentId), '203.0.113.33') === 'ok'
        && $device('desk-one', static fn() => (new SessionRegistry($db))->check($studentId), '203.0.113.30') === 'revoked',
        'The newest session is accepted and the one it replaced is refused.', 'Cap-3 verdicts were wrong.');
    $putSetting->execute([SessionRegistry::MAX_ACTIVE_KEY, '1']);

    // A session opened before this feature existed must not be refused on its first click after a
    // deploy: adoption is the difference between an upgrade and a lock-out.
    $resetSessions($studentId);
    $registry = new SessionRegistry($db);
    $adopted = $device('legacy', static fn() => $registry->check($studentId), $phoneIp);
    $forgetVerdicts();
    $assert($adopted === 'ok', 'A session with no row is adopted rather than refused.', 'check() answered ' . $adopted . ' for an unknown session.');
    $assert(count($activeRows($studentId)) === 1, 'Adoption writes the row, so the device appears on the admin desk.',
        'The adopted session left ' . count($activeRows($studentId)) . ' row(s).');

    // Idle rows are closed by the next sign-in rather than by a scheduler this environment has not
    // got. The order matters: idle is written before the cap is applied, so the reason an admin
    // reads is the truth about why that session stopped.
    $resetSessions($studentId);
    $device('stale', static fn() => (new SessionRegistry($db))->register($studentId), $phoneIp);
    $db->prepare('UPDATE lms_user_sessions SET last_seen_at = UTC_TIMESTAMP() - INTERVAL ? SECOND WHERE user_id = ?')
        ->execute([(SessionRegistry::IDLE_MINUTES + 60) * 60, $studentId]);
    $device('fresh', static fn() => (new SessionRegistry($db))->register($studentId), $phoneIp);
    $forgetVerdicts();
    $idleReasons = array_values(array_filter(array_column($activeRows($studentId), 'revoked_reason')));
    $assert($idleReasons === ['idle'], 'A session untouched for longer than the idle window closes as idle, not as superseded.',
        'The stale row was closed as ' . json_encode($idleReasons) . '.');

    // Two devices are open here on purpose, so "a logout closes one session" can be told apart from
    // "a logout closes the account".
    $resetSessions($studentId);
    $putSetting->execute([SessionRegistry::MAX_ACTIVE_KEY, '2']);
    $logoutRegistry = new SessionRegistry($db);
    $device('two-a', static fn() => $logoutRegistry->register($studentId), $phoneIp);
    $twoBHash = $device('two-b', static function () use ($logoutRegistry, $studentId) {
        $logoutRegistry->register($studentId);
        $hash = $logoutRegistry->currentHash();
        $logoutRegistry->revokeCurrent($studentId, 'logout');
        return $hash;
    }, $phoneIp);
    $putSetting->execute([SessionRegistry::MAX_ACTIVE_KEY, '1']);
    $forgetVerdicts();
    $logoutRows = $activeRows($studentId);
    $closedByLogout = array_values(array_filter($logoutRows, static fn(array $r): bool => (string) $r['revoked_reason'] === 'logout'));
    $assert(count($closedByLogout) === 1 && $closedByLogout[0]['session_hash'] === $twoBHash,
        'A logout closes the session that logged out and leaves the account alone elsewhere.',
        'Logout closed ' . json_encode(array_column($closedByLogout, 'session_hash')) . ' of ' . count($logoutRows) . ' row(s).');
    $leftOpen = array_values(array_filter($logoutRows, static fn(array $r): bool => $r['revoked_at'] === null));
    $assert(count($leftOpen) === 1, 'The other device keeps its session when one of them logs out.',
        count($leftOpen) . ' of ' . count($logoutRows) . ' row(s) survived the logout.');

    /* ----------------------------------------------------------- what a password change does */
    $emit("\n-- signing the other devices out");
    $resetSessions($studentId);
    // The cap goes up for this scenario on purpose: with one device already open and a cap of 1, the
    // second sign-in would have closed the first as superseded and the change would have had nothing
    // left to revoke. Two live sessions is the situation the rule is written for.
    $putSetting->execute([SessionRegistry::MAX_ACTIVE_KEY, '3']);
    $device('other', static fn() => (new SessionRegistry($db))->register($studentId), $laptopIp);
    $forgetVerdicts();
    $keptHash = $device('kept', static function () use ($db, $studentId, $studentEmail, $studentPassword, $auth): string {
        $registry = new SessionRegistry($db);
        $registry->register($studentId);
        $hash = $registry->currentHash();
        $auth->changePassword(['id' => $studentId, 'email' => $studentEmail], $studentPassword, 'Video Protection Smoke 2!');
        return $hash;
    }, $phoneIp);
    $registry = new SessionRegistry($db);
    $putSetting->execute([SessionRegistry::MAX_ACTIVE_KEY, '1']);
    $forgetVerdicts();
    $afterChange = $activeRows($studentId);
    $stillOpen = array_values(array_filter($afterChange, static fn(array $r): bool => $r['revoked_at'] === null));
    $assert(count($stillOpen) === 1 && $stillOpen[0]['session_hash'] === $keptHash,
        'Changing a password keeps the device that just proved it knew the old one.',
        'After the change, the open rows were ' . json_encode(array_column($stillOpen, 'session_hash')) . ' against ' . $keptHash . '.');
    $changedReasons = array_values(array_filter(array_map(static fn(array $r): string => (string) $r['revoked_reason'], $afterChange), static fn(string $r): bool => $r === 'password_change'));
    $assert(count($changedReasons) === 1, 'The other device is closed as a password change.', 'Reasons on file: ' . json_encode(array_column($afterChange, 'revoked_reason')));
    $assert((int) $db->query("SELECT COUNT(*) FROM lms_audit_logs WHERE action='profile.password_changed' AND actor_user_id=" . (int) $studentId)->fetchColumn() === 1,
        'The change is audited as it was before this feature existed.', 'The password change left no audit row.');
    $studentPassword = 'Video Protection Smoke 2!';

    // A reset proves the mailbox, not the device standing in front of it, so nothing that was signed
    // in before the reset is trusted afterwards - including the session the reset was done on.
    $resetSessions($resetStudentId);
    $putSetting->execute([SessionRegistry::MAX_ACTIVE_KEY, '3']);
    $device('reset-phone', static fn() => (new SessionRegistry($db))->register($resetStudentId), $laptopIp);
    $db->prepare("INSERT INTO lms_email_otps(user_id,purpose,target_email,otp_hash,expires_at) VALUES(?,?,?, ?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 600 SECOND))")
        ->execute([$resetStudentId, 'reset_password', $resetEmail, password_hash('246810', PASSWORD_BCRYPT)]);
    $device('reset-web', static function () use ($db, $auth, $resetStudentId, $resetEmail, $phoneIp): void {
        $registry = new SessionRegistry($db);
        $registry->register($resetStudentId);
        $auth->resetPassword($resetEmail, '246810', 'Video Protection Reset 3!');
    }, $phoneIp);
    $putSetting->execute([SessionRegistry::MAX_ACTIVE_KEY, '1']);
    $forgetVerdicts();
    $resetRows = $activeRows($resetStudentId);
    $assert($resetRows !== [] && array_filter($resetRows, static fn(array $r): bool => $r['revoked_at'] === null) === [],
        'A password reset closes every session for the account, the one it was done on included.',
        'Rows after the reset: ' . json_encode(array_map(static fn(array $r): string => (string) $r['revoked_reason'], $resetRows)));
    $loginProbe = $auth->login($resetEmail, 'Video Protection Reset 3!');
    $assert((int) $loginProbe['id'] === $resetStudentId, 'The reset password is the one that works afterwards.', 'The new password did not sign the account in.');

    /* ------------------------------------------------------------------ the fail-open half */
    $emit("\n-- a database that has not been re-imported");
    // A schema derived from an already-guarded scratch name, so it can never point at a real
    // database. Everything this half needs is the absence of the two tables.
    shell_exec($mysql . ' ' . $credentials . ' -e ' . escapeshellarg('CREATE DATABASE IF NOT EXISTS `' . $emptyName . '`'));
    $barePdo = new PDO('mysql:host=' . $dbHost . ';port=' . $dbPort . ';dbname=' . $emptyName . ';charset=utf8mb4', $dbUser, $dbPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $bareRegistry = new SessionRegistry($barePdo);
    $bareProtection = new LessonProtection($barePdo);
    $assert($bareRegistry->available() === false && $bareProtection->available() === false,
        'Both services notice the missing tables instead of assuming them.', 'A service claimed a table it cannot read.');
    $device('bare', static function () use ($bareRegistry, $bareProtection, $assert, $emit, $studentId, $lessonId): void {
        $verdict = $bareRegistry->check($studentId);
        $emit('  raw verdict ' . $verdict);
        $assert($verdict === 'unknown', 'With nothing to check, requireRole() is told to leave the request alone.', 'check() answered ' . $verdict . ' against a bare schema.');
        $assert($bareRegistry->register($studentId) === ['evicted' => 0, 'concurrent_ip' => false], 'A sign-in on a bare schema does nothing rather than failing.', 'register() reported something on a bare schema.');
        $assert($bareRegistry->revokeAll($studentId, 'admin_revoked') === ['closed' => 0, 'kept' => false], 'A revoke on a bare schema closes nothing and throws nothing.', 'revokeAll() misbehaved on a bare schema.');
        $assert($bareRegistry->activeFor($studentId) === [] && $bareRegistry->maxActive() === 1, 'The admin desk reads an empty session list and the default cap.', 'Bare registry read wrong.');
        $assert($bareProtection->watermarkEnabled() === true, 'The watermark stays on when its setting cannot be read: the mark protects the recordings.', 'A missing setting switched the mark off.');
        $assert($bareProtection->log($studentId, $lessonId, 'context_menu', 'x') === false && $bareProtection->count() === 0 && $bareProtection->recent() === [],
            'A report against a bare schema is dropped rather than raised as an error.', 'The view log did not fail open.');
        $assert($bareProtection->clearAll() === 0 && $bareProtection->sweepExpired() === 0, 'The desk actions are no-ops without their table.', 'A bare-schema admin action raised or deleted something.');
    });
    $bareMark = $bareProtection->watermark($studentId, 'Video Protection Smoke Student');
    $assert($bareMark['enabled'] === true && $bareMark['reference'] === $studentReference,
        'The lesson page can still draw the mark when only the log is missing.', 'watermark() gave up with the log absent.');
    $barePdo = null;
    shell_exec($mysql . ' ' . $credentials . ' -e ' . escapeshellarg('DROP DATABASE IF EXISTS `' . $emptyName . '`'));
    $emit('  ok the temporary empty schema ' . $emptyName . ' is dropped again');

    $logProbe = $protection->log($studentId, $lessonId, 'not_an_event');
    $assert($logProbe === false, 'An event the log does not know is refused rather than written.', 'log() accepted an unknown event name.');
    $long = str_repeat('a', 300);
    $protection->log($studentId, $lessonId, 'download_blocked', "  squeezed   text  ");
    $storedDetail = (string) $db->query('SELECT detail FROM lms_lesson_view_events WHERE student_id = ' . (int) $studentId . ' AND event_type="download_blocked" ORDER BY id DESC LIMIT 1')->fetchColumn();
    $assert($storedDetail === 'squeezed text', 'A report is stored on one line, with its run of spaces collapsed.', 'The detail read "' . $storedDetail . '".');
    $protection->log($studentId, $lessonId, 'download_blocked', $long);
    $clip = (string) $db->query('SELECT detail FROM lms_lesson_view_events WHERE student_id = ' . (int) $studentId . ' AND event_type="download_blocked" ORDER BY id DESC LIMIT 1')->fetchColumn();
    $assert(mb_strlen($clip) === 255, 'An oversized report is cut to what the column holds instead of failing the row.', 'The detail was ' . mb_strlen($clip) . ' characters.');
    $resetSessions($studentId);
} catch (Throwable $exception) {
    $failures[] = 'The in-process half stopped: ' . $exception->getMessage();
    $report .= '  x ' . $exception->getMessage() . "\n";
}

try {
    if ($failures !== []) {
        throw new RuntimeException('the registry checks did not finish cleanly');
    }

    /* --------------------------------------------------------------------- over HTTP */
    $emit("\n-- a real sign-in, over real cookies");
    $jarA = $jarOf('deviceA');
    $jarB = $jarOf('deviceB');
    $jarC = $jarOf('deviceC');
    $jarD = $jarOf('deviceD');
    $jarAdmin = $jarOf('admin');
    $jarStudent2 = $jarOf('student2');

    $login = $signIn($jarA, $studentEmail, $studentPassword);
    $assert($login['signed'], 'Device A signs in and lands on the dashboard.', 'Device A was not sent to /dashboard (status ' . $login['status'] . ').');
    $sessionA = $sessionIdOf($jarA);
    $rows = $activeRows($studentId);
    $assert(count($rows) === 1 && $rows[0]['revoked_at'] === null, 'The sign-in left exactly one open session.', 'Rows after sign-in: ' . json_encode($rows));
    $assert($rows[0]['session_hash'] === hash('sha256', $sessionA) && $rows[0]['session_hash'] !== $sessionA && strlen($rows[0]['session_hash']) === 64,
        'The registry stores a hash of the session id, so a leaked table hands over nothing usable.',
        'The stored value did not match the cookie the browser is holding.');
    $assert($rows[0]['ip_address'] === '127.0.0.1', 'The session row records the address it came from.', 'ip_address read ' . var_export($rows[0]['ip_address'], true) . '.');
    $dashboard = $open('GET', '/dashboard', [], $jarA);
    $assert($dashboard['status'] === 200, 'The open session gets its dashboard back.', 'GET /dashboard answered ' . $dashboard['status'] . '.');

    $emit("\n-- the player page carries the mark");
    $player = $open('GET', '/learning/lesson?lesson_id=' . $lessonId, [], $jarA);
    $assert($player['status'] === 200, 'The lesson page renders for the student holding the session.', 'The lesson page answered ' . $player['status'] . '.');
    $assert(str_contains($player['body'], 'class="lesson-video-shell" data-video-shell'),
        'The recording is wrapped in the shell that goes fullscreen, so the mark goes with it.',
        'No positioned shell around the <video> element.');
    $assert(str_contains($player['body'], 'lesson-watermark-strong') && str_contains($player['body'], 'lesson-watermark-faint'),
        'Two copies of the mark are drawn, so a crop that removes one still names somebody.',
        'The overlay is not printed on the player page.');
    $assert(str_contains($player['body'], Security::escape('Video Protection Smoke Student · ' . $studentReference)),
        'The mark prints the name and Academy ID the student is quoted back.',
        'The mark on the page does not read "Video Protection Smoke Student · ' . $studentReference . '".');
    $assert(!str_contains($player['body'], 'data-student-label="' . Security::escape('Video Protection Smoke Student · ' . $studentId) . '"')
        && str_contains($player['body'], 'data-student-label="' . Security::escape('Video Protection Smoke Student · ' . $studentReference) . '"'),
        'The page identifies the student by Academy ID, not by the row id it happens to sit on.',
        'The player carries no student label, or it prints the internal row id.');
    $assert(str_contains($player['body'], 'controlsList="nodownload"') && str_contains($player['body'], 'disablepictureinpicture'),
        'The browser is asked for no download button and no picture-in-picture.',
        'The <video> element lost its download or picture-in-picture attributes.');
    $assert(!str_contains($player['body'], 'noplaybackrate'),
        'Speed control is left alone, because the company documentation asks for 0.5x to 2x.',
        'The player blocks the playback rate it is required to offer.');
    $assert(str_contains($player['body'], 'data-view-endpoint="' . $appUrl . '/learning/lesson/view"') || str_contains($player['body'], '/learning/lesson/view'),
        'The player is handed the address it reports to.', 'No view endpoint on the player.');
    $assert(str_contains($player['body'], 'data-watermark="1"') && preg_match('/data-trace="([0-9A-F]{6})"/', $player['body'], $traceMatch) === 1,
        'The rotating code the script keeps refreshing is on the page from the first render.', 'The player carries no trace code.');
    // The attribute the script refreshes and the copy the student reads are drawn by the same call,
    // so a mark that disagrees with itself would be evidence against nobody.
    $assert(str_contains($player['body'], '<span data-wm-trace>' . ($traceMatch[1] ?? '') . '</span>')
        && str_contains($player['body'], 'data-student-label="' . Security::escape($mark['label']) . '"'),
        'The code on screen and the code the player was handed are the same one.', 'The overlay reads ' . var_export($traceMatch[1] ?? null, true) . ' and the attribute something else.');

    $embed = $open('GET', '/learning/lesson?lesson_id=' . $embedLessonId, [], $jarA);
    // Measured on the iframe tag itself: the card also carries a sentence for the student that
    // explains in plain words that picture-in-picture has been switched off, and that is copy, not
    // a permission the browser has been granted.
    $iframeTag = preg_match('/<iframe\b[^>]*>/', $embed['body'], $iframeMatch) === 1 ? $iframeMatch[0] : '';
    $escapeRoute = str_contains($iframeTag, 'allowfullscreen') ? 'allowfullscreen'
        : (str_contains($iframeTag, 'picture-in-picture') ? 'picture-in-picture'
            : (str_contains($iframeTag, 'encrypted-media') ? 'encrypted-media' : ''));
    $assert($embed['status'] === 200 && str_contains($embed['body'], 'lesson-video-shell')
        && $iframeTag !== ''
        && str_contains($embed['body'], 'allow="accelerometer; autoplay; clipboard-write"')
        && $escapeRoute === '',
        'The provider player loses its own fullscreen and picture-in-picture, so the mark is never left behind.',
        $iframeTag === '' ? 'The embed page renders no iframe at all.' : 'The iframe still carries an escape route: ' . $escapeRoute . '.');

    $emit("\n-- the watch-time maths is untouched");
    $csrf = $csrfAttr($player['body']);
    $beat = json_decode((string) $open('POST', '/learning/lesson/watch', ['_csrf' => $csrf, 'lesson_id' => (string) $lessonId, 'position' => '20'], $jarA)['body'], true);
    $assert((int) ($beat['watched'] ?? -1) === 20, 'The first heartbeat credits its forward move exactly as before this change.', 'The heartbeat answered ' . json_encode($beat) . '.');
    $assert(is_array($beat['watermark'] ?? null) && preg_match('/^[0-9A-F]{6}$/', (string) ($beat['watermark']['trace'] ?? '')) === 1,
        'The rotating code rides the heartbeat it was already making, so the mark cannot go stale.',
        'The heartbeat carried no fresh trace: ' . json_encode($beat['watermark'] ?? null) . '.');
    $db->prepare('UPDATE lms_lesson_progress SET updated_at = UTC_TIMESTAMP() - INTERVAL 30 SECOND WHERE student_id = ? AND lesson_id = ?')->execute([$studentId, $lessonId]);
    $beat2 = json_decode((string) $open('POST', '/learning/lesson/watch', ['_csrf' => $csrf, 'lesson_id' => (string) $lessonId, 'position' => '45'], $jarA)['body'], true);
    $assert((int) ($beat2['watched'] ?? -1) === 40,
        'A 25 second scrub still credits only the 20 second cap, protection layer or no protection layer.',
        'The second heartbeat credited ' . json_encode($beat2['watched'] ?? null) . ', expected 40 in total.');
    $assert((int) $db->query('SELECT COUNT(*) FROM lms_lesson_view_events WHERE student_id = ' . (int) $studentId)->fetchColumn() === 0,
        'Ordinary heartbeats file no protection report; the log is for resistance, not for watching.',
        'Watch pings wrote into the view log.');

    $emit("\n-- what the player is allowed to report");
    $before = (int) $db->query('SELECT COUNT(*) FROM lms_lesson_view_events WHERE student_id = ' . (int) $studentId)->fetchColumn();
    $reportReply = $open('POST', '/learning/lesson/view', ['_csrf' => $csrf, 'lesson_id' => (string) $lessonId, 'event' => 'download_blocked', 'detail' => 'Save video as'], $jarA);
    $assert($reportReply['status'] === 200 && str_contains($reportReply['body'], '{"ok":true}'), 'A blocked download is accepted.', 'The view endpoint answered ' . $reportReply['body'] . '.');
    $logged = $db->prepare('SELECT event_type,lesson_id,ip_address,detail FROM lms_lesson_view_events WHERE student_id = ? ORDER BY id DESC LIMIT 1');
    $logged->execute([$studentId]);
    $logRow = $logged->fetch();
    $assert(is_array($logRow) && $logRow['event_type'] === 'download_blocked' && (int) $logRow['lesson_id'] === $lessonId && $logRow['ip_address'] === '127.0.0.1',
        'The report names the student, the recording and the address it came from.', 'The row read ' . json_encode($logRow) . '.');
    $assert(str_contains($logRow['detail'] ?? '', 'Save video as'), 'What the student tried is kept with the event.', 'The detail was lost: ' . json_encode($logRow));

    $forged = $open('POST', '/learning/lesson/view', ['_csrf' => 'not-a-token', 'lesson_id' => (string) $lessonId, 'event' => 'download_blocked'], $jarA);
    $assert(str_contains($forged['body'], '"error":"session"'), 'A report without the session token is refused.', 'A forged token was answered with ' . $forged['body'] . '.');
    $accuse = $open('POST', '/learning/lesson/view', ['_csrf' => $csrf, 'lesson_id' => (string) $lessonId, 'event' => 'session_cap'], $jarA);
    $assert(str_contains($accuse['body'], '"error":"event"'), 'A browser cannot file a sign-in accusation about its own account.', 'The endpoint accepted ' . $accuse['body'] . '.');
    $foreign = $open('POST', '/learning/lesson/view', ['_csrf' => $csrf, 'lesson_id' => (string) $foreignLessonId, 'event' => 'context_menu'], $jarA);
    $assert($foreign['status'] === 404 && str_contains($foreign['body'], '"error":"unavailable"'),
        'A report cannot be filed against a lesson the student has no access to.', 'A foreign lesson answered ' . $foreign['status'] . ' ' . $foreign['body'] . '.');
    $metered = (int) $db->query('SELECT COUNT(*) FROM lms_auth_rate_limits WHERE action = "lesson_view_event"')->fetchColumn();
    $assert($metered === 1, 'The reports are metered per account, so the log cannot be flooded.', 'No rate-limit row for the view endpoint.');
    $assert((int) $db->query('SELECT COUNT(*) FROM lms_lesson_view_events WHERE student_id = ' . (int) $studentId)->fetchColumn() === $before + 1,
        'Everything that was refused wrote nothing.', 'Refused reports still reached the log.');

    $emit("\n-- the displaced device bounces where a student can read why");
    $second = $signIn($jarB, $studentEmail, $studentPassword);
    $assert($second['signed'], 'Device B signs in with the same account.', 'Device B could not sign in (status ' . $second['status'] . ').');
    $bounce = $open('GET', '/dashboard', [], $jarA);
    $assert($bounce['status'] === 302 && (string) $bounce['location'] === $appUrl . '/login',
        'Device A is sent to the sign-in page by its own next click, not by a timer.', 'Device A got ' . $bounce['status'] . ' with Location ' . json_encode($bounce['location']) . '.');
    $explained = $open('GET', '/login', [], $jarA);
    $assert($explained['status'] === 200 && str_contains($explained['body'], 'signed in somewhere else'),
        'The page it lands on explains what happened in one sentence.', 'The bounced device arrived at a silent form.');
    $assert(!str_contains($open('GET', '/login', [], $jarA)['body'], 'signed in somewhere else'),
        'The explanation is shown once and does not follow the student around.', 'The bounce message survived being read.');
    $again = $open('GET', '/dashboard', [], $jarA);
    $assert($again['status'] === 302, 'The bounced device cannot reach its dashboard again without signing in.', 'A revoked session was let through.');
    $rows = $activeRows($studentId);
    $openB = array_values(array_filter($rows, static fn(array $r): bool => $r['revoked_at'] === null));
    $assert(count($openB) === 1 && $openB[0]['session_hash'] === hash('sha256', $sessionIdOf($jarB)),
        'One account, one open session - the company rule, enforced across two browsers. The new session the bounce created is not adopted into the register.',
        'Open rows: ' . json_encode(array_column($openB, 'session_hash')) . ' vs ' . hash('sha256', $sessionIdOf($jarB)) . '.');
    $assert((int) $db->query('SELECT COUNT(*) FROM lms_lesson_view_events WHERE student_id = ' . (int) $studentId . ' AND event_type="session_cap"')->fetchColumn() >= 1,
        'The eviction is on the admin desk as well as in the registry.', 'The sign-in displaced a session with no record of it.');

    $emit("\n-- the admin sets the rules on their own page");
    $loginAdmin = $signIn($jarAdmin, $adminEmail, $adminPassword);
    $assert($loginAdmin['signed'], 'The admin signs in.', 'The admin could not sign in (status ' . $loginAdmin['status'] . ').');
    $adminAsStudent = $open('POST', '/learning/lesson/view', ['_csrf' => $csrfOf($open('GET', '/admin/settings', [], $jarAdmin)['body']), 'lesson_id' => (string) $lessonId, 'event' => 'context_menu'], $jarAdmin);
    $assert($adminAsStudent['status'] === 302 && (string) $adminAsStudent['location'] === $appUrl . '/login',
        'An administrator is not a student, and a signed-in admin cookie is bounced from the student endpoint.',
        'The student endpoint accepted an admin cookie (' . $adminAsStudent['status'] . ').');
    // The admin session was destroyed by that bounce, so the rest of the desk work signs in fresh.
    $loginAdmin = $signIn($jarAdmin, $adminEmail, $adminPassword);
    $assert($loginAdmin['signed'], 'The administrator signs in again after the role bounce.', 'The admin could not sign back in (status ' . $loginAdmin['status'] . ').');
    $settingsPage = $open('GET', '/admin/settings', [], $jarAdmin);
    $assert($settingsPage['status'] === 200 && str_contains($settingsPage['body'], 'Sessions &amp; video protection'),
        'The settings page carries its own protection card.', 'No "Sessions & video protection" card on /admin/settings.');
    foreach ([SessionRegistry::MAX_ACTIVE_KEY, LessonProtection::WATERMARK_KEY, LessonProtection::RETENTION_KEY] as $key) {
        $assert(str_contains($settingsPage['body'], 'name="' . $key . '"'), $key . ' is a named field the form posts.', $key . ' has no input on the settings page.');
    }
    $assert(str_contains($settingsPage['body'], '/admin/settings/video-protection'),
        'The card saves to its own endpoint, in the page\'s one-key-per-handler convention.', 'The protection card posts to the shared settings endpoint.');
    $securityBefore = $readSetting('login_max_attempts');
    $adminCsrf = $csrfOf($settingsPage['body']);
    $save = $open('POST', '/admin/settings/video-protection', [
        '_csrf' => $adminCsrf,
        SessionRegistry::MAX_ACTIVE_KEY => '3',
        LessonProtection::WATERMARK_KEY => '1',
        LessonProtection::RETENTION_KEY => '45',
    ], $jarAdmin);
    $assert($save['status'] === 302 && (string) $save['location'] === $appUrl . '/admin/settings', 'Saving the card goes back to the page it belongs to.', 'The save answered ' . $save['status'] . '.');
    $assert($readSetting(SessionRegistry::MAX_ACTIVE_KEY) === '3' && $readSetting(LessonProtection::RETENTION_KEY) === '45',
        'The cap and the retention window are stored as the admin typed them.', 'Stored: ' . json_encode([$readSetting(SessionRegistry::MAX_ACTIVE_KEY), $readSetting(LessonProtection::RETENTION_KEY)]));
    $assert($readSetting('login_max_attempts') === $securityBefore,
        'This endpoint saves its own three keys and reaches for nothing else.', 'The protection save changed the security settings.');
    $assert((int) $db->query("SELECT COUNT(*) FROM lms_audit_logs WHERE action='settings.video_protection_updated'")->fetchColumn() === 1,
        'The change is audited with the keys it touched.', 'No audit row for the protection save.');

    $tooMany = $open('POST', '/admin/settings/video-protection', [
        '_csrf' => $csrfOf($open('GET', '/admin/settings', [], $jarAdmin)['body']),
        SessionRegistry::MAX_ACTIVE_KEY => '11',
        LessonProtection::WATERMARK_KEY => '1',
        LessonProtection::RETENTION_KEY => '45',
    ], $jarAdmin);
    $assert($tooMany['status'] === 302 && $readSetting(SessionRegistry::MAX_ACTIVE_KEY) === '3',
        'A cap outside 1 to 10 is refused and nothing is written.', 'The save took a cap of 11 (stored: ' . var_export($readSetting(SessionRegistry::MAX_ACTIVE_KEY), true) . ').');
    $assert(str_contains($open('GET', '/admin/settings', [], $jarAdmin)['body'], 'must be between 1 and 10 sessions'),
        'The admin is told which number was wrong, in the words the card uses.', 'The rejected save left no explanation on the page.');
    $forgedAdmin = $open('POST', '/admin/settings/video-protection', [
        '_csrf' => 'not-a-token',
        SessionRegistry::MAX_ACTIVE_KEY => '10',
        LessonProtection::WATERMARK_KEY => '0',
        LessonProtection::RETENTION_KEY => '1',
    ], $jarAdmin);
    $assert($forgedAdmin['status'] === 419 && $readSetting(SessionRegistry::MAX_ACTIVE_KEY) === '3',
        'A forged token costs the save, not the settings.', 'A forged save was answered with ' . $forgedAdmin['status'] . '.');

    // The cap is a setting the app reads per sign-in, so raising it has to be visible to the browser.
    $third = $signIn($jarC, $studentEmail, $studentPassword);
    $fourth = $signIn($jarD, $studentEmail, $studentPassword);
    $assert($third['signed'] && $fourth['signed'], 'Two more devices sign in as the same student.', 'A later sign-in was refused.');
    $held = array_values(array_filter($activeRows($studentId), static fn(array $r): bool => $r['revoked_at'] === null));
    $assert(count($held) === 3, 'With the cap raised to 3 the account holds three devices at once.', 'The cap of 3 left ' . count($held) . ' open session(s).');
    $assert($open('GET', '/dashboard', [], $jarC)['status'] === 200, 'A student who is one of the three keeps their dashboard.', 'Device C was bounced under a cap of 3.');

    $emit("\n-- the mark follows the switch, and the desk reads the log");
    $open('POST', '/admin/settings/video-protection', [
        '_csrf' => $csrfOf($open('GET', '/admin/settings', [], $jarAdmin)['body']),
        SessionRegistry::MAX_ACTIVE_KEY => '3',
        LessonProtection::WATERMARK_KEY => '0',
        LessonProtection::RETENTION_KEY => '45',
    ], $jarAdmin);
    $off = $open('GET', '/learning/lesson?lesson_id=' . $lessonId, [], $jarC);
    $assert(str_contains($off['body'], 'data-watermark="0"') && !str_contains($off['body'], 'lesson-watermark'),
        'Switching the mark off takes the overlay out of the page rather than hiding it.', 'The overlay survived being switched off.');
    $beatOff = json_decode((string) $open('POST', '/learning/lesson/watch', ['_csrf' => $csrfAttr($off['body']), 'lesson_id' => (string) $lessonId, 'position' => '60'], $jarC)['body'], true);
    $assert(!isset($beatOff['watermark']), 'A heartbeat on an off mark does not carry one anyway.', 'The heartbeat still sent a trace.');
    $open('POST', '/admin/settings/video-protection', [
        '_csrf' => $csrfOf($open('GET', '/admin/settings', [], $jarAdmin)['body']),
        SessionRegistry::MAX_ACTIVE_KEY => '3',
        LessonProtection::WATERMARK_KEY => '1',
        LessonProtection::RETENTION_KEY => '45',
    ], $jarAdmin);
    $assert(str_contains($open('GET', '/learning/lesson?lesson_id=' . $lessonId, [], $jarC)['body'], 'lesson-watermark-strong'),
        'Switching it back on puts the mark where the student can see it again.', 'The mark did not come back.');

    $activity = $open('GET', '/admin/activity', [], $jarAdmin);
    $assert($activity['status'] === 200 && str_contains($activity['body'], 'Lesson view log'), 'The recent activity report carries its own view log card.', 'No "Lesson view log" card on /admin/activity.');
    $assert(str_contains($activity['body'], '<code>' . Security::escape($studentReference) . '</code>'),
        'Each row identifies the student by Academy ID, the number an admin can act on.', 'The view log did not print ' . $studentReference . '.');
    $assert(str_contains($activity['body'], 'Account session') && str_contains($activity['body'], 'Signed in on another device'),
        'A sign-in report is printed as what it is: something that happened at the door, not in a recording.',
        'The account-level rows are missing or mislabelled on the desk.');
    $assert(str_contains($activity['body'], '/admin/activity/view-log/clear'), 'The desk has its own clear for this table.', 'No clear control on the view log card.');
    $db->prepare('INSERT INTO lms_lesson_view_events(student_id,lesson_id,event_type,created_at) VALUES(?,?,?,UTC_TIMESTAMP() - INTERVAL 400 DAY)')
        ->execute([$studentId, $lessonId, 'seek_ahead']);
    $swept = $open('GET', '/admin/activity', [], $jarAdmin);
    $assert(str_contains($swept['body'], '45 day(s)'), 'The desk states the retention window the admin set.', 'The card still says the seeded 30 days.');
    $assert((int) $db->query('SELECT COUNT(*) FROM lms_lesson_view_events WHERE event_type="seek_ahead"')->fetchColumn() === 0,
        'A report older than the window is dropped the next time the desk is opened, with no scheduler to run.',
        'A 400 day old report survived the sweep.');
    $assert((int) $db->query('SELECT COUNT(*) FROM lms_lesson_view_events WHERE event_type="download_blocked"')->fetchColumn() >= 1,
        'The sweep only takes what is past its window.', 'Fresh reports were cleared with the old one.');
    $cleared = $open('POST', '/admin/activity/view-log/clear', ['_csrf' => $csrfOf($open('GET', '/admin/activity', [], $jarAdmin)['body'])], $jarAdmin);
    $assert($cleared['status'] === 302 && (int) $db->query('SELECT COUNT(*) FROM lms_lesson_view_events')->fetchColumn() === 0,
        'Clearing the viewing log empties it and only it.', 'The clear answered ' . $cleared['status'] . ' and left ' . (int) $db->query('SELECT COUNT(*) FROM lms_lesson_view_events')->fetchColumn() . ' row(s).');
    $assert((int) $db->query("SELECT COUNT(*) FROM lms_audit_logs WHERE action='lesson_view_log.cleared'")->fetchColumn() === 1,
        'Emptying the evidence table is itself on record.', 'No audit row for the view log clear.');

    $emit("\n-- the student directory shows and closes sessions");
    $db->prepare('INSERT INTO lms_lesson_view_events(student_id,lesson_id,event_type,detail) VALUES(?,?,?,?)')->execute([$studentId, $lessonId, 'context_menu', 'right click']);
    $detail = $open('GET', '/admin/students?student_id=' . $studentId, [], $jarAdmin);
    $assert($detail['status'] === 200 && str_contains($detail['body'], 'Open sessions'), 'The student detail card lists the devices holding the account.', 'No "Open sessions" block on the student detail.');
    $assert(str_contains($detail['body'], '127.0.0.1') && str_contains($detail['body'], 'Chrome'),
        'A listed session shows the address and the kind of device, never the raw string or the session id.',
        'The session table did not print the address and device family.');
    $assert(!str_contains($detail['body'], $sessionIdOf($jarC)) && !str_contains($detail['body'], hash('sha256', $sessionIdOf($jarC))),
        'No session hash or id is printed where an admin can read it off the screen.', 'A session identifier is printed on the desk.');
    $closed = $open('POST', '/admin/students/revoke-sessions', ['_csrf' => $csrfOf($detail['body']), 'student_id' => (string) $studentId], $jarAdmin);
    $assert($closed['status'] === 302, 'The close-all button returns to the card it belongs to.', 'The revoke answered ' . $closed['status'] . '.');
    $stillOpen = array_values(array_filter($activeRows($studentId), static fn(array $r): bool => $r['revoked_at'] === null));
    $assert($stillOpen === [], 'Every device holding the account is closed at once.', count($stillOpen) . ' session(s) stayed open.');
    $assert(in_array('admin_revoked', array_column($activeRows($studentId), 'revoked_reason'), true),
        'Each closed row says an administrator signed it out, so the reason is not guesswork later.', 'The rows do not say why they closed.');
    $assert((int) $db->query("SELECT COUNT(*) FROM lms_audit_logs WHERE action='session.revoked_all' AND entity_id=" . (int) $studentId)->fetchColumn() === 1,
        'The action is audited against the account it was taken on.', 'No audit row for the session close.');
    $pushed = $open('GET', '/dashboard', [], $jarC);
    $assert($pushed['status'] === 302 && (string) $pushed['location'] === $appUrl . '/login',
        'The student who was on that device is out of their dashboard on the next click.', 'The closed device was still served (status ' . $pushed['status'] . ').');
    $assert(str_contains($open('GET', '/admin/students?student_id=' . $studentId, [], $jarAdmin)['body'], 'No open session'),
        'The card says so in the same words an admin can pass on.', 'The card still lists sessions that are closed.');

    $emit("\n-- one more sign-in, and the block that stops abuse");
    $reopened = $signIn($jarStudent2, $studentEmail, $studentPassword);
    $assert($reopened['signed'], 'The student signs in again after the admin closed everything.', 'The student could not sign back in (status ' . $reopened['status'] . ').');
    $assert(count(array_values(array_filter($activeRows($studentId), static fn(array $r): bool => $r['revoked_at'] === null))) === 1,
        'A clean sign-in opens exactly one session, however many were closed before it.', 'The new sign-in left more than one open row.');
    $studentSaves = $open('POST', '/admin/settings/video-protection', [
        '_csrf' => $csrfAttr($open('GET', '/learning/lesson?lesson_id=' . $lessonId, [], $jarStudent2)['body']),
        SessionRegistry::MAX_ACTIVE_KEY => '10',
        LessonProtection::WATERMARK_KEY => '0',
        LessonProtection::RETENTION_KEY => '1',
    ], $jarStudent2);
    $assert($studentSaves['status'] === 302 && (string) $studentSaves['location'] === $appUrl . '/login'
        && $readSetting(SessionRegistry::MAX_ACTIVE_KEY) === '3' && $readSetting(LessonProtection::RETENTION_KEY) === '45',
        'A student who posts at the admin settings endpoint is bounced before any value is written.',
        'The student save changed the settings (status ' . $studentSaves['status'] . ', cap ' . var_export($readSetting(SessionRegistry::MAX_ACTIVE_KEY), true) . ').');

    // The role bounce above destroyed the student's session, so the sign-out step opens a fresh one.
    // It first puts the cap back to the seeded 1, which is also what makes the row that destroyed
    // session left behind go away: a new sign-in displaces the oldest one on file, and a cookie that
    // no longer exists is exactly the kind of row the company rule wants taken out.
    $emit("\n-- a logout closes the device that logged out");
    $putSetting->execute([SessionRegistry::MAX_ACTIVE_KEY, '1']);
    $resigned = $signIn($jarStudent2, $studentEmail, $studentPassword);
    $assert($resigned['signed'], 'The student signs in once more to leave by the door they came in.', 'The student could not sign in (status ' . $resigned['status'] . ').');
    $assert(in_array('superseded', array_column($activeRows($studentId), 'revoked_reason'), true),
        'The row a destroyed session left behind is displaced by the next sign-in rather than left open.',
        'Nothing was closed as superseded, so the orphan row is still holding a slot: ' . json_encode($activeRows($studentId)) . '.');
    $logoutHash = hash('sha256', $sessionIdOf($jarStudent2));
    $signedOut = $open('POST', '/logout', ['_csrf' => $csrfOf($open('GET', '/dashboard', [], $jarStudent2)['body'])], $jarStudent2);
    $assert($signedOut['status'] === 302 && (string) $signedOut['location'] === $appUrl . '/login', 'Signing out goes back to the form.', 'The logout answered ' . $signedOut['status'] . '.');
    $logoutClosed = array_values(array_filter($activeRows($studentId), static fn(array $r): bool => (string) $r['revoked_reason'] === 'logout'));
    $assert(count($logoutClosed) === 1 && $logoutClosed[0]['session_hash'] === $logoutHash,
        'The button a student leaves by closes that device\'s row as a logout, and nothing else.',
        'Rows closed as logout after a real sign-out: ' . json_encode(array_column($logoutClosed, 'session_hash')) . ' against ' . $logoutHash . '.');
    $assert(count(array_values(array_filter($activeRows($studentId), static fn(array $r): bool => $r['revoked_at'] === null))) === 0,
        'A signed-out account holds no open session.', 'A session stayed open after the logout.');

    $emit("\n-- the wiring the browser depends on");
    $routes = (string) file_get_contents(BASE_PATH . '/public/index.php');
    foreach ([
        "\$router->post('/learning/lesson/view', [\\App\\Controllers\\LearningController::class, 'view'])",
        "\$router->post('/admin/settings/video-protection', [\\App\\Controllers\\AdminManagementController::class, 'saveVideoProtection'])",
        "\$router->post('/admin/activity/view-log/clear', [\\App\\Controllers\\NotificationController::class, 'clearViewLog'])",
        "\$router->post('/admin/students/revoke-sessions', [\\App\\Controllers\\StudentManagementController::class, 'revokeSessions'])",
        "'/app/Services/LessonProtection.php'",
        "'/app/Services/SessionRegistry.php'",
    ] as $declaration) {
        $assert(str_contains($routes, $declaration), 'public/index.php declares ' . trim($declaration) . '.', 'Missing declaration: ' . $declaration);
    }
    $frontScript = (string) file_get_contents(BASE_PATH . '/public/assets/js/main.js');
    foreach ([
        'data-video-shell', 'requestFullscreen', 'fullscreenchange', 'enterpictureinpicture',
        'contextmenu', 'seek_ahead', 'data-wm-trace', 'data-protection-note',
    ] as $hook) {
        $assert(str_contains($frontScript, $hook), 'main.js handles ' . $hook . '.', 'main.js has no ' . $hook . ' handling, so the guard is decorative.');
    }
    $styleSheet = (string) file_get_contents(BASE_PATH . '/public/assets/css/pages.css');
    foreach (['.lesson-video-shell', '.lesson-watermark', '.lesson-watermark-faint', '.lesson-protection-note'] as $selector) {
        $assert(str_contains($styleSheet, $selector), 'pages.css styles ' . $selector . '.', $selector . ' has no rules, so the overlay would not sit over the player.');
    }
    $assert(str_contains($styleSheet, 'pointer-events:none') || str_contains($styleSheet, 'pointer-events: none'),
        'The overlay cannot be clicked through or dragged off the recording.', 'The overlay is not pointer-transparent.');
} catch (Throwable $exception) {
    $failures[] = 'The HTTP half stopped: ' . $exception->getMessage();
    $report .= '  x ' . $exception->getMessage() . "\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    foreach ($cliSessions ?? [] as $id) {
        @session_id($id);
        @session_start();
        $_SESSION = [];
        @session_destroy();
        @session_write_close();
    }
    shell_exec($mysql . ' ' . $credentials . ' -e ' . escapeshellarg('DROP DATABASE IF EXISTS `' . $emptyName . '`'));
    // The rows this run made, and the three settings it moved, go back the way they were found.
    $db->prepare('DELETE FROM lms_lesson_view_events WHERE student_id IN (?,?)')->execute([$studentId, $resetStudentId]);
    $db->prepare('DELETE FROM lms_user_sessions WHERE user_id IN (?,?)')->execute([$studentId, $resetStudentId]);
    $db->prepare('DELETE FROM lms_audit_logs WHERE actor_user_id IN (?,?,?)')->execute([$studentId, $resetStudentId, $adminId]);
    $db->prepare('DELETE FROM lms_auth_rate_limits WHERE subject_hash IN (?)')->execute([hash('sha256', 'user:' . $studentId)]);
    // The admin who saved the protection card is stored on the settings rows as who last touched
    // them, so that reference has to go back to NULL before the account itself can be removed - the
    // settings rows themselves stay, with their values restored below.
    $db->prepare('UPDATE lms_app_settings SET updated_by = NULL WHERE updated_by IN (?,?,?)')->execute([$studentId, $resetStudentId, $adminId]);
    $db->prepare('DELETE FROM lms_login_events WHERE user_id IN (?,?)')->execute([$studentId, $resetStudentId]);
    $db->prepare('DELETE FROM lms_email_otps WHERE user_id IN (?,?)')->execute([$studentId, $resetStudentId]);
    $db->prepare('DELETE FROM lms_attendance_records WHERE student_id IN (?,?)')->execute([$studentId, $resetStudentId]);
    $db->prepare('DELETE FROM lms_lesson_progress WHERE student_id IN (?,?)')->execute([$studentId, $resetStudentId]);
    $db->prepare('DELETE FROM lms_student_course_access WHERE student_id IN (?,?)')->execute([$studentId, $resetStudentId]);
    $db->prepare('DELETE FROM lms_users WHERE id IN (?,?,?)')->execute([$studentId, $resetStudentId, $adminId]);
    // The course is the top of a chain the run built, so the lesson and the module above it have to
    // go in the same order the schema stacks them.
    $db->prepare('DELETE FROM lms_lessons WHERE module_id IN (SELECT id FROM lms_course_modules WHERE course_id IN (?,?))')->execute([$courseId, $otherCourseId]);
    $db->prepare('DELETE FROM lms_course_modules WHERE course_id IN (?,?)')->execute([$courseId, $otherCourseId]);
    $db->prepare('DELETE FROM lms_courses WHERE slug IN (?,?)')->execute($slugs);
    $restore = [SessionRegistry::MAX_ACTIVE_KEY => '1', LessonProtection::WATERMARK_KEY => '1', LessonProtection::RETENTION_KEY => '30'];
    foreach ($restore as $key => $value) $putSetting->execute([$key, $value]);
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
    echo "PASS: video protection behaves as designed on " . $dbName . "\n";
}
exit($status);
