<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\Security;
use App\Core\View;
use App\Services\AuthService;
use App\Services\AccountIdentity;
use App\Services\MailService;

/**
 * The account settings page every signed-in person owns: name, email address and password.
 *
 * One controller and one template serve both roles, because the fields, the validation and
 * the confirmation flow are identical — only the labels differ (Student ID versus Admin ID)
 * and the destination each form returns to. The database id behind the account is never
 * editable: it is the primary key the whole platform references, so it is displayed read-only
 * by design. The public ID is a different matter. An admin sets the scheme every new student ID
 * is minted from, and may rename their own, because those two numbers are printed on documents
 * and typed into a verification box rather than joined against.
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

    /**
     * Admin-only: the prefix and the first number every new student ID is minted from.
     *
     * Saved together with the optional re-numbering of the students already on the roll in one
     * transaction, so the scheme can never be half-applied - a prefix saved without the counter
     * would hand out IDs in a format nobody asked for.
     */
    public function saveIdentityScheme(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $this->assertCsrf();
        try {
            $this->performSaveScheme($admin);
        } catch (\Throwable $exception) {
            $_SESSION['_flash_error'] = $exception->getMessage();
        }
        $this->redirect('/admin/account');
    }

    private function performSaveScheme(array $admin): void
    {
        $prefix = AccountIdentity::cleanPrefix((string) ($_POST['student_id_prefix'] ?? ''));
        $start = trim((string) ($_POST['student_id_start'] ?? ''));
        if (!ctype_digit($start) || (int) $start < 1) {
            throw new RuntimeException('Enter the number the next student ID starts at, for example 1.');
        }
        $reissue = ((string) ($_POST['apply_to_existing'] ?? '')) === '1';
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $scheme = AccountIdentity::saveScheme($db, $prefix, (int) $start, (int) $admin['id']);
            $tally = $reissue ? AccountIdentity::reissueStudents($db, (int) $admin['id']) : ['students' => 0, 'certificates' => 0];
            $next = $reissue ? AccountIdentity::scheme($db)['next'] : $scheme['next'];
            $db->prepare("INSERT INTO audit_logs(actor_user_id,action,entity_type,metadata) VALUES(?,'account.identity_scheme_updated','app_settings',?)")
                ->execute([(int) $admin['id'], json_encode(['prefix' => $scheme['prefix'], 'start' => (int) $start, 'reissued_students' => $tally['students'], 'reissued_certificates' => $tally['certificates']], JSON_THROW_ON_ERROR)]);
            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
        $example = AccountIdentity::format($scheme['prefix'], $next);
        $_SESSION['_flash'] = $tally['students'] > 0
            ? 'Student ID scheme saved. ' . $tally['students'] . ' student' . ($tally['students'] === 1 ? '' : 's') . ' re-numbered from ' . AccountIdentity::format($scheme['prefix'], (int) $start) . ', ' . $tally['certificates'] . ' issued certificate' . ($tally['certificates'] === 1 ? '' : 's') . ' carried across. The next sign-up gets ' . $example . '.'
            : 'Student ID scheme saved. The next student to sign up is issued ' . $example . '.';
    }

    /**
     * Admin-only: rename the ID this account is quoted by. Confirmed in a dialog first, because
     * the number is on every receipt and certificate this account has already handed out.
     */
    public function saveAdminId(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $this->assertCsrf();
        try {
            $db = Database::connection();
            $before = AccountIdentity::reference((int) $admin['id'], 'admin');
            $reference = AccountIdentity::setReference($db, (int) $admin['id'], (string) ($_POST['account_reference'] ?? ''));
            $db->prepare("INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,metadata) VALUES(?,?,?,?,?)")
                ->execute([(int) $admin['id'], 'account.id_updated', 'user', (int) $admin['id'], json_encode(['from' => $before, 'to' => $reference], JSON_THROW_ON_ERROR)]);
            $_SESSION['_flash'] = $before === $reference
                ? 'Your Admin ID is still ' . $reference . ' - nothing needed changing.'
                : 'Your Admin ID is now ' . $reference . '. Anything already issued under ' . $before . ' points at it too.';
        } catch (\Throwable $exception) {
            $_SESSION['_flash_error'] = $exception->getMessage();
        }
        $this->redirect('/admin/account');
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
        // Only an admin sees the scheme card, and the page still renders on its defaults if the
        // settings table is missing, so a half-imported database cannot blank the workspace.
        $scheme = [
            'prefix' => AccountIdentity::DEFAULT_PREFIX,
            'start' => 1,
            'example' => AccountIdentity::format(AccountIdentity::DEFAULT_PREFIX, 1),
            'students' => 0,
        ];
        if ($role === 'admin') {
            try {
                $db = Database::connection();
                $current = AccountIdentity::scheme($db);
                $scheme = [
                    'prefix' => $current['prefix'],
                    'start' => $current['next'],
                    'example' => AccountIdentity::format($current['prefix'], $current['next']),
                    'students' => AccountIdentity::countStudents($db),
                ];
            } catch (\Throwable $exception) {
                // The defaults above are what an un-seeded install would hand out anyway.
            }
        }
        View::render('account/settings', [
            'title' => $role === 'admin' ? 'My account' : 'Account settings',
            'user' => $user,
            'role' => $role,
            'idLabel' => $role === 'admin' ? 'Admin ID' : 'Student ID',
            'publicId' => MailService::accountReference((int) $user['id'], $role),
            'scheme' => $scheme,
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
