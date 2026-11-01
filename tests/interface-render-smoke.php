<?php
declare(strict_types=1);

/**
 * Admin and student workspace render smoke test.
 *
 * Runs on a scratch database only. It boots the schema, signs in as a seeded admin and a seeded
 * student through the session, publishes real notifications through NotificationService, and then
 * renders the pages those two roles depend on: the dashboard with its sidebar badges, the
 * announcement board, the two chat pages, the recent activity report, the student inbox, the account
 * settings page and the five workspaces that carry the bulk delete tool. A page that fatals, a badge
 * that stays at zero while unread notifications exist, a conversation that hides the news list, that
 * loses one of its three chat panes or prints a bubble without the sender line above it, or a purge
 * card without its confirmation phrase all fail this test. The desk's own clearing actions are driven
 * through ChatService here as well, because the list, the badge and the count have to agree after a
 * thread is hidden and then revived by a new message from the student.
 *
 *   /Applications/XAMPP/xamppfiles/bin/php tests/interface-render-smoke.php --database=tsg_render_check_tmp
 *
 * Add --drop=1 to remove the scratch database afterwards.
 */

define('BASE_PATH', dirname(__DIR__));
foreach (
    [
        '/app/Core/Env.php',
        '/app/Core/View.php',
        '/app/Core/Security.php',
        '/app/Core/Database.php',
        '/app/Core/AuthMiddleware.php',
        '/app/Core/RateLimiter.php',
        '/app/Core/PurgeGuard.php',
        '/app/Services/EmailLayout.php',
        '/app/Services/EmailMessages.php',
        '/app/Services/AccountIdentity.php',
        '/app/Services/AdminAttention.php',
        '/app/Services/MailService.php',
        '/app/Services/NotificationService.php',
        '/app/Services/ChatService.php',
        '/app/Services/AuthService.php',
        '/app/Services/CourseDuration.php',
        '/app/Services/LessonMedia.php',
        '/app/Services/CourseGrading.php',
        '/app/Services/AttendanceService.php',
        '/app/Controllers/DashboardController.php',
        '/app/Controllers/NotificationController.php',
        '/app/Controllers/AccountController.php',
        '/app/Controllers/AssignmentController.php',
        '/app/Controllers/ChatController.php',
        '/app/Controllers/QuizController.php',
        '/app/Controllers/CourseAdminController.php',
        '/app/Controllers/PublicPageController.php',
        '/app/Controllers/StudentManagementController.php',
    ] as $file
) {
    require BASE_PATH . $file;
}

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\Env;
use App\Core\Security;
use App\Controllers\AccountController;
use App\Controllers\AssignmentController;
use App\Controllers\ChatController;
use App\Controllers\CourseAdminController;
use App\Controllers\PublicPageController;
use App\Controllers\StudentManagementController;
use App\Controllers\DashboardController;
use App\Controllers\NotificationController;
use App\Controllers\QuizController;
use App\Services\AccountIdentity;
use App\Services\AdminAttention;
use App\Services\ChatService;
use App\Services\NotificationService;

Env::load(BASE_PATH . '/.env');
$options = getopt('', ['database::', 'drop::']);
if (($options['database'] ?? '') !== '') {
    putenv('DB_NAME=' . $options['database']);
}
$dbName = (string) (getenv('DB_NAME') ?: '');

$failures = [];
// A passing line must read as a fact that holds, so every message below states what was verified.
$assert = static function (bool $condition, string $passMessage, string $failMessage) use (&$failures): void {
    if ($condition) {
        echo '  ok ' . $passMessage . "\n";
        return;
    }
    $failures[] = $failMessage;
    echo '  FAIL ' . $failMessage . "\n";
};

// This guard has to stop the run rather than just record a failure: as a soft assertion it once
// let the whole suite continue against the development database and leave fixture accounts behind.
if (preg_match('/(tmp|check|scratch|test)/i', $dbName) !== 1) {
    fwrite(STDERR, 'Refusing to run: --database=' . $dbName . " does not look like a scratch database.\n");
    exit(1);
}
echo "  ok Running against the scratch database " . $dbName . "\.\n";

$mysql = '/Applications/XAMPP/xamppfiles/bin/mysql';
$user = (string) (getenv('DB_USER') ?: 'root');
$password = (string) (getenv('DB_PASS') ?: '');
$host = (string) (getenv('DB_HOST') ?: '127.0.0.1');
$port = (string) (getenv('DB_PORT') ?: '3306');
$credentials = '-u ' . escapeshellarg($user) . ($password === '' ? '' : ' -p' . escapeshellarg($password))
    . ' -h ' . escapeshellarg($host) . ' -P ' . escapeshellarg($port);
$exists = trim((string) shell_exec($mysql . ' ' . $credentials . ' -N -B -e ' .
    escapeshellarg("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='" . $dbName . "'")));
if ($exists !== '1') {
    shell_exec($mysql . ' ' . $credentials . ' -e ' . escapeshellarg('CREATE DATABASE `' . $dbName . '`'));
    shell_exec($mysql . ' ' . $credentials . ' ' . escapeshellarg($dbName) . ' < ' . escapeshellarg(BASE_PATH . '/database/migrations/001_initial_schema.sql'));
    echo "bootstrapped scratch schema into " . $dbName . "\n";
}
$assert($exists === '1' || (int) shell_exec($mysql . ' ' . $credentials . ' -N -B -e ' .
    escapeshellarg("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='" . $dbName . "'")) >= 20,
    'The scratch database holds the full schema.', 'The scratch database has no schema; import database/migrations/001_initial_schema.sql and rerun.');

$db = Database::connection();
$adminEmail = 'render-smoke-admin@example.test';
$studentEmail = 'render-smoke-student@example.test';

/* --------------------------------------------------------------- environment surface */
echo "\n-- .env handover surface\n";
$envFile = BASE_PATH . '/.env';
$exampleFile = BASE_PATH . '/.env.example';
$keysIn = static function (string $path): array {
    $keys = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2 && trim($parts[0]) !== '') $keys[] = trim($parts[0]);
    }
    return $keys;
};
$exampleKeys = $keysIn($exampleFile);
$liveKeys = $keysIn($envFile);
$missing = array_diff($exampleKeys, $liveKeys);
$assert($missing === [], '.env carries every key .env.example advertises (' . count($exampleKeys) . ' keys).',
    '.env.example advertises keys the live .env does not carry: ' . implode(', ', $missing));
foreach (['MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_ADDRESS', 'DEVELOPER_NOTIFICATION_MAIL_ADDRESS'] as $required) {
    $assert(in_array($required, $exampleKeys, true), '.env.example documents ' . $required . ' for the handover.',
        '.env.example is missing ' . $required . ', so the company would not know to set it.');
}
foreach ($exampleKeys as $key) {
    $assert(preg_match('/^[A-Z][A-Z0-9_]*$/', $key) === 1, 'Key ' . $key . ' is a valid variable name.',
        'Key "' . $key . '" in .env.example is not a valid variable name.');
}
$assert(getenv('DEVELOPER_NOTIFICATION_MAIL_ADDRESS') !== false, 'DEVELOPER_NOTIFICATION_MAIL_ADDRESS is loaded from .env.',
    'DEVELOPER_NOTIFICATION_MAIL_ADDRESS is not loaded, so developer mail has no destination.');
$assert(\App\Services\MailService::developerAddress() === (string) getenv('DEVELOPER_NOTIFICATION_MAIL_ADDRESS'),
    'Developer mail resolves to DEVELOPER_NOTIFICATION_MAIL_ADDRESS.',
    'Developer mail does not follow DEVELOPER_NOTIFICATION_MAIL_ADDRESS, so the handover would still reach the old inbox.');

