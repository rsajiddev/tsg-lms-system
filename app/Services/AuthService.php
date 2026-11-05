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

    /**
     * Creates the pending account and asks for the email verification step.
     *
     * Two very different things used to collapse into one `false`: an address that already has an
     * account, and an OTP the mail server would not accept. The caller cannot write an honest
     * message from a boolean that means both, so the outcome is returned as two flags. A duplicate
     * is reported without confirming that the account exists, which keeps the form from handing out
     * a list of registered addresses.
     *
     * @return array{created: bool, mail: bool}
     */
    public function register(string $name, string $email, string $password): array
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
            // The ID is issued with the account rather than derived later: it is what the
            // welcome email, the receipt and eventually the certificate all quote, and the
            // student verifies their certificate with it.
            $reference = AccountIdentity::issue($this->db);
            $this->db->prepare('UPDATE users SET account_reference = ? WHERE id = ?')->execute([$reference, $userId]);
            AccountIdentity::remember($userId, $reference);
            $code = $this->issueOtp($userId, 'verify_email');
            $this->db->commit();
        } catch (PDOException $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ((string) $exception->getCode() === '23000') return ['created' => false, 'mail' => false];
            throw new RuntimeException('Registration could not be completed. Please try again later.');
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $exception;
        }
        $studentEmailSent = $this->mail->sendOtp(strtolower($email), $code, 'verify_email', $this->setting('otp_lifetime_seconds', 600), $name, $userId);
        $this->mail->sendNewStudentAlert($name, strtolower($email), $userId);
        return ['created' => true, 'mail' => $studentEmailSent];
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
        $query = $this->db->prepare("SELECT id, full_name FROM users WHERE email = ? AND role = 'student' AND status = 'pending' LIMIT 1");
        $query->execute([$email]);
        $account = $query->fetch();
        if (!$account) return false;
        $userId = (int) $account['id'];
        $this->assertOtpCooldown($userId, 'verify_email');
        $code = $this->issueOtp($userId, 'verify_email');
        return $this->mail->sendOtp($email, $code, 'verify_email', $this->setting('otp_lifetime_seconds', 600), (string) $account['full_name'], $userId);
    }

    public function resendSecondsRemaining(string $email, string $purpose = 'verify_email'): int
    {
        $email = strtolower(trim($email));
        $stmt = $this->db->prepare('SELECT eo.created_at FROM email_otps eo JOIN users u ON u.id=eo.user_id WHERE u.email=? AND eo.purpose=? ORDER BY eo.id DESC LIMIT 1');
        $stmt->execute([$email, $purpose]);
        $created = $stmt->fetchColumn();
        if (!$created) return 0;
        return max(0, $this->resendCooldownSeconds() - (time() - (int)strtotime((string)$created . ' UTC')));
    }

    /** How long an issued code stays usable. Read by the pages that have to promise an expiry. */
    public function otpLifetimeSeconds(): int
    {
        return $this->setting('otp_lifetime_seconds', 600);
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
        if ($user['role'] === 'student') {
            $this->recordLoginEvent((int) $user['id'], 'success');
            $this->openAttendanceSession((int) $user['id']);
        }
        unset($user['password_hash'], $user['status'], $user['email_verified_at']);
        return $user;
    }

    /** Attendance must never block a valid login; history logging fails open. */
    private function openAttendanceSession(int $studentId): void
    {
        try {
            (new AttendanceService($this->db))->openDailySession($studentId);
        } catch (\Throwable $exception) {
            error_log('[ATTENDANCE] Sign-in record skipped: ' . $exception->getMessage());
        }
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
        $query = $this->db->prepare("SELECT id, full_name FROM users WHERE email = ? AND status = 'active' AND email_verified_at IS NOT NULL LIMIT 1");
        $query->execute([$email]);
        $account = $query->fetch();
        if (!$account) return false;
        $userId = (int) $account['id'];
        $this->assertOtpCooldown($userId, 'reset_password');
        $code = $this->issueOtp($userId, 'reset_password');
        return $this->mail->sendOtp($email, $code, 'reset_password', $this->setting('otp_lifetime_seconds', 600), (string) $account['full_name'], $userId);
    }

    public function resetPassword(string $email, string $code, string $password): void
    {
        if (strlen($password) < 12 || strlen($password) > 4096) {
            throw new RuntimeException('Use a password between 12 and 4096 characters.');
        }
        $userId = $this->verifyOtp($email, $code, 'reset_password', false, password_hash($password, PASSWORD_DEFAULT));
        // A reset proves the mailbox, not a device. Nothing that was signed in before the password
        // changed is trusted afterwards, so every session for the account is closed and left to be
        // re-opened by a real sign-in.
        (new SessionRegistry($this->db))->revokeAll($userId, 'password_change');
    }

    /* ------------------------------------------------------------- account settings */

    /**
     * Rename an account. The Student ID is the users.id row itself and is never editable,
     * so it is deliberately absent from this write.
     */
    public function updateName(array $user, string $name): string
    {
        $name = trim($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
            throw new RuntimeException('Enter a name between 2 and 150 characters.');
        }
        $stmt = $this->db->prepare('UPDATE users SET full_name = ? WHERE id = ?');
        $stmt->execute([$name, (int) $user['id']]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('Your name could not be saved. Reload the page and try again.');
        $this->recordProfileAudit((int) $user['id'], 'profile.name_changed');
        return $name;
    }

    /** Replace the password after the current one has been proved. */
    public function changePassword(array $user, string $currentPassword, string $newPassword): void
    {
        if (strlen($newPassword) < 12 || strlen($newPassword) > 4096) {
            throw new RuntimeException('Use a new password between 12 and 4096 characters.');
        }
        if (hash_equals($currentPassword, $newPassword)) {
            throw new RuntimeException('Choose a new password that is different from your current one.');
        }
        $stmt = $this->db->prepare('SELECT password_hash FROM users WHERE id = ? AND status = \'active\' LIMIT 1');
        $stmt->execute([(int) $user['id']]);
        $hash = (string) ($stmt->fetchColumn() ?: '');
        if ($hash === '' || !password_verify($currentPassword, $hash)) {
            $this->limiter->record('password_change_failed', ['user:' . (int) $user['id']]);
            throw new RuntimeException('Your current password is incorrect.');
        }
        $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int) $user['id']]);
        $this->limiter->clear('password_change_failed', ['user:' . (int) $user['id']]);
        $this->recordProfileAudit((int) $user['id'], 'profile.password_changed');
        // Every other device holding this account is signed out. The one that has just proved it
        // knows the previous password is kept, and re-registered so the registry has a live row
        // for it even when the session predates the feature - throwing a student off the device
        // they are standing on would only teach them to distrust the change.
        $registry = new SessionRegistry($this->db);
        $registry->revokeAll((int) $user['id'], 'password_change', true);
        $registry->register((int) $user['id']);
    }

    /** Address the user is currently being asked to confirm, if a change is still pending. */
    public function pendingEmailChange(int $userId): ?string
    {
        if ($userId < 1) return null;
        try {
            $stmt = $this->db->prepare("SELECT target_email FROM email_otps WHERE user_id = ? AND purpose = 'change_email' AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP() AND target_email IS NOT NULL ORDER BY id DESC LIMIT 1");
            $stmt->execute([$userId]);
            $pending = $stmt->fetchColumn();
        } catch (\Throwable $exception) {
            return null;
        }
        return $pending ? (string) $pending : null;
    }

    /**
     * Begin an email-address change. Identity is re-proved with the current password and the
     * code is sent to the new address, because only receiving it proves the student owns it.
     */
    public function requestEmailChange(array $user, string $newEmail, string $currentPassword): bool
    {
        $newEmail = strtolower(trim($newEmail));
        $current = strtolower(trim((string) $user['email']));
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL) || strlen($newEmail) > 190) {
            throw new RuntimeException('Enter a valid email address.');
        }
        if ($newEmail === $current) {
            throw new RuntimeException('That is already the email address on your account.');
        }
        $stmt = $this->db->prepare('SELECT password_hash FROM users WHERE id = ? AND status = \'active\' LIMIT 1');
        $stmt->execute([(int) $user['id']]);
        $hash = (string) ($stmt->fetchColumn() ?: '');
        if ($hash === '' || !password_verify($currentPassword, $hash)) {
            throw new RuntimeException('Enter your current password to change your email address.');
        }
        $subjects = $this->emailIpSubjects($current);
        $this->limiter->assertAllowed('email_change', $subjects, $this->setting('otp_resends_per_window', 3), $this->setting('otp_resend_window_minutes', 15) * 60);
        $this->limiter->record('email_change', $subjects);
        $taken = $this->db->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
        $taken->execute([$newEmail]);
        if ($taken->fetchColumn()) {
            throw new RuntimeException('That email address is already in use. Contact support if it belongs to you.');
        }
        $this->assertOtpCooldown((int) $user['id'], 'change_email');
        $code = $this->issueOtp((int) $user['id'], 'change_email', $newEmail);
        if (!$this->mail->sendOtp($newEmail, $code, 'change_email', $this->setting('otp_lifetime_seconds', 600), (string) $user['name'], (int) $user['id'])) {
            throw new RuntimeException('The confirmation code could not be sent to that address. Check it and try again.');
        }
        return true;
    }

    /** Apply a confirmed email change and return the new address. */
    public function confirmEmailChange(array $user, string $code): string
    {
        $userId = (int) $user['id'];
        $newEmail = (string) ($this->pendingEmailChange($userId) ?? '');
        if ($newEmail === '') {
            throw new RuntimeException('No email change is pending. Request a new confirmation code.');
        }
        $this->db->beginTransaction();
        try {
            $locked = $this->db->prepare('SELECT email FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
            $locked->execute([$userId]);
            $current = strtolower((string) ($locked->fetchColumn() ?: ''));
            if ($current === '') throw new RuntimeException('Your account could not be loaded. Sign in again and try once more.');
            if (!$this->consumeOtp($userId, $code, 'change_email')) {
                $this->db->commit();
                throw new RuntimeException('The code is invalid or expired.');
            }
            if ($newEmail === $current) throw new RuntimeException('That email address is already on your account.');
            $taken = $this->db->prepare('SELECT 1 FROM users WHERE email = ? AND id <> ? LIMIT 1');
            $taken->execute([$newEmail, $userId]);
            if ($taken->fetchColumn()) {
                $this->db->commit();
                throw new RuntimeException('That email address was taken while your change was pending.');
            }
            $this->db->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$newEmail, $userId]);
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $exception;
        }
        $this->recordProfileAudit($userId, 'profile.email_changed');
        return $newEmail;
    }

    private function issueOtp(int $userId, string $purpose, ?string $targetEmail = null): string
    {
        $code = (string) random_int(100000, 999999);
        $this->db->prepare('UPDATE email_otps SET consumed_at = UTC_TIMESTAMP() WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL')->execute([$userId, $purpose]);
        $lifetime = $this->setting('otp_lifetime_seconds', 600);
        $stmt = $this->db->prepare('INSERT INTO email_otps (user_id, purpose, target_email, otp_hash, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND))');
        $stmt->execute([$userId, $purpose, $targetEmail, password_hash($code, PASSWORD_DEFAULT), $lifetime]);
        return $code;
    }

    private function verifyOtp(string $email, string $code, string $purpose, bool $activate, ?string $newPasswordHash = null): int
    {
        $email = strtolower(trim($email));
        $this->db->beginTransaction();
        try {
            $userQuery = $this->db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1 FOR UPDATE');
            $userQuery->execute([$email]);
            $userId = (int) $userQuery->fetchColumn();
            if (!$userId) throw new RuntimeException('The code is invalid or expired.');
            // The attempt counter is committed before the failure is reported, so a wrong guess
            // is never lost and a caller cannot reset it by retrying with the same email.
            if (!$this->consumeOtp($userId, $code, $purpose)) {
                $this->db->commit();
                throw new RuntimeException('The code is invalid or expired.');
            }
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

    /**
     * Validate and consume the newest live code for one account and purpose. Returns false
     * when it is missing, exhausted or wrong; must run inside a transaction so the counter
     * write and the account write commit together.
     */
    private function consumeOtp(int $userId, string $code, string $purpose): bool
    {
        if ($userId < 1 || !preg_match('/^\d{6}$/', $code)) return false;
        $otpQuery = $this->db->prepare('SELECT id, otp_hash, attempts FROM email_otps WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP() ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $otpQuery->execute([$userId, $purpose]);
        $otp = $otpQuery->fetch();
        $maxAttempts = $this->setting('otp_max_attempts', 5);
        if (!$otp || (int) $otp['attempts'] >= $maxAttempts || !password_verify($code, (string) $otp['otp_hash'])) {
            if ($otp) {
                $this->db->prepare('UPDATE email_otps SET attempts = attempts + 1, consumed_at = IF(attempts + 1 >= ?, UTC_TIMESTAMP(), consumed_at) WHERE id = ?')->execute([$maxAttempts, $otp['id']]);
            }
            return false;
        }
        $this->db->prepare('UPDATE email_otps SET consumed_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$otp['id']]);
        return true;
    }

    /**
     * Profile writes are security-relevant, but a failed audit insert must never undo a
     * change the user is entitled to keep.
     */
    private function recordProfileAudit(int $userId, string $action): void
    {
        try {
            $this->db->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, metadata) VALUES (?, ?, 'user', ?, JSON_OBJECT('via', 'account_settings'))")
                ->execute([$userId, $action, $userId]);
        } catch (\Throwable $exception) {
            error_log('[AUTH] Profile audit entry could not be recorded: ' . $action);
        }
    }

    private function assertOtpCooldown(int $userId, string $purpose): void
    {
        $stmt = $this->db->prepare('SELECT created_at FROM email_otps WHERE user_id = ? AND purpose = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId, $purpose]);
        $created = $stmt->fetchColumn();
        if ($created && strtotime((string) $created . ' UTC') > time() - $this->resendCooldownSeconds()) {
            throw new RuntimeException('Please wait before requesting another code.');
        }
    }

    /**
     * How long a student must wait before a code can be sent again. This is deliberately not the
     * OTP lifetime: waiting out a slow mail server used to cost the full expiry window, which read
     * to the student as the form being broken. Resending still invalidates the previous code,
     * because issueOtp() consumes it, so a shorter wait does not put two codes in play at once.
     */
    private function resendCooldownSeconds(): int
    {
        return $this->setting('otp_resend_cooldown_seconds', 60);
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
