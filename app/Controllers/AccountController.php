<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\Security;
use App\Core\View;
use App\Services\AuthService;
use App\Services\MailService;

/**
 * The account settings page every signed-in person owns: name, email address and password.
 *
 * One controller and one template serve both roles, because the fields, the validation and
 * the confirmation flow are identical — only the labels differ (Student ID versus Admin ID)
 * and the destination each form returns to. The account id itself is never editable: it is
 * the primary key the whole platform references, so it is displayed read-only by design.
 *
 * Email changes are the one field that cannot simply be written. A code is sent to the new
 * address and the change is applied only when that code is confirmed, so a typo can never
 * lock a student out of their own account or hand someone else's inbox to them.
 */
final class AccountController
{
    private const MIN_PASSWORD_LENGTH = 12;

    public function studentSettings(): void
    {
        $this->renderPage('student');
    }

    public function adminSettings(): void
    {
        $this->renderPage('admin');
    }

    /** Save the display name. */
    public function saveName(): void
    {
        $user = $this->currentUser();
        $this->assertCsrf();
        $name = trim((string) ($_POST['full_name'] ?? ''));
        if ($name === '') {
            $_SESSION['_flash_error'] = 'Enter your name before saving.';
            $this->back($user);
        }
        try {
            $saved = $this->auth()->updateName($user, $name);
            $_SESSION['_flash'] = 'Welcome back, ' . $saved . ' — your name has been updated.';
        } catch (\Throwable $exception) {
            $_SESSION['_flash_error'] = $exception->getMessage();
        }
        $this->back($user);
    }

    /** Replace the password after the current one has been verified. */
    public function savePassword(): void
    {
        $user = $this->currentUser();
        $this->assertCsrf();
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        if ($new !== $confirm) {
            $_SESSION['_flash_error'] = 'The new password and its confirmation do not match.';
            $this->back($user);
        }
        try {
            $this->auth()->changePassword($user, $current, $new);
            $_SESSION['_flash'] = 'Your password has been changed. Use it the next time you sign in.';
        } catch (\Throwable $exception) {
            $_SESSION['_flash_error'] = $exception->getMessage();
        }
        $this->back($user);
    }

    /** Send a confirmation code to the requested new address. */
    public function requestEmailChange(): void
    {
        $user = $this->currentUser();
        $this->assertCsrf();
        try {
            $this->auth()->requestEmailChange($user, (string) ($_POST['new_email'] ?? ''), (string) ($_POST['current_password'] ?? ''));
            $_SESSION['_flash'] = 'A six-digit confirmation code is on its way to your new address. Enter it below to finish the change.';
        } catch (\Throwable $exception) {
            $_SESSION['_flash_error'] = $exception->getMessage();
        }
        $this->back($user);
    }

    /** Apply the change once the code sent to the new address is confirmed. */
    public function confirmEmailChange(): void
    {
        $user = $this->currentUser();
        $this->assertCsrf();
        try {
            $updated = $this->auth()->confirmEmailChange($user, trim((string) ($_POST['otp_code'] ?? '')));
            $_SESSION['_flash'] = 'Your sign-in email address is now ' . $updated . '.';
        } catch (\Throwable $exception) {
            $_SESSION['_flash_error'] = $exception->getMessage();
        }
        $this->back($user);
    }

    /* -------------------------------------------------------------------------- helpers */

    private function renderPage(string $role): void
    {
        $user = AuthMiddleware::requireRole($role);
        $auth = $this->auth();
        $lifetime = 600;
        try {
            $stmt = Database::connection()->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'otp_lifetime_seconds' LIMIT 1");
            $stmt->execute();
            $configured = $stmt->fetchColumn();
            if ($configured !== false && ctype_digit((string) $configured)) $lifetime = max(1, (int) $configured);
        } catch (\Throwable $exception) {
            // The default expiry is good enough for the hint text.
        }
        $account = [];
        try {
            $stmt = Database::connection()->prepare('SELECT created_at, last_login_at FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([(int) $user['id']]);
            $account = $stmt->fetch() ?: [];
        } catch (\Throwable $exception) {
            $account = [];
        }
        View::render('account/settings', [
            'title' => $role === 'admin' ? 'My account' : 'Account settings',
            'user' => $user,
            'role' => $role,
            'idLabel' => $role === 'admin' ? 'Admin ID' : 'Student ID',
            'publicId' => MailService::accountReference((int) $user['id'], $role),
            'pendingEmail' => $auth->pendingEmailChange((int) $user['id']),
            'account' => $account,
            'minPassword' => self::MIN_PASSWORD_LENGTH,
            'otpMinutes' => max(1, (int) ceil($lifetime / 60)),
            'message' => $_SESSION['_flash'] ?? null,
            'error' => $_SESSION['_flash_error'] ?? null,
        ]);
        unset($_SESSION['_flash'], $_SESSION['_flash_error']);
    }

    private function auth(): AuthService
    {
        return new AuthService(Database::connection(), new MailService(Database::connection()));
    }

    /** Either signed-in role may manage its own account; nobody else. */
    private function currentUser(): array
    {
        $session = $_SESSION['user'] ?? null;
        if (!is_array($session) || !in_array($session['role'] ?? '', ['admin', 'student'], true)) {
            $this->redirect('/login');
        }
        return AuthMiddleware::requireRole((string) $session['role']);
    }

    private function assertCsrf(): void
    {
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            $_SESSION['_flash_error'] = 'Your session expired. Refresh the page and try again.';
            $role = (string) ($_SESSION['user']['role'] ?? 'student');
            $this->redirect($role === 'admin' ? '/admin/account' : '/student/settings');
        }
    }

    /** Return to the caller's own settings page. */
    private function back(array $user): void
    {
        $this->redirect(($user['role'] ?? '') === 'admin' ? '/admin/account' : '/student/settings');
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim((require BASE_PATH . '/config/app.php')['url'], '/') . $path);
        exit;
    }
}
