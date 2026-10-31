<?php
declare(strict_types=1);

/**
 * Authentication flow smoke test.
 *
 * Run against the local development database:  /Applications/XAMPP/xamppfiles/bin/php tests/auth-flow-smoke.php
 *
 * Mail no longer goes through PHP's mail() function, so this test cannot fake delivery with a
 * namespaced stub. Instead it starts a throwaway SMTP server on the loopback interface, points
 * MAIL_* at it, and reads the messages the application actually puts on the wire. That proves
 * the whole pipeline a company would notice when it breaks: the handshake, the login, the MIME
 * envelope, the template behind the OTP and the email_logs row written next to it. Nothing here
 * ever reaches the internet or a real mailbox.
 */

namespace {
    define('BASE_PATH', dirname(__DIR__));
    require BASE_PATH . '/app/Core/Env.php';
    require BASE_PATH . '/app/Core/Database.php';
    require BASE_PATH . '/app/Core/RateLimiter.php';
    require BASE_PATH . '/app/Services/EmailLayout.php';
    require BASE_PATH . '/app/Services/EmailMessages.php';
    require BASE_PATH . '/app/Services/AccountIdentity.php';
    require BASE_PATH . '/app/Services/MailService.php';
    require BASE_PATH . '/app/Services/AttendanceService.php';
    require BASE_PATH . '/app/Services/AuthService.php';
    \App\Core\Env::load(BASE_PATH . '/.env');

    /* ------------------------------------------------------------- loopback mail catcher */
    // The catcher's scratch files live under storage/ so they are cleaned up with the project.
    if (!is_dir(BASE_PATH . '/storage/tmp')) {
        @mkdir(BASE_PATH . '/storage/tmp', 0700, true);
    }
    // The parent picks a fresh mailbox path; the child is handed the SAME path on the command
    // line, because a child that re-randomised it would write to files the parent never reads.
    $mailbox = BASE_PATH . '/storage/tmp/auth-smoke-' . bin2hex(random_bytes(6));
    foreach ($argv ?? [] as $index => $argument) {
        if ($argument === '--mailbox' && isset($argv[$index + 1])) $mailbox = $argv[$index + 1];
    }
    $outFile = $mailbox . '.json';
    $logFile = $mailbox . '.log';
    file_put_contents($outFile, '[]');

