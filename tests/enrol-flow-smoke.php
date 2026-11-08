<?php
declare(strict_types=1);

/**
 * Enrolment journey smoke test.
 *
 * The course a visitor picked used to be forgotten the moment they were sent to /login, which is
 * what made buying a course feel like a loop: catalogue, course, sign-in detour, empty dashboard,
 * back to the catalogue. This suite walks the journey over real HTTP so nothing about it is taken
 * on trust. It boots PHP's built-in web server against a scratch database and then drives the
 * browser's own steps — a cookie jar, the CSRF token scraped from the rendered card, and the 302
 * targets read straight off the response — because a handler wired to a route nobody can reach, or
 * a redirect that lands on /login instead of the payment step, is exactly the failure this change
 * was written to remove.
 *
 * Mail is never configured in this process (MAIL_HOST is forced empty), so registration takes the
 * "the code could not be delivered" branch on purpose: the countdown, the resend row and the
 * OTP popup are what is under test here, and real SMTP delivery is proven by
 * auth-flow-smoke.php. Where a code is needed to finish the journey, the hash stored in email_otps
 * is replaced with a known one, which exercises the same verification path a posted code travels.
 *
 *   /Applications/XAMPP/xamppfiles/bin/php tests/enrol-flow-smoke.php --database=tsg_enrol_check_tmp
 *
 * Add --drop=1 to remove the scratch database afterwards.
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Core/Env.php';
require BASE_PATH . '/app/Core/Database.php';

use App\Core\Database;
use App\Core\Env;

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

// Fatal, never a recorded failure: this suite creates real accounts, and a soft guard is how
// fixture students ended up in the development database once already.
if (preg_match('/(tmp|check|scratch|test)/i', $dbName) !== 1) {
    fwrite(STDERR, 'Refusing to run: --database=' . $dbName . " does not look like a scratch database.\n");
    exit(1);
}
echo '  ok Running against the scratch database ' . $dbName . ".\n";

$mysql = '/Applications/XAMPP/xamppfiles/bin/mysql';
$dbUser = (string) (getenv('DB_USER') ?: 'root');
$dbPassword = (string) (getenv('DB_PASS') ?: '');
$dbHost = (string) (getenv('DB_HOST') ?: '127.0.0.1');
$dbPort = (string) (getenv('DB_PORT') ?: '3306');
$credentials = '-u ' . escapeshellarg($dbUser) . ($dbPassword === '' ? '' : ' -p' . escapeshellarg($dbPassword))
    . ' -h ' . escapeshellarg($dbHost) . ' -P ' . escapeshellarg($dbPort);
$exists = trim((string) shell_exec($mysql . ' ' . $credentials . ' -N -B -e ' .
    escapeshellarg("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='" . $dbName . "'")));
if ($exists !== '1') {
    shell_exec($mysql . ' ' . $credentials . ' -e ' . escapeshellarg('CREATE DATABASE `' . $dbName . '`'));
    shell_exec($mysql . ' ' . $credentials . ' ' . escapeshellarg($dbName) . ' < ' . escapeshellarg(BASE_PATH . '/database/migrations/001_initial_schema.sql'));
    echo "bootstrapped scratch schema into " . $dbName . "\n";
}
$tableCount = (int) shell_exec($mysql . ' ' . $credentials . ' -N -B -e ' .
    escapeshellarg("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='" . $dbName . "'"));
$assert($exists !== '1' ? $tableCount >= 20 : true,
    'The scratch database holds the full schema.', 'The scratch database has no schema; import database/migrations/001_initial_schema.sql and rerun.');

$db = Database::connection();

/* --------------------------------------------------------------------------- web server */
// The journey only exists as HTTP: routes, redirects, session cookies and the CSRF token a real
// form carries. PHP's own server is pointed at the front controller, and APP_URL is rebuilt around
// the port it actually got, so the Location headers the app emits are the ones a browser follows.
$probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!is_resource($probe)) {
    fwrite(STDERR, 'FAIL: no free loopback port for the test web server: ' . $errstr . "\n");
    exit(1);
}
$serverPort = (int) parse_url((string) stream_socket_get_name($probe, false), PHP_URL_PORT);
fclose($probe);
$appUrl = 'http://127.0.0.1:' . $serverPort;

putenv('APP_URL=' . $appUrl);
// Forced empty on purpose: the mail step must fail fast and predictably rather than reach for a
// server this suite is not testing.
putenv('MAIL_HOST=');
putenv('MAIL_PORT=25');

if (!is_dir(BASE_PATH . '/storage/tmp')) {
    @mkdir(BASE_PATH . '/storage/tmp', 0700, true);
}
$scratch = BASE_PATH . '/storage/tmp/enrol-smoke-' . bin2hex(random_bytes(6));
$serverLog = $scratch . '.server.log';
$cookieJar = $scratch . '.cookies';
// The checkout demands a receipt upload, so the journey that ends in a payment needs a file to send.
$proofPath = $scratch . '.proof.pdf';
file_put_contents($proofPath, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $serverPort, '-t', 'public', 'public/index.php'],
    [1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']],
    $pipes,
    BASE_PATH
);
if (!is_resource($server)) {
    fwrite(STDERR, "FAIL: the test web server could not start.\n");
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
    fwrite(STDERR, "FAIL: the test web server never accepted a connection.\n");
    exit(1);
}

