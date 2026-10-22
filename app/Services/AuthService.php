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
        $this->limiter->assertAllowed('register', $subjects, 5, 3600);
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
        $studentEmailSent = $this->mail->sendOtp(strtolower($email), $code, 'verify_email');
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
        $this->limiter->assertAllowed('otp_resend', $subjects, 3, 900);
        $this->limiter->record('otp_resend', $subjects);
        $query = $this->db->prepare("SELECT id FROM users WHERE email = ? AND role = 'student' AND status = 'pending' LIMIT 1");
        $query->execute([$email]);
        $userId = $query->fetchColumn();
        if (!$userId) return false;
        $this->assertOtpCooldown((int) $userId, 'verify_email');
        $code = $this->issueOtp((int) $userId, 'verify_email');
        return $this->mail->sendOtp($email, $code, 'verify_email');
    }

    public function login(string $email, string $password): array
    {
        $email = strtolower(trim($email));
        $subjects = $this->emailIpSubjects($email);
        $this->limiter->assertAllowed('login_fail', $subjects, 5, 900);
        $query = $this->db->prepare('SELECT id, full_name, email, password_hash, role, status, email_verified_at FROM users WHERE email = ? LIMIT 1');
        $query->execute([$email]);
        $user = $query->fetch();
        $hash = $user['password_hash'] ?? password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $valid = password_verify($password, $hash);
        if (!$user || !$valid || $user['status'] !== 'active' || !$user['email_verified_at'] || !in_array($user['role'], ['student', 'admin'], true)) {
            $this->limiter->record('login_fail', $subjects);
            throw new RuntimeException('Email or password is incorrect.');
        }
        $this->limiter->clear('login_fail', $subjects);
        $this->db->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$user['id']]);
        unset($user['password_hash'], $user['status'], $user['email_verified_at']);
        return $user;
    }

    public function requestPasswordReset(string $email): bool
    {
        $email = strtolower(trim($email));
        $subjects = $this->emailIpSubjects($email);
        $this->limiter->assertAllowed('reset_request', $subjects, 3, 900);
        $this->limiter->record('reset_request', $subjects);
        $query = $this->db->prepare("SELECT id FROM users WHERE email = ? AND status = 'active' AND email_verified_at IS NOT NULL LIMIT 1");
        $query->execute([$email]);
        $userId = $query->fetchColumn();
        if (!$userId) return false;
        $this->assertOtpCooldown((int) $userId, 'reset_password');
        $code = $this->issueOtp((int) $userId, 'reset_password');
        return $this->mail->sendOtp($email, $code, 'reset_password');
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
        $stmt = $this->db->prepare('INSERT INTO email_otps (user_id, purpose, otp_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE))');
        $stmt->execute([$userId, $purpose, password_hash($code, PASSWORD_DEFAULT)]);
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
            if (!$otp || (int) $otp['attempts'] >= 5 || !password_verify($code, $otp['otp_hash'])) {
                if ($otp) {
                    $this->db->prepare('UPDATE email_otps SET attempts = attempts + 1, consumed_at = IF(attempts + 1 >= 5, UTC_TIMESTAMP(), consumed_at) WHERE id = ?')->execute([$otp['id']]);
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
        if ($created && strtotime((string) $created . ' UTC') > time() - 60) {
            throw new RuntimeException('Please wait before requesting another code.');
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
