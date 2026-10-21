<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Core/Env.php';
require BASE_PATH . '/app/Core/Router.php';
require BASE_PATH . '/app/Core/View.php';
require BASE_PATH . '/app/Core/Security.php';
require BASE_PATH . '/app/Core/Database.php';
require BASE_PATH . '/app/Core/RateLimiter.php';
require BASE_PATH . '/app/Core/AuthMiddleware.php';
require BASE_PATH . '/app/Controllers/HomeController.php';
require BASE_PATH . '/app/Controllers/HealthController.php';
require BASE_PATH . '/app/Services/MailService.php';
require BASE_PATH . '/app/Services/AuthService.php';
require BASE_PATH . '/app/Controllers/AuthController.php';
require BASE_PATH . '/app/Controllers/DashboardController.php';
\App\Core\Env::load(BASE_PATH . '/.env');
$app = require BASE_PATH . '/config/app.php';
date_default_timezone_set($app['timezone']);
ini_set('display_errors', '0');
header_remove('X-Powered-By');
set_exception_handler(static function (\Throwable $exception) use ($app): void {
    error_log(sprintf('[APP] %s in %s:%d', $exception->getMessage(), $exception->getFile(), $exception->getLine()));
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo !empty($app['debug']) && $app['env'] !== 'production'
        ? 'Application error: ' . $exception->getMessage()
        : 'The application could not complete this request.';
});
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name($app['session_name']);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    $cookiePath = parse_url($app['url'], PHP_URL_PATH) ?: '/';
    session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Lax', 'path' => rtrim($cookiePath, '/') . '/']);
    session_start();
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
$router = new \App\Core\Router();
$router->get('/', [\App\Controllers\HomeController::class, 'index']);
$router->get('/health', [\App\Controllers\HealthController::class, 'show']);
$auth = \App\Controllers\AuthController::class;
$router->get('/register', [$auth, 'registerPage']);
$router->post('/register', [$auth, 'register']);
$router->get('/verify-email', [$auth, 'verifyPage']);
$router->post('/verify-email', [$auth, 'verify']);
$router->post('/verify-email/resend', [$auth, 'resend']);
$router->get('/login', [$auth, 'loginPage']);
$router->post('/login', [$auth, 'login']);
$router->post('/logout', [$auth, 'logout']);
$router->get('/forgot-password', [$auth, 'forgotPage']);
$router->post('/forgot-password', [$auth, 'forgot']);
$router->get('/reset-password', [$auth, 'resetPage']);
$router->post('/reset-password', [$auth, 'reset']);
$router->get('/dashboard', [\App\Controllers\DashboardController::class, 'show']);
$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
