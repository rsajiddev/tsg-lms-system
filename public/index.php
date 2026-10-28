<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

foreach (
    [
        '/app/Core/Env.php',
        '/app/Core/Router.php',
        '/app/Core/View.php',
        '/app/Core/Security.php',
        '/app/Core/Database.php',
        '/app/Core/RateLimiter.php',
        '/app/Core/AuthMiddleware.php',
        '/app/Services/MailService.php',
        '/app/Services/CourseDuration.php',
        '/app/Services/PaymentReceiptPdf.php',
        '/app/Services/PaymentReceiptJpg.php',
        '/app/Services/AuthService.php',
        '/app/Services/AttendanceService.php',
        '/app/Services/LessonMedia.php',
        '/app/Controllers/HomeController.php',
        '/app/Controllers/PublicPageController.php',
        '/app/Controllers/HealthController.php',
        '/app/Controllers/AuthController.php',
        '/app/Controllers/DashboardController.php',
        '/app/Controllers/LearningController.php',
        '/app/Controllers/StudentPortalController.php',
        '/app/Controllers/CourseAdminController.php',
        '/app/Controllers/AdminManagementController.php',
        '/app/Controllers/PaymentController.php',
        '/app/Controllers/QuizController.php',
        '/app/Controllers/AssignmentController.php',
        '/app/Controllers/NotificationController.php',
        '/app/Controllers/StudentManagementController.php',
        '/app/Controllers/CertificateController.php',
        '/app/Controllers/AttendanceController.php',
        '/app/Controllers/CodingChallengeController.php',
    ] as $file
) {
    require BASE_PATH . $file;
}

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
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => rtrim($cookiePath, '/') . '/',
    ]);
    session_start();
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

