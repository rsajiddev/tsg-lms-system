<?php
declare(strict_types=1);

/**
 * Admin and student workspace render smoke test.
 *
 * Runs on a scratch database only. It boots the schema, signs in as a seeded admin and a seeded
 * student through the session, publishes real notifications through NotificationService, and then
 * renders the pages those two roles depend on: the dashboard with its sidebar badges, the
 * announcement workspace, the student inbox, the account settings page and the three workspaces
 * that carry the bulk delete tool. A page that fatals, a badge that stays at zero while unread
 * notifications exist, or a purge card without its confirmation phrase all fail this test.
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
        '/app/Services/AuthService.php',
        '/app/Services/CourseDuration.php',
        '/app/Services/LessonMedia.php',
        '/app/Services/CourseGrading.php',
        '/app/Services/AttendanceService.php',
        '/app/Controllers/DashboardController.php',
        '/app/Controllers/NotificationController.php',
        '/app/Controllers/AccountController.php',
        '/app/Controllers/AssignmentController.php',
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
use App\Controllers\CourseAdminController;
use App\Controllers\PublicPageController;
use App\Controllers\StudentManagementController;
use App\Controllers\DashboardController;
use App\Controllers\NotificationController;
use App\Controllers\QuizController;
use App\Services\AccountIdentity;
use App\Services\AdminAttention;
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
foreach ([
    [NotificationService::ANNOUNCEMENT, 'Class on Friday', 'The live class moves to Friday.', 'announcement', 0, '/notifications'],
    [NotificationService::LESSON, 'New lesson published', 'Smoke lesson is ready for you.', 'lesson', $lessonId, '/learning/lesson/' . $lessonId],
    [NotificationService::QUIZ, 'New quiz released', 'Smoke quiz is open.', 'quiz', $quizId, '/learning/quizzes'],
    [NotificationService::ASSIGNMENT, 'New assignment', 'Smoke assignment has been uploaded.', 'assignment', $assignmentId, '/learning/assignments'],
    [NotificationService::COURSE, 'Course updated', 'The syllabus has been refreshed.', 'course', $courseId, '/learning/course/' . $courseId],
    [NotificationService::PAYMENT, 'Payment approved', 'Your payment has been approved.', 'payment', $courseId, '/student/payments'],
    [NotificationService::GRADE, 'Grade published', 'Your quiz result has been graded.', 'grade', $quizId, '/learning/quizzes'],
    [NotificationService::CERTIFICATE, 'Certificate ready', 'Your certificate is available.', 'certificate', $courseId, '/student/courses'],
] as [$category, $title, $body, $sourceType, $sourceId, $link]) {
    if ($sourceId > 0) {
        $notifier->notifyCourse($courseId, $category, $title, $body, $sourceType, $sourceId, $link, $adminId);
    } else {
        $notifier->notifyStudents([$studentId], $category, $title, $body, $sourceType, max(1, $courseId), $link, $adminId);
    }
}
$expectedBadges = ['notifications' => 1, 'lessons' => 1, 'quizzes' => 2, 'assignments' => 1, 'courses' => 2, 'payments' => 1];
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
$checkPage('Announcement workspace', '/admin/announcements', static fn() => (new NotificationController())->adminIndex(), [
    'Automated student updates',
    'Email delivery log',
    'Clear activity',
    'Clear the announcement board',
    'DELETE ALL ANNOUNCEMENTS',
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
    'Grade published',
    'Payment approved',
    'Mark all read',
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
    '/admin/announcements' => 'Announcements',
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

$db->prepare('INSERT INTO notification_replies(notification_id,student_id,recipient_student_id,message) VALUES(?,?,?,?)')
    ->execute([$announcementId, $adminId, $studentId, 'Yes - the recording goes up the same evening.']);
$answered = (new AdminAttention($db))->pendingByArea();
$assert(($answered['messages'] ?? 0) === ($before['messages'] ?? 0), 'An Academy answer takes the conversation back off the queue.',
    'The messages badge still counts a thread the Academy has already answered (' . json_encode($answered) . ' against ' . json_encode($before) . ').');

/* ---------------------------------------------------------------------- private conversation */
echo "\n-- private conversation\n";
$_SERVER['REQUEST_URI'] = '/TSG-Academy-LMS/notifications/view?notification_id=' . $announcementId;
$signIn($studentId, 'Render Smoke Student', $studentEmail, 'student');
// signIn() clears $_GET, so the thread is addressed after the session is put in place.
$_GET = ['notification_id' => (string) $announcementId];
$html = $render(static fn() => (new NotificationController())->show());
$assert(str_contains($html, 'conversation-page') && str_contains($html, 'conversation-panel') && str_contains($html, 'conversation-bubble'),
    'The student thread renders its page frame, panel and message bubbles.', 'The student thread did not render the conversation page, panel or bubbles.');
$assert(str_contains($html, 'Will the class be recorded?'), 'The student sees their own message in the thread.', 'The student\'s message is missing from the thread.');
$assert(str_contains($html, 'Academy announcement'), 'The announcement opens the thread.', 'The announcement is not printed above the replies.');
$assert(str_contains($html, 'is-mine'), 'The reader\'s own message is aligned as theirs.', 'Nothing in the thread is recognised as the reader\'s own message.');
$assert(preg_match('/<time[^>]*>[A-Z][a-z]{2} \d{1,2}, \d{4} · \d{1,2}:\d{2} [AP]M</', $html) === 1,
    'A message prints its date and time to the minute.', 'No message carries a readable date and time.');
$assert(str_contains($html, 'action="') && str_contains($html, '/notifications/reply') && str_contains($html, 'name="message"'),
    'The composer still posts a reply to the same endpoint with the same field.', 'The reply form lost its endpoint or its message field.');
$assert(stripos($html, 'admin-alert-success') === false && stripos($html, 'Reply added') === false,
    'The thread keeps no send-confirmation banner above the chat.', 'The conversation still prints a flash banner.');

$signIn($adminId, 'Render Smoke Admin', $adminEmail, 'admin');
$_GET = ['notification_id' => (string) $announcementId, 'student_id' => (string) $studentId];
$html = $render(static fn() => (new NotificationController())->show());
$assert(str_contains($html, 'conversation-picker') && str_contains($html, 'name="student_id"'),
    'The admin thread still offers the recipient picker.', 'The admin lost the recipient picker.');
$assert(str_contains($html, 'Reply privately to Render Smoke Student'),
    'The composer names the student the reply goes to.', 'The admin composer does not name its recipient.');
$assert(str_contains($html, 'Academy team') && str_contains($html, 'name="recipient_student_id"'),
    'The admin\'s own answer is labelled as the Academy and pinned to the thread.', 'An admin reply is not identified or not scoped to the student.');

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
