<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Database;
use App\Core\Security;
use App\Core\View;
use App\Services\AuthService;
use App\Services\MailService;
use RuntimeException;

final class AuthController
{
    private AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService(Database::connection(), new MailService());
    }

    public function registerPage(): void { $this->page('register'); }

    public function register(): void
    {
        if (!$this->csrfValid()) return;
        $name = trim((string) ($_POST['full_name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if (!hash_equals($password, (string) ($_POST['password_confirmation'] ?? ''))) {
            $this->page('register', [], 'Passwords do not match.');
            return;
        }
        try {
            $this->auth->register($name, $email, $password);
            $this->page('register', ['email' => $email], 'If this address can be registered, verification instructions will be sent.');
        } catch (RuntimeException $exception) {
            $this->page('register', ['full_name' => $name, 'email' => $email], $exception->getMessage());
        }
    }

    public function verifyPage(): void { $this->page('verify'); }

    public function verify(): void
    {
        if (!$this->csrfValid()) return;
        try {
            $this->auth->verifyEmail((string) ($_POST['email'] ?? ''), (string) ($_POST['code'] ?? ''));
            $this->page('login', ['email' => (string) ($_POST['email'] ?? '')], 'Email verified. You can now sign in.');
        } catch (RuntimeException $exception) {
            $this->page('verify', ['email' => (string) ($_POST['email'] ?? '')], $exception->getMessage());
        }
    }

    public function resend(): void
    {
        if (!$this->csrfValid()) return;
        try {
            $this->auth->resendVerification((string) ($_POST['email'] ?? ''));
            $this->page('verify', ['email' => (string) ($_POST['email'] ?? '')], 'If a pending account exists, a new code will be sent.');
        } catch (RuntimeException $exception) {
            $this->page('verify', ['email' => (string) ($_POST['email'] ?? '')], $exception->getMessage());
        }
    }

    public function loginPage(): void { $this->page('login'); }

    public function login(): void
    {
        if (!$this->csrfValid()) return;
        try {
            $user = $this->auth->login((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
            session_regenerate_id(true);
            unset($_SESSION['_csrf']);
            $_SESSION['user'] = ['id' => (int) $user['id'], 'name' => $user['full_name'], 'email' => $user['email'], 'role' => $user['role']];
            $this->redirect('/dashboard');
        } catch (RuntimeException $exception) {
            $this->page('login', ['email' => (string) ($_POST['email'] ?? '')], $exception->getMessage());
        }
    }

    public function logout(): void
    {
        if (!$this->csrfValid()) return;
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $params['path'], 'domain' => $params['domain'], 'secure' => $params['secure'], 'httponly' => $params['httponly'], 'samesite' => 'Lax']);
        }
        session_destroy();
        $this->redirect('/login');
    }

    public function forgotPage(): void { $this->page('forgot'); }

    public function forgot(): void
    {
        if (!$this->csrfValid()) return;
        try {
            $this->auth->requestPasswordReset((string) ($_POST['email'] ?? ''));
            $this->page('forgot', [], 'If an eligible account exists, password reset instructions will be sent.');
        } catch (RuntimeException $exception) {
            $this->page('forgot', ['email' => (string) ($_POST['email'] ?? '')], $exception->getMessage());
        }
    }

    public function resetPage(): void { $this->page('reset'); }

    public function reset(): void
    {
        if (!$this->csrfValid()) return;
        $email = (string) ($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        if (!hash_equals($password, (string) ($_POST['password_confirmation'] ?? ''))) {
            $this->page('reset', ['email' => $email, 'code' => (string) ($_POST['code'] ?? '')], 'Passwords do not match.');
            return;
        }
        try {
            $this->auth->resetPassword($email, (string) ($_POST['code'] ?? ''), $password);
            $this->page('login', ['email' => $email], 'Password updated. You can now sign in.');
        } catch (RuntimeException $exception) {
            $this->page('reset', ['email' => $email, 'code' => (string) ($_POST['code'] ?? '')], $exception->getMessage());
        }
    }

    private function csrfValid(): bool
    {
        if (Security::verifyCsrf(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) return true;
        http_response_code(419);
        $this->page('error', [], 'This form expired. Refresh the page and try again.');
        return false;
    }

    private function page(string $mode, array $values = [], ?string $message = null): void
    {
        View::render('auth/form', ['title' => $this->title($mode), 'mode' => $mode, 'values' => $values, 'message' => $message]);
    }

    private function title(string $mode): string
    {
        return match ($mode) {
            'register' => 'Create Student Account', 'verify' => 'Verify Email', 'login' => 'Sign In',
            'forgot' => 'Reset Password', 'reset' => 'Choose New Password', default => 'Request Error',
        };
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim((require BASE_PATH . '/config/app.php')['url'], '/') . $path);
        exit;
    }
}
