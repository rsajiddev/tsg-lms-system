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
 * that stays at zero while unread notifications exist, a chat that loses its frame or prints a bubble
 * without its time line, an emoji picker offering fewer glyphs than it claims, a name with no menu
 * beside it, or a purge card without its confirmation phrase all fail this test. The two sides of one
 * conversation are then cleared through ChatService here, because the feed, the list and the badge
 * have to agree after one side stops seeing what it was shown - and the other side is found still
 * reading every message, because clearing a chat was never the same act as deleting one.
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
        '/app/Core/PrivateStorage.php',
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
        '/app/Services/AccessPlan.php',
        '/app/Services/LessonMedia.php',
        '/app/Services/CourseGrading.php',
        '/app/Services/AttendanceService.php',
        '/app/Services/LessonProtection.php',
        '/app/Services/SessionRegistry.php',
        '/app/Controllers/DashboardController.php',
        '/app/Controllers/AdminManagementController.php',
        '/app/Controllers/NotificationController.php',
        '/app/Controllers/AccountController.php',
        '/app/Controllers/AssignmentController.php',
        '/app/Controllers/ChatController.php',
        '/app/Controllers/QuizController.php',
        '/app/Controllers/CourseAdminController.php',
        '/app/Controllers/PublicPageController.php',
        '/app/Controllers/StudentManagementController.php',
        '/app/Controllers/StudentPortalController.php',
        '/app/Controllers/CertificateController.php',
    ] as $file
) {
    require BASE_PATH . $file;
}

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\Env;
use App\Core\Security;
use App\Controllers\AccountController;
use App\Controllers\AdminManagementController;
use App\Controllers\AssignmentController;
use App\Controllers\ChatController;
use App\Controllers\CourseAdminController;
use App\Controllers\PublicPageController;
use App\Controllers\StudentManagementController;
use App\Controllers\DashboardController;
use App\Controllers\NotificationController;
use App\Controllers\QuizController;
use App\Services\AccessPlan;
use App\Services\AccountIdentity;
use App\Services\AdminAttention;
use App\Services\ChatService;
use App\Services\LessonProtection;
use App\Services\NotificationService;
use App\Services\SessionRegistry;

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

// A scratch database older than the current chat holds a chat_threads table without the two per-side
// clearing stamps, and the migration only prints them onto a table it creates itself. The same two
// columns are added here, guarded the same way, so a rerun measures the schema the pages expect.
foreach (['student_cleared_at' => 'AFTER admin_seen_at', 'admin_cleared_at' => 'AFTER student_cleared_at'] as $column => $position) {
    $held = trim((string) shell_exec($mysql . ' ' . $credentials . ' -N -B -e ' . escapeshellarg(
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='" . $dbName
        . "' AND table_name='chat_threads' AND column_name='" . $column . "'")));
    if ($held === '1') {
        continue;
    }
    shell_exec($mysql . ' ' . $credentials . ' ' . escapeshellarg($dbName) . ' -e ' . escapeshellarg(
        'ALTER TABLE chat_threads ADD COLUMN ' . $column . ' DATETIME NULL ' . $position));
    echo "topped " . $dbName . ".chat_threads up with " . $column . "\n";
}

$db = Database::connection();
// A scratch database that predates the video protection feature has neither of the two tables the
// three surfaces below read, and a missing table would show up as an empty card rather than as the
// stale schema it is. The migration is one guarded script that can be run again over a database it
// already built, so the top-up re-imports it instead of copying the table definitions in here.
$hasTable = static fn(string $table): bool => trim((string) shell_exec($mysql . ' ' . $credentials . ' -N -B -e ' . escapeshellarg(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='" . $dbName . "' AND table_name='" . $table . "'"
))) === '1';
$protectionTables = ['user_sessions', 'lesson_view_events'];
$missingTables = array_values(array_filter($protectionTables, static fn(string $table): bool => !$hasTable($table)));
if ($missingTables !== []) {
    shell_exec($mysql . ' ' . $credentials . ' ' . escapeshellarg($dbName) . ' < '
        . escapeshellarg(BASE_PATH . '/database/migrations/001_initial_schema.sql'));
    echo 're-imported the schema into ' . $dbName . ' to add ' . implode(', ', $missingTables) . "\n";
}
$left = array_values(array_filter($protectionTables, static fn(string $table): bool => !$hasTable($table)));
$assert($left === [], 'The scratch database holds the tables the protection surfaces read.',
    'Even after a re-import, ' . $dbName . ' has no ' . implode(', ', $left) . '; the schema file is not the single guarded script it claims to be.');
// The same argument for the four live class columns: a scratch database that predates them would show
// the fields hidden rather than the schema being behind, and those two states look identical on screen.
$hasColumn = static function (string $table, string $column) use ($mysql, $credentials, $dbName): bool {
    return trim((string) shell_exec($mysql . ' ' . $credentials . ' -N -B -e ' . escapeshellarg(
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='" . $dbName
        . "' AND table_name='" . $table . "' AND column_name='" . $column . "'"
    ))) === '1';
};
$liveColumns = [['lessons', 'meeting_url'], ['lessons', 'meeting_at'], ['payments', 'plan_code'], ['student_course_access', 'plan_code']];
$names = static fn(array $pairs): string => implode(', ', array_map(static fn(array $pair): string => $pair[0] . '.' . $pair[1], $pairs));
$missingColumns = array_values(array_filter($liveColumns, static fn(array $pair): bool => !$hasColumn($pair[0], $pair[1])));
if ($missingColumns !== []) {
    shell_exec($mysql . ' ' . $credentials . ' ' . escapeshellarg($dbName) . ' < '
        . escapeshellarg(BASE_PATH . '/database/migrations/001_initial_schema.sql'));
    AccessPlan::forgetColumnState();
    echo 're-imported the schema into ' . $dbName . ' to add ' . $names($missingColumns) . "\n";
}
$stillMissing = array_values(array_filter($liveColumns, static fn(array $pair): bool => !$hasColumn($pair[0], $pair[1])));
$assert($stillMissing === [], 'The scratch database holds the columns the live class surfaces read.',
    'Even after a re-import, ' . $dbName . ' has no ' . $names($stillMissing) . '.');
