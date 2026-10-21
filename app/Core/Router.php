<?php
declare(strict_types=1);
namespace App\Core;
final class Router {
    private array $routes = [];
    public function get(string $path, callable|array $handler): void { $this->map('GET', $path, $handler); }
    public function post(string $path, callable|array $handler): void { $this->map('POST', $path, $handler); }
    private function map(string $method, string $path, callable|array $handler): void { $this->routes[$method][$this->normalize($path)] = $handler; }
    public function dispatch(string $method, string $uri): void {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $base = parse_url((require BASE_PATH . '/config/app.php')['url'], PHP_URL_PATH) ?: '';
        $base = rtrim($base, '/');
        if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        $path = $this->normalize($path);
        $handler = $this->routes[strtoupper($method)][$path] ?? null;
        if (!$handler) { http_response_code(404); require BASE_PATH . '/app/Views/errors/404.php'; return; }
        if (is_array($handler)) { [$class, $action] = $handler; (new $class())->$action(); return; }
        $handler();
    }
    private function normalize(string $path): string { $path = '/' . trim($path, '/'); return $path === '//' ? '/' : $path; }
}
