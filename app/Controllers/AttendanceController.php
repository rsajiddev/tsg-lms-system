<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Security;
use App\Services\AttendanceService;
use RuntimeException;

/**
 * Student attendance. Sign-in opens the daily record (see AttendanceService); this controller only
 * lets the signed-in student confirm presence inside the grace window.
 */
final class AttendanceController
{
    public function mark(): void
    {
        $user = AuthMiddleware::requireRole('student');
        try {
            if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
                throw new RuntimeException('Your session expired. Refresh the page and try again.');
            }
            $service = new AttendanceService();
            if (!$service->available()) {
                throw new RuntimeException('Attendance is not available yet. Please try again after the Academy updates the system.');
            }
            $result = $service->markPresent((int) $user['id']);
            $_SESSION[$result['ok'] ? '_flash' : '_flash_error'] = $result['message'];
        } catch (\Throwable $exception) {
            $_SESSION['_flash_error'] = $exception->getMessage();
        }

        $base = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
        header('Location: ' . $base . '/dashboard');
        exit;
    }
}