$adminEmail = 'render-smoke-admin@example.test';
$studentEmail = 'render-smoke-student@example.test';

/* --------------------------------------------------------------- environment surface */
echo "\n-- .env handover surface\n";
$envFile = BASE_PATH . '/.env';
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
$liveKeys = $keysIn($envFile);
foreach ($liveKeys as $key) {
    $assert(preg_match('/^[A-Z][A-Z0-9_]*$/', $key) === 1, 'Key ' . $key . ' is a valid variable name.',
        'Key "' . $key . '" in .env is not a valid variable name.');
}
// The .env is the only configuration template the project ships, so the keys a new owner
// must fill in are checked directly against it instead of against a separate example file.
foreach (['MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_ADDRESS'] as $required) {
    $assert(in_array($required, $liveKeys, true), '.env carries ' . $required . ' for the handover.',
        '.env is missing ' . $required . ', so the company could not send mail.');
}
// Company alerts share the one sending mailbox: the HR and certificate readers resolve to
// MAIL_FROM_ADDRESS, which is what lets a company swap in its no-reply address without touching
// any other key. The developer reader is deliberately separate - it follows MAIL_DEVELOPER_ADDRESS
// and only falls back to the company mailbox when that address is blank - so developer copies
// never land in the company inbox.
foreach (['hrAddress', 'certificateAddress'] as $reader) {
    $assert(\App\Services\MailService::$reader() === (string) getenv('MAIL_FROM_ADDRESS'),
        'MailService::' . $reader . '() follows MAIL_FROM_ADDRESS.',
        'MailService::' . $reader . '() does not follow MAIL_FROM_ADDRESS, so the handover would still reach an old inbox.');
}
$developerConfigured = filter_var((string) getenv('MAIL_DEVELOPER_ADDRESS'), FILTER_VALIDATE_EMAIL);
if ($developerConfigured) {
    $assert(\App\Services\MailService::developerAddress() === (string) getenv('MAIL_DEVELOPER_ADDRESS'),
        'MailService::developerAddress() follows MAIL_DEVELOPER_ADDRESS.',
        'MailService::developerAddress() does not follow MAIL_DEVELOPER_ADDRESS, so developer alerts would mix into the company inbox.');
    $assert(\App\Services\MailService::developerAddress() !== (string) getenv('MAIL_FROM_ADDRESS'),
        'The developer inbox is separate from the company inbox.',
        'The developer inbox equals the company inbox, so developer notifications would be delivered to the company.');
} else {
    $assert(\App\Services\MailService::developerAddress() === (string) getenv('MAIL_FROM_ADDRESS'),
        'The developer inbox falls back to the company mailbox when unconfigured.',
        'With MAIL_DEVELOPER_ADDRESS unset the developer inbox should fall back to MAIL_FROM_ADDRESS rather than drop the copies.');
}

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
    'Open the chat desk',
    'Open recent activity',
]);
$checkPage('Admin chat desk', '/admin/chats', static fn() => (new ChatController())->adminIndex(), [
    'No conversation has been started yet.',
    'Waiting for you',
    'How to use the chat desk',
    'professional-chat-layout',
]);
$checkPage('Recent activity report', '/admin/activity', static fn() => (new NotificationController())->activity(), [
    'Automated student updates',
    '/admin/activity/automated/clear',
    'Clear updates',
    'Email delivery log',
    'How to use recent activity',
]);
$adminUpdateHtml = $render(static function (): void {
    \App\Core\View::render('notifications/read', [
        'title' => 'Announcement',
        'user' => $_SESSION['user'],
        'notification' => [
            'title' => 'Rendered admin update',
            'body' => 'Update detail.',
            'category' => 'lesson',
            'published_at' => '2026-01-01 12:00:00',
            'author_name' => 'Render Smoke Admin',
        ],
    ]);
});
$assert(!str_contains($adminUpdateHtml, 'Back to notifications'),
    'The update detail has no Back to notifications button.',
    'The update detail still shows a Back to notifications button.');
$assert(str_contains($adminUpdateHtml, '/admin/activity">Recent activity</a>'),
    'An admin update detail links back to Recent activity.',
    'An admin update detail has no working Recent activity breadcrumb.');
