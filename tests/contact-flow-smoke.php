<?php
declare(strict_types=1);

/**
 * Contact form flow smoke test.
 *
 *   /Applications/XAMPP/xamppfiles/bin/php tests/contact-flow-smoke.php --database=tsg_contact_check_tmp
 *
 * Add --drop=1 to remove the scratch database afterwards.
 *
 * This exercises the whole public contact path without ever reaching the internet. A throwaway
 * SMTP server on the loopback interface records exactly what the application puts on the wire, and
 * a child process runs PublicPageController::submitContact() against a scratch database so the
 * CSRF check, validation, the two-message fan-out and the email_logs rows are all proven together.
 * Two messages must fire from one submission: the alert to the company inbox and the
 * acknowledgement back to the sender, both sent from the same mailbox.
 */

define('BASE_PATH', dirname(__DIR__));
foreach (
    [
        '/app/Core/Env.php',
        '/app/Core/Database.php',
        '/app/Core/Security.php',
        '/app/Core/RateLimiter.php',
        '/app/Services/EmailLayout.php',
        '/app/Services/EmailMessages.php',
        '/app/Services/AccountIdentity.php',
        '/app/Services/AdminAttention.php',
        '/app/Services/MailService.php',
        '/app/Controllers/PublicPageController.php',
    ] as $file
) {
    require BASE_PATH . $file;
}

use App\Controllers\PublicPageController;

\App\Core\Env::load(BASE_PATH . '/.env');

/* The mailbox path is shared with the catcher child on the command line, so it is chosen here and
   handed down unchanged; a child that re-randomised it would write where the parent never reads. */
$mailbox = BASE_PATH . '/storage/tmp/contact-smoke-' . bin2hex(random_bytes(6));
foreach ($argv ?? [] as $index => $argument) {
    if ($argument === '--mailbox' && isset($argv[$index + 1])) $mailbox = $argv[$index + 1];
}
$outFile = $mailbox . '.json';
$logFile = $mailbox . '.log';

/* ------------------------------------------------------------------ child: the mail catcher */
if (($argv[1] ?? '') === '--serve-mail') {
    file_put_contents($outFile, '[]');
    $server = stream_socket_server('tcp://127.0.0.1:' . (int) $argv[2], $errno, $errstr);
    if (!is_resource($server)) {
        file_put_contents($logFile, 'listen failed: ' . $errstr);
        exit(1);
    }
    file_put_contents($logFile, 'ready');
    $deadline = microtime(true) + 40;
    $messages = [];
    while (microtime(true) < $deadline && count($messages) < 8) {
        $read = [$server];
        $write = $except = null;
        if (stream_select($read, $write, $except, 1) < 1) continue;
        $connection = @stream_socket_accept($server, 2);
        if (!is_resource($connection)) continue;
        fwrite($connection, "220 tsg-test SMTP ready\r\n");
        $dataMode = false;
        $authStage = 0;
        $data = '';
        while (!feof($connection)) {
            $line = fgets($connection, 4096);
            if ($line === false) break;
            if ($dataMode) {
                if ($line === ".\r\n" || rtrim($line, "\r\n") === '.') {
                    $messages[] = $data;
                    fwrite($connection, "250 2.6.0 Message received\r\n");
                    $dataMode = false;
                    $data = '';
                } else {
                    $data .= $line;
                }
                continue;
            }
            $verb = strtoupper(substr(ltrim($line), 0, 4));
            if ($authStage === 1) { $authStage = 2; fwrite($connection, "334 VXNlcm5hbWU6\r\n"); continue; }
            if ($authStage === 2) { $authStage = 0; fwrite($connection, "235 2.7.0 Authentication successful\r\n"); continue; }
            $response = match (true) {
                $verb === 'EHLO' => "250-tsg-test greets you\r\n250 AUTH LOGIN PLAIN\r\n",
                $verb === 'HELO' => "250 tsg-test greets you\r\n",
                $verb === 'AUTH' => "334\r\n",
                $verb === 'DATA' => "354 Send message content\r\n",
                $verb === 'QUIT' => "221 2.0.0 closing connection\r\n",
                default => "250 2.5.0 Ok\r\n",
            };
            if ($verb === 'AUTH') $authStage = 1;
            fwrite($connection, $response);
            if ($verb === 'DATA') $dataMode = true;
            if ($verb === 'QUIT') break;
        }
        fclose($connection);
        file_put_contents($outFile, json_encode($messages));
    }
    exit(0);
}

