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
        '/app/Core/PurgeGuard.php',
        '/app/Core/PrivateStorage.php',
        '/app/Services/EmailLayout.php',
        '/app/Services/EmailMessages.php',
        '/app/Services/AccountIdentity.php',
        '/app/Services/AdminAttention.php',
        '/app/Services/ChatService.php',
        '/app/Services/MailService.php',
        '/app/Services/NotificationService.php',
        '/app/Services/CourseDuration.php',
        '/app/Services/AccessPlan.php',
        '/app/Services/PdfCanvas.php',
        '/app/Services/DocumentPdf.php',
        '/app/Services/ReportSharing.php',
        '/app/Services/AuthService.php',
        '/app/Services/AttendanceService.php',
        '/app/Services/LessonMedia.php',
        '/app/Services/LessonProtection.php',
        '/app/Services/SessionRegistry.php',
        '/app/Services/CourseGrading.php',
        '/app/Services/StudentProgress.php',
        '/app/Controllers/HomeController.php',
        '/app/Controllers/PublicPageController.php',
        '/app/Controllers/HealthController.php',
        '/app/Controllers/AuthController.php',
        '/app/Controllers/AccountController.php',
        '/app/Controllers/DashboardController.php',
        '/app/Controllers/DashboardTaskController.php',
        '/app/Controllers/DashboardSearchController.php',
        '/app/Controllers/LearningController.php',
        '/app/Controllers/StudentPortalController.php',
        '/app/Controllers/CourseAdminController.php',
        '/app/Controllers/AdminManagementController.php',
        '/app/Controllers/PaymentController.php',
        '/app/Controllers/ReportsController.php',
        '/app/Controllers/QuizController.php',
        '/app/Controllers/AssignmentController.php',
        '/app/Controllers/NotificationController.php',
        '/app/Controllers/ChatController.php',
        '/app/Controllers/StudentManagementController.php',
        '/app/Controllers/CertificateController.php',
        '/app/Controllers/AttendanceController.php',
        '/app/Controllers/CodingChallengeController.php',
        '/app/Controllers/EnrolmentController.php',
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
$router->post('/contact', [\App\Controllers\PublicPageController::class, 'submitContact']);
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

