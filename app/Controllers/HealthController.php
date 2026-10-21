<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Database;
final class HealthController
{
    public function show(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        try {
            Database::connection()->query('SELECT 1')->fetchColumn();
            http_response_code(200);
            echo json_encode(['status' => 'ok', 'database' => 'ok'], JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            error_log('[HEALTH] Dependency check failed: ' . $exception->getMessage());
            http_response_code(503);
            echo json_encode(['status' => 'unavailable', 'database' => 'unavailable'], JSON_THROW_ON_ERROR);
        }
    }
}
