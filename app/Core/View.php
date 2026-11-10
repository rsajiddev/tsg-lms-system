<?php

declare(strict_types=1);

namespace App\Core;

final class View
{
    /** @var array<int, array{tone: string, text: string}> confirmation messages queued while a page renders. */
    private static array $toasts = [];

    /**
     * Queue a confirmation for the overlay card that layouts/footer.php renders at the end of the
     * page. Views call this instead of printing a banner where they sit, so the message floats
     * over the workspace and stays readable whatever the page is doing.
     */
    public static function toast(string $tone, ?string $text): void
    {
        $text = is_string($text) ? trim($text) : '';
        if ($text === '') return;
        // Unknown tones arrive as the neutral one rather than producing an unstyled overlay.
        $tone = in_array($tone, ['success', 'error', 'pending', 'info'], true) ? $tone : 'info';
        self::$toasts[] = ['tone' => $tone, 'text' => $text];
    }

    /** The queued messages, drained: a page can only be rendered once per request. */
    public static function toasts(): array
    {
        $queued = self::$toasts;
        self::$toasts = [];
        return $queued;
    }

    public static function render(string $view, array $data = []): void
    {
        self::$toasts = [];
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
