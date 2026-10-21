<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\View;

final class DashboardController
{
    public function show(): void
    {
        $user = $_SESSION['user'] ?? null;
        if (!is_array($user) || !in_array($user['role'] ?? '', ['student', 'admin'], true)) {
            header('Location: ' . rtrim((require BASE_PATH . '/config/app.php')['url'], '/') . '/login');
            return;
        }
        AuthMiddleware::requireRole($user['role']);
        View::render('dashboard/index', ['title' => ucfirst($user['role']) . ' Dashboard', 'user' => $user]);
    }
}
