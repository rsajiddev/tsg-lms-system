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

    /** Messages the catcher recorded, as [recipient, subject, decoded body] rows. */
    $delivered = static function () use ($outFile): array {
        $decoded = [];
        foreach ((array) json_decode((string) file_get_contents($outFile), true) as $raw) {
            $headers = [];
            foreach (preg_split("/\r\n/", (string) $raw) ?: [] as $line) {
                if ($line === '') break;
                if (preg_match('/^([A-Za-z-]+):\s*(.*)$/', $line, $parts)) {
                    $headers[strtolower($parts[1])][] = $parts[2];
                }
            }
            $body = '';
            foreach (preg_split('/\r?\n/', (string) $raw) ?: [] as $line) {
                if (preg_match('/^[A-Za-z0-9+\/=]{16,}$/', trim($line))) $body .= trim($line);
            }
            $plain = (string) base64_decode($body, false);
            $recipient = '';
            foreach ($headers['to'] ?? [] as $value) {
                if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+/i', $value, $match)) $recipient = $match[0];
            }
            $subject = '';
            foreach ($headers['subject'] ?? [] as $value) {
                if (preg_match_all('/=\?[^?]*\?B\?([A-Za-z0-9+\/=]+)\?=/', $value, $parts)) {
                    $subject .= implode('', array_map(static fn(string $chunk): string => (string) base64_decode($chunk), $parts[1]));
                } else {
                    $subject .= $value;
                }
            }
            $decoded[] = ['to' => $recipient, 'subject' => $subject, 'body' => $plain];
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
    $codeFrom = static function (array $messages, string $needle): string {
        foreach ($messages as $message) {
            if (preg_match('/\b(\d{6})\b/', (string) $message['body'], $match) && stripos((string) $message['body'], $needle) !== false) {
                return $match[1];
            }
        }
        throw new RuntimeException('No delivered message carried a six-digit code for "' . $needle . '".');
    };

    try {
        $auth->register($name, $email, 'Correct Horse Battery 9!');
        $userQuery = $db->prepare('SELECT id, status, email_verified_at FROM users WHERE email = ?');
        $userQuery->execute([$email]);
        $user = $userQuery->fetch();
        $assert(is_array($user) && $user['status'] === 'pending' && $user['email_verified_at'] === null, 'Registration must create a pending student.');
        $userId = (int) $user['id'];

        // Registration is a three-mail event: the student's code, the HR alert and the developer alert.
        $messages = $waitFor(3);
        $assert(count($messages) === 3, 'Registration should deliver three messages, saw ' . count($messages) . '.');
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
        $auth->resetPassword($email, $codeFrom($resetMessages, 'reset the password'), 'New Correct Horse 8!');
        $auth->login($email, 'New Correct Horse 8!');
        $expectFailure(static fn() => $auth->login($email, 'Correct Horse Battery 9!'), 'Email or password is incorrect');

        $mailCount = count($delivered());
        $assert($auth->register($name, $email, 'Another Correct Horse 8!') === false, 'Duplicate registration must be rejected.');
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

        echo "Auth smoke checks passed: registration, loopback SMTP delivery, OTP activation, replay rejection, login, password reset, duplicate rejection, email log and rate limiting.\n";
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