/** One request, sent the way a browser sends it. No redirect is followed automatically. `$upload`
 *  names a file field to send as multipart, which the payment form requires. */
$open = static function (string $method, string $path, array $fields = [], string $upload = '') use ($appUrl, $cookieJar, &$failures, $proofPath): array {
    $handle = curl_init($appUrl . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 40,
    ]);
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
    $headers = (string) substr((string) $response, 0, $headerSize);
    $location = null;
    foreach (explode("\r\n", $headers) as $line) {
        if (stripos($line, 'Location:') === 0) $location = trim(substr($line, 9));
    }
    return ['status' => $status, 'body' => (string) substr((string) $response, $headerSize), 'location' => $location];
};
// The card is only ever shown to somebody who does not hold an account, so each journey that must
// start as a stranger throws away the session cookie first.
$freshVisitor = static function () use ($cookieJar): void {
    @unlink($cookieJar);
};
$csrfOf = static function (string $html): string {
    return preg_match('/name="_csrf" value="([^"]+)"/', $html, $match) === 1 ? $match[1] : '';
};

/* ------------------------------------------------------------------------------ fixtures */
$enrolEmail = 'enrol-smoke-' . bin2hex(random_bytes(6)) . '@example.test';
$slugLive = 'enrol-smoke-open';
$slugDraft = 'enrol-smoke-draft';
$slugFree = 'enrol-smoke-unpriced';
$slugOther = 'enrol-smoke-other';
$slugs = "'" . $slugLive . "','" . $slugDraft . "','" . $slugFree . "','" . $slugOther . "'";
$studentId = 0;
// The notices this journey raises, read back out of the database on the way cleanup, so a rerun
// counts its own fixtures and nothing else.
$noticeIds = [];

// A run that died part way leaves a payment behind, and a payment pins both the account and the
// course to it - so the rows that would block the fixtures are cleared before the fixtures are written.
$db->exec('DELETE FROM payments WHERE student_id IN (SELECT id FROM users WHERE email = ' . $db->quote($enrolEmail) . ')');
$db->exec("DELETE FROM payments WHERE course_id IN (SELECT id FROM courses WHERE slug IN (" . $slugs . '))');
$db->exec("DELETE FROM courses WHERE slug IN (" . $slugs . ")");
$db->exec('DELETE FROM users WHERE email = ' . $db->quote($enrolEmail));

// created_by is nullable and pointing at a real admin would only make the cleanup longer.
$insertCourse = $db->prepare("INSERT INTO courses(title,slug,visibility,currency,short_description) VALUES(?,?,?,'PKR','Fixture course used by the enrolment journey test.')");
$insertCourse->execute(['Enrol Smoke Open Course', $slugLive, 'published']);
$courseId = (int) $db->lastInsertId();
$insertCourse->execute(['Enrol Smoke Draft Course', $slugDraft, 'draft']);
$draftCourseId = (int) $db->lastInsertId();
$insertCourse->execute(['Enrol Smoke Unpriced Course', $slugFree, 'published']);
$freeCourseId = (int) $db->lastInsertId();
$insertCourse->execute(['Enrol Smoke Other Course', $slugOther, 'published']);
$otherCourseId = (int) $db->lastInsertId();
$insertOption = $db->prepare("INSERT INTO course_pricing_options(course_id,option_code,display_label,price,currency) VALUES(?,?,?,?,'PKR')");
$insertOption->execute([$courseId, 'recorded_only', 'Recorded Only', 24000]);
$insertOption->execute([$courseId, 'live_recorded', 'Live + Recorded', 39000]);
$insertOption->execute([$draftCourseId, 'recorded_only', 'Recorded Only', 24000]);
$insertOption->execute([$otherCourseId, 'recorded_only', 'Recorded Only', 21000]);
// Published, listed, and nothing to buy: the checkout must refuse to walk a student all the way to
// a payment form that has no price behind it.
$insertOption->execute([$freeCourseId, 'recorded_only', 'Recorded Only', 0]);

