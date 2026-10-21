<?php

declare(strict_types=1);

namespace App\Core;

final class AuthMiddleware
{
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
        $_SESSION['user'] = ['id' => (int) $fresh['id'], 'name' => $fresh['full_name'], 'email' => $fresh['email'], 'role' => $fresh['role']];
        return $_SESSION['user'];
    }
}
