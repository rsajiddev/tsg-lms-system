<?php

declare(strict_types=1);

namespace App\Core;

final class View
{
    public static function render(string $view, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        // Keep template locals explicit for runtime safety and IDE/static analysis.
        $title = isset($title) && is_string($title) ? $title : 'TSG Academy LMS';
        $mode = isset($mode) && is_string($mode) ? $mode : '';
        $message = isset($message) && is_string($message) ? $message : null;
        $values = isset($values) && is_array($values) ? $values : [];
        $user = isset($user) && is_array($user) ? $user : [];
        $baseUrl = isset($baseUrl) && is_string($baseUrl)
            ? $baseUrl
            : rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
        $viewFile = BASE_PATH . '/app/Views/' . $view . '.php';
        if (!is_file($viewFile)) throw new \RuntimeException('View not found.');
        require BASE_PATH . '/app/Views/layouts/header.php';
        require $viewFile;
        require BASE_PATH . '/app/Views/layouts/footer.php';
    }
}