$router = new \App\Core\Router();
$router->get('/', [\App\Controllers\HomeController::class, 'index']);
$router->post('/reviews', [\App\Controllers\HomeController::class, 'storeReview']);
$router->get('/about', [\App\Controllers\PublicPageController::class, 'about']);
$router->get('/courses', [\App\Controllers\PublicPageController::class, 'courses']);
$router->get('/courses/{slug}', [\App\Controllers\PublicPageController::class, 'courseDetails']);
$router->get('/contact', [\App\Controllers\PublicPageController::class, 'contact']);
$router->get('/terms', [\App\Controllers\PublicPageController::class, 'terms']);
$router->get('/privacy', [\App\Controllers\PublicPageController::class, 'privacy']);
$router->get('/refund-policy', [\App\Controllers\PublicPageController::class, 'refundPolicy']);
$router->get('/verify', [\App\Controllers\CertificateController::class, 'verify']);
$router->get('/certificate/download', [\App\Controllers\CertificateController::class, 'download']);
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
$router->get('/learning', [\App\Controllers\LearningController::class, 'course']);
$router->get('/learning/lesson', [\App\Controllers\LearningController::class, 'lesson']);
$router->post('/learning/complete', [\App\Controllers\LearningController::class, 'complete']);
$router->post('/learning/lesson/watch', [\App\Controllers\LearningController::class, 'watch']);
$router->get('/learning/challenges', [\App\Controllers\CodingChallengeController::class, 'studentIndex']);
$router->get('/learning/challenge', [\App\Controllers\CodingChallengeController::class, 'editor']);
$router->post('/learning/challenge/submit', [\App\Controllers\CodingChallengeController::class, 'submitAttempt']);
$router->get('/learning/quizzes', [\App\Controllers\QuizController::class, 'courseQuizzes']);
$router->get('/learning/assignments', [\App\Controllers\AssignmentController::class, 'studentIndex']);
$router->post('/learning/assignments/submit', [\App\Controllers\AssignmentController::class, 'submit']);
$router->get('/learning/assignments/file', [\App\Controllers\AssignmentController::class, 'file']);
$router->get('/student/courses', [\App\Controllers\StudentPortalController::class, 'courses']);
$router->get('/student/lessons', [\App\Controllers\StudentPortalController::class, 'lessons']);
$router->get('/student/payments', [\App\Controllers\StudentPortalController::class, 'payments']);
$router->get('/student/quizzes', [\App\Controllers\StudentPortalController::class, 'quizzes']);
$router->get('/student/assignments', [\App\Controllers\StudentPortalController::class, 'assignments']);
$router->post('/student/certificates/request', [\App\Controllers\CertificateController::class, 'request']);
$router->post('/student/attendance/mark', [\App\Controllers\AttendanceController::class, 'mark']);
$router->get('/admin/certificates', [\App\Controllers\CertificateController::class, 'adminIndex']);
$router->post('/admin/certificates/issue', [\App\Controllers\CertificateController::class, 'issue']);
$router->post('/admin/certificates/reject', [\App\Controllers\CertificateController::class, 'reject']);
$router->get('/admin/assignments', [\App\Controllers\AssignmentController::class, 'adminIndex']);
$router->get('/admin/students', [\App\Controllers\StudentManagementController::class, 'index']);
$router->post('/admin/students/status', [\App\Controllers\StudentManagementController::class, 'setStatus']);
$router->post('/admin/students/clear-activity', [\App\Controllers\StudentManagementController::class, 'clearActivity']);
$router->post('/admin/assignments/save', [\App\Controllers\AssignmentController::class, 'save']);
$router->post('/admin/assignments/review', [\App\Controllers\AssignmentController::class, 'review']);
$router->get('/admin/announcements', [\App\Controllers\NotificationController::class, 'adminIndex']);
$router->post('/admin/announcements/publish', [\App\Controllers\NotificationController::class, 'publish']);
$router->get('/notifications', [\App\Controllers\NotificationController::class, 'studentIndex']);
$router->get('/notifications/view', [\App\Controllers\NotificationController::class, 'show']);
$router->post('/notifications/reply', [\App\Controllers\NotificationController::class, 'reply']);
$router->post('/learning/quiz/start', [\App\Controllers\QuizController::class, 'start']);
$router->get('/learning/quiz/attempt', [\App\Controllers\QuizController::class, 'attempt']);
$router->post('/learning/quiz/submit', [\App\Controllers\QuizController::class, 'submit']);
$router->post('/learning/quiz/retake-request', [\App\Controllers\QuizController::class, 'requestRetake']);
$router->get('/learning/quiz/result', [\App\Controllers\QuizController::class, 'result']);
$router->get('/admin/quizzes', [\App\Controllers\QuizController::class, 'adminIndex']);
$router->get('/admin/coding-challenges', [\App\Controllers\CodingChallengeController::class, 'adminIndex']);
$router->post('/admin/coding-challenges/save', [\App\Controllers\CodingChallengeController::class, 'save']);
$router->get('/admin/quiz-reports', [\App\Controllers\QuizController::class, 'adminReports']);
$router->post('/admin/quizzes/save', [\App\Controllers\QuizController::class, 'saveQuiz']);
$router->post('/admin/quizzes/delete', [\App\Controllers\QuizController::class, 'deleteQuiz']);
$router->post('/admin/quizzes/questions/save', [\App\Controllers\QuizController::class, 'saveQuestion']);
$router->post('/admin/quizzes/retake-decision', [\App\Controllers\QuizController::class, 'retakeDecision']);
$router->get('/admin/courses', [\App\Controllers\CourseAdminController::class, 'index']);
$router->get('/admin/lessons', [\App\Controllers\CourseAdminController::class, 'lessons']);
$router->post('/admin/courses/save', [\App\Controllers\CourseAdminController::class, 'save']);
$router->post('/admin/courses/delete', [\App\Controllers\CourseAdminController::class, 'delete']);
$router->post('/admin/courses/lessons/save', [\App\Controllers\CourseAdminController::class, 'saveLesson']);
$router->post('/admin/courses/access', [\App\Controllers\CourseAdminController::class, 'access']);
$router->get('/admin/settings', [\App\Controllers\AdminManagementController::class, 'settings']);
$router->post('/admin/settings', [\App\Controllers\AdminManagementController::class, 'saveSettings']);
$router->get('/admin/reviews', [\App\Controllers\AdminManagementController::class, 'reviews']);
$router->post('/admin/reviews/decision', [\App\Controllers\AdminManagementController::class, 'reviewDecision']);
$router->get('/admin/payments', [\App\Controllers\PaymentController::class, 'adminIndex']);
$router->post('/admin/payments/decision', [\App\Controllers\PaymentController::class, 'decision']);
$router->get('/admin/payments/revenue-receipt', [\App\Controllers\PaymentController::class, 'revenueReceipt']);
$router->post('/admin/payments/revenue-notify', [\App\Controllers\PaymentController::class, 'notifyDeveloper']);
$router->post('/admin/payments/settings', [\App\Controllers\AdminManagementController::class, 'savePaymentDetails']);
$router->get('/payments/new', [\App\Controllers\PaymentController::class, 'newPage']);
$router->post('/payments', [\App\Controllers\PaymentController::class, 'submit']);
$router->get('/payments/receipt', [\App\Controllers\PaymentController::class, 'receiptView']);
$router->get('/payments/receipt/download', [\App\Controllers\PaymentController::class, 'receipt']);
$router->get('/payments/proof', [\App\Controllers\PaymentController::class, 'proof']);

$requestMethod = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$requestUri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
$router->dispatch($requestMethod, $requestUri);
