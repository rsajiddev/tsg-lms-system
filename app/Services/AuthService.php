<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\RateLimiter;
use PDO;
use PDOException;
use RuntimeException;

final class AuthService
{
    private RateLimiter $limiter;

    public function __construct(private PDO $db, private MailService $mail)
    {
        $this->limiter = new RateLimiter($db);
    }

    public function register(string $name, string $email, string $password): bool
    {
        $this->validateIdentity($name, $email);
        if (strlen($password) < 12 || strlen($password) > 4096) {
            throw new RuntimeException('Use a password between 12 and 4096 characters.');
        }
        $subjects = $this->emailIpSubjects($email);
        $this->limiter->assertAllowed('register', $subjects, $this->setting('register_max_attempts', 5), $this->setting('register_window_minutes', 60) * 60);
        $this->limiter->record('register', $subjects);

        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare("INSERT INTO users (full_name, email, password_hash, role, status) VALUES (?, ?, ?, 'student', 'pending')");
            $stmt->execute([$name, strtolower($email), password_hash($password, PASSWORD_DEFAULT)]);
            $userId = (int) $this->db->lastInsertId();
            $code = $this->issueOtp($userId, 'verify_email');
            $this->db->commit();
        } catch (PDOException $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ((string) $exception->getCode() === '23000') return false;
            throw new RuntimeException('Registration could not be completed. Please try again later.');
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $exception;
        }
        $studentEmailSent = $this->mail->sendOtp(strtolower($email), $code, 'verify_email', $this->setting('otp_lifetime_seconds', 600));
        $this->mail->sendNewStudentAlert($name, strtolower($email));
        return $studentEmailSent;
    }

    public function verifyEmail(string $email, string $code): void
    {
        $this->verifyOtp($email, $code, 'verify_email', true);
    }

    public function resendVerification(string $email): bool
    {
        $email = strtolower(trim($email));
        $subjects = $this->emailIpSubjects($email);
        $this->limiter->assertAllowed('otp_resend', $subjects, $this->setting('otp_resends_per_window', 3), $this->setting('otp_resend_window_minutes', 15) * 60);
        $this->limiter->record('otp_resend', $subjects);
        $query = $this->db->prepare("SELECT id FROM users WHERE email = ? AND role = 'student' AND status = 'pending' LIMIT 1");
        $query->execute([$email]);
        $userId = $query->fetchColumn();
        if (!$userId) return false;
        $this->assertOtpCooldown((int) $userId, 'verify_email');
        $code = $this->issueOtp((int) $userId, 'verify_email');
        return $this->mail->sendOtp($email, $code, 'verify_email', $this->setting('otp_lifetime_seconds', 600));
    }

    public function resendSecondsRemaining(string $email, string $purpose = 'verify_email'): int
    {
        $email = strtolower(trim($email));
        $stmt = $this->db->prepare('SELECT eo.created_at FROM email_otps eo JOIN users u ON u.id=eo.user_id WHERE u.email=? AND eo.purpose=? ORDER BY eo.id DESC LIMIT 1');
        $stmt->execute([$email, $purpose]);
        $created = $stmt->fetchColumn();
        if (!$created) return 0;
        return max(0, $this->setting('otp_lifetime_seconds', 600) - (time() - (int)strtotime((string)$created . ' UTC')));
    }

    public function login(string $email, string $password): array
    {
        $email = strtolower(trim($email));
        $subjects = $this->emailIpSubjects($email);
        try {
            $this->limiter->assertAllowed('login_fail', $subjects, $this->setting('login_max_attempts', 5), $this->setting('login_lockout_minutes', 15) * 60);
        } catch (RuntimeException $exception) {
            $studentId = null;
            try {
                $blockedAccount = $this->db->prepare("SELECT id FROM users WHERE email = ? AND role = 'student' LIMIT 1");
                $blockedAccount->execute([$email]);
                $studentId = $blockedAccount->fetchColumn();
                $studentId = $studentId === false ? null : (int) $studentId;
            } catch (PDOException) {
                // Authentication remains generic when the lookup is unavailable.
            }
            $this->recordLoginEvent($studentId, 'blocked');
            throw $exception;
        }
        $query = $this->db->prepare('SELECT id, full_name, email, password_hash, role, status, email_verified_at FROM users WHERE email = ? LIMIT 1');
        $query->execute([$email]);
        $user = $query->fetch();
        $hash = $user['password_hash'] ?? password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $valid = password_verify($password, $hash);
        if (!$user || !$valid || $user['status'] !== 'active' || !$user['email_verified_at'] || !in_array($user['role'], ['student', 'admin'], true)) {
            $this->limiter->record('login_fail', $subjects);
            $this->recordLoginEvent($user && $user['role'] === 'student' ? (int) $user['id'] : null, 'failed');
            throw new RuntimeException('Email or password is incorrect.');
        }
        $this->limiter->clear('login_fail', $subjects);
        $this->db->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$user['id']]);
        if ($user['role'] === 'student') $this->recordLoginEvent((int) $user['id'], 'success');
        unset($user['password_hash'], $user['status'], $user['email_verified_at']);
        return $user;
    }

    /** Login must remain available during a rolling schema import; history logging fails open. */
    private function recordLoginEvent(?int $userId, string $eventType): void
    {
        try {
            $stmt = $this->db->prepare('INSERT INTO login_events (user_id, event_type, ip_address, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())');
            $ip = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ? $_SERVER['REMOTE_ADDR'] : null;
            $stmt->execute([$userId, $eventType, $ip]);
        } catch (PDOException $exception) {
            // Keep authentication working if the updated canonical schema has not been imported yet.
            error_log('[AUTH] Login event could not be recorded; check the login_events schema.');
        }
    }

    public function requestPasswordReset(string $email): bool
    {
        $email = strtolower(trim($email));
        $subjects = $this->emailIpSubjects($email);
        $this->limiter->assertAllowed('reset_request', $subjects, $this->setting('password_reset_max_attempts', 3), $this->setting('password_reset_window_minutes', 15) * 60);
        $this->limiter->record('reset_request', $subjects);
        $query = $this->db->prepare("SELECT id FROM users WHERE email = ? AND status = 'active' AND email_verified_at IS NOT NULL LIMIT 1");
        $query->execute([$email]);
        $userId = $query->fetchColumn();
        if (!$userId) return false;
        $this->assertOtpCooldown((int) $userId, 'reset_password');
        $code = $this->issueOtp((int) $userId, 'reset_password');
        return $this->mail->sendOtp($email, $code, 'reset_password', $this->setting('otp_lifetime_seconds', 600));
    }

    public function resetPassword(string $email, string $code, string $password): void
    {
        if (strlen($password) < 12 || strlen($password) > 4096) {
            throw new RuntimeException('Use a password between 12 and 4096 characters.');
        }
        $this->verifyOtp($email, $code, 'reset_password', false, password_hash($password, PASSWORD_DEFAULT));
    }

    private function issueOtp(int $userId, string $purpose): string
    {
        $code = (string) random_int(100000, 999999);
        $this->db->prepare('UPDATE email_otps SET consumed_at = UTC_TIMESTAMP() WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL')->execute([$userId, $purpose]);
        $lifetime = $this->setting('otp_lifetime_seconds', 600);
        $stmt = $this->db->prepare('INSERT INTO email_otps (user_id, purpose, otp_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND))');
        $stmt->execute([$userId, $purpose, password_hash($code, PASSWORD_DEFAULT), $lifetime]);
        return $code;
    }

    private function verifyOtp(string $email, string $code, string $purpose, bool $activate, ?string $newPasswordHash = null): int
    {
        $email = strtolower(trim($email));
        if (!preg_match('/^\d{6}$/', $code)) throw new RuntimeException('The code is invalid or expired.');
        $this->db->beginTransaction();
        try {
            $userQuery = $this->db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1 FOR UPDATE');
            $userQuery->execute([$email]);
            $userId = (int) $userQuery->fetchColumn();
            if (!$userId) throw new RuntimeException('The code is invalid or expired.');
            $otpQuery = $this->db->prepare('SELECT id, otp_hash, attempts FROM email_otps WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP() ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $otpQuery->execute([$userId, $purpose]);
            $otp = $otpQuery->fetch();
            $maxAttempts = $this->setting('otp_max_attempts', 5);
            if (!$otp || (int) $otp['attempts'] >= $maxAttempts || !password_verify($code, $otp['otp_hash'])) {
                if ($otp) {
                    $this->db->prepare('UPDATE email_otps SET attempts = attempts + 1, consumed_at = IF(attempts + 1 >= ?, UTC_TIMESTAMP(), consumed_at) WHERE id = ?')->execute([$maxAttempts, $otp['id']]);
                }
                $this->db->commit();
                throw new RuntimeException('The code is invalid or expired.');
            }
            $this->db->prepare('UPDATE email_otps SET consumed_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$otp['id']]);
            if ($activate) {
                $this->db->prepare("UPDATE users SET email_verified_at = UTC_TIMESTAMP(), status = 'active' WHERE id = ?")->execute([$userId]);
            }
            if ($newPasswordHash !== null) {
                $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$newPasswordHash, $userId]);
            }
            $this->db->commit();
            return $userId;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $exception;
        }
    }

    private function assertOtpCooldown(int $userId, string $purpose): void
    {
        $stmt = $this->db->prepare('SELECT created_at FROM email_otps WHERE user_id = ? AND purpose = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId, $purpose]);
        $created = $stmt->fetchColumn();
        if ($created && strtotime((string) $created . ' UTC') > time() - $this->setting('otp_lifetime_seconds', 600)) {
            throw new RuntimeException('Please wait before requesting another code.');
        }
    }

    private function setting(string $key, int $fallback): int
    {
        try {
            $stmt = $this->db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            return $value !== false && ctype_digit((string) $value) ? max(1, (int) $value) : $fallback;
        } catch (PDOException) {
            return $fallback;
        }
    }

    private function validateIdentity(string $name, string $email): void
    {
        if (mb_strlen(trim($name)) < 2 || mb_strlen(trim($name)) > 150) {
            throw new RuntimeException('Enter a name between 2 and 150 characters.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            throw new RuntimeException('Enter a valid email address.');
        }
    }

    private function emailIpSubjects(string $email): array
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        return ['email:' . strtolower(trim($email)), 'ip:' . $ip];
    }
}
