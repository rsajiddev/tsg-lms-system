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
        $stmt = Database::connection()->prepare("SELECT id, full_name, email, role FROM lms_users WHERE id = ? AND status = 'active' AND email_verified_at IS NOT NULL LIMIT 1");
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
        if ($role === 'student' && !self::isPaymentRoute()) {
            $db = Database::connection();
            $access = $db->prepare("SELECT 1 FROM lms_student_course_access WHERE student_id=? AND status IN ('active','completed','certified') AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP()) LIMIT 1");
            $access->execute([(int) $fresh['id']]);
            if (!$access->fetchColumn()) {
                $pending = $db->prepare("SELECT course_id FROM lms_payments WHERE student_id=? AND status='pending' ORDER BY created_at DESC, id DESC LIMIT 1");
                $pending->execute([(int) $fresh['id']]);
                $courseId = (int) ($pending->fetchColumn() ?: 0);
                $baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
                header('Location: ' . $baseUrl . ($courseId > 0 ? '/payments/new?course_id=' . $courseId : '/courses'));
                exit;
            }
        }
        return $_SESSION['user'];
    }

    private static function isPaymentRoute(): bool
    {
        $basePath = rtrim((string) (parse_url((require BASE_PATH . '/config/app.php')['url'], PHP_URL_PATH) ?: ''), '/');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if ($basePath !== '' && str_starts_with($path, $basePath)) $path = '/' . ltrim(substr($path, strlen($basePath)), '/');
        return str_starts_with('/' . trim($path, '/'), '/payments');
    }
}