// Written rather than updated: a scratch database that was bootstrapped before this key existed
// would otherwise silently keep the service fallback and the countdown check would prove nothing.
$putSetting = $db->prepare("INSERT INTO app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
$settings = [
    'otp_lifetime_seconds' => '600',
    'otp_resend_cooldown_seconds' => '40',
    'register_max_attempts' => '50',
    'login_max_attempts' => '50',
];
foreach ($settings as $key => $value) $putSetting->execute([$key, $value]);

try {
    echo "\n-- the routes and the card the browser needs\n";
    $routes = (string) file_get_contents(BASE_PATH . '/public/index.php');
    foreach ([
        "\$router->get('/enrol/{slug}', [\$enrol, 'start'])",
        "\$router->post('/enrol', [\$enrol, 'register'])",
        "\$router->post('/enrol/verify', [\$enrol, 'verify'])",
        "\$router->post('/enrol/verify/resend', [\$enrol, 'resend'])",
        "\$router->post('/enrol/back', [\$enrol, 'back'])",
    ] as $declaration) {
        $assert(str_contains($routes, $declaration), 'The router declares ' . $declaration . '.', 'Missing route declaration: ' . $declaration);
    }
    $cardSource = (string) file_get_contents(BASE_PATH . '/app/Views/enrol/checkout.php');
    $assert(str_contains($cardSource, 'data-enrol-form') && str_contains($cardSource, 'data-otp-countdown'),
        'The checkout card carries the hooks the resend countdown is driven from.',
        'The checkout card lost the hooks main.js needs.');
    $assert(!str_contains($cardSource, 'name="course_id"'),
        'The card posts no course id at all, so the session is the only place the choice can come from.',
        'The checkout card carries a posted course id, which means the session intent can be overwritten.');
    $frontScript = (string) file_get_contents(BASE_PATH . '/public/assets/js/main.js');
    $assert(str_contains($frontScript, 'form[data-enrol-form]'),
        'main.js binds an in-flight progress state to the enrolment form.',
        'main.js has no handler for the enrolment form, so the OTP wait is silent again.');
    $assert(str_contains($frontScript, 'form[data-submit-progress-form]')
        && str_contains($cardSource, 'Please wait, OTP is being sent to your Gmail.'),
        'The registration request opens its OTP-sending progress popup.',
        'The registration request has no OTP-sending progress popup.');
    $paymentFormSource = (string) file_get_contents(BASE_PATH . '/app/Views/payments/form.php');
    $adminPaymentSource = (string) file_get_contents(BASE_PATH . '/app/Views/admin/payments.php');
    $paymentControllerSource = (string) file_get_contents(BASE_PATH . '/app/Controllers/PaymentController.php');
    $assert(str_contains($paymentFormSource, 'data-submit-progress-form')
        && str_contains($paymentFormSource, 'data-submit-progress-overlay'),
        'Payment submission displays an in-flight progress popup.',
        'Payment submission has no in-flight progress popup.');
    $assert(str_contains($adminPaymentSource, 'data-progress-approval-only')
        && str_contains($adminPaymentSource, 'Your payment is being approved. Please wait.')
        && str_contains($adminPaymentSource, 'data-submit-progress-overlay'),
        'Admin approval displays its progress popup without changing rejection behavior.',
        'Admin approval has no dedicated progress popup.');
    $assert(str_contains($frontScript, "window.confirm('Are you sure you want to reject this payment? The student will be notified.')")
        && str_contains($frontScript, 'Your payment rejection is being processed. Please wait.')
        && str_contains($adminPaymentSource, 'Processing payment decision'),
        'Payment rejection asks for confirmation and displays its own in-flight message after confirmation.',
        'Payment rejection is missing confirmation or its in-flight message.');
    $proofPosition = strpos($adminPaymentSource, '>Download Submitted Proof</a>');
    $approvePosition = strpos($adminPaymentSource, 'payment-approve-button');
    $rejectPosition = strpos($adminPaymentSource, 'payment-reject-button');
    $assert($proofPosition !== false && $approvePosition !== false && $rejectPosition !== false
        && $proofPosition < $approvePosition && $approvePosition < $rejectPosition,
        'The proof, approval and rejection actions render in the requested order.',
        'The payment card actions are not ordered proof, approval, then rejection.');
    $assert(str_contains($adminPaymentSource, 'Review note <span>(required to reject)</span>')
        && str_contains($frontScript, 'Add a review note before rejecting this payment.')
        && str_contains($paymentControllerSource, "if (\$decision === 'rejected' && \$note === '')"),
        'Rejection requires a review note while approval keeps it optional.',
        'The reject note requirement is not clear and enforced.');
    $assert(str_contains($paymentControllerSource, 'Your payment approval request has been emailed to the admin.')
        && str_contains($paymentControllerSource, 'Payment approved successfully. Course access has been granted to the student.'),
        'Payment submission and approval return clear completion messages.',
        'The payment submission or approval completion message is missing.');

    echo "\n-- a course that cannot be enrolled in goes back to the catalogue\n";
    foreach ([['unknown-slug', 'unknown'], [$slugDraft, 'unpublished'], [$slugFree, 'unpriced']] as [$slug, $label]) {
        $freshVisitor();
        $bounce = $open('GET', '/enrol/' . rawurlencode($slug));
        $assert($bounce['status'] === 302 && (string) $bounce['location'] === $appUrl . '/courses',
            $label . ' slug redirects to the catalogue instead of a checkout that cannot be finished.',
            $label . ' slug answered ' . $bounce['status'] . ' with Location ' . json_encode($bounce['location']));
        $notice = $open('GET', '/courses');
        $assert(str_contains($notice['body'], 'not open for enrollment'),
            $label . ' bounce explains itself on the catalogue rather than dropping the visitor silently.',
            $label . ' bounce reached the catalogue without the explanation (status ' . $notice['status'] . ').');
        // The notice is a one-shot flash. Left in the session it would print again on whichever page
        // the visitor opens next, which is how a flash key no page clears leaks across an app.
        $repeat = $open('GET', '/courses');
        $assert(!str_contains($repeat['body'], 'not open for enrollment'),
            $label . ' notice is cleared once shown.',
            $label . ' notice survived a page view and would reappear on another screen.');
    }

    echo "\n-- /register is no longer a door into a course-less account\n";
    $freshVisitor();
    $registerPage = $open('GET', '/register');
    $assert($registerPage['status'] === 302 && (string) $registerPage['location'] === $appUrl . '/courses',
        'GET /register sends the visitor to the catalogue, so an account is never opened without a course.',
        'GET /register answered ' . $registerPage['status'] . ' with Location ' . json_encode($registerPage['location']));

    echo "\n-- the open course opens the account step\n";
    $freshVisitor();
    $start = $open('GET', '/enrol/' . $slugLive);
    $assert($start['status'] === 200 && str_contains($start['body'], 'Create account and send code'),
        'GET /enrol/{slug} renders the details step of the card.',
        'The enrolment card did not render the account step (status ' . $start['status'] . ').');
    $assert(str_contains($start['body'], 'Enrol Smoke Open Course') && str_contains($start['body'], '24,000'),
        'The card names the course and its prices, so the choice is visible while the account is typed.',
        'The card does not show the chosen course and its prices.');
    $assert(str_contains($start['body'], 'Verify email') && str_contains($start['body'], 'Submit payment'),
        'The card prints the whole journey, so the student can see how many steps are left.',
        'The card lost its step list.');
    $assert($csrfOf($start['body']) !== '', 'The account step carries a CSRF token.', 'The account step rendered without a CSRF token.');

    echo "\n-- the code step opens its popup and keeps the course\n";
    $tampered = $open('POST', '/enrol', [
        '_csrf' => $csrfOf($start['body']),
        // Sent along with the real form fields to prove the handler has nothing to read it from.
        'course_id' => (string) $otherCourseId,
        'full_name' => 'Enrol Smoke Student',
        'email' => $enrolEmail,
        'password' => 'Correct Horse Battery 9!',
        'password_confirmation' => 'Correct Horse Battery 9!',
    ]);
    $assert($tampered['status'] === 200
        && str_contains($tampered['body'], 'id="enrol-otp-modal"')
        && str_contains($tampered['body'], 'data-enrol-otp-auto-open')
        && str_contains($tampered['body'], 'name="code"'),
        'The account step opens the code form in the OTP verification popup.',
        'Registering did not render the code form in its popup (status ' . $tampered['status'] . ').');
    $assert(str_contains($tampered['body'], 'could not be delivered'),
        'A code the server could not email is reported honestly instead of as a duplicate account.',
        'The undeliverable-code case is not stated on the card.');
    $assert(!str_contains($tampered['body'], 'We could not open a new account'),
        'An address that was free is not reported as taken.',
        'The card claims the address already has an account when it did not.');
    $student = $db->query('SELECT id, status, email_verified_at FROM users WHERE email = ' . $db->quote($enrolEmail))->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($student) && $student['status'] === 'pending' && $student['email_verified_at'] === null,
        'The account exists and stays locked until the code is confirmed.',
        'Registration did not leave a pending, unverified student: ' . json_encode($student));
    $studentId = (int) ($student['id'] ?? 0);
    $countdown = preg_match('/data-otp-countdown="(\d+)"/', $tampered['body'], $match) === 1 ? (int) $match[1] : -1;
    $assert($countdown > 0 && $countdown <= 40,
        'The resend countdown runs on the 40 second wait, not the 600 second code lifetime.',
        'The card advertised a ' . $countdown . ' second resend wait with lifetime 600 and cooldown 40.');
    $assert(str_contains($tampered['body'], 'Enrol Smoke Open Course'),
        'The code step still names the course the session holds.',
        'The code step lost the chosen course.');

    echo "\n-- the code step keeps resend, timer and email correction easy to reach\n";
    preg_match('/<p class="otp-resend-row"[^>]*data-otp-countdown="\d+"[^>]*>(?<row>.*?)<\/p>/s', $tampered['body'], $resendRow);
    $rowHtml = (string) ($resendRow['row'] ?? '');
    $assert(str_contains($rowHtml, 'Didn’t receive a code?') && str_contains($rowHtml, '>Resend</button>'),
        'The resend prompt and button share a single row.',
        'The code step does not keep the resend prompt and button together.');
    preg_match('/<button[^>]*>(?<label>[^<]*)<\/button>/', $rowHtml, $firstButton);
    $assert(trim((string) ($firstButton['label'] ?? '')) === 'Resend',
        'Resend is the first button in the countdown row, which is the control main.js counts down on.',
        'The countdown row now opens with another button, so the timer would drive the wrong control.');
    $assert(str_contains($rowHtml, 'class="otp-resend-timer"'),
        'The countdown row keeps the element the remaining seconds are written into.',
        'The countdown row lost its timer element, so the wait would be silent.');
    $assert(str_contains($tampered['body'], 'Wrong email? Go back')
        && str_contains($cardSource, 'class="submit-progress-overlay"')
        && str_contains($cardSource, 'data-submit-progress-form'),
        'The email correction remains available separately and registration has a blocking progress popup.',
        'The code step lost the email correction or registration progress popup.');
    // The summary states the course once; the category and duration beside it only repeated it.
    preg_match('/<div class="enrol-summary-main">(?<main>.*?)<\/div>/s', $tampered['body'], $summary);
    $summaryMain = trim((string) ($summary['main'] ?? ''));
    $assert($summaryMain !== '' && str_contains($summaryMain, 'Enrol Smoke Open Course') && stripos($summaryMain, '<small') === false,
        'The enrolment summary names the course once, with no repeated format line under it.',
        'The enrolment summary still doubles the course information: ' . $summaryMain);

    echo "\n-- a wrong code is survivable and a posted course id is inert\n";
    $wrongCode = $open('POST', '/enrol/verify', [
        '_csrf' => $csrfOf($tampered['body']),
        'email' => $enrolEmail,
        'code' => '000000',
        'course_id' => (string) $otherCourseId,
    ]);
    $assert($wrongCode['status'] === 200 && str_contains($wrongCode['body'], 'invalid or expired'),
        'A wrong code re-renders the card and does not activate the account.',
        'A wrong code was not reported on the card (status ' . $wrongCode['status'] . ').');
    $assert(!(bool) $db->query('SELECT 1 FROM users WHERE email = ' . $db->quote($enrolEmail) . " AND status = 'active'")->fetchColumn(),
        'A rejected code leaves the account pending.',
        'A wrong code activated the account.');
    $retryable = (bool) $db->query("SELECT consumed_at IS NULL FROM email_otps WHERE user_id = " . $studentId . " ORDER BY id DESC LIMIT 1")->fetchColumn();
    $assert($retryable, 'The issued code is still unconsumed, so one typo does not cost a new OTP.',
        'The wrong attempt burned the live code.');

    // A known code through the same hash the service verifies against, so the path under test is the
    // real one; delivery itself belongs to auth-flow-smoke.php.
    $db->prepare("UPDATE email_otps SET otp_hash = ?, attempts = 0, consumed_at = NULL, expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 600 SECOND) WHERE user_id = ? AND purpose = 'verify_email'")
        ->execute([password_hash('246810', PASSWORD_DEFAULT), $studentId]);
    $verified = $open('POST', '/enrol/verify', [
        '_csrf' => $csrfOf($wrongCode['body']),
        'email' => $enrolEmail,
        'code' => '246810',
        'course_id' => (string) $otherCourseId,
    ]);
    $assert($verified['status'] === 302 && (string) $verified['location'] === $appUrl . '/payments/new?course_id=' . $courseId,
        'Confirming the code goes to the payment step for the course the session holds, not the one that was posted.',
        'Verification answered ' . $verified['status'] . ' with Location ' . json_encode($verified['location'])
            . ' (session course ' . $courseId . ', posted course ' . $otherCourseId . ').');
    $active = $db->query('SELECT status, email_verified_at FROM users WHERE id = ' . $studentId)->fetch(PDO::FETCH_ASSOC);
    $assert((string) ($active['status'] ?? '') === 'active' && ($active['email_verified_at'] ?? null) !== null,
        'The confirmed account is active.',
        'The account was not activated by the confirmed code: ' . json_encode($active));

    echo "\n-- and that payment step is the next screen, not a sign-in wall\n";
    $payment = $open('GET', '/payments/new?course_id=' . $courseId);
    $assert($payment['status'] === 200 && str_contains($payment['body'], 'Submit payment') && str_contains($payment['body'], 'Enrol Smoke Open Course'),
        'The verified student lands on the bank-details page for the course they came for.',
        'The payment step did not render for the freshly verified student (status ' . $payment['status']
            . ', Location ' . json_encode($payment['location']) . ').');
    $assert(!str_contains($payment['body'], 'Email verified for '),
        'The payment page does not show the unwanted registration flash.',
        'Successful OTP verification still leaves the registration flash on the payment page.');
    $assert(str_contains($payment['body'], 'payment_proof') && str_contains($payment['body'], 'transaction_reference'),
        'The existing payment form is reused as it is, upload field and reference field included.',
        'The payment form fields are missing from the page the journey ends on.');

    echo "\n-- and the option chosen on that step is written down, not just posted\n";
    // The card has always posted option_code. Until live class links existed the choice only reached
    // the audit blob, where no page could read it back, so the last step of the journey is now the
    // one thing that has to survive it: a payment that says which plan was bought.
    $assert(str_contains($payment['body'], 'name="option_code"')
        && str_contains($payment['body'], 'value="recorded_only"') && str_contains($payment['body'], 'value="live_recorded"'),
        'The payment step asks for the learning option and offers both plans the catalogue sells.',
        'The payment form posts no option_code, or offers only half the plans.');
    // A scratch database that was bootstrapped before the column arrived would report a missing plan
    // for a reason that has nothing to do with this journey, so the schema is topped up the same
    // guarded way the app's own migration does it.
    $planColumn = (int) $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'plan_code'")->fetchColumn();
    if ($planColumn === 0) {
        shell_exec($mysql . ' ' . $credentials . ' ' . escapeshellarg($dbName) . ' < '
            . escapeshellarg(BASE_PATH . '/database/migrations/001_initial_schema.sql'));
        $planColumn = (int) $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'plan_code'")->fetchColumn();
        echo 're-imported the schema into ' . $dbName . " to add payments.plan_code\n";
    }
    $assert($planColumn === 1, 'The scratch database holds the column the journey writes the plan into.',
        'payments.plan_code is still missing after a re-import of database/migrations/001_initial_schema.sql.');
    // The proof folder is private, and a folder another account created is not writable from here.
    // Where it cannot be written, the refusal is what is measured - a journey that half-wrote a
    // payment would be the real bug.
    $proofFolder = BASE_PATH . '/storage/uploads/private/payment-proofs';
    $canUpload = is_dir($proofFolder) ? is_writable($proofFolder) : @mkdir($proofFolder, 0700, true);
    $reference = 'ENROL-' . substr($slugLive, -6) . '-' . bin2hex(random_bytes(4));
    $bought = $open('POST', '/payments', [
        '_csrf' => $csrfOf($payment['body']),
        'course_id' => (string) $courseId,
        'option_code' => 'live_recorded',
        'transaction_reference' => $reference,
    ], 'payment_proof');
    $assert($bought['status'] === 302, 'The finished checkout answers with a redirect back to the step, as every other form does.',
        'The payment POST answered ' . $bought['status'] . ' instead of redirecting.');
    $row = $db->prepare('SELECT id,plan_code,status,amount,currency FROM payments WHERE student_id = ? AND course_id = ?');
    $row->execute([$studentId, $courseId]);
    $written = $row->fetch(PDO::FETCH_ASSOC) ?: [];
    $journeyPaymentId = (int) ($written['id'] ?? 0);
    if ($canUpload) {
        $assert($journeyPaymentId > 0 && (string) ($written['status'] ?? '') === 'pending',
            'The last step of the enrolment journey leaves a payment waiting in the review queue.',
            'The checkout wrote no pending payment (row ' . json_encode($written) . ').');
        $assert((string) ($written['plan_code'] ?? '') === 'live_recorded',
            'The plan the student picked on that step is stored against the payment.',
            'payments.plan_code reads ' . var_export($written['plan_code'] ?? null, true) . ' after choosing Live + Recorded.');
        $assert((float) ($written['amount'] ?? 0) === 39000.0,
            'The amount charged is the price of the plan that was picked, not the cheaper one.',
            'The payment was written for ' . var_export($written['amount'] ?? null, true) . '.');
        $listed = $open('GET', '/student/payments');
        $assert($listed['status'] === 200 && str_contains($listed['body'], 'Live + Recorded') && str_contains($listed['body'], $reference),
            'The student can read the plan back on their own purchase list.',
            'The purchase list does not show the plan or the reference that was just submitted (status ' . $listed['status'] . ').');
    } else {
        $assert($journeyPaymentId === 0 && str_contains($open('GET', '/payments/new?course_id=' . $courseId)['body'], 'not writable'),
            'A proof folder this account cannot write stops the purchase, names the folder, and leaves no half-written payment.',
            'The blocked checkout left ' . json_encode($written) . ' behind.');
        echo "  NOTE " . $proofFolder . " belongs to another account, so this journey could not finish its upload from the command line. Step 1 of README \"Testing live class links\" shows it in a browser.\n";
    }
    $expectedPayments = (int) $db->query('SELECT COUNT(*) FROM payments WHERE student_id = ' . (int) $studentId)->fetchColumn();
    $forged = $open('POST', '/payments', [
        '_csrf' => $csrfOf($open('GET', '/payments/new?course_id=' . $courseId)['body']),
        'course_id' => (string) $courseId,
        'option_code' => 'gold_plan',
        'transaction_reference' => 'GOLD-' . bin2hex(random_bytes(4)),
    ], 'payment_proof');
    $afterForgery = (int) $db->query('SELECT COUNT(*) FROM payments WHERE student_id = ' . (int) $studentId)->fetchColumn();
    $assert($forged['status'] === 302 && $afterForgery === $expectedPayments,
        'An option nobody sells is refused, and the journey does not invent a plan for it.',
        'A forged learning option left ' . $afterForgery . ' payment row(s) where ' . $expectedPayments . ' were before.');

    echo "\n-- a course withdrawn between steps stops the journey\n";
    $db->exec('UPDATE courses SET visibility = \'draft\' WHERE id = ' . $courseId);
    $freshVisitor();
    $withdrawn = $open('GET', '/enrol/' . $slugLive);
    $assert($withdrawn['status'] === 302 && (string) $withdrawn['location'] === $appUrl . '/courses',
        'A course unpublished mid-checkout sends the visitor back to the catalogue.',
        'An unpublished course still served a checkout card (status ' . $withdrawn['status'] . ').');
    $db->exec('UPDATE courses SET visibility = \'published\' WHERE id = ' . $courseId);
    $reopened = $open('GET', '/enrol/' . $slugLive);
    $assert($reopened['status'] === 200,
        'Republished, the same link opens the account step again.',
        'The course could not be re-opened after being republished (status ' . $reopened['status'] . ').');

    echo "\n-- an account that already exists goes straight to payment for the picked course\n";
    $freshVisitor();
    $picked = $open('GET', '/enrol/' . $slugOther);
    $assert($picked['status'] === 200 && str_contains($picked['body'], 'Enrol Smoke Other Course'),
        'A visitor can open the card for a different course.',
        'That course did not open its card (status ' . $picked['status'] . ').');
    $signIn = $open('POST', '/login', [
        '_csrf' => $csrfOf($picked['body']),
        'email' => $enrolEmail,
        'password' => 'Correct Horse Battery 9!',
    ]);
    $assert($signIn['status'] === 302 && (string) $signIn['location'] === $appUrl . '/payments/new?course_id=' . $otherCourseId,
        'Signing in with a course picked goes to that course rather than the dashboard.',
        'Sign-in answered ' . $signIn['status'] . ' with Location ' . json_encode($signIn['location']));
    $coldSignIn = $open('POST', '/login', [
        '_csrf' => 'not-a-real-token',
        'email' => $enrolEmail,
        'password' => 'Correct Horse Battery 9!',
    ]);
    $assert($coldSignIn['status'] === 419,
        'A sign-in form carrying a forged token is refused outright.',
        'A forged sign-in answered ' . $coldSignIn['status'] . '.');

    echo "\n-- with nothing picked, sign-in behaves exactly as it always did\n";
    $freshVisitor();
    $loginPage = $open('GET', '/login');
    $failedSignIn = $open('POST', '/login', [
        '_csrf' => $csrfOf($loginPage['body']),
        'email' => 'unregistered-' . bin2hex(random_bytes(5)) . '@gmail.com',
        'password' => 'Incorrect Password 123!',
    ]);
    $assert($failedSignIn['status'] === 200
        && str_contains($failedSignIn['body'], 'flash-toast-card is-error')
        && str_contains($failedSignIn['body'], '<p class="flash-toast-label">Error</p>')
        && str_contains($failedSignIn['body'], 'Email or password is incorrect.')
        && !str_contains($failedSignIn['body'], '<p class="flash-toast-label">Success</p>'),
        'An unregistered email gets an error-styled login validation popup.',
        'An unregistered email did not render the login failure as an error popup.');

    $freshVisitor();
    $loginPage = $open('GET', '/login');
    $plainSignIn = $open('POST', '/login', [
        '_csrf' => $csrfOf($loginPage['body']),
        'email' => $enrolEmail,
        'password' => 'Correct Horse Battery 9!',
    ]);
    $assert($plainSignIn['status'] === 302 && (string) $plainSignIn['location'] === $appUrl . '/dashboard',
        'Without an enrolment in the session, sign-in still lands on the dashboard.',
        'A plain sign-in answered ' . $plainSignIn['status'] . ' with Location ' . json_encode($plainSignIn['location']));

    echo "\n-- the card cannot be driven from the outside\n";
    $freshVisitor();
    $open('GET', '/enrol/' . $slugLive);
    $reject = $open('POST', '/enrol', [
        '_csrf' => 'not-a-real-token',
        'full_name' => 'Ghost Student',
        'email' => 'ghost-' . bin2hex(random_bytes(6)) . '@example.test',
        'password' => 'Correct Horse Battery 9!',
        'password_confirmation' => 'Correct Horse Battery 9!',
    ]);
    $assert($reject['status'] === 419,
        'The enrolment form answers a forged token with 419 like every other form in the app.',
        'A forged enrolment post answered ' . $reject['status'] . '.');
    $assert(!(bool) $db->query("SELECT 1 FROM users WHERE full_name = 'Ghost Student'")->fetchColumn(),
        'No account was created behind the rejected token.',
        'A rejected enrolment still created a user.');

    $duplicate = $open('POST', '/enrol', [
        '_csrf' => $csrfOf($reject['body']),
        'full_name' => 'Enrol Smoke Student',
        'email' => $enrolEmail,
        'password' => 'Correct Horse Battery 9!',
        'password_confirmation' => 'Correct Horse Battery 9!',
    ]);
    $assert($duplicate['status'] === 200 && str_contains($duplicate['body'], 'We could not open a new account with that address'),
        'A taken address is answered with the shared, non-committal wording.',
        'Duplicate registration did not render the neutral message (status ' . $duplicate['status'] . ').');
    $assert(str_contains($duplicate['body'], 'sign in below or request a code')
        && !str_contains($duplicate['body'], 'A six-digit code is on its way'),
        'The wording offers both possibilities instead of confirming that an account exists.',
        'The duplicate response confirms that the address has an account.');
    $assert((int) $db->query('SELECT COUNT(*) FROM users WHERE email = ' . $db->quote($enrolEmail))->fetchColumn() === 1,
        'One account per address after a rejected duplicate.',
        'The duplicate attempt created a second row for the address.');
} catch (Throwable $exception) {
    $failures[] = 'The journey could not be completed: ' . $exception->getMessage();
    echo '  FAIL ' . $exception->getMessage() . "\n";
} finally {
    proc_terminate($server);
    proc_close($server);
    foreach ($settings as $key => $unused) {
        $restore = $db->prepare("INSERT INTO app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $fallback = ['otp_lifetime_seconds' => '600', 'otp_resend_cooldown_seconds' => '60', 'register_max_attempts' => '5', 'login_max_attempts' => '5'];
        $restore->execute([$key, $fallback[$key]]);
    }
    if ($studentId > 0) {
        // The journey's own checkout writes a payment, and a payment holds both the account and the
        // course it was bought for - so it has to go before either of them can.
        $heldProof = $db->prepare('SELECT payment_proof_path FROM payments WHERE student_id = ?');
        $heldProof->execute([$studentId]);
        foreach ($heldProof->fetchAll(PDO::FETCH_COLUMN) ?: [] as $storedProof) {
            if ($storedProof !== null) @unlink(BASE_PATH . '/storage/uploads/private/' . (string) $storedProof);
        }
        // Read before the recipients go, because the notice itself is only reachable through them.
        $heldNotices = $db->prepare('SELECT notification_id FROM notification_recipients WHERE student_id = ?');
        $heldNotices->execute([$studentId]);
        $noticeIds = array_map('intval', $heldNotices->fetchAll(PDO::FETCH_COLUMN) ?: []);
        foreach ([
            'DELETE FROM payments WHERE student_id = ?',
            // Submitting a payment leaves an audit row and two inbox notices behind, all of them
            // pointing back at the account the cleanup is about to remove.
            'DELETE FROM audit_logs WHERE actor_user_id = ?',
            'DELETE FROM notification_recipients WHERE student_id = ?',
            'DELETE FROM email_otps WHERE user_id = ?',
            'DELETE FROM email_logs WHERE related_user_id = ?',
            'DELETE FROM login_events WHERE user_id = ?',
            'DELETE FROM attendance_records WHERE student_id = ?',
            'DELETE FROM users WHERE id = ?',
        ] as $sql) {
            try {
                $db->prepare($sql)->execute([$studentId]);
            } catch (PDOException $exception) {
                // 1146 = the table is not there yet in a half-imported scratch database.
                if ((int) ($exception->errorInfo[1] ?? 0) !== 1146) throw $exception;
            }
        }
    }
    if ($noticeIds !== []) {
        // A notice with no reader left is nothing but a row, and the board a later run counts should
        // not be carrying the ones this journey raised.
        $db->exec('DELETE FROM notifications WHERE id IN (' . implode(',', $noticeIds) . ')');
    }
    $db->exec('DELETE FROM courses WHERE slug IN (' . $slugs . ')');
    $cleanup = $db->prepare('DELETE FROM auth_rate_limits WHERE subject_hash = ?');
    foreach ([hash('sha256', 'email:' . $enrolEmail), hash('sha256', 'ip:127.0.0.1')] as $hash) $cleanup->execute([$hash]);
    if ($failures !== [] && is_file($serverLog)) {
        // The app logs what PHP's server could not render, which is the part a status code hides.
        $tail = array_slice(file($serverLog, FILE_IGNORE_NEW_LINES) ?: [], -15);
        fwrite(STDERR, "\n-- last lines of the test server log\n" . implode("\n", $tail) . "\n");
    }
    @unlink($cookieJar);
    @unlink($serverLog);
    @unlink($proofPath);
    if (($options['drop'] ?? '') === '1') {
        shell_exec($mysql . ' ' . $credentials . ' -e ' . escapeshellarg('DROP DATABASE `' . $dbName . '`'));
        echo "dropped " . $dbName . "\n";
    }
}

echo $failures === []
    ? "\nEnrolment journey checks passed: slug intent, catalogue bounce with a flash that clears, OTP popup, resend cooldown, session-only course choice, verified hand-off to the payment step, sign-in intent and CSRF rejection.\n"
    : "\n" . count($failures) . " enrolment journey check(s) failed.\n";
exit($failures === [] ? 0 : 1);