$checkPage('Assignment workspace', '/admin/assignments', static fn() => (new AssignmentController())->adminIndex(), []);
$checkPage('Quiz workspace', '/admin/quizzes', static fn() => (new QuizController())->adminIndex(), []);
$checkPage('Lesson library', '/admin/lessons', static fn() => (new CourseAdminController())->lessons(), []);
$checkPage('Student directory', '/admin/students', static fn() => (new StudentManagementController())->index(), []);
// Every guarded bulk delete - seven wipes plus the counter re-arm - now lives on one Danger Zone
// page. Each card renders here with its exact confirmation phrase and acknowledgement checkbox.
$checkPage('Danger Zone', '/admin/danger-zone', static fn() => (new AdminManagementController())->dangerZone(), [
    'Danger Zone',
    'Read before deleting',
    'admin-card-testing',
    'confirm_phrase',
    'data-purge-ack',
    'CLEAR ALL DATA',
    '/admin/settings/clear-data',
    'DELETE ALL STUDENTS',
    '/admin/students/purge',
    'DELETE ALL ANNOUNCEMENTS',
    'CLEAR RECENT ACTIVITY',
    '/admin/activity/clear',
    'DELETE ALL LESSONS',
    'DELETE ALL QUIZZES',
    'DELETE ALL ASSIGNMENTS',
    'RESET ID COUNTER',
    '/admin/settings/reset-counter',
    'name="student_id_start"',
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
    'How to use your chats',
    'Your conversation with the Academy is always here.',
    'Type your message',
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
$assert(!str_contains($unreadAgainHtml, '/chats/clear') && !str_contains($unreadAgainHtml, '/chats/send'),
    'The inbox holds no chat controls - only the link to the page that has them.',
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
$studentCoursesHtml = $render(static fn() => (new \App\Controllers\StudentPortalController())->courses());
$assert(str_contains($studentCoursesHtml, 'Test certificate workflow')
    && str_contains($studentCoursesHtml, 'skips course eligibility checks')
    && str_contains($studentCoursesHtml, '/student/certificates/test-request'),
    'An enrolled student can see a clearly labelled test button before meeting course eligibility.',
    'The student testing button is hidden or does not explain that it is separate from eligibility.');
$db->prepare("INSERT INTO certificate_test_requests(student_id,course_id,status) VALUES(?,?,'requested')")
    ->execute([$studentId, $courseId]);
$signIn($adminId, 'Render Smoke Admin', $adminEmail, 'admin');
$adminCertificatesHtml = $render(static fn() => (new \App\Controllers\CertificateController())->adminIndex());
$assert(str_contains($adminCertificatesHtml, 'Sample certificate workflow')
    && str_contains($adminCertificatesHtml, 'certificate-test-request')
    && str_contains($adminCertificatesHtml, 'certificate-test-upload-form')
    && str_contains($adminCertificatesHtml, 'TEST ONLY')
    && str_contains($adminCertificatesHtml, '/admin/certificates/test-issue')
    && str_contains($adminCertificatesHtml, '/admin/certificates/test-clear'),
    'The admin can see the isolated test queue, upload a sample and clear the test workflow.',
    'The admin test queue, upload form or clear-all control is missing.');
$assert((int) $db->query('SELECT COUNT(*) FROM certificates WHERE student_id = ' . (int) $studentId)->fetchColumn() === 0,
    'Creating a test request did not create an official certificate record.',
    'A test-only request leaked into the official certificate register.');
$db->exec('DELETE FROM certificate_test_requests WHERE student_id = ' . (int) $studentId);
$signIn($studentId, 'Render Smoke Student', $studentEmail, 'student');

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

/* ------------------------------------------------------------------------- contact page */
// The contact page is the one public form a visitor can use without an account, so it has to render
// for a guest, carry the CSRF token the submit handler checks, and post to the /contact route.
$checkPage('Public contact page', '/contact', static fn() => (new PublicPageController())->contact(), [
    'get in touch',
    'class="contact-form"',
    'action="',
    'name="_csrf"',
    'name="message"',
    'Send to us',
]);

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

/* ------------------------------------------------------------------ closing usage reference */
echo "\n-- the closing usage guide\n";
// One component prints the reference list at the foot of every workspace page. Readers skip long
// walkthroughs, so it is a quiet footnote - one short pointer per line, no card of its own - and the
// placement is part of the rule as well: it always closes the page.
$signIn($adminId, 'Render Smoke Admin', $adminEmail, 'admin');
$_SERVER['REQUEST_URI'] = '/TSG-Academy-LMS/admin/lessons';
$html = $render(static fn() => (new CourseAdminController())->lessons());
$assert(str_contains($html, 'admin-usage-card') && str_contains($html, 'admin-usage-eyebrow') && str_contains($html, 'admin-usage-title'),
    'The Lesson library closes with its labelled reference list.',
    'The Lesson library renders no labelled usage reference.');
$assert(preg_match('/<ul class="admin-usage-list">.*<span class="admin-usage-check" aria-hidden="true"><svg.*<span class="admin-usage-check" aria-hidden="true"><svg/s', $html) === 1,
    'The reference is an unordered list whose lines each open with an arrow ring.',
    'The guide is not an arrowed list: ' . substr((string) strstr($html, 'admin-usage-list'), 0, 200));
$assert(substr_count($html, 'admin-usage-number') === 0,
    'No step counter survives from the old numbered walkthrough.',
    'The reference still prints numbered step chips.');
$assert(!str_contains($html, 'admin-usage-detail'),
    'A reference line is one short pointer, with no explanatory sentence trailing it.',
    'The reference still prints the long explanation after each line title.');
// The walkthrough closes each workspace page without a moved-delete note taking up space.
$assert(!str_contains($html, 'danger-moved-note'),
    'The Lesson library has no moved-delete note.',
    'The Lesson library still renders a moved-delete note.');
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
    $assert(!str_contains($deskHtml, 'danger-moved-note'),
        'The ' . $deskLabel . ' has no moved-delete note.',
        'The ' . $deskLabel . ' still renders a moved-delete note.');
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
// A moved-delete pointer is no longer needed now every workspace's destructive action is on the
// Danger Zone page.
echo "\n-- moved-delete notes removed\n";
foreach (glob(BASE_PATH . '/app/Views/admin/*.php') ?: [] as $adminView) {
    $adminSource = (string) file_get_contents($adminView);
    $assert(!str_contains($adminSource, 'Bulk delete moved'),
        basename($adminView) . ' has no moved-delete note.',
        basename($adminView) . ' still contains a moved-delete note.');
}
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

/* ------------------------------------------------------------ the video protection surfaces */
// Three admin surfaces read the tables the video protection feature adds: the settings card that sets
// the rules, the desk that prints what the player reported, and the student card that lists the live
// devices. Each is rendered with real rows behind it, because a card that has only ever been seen in
// its empty state can be broken for months without anyone noticing.
echo "\n-- the video protection surfaces\n";
$protectionReference = 'TSG-CER-2026-001';
$db->prepare('UPDATE users SET account_reference = ? WHERE id = ?')->execute([$protectionReference, $studentId]);
// The desk labels a device out of the stored hash, and only recognises the one in front of it - so
// the harness has to be wearing the browser it expects to read back.
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
$db->exec('DELETE FROM user_sessions WHERE user_id = ' . (int) $studentId);
$db->exec('DELETE FROM lesson_view_events WHERE student_id = ' . (int) $studentId);
$db->prepare('INSERT INTO user_sessions(user_id,session_hash,ip_address,user_agent_hash,created_at,last_seen_at) VALUES(?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())')
    ->execute([$studentId, hash('sha256', 'render-smoke-session'), '203.0.113.77', SessionRegistry::agentHash()]);
$db->prepare('INSERT INTO lesson_view_events(student_id,lesson_id,event_type,ip_address,detail,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())')
    ->execute([$studentId, $lessonId, 'download_blocked', '203.0.113.77', 'Save video as']);
// An account-level report has no recording behind it, so the desk has to name the door it happened at
// rather than leave a student's row standing next to a blank column.
$db->prepare("INSERT INTO lesson_view_events(student_id,lesson_id,event_type,ip_address,detail,created_at) VALUES(?,NULL,?,?,?,UTC_TIMESTAMP())")
    ->execute([$studentId, 'session_cap', '203.0.113.77', '1 older session(s) signed out by this sign-in.']);

$signIn($adminId, 'Render Smoke Admin', $adminEmail, 'admin');
$_SERVER['REQUEST_URI'] = '/TSG-Academy-LMS/admin/settings';
$settingsHtml = $render(static fn() => (new AdminManagementController())->settings());
foreach ([
    'Sessions &amp; video protection',
    'Active sessions per account',
    'Account watermark over the player',
    'Keep unusual viewing reports for',
    '/admin/settings/video-protection',
    'Save protection settings',
    'name="' . SessionRegistry::MAX_ACTIVE_KEY . '"',
    'name="' . LessonProtection::WATERMARK_KEY . '"',
    'name="' . LessonProtection::RETENTION_KEY . '"',
] as $needle) {
    $assert(str_contains($settingsHtml, $needle), 'The settings card prints "' . $needle . '".',
        'The Sessions & video protection card is missing "' . $needle . '".');
}
$assert(str_contains($settingsHtml, 'not burned into the video file') || str_contains($settingsHtml, 'not burned into'),
    'The card states the limit of the mark where the switch is offered, not only in the README.',
    'The watermark switch is offered without saying what it cannot do.');

$_SERVER['REQUEST_URI'] = '/TSG-Academy-LMS/admin/activity';
$activityHtml = $render(static fn() => (new NotificationController())->activity());
$assert(str_contains($activityHtml, 'Lesson view log'), 'The recent activity report carries its own view log card.',
    'No "Lesson view log" card on the activity page.');
$assert(str_contains($activityHtml, '<code>' . $protectionReference . '</code>'), 'The view log names the student by Academy ID ' . $protectionReference . '.',
    'The view log did not print the stored Academy ID.');
$assert(preg_match('/<code>\s*' . (int) $studentId . '\s*<\/code>/', $activityHtml) !== 1,
    'No view log row prints the internal row number where an ID belongs.',
    'A view log row prints the users.id row instead of the Academy ID.');
$assert(str_contains($activityHtml, 'Account session'), 'A sign-in report is filed under "Account session" rather than an invented lesson.',
    'The account-level row lost its lesson-less label on the desk.');
$assert(str_contains($activityHtml, 'Download attempted') && str_contains($activityHtml, 'Signed in on another device'),
    'An event reaches the admin as a sentence, not as a column value.',
    'The view log printed raw event keys.');
$assert(str_contains($activityHtml, '/admin/activity/view-log/clear'), 'The view log has its own clear, apart from the page purge.',
    'The view log card has no clear control, or it shares the recent activity purge.');
$assert(str_contains($activityHtml, (new LessonProtection($db))->retentionDays() . ' day(s)'),
    'The desk states the retention window it sweeps rows by.',
    'The view log card does not name its retention window.');

$_GET = ['student_id' => (string) $studentId];
$sessionHtml = $render(static fn() => (new StudentManagementController())->index());
$_GET = [];
$assert(str_contains($sessionHtml, 'Open sessions') && str_contains($sessionHtml, 'Close all sessions'),
    'The student detail lists the devices holding the account and how to close them.',
    'The student detail renders no session block or no close control.');
$assert(str_contains($sessionHtml, '203.0.113.77') && str_contains($sessionHtml, 'Chrome'),
    'A listed session shows the address and the kind of device it was opened on.',
    'The session table printed neither the address nor the device family.');
$assert(!str_contains($sessionHtml, hash('sha256', 'render-smoke-session')),
    'No session hash reaches the page an admin reads off the screen.',
    'The session table prints the stored session hash.');
$db->exec('DELETE FROM user_sessions WHERE user_id = ' . (int) $studentId);
$_GET = ['student_id' => (string) $studentId];
$noSessionHtml = $render(static fn() => (new StudentManagementController())->index());
$_GET = [];
$assert(str_contains($noSessionHtml, 'No open session'), 'A student with nothing signed in is told so in words.',
    'The session block renders an empty table instead of saying there is nothing open.');
// Back to how this section found the scratch database, so the queue counts below measure their own
// fixtures and a rerun sees the same empty tables.
$db->exec('DELETE FROM lesson_view_events WHERE student_id = ' . (int) $studentId);
$db->prepare('UPDATE users SET account_reference = NULL WHERE id = ?')->execute([$studentId]);
unset($_SERVER['HTTP_USER_AGENT']);

/* --------------------------------------------------------------- the live class surfaces */
// A live class link is set on two admin desks and spent on one student page. The desks are HTML built
// from the row in front of them, so they are rendered here. The student page is not: it decides
// whether to print an address at all, and that decision is measured on raw HTTP bytes in
// tests/live-class-flow-smoke.php, because a CLI render cannot feed the query parameters a lesson page
// reads. What is checked here instead is that the template prints only the value it is handed.
echo "\n-- the live class surfaces\n";
$meetingAddress = 'https://meet.google.com/abc-defg-hij';
$classStart = gmdate('Y-m-d H:i:s', (time() + 30 * 86400));
// The two surfaces label the same instant their own way: the library list keeps its line short, the
// student page spells the date out. Both drop the stored value onto the UTC clock, so neither one
// moves the class by the admin's or the student's own timezone.
$classLabel = gmdate('M j, Y, g:i A', strtotime($classStart . ' UTC')) . ' UTC';
$classSpelling = gmdate('D, M j, Y, g:i A', strtotime($classStart . ' UTC')) . ' UTC';
$db->prepare('UPDATE lessons SET meeting_url = ?, meeting_at = ? WHERE id = ?')->execute([$meetingAddress, $classStart, $lessonId]);

$signIn($adminId, 'Render Smoke Admin', $adminEmail, 'admin');
$lessonsHtml = $render(static fn() => (new CourseAdminController())->lessons());
foreach ([
    'name="meeting_url"',
    'name="meeting_at"',
    'Live class link',
    'Class date &amp; time (UTC)',
    'https://zoom.us/j/... or https://meet.google.com/abc-defg-hij',
    'cannot follow a student into a Zoom',
] as $needle) {
    $assert(str_contains($lessonsHtml, $needle), 'The Lesson library offers "' . $needle . '".',
        'The Lesson library prints no "' . $needle . '" - the fields or their caveat never reached the page.');
}
// The same two fields belong in the inline editor as well, or an admin could set a link once and
// never change it. Two of each control, and the stored address prefilled in the editor.
$assert(substr_count($lessonsHtml, 'name="meeting_url"') === 2 && substr_count($lessonsHtml, 'name="meeting_at"') === 2,
    'Add card and inline editor each carry one link field and one class time field.',
    'The library renders ' . substr_count($lessonsHtml, 'name="meeting_url"') . ' link fields; both the add card and the editor need one.');
$assert(str_contains($lessonsHtml, 'value="' . $meetingAddress . '"'), 'The editor opens with the address the lesson already holds.',
    'The inline editor does not prefill ' . $meetingAddress . '.');
$assert(str_contains($lessonsHtml, ' · live class ' . $classLabel), 'A lesson with a link names its class time in the library list.',
    'The library summary line carries no live class time for the lesson that has one.');
$assert(str_contains($lessonsHtml, '<span class="table-badge live">Live</span>'), 'A lesson with a link is badged Live where it is listed.',
    'No Live chip is printed next to the lesson that has a class address.');

$_SERVER['REQUEST_URI'] = '/TSG-Academy-LMS/admin/courses';
$coursesHtml = $render(static fn() => (new CourseAdminController())->index());
foreach ([
    'name="plan_code"',
    'id="access-plan"',
    'Delivery plan',
    'Set plan only (leave access as it is)',
    'Recorded Only',
    'Live + Recorded',
] as $needle) {
    $assert(str_contains($coursesHtml, $needle), 'The course desk offers "' . $needle . '".',
        'The enrolment card on the course desk has no "' . $needle . '".');
}
// Both plans are named on the page that chooses them, in the words a student was sold - a code a
// student never saw would make the admin guess which half of the catalogue they are handing over.
$assert(substr_count($coursesHtml, 'value="recorded_only"') >= 1 && substr_count($coursesHtml, 'value="live_recorded"') >= 1,
    'The plan selector posts the two codes the checkout uses.',
    'The plan selector does not post recorded_only / live_recorded.');

$signIn($studentId, 'Render Smoke Student', $studentEmail, 'student');
$_SERVER['REQUEST_URI'] = '/TSG-Academy-LMS/learning/lesson?lesson_id=' . $lessonId;
$lessonRow = ['lesson_id' => $lessonId, 'lesson_title' => 'Smoke lesson', 'module_title' => 'Smoke module',
    'course_id' => $courseId, 'course_title' => 'Render Smoke Course', 'body' => 'Lesson body.', 'completed' => 0];
$liveHtml = $render(static fn() => \App\Core\View::render('learning/lesson', [
    'title' => 'Smoke lesson',
    'user' => ['id' => $studentId, 'name' => 'Render Smoke Student', 'email' => $studentEmail, 'role' => 'student'],
    'lesson' => $lessonRow,
    'liveClass' => ['url' => $meetingAddress, 'at' => $classStart],
]));
$assert(str_contains($liveHtml, 'href="' . $meetingAddress . '"') && str_contains($liveHtml, 'Open live class'),
    'A lesson page handed the class prints the address and a button to open it.',
    'The live class card did not render the address it was given.');
$assert(str_contains($liveHtml, 'rel="noopener noreferrer"') && str_contains($liveHtml, 'target="_blank"'),
    'The meeting opens in its own tab, cut off from the lesson page that sent the student there.',
    'The live class button opens without noopener, so the meeting window can reach back to the LMS.');
$assert(str_contains($liveHtml, $classSpelling), 'The start time is printed labelled UTC, the same clock the record is kept in.',
    'The class time reached the page unlabelled or not at all.');
// The gate is the controller's, and the template obeys it: a row that carries an address is handed
// over with the plan closed, and the bytes contain none of it. Hiding the card with CSS would leave
// the address in the page for anybody who looked, which is the one outcome this feature cannot undo.
$closedHtml = $render(static fn() => \App\Core\View::render('learning/lesson', [
    'title' => 'Smoke lesson',
    'user' => ['id' => $studentId, 'name' => 'Render Smoke Student', 'email' => $studentEmail, 'role' => 'student'],
    'lesson' => $lessonRow + ['meeting_url' => $meetingAddress, 'meeting_at' => $classStart, 'plan_code' => AccessPlan::RECORDED],
    'liveClass' => null,
]));
foreach ([$meetingAddress, 'meet.google.com', 'Open live class', 'lesson-live-class', 'LIVE CLASS'] as $leak) {
    $assert(!str_contains($closedHtml, $leak), 'A Recorded Only lesson page holds no "' . $leak . '".',
        'The closed gate still let "' . $leak . '" into the rendered page.');
}
$assert(str_contains($closedHtml, 'Lesson body.'), 'The recording and the lesson text are untouched for that student.',
    'Closing the live class took the lesson page down with it.');
$db->prepare('UPDATE lessons SET meeting_url = NULL, meeting_at = NULL WHERE id = ?')->execute([$lessonId]);

/* ------------------------------------------------------- work waiting on the Academy */
// A student can leave six kinds of work sitting on the Academy. Each has to be countable straight
// from the sidebar, so signing in is enough to know where to start looking.
echo "\n-- administrator attention badges\n";
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
// The messages badge counts conversations, not rows, so this fixture opens the student's own line and
// writes the newest half of it - the one state the chat desk is meant to surface on its own.
$chats = new ChatService($db);
$threadId = $chats->studentThread($studentId);
$writeMessage = static function (int $senderId, string $text, int $minutesAgo) use ($db, $threadId): void {
    $db->prepare('INSERT INTO chat_messages(thread_id,sender_id,message,created_at) VALUES(?,?,?,UTC_TIMESTAMP() - INTERVAL ? MINUTE)')
        ->execute([$threadId, $senderId, $text, $minutesAgo]);
};
$writeMessage($studentId, 'Will the class be recorded?', 60);
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

$writeMessage($adminId, 'Yes - the recording goes up the same evening.', 50);
$answered = (new AdminAttention($db))->pendingByArea();
$assert(($answered['messages'] ?? 0) === ($before['messages'] ?? 0), 'An Academy answer takes the conversation back off the queue.',
    'The messages badge still counts a thread the Academy has already answered (' . json_encode($answered) . ' against ' . json_encode($before) . ').');

/* ---------------------------------------------------------------------- private conversation */
// The conversation is two pages now: the student's own line to the Academy, and the desk where an
// administrator picks a student. Both are rendered here, because the frame, the emoji picker and the
// menu beside a name are the parts a reader actually uses - and a page that fatals over one of them is
// exactly the failure this suite exists to catch.
echo "\n-- private conversation\n";
$writeMessage($studentId, 'Please send the link before Friday.', 40);
$signIn($studentId, 'Render Smoke Student', $studentEmail, 'student');
$_GET = ['thread_id' => (string) $threadId];
$html = $render(static fn() => (new ChatController())->studentIndex());
$_GET = [];
$assert(str_contains($html, 'professional-chat-layout') && str_contains($html, 'professional-chat-main')
        && str_contains($html, 'professional-composer') && str_contains($html, '/chats/send"')
        && str_contains($html, 'name="message"'),
    'The student page renders its frame, its feed and a composer that posts to the send endpoint.',
    'The student chat lost its layout, its composer or the endpoint a message goes to.');
$assert(str_contains($html, 'Will the class be recorded?') && str_contains($html, 'Yes - the recording goes up the same evening.')
        && str_contains($html, 'Please send the link before Friday.'),
    'Every message written on this line is on the page, from both sides.',
    'The student feed is missing one of the three messages this conversation holds.');
$assert(substr_count($html, 'class="professional-message') === 3
        && substr_count($html, 'class="professional-message is-mine"') === 2,
    'The reader\'s own two bubbles sit on one side and the Academy\'s single answer on the other.',
    'The student feed marked ' . substr_count($html, 'class="professional-message is-mine"') . ' of three messages as their own.');
// The separator between the day and the time is a printed glyph, so it is matched as "whatever is
// not a digit" rather than as one particular byte - the assertion is about a readable stamp.
$timeLines = preg_match_all('/<time>[A-Z][a-z]{2} \d{1,2}[^\d<]+\d{1,2}:\d{2} [AP]M<\/time>/', $html);
$assert($timeLines === 3, 'Every bubble carries a readable date and time under it.',
    $timeLines . ' of the three messages printed a time line this page can be read by.');
$assert(str_contains($html, 'data-chat-panel-toggle="chat-emoji-panel"') && str_contains($html, 'id="chat-emoji-panel"')
        && str_contains($html, 'aria-label="Add an emoji"')
        && substr_count($html, '<button class="chat-emoji" type="button" data-emoji="') === 48
        && substr_count($html, 'aria-label="Insert ') === 48,
    'The composer carries a forty-eight glyph picker that opens as a panel of buttons, never as form inputs.',
    'The emoji picker offered ' . substr_count($html, '<button class="chat-emoji" type="button" data-emoji="') . ' buttons instead of forty-eight.');
$assert(str_contains($html, 'chat-emoji-group') && str_contains($html, 'Study and work'),
    'The picker is grouped, so forty-eight glyphs do not arrive as one undifferentiated block.',
    'The emoji popover lost its group headings.');
$assert(str_contains($html, 'data-chat-panel-toggle="chat-thread-menu"') && str_contains($html, 'id="chat-thread-menu"')
        && str_contains($html, 'aria-label="More chat actions"') && str_contains($html, '/chats/clear"')
        && str_contains($html, 'Clear this chat') && str_contains($html, 'Clear this chat for you only?'),
    'The three dots beside the name hold the one action a student is offered: clearing this chat for themselves.',
    'The chat menu is missing, or its clear form does not address this conversation.');
$assert(!str_contains($html, 'chat-delete-confirm') && !str_contains($html, '/admin/chats/delete')
        && !str_contains($html, 'Delete conversation'),
    'No permanent delete is offered on a student page, because a student cannot destroy the Academy\'s copy.',
    'A permanent delete control reached the student chat.');
/* Both panels are printed closed and the button only flips the attribute, so the stylesheet has to
   name the attribute as well: a display written by the author on the class outranks the browser's own
   [hidden] handling, which is exactly how the menu and the picker were found open on every load. */
$stylesheet = (string) file_get_contents(BASE_PATH . '/public/assets/css/pages.css');
foreach (['chat-menu' => 'the three-dot menu', 'chat-emoji-popover' => 'the emoji picker'] as $panelClass => $panelName) {
    $printedClosed = str_contains($html, 'class="' . $panelClass . '" id="') && str_contains($html, ' hidden>');
    $closedByCss = preg_match('/\.' . preg_quote($panelClass, '/') . '\[hidden\]\s*\{\s*display:\s*none/', $stylesheet) === 1;
    $assert($printedClosed && $closedByCss, $panelName . ' is printed closed and the stylesheet keeps it closed.',
        $panelName . ' ' . ($printedClosed ? 'is printed closed but nothing in the stylesheet honours [hidden] on it' : 'is not printed with its hidden attribute') . ', so it opens before anyone asks.');
}

$signIn($adminId, 'Render Smoke Admin', $adminEmail, 'admin');
$_GET = ['thread_id' => (string) $threadId];
$html = $render(static fn() => (new ChatController())->adminIndex());
$_GET = [];
$assert(str_contains($html, 'professional-chat-head') && str_contains($html, 'chat-head-copy')
        && str_contains($html, 'Render Smoke Student') && str_contains($html, $studentEmail),
    'The desk names the student the thread belongs to and prints the address beside the name.',
    'The admin chat header does not identify whose conversation is on screen.');
$assert(str_contains($html, 'name="student_id"') && str_contains($html, 'Select student'),
    'The desk is steered from its own student picker.',
    'The student picker is gone from the desk.');
$assert(substr_count($html, 'class="professional-message') === 3
        && substr_count($html, 'class="professional-message is-mine"') === 1,
    'The same three messages read mirrored for the Academy, whose own answer is the one on the right.',
    'The desk did not show all three messages, or it marked the wrong half as its own.');
$assert(str_contains($html, 'Clear this chat for the desk only?') && str_contains($html, 'the student keeps reading every message'),
    'The desk\'s clear is worded as a desk action and says which side it leaves alone.',
    'The clear confirmation on the desk reads word for word like the student side.');
$assert(str_contains($html, 'data-admin-modal-open="chat-delete-confirm"') && str_contains($html, '/admin/chats/delete"')
        && str_contains($html, 'name="confirm_phrase"') && str_contains($html, 'DELETE THIS CONVERSATION')
        && str_contains($html, 'name="irreversible" value="1"'),
    'The permanent delete sits behind the same typed phrase and acknowledgement as every other purge tool.',
    'The desk offers no delete dialog, or the dialog can run without the phrase and the checkbox.');
$assert(str_contains($html, 'All conversations') && str_contains($html, 'Waiting for you <small>1</small>'),
    'The chips above the list count the conversations waiting on an answer.',
    'The waiting chip does not agree with the list it filters.');
$waiting = $chats->adminThreads(true);
$assert(count($waiting) === 1 && ChatService::awaitsReply($waiting[0]) && $chats->waitingCount() === 1,
    'A conversation whose newest message came from a student is listed, counted and flagged as waiting.',
    'The waiting view held ' . count($waiting) . ' thread(s) against a count of ' . $chats->waitingCount() . '.');
$writeMessage($adminId, 'The link is in your dashboard now.', 30);
$openThreads = $chats->adminThreads();
$assert($chats->adminThreads(true) === [] && $chats->waitingCount() === 0 && $openThreads !== []
        && ChatService::awaitsReply($openThreads[0]) === false,
    'Answering takes a conversation out of the waiting view without taking it off the desk.',
    'An answered conversation is still listed as waiting, or it left the desk altogether.');
$_GET = ['filter' => 'waiting'];
$html = $render(static fn() => (new ChatController())->adminIndex());
$_GET = [];
$assert(str_contains($html, 'Nothing is waiting for an answer.') && str_contains($html, 'Select a student'),
    'An answered desk says so in words instead of printing an empty table beside the picker.',
    'The waiting filter did not report that every conversation has an answer.');

/* The marks below are moved to fixed points in the past instead of being trusted to "a moment ago":
   the rule under test is which side of a timestamp a message falls on, not how fast this machine
   writes a row, and a stamp kept to the second would make either half of that a coin toss. */
$newsBefore = $notifier->unreadByArea($studentId);
$hidden = $chats->clear($threadId, 'student');
$assert($hidden === 4 && $chats->messages($threadId, 'student') === [] && $chats->studentThreads($studentId) === [],
    'Clearing takes all four messages out of the student feed, their preview and their list.',
    'The student clear hid ' . $hidden . ' message(s) and left ' . count($chats->messages($threadId, 'student')) . ' in the feed.');
$assert(count($chats->messages($threadId, 'admin')) === 4 && count($chats->adminThreads()) === 1,
    'The Academy is still reading all four, because a clear never reaches the other side.',
    'Clearing the student side changed what the desk can see.');
$assert((int) $db->query('SELECT COUNT(*) FROM chat_messages WHERE thread_id = ' . (int) $threadId)->fetchColumn() === 4,
    'Nothing was deleted - the four rows are exactly where they were.',
    'A clear destroyed message rows it has no claim on.');
$assert($notifier->unreadByArea($studentId) === $newsBefore, 'Clearing a chat leaves every news badge exactly where it was.',
    'The chat clear moved the notification counters to ' . json_encode($notifier->unreadByArea($studentId)) . '.');
$db->exec('UPDATE chat_threads SET student_cleared_at = UTC_TIMESTAMP() - INTERVAL 35 MINUTE WHERE id = ' . (int) $threadId);
$reopenedFeed = $chats->messages($threadId, 'student');
$assert(count($reopenedFeed) === 1 && (string) ($reopenedFeed[0]['message'] ?? '') === 'The link is in your dashboard now.',
    'A mark hides only what was written before it, so the answer that came after shows here again.',
    'A moved clear stamp left ' . count($reopenedFeed) . ' message(s) visible to the student.');
$signIn($studentId, 'Render Smoke Student', $studentEmail, 'student');
$db->exec('UPDATE chat_threads SET student_cleared_at = UTC_TIMESTAMP() WHERE id = ' . (int) $threadId);
$_GET = ['thread_id' => (string) $threadId];
$html = $render(static fn() => (new ChatController())->studentIndex());
$_GET = [];
$assert(str_contains($html, 'No messages on this side right now')
        && str_contains($html, 'This chat is cleared for you. The Academy still reads it')
        && str_contains($html, 'Your conversation with the Academy is always here.'),
    'A cleared chat explains itself on both halves of the page: an empty feed here, an empty list there.',
    'The cleared state printed neither its feed note nor its list note.');
$hidden = $chats->clear($threadId, 'admin');
$assert($hidden === 4 && $chats->adminThreads() === [] && $chats->adminThreadCount() === 1,
    'The desk clears its own view, and the thread row survives so the student can still write.',
    'The desk clear hid ' . $hidden . ' message(s) against a thread count of ' . $chats->adminThreadCount() . '.');
$clearedQueue = (new AdminAttention($db))->pendingByArea();
$assert(($clearedQueue['messages'] ?? 0) === ($before['messages'] ?? 0),
    'A conversation cleared off the desk comes off the Chats badge with it.',
    'The badge still counts a conversation the desk deliberately cleared: ' . json_encode($clearedQueue) . '.');
$db->exec('UPDATE chat_threads SET admin_cleared_at = UTC_TIMESTAMP() - INTERVAL 25 MINUTE WHERE id = ' . (int) $threadId);
$writeMessage($studentId, 'Still waiting on that link.', 20);
$revived = $chats->adminThreads();
$assert(count($revived) === 1 && (int) ($revived[0]['message_count'] ?? 0) === 1 && ChatService::awaitsReply($revived[0]),
    'The next message from that student puts the conversation back on the desk, one message deep.',
    'A cleared conversation came back holding ' . json_encode(array_map(static fn(array $thread): int => (int) ($thread['message_count'] ?? -1), $revived)) . ' message(s) per row.');
$revivedQueue = (new AdminAttention($db))->pendingByArea();
$assert(($revivedQueue['messages'] ?? 0) === 1, 'and the badge comes back with it.',
    'The reopened thread did not return to the messages queue: ' . json_encode($revivedQueue) . '.');
$assert($chats->unreadCount($studentId) === 0,
    'A chat this side cleared is not counted as unread work for the side that cleared it.',
    'The unread count read ' . $chats->unreadCount($studentId) . ' for a student who cleared the conversation.');
$assert($chats->thread($threadId, $adminId, true) !== null && $chats->thread($threadId, 999999, false) === null
        && $chats->thread(999999, $studentId, false) === null,
    'A thread is readable by the desk and by its own student, and by nobody else.',
    'ChatService handed a conversation to an account it does not belong to.');
$sideRefusal = '';
try {
    $chats->messages($threadId, 'administrator');
} catch (RuntimeException $exception) {
    $sideRefusal = $exception->getMessage();
}
$assert($sideRefusal !== '', 'A side that is not on the two-name list is refused before a column is built into the SQL.',
    'messages() accepted a side with no clearing column, which is how a forged one would be phrased.');
$deleted = $chats->deleteMessages($threadId);
$assert($deleted === 5 && $chats->messages($threadId, 'admin') === [] && $chats->messages($threadId, 'student') === []
        && $chats->thread($threadId, $adminId, true) !== null,
    'The desk\'s delete takes all five messages for both sides and keeps the thread, so the next message opens a clean page.',
    'deleteMessages() removed ' . $deleted . ' row(s) from a conversation holding five.');
$assert($chats->clear($threadId, 'student') === 0,
    'A conversation with nothing left to hide reports zero instead of claiming a second clear.',
    'clear() found messages in a conversation that had just been emptied.');

// Leave every queue exactly as this section found it, so a rerun measures the same deltas. The chat
// goes back entirely, thread row included, because a conversation with no messages is the state this
// run started from - and the badge counts conversations, not rows.
$db->exec('DELETE FROM chat_threads WHERE student_id = ' . (int) $studentId);
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
