<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\SessionRegistry;

final class AuthMiddleware
{
    /**
     * Authorise a protected page, and with it the session the request came in on.
     *
     * Every student and admin screen already passes through this method and already costs one
     * read of the users row, so the active-session check belongs here rather than being copied
     * into each controller - a route someone forgets to patch would otherwise be a hole.
     */
    public static function requireRole(string $role): array
    {
        $user = $_SESSION['user'] ?? null;
        if (!is_array($user)) {
            header('Location: ' . rtrim((require BASE_PATH . '/config/app.php')['url'], '/') . '/login');
            exit;
        }
        $stmt = Database::connection()->prepare("SELECT id, full_name, email, role FROM users WHERE id = ? AND status = 'active' AND email_verified_at IS NOT NULL LIMIT 1");
        $stmt->execute([(int) ($user['id'] ?? 0)]);
        $fresh = $stmt->fetch();
        if (!$fresh) {
            $_SESSION = [];
            session_regenerate_id(true);
            header('Location: ' . rtrim((require BASE_PATH . '/config/app.php')['url'], '/') . '/login');
            exit;
        }
        if ($fresh['role'] !== $role) {
            $_SESSION = [];
            session_regenerate_id(true);
            header('Location: ' . rtrim((require BASE_PATH . '/config/app.php')['url'], '/') . '/login');
            exit;
        }
        // One active session per account. Wrapped so that a database without the session table,
        // or a registry that cannot be read, never stands between a student and their lesson.
        $verdict = 'unknown';
        try {
            $verdict = (new SessionRegistry())->check((int) $fresh['id']);
        } catch (\Throwable $exception) {
            error_log('[SESSIONS] Middleware check skipped: ' . $exception->getMessage());
        }
        if ($verdict === 'revoked') {
            $_SESSION = ['_flash_error' => 'This device was signed out because the account signed in somewhere else. Sign in again to continue.'];
            session_regenerate_id(true);
            header('Location: ' . rtrim((require BASE_PATH . '/config/app.php')['url'], '/') . '/login');
            exit;
        }
        $_SESSION['user'] = ['id' => (int) $fresh['id'], 'name' => $fresh['full_name'], 'email' => $fresh['email'], 'role' => $fresh['role']];
        return $_SESSION['user'];
    }
}