// Course-bound checkout. The chosen course is held in the session, so the account form and the
// payment step stay pointed at the same course instead of dropping the visitor on an empty
// dashboard halfway through buying something.
$enrol = \App\Controllers\EnrolmentController::class;
$router->get('/enrol/{slug}', [$enrol, 'start']);
$router->post('/enrol', [$enrol, 'register']);
$router->post('/enrol/verify', [$enrol, 'verify']);
$router->post('/enrol/verify/resend', [$enrol, 'resend']);
$router->post('/enrol/back', [$enrol, 'back']);
$router->get('/forgot-password', [$auth, 'forgotPage']);
$router->post('/forgot-password', [$auth, 'forgot']);
$router->get('/reset-password', [$auth, 'resetPage']);
$router->post('/reset-password', [$auth, 'reset']);
$router->get('/dashboard', [\App\Controllers\DashboardController::class, 'show']);
$router->get('/dashboard/search', [\App\Controllers\DashboardSearchController::class, 'search']);
$router->post('/dashboard/tasks/add', [\App\Controllers\DashboardTaskController::class, 'add']);
$router->post('/dashboard/tasks/toggle', [\App\Controllers\DashboardTaskController::class, 'toggle']);
$router->post('/dashboard/tasks/delete', [\App\Controllers\DashboardTaskController::class, 'delete']);
$router->post('/dashboard/tasks/reminder', [\App\Controllers\DashboardTaskController::class, 'reminder']);
$router->get('/learning', [\App\Controllers\LearningController::class, 'course']);
$router->get('/learning/lesson', [\App\Controllers\LearningController::class, 'lesson']);
$router->post('/learning/complete', [\App\Controllers\LearningController::class, 'complete']);
$router->post('/learning/lesson/watch', [\App\Controllers\LearningController::class, 'watch']);
$router->post('/learning/lesson/view', [\App\Controllers\LearningController::class, 'view']);
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
$router->get('/student/reports', [\App\Controllers\StudentPortalController::class, 'reports']);
$router->get('/student/reports/download', [\App\Controllers\StudentPortalController::class, 'reportDownload']);
$router->get('/student/progress', [\App\Controllers\StudentPortalController::class, 'progress']);
$router->get('/student/progress/report', [\App\Controllers\StudentPortalController::class, 'progressReport']);
$router->get('/student/settings', [\App\Controllers\AccountController::class, 'studentSettings']);
$router->get('/admin/account', [\App\Controllers\AccountController::class, 'adminSettings']);
$account = \App\Controllers\AccountController::class;
$router->post('/account/name', [$account, 'saveName']);
$router->post('/account/password', [$account, 'savePassword']);
$router->post('/account/email/request', [$account, 'requestEmailChange']);
$router->post('/account/email/confirm', [$account, 'confirmEmailChange']);
$router->post('/admin/account/identity-scheme', [$account, 'saveIdentityScheme']);
$router->post('/admin/account/id', [$account, 'saveAdminId']);
$router->post('/student/certificates/request', [\App\Controllers\CertificateController::class, 'request']);
$router->post('/student/attendance/mark', [\App\Controllers\AttendanceController::class, 'mark']);
$router->get('/admin/certificates', [\App\Controllers\CertificateController::class, 'adminIndex']);
$router->post('/admin/certificates/settings', [\App\Controllers\CertificateController::class, 'saveSettings']);
$router->post('/admin/certificates/issue', [\App\Controllers\CertificateController::class, 'issue']);
$router->post('/admin/certificates/reject', [\App\Controllers\CertificateController::class, 'reject']);
$router->get('/admin/assignments', [\App\Controllers\AssignmentController::class, 'adminIndex']);
$router->get('/admin/students', [\App\Controllers\StudentManagementController::class, 'index']);
$router->post('/admin/students/status', [\App\Controllers\StudentManagementController::class, 'setStatus']);
$router->post('/admin/students/revoke-sessions', [\App\Controllers\StudentManagementController::class, 'revokeSessions']);
$router->post('/admin/students/clear-activity', [\App\Controllers\StudentManagementController::class, 'clearActivity']);
$router->post('/admin/students/purge', [\App\Controllers\StudentManagementController::class, 'purgeAll']);
$router->post('/admin/students/attendance', [\App\Controllers\StudentManagementController::class, 'saveAttendance']);
$router->post('/admin/assignments/save', [\App\Controllers\AssignmentController::class, 'save']);
$router->post('/admin/assignments/review', [\App\Controllers\AssignmentController::class, 'review']);
$router->post('/admin/assignments/purge', [\App\Controllers\AssignmentController::class, 'purgeAll']);
$router->get('/admin/announcements', [\App\Controllers\NotificationController::class, 'adminIndex']);
$router->post('/admin/announcements/publish', [\App\Controllers\NotificationController::class, 'publish']);
$router->post('/admin/announcements/purge', [\App\Controllers\NotificationController::class, 'purgeAll']);
// The two reports that used to sit under the announcement board have their own desk now, and the
// conversations a student starts have a page of their own instead of only a sidebar number.
$router->get('/admin/chats', [\App\Controllers\ChatController::class, 'adminIndex']);
$router->post('/chats/send', [\App\Controllers\ChatController::class, 'send']);
// Clearing is one shared endpoint because it means the same thing on both sides of a conversation:
// hide it from me, leave it for the other side. Deleting is not offered to a student at all, since
// only an administrator may destroy a record of what the Academy was told.
$router->post('/chats/clear', [\App\Controllers\ChatController::class, 'clear']);
$router->post('/admin/chats/delete', [\App\Controllers\ChatController::class, 'deleteThread']);
$router->get('/admin/activity', [\App\Controllers\NotificationController::class, 'activity']);
$router->post('/admin/activity/automated/clear', [\App\Controllers\NotificationController::class, 'clearAutomatedUpdates']);
$router->post('/admin/activity/email-log/clear', [\App\Controllers\NotificationController::class, 'clearEmailLog']);
$router->post('/admin/activity/view-log/clear', [\App\Controllers\NotificationController::class, 'clearViewLog']);
$router->post('/admin/activity/clear', [\App\Controllers\NotificationController::class, 'clearActivity']);
$router->get('/notifications', [\App\Controllers\NotificationController::class, 'studentIndex']);
$router->post('/notifications/read-all', [\App\Controllers\NotificationController::class, 'markAllRead']);
$router->get('/notifications/view', [\App\Controllers\NotificationController::class, 'show']);
$router->get('/student/chats', [\App\Controllers\ChatController::class, 'studentIndex']);
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
$router->post('/admin/quizzes/purge', [\App\Controllers\QuizController::class, 'purgeAll']);
$router->post('/admin/quizzes/questions/save', [\App\Controllers\QuizController::class, 'saveQuestion']);
$router->post('/admin/quizzes/retake-decision', [\App\Controllers\QuizController::class, 'retakeDecision']);
$router->get('/admin/courses', [\App\Controllers\CourseAdminController::class, 'index']);
$router->get('/admin/lessons', [\App\Controllers\CourseAdminController::class, 'lessons']);
$router->post('/admin/courses/save', [\App\Controllers\CourseAdminController::class, 'save']);
$router->post('/admin/courses/delete', [\App\Controllers\CourseAdminController::class, 'delete']);
$router->post('/admin/courses/lessons/save', [\App\Controllers\CourseAdminController::class, 'saveLesson']);
$router->post('/admin/lessons/watch-rule', [\App\Controllers\CourseAdminController::class, 'saveWatchRule']);
$router->post('/admin/lessons/purge', [\App\Controllers\CourseAdminController::class, 'purgeLessons']);
$router->post('/admin/courses/access', [\App\Controllers\CourseAdminController::class, 'access']);
$router->get('/admin/settings', [\App\Controllers\AdminManagementController::class, 'settings']);
$router->post('/admin/settings', [\App\Controllers\AdminManagementController::class, 'saveSettings']);
$router->post('/admin/settings/video-protection', [\App\Controllers\AdminManagementController::class, 'saveVideoProtection']);
// Handover testing tools: the phrase-guarded wipe of all student data and the ID counter re-arm.
$router->post('/admin/settings/clear-data', [\App\Controllers\AdminManagementController::class, 'clearAllData']);
$router->post('/admin/settings/reset-counter', [\App\Controllers\AdminManagementController::class, 'resetIdCounter']);
// Danger Zone: the single page that gathers every guarded bulk-delete tool. The cards post to the
// workspace endpoints above, so this route only renders the page - no delete has its own new handler.
$router->get('/admin/danger-zone', [\App\Controllers\AdminManagementController::class, 'dangerZone']);
$router->get('/admin/reviews', [\App\Controllers\AdminManagementController::class, 'reviews']);
$router->post('/admin/reviews/decision', [\App\Controllers\AdminManagementController::class, 'reviewDecision']);
$router->get('/admin/payments', [\App\Controllers\PaymentController::class, 'adminIndex']);
$router->get('/admin/reports', [\App\Controllers\ReportsController::class, 'index']);
$router->get('/admin/reports/download', [\App\Controllers\ReportsController::class, 'download']);
$router->post('/admin/reports/send', [\App\Controllers\ReportsController::class, 'send']);
// The Recently sent shelf is the admin's own view of what went out, so clearing it is a desk action
// and not a deletion - the student keeps every report already shared with them.
$router->post('/admin/reports/clear-recent', [\App\Controllers\ReportsController::class, 'clearRecent']);
$router->post('/admin/payments/decision', [\App\Controllers\PaymentController::class, 'decision']);
$router->get('/admin/payments/revenue-receipt', [\App\Controllers\PaymentController::class, 'revenueReceipt']);
$router->post('/admin/payments/revenue-notify', [\App\Controllers\PaymentController::class, 'notifyDeveloper']);
$router->post('/admin/payments/settings', [\App\Controllers\AdminManagementController::class, 'savePaymentDetails']);
$router->get('/payments/new', [\App\Controllers\PaymentController::class, 'newPage']);
$router->post('/payments', [\App\Controllers\PaymentController::class, 'submit']);
$router->get('/payments/receipt/download', [\App\Controllers\PaymentController::class, 'receipt']);
$router->get('/payments/proof', [\App\Controllers\PaymentController::class, 'proof']);

$requestMethod = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$requestUri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';

// A student who opens the page a notification pointed at has seen what it was about, so the
// sidebar badge for that area is settled before the page renders. Announcements are left alone on
// purpose: the inbox only lists unread items, and emptying it stays an action taken inside it.
if (($_SESSION['user']['role'] ?? null) === 'student') {
    $seenBasePath = rtrim(parse_url((string) ($app['url'] ?? ''), PHP_URL_PATH) ?: '', '/');
    $seenPath = parse_url($requestUri, PHP_URL_PATH) ?: '/';
    if ($seenBasePath !== '' && str_starts_with($seenPath, $seenBasePath)) {
        $seenPath = '/' . ltrim(substr($seenPath, strlen($seenBasePath)), '/');
    }
    $seenArea = \App\Services\NotificationService::areaForPage($seenPath);
    if ($seenArea !== '') {
        try {
            (new \App\Services\NotificationService(\App\Core\Database::connection()))
                ->markAreaSeen((int) ($_SESSION['user']['id'] ?? 0), $seenArea);
        } catch (\Throwable $exception) {
            // Counters are derived data; the workspace still has to open when they cannot be kept.
        }
    }
}

$router->dispatch($requestMethod, $requestUri);