/* ------------------------------------------------------------------------ scratch data */
echo "\n-- seeded workspaces\n";
// Rows a previous run left behind would move the counters, so the fixture course, the fixture
// accounts and everything hanging off them are removed first - and only those, because the scratch
// database may also hold a real admin and their enrolments, which have to survive.
$edges = $db->query("
    SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
$parentsOf = [];
foreach ($edges as $edge) {
    $parentsOf[$edge['REFERENCED_TABLE_NAME']][] = $edge;
}
$roots = [
    'courses|slug' => ['courses', 'slug', $db->quote('render-smoke-course')],
    'users|email' => ['users', 'email', $db->quote($adminEmail) . ',' . $db->quote($studentEmail)],
];
$targets = $roots;
// The graph is walked outwards from the fixture rows. Discovery only collects here; deleting
// happens afterwards, because a module row can only leave once its lesson rows are gone.
for ($pass = 0; $pass < 6; $pass++) {
    $changed = false;
    foreach ($targets as $criteria) {
        foreach ($parentsOf[$criteria[0]] ?? [] as $edge) {
            $key = $edge['TABLE_NAME'] . '|' . $edge['COLUMN_NAME'];
            if ($edge['TABLE_NAME'] === $criteria[0] || isset($targets[$key])) continue;
            $targets[$key] = [
                $edge['TABLE_NAME'],
                $edge['COLUMN_NAME'],
                '(SELECT `' . $edge['REFERENCED_COLUMN_NAME'] . '` FROM `' . $criteria[0] . '` WHERE `' . $criteria[1] . '` IN (' . $criteria[2] . '))',
            ];
            $changed = true;
        }
    }
    if (!$changed) break;
}
// Discovery is breadth first, so the queue is drained back to front: deepest children first,
// the two fixture roots last. A row still held by an undiscovered foreign key is retried.
$pending = array_reverse($targets, true);
for ($pass = 0; $pass < 6 && $pending !== []; $pass++) {
    foreach ($pending as $key => $criteria) {
        if (($key === 'courses|slug' || $key === 'users|email') && count($pending) > 2) continue;
        try {
            $db->exec('DELETE FROM `' . $criteria[0] . '` WHERE `' . $criteria[1] . '` IN (' . $criteria[2] . ')');
            unset($pending[$key]);
        } catch (PDOException $exception) {
            if (!in_array((int) ($exception->errorInfo[1] ?? 0), [1146, 1451], true)) throw $exception;
        }
    }
}
foreach ($roots as $key => $criteria) {
    try {
        $db->exec('DELETE FROM `' . $criteria[0] . '` WHERE `' . $criteria[1] . '` IN (' . $criteria[2] . ')');
    } catch (PDOException $exception) {
        if ((int) ($exception->errorInfo[1] ?? 0) !== 1451) throw $exception;
    }
}

$insertUser = $db->prepare("INSERT INTO users(full_name,email,password_hash,role,status,email_verified_at) VALUES(?,?,?,?,'active',UTC_TIMESTAMP())");
$insertUser->execute(['Render Smoke Admin', $adminEmail, 'unusable-hash', 'admin']);
$adminId = (int) $db->lastInsertId();
$insertUser->execute(['Render Smoke Student', $studentEmail, 'unusable-hash', 'student']);
$studentId = (int) $db->lastInsertId();
$db->prepare("INSERT INTO courses(title,slug,visibility,created_by) VALUES('Render Smoke Course','render-smoke-course','published',?)")->execute([$adminId]);
$courseId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO course_modules(course_id,title) VALUES(?,?)')->execute([$courseId, 'Smoke module']);
$moduleId = (int) $db->lastInsertId();
$db->prepare("INSERT INTO student_course_access(student_id,course_id,status,granted_by) VALUES(?,?,'active',?)")->execute([$studentId, $courseId, $adminId]);
$lessonInsert = $db->prepare("INSERT INTO lessons(module_id,title,body,status) VALUES(?,?,?,'published')");
$lessonInsert->execute([$moduleId, 'Smoke lesson', 'Body']);
$lessonId = (int) $db->lastInsertId();
$db->prepare("INSERT INTO quizzes(course_id,week_number,title,quiz_type,status,created_by) VALUES(?,1,'Smoke quiz','practice','released',?)")->execute([$courseId, $adminId]);
$quizId = (int) $db->lastInsertId();
$db->prepare("INSERT INTO assignments(course_id,title,instructions,max_marks,status,created_by) VALUES(?,?,'Brief',100,'published',?)")->execute([$courseId, 'Smoke assignment', $adminId]);
$assignmentId = (int) $db->lastInsertId();

$notifier = new NotificationService($db);
// Links are written exactly as the controllers write them, because the badge a student sees is
// decided by the page the notification sends them to.
foreach ([
    [NotificationService::ANNOUNCEMENT, 'Class on Friday', 'The live class moves to Friday.', 'announcement', 0, '/notifications'],
    [NotificationService::LESSON, 'New lesson published', 'Smoke lesson is ready for you.', 'lesson', $lessonId, '/learning/lesson?lesson_id=' . $lessonId],
    [NotificationService::QUIZ, 'New quiz released', 'Smoke quiz is open.', 'quiz', $quizId, '/learning/quizzes?course_id=' . $courseId],
    [NotificationService::ASSIGNMENT, 'New assignment', 'Smoke assignment has been uploaded.', 'assignment', $assignmentId, '/learning/assignments?course_id=' . $courseId],
    [NotificationService::COURSE, 'Course updated', 'The syllabus has been refreshed.', 'course', $courseId, '/learning?course_id=' . $courseId],
    [NotificationService::PAYMENT, 'Payment approved', 'Your payment has been approved.', 'payment', $courseId, '/student/payments'],
    // The only grade the Academy raises comes out of the assignment review, so it lands on the
    // Assignments badge - a result page that never changed must not light the quiz link.
    [NotificationService::GRADE, 'Assignment graded', 'Your submission has been marked.', 'assignment_submission', $assignmentId, '/learning/assignments?course_id=' . $courseId],
    [NotificationService::CERTIFICATE, 'Certificate ready', 'Your certificate is available.', 'certificate', $courseId, '/student/courses'],
] as [$category, $title, $body, $sourceType, $sourceId, $link]) {
    if ($sourceId > 0) {
        $notifier->notifyCourse($courseId, $category, $title, $body, $sourceType, $sourceId, $link, $adminId);
    } else {
        $notifier->notifyStudents([$studentId], $category, $title, $body, $sourceType, max(1, $courseId), $link, $adminId);
    }
}
$expectedBadges = ['notifications' => 1, 'lessons' => 1, 'quizzes' => 1, 'assignments' => 2, 'courses' => 2, 'payments' => 1];
$observedBadges = $notifier->unreadByArea($studentId);
foreach ($expectedBadges as $area => $count) {
    $assert((int) ($observedBadges[$area] ?? -1) === $count,
        $area . ' badge counts ' . $count . ' unread notification(s).',
        $area . ' badge reports ' . json_encode($observedBadges[$area] ?? null) . ', expected ' . $count . ' (all badges: ' . json_encode($observedBadges) . ').');
}

/* -------------------------------------------------------------------------- rendering */
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/TSG-Academy-LMS/index.php';
$_SERVER['PHP_SELF'] = '/TSG-Academy-LMS/index.php';

$render = static function (callable $callable) use (&$failures): string {
    $previous = error_reporting(E_ALL & ~E_DEPRECATED);
    ob_start();
    try {
        $callable();
    } catch (Throwable $exception) {
        ob_end_clean();
        error_reporting($previous);
        $failures[] = 'Rendering crashed: ' . $exception->getMessage();
        echo '  x rendering crashed: ' . $exception->getMessage() . "\n";
        return '';
    }
    $html = (string) ob_get_clean();
    error_reporting($previous);
    return $html;
};

$signIn = static function (int $id, string $name, string $email, string $role): void {
    $_SESSION = ['user' => ['id' => $id, 'name' => $name, 'email' => $email, 'role' => $role]];
    $_POST = ['_csrf' => Security::csrfToken()];
    $_GET = [];
};

$checkPage = static function (string $label, string $path, callable $callable, array $needles) use ($render, $assert): void {
    echo "\n-- " . $label . "\n";
    $_SERVER['REQUEST_URI'] = '/TSG-Academy-LMS' . $path;
    $html = $render($callable);
    $assert($html !== '', $label . ' rendered a full page.', $label . ' produced no output.');
    foreach ($needles as $needle) {
        $assert(stripos($html, $needle) !== false, $label . ' shows "' . $needle . '".', $label . ' does not contain "' . $needle . '".');
    }
};

$signIn($adminId, 'Render Smoke Admin', $adminEmail, 'admin');
$checkPage('Admin dashboard', '/dashboard', static fn() => (new DashboardController())->show(), [
    'dashboard-side-nav',
    'My account',
]);
// The board is publishing only now: the reports it used to carry have their own page, and the
// messages have theirs, so a reader is handed a link rather than a second table.
$checkPage('Announcement workspace', '/admin/announcements', static fn() => (new NotificationController())->adminIndex(), [
    'Clear the announcement board',
    'DELETE ALL ANNOUNCEMENTS',
    'Open the chat desk',
    'Open recent activity',
]);
$checkPage('Admin chat desk', '/admin/chats', static fn() => (new ChatController())->adminIndex(), [
    'No conversations yet',
    'Waiting for you',
    'How to use the chat desk',
]);
$checkPage('Recent activity report', '/admin/activity', static fn() => (new NotificationController())->activity(), [
    'Automated student updates',
    'Email delivery log',
    'CLEAR RECENT ACTIVITY',
    'admin-card-testing',
    'How to use recent activity',
]);
$checkPage('Assignment workspace', '/admin/assignments', static fn() => (new AssignmentController())->adminIndex(), [
    'DELETE ALL ASSIGNMENTS',
    'admin-card-testing',
    'confirm_phrase',
]);
$checkPage('Quiz workspace', '/admin/quizzes', static fn() => (new QuizController())->adminIndex(), [
    'DELETE ALL QUIZZES',
    'admin-card-testing',
]);
$checkPage('Lesson library', '/admin/lessons', static fn() => (new CourseAdminController())->lessons(), [
    'DELETE ALL LESSONS',
    'admin-card-testing',
]);
$checkPage('Student directory', '/admin/students', static fn() => (new StudentManagementController())->index(), [
    'DELETE ALL STUDENTS',
    'admin-card-testing',
    'confirm_phrase',
]);
// A student row has to print the number an admin is quoted back - the Academy ID on the account,
// the same one the student sees on their own settings page - and never the internal row id.
$directoryHtml = $render(static fn() => (new StudentManagementController())->index());
$storedReference = trim((string) $db->query('SELECT account_reference FROM users WHERE id = ' . (int) $studentId)->fetchColumn());
$directoryId = $storedReference !== '' ? $storedReference : AccountIdentity::derived($studentId);
$assert(str_contains($directoryHtml, 'ID · ' . $directoryId), 'The directory prints the Academy ID ' . $directoryId . ' for the fixture student.',
    'The directory does not print ' . $directoryId . ' for the fixture student.');
$assert(preg_match('/ID · \d+<\/small>/', $directoryHtml) !== 1, 'No student row prints a bare numeric id.',
    'A student row still prints the internal row number instead of the Academy ID.');
// An administrator can type an ID by hand, and that is the number the student quotes at the Academy
// desk - so the directory has to print the stored reference, not whichever row the account sits on.
$db->prepare('UPDATE users SET account_reference = ? WHERE id = ?')->execute(['TSG-CER-2026-001', $studentId]);
$typedHtml = $render(static fn() => (new StudentManagementController())->index());
$assert(str_contains($typedHtml, 'ID · TSG-CER-2026-001'), 'A hand-set Academy ID is what the directory prints.',
    'The directory ignored the stored Academy ID and printed something else.');
// The search box promises a student ID, and the ID it shows is now the Academy one.
$_GET = ['q' => 'TSG-CER-2026-001'];
$foundHtml = $render(static fn() => (new StudentManagementController())->index());
$_GET = [];
$assert(str_contains($foundHtml, 'Render Smoke Student') && str_contains($foundHtml, 'ID · TSG-CER-2026-001'),
    'Pasting the Academy ID into the search finds that student.',
    'Searching for the Academy ID printed no matching student row.');
$_GET = ['student_id' => (string) $studentId];
$detailHtml = $render(static fn() => (new StudentManagementController())->index());
$_GET = [];
$assert(str_contains($detailHtml, 'ID TSG-CER-2026-001'), 'The student detail card prints the ID the row prints.',
    'The detail card shows a different number from the directory row it came from.');
$db->exec('UPDATE users SET account_reference = NULL WHERE id = ' . (int) $studentId);
$checkPage('Admin account settings', '/admin/account', static fn() => (new AccountController())->adminSettings(), [
    'Admin ID',
    'current_password',
    'TSG-ADM-',
    'account-settings-page',
    'Student ID scheme',
    'student_id_prefix',
    'student_id_start',
    'admin-id-form',
    'data-admin-modal-open="admin-id-change"',
    'data-admin-modal-open="student-id-scheme-confirm"',
]);
$html = $render(static fn() => (new AccountController())->adminSettings());
$assert(str_contains($html, 'Confirm new ID') && str_contains($html, 'Confirm and save'),
    'Both ID dialogs ask for an explicit confirmation.', 'An ID change can be submitted without a confirmation dialog.');

$signIn($studentId, 'Render Smoke Student', $studentEmail, 'student');
$checkPage('Student dashboard badges', '/dashboard', static fn() => (new DashboardController())->show(), [
    'dashboard-nav-badge',
    'Notifications',
    'Account settings',
    '/student/settings',
]);
$checkPage('Student inbox', '/notifications', static fn() => (new NotificationController())->studentIndex(), [
    'Assignment graded',
    'Payment approved',
    'Mark all read',
    'Open update',
    'How to use your inbox',
]);
$checkPage('Student chat list', '/student/chats', static fn() => (new ChatController())->studentIndex(), [
    'No conversations yet',
    'How to use your chats',
]);
// The shortcut only exists while something is unread: a clean inbox has nothing to clear, and its
// sidebar badges are already at zero, so the button would only invite a pointless click.
$db->exec('UPDATE notification_recipients SET read_at = UTC_TIMESTAMP() WHERE student_id = ' . (int) $studentId);
$readAllHtml = $render(static fn() => (new NotificationController())->studentIndex());
$assert(!str_contains($readAllHtml, 'notifications/read-all'), 'An inbox with nothing unread hides the Mark all read shortcut.',
    'The read-all form is offered even though every notice is already read.');
$db->exec('UPDATE notification_recipients SET read_at = NULL WHERE student_id = ' . (int) $studentId);
$unreadAgainHtml = $render(static fn() => (new NotificationController())->studentIndex());
$assert(str_contains($unreadAgainHtml, 'notifications/read-all') && str_contains($unreadAgainHtml, 'Mark all read'),
    'The shortcut returns the moment a notification is unread again.',
    'Re-opening the unread state did not bring the read-all form back.');
// News and conversation are deliberately two pages. The sidebar links to Chats, but the inbox itself
// must offer no control over messages, because clearing a chat is not the same act as reading news.
$assert(!str_contains($unreadAgainHtml, '/student/chats/read-all') && !str_contains($unreadAgainHtml, '/student/chats/delete'),
    'The inbox holds no chat controls.',
    'A conversation action leaked onto the notifications page.');
$checkPage('Student account settings', '/student/settings', static fn() => (new AccountController())->studentSettings(), [
    'Student ID',
    'TSG-STU-',
    'new_password',
    'readonly',
    'account-settings-page',
]);
$html = $render(static fn() => (new AccountController())->studentSettings());
$assert(stripos($html, 'Student ID scheme') === false, 'A student is not offered the ID scheme card.',
    'The student settings page exposes the scheme an admin owns.');
$assert(stripos($html, 'account-id-input') === false, 'A student sees their ID as read-only text.',
    'The student settings page renders an editable ID field.');

/* ------------------------------------------------------------------- catalogue call to action */
// The catalogue is the one page a signed-in student and a visitor must not see the same way: the
// visitor still has to open an account, the student only has to pay. Both readings are checked, and
// from the same fixture course, so the branch is the session and not the data.
echo "\n-- catalogue CTA by role\n";
$_SERVER['REQUEST_URI'] = '/TSG-Academy-LMS/courses';
$studentCatalogue = $render(static fn() => (new PublicPageController())->courses());
$assert(str_contains($studentCatalogue, 'Render Smoke Course')
    && str_contains($studentCatalogue, 'Submit payment for further onboarding')
    && str_contains($studentCatalogue, '/payments/new?course_id='),
    'A signed-in student\'s course card offers the payment step for the course it shows.',
    'The student catalogue does not lead with the payment step: ' . substr($studentCatalogue, 0, 200));
$assert(str_contains($studentCatalogue, 'View course details') && str_contains($studentCatalogue, '/courses/render-smoke-course'),
    'The student card keeps a way to read the syllabus, so paying does not mean buying blind.',
    'The student card dropped its details link.');
$_SESSION = [];
$guestCatalogue = $render(static fn() => (new PublicPageController())->courses());
$assert(str_contains($guestCatalogue, 'Explore course') && !str_contains($guestCatalogue, 'Submit payment for further onboarding'),
    'A visitor\'s card still leads with the course rather than a checkout they cannot finish.',
    'The guest catalogue is showing a signed-in student\'s payment CTA.');

/* ------------------------------------------------------------------------ badge wiring */
echo "\n-- sidebar badge wiring\n";
$signIn($studentId, 'Render Smoke Student', $studentEmail, 'student');
$_SERVER['REQUEST_URI'] = '/TSG-Academy-LMS/student/quizzes';
$html = $render(static fn() => (new DashboardController())->show());
$assert(str_contains($html, 'dashboard-nav-badge'), 'The student sidebar renders unread badges.',
    'The student sidebar shows no unread badge at all.');
preg_match_all('/<a[^>]*href="[^"]*\/student\/quizzes"[^>]*>(?<body>.*?)<\/a>/s', $html, $matches);
$quizLink = $matches['body'][0] ?? '';
$assert(str_contains($quizLink, 'dashboard-nav-badge'), 'The quizzes link carries a badge next to its label.',
    'The quizzes link carries no badge although unread quiz notifications exist.');
$assert(!str_contains($quizLink, '>0<'), 'No badge on the quizzes link renders a zero.', 'A badge renders a literal zero on the quizzes link.');

/* ------------------------------------------------------- badge attribution and seen state */
echo "\n-- which badge a notification lights\n";
// A grade shares its category name with course results but is raised by the assignment review, so
// the page it sends the student to decides the badge - not the category alone.
$assert(NotificationService::areaFor(NotificationService::GRADE, '/learning/assignments?course_id=' . $courseId) === 'assignments',
    'A grade linked to the Assignments page counts on the Assignments badge.',
    'A grade pointing at the Assignments page lit ' . NotificationService::areaFor(NotificationService::GRADE, '/learning/assignments?course_id=' . $courseId) . '.');
$assert(NotificationService::areaFor(NotificationService::QUIZ, '/learning/quizzes?course_id=' . $courseId) === 'quizzes',
    'A quiz release still counts on the Quizzes badge.',
    'A quiz release lit ' . NotificationService::areaFor(NotificationService::QUIZ, '/learning/quizzes?course_id=' . $courseId) . '.');
// Older rows, and anything the harness publishes for a test, carry a link the map has never seen;
// those fall back to the category instead of disappearing from every counter.
$assert(NotificationService::areaFor(NotificationService::LESSON, '/smoke') === 'lessons',
    'An unknown link falls back to the category.',
    'An unknown lesson link lit ' . NotificationService::areaFor(NotificationService::LESSON, '/smoke') . '.');
$assert(NotificationService::areaFor('something-new', null) === 'notifications',
    'A category the sidebar does not know still reaches the inbox counter.',
    'An unknown category lit ' . NotificationService::areaFor('something-new', null) . '.');
// Listing the inbox is not reading it, so the inbox page settles nothing on its own.
$assert(NotificationService::areaForPage('/notifications') === '',
    'Opening the inbox clears no badge.',
    'The inbox page settled the ' . NotificationService::areaForPage('/notifications') . ' area.');
$assert(NotificationService::areaForPage('/learning/assignments') === 'assignments'
    && NotificationService::areaForPage('/learning/lesson') === 'lessons'
    && NotificationService::areaForPage('/learning') === 'courses',
    'Each workspace page settles its own area, longest path first.',
    'Workspace pages settled as ' . json_encode([
        NotificationService::areaForPage('/learning/assignments'),
        NotificationService::areaForPage('/learning/lesson'),
        NotificationService::areaForPage('/learning'),
    ]));

$seenAssignments = $notifier->markAreaSeen($studentId, 'assignments');
$assert($seenAssignments === 2, 'Opening the Assignments page marks the two items waiting there seen.',
    'Opening the Assignments page settled ' . $seenAssignments . ' of 2 unread items.');
$areasAfterSeen = $notifier->unreadByArea($studentId);
$assert(($areasAfterSeen['assignments'] ?? -1) === 0
        && ($areasAfterSeen['quizzes'] ?? -1) === 1
        && ($areasAfterSeen['notifications'] ?? -1) === 1,
    'Settling one badge leaves every other counter alone.',
    'After settling Assignments the counters read ' . json_encode($areasAfterSeen) . '.');
$assert($notifier->markAreaSeen($studentId, 'assignments') === 0,
    'A second visit to the same page has nothing left to settle.',
    'A repeat visit settled items the student had already seen.');
// Put the rows back unread so a rerun against the same scratch database measures the same deltas.
$db->exec('UPDATE notification_recipients SET read_at = NULL WHERE student_id = ' . (int) $studentId);

/* ------------------------------------------------------------------ usage guide card */
echo "\n-- the closing usage guide\n";
// One component renders the walkthrough at the foot of every workspace page, in the same shape as
// the public course page's steps, so a reader who learns it once re-reads nothing on the next page.
// Placement is part of the rule as well: it is always the last card on the page.
$signIn($adminId, 'Render Smoke Admin', $adminEmail, 'admin');
$_SERVER['REQUEST_URI'] = '/TSG-Academy-LMS/admin/lessons';
$html = $render(static fn() => (new CourseAdminController())->lessons());
$assert(str_contains($html, 'admin-usage-card') && str_contains($html, 'admin-usage-eyebrow') && str_contains($html, 'admin-usage-title'),
    'The Lesson library closes with its labelled guide card.',
    'The Lesson library renders no labelled usage card.');
$assert(preg_match('/<ol class="admin-usage-list">.*<span class="admin-usage-number" aria-hidden="true">1<\/span>.*<span class="admin-usage-number" aria-hidden="true">2<\/span>/s', $html) === 1,
    'The steps are an ordered list counted from one.',
    'The guide is not a numbered list starting at 1: ' . substr((string) strstr($html, 'admin-usage-list'), 0, 200));
$assert(str_contains($html, 'admin-usage-copy"><strong>') && str_contains($html, 'admin-usage-detail'),
    'Each step prints its short title above the sentence that explains it.',
    'A guide step carries no title or no detail.');
// The walkthrough is the last card on the page, so it never cuts the workflow above it in half -
// and that holds even on a desk that ends with a guarded bulk delete.
$assert(stripos($html, 'admin-usage-card') > stripos($html, 'admin-card-testing'),
    'The guide sits below the bulk delete card at the very bottom of the Lesson library.',
    'The Lesson library prints its walkthrough above its bulk delete card.');
// The two desks that had no walkthrough now close with one, in the same place.
foreach (
    [
        'announcement board' => [static fn() => (new NotificationController())->adminIndex(), 'How to use the announcement board'],
        'student directory' => [static fn() => (new StudentManagementController())->index(), 'How to use the student directory'],
    ] as $deskLabel => $deskCase
) {
    $deskHtml = $render($deskCase[0]);
    $assert(str_contains($deskHtml, $deskCase[1]), 'The ' . $deskLabel . ' closes with its own walkthrough.',
        'The ' . $deskLabel . ' prints no "' . $deskCase[1] . '" card.');
    $assert(stripos($deskHtml, 'admin-usage-card') > stripos($deskHtml, 'admin-card-testing'),
        'The ' . $deskLabel . ' prints its walkthrough below its bulk delete card.',
        'The ' . $deskLabel . ' guide is not the last card on the page.');
}
// The dashboard is a summary of the pages around it, each of which already closes with a guide, so
// it deliberately prints none of its own.
$signIn($studentId, 'Render Smoke Student', $studentEmail, 'student');
$_SERVER['REQUEST_URI'] = '/TSG-Academy-LMS/dashboard';
$html = $render(static fn() => (new DashboardController())->show());
$assert(!str_contains($html, 'admin-usage-card'), 'The student workspace closes with no guide card.',
    'The student dashboard still prints a usage card.');
// The practice lab closes with a walkthrough for every student, including one whose course has no
// challenge set yet - the page above them explains the empty list, not the absence of a guide.
$_SERVER['REQUEST_URI'] = '/TSG-Academy-LMS/learning/challenges';
$html = $render(static fn() => \App\Core\View::render('learning/coding-challenges', [
    'title' => 'Coding challenges',
    'user' => ['id' => $studentId, 'name' => 'Render Smoke Student', 'email' => $studentEmail, 'role' => 'student'],
    'course' => ['id' => $courseId, 'title' => 'Render Smoke Course'],
    'challenges' => [],
    'hasCodingCourseAccess' => false,
    'message' => null,
]));
$assert(str_contains($html, 'How to use coding challenges') && str_contains($html, 'Your practice lab'),
    'The student challenge page closes with its walkthrough even without course access.',
    'The student challenge page renders no usage card.');
$assert(stripos($html, 'admin-usage-card') !== false && stripos($html, 'admin-usage-card') < stripos($html, 'Browser-based practice'),
    'The standing practice-only caveat still follows the walkthrough.',
    'The challenge page prints its caveat above the guide.');
$assert(stripos($html, 'admin-usage-card') < stripos($html, 'For web development students'),
    'The course-access note closes the challenge page under the walkthrough instead of opening it.',
    'The challenge page meets the student with the access note before the guide.');
// The placement rule holds on every surface that carries a walkthrough: a standing note never sits
// above it, so the guide is always the last card and the notes are the page's closing lines. The one
// exception is a precondition that replaces the workflow altogether - a desk with nothing to work on
// yet explains itself before it explains how to work.
echo "\n-- no standing note above a walkthrough\n";
$preconditions = ['Create a course first'];
foreach (glob(BASE_PATH . '/app/Views/*/*.php') ?: [] as $viewFile) {
    $source = (string) file_get_contents($viewFile);
    $guideAt = strpos($source, "components/usage-guide.php");
    if ($guideAt === false) continue;
    $relative = str_replace(BASE_PATH . '/app/Views/', '', $viewFile);
    preg_match_all('/class="[^"]*dashboard-note[^"]*"[^<]*<strong>([^<]+)<\/strong>/', $source, $notes, PREG_OFFSET_CAPTURE);
    foreach ($notes[0] as $index => $note) {
        $label = trim((string) $notes[1][$index][0]);
        if (in_array($label, $preconditions, true)) continue;
        $assert((int) $note[1] > $guideAt, $relative . ' keeps its note "' . $label . '" under the walkthrough.',
            $relative . ' prints the note "' . $label . '" above the walkthrough.');
    }
}

/* --------------------------------------------------------------- confirmation overlay */
echo "\n-- confirmation overlay\n";
$signIn($adminId, 'Render Smoke Admin', $adminEmail, 'admin');
$_SERVER['REQUEST_URI'] = '/TSG-Academy-LMS/admin/announcements';
$_SESSION['_flash'] = 'Announcement published to one student.';
$_SESSION['_flash_error'] = 'The recipient list could not be loaded.';
$html = $render(static fn() => (new NotificationController())->adminIndex());
$assert(substr_count($html, 'flash-toast-card is-success') === 1 && substr_count($html, 'flash-toast-card is-error') === 1,
    'Both outcomes of this page arrive as one overlay card each.',
    'The announcement page rendered ' . substr_count($html, 'flash-toast-card is-success') . ' success and ' . substr_count($html, 'flash-toast-card is-error') . ' error cards.');
$assert(str_contains($html, 'flash-toast-label">Success<') && str_contains($html, 'flash-toast-label">Error<'),
    'Each card states its outcome in a word, not only in a colour.',
    'An overlay card carries no written outcome.');
$assert(str_contains($html, 'Announcement published to one student.') && str_contains($html, 'The recipient list could not be loaded.') && str_contains($html, 'role="alert"'),
    'The full message is carried into the card and the failure is announced to a reader.',
    'A confirmation sentence or the alert role is missing from the overlay.');
$assert(stripos($html, 'admin-alert-success') === false && stripos($html, 'admin-alert-error') === false,
    'No banner is left stacked above the page content.',
    'The announcement page still prints a flash banner.');
$assert(!str_contains($html, 'Testing tool'), 'The bulk delete card no longer wears a testing label in its header.',
    'The bulk delete card still shows a Testing tool pill.');
// Nothing is floated over a page that has nothing to report, so the overlay never hides a
// confirmation that belongs to some earlier action.
$quiet = $render(static fn() => (new NotificationController())->adminIndex());
$assert(!str_contains($quiet, 'flash-toast-layer'), 'A page with no confirmation prints no overlay.',
    'An empty overlay layer was printed with nothing to say.');

/* -------------------------------------------------------------------- account identity */
echo "\n-- student ID scheme\n";
$schemeBefore = AccountIdentity::scheme($db);
$firstMint = AccountIdentity::issue($db);
$assert(str_starts_with($firstMint, $schemeBefore['prefix'] . '-'), 'A new sign-up is issued an ID in the saved format: ' . $firstMint . '.',
    'Issuing an ID ignored the saved prefix and produced ' . $firstMint . '.');
$held = $db->prepare('SELECT COUNT(*) FROM users WHERE account_reference = ?');
$held->execute([$firstMint]);
$assert((int) $held->fetchColumn() === 0, 'The minted ID belongs to nobody yet, so two sign-ups cannot share it.',
    'Issuing an ID handed back a number that is already on an account.');
$assert(AccountIdentity::scheme($db)['next'] > $schemeBefore['next'], 'The counter moved past ' . $firstMint . ' instead of reusing it.',
    'Issuing an ID left the counter where it was.');

// A row that predates the column has to be given a real scheme ID, not a derived guess, because
// the certificate is issued under the number the student can see on their own settings page.
$db->exec('UPDATE users SET account_reference = NULL WHERE email = ' . $db->quote($studentEmail));
$ensured = AccountIdentity::ensure($db, $studentId);
$assert(str_starts_with($ensured, $schemeBefore['prefix'] . '-'), 'An account with no ID is issued one from the scheme: ' . $ensured . '.',
    'ensure() did not mint a scheme ID for an account that had none.');

$renamed = AccountIdentity::setReference($db, $adminId, 'tsg-adm-smoke');
$assert($renamed === 'TSG-ADM-SMOKE', 'A typed Admin ID is stored upper-cased: ' . $renamed . '.',
    'setReference() stored ' . $renamed . ' instead of TSG-ADM-SMOKE.');
$clash = '';
try {
    AccountIdentity::setReference($db, $studentId, 'TSG-ADM-SMOKE');
} catch (\Throwable $exception) {
    $clash = $exception->getMessage();
}
$assert(stripos($clash, 'unique') !== false, 'A second account cannot take an ID that is already in use.',
    'Two accounts were allowed to answer to the same ID.');

$saved = AccountIdentity::saveScheme($db, 'tsg-smoke', 700, $adminId);
$assert($saved['prefix'] === 'TSG-SMOKE' && AccountIdentity::scheme($db)['next'] === 700,
    'The settings page stores the prefix and the first number it was given.',
    'saveScheme() did not persist both halves of the scheme.');
$db->prepare("INSERT INTO certificates(certificate_id,student_id,course_id,status,issued_at) VALUES(?,?,?,'issued',UTC_TIMESTAMP())")
    ->execute([$ensured, $studentId, $courseId]);
$tally = AccountIdentity::reissueStudents($db, $adminId);
$rank = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND id <= " . (int) $studentId)->fetchColumn();
$expected = AccountIdentity::format('TSG-SMOKE', 700 + $rank - 1);
$actual = (string) $db->query('SELECT account_reference FROM users WHERE id = ' . (int) $studentId)->fetchColumn();
$assert($actual === $expected, 'Re-issuing numbered the fixture student as ' . $actual . ', oldest account first.',
    'Re-issuing left the fixture student at ' . $actual . ' instead of ' . $expected . '.');
$carried = (string) $db->query('SELECT certificate_id FROM certificates WHERE student_id = ' . (int) $studentId)->fetchColumn();
$assert($carried === $actual, 'A certificate already issued follows its owner to the new number.',
    'The issued certificate stayed at ' . $carried . ' while the account moved to ' . $actual . '.');
$assert((int) $tally['students'] >= 1 && AccountIdentity::scheme($db)['next'] === 700 + (int) $tally['students'],
    'The counter leaves the re-numbered set behind at ' . AccountIdentity::scheme($db)['next'] . '.',
    'The counter did not clear the students that were just re-numbered.');

// Leave the scratch database as this section found it: the fixture accounts are deleted at the
// start of every run anyway, and no later assertion should have to know about these numbers.
$db->exec('DELETE FROM certificates WHERE student_id = ' . (int) $studentId);
$db->exec('UPDATE users SET account_reference = NULL WHERE email IN (' . $db->quote($adminEmail) . ',' . $db->quote($studentEmail) . ')');
AccountIdentity::saveScheme($db, $schemeBefore['prefix'], $schemeBefore['next'], $adminId);
$assert(AccountIdentity::scheme($db) === $schemeBefore, 'The scheme the run found is the scheme it left behind.',
    'The identity section did not restore the saved scheme.');

/* ------------------------------------------------------- work waiting on the Academy */
// A student can leave six kinds of work sitting on the Academy. Each has to be countable straight
// from the sidebar, so signing in is enough to know where to start looking.
echo "\n-- administrator attention badges\n";
$announcementId = (int) $db->query("SELECT id FROM notifications WHERE title = 'Class on Friday' LIMIT 1")->fetchColumn();
$before = (new AdminAttention($db))->pendingByArea();
$db->prepare("INSERT INTO assignment_submissions(assignment_id,student_id,submission_text,status) VALUES(?,?,?,'submitted')")
    ->execute([$assignmentId, $studentId, 'The write-up is in the repository.']);
$submissionId = (int) $db->lastInsertId();
$db->prepare("INSERT INTO certificates(student_id,course_id,status,student_remarks) VALUES(?,?,?,'Course finished, please issue it.')")
    ->execute([$studentId, $courseId, 'requested']);
$certificateId = (int) $db->lastInsertId();
$db->prepare("INSERT INTO payments(student_id,course_id,amount,transaction_reference,status) VALUES(?,?,24000,'SMOKE-PENDING-1','pending')")
    ->execute([$studentId, $courseId]);
$paymentId = (int) $db->lastInsertId();
$db->prepare("INSERT INTO reviews(reviewer_type,full_name,rating,content,status) VALUES('student','Render Smoke Student',5,'The course was worth it.','pending')")
    ->execute();
$reviewId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO notification_replies(notification_id,student_id,recipient_student_id,message) VALUES(?,?,?,?)')
    ->execute([$announcementId, $studentId, $studentId, 'Will the class be recorded?']);
$after = (new AdminAttention($db))->pendingByArea();
foreach (['assignments' => 'an unreviewed submission', 'certificates' => 'a certificate request', 'payments' => 'a payment to check', 'reviews' => 'a review to moderate', 'messages' => 'a message to answer'] as $area => $what) {
    $assert(($after[$area] ?? 0) - ($before[$area] ?? 0) === 1, $area . ' counts ' . $what . ' the student just left behind.',
        $area . ' moved by ' . (($after[$area] ?? 0) - ($before[$area] ?? 0)) . ' instead of one (' . $what . ').');
}
// An attempt that finished without a result is the one quiz state automatic grading left open.
$db->prepare("INSERT INTO quiz_attempts(quiz_id,student_id,started_at,deadline_at,submitted_at,status) VALUES(?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP(),'submitted')")
    ->execute([$quizId, $studentId]);
$attemptId = (int) $db->lastInsertId();
$ungraded = (new AdminAttention($db))->pendingByArea();
$assert(($ungraded['quizzes'] ?? 0) - ($before['quizzes'] ?? 0) === 1, 'quizzes counts the finished attempt grading could not settle.',
    'quizzes moved by ' . (($ungraded['quizzes'] ?? 0) - ($before['quizzes'] ?? 0)) . ' instead of one (an ungraded attempt).');
// Answering takes the thread off the queue: these badges are outstanding work, not alerts that a
// careful administrator would have to remember to dismiss. That happens below, once the sidebar
// has been rendered with the message still waiting.
$signIn($adminId, 'Render Smoke Admin', $adminEmail, 'admin');
$_SERVER['REQUEST_URI'] = '/TSG-Academy-LMS/dashboard';
$html = $render(static fn() => (new DashboardController())->show());
foreach ([
    '/dashboard' => 'Overview',
    '/admin/quiz-reports' => 'Quiz reports',
    '/admin/assignments' => 'Assignments',
    '/admin/chats' => 'Chats',
    '/admin/certificates' => 'Certificates',
    '/admin/payments' => 'Revenue and payment',
    '/admin/reviews' => 'Review moderation',
] as $href => $label) {
    // Several links can point at the same page - the brand also goes to /dashboard - so every
    // match is checked: the badge only ever renders inside the numbered navigation entry.
    preg_match_all('/<a[^>]*href="[^"]*' . preg_quote($href, '/') . '"[^>]*>(.*?)<\/a>/s', $html, $matches);
    $badged = false;
    foreach ($matches[1] as $link) {
        $badged = $badged || str_contains($link, 'dashboard-nav-badge');
    }
    $assert($badged, $label . ' shows a badge in the admin sidebar.',
        $label . ' carries no badge although a student left work waiting.');
}
preg_match('/<a[^>]*href="[^"]*\/admin\/students"[^>]*>(?<link>.*?)<\/a>/s', $html, $matches);
$studentsLink = (string) ($matches['link'] ?? '');
$assert($studentsLink !== '' && !str_contains($studentsLink, 'dashboard-nav-badge'),
    'Students carries no badge, because no student action queues there.',
    'The Students link is badged even though nothing there waits on the Academy.');
// A student message used to be a number on the Announcements link with nowhere to answer it. The
// count now sits beside the page that actually holds the conversations.
preg_match('/<a[^>]*href="[^"]*\/admin\/announcements"[^>]*>(?<link>.*?)<\/a>/s', $html, $matches);
$boardLink = (string) ($matches['link'] ?? '');
$assert($boardLink !== '' && !str_contains($boardLink, 'dashboard-nav-badge'),
    'Announcements no longer wears the message count.',
    'The Announcements link still carries the badge that moved to the chat desk.');

$db->prepare('INSERT INTO notification_replies(notification_id,student_id,recipient_student_id,message) VALUES(?,?,?,?)')
    ->execute([$announcementId, $adminId, $studentId, 'Yes - the recording goes up the same evening.']);
$answered = (new AdminAttention($db))->pendingByArea();
$assert(($answered['messages'] ?? 0) === ($before['messages'] ?? 0), 'An Academy answer takes the conversation back off the queue.',
    'The messages badge still counts a thread the Academy has already answered (' . json_encode($answered) . ' against ' . json_encode($before) . ').');

/* ---------------------------------------------------------------------- private conversation */
echo "\n-- private conversation\n";
$_SERVER['REQUEST_URI'] = '/TSG-Academy-LMS/notifications/view?notification_id=' . $announcementId;
// A second conversation, because the point of the rails is what they hold besides the thread on
// screen. It is written by the student, so it is a real thread, and it is removed again below.
$otherThread = $db->query('SELECT nr.notification_id AS id, n.title FROM notification_recipients nr'
    . ' JOIN notifications n ON n.id = nr.notification_id'
    . ' WHERE nr.student_id = ' . (int) $studentId . ' AND nr.notification_id <> ' . (int) $announcementId
    . ' AND n.published_at IS NOT NULL ORDER BY nr.notification_id LIMIT 1')->fetch();
$otherThreadId = (int) ($otherThread['id'] ?? 0);
$db->prepare('INSERT INTO notification_replies(notification_id,student_id,recipient_student_id,message) VALUES(?,?,?,?)')
    ->execute([$otherThreadId, $studentId, $studentId, 'Second thread: is there a replay of the session?']);
$signIn($studentId, 'Render Smoke Student', $studentEmail, 'student');
// signIn() clears $_GET, so the thread is addressed after the session is put in place.
$_GET = ['notification_id' => (string) $announcementId];
$html = $render(static fn() => (new NotificationController())->show());
$assert(str_contains($html, 'conversation-page') && str_contains($html, 'conversation-panel') && str_contains($html, 'conversation-bubble'),
    'The student thread renders its page frame, panel and message bubbles.', 'The student thread did not render the conversation page, panel or bubbles.');
$assert(str_contains($html, 'Will the class be recorded?'), 'The student sees their own message in the thread.', 'The student\'s message is missing from the thread.');
$assert(substr_count($html, 'class="conversation-row is-mine"') === 1 && substr_count($html, 'class="conversation-row"') === 1,
    'The reader\'s own bubble sits on one side and the Academy\'s on the other.',
    'The thread does not mark exactly one of its two messages as the reader\'s own.');
$assert(str_contains($html, 'Academy announcement'), 'The announcement opens the thread.', 'The announcement is not printed above the replies.');
$assert(str_contains($html, 'is-mine'), 'The reader\'s own message is aligned as theirs.', 'Nothing in the thread is recognised as the reader\'s own message.');
$assert(preg_match('/<time[^>]*>[A-Z][a-z]{2} \d{1,2}, \d{4} · \d{1,2}:\d{2} [AP]M</', $html) === 1,
    'A message prints its date and time to the minute.', 'No message carries a readable date and time.');
$assert(str_contains($html, 'action="') && str_contains($html, '/notifications/reply') && str_contains($html, 'name="message"'),
    'The composer still posts a reply to the same endpoint with the same field.', 'The reply form lost its endpoint or its message field.');
$assert(stripos($html, 'admin-alert-success') === false && stripos($html, 'Reply added') === false,
    'The thread keeps no send-confirmation banner above the chat.', 'The conversation still prints a flash banner.');
// The page is a chat client, so all three panes have to be on it: the reader's other conversations,
// the messages, and the update behind the thread with the recent chats under it.
$assert(str_contains($html, 'chat-shell') && str_contains($html, 'chat-rail-inbox') && str_contains($html, 'chat-main')
        && str_contains($html, 'chat-rail-side'),
    'The thread renders as an inbox, a message pane and a details rail.',
    'The conversation page is missing one of its three chat panes.');
$assert(substr_count($html, 'class="chat-rail-row is-open') === 1 && substr_count($html, 'aria-current="true"') === 1,
    'The inbox marks the conversation that is on screen.',
    'The conversation inbox does not flag the open thread.');
$assert(str_contains($html, 'chat-recent-list') && str_contains($html, Security::escape((string) $otherThread['title']))
        && str_contains($html, 'is there a replay of the session?'),
    'The other conversation is listed in the inbox and again in the recent chats.',
    'The second conversation did not reach the rails.');
$assert(str_contains($html, '/student/chats/delete"') && str_contains($html, '/student/chats/delete-all'),
    'A reader can clear one conversation or the whole list from inside the thread.',
    'The chat page offers no clearing actions.');
// The sender and the time are printed above the bubble, so the coloured part holds the message only.
$assert(preg_match_all('/<div class="conversation-stack">\s*<p class="conversation-caption">.*?<\/p>\s*<div class="conversation-bubble">/s', $html) === 2,
    'Every message prints its sender and time above its bubble.',
    'A bubble does not carry its sender line above it.');

$signIn($adminId, 'Render Smoke Admin', $adminEmail, 'admin');
$_GET = ['notification_id' => (string) $announcementId, 'student_id' => (string) $studentId];
$html = $render(static fn() => (new NotificationController())->show());
$assert(str_contains($html, 'conversation-picker') && str_contains($html, 'name="student_id"'),
    'The admin thread still offers the recipient picker.', 'The admin lost the recipient picker.');
$assert(str_contains($html, 'Reply privately to Render Smoke Student'),
    'The composer names the student the reply goes to.', 'The admin composer does not name its recipient.');
$assert(str_contains($html, 'Academy team') && str_contains($html, 'name="recipient_student_id"'),
    'The admin\'s own answer is labelled as the Academy and pinned to the thread.', 'An admin reply is not identified or not scoped to the student.');
$assert(substr_count($html, 'class="conversation-row is-mine"') === 1,
    'The same thread reads mirrored for the Academy: their answer right, the student\'s question left.',
    'The admin view does not mark exactly one of the two messages as their own.');
$assert(str_contains($html, 'STUDENT') && str_contains($html, 'Academy ID') && str_contains($html, $studentEmail),
    'The details rail tells the Academy whose thread this is, by name, account id and address.',
    'The admin thread does not identify the student it belongs to.');
$assert(str_contains($html, '/admin/chats/delete"') && str_contains($html, '/admin/chats/delete-all')
        && str_contains($html, 'name="student_id" value="' . $studentId . '"'),
    'The desk offers the same two clearing actions, scoped to the right student.',
    'The admin thread is missing its Delete or Delete all control.');
$db->exec('DELETE FROM notification_replies WHERE notification_id = ' . $otherThreadId);

/* ------------------------------------------------------------------------- the chat list */
// Eight updates landed in the student inbox above and exactly one of them became a conversation,
// because a thread only exists once somebody has written in it. The two lists have to keep counting
// different things, so each half is checked on its own.
echo "\n-- conversations, not notifications\n";
$chats = new ChatService($db);
$threadList = $chats->studentThreads($studentId);
$assert(count($threadList) === 1 && (int) ($threadList[0]['id'] ?? 0) === $announcementId
        && (int) ($threadList[0]['message_count'] ?? 0) === 2,
    'The chat page lists the one conversation and none of the seven updates that hold no messages.',
    'The student chat list returned ' . json_encode(array_map(static fn(array $thread): array => ['id' => (int) $thread['id'], 'messages' => (int) $thread['message_count']], $threadList)) . '.');
// Bookkeeping is stored to the second, so the seen stamp is rewound a minute instead of trusting that
// a message written "just now" lands in a later one: the rule is under test, not the clock.
$rewindSeen = 'UPDATE notification_recipients SET chat_seen_at = chat_seen_at - INTERVAL 1 MINUTE WHERE student_id = '
    . (int) $studentId . ' AND notification_id = ' . (int) $announcementId;
$db->exec($rewindSeen);
$assert($chats->unreadCount($studentId) === 1, 'An Academy answer written after the student last opened the thread is counted unread.',
    'The unread chat count read ' . $chats->unreadCount($studentId) . '.');
$signIn($studentId, 'Render Smoke Student', $studentEmail, 'student');
$_SERVER['REQUEST_URI'] = '/TSG-Academy-LMS/student/chats';
$chatHtml = $render(static fn() => (new ChatController())->studentIndex());
$assert(str_contains($chatHtml, 'class="chat-thread-row is-unread"') && str_contains($chatHtml, '1 new')
        && str_contains($chatHtml, 'Yes - the recording goes up the same evening.') && str_contains($chatHtml, '2 messages'),
    'The list previews the newest message and flags the thread as unread.',
    'A conversation row is missing its preview, its message count or its new flag.');
$assert(str_contains($chatHtml, '/student/chats/read-all') && str_contains($chatHtml, '/student/chats/delete-all')
        && str_contains($chatHtml, '/student/chats/delete"'),
    'Read all, Delete all and Delete are all offered while a conversation exists.',
    'One of the three chat actions is missing from the list.');
$assert(!str_contains($chatHtml, 'Payment approved') && !str_contains($chatHtml, 'Assignment graded'),
    'No update that was never replied to is listed as a conversation.',
    'The chat page is showing plain news.');
$chats->openThread($studentId, $announcementId);
$assert($chats->unreadCount($studentId) === 0, 'Opening a conversation takes the number beside Chats down.',
    'The count still read ' . $chats->unreadCount($studentId) . ' after the thread was opened.');
// Read all has to settle exactly what the badge counted, or the confirmation would disagree with the
// sidebar, and it must not reach into the news counters beside them.
$db->exec($rewindSeen);
$newsBefore = $notifier->unreadByArea($studentId);
$settled = $chats->markAllSeen($studentId);
$assert($settled === 1, 'Read all settles the one conversation that was unread.',
    'Read all settled ' . $settled . ' conversation(s) for a list holding one unread thread.');
$assert($chats->unreadCount($studentId) === 0, 'The chat badge is clear after Read all.',
    'The unread chat count survived Read all at ' . $chats->unreadCount($studentId) . '.');
$assert($notifier->unreadByArea($studentId) === $newsBefore, 'Read all leaves every news badge exactly where it was.',
    'Marking conversations read moved the news counters to ' . json_encode($notifier->unreadByArea($studentId)) . '.');
// Hiding is per recipient, so it empties this student's list and changes nothing the Academy keeps.
$chats->hideThread($studentId, $announcementId);
$assert($chats->studentThreads($studentId) === [], 'Deleting a conversation takes it off the student list.',
    'A hidden conversation is still listed for the student.');
$assert((int) $db->query('SELECT COUNT(*) FROM notification_replies WHERE notification_id = ' . (int) $announcementId)->fetchColumn() === 2
        && (int) $chats->adminThreadCount() === 1,
    'Nothing was deleted - the messages and the Academy desk both still hold the thread.',
    'Hiding one chat destroyed rows it has no claim on.');
$foreignRefusal = '';
try {
    $chats->hideThread($studentId, 999999);
} catch (RuntimeException $exception) {
    $foreignRefusal = $exception->getMessage();
}
$assert($foreignRefusal !== '', 'A conversation id that is not on this account is refused instead of hidden.',
    'hideThread() accepted a thread the student does not own.');
// The rule that makes hiding safe: a message from the other side brings the thread straight back.
$db->exec('UPDATE notification_recipients SET chat_deleted_at = chat_deleted_at - INTERVAL 1 MINUTE WHERE student_id = '
    . (int) $studentId . ' AND notification_id = ' . (int) $announcementId);
$db->prepare('INSERT INTO notification_replies(notification_id,student_id,recipient_student_id,message) VALUES(?,?,?,?)')
    ->execute([$announcementId, $adminId, $studentId, 'Is there anything else I can check for you?']);
$assert(count($chats->studentThreads($studentId)) === 1, 'An Academy reply reopens a conversation the student had cleared.',
    'A cleared thread stayed hidden after the Academy wrote in it.');
// A thread that came back is on the list again, so the button beside it has to work a second time.
$chats->hideThread($studentId, $announcementId);
$assert($chats->studentThreads($studentId) === [], 'A reopened conversation can be cleared again.',
    'The second Delete left a revived thread on the student list.');
$chats->openThread($studentId, $announcementId);
$cleared = $chats->hideAllThreads($studentId);
$assert($cleared === 1, 'Delete all clears the conversation and no plain update.',
    'Delete all reported ' . $cleared . ' thread(s) for a list holding one.');
$assert($chats->studentThreads($studentId) === []
        && (int) $db->query('SELECT COUNT(*) FROM notification_recipients WHERE student_id = ' . (int) $studentId)->fetchColumn() === 8,
    'The chat list is empty while all eight notifications are still on the account.',
    'Clearing the chat list took news with it: ' . (int) $db->query('SELECT COUNT(*) FROM notification_recipients WHERE student_id = ' . (int) $studentId)->fetchColumn() . ' recipient row(s) left.');
// The desk an administrator needed: which threads still end with a student, and which are answered.
$answeredThreads = $chats->adminThreads();
$assert($chats->adminThreads(true) === [] && $answeredThreads !== [] && ChatService::awaitsReply($answeredThreads[0]) === false,
    'A thread whose last message is the Academy\'s is not waiting on anyone.',
    'The waiting filter returned ' . json_encode($chats->adminThreads(true)) . ' for a thread the Academy has the last word in.');
$db->prepare('INSERT INTO notification_replies(notification_id,student_id,recipient_student_id,message) VALUES(?,?,?,?)')
    ->execute([$announcementId, $studentId, $studentId, 'Yes - please send the recording link.']);
$waitingThreads = $chats->adminThreads(true);
$assert(count($waitingThreads) === 1 && ChatService::awaitsReply($waitingThreads[0]),
    'A student having the last word puts the thread in the Waiting list.',
    'The waiting filter read ' . json_encode($waitingThreads) . '.');
$signIn($adminId, 'Render Smoke Admin', $adminEmail, 'admin');
$_SERVER['REQUEST_URI'] = '/TSG-Academy-LMS/admin/chats?filter=waiting';
$_GET = ['filter' => 'waiting'];
$deskHtml = $render(static fn() => (new ChatController())->adminIndex());
$assert(str_contains($deskHtml, 'Waiting for the Academy') && str_contains($deskHtml, 'Render Smoke Student')
        && str_contains($deskHtml, 'please send the recording link') && str_contains($deskHtml, 'How to use the chat desk'),
    'The desk names the student, previews the open message and flags it as waiting.',
    'The admin chat desk did not list the thread that is waiting on an answer.');
$assert(str_contains($deskHtml, 'notification_id=' . $announcementId) && str_contains($deskHtml, 'student_id=' . $studentId),
    'Every desk row links to the thread for that one student.',
    'The chat row does not carry both halves of the thread address.');
$assert(str_contains($deskHtml, '/admin/chats/delete"') && str_contains($deskHtml, '/admin/chats/delete-all')
        && str_contains($deskHtml, 'Delete all'),
    'The desk carries the Delete and Delete all controls a list is expected to have.',
    'The admin chat desk still has no way to clear itself.');
// Clearing the desk is the same promise from the other side: the thread leaves the list and the
// badge, stays with the student, and comes back the moment that student writes again.
$chats->openThread($studentId, $announcementId);
$chats->adminHideThread($studentId, $announcementId);
$assert($chats->adminThreads() === [] && $chats->adminThreadCount() === 0,
    'A cleared conversation leaves the desk and both of its counters.',
    'The desk still listed a thread that had been cleared from it.');
$assert(count($chats->studentThreads($studentId)) === 1,
    'The student keeps the conversation the Academy cleared, message for message.',
    'Clearing the desk reached into the student\'s own list.');
$clearedQueue = (new AdminAttention($db))->pendingByArea();
$assert(($clearedQueue['messages'] ?? 0) === 0,
    'A thread cleared off the desk also comes off the Chats badge.',
    'The badge still counts a conversation the Academy deliberately cleared: ' . json_encode($clearedQueue) . '.');
$deskRefusal = '';
try {
    $chats->adminHideThread(999999, $announcementId);
} catch (RuntimeException $exception) {
    $deskRefusal = $exception->getMessage();
}
$assert($deskRefusal !== '', 'A thread that is not on the desk cannot be cleared by id.',
    'adminHideThread() accepted a pair that holds no conversation.');
$db->exec('UPDATE notification_recipients SET admin_chat_deleted_at = admin_chat_deleted_at - INTERVAL 1 MINUTE'
    . ' WHERE student_id = ' . (int) $studentId . ' AND notification_id = ' . (int) $announcementId);
$db->prepare('INSERT INTO notification_replies(notification_id,student_id,recipient_student_id,message) VALUES(?,?,?,?)')
    ->execute([$announcementId, $studentId, $studentId, 'Still waiting on that link.']);
$assert(count($chats->adminThreads()) === 1 && $chats->adminThreadCount() === 1,
    'The next message from the student puts the thread back on the desk.',
    'A cleared conversation never came back after its student wrote again.');
$assert((new AdminAttention($db))->pendingByArea()['messages'] === 1,
    'and the badge comes back with it.',
    'The reopened thread did not return to the messages queue.');
$chats->adminHideThread($studentId, $announcementId);
$assert($chats->adminThreads() === [], 'A conversation that came back can be cleared off the desk again.',
    'The desk kept a revived thread that its own Delete button was offered for.');
$db->exec('UPDATE notification_recipients SET admin_chat_deleted_at = NULL'
    . ' WHERE student_id = ' . (int) $studentId . ' AND notification_id = ' . (int) $announcementId);
$replyTotal = (int) $db->query('SELECT COUNT(*) FROM notification_replies')->fetchColumn();
$clearedDesk = $chats->adminHideAllThreads();
$assert($clearedDesk === 1 && $chats->adminThreads() === []
        && (int) $db->query('SELECT COUNT(*) FROM notification_replies')->fetchColumn() === $replyTotal,
    'Delete all clears the desk in one action and destroys nothing.',
    'Delete all reported ' . $clearedDesk . ' thread(s) against a desk holding one, with ' . $replyTotal . ' message row(s) before.');
$_GET = [];
// The chat flags belong to this section; the messages themselves are removed by the cleanup below.
$db->exec('UPDATE notification_recipients SET chat_deleted_at = NULL, chat_seen_at = NULL, admin_chat_deleted_at = NULL WHERE student_id = '
    . (int) $studentId);

// Leave every queue exactly as this section found it, so a rerun measures the same deltas.
$db->exec('DELETE FROM notification_replies WHERE notification_id = ' . (int) $announcementId);
$db->exec('DELETE FROM assignment_submissions WHERE id = ' . $submissionId);
$db->exec('DELETE FROM certificates WHERE id = ' . $certificateId);
$db->exec('DELETE FROM payments WHERE id = ' . $paymentId);
$db->exec('DELETE FROM reviews WHERE id = ' . $reviewId);
$db->exec('DELETE FROM quiz_attempts WHERE id = ' . $attemptId);
$restored = (new AdminAttention($db))->pendingByArea();
$assert($restored === $before, 'Removing the fixtures leaves the queues where they were.',
    'The section left work behind: ' . json_encode($restored) . ' against ' . json_encode($before) . '.');

echo "\n";
if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " problem(s)\n - " . implode("\n - ", $failures) . "\n");
    $status = 1;
} else {
    echo "PASS: workspace pages render, badges count real unread work on " . $dbName . "\n";
    $status = 0;
}

if (($options['drop'] ?? '0') === '1') {
    shell_exec($mysql . ' ' . $credentials . ' -e ' . escapeshellarg('DROP DATABASE IF EXISTS `' . $dbName . '`'));
    echo "dropped " . $dbName . "\n";
}

exit($status);