/* ------------------------------------------------------------- scratch database guard */
$options = getopt('', ['database::', 'drop::']);
$dbName = (string) (($options['database'] ?? '') !== '' ? $options['database'] : (getenv('DB_NAME') ?: ''));
if (preg_match('/(tmp|check|scratch|test)/i', $dbName) !== 1) {
    fwrite(STDERR, "Refusing to run: --database=" . $dbName . " does not look like a scratch database.\n");
    exit(1);
}
putenv('DB_NAME=' . $dbName);

$mysql = '/Applications/XAMPP/xamppfiles/bin/mysql';
if (!is_executable($mysql)) $mysql = 'mysql';
$socket = '/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock';
$credentials = '-u root --socket=' . $socket;

/* -------------------------------------------------- child: run one contact form submission */
if (($argv[1] ?? '') === '--submit') {
    // The schema is already imported by the parent; this child only points the mail block at the
    // catcher and drives the controller. submitContact ends in a redirect and exit, which is why it
    // runs as its own process rather than inline in the harness.
    putenv('MAIL_HOST=127.0.0.1');
    putenv('MAIL_PORT=' . (int) $argv[2]);
    putenv('MAIL_ENCRYPTION=none');
    putenv('MAIL_USERNAME=catcher@test');
    putenv('MAIL_PASSWORD=test-password');
    putenv('MAIL_FROM_ADDRESS=academy@example.test');
    putenv('MAIL_FROM_NAME=TSG Academy Test');
    putenv('MAIL_REPLY_TO_ADDRESS=');

    $_SESSION = ['_csrf' => 'contact-flow-token'];
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [
        '_csrf' => 'contact-flow-token',
        'name' => 'Ayesha Khan',
        'email' => 'visitor@example.test',
        'phone' => '+92 300 1234567',
        'subject' => 'Live batch fee',
        'message' => 'Please share the fee and schedule for the next live batch.',
    ];
    (new PublicPageController())->submitContact();
    exit(0);
}

/* ------------------------------------------------------------------------ parent harness */
$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '  ok ' : '  FAIL ') . $message . "\n";
    if (!$condition) $failures++;
};

// A fresh scratch schema so the email_logs write has a home, imported once before any child runs.
shell_exec($mysql . ' ' . $credentials . ' -e ' . escapeshellarg('CREATE DATABASE IF NOT EXISTS `' . $dbName . '`'));
shell_exec($mysql . ' ' . $credentials . ' ' . escapeshellarg($dbName) . ' < ' . escapeshellarg(BASE_PATH . '/database/migrations/001_initial_schema.sql'));
shell_exec($mysql . ' ' . $credentials . ' ' . escapeshellarg($dbName) . ' -e ' . escapeshellarg("DELETE FROM lms_email_logs WHERE template_key LIKE 'contact.%'"));

// Reserve a free port, bring the catcher up, then wait until it announces it is listening.
$probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!is_resource($probe)) {
    fwrite(STDERR, 'FAIL: no loopback port could be reserved: ' . $errstr . "\n");
    exit(1);
}
$port = (int) parse_url(stream_socket_get_name($probe, false), PHP_URL_PORT);
fclose($probe);