    if (($argv[1] ?? '') === '--serve-mail') {
        // Child process: answer one SMTP conversation per connection and record every message.
        $server = stream_socket_server('tcp://127.0.0.1:' . (int) $argv[2], $errno, $errstr);
        if (!is_resource($server)) {
            file_put_contents($logFile, 'listen failed: ' . $errstr);
            exit(1);
        }
        file_put_contents($logFile, 'ready');
        $deadline = microtime(true) + 60;
        $messages = [];
        while (microtime(true) < $deadline && count($messages) < 12) {
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
                // AUTH LOGIN is a three-step exchange: the challenge, then the base64 username,
                // then the base64 password. MailService expects 334, 334 and 235 in that order.
                if ($authStage === 1) { $authStage = 2; fwrite($connection, "334 VXNlcm5hbWU6\r\n"); continue; }
                if ($authStage === 2) { $authStage = 0; fwrite($connection, "235 2.7.0 Authentication successful\r\n"); continue; }
                $response = match (true) {
                    $verb === 'EHLO' => "250-tsg-test greets you\r\n250 AUTH LOGIN PLAIN\r\n",
                    $verb === 'HELO' => "250 tsg-test greets you\r\n",
                    $verb === 'AUTH' => ("334\r\n"),
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

    $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!is_resource($server)) {
        fwrite(STDERR, 'FAIL: the loopback mail catcher could not start: ' . $errstr . "\n");
        exit(1);
    }
    $port = (int) parse_url(stream_socket_get_name($server, false), PHP_URL_PORT);
    fclose($server);
    putenv('MAIL_HOST=127.0.0.1');
    putenv('MAIL_PORT=' . $port);
    putenv('MAIL_ENCRYPTION=none');
    putenv('MAIL_USERNAME=catcher@test');
    putenv('MAIL_PASSWORD=test-password');
    putenv('MAIL_FROM_ADDRESS=academy@example.test');
    putenv('MAIL_FROM_NAME=TSG Academy Test');
    putenv('MAIL_REPLY_TO_ADDRESS=');
    putenv('MAIL_ADMIN_NOTIFICATION_ADDRESS=hr@example.test');
    putenv('DEVELOPER_NOTIFICATION_MAIL_ADDRESS=dev@example.test');
    $child = proc_open(
        [\PHP_BINARY, __FILE__, '--serve-mail', (string) $port, '--mailbox', $mailbox],
        [1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']],
        $pipes
    );
    $waitReady = static function () use ($logFile): bool {
        for ($i = 0; $i < 200; $i++) {
            if (is_file($logFile) && str_contains((string) file_get_contents($logFile), 'ready')) return true;
            usleep(50000);
        }
        return false;
    };
    if (!$waitReady()) {
        fwrite(STDERR, "FAIL: the loopback mail catcher never came up.\n");
        exit(1);
    }

    /** Messages the catcher recorded, read the way a mail client reads them. */
    $delivered = static function () use ($outFile): array {
        $decoded = [];
        foreach ((array) json_decode((string) file_get_contents($outFile), true) as $raw) {
            $raw = (string) $raw;
            // A mail client accepts only a real CRLF as a line break, so the header block ends at the
            // first blank line and every MIME part has to carry its own headers, a blank line and a
            // body that strictly decodes. Reading anything looser here would hide a malformed
            // message behind a lucky regular-expression match, which is exactly how the base64
            // part-headers once slipped past this file.
            [$headBlock, $bodyBlock] = array_pad(preg_split("/\r\n\r\n/", $raw, 2) ?: [], 2, '');
            $mimeOk = $headBlock !== '' && $bodyBlock !== '' && !str_contains($raw, '\\r\\n');
            $headers = [];
            $lastHeader = '';
            foreach (preg_split("/\r\n/", $headBlock) ?: [] as $line) {
                if ($line === '') continue;
                if (preg_match('/^[ \t](.*)$/', $line, $continuation)) {
                    // A folded header continues the one above it - mb_encode_mimeheader folds any
                    // Subject long enough to need it.
                    if ($lastHeader !== '') $headers[$lastHeader][] = ' ' . $continuation[1];
                    continue;
                }
                if (!preg_match('/^([A-Za-z-]+):[ \t]*(.*)$/', $line, $parts) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $parts[2])) {
                    $mimeOk = false;
                    continue;
                }
                $lastHeader = strtolower($parts[1]);
                $headers[$lastHeader][] = $parts[2];
            }
            $boundary = '';
            foreach ($headers['content-type'] ?? [] as $value) {
                if (preg_match('/boundary="([^"]+)"/', $value, $parts)) $boundary = $parts[1];
            }
            $parts = [];
            foreach ($boundary === '' ? [] : array_slice(explode('--' . $boundary, $bodyBlock), 1) as $segment) {
                $segment = trim($segment, "\r\n");
                if ($segment === '' || $segment === '--') continue;
                $pieces = preg_split("/\r\n\r\n/", $segment, 2) ?: [];
                if (count($pieces) !== 2 || !preg_match('/^Content-Type: (text\/(?:plain|html)); charset=UTF-8\r?$/m', $pieces[0], $type) || !str_contains($pieces[0], 'Content-Transfer-Encoding: base64')) {
                    $mimeOk = false;
                    continue;
                }
                $text = base64_decode(trim($pieces[1]), true);
                if ($text === false) {
                    $mimeOk = false;
                    continue;
                }
                $parts[$type[1]] = $text;
            }
            if (!isset($parts['text/plain'], $parts['text/html']) || !str_ends_with(rtrim($bodyBlock, "\r\n"), '--' . $boundary . '--')) $mimeOk = false;
            $recipient = '';
            foreach ($headers['to'] ?? [] as $value) {
                if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+/i', $value, $match)) $recipient = $match[0];
            }
            $subject = '';
            foreach ($headers['subject'] ?? [] as $value) {
                if (preg_match_all('/=\?[^?]*\?B\?([A-Za-z0-9+\/=]+)\?=/', $value, $chunks)) {
                    $subject .= implode('', array_map(static fn(string $chunk): string => (string) base64_decode($chunk), $chunks[1]));
                } else {
                    $subject .= $value;
                }
            }
            $decoded[] = ['to' => $recipient, 'subject' => $subject, 'body' => (string) ($parts['text/plain'] ?? ''), 'html' => (string) ($parts['text/html'] ?? ''), 'mime_ok' => $mimeOk];
        }
        return $decoded;
    };
    $waitFor = static function (int $count) use ($delivered): array {
        for ($i = 0; $i < 100; $i++) {
            $messages = $delivered();
            if (count($messages) >= $count) return $messages;
            usleep(50000);
        }
        return $delivered();
    };

    $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
    $db = \App\Core\Database::connection();
    $auth = new \App\Services\AuthService($db, new \App\Services\MailService($db));
    $email = 'auth-smoke-' . bin2hex(random_bytes(6)) . '@example.test';
    $name = 'Auth Flow Smoke Test';
    $userId = null;
    $assert = static function (bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException($message);
    };
    $expectFailure = static function (callable $operation, string $contains): void {
        try {
            $operation();
        } catch (RuntimeException $exception) {
            if (str_contains($exception->getMessage(), $contains)) return;
            throw $exception;
        }
        throw new RuntimeException('Expected operation to fail.');
    };
    /** Every message so far must be a well-formed multipart mail, not just a decodable blob. */
    $assertMime = static function (array $messages, string $stage) use ($assert): void {
        foreach ($messages as $index => $message) {
            $assert((bool) ($message['mime_ok'] ?? false), $stage . ' delivered a message a mail client cannot render (message ' . ($index + 1) . ', ' . (string) ($message['subject'] ?? '') . ').');
            $assert(str_contains((string) ($message['html'] ?? ''), '<html'), $stage . ' delivered a message with no HTML part a mail client can show (' . (string) ($message['subject'] ?? '') . ').');
        }
    };
    $codeFrom = static function (array $messages, string $needle): string {
        foreach ($messages as $message) {
            if (preg_match('/\b(\d{6})\b/', (string) $message['body'], $match) && stripos((string) $message['body'], $needle) !== false) {
                return $match[1];
            }
        }
        throw new RuntimeException('No delivered message carried a six-digit code for "' . $needle . '".');
    };

    try {
        $registration = $auth->register($name, $email, 'Correct Horse Battery 9!');
        $assert($registration === ['created' => true, 'mail' => true],
            'Registration reports the account and the mail separately instead of collapsing both into one boolean.');
        $userQuery = $db->prepare('SELECT id, status, email_verified_at FROM users WHERE email = ?');
        $userQuery->execute([$email]);
        $user = $userQuery->fetch();
        $assert(is_array($user) && $user['status'] === 'pending' && $user['email_verified_at'] === null, 'Registration must create a pending student.');
        $userId = (int) $user['id'];

        // Registration is a three-mail event: the student's code, the HR alert and the developer alert.
        $messages = $waitFor(3);
        $assert(count($messages) === 3, 'Registration should deliver three messages, saw ' . count($messages) . '.');
        $assertMime($messages, 'Registration');
        $students = array_values(array_filter($messages, static fn(array $m): bool => $m['to'] === $email));
        $assert(count($students) === 1, 'Exactly one message must go to the registering student.');
        $assert(str_contains(strtolower((string) $students[0]['subject']), 'verify')
            || str_contains(strtolower((string) $students[0]['subject']), 'code')
            || str_contains(strtolower((string) $students[0]['subject']), 'confirm'),
            'The student message is not recognisable as a verification mail: "' . $students[0]['subject'] . '".');
        $recipients = array_map(static fn(array $m): string => $m['to'], $messages);
        $assert(in_array('hr@example.test', $recipients, true), 'HR did not get the new student alert: ' . json_encode($recipients));
        $assert(in_array('dev@example.test', $recipients, true), 'The developer did not get the new student alert: ' . json_encode($recipients));

        $verificationCode = $codeFrom($students, 'confirm your email address');
        $auth->verifyEmail($email, $verificationCode);
        $userQuery->execute([$email]);
        $user = $userQuery->fetch();
        $assert($user['status'] === 'active' && $user['email_verified_at'] !== null, 'Valid OTP must activate the account.');
        $expectFailure(static fn() => $auth->verifyEmail($email, $verificationCode), 'invalid or expired');

        $loggedIn = $auth->login($email, 'Correct Horse Battery 9!');
        $assert($loggedIn['role'] === 'student' && (int) $loggedIn['id'] === $userId, 'Verified student should be able to sign in.');
        $expectFailure(static fn() => $auth->login($email, 'incorrect password'), 'Email or password is incorrect');

        $before = count($delivered());
        $auth->requestPasswordReset($email);
        $resetMessages = $waitFor($before + 1);
        $assertMime($resetMessages, 'The password reset');
        $auth->resetPassword($email, $codeFrom($resetMessages, 'reset the password'), 'New Correct Horse 8!');
        $auth->login($email, 'New Correct Horse 8!');
        $expectFailure(static fn() => $auth->login($email, 'Correct Horse Battery 9!'), 'Email or password is incorrect');

        $mailCount = count($delivered());
        // A duplicate and an undeliverable code used to be the same `false`, which is why the form
        // told a returning student to go and configure SMTP. They are two flags now.
        $duplicate = $auth->register($name, $email, 'Another Correct Horse 8!');
        $assert($duplicate === ['created' => false, 'mail' => false], 'Duplicate registration must report no account and no mail.');
        $assert(count($delivered()) === $mailCount, 'Duplicate registration must not send another OTP.');

        $logs = $db->query("SELECT template_key, status, related_user_id FROM email_logs WHERE recipient_email IN (" . $db->quote($email) . ",'hr@example.test','dev@example.test') ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
        $assert(count($logs) >= 4, 'Every delivered message must leave an email_logs row; saw ' . count($logs) . '.');
        $assert(!in_array('failed', array_column($logs, 'status'), true), 'email_logs recorded a failed delivery.');
        // Even the HR and developer copies of a student's event belong to that student in the
        // log, otherwise the admin workspace cannot show one account's full mail history.
        $unlinked = array_values(array_filter($logs, static fn(array $row): bool => (int) $row['related_user_id'] !== $userId));
        $assert($unlinked === [], 'Every email_logs row for this event must name the student; unlinked: ' . json_encode(array_column($unlinked, 'template_key')));

        $rateLimiter = new \App\Core\RateLimiter($db);
        $rateSubjects = ['auth-smoke-rate-limit'];
        $rateLimiter->clear('auth_smoke', $rateSubjects);
        for ($i = 0; $i < 5; $i++) $rateLimiter->record('auth_smoke', $rateSubjects);
        $expectFailure(static fn() => $rateLimiter->assertAllowed('auth_smoke', $rateSubjects, 5, 3600), 'Too many requests');
        $rateLimiter->clear('auth_smoke', $rateSubjects);

        // The wait for a new code is its own interval now: against a long code lifetime, a student
        // who cannot see an email used to be locked out for ten minutes and told nothing was wrong.
        // This suite runs against whatever values the development database holds, so it only proves
        // the row is seeded and that the browser never gets a countdown longer than that row. The
        // discriminating check, lifetime and cooldown deliberately set apart, lives in
        // enrol-flow-smoke.php, which owns a scratch database and can pick both numbers.
        $rowOf = static function (string $key) use ($db) {
            $statement = $db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
            $statement->execute([$key]);
            $value = $statement->fetchColumn();
            return $value === false ? null : (int) $value;
        };
        $configuredCooldown = $rowOf('otp_resend_cooldown_seconds');
        $assert($configuredCooldown !== null && $configuredCooldown > 0,
            'The schema seeds a separate resend interval (' . $configuredCooldown . 's).',
            'app_settings has no otp_resend_cooldown_seconds row; import database/migrations/001_initial_schema.sql so the resend wait is stored separately from the code lifetime.');
        // The reset code requested earlier in this run is still fresh, so its remaining wait is the
        // number the browser would be handed. Asking for another one here would trip the very
        // cooldown being measured.
        $resetWait = $auth->resendSecondsRemaining($email, 'reset_password');
        $assert($resetWait > 0 && $resetWait <= (int) $configuredCooldown,
            'The countdown handed to the browser runs on the resend wait, so it can never outlive the interval the server enforces.',
            'resendSecondsRemaining() returned ' . $resetWait . ' against a configured cooldown of ' . $configuredCooldown . '.');

        echo "Auth smoke checks passed: registration, loopback SMTP delivery, OTP activation, replay rejection, login, password reset, duplicate rejection, email log, rate limiting and the separate resend interval.\n";
        $status = 0;
    } catch (Throwable $exception) {
        fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . "\n");
        $status = 1;
    } finally {
        if (is_resource($child)) {
            proc_terminate($child);
            proc_close($child);
        }
        if ($userId !== null) {
            // Sign-in leaves attendance and login-event rows that reference the student, so they
            // must go before the account itself can be removed from the development database.
            $childColumns = [
                'attendance_records' => 'student_id',
                'login_events' => 'user_id',
                'email_otps' => 'user_id',
                'email_logs' => 'related_user_id',
            ];
            foreach ($childColumns as $child => $column) {
                try {
                    $db->prepare('DELETE FROM ' . $child . ' WHERE ' . $column . ' = ?')->execute([$userId]);
                } catch (PDOException $exception) {
                    if ((int) ($exception->errorInfo[1] ?? 0) !== 1146) throw $exception;
                }
            }
            $db->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        }
        $hashes = [hash('sha256', 'email:' . strtolower($email)), hash('sha256', 'ip:' . $_SERVER['REMOTE_ADDR'])];
        $cleanup = $db->prepare('DELETE FROM auth_rate_limits WHERE subject_hash = ?');
        foreach ($hashes as $hash) $cleanup->execute([$hash]);
        @unlink($outFile);
        @unlink($logFile);
    }

    exit($status);
}
