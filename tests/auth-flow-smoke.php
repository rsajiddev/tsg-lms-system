<?php
declare(strict_types=1);

namespace App\Services {
    function mail(string $to, string $subject, string $message, string $headers = ''): bool
    {
        $GLOBALS['_auth_test_messages'][] = ['to' => $to, 'subject' => $subject, 'message' => $message];
        return true;
    }
}

namespace {
    define('BASE_PATH', dirname(__DIR__));
    require BASE_PATH . '/app/Core/Env.php';
    require BASE_PATH . '/app/Core/Database.php';
    require BASE_PATH . '/app/Core/RateLimiter.php';
    require BASE_PATH . '/app/Services/MailService.php';
    require BASE_PATH . '/app/Services/AuthService.php';
    \App\Core\Env::load(BASE_PATH . '/.env');
    putenv('MAIL_FROM_ADDRESS=academy@example.test');
    $_SERVER['REMOTE_ADDR'] = '203.0.113.10';

    $db = \App\Core\Database::connection();
    $auth = new \App\Services\AuthService($db, new \App\Services\MailService());
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
    $codeFromLatestMail = static function (): string {
        $last = end($GLOBALS['_auth_test_messages']);
        if (!is_array($last) || !preg_match('/\b(\d{6})\b/', $last['message'], $matches)) {
            throw new RuntimeException('Test mail did not include an OTP.');
        }
        return $matches[1];
    };

    try {
        $auth->register($name, $email, 'Correct Horse Battery 9!');
        $userQuery = $db->prepare('SELECT id, status, email_verified_at FROM users WHERE email = ?');
        $userQuery->execute([$email]);
        $user = $userQuery->fetch();
        $assert(is_array($user) && $user['status'] === 'pending' && $user['email_verified_at'] === null, 'Registration must create a pending student.');
        $userId = (int) $user['id'];
        $assert(count($GLOBALS['_auth_test_messages'] ?? []) === 1, 'Registration should send one verification message.');

        $verificationCode = $codeFromLatestMail();
        $auth->verifyEmail($email, $verificationCode);
        $userQuery->execute([$email]);
        $user = $userQuery->fetch();
        $assert($user['status'] === 'active' && $user['email_verified_at'] !== null, 'Valid OTP must activate the account.');
        $expectFailure(fn() => $auth->verifyEmail($email, $verificationCode), 'invalid or expired');

        $loggedIn = $auth->login($email, 'Correct Horse Battery 9!');
        $assert($loggedIn['role'] === 'student' && (int) $loggedIn['id'] === $userId, 'Verified student should be able to sign in.');
        $expectFailure(fn() => $auth->login($email, 'incorrect password'), 'Email or password is incorrect');

        $auth->requestPasswordReset($email);
        $resetCode = $codeFromLatestMail();
        $auth->resetPassword($email, $resetCode, 'New Correct Horse 8!');
        $auth->login($email, 'New Correct Horse 8!');
        $expectFailure(fn() => $auth->login($email, 'Correct Horse Battery 9!'), 'Email or password is incorrect');

        $mailCount = count($GLOBALS['_auth_test_messages']);
        $auth->register($name, $email, 'Another Correct Horse 8!');
        $assert(count($GLOBALS['_auth_test_messages']) === $mailCount, 'Duplicate registration must not send another OTP.');

        $rateLimiter = new \App\Core\RateLimiter($db);
        $rateSubjects = ['auth-smoke-rate-limit'];
        $rateLimiter->clear('auth_smoke', $rateSubjects);
        for ($i = 0; $i < 5; $i++) $rateLimiter->record('auth_smoke', $rateSubjects);
        $expectFailure(fn() => $rateLimiter->assertAllowed('auth_smoke', $rateSubjects, 5, 3600), 'Too many requests');
        $rateLimiter->clear('auth_smoke', $rateSubjects);

        echo "Auth smoke checks passed: registration, OTP activation/replay rejection, login, password reset, duplicate registration, and rate limiting.\n";
    } finally {
        if ($userId !== null) {
            $db->prepare('DELETE FROM email_otps WHERE user_id = ?')->execute([$userId]);
            $db->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        }
        $hashes = [hash('sha256', 'email:' . strtolower($email)), hash('sha256', 'ip:' . $_SERVER['REMOTE_ADDR'])];
        $cleanup = $db->prepare('DELETE FROM auth_rate_limits WHERE subject_hash = ?');
        foreach ($hashes as $hash) $cleanup->execute([$hash]);
    }
}