$catcher = proc_open(
    [\PHP_BINARY, __FILE__, '--serve-mail', (string) $port, '--mailbox', $mailbox],
    [1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']],
    $pipes
);
$ready = false;
for ($i = 0; $i < 200; $i++) {
    if (is_file($logFile) && str_contains((string) file_get_contents($logFile), 'ready')) { $ready = true; break; }
    usleep(50000);
}
if (!$ready) {
    fwrite(STDERR, "FAIL: the loopback mail catcher never came up.\n");
    exit(1);
}

echo "\n-- contact submission on " . $dbName . "\n";
$submit = proc_open(
    [\PHP_BINARY, __FILE__, '--submit', (string) $port, '--database=' . $dbName],
    [1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']],
    $pipes
);
proc_close($submit);

// Read the recorded messages, waiting briefly for the second one to flush after the child returns.
$messages = [];
for ($i = 0; $i < 80; $i++) {
    $messages = json_decode((string) @file_get_contents($outFile), true) ?: [];
    if (count($messages) >= 2) break;
    usleep(100000);
}
if (is_resource($catcher)) { proc_terminate($catcher); proc_close($catcher); }

$assert(count($messages) === 2, 'One submission delivers exactly two messages, saw ' . count($messages) . '.');

// Read each recipient straight from its To: header, the way a mail client would.
$recipients = [];
foreach ($messages as $raw) {
    if (preg_match('/^To:\s*<?([^>\r\n]*)>/mi', (string) $raw, $match) === 1) {
        $recipients[] = strtolower(trim($match[1]));
    }
}
$assert(in_array('visitor@example.test', $recipients, true), 'The sender receives the acknowledgement.');
$assert(in_array('academy@example.test', $recipients, true), 'The company inbox receives the enquiry alert.');

// Both attempts must be recorded in email_logs, both as sent, tied to the contact templates.
$logs = [];
$savedMessages = [];
try {
    $pdo = new \PDO('mysql:unix_socket=' . $socket . ';dbname=' . $dbName . ';charset=utf8mb4', 'root', '', [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ]);
    $logs = $pdo->query("SELECT template_key, status FROM lms_email_logs WHERE template_key LIKE 'contact.%' ORDER BY id")->fetchAll();
    $savedMessages = $pdo->query('SELECT sender_name,sender_email,phone,subject,message FROM lms_contact_messages ORDER BY id DESC LIMIT 1')->fetchAll();
} catch (\Throwable $exception) {
    $assert(false, 'Contact submission records could be read: ' . $exception->getMessage());
}
$savedMessage = $savedMessages[0] ?? [];
$assert(($savedMessage['sender_name'] ?? '') === 'Ayesha Khan'
    && ($savedMessage['sender_email'] ?? '') === 'visitor@example.test'
    && ($savedMessage['subject'] ?? '') === 'Live batch fee'
    && ($savedMessage['message'] ?? '') === 'Please share the fee and schedule for the next live batch.',
    'The submitted contact details and message are saved for the admin inbox.');
$keys = array_column($logs, 'template_key');
$assert(in_array('contact.admin', $keys, true), 'The admin alert is recorded in email_logs.');
$assert(in_array('contact.acknowledgement', $keys, true), 'The sender acknowledgement is recorded in email_logs.');
$assert($logs !== [] && !in_array('failed', array_column($logs, 'status'), true), 'No contact delivery was logged as failed.');

foreach ([$outFile, $logFile] as $scratch) { @unlink($scratch); }
if (($options['drop'] ?? '') === '1') {
    shell_exec($mysql . ' ' . $credentials . ' -e ' . escapeshellarg('DROP DATABASE IF EXISTS `' . $dbName . '`'));
    echo "\ndropped " . $dbName . "\n";
}

if ($failures === 0) {
    echo "\nPASS: the contact form reaches the company inbox and the sender on " . $dbName . "\n";
    exit(0);
}
echo "\nFAILED: " . $failures . " contact-flow check(s) did not pass.\n";
exit(1);
