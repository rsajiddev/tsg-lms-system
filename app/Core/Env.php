<?php

declare(strict_types=1);

namespace App\Core;

final class Env
{
    public static function load(string $file): void
    {
        if (!is_file($file)) return;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            // Operators write notes after a value, e.g. "MAIL_USERNAME=x@y.com  # testing".
            // Only an unquoted value loses a '#' that starts after whitespace, so a password or
            // URL that genuinely contains '#' is never cut short.
            if ($value !== '' && $value[0] !== '"' && $value[0] !== "'") {
                $value = trim((string) preg_replace('/\s+#.*$/', '', $value));
            }
            $value = trim($value, "\"'");
            if ($key !== '' && getenv($key) === false) {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
    }
}
