<?php
declare(strict_types=1);

/**
 * Bulk delete (testing purge) smoke test.
 *
 * Run against a scratch database:
 *   /Applications/XAMPP/xamppfiles/bin/php tests/purge-tools-smoke.php --database=tsg_purge_check_tmp
 *
 * The scratch database is created and loaded from database/migrations/001_initial_schema.sql by
 * the script itself when it does not exist yet, so one command is enough.
 *
 * The script seeds one course with lessons, quizzes, attempts, assignments and submissions, then
 * drives the three real purge endpoints the admin workspace exposes - in a child process each,
 * because a controller ends with a redirect. It proves that a wrong phrase deletes nothing, that
 * the right phrase empties every dependent table in the order the foreign keys demand, that the
 * stored submission file is removed, that the matching student notifications retire, and that an
 * audit row records the scale of the deletion. It only ever runs against a scratch database.
 */

define('BASE_PATH', dirname(__DIR__));
foreach (
    [
        '/app/Core/Env.php',
        '/app/Core/View.php',
        '/app/Core/Security.php',
        '/app/Core/Database.php',
        '/app/Core/AuthMiddleware.php',
        '/app/Core/PurgeGuard.php',
        '/app/Services/EmailLayout.php',
        '/app/Services/EmailMessages.php',
        '/app/Services/AccountIdentity.php',
        '/app/Services/MailService.php',
        '/app/Services/NotificationService.php',
        '/app/Services/CourseDuration.php',
        '/app/Services/LessonMedia.php',
        '/app/Services/CourseGrading.php',
        '/app/Controllers/AssignmentController.php',
        '/app/Controllers/QuizController.php',
        '/app/Controllers/CourseAdminController.php',
    ] as $file
) {
    require BASE_PATH . $file;
}

use App\Core\Database;
use App\Core\Env;
use App\Core\Security;
use App\Controllers\AssignmentController;
use App\Controllers\CourseAdminController;
use App\Controllers\QuizController;
use App\Services\NotificationService;

Env::load(BASE_PATH . '/.env');
$options = getopt('', ['database::', 'run::', 'bad::']);
if (isset($options['database']) && is_string($options['database']) && $options['database'] !== '') {
    putenv('DB_NAME=' . $options['database']);
}
$dbName = (string) (getenv('DB_NAME') ?: '');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$assert(preg_match('/(tmp|check|scratch|test)/i', $dbName) === 1, 'Refusing to run: --database=' . $dbName . ' does not look like a scratch database.');

// Bootstrap the scratch schema on demand so the whole check stays a single command.
$mysql = '/Applications/XAMPP/xamppfiles/bin/mysql';
$password = (string) (getenv('DB_PASS') ?: '');
$credentials = '-u ' . escapeshellarg((string) (getenv('DB_USER') ?: 'root'))
    . ($password === '' ? '' : ' -p' . escapeshellarg($password))
    . ' -h ' . escapeshellarg((string) (getenv('DB_HOST') ?: '127.0.0.1'))
    . ' -P ' . escapeshellarg((string) (getenv('DB_PORT') ?: '3306'));
$tableCount = (int) trim((string) shell_exec($mysql . ' ' . $credentials . ' -N -B -e '
    . escapeshellarg("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='" . $dbName . "'")));
if ($tableCount < 20) {
    shell_exec($mysql . ' ' . $credentials . ' -e ' . escapeshellarg('CREATE DATABASE IF NOT EXISTS `' . $dbName . '`'));
    shell_exec($mysql . ' ' . $credentials . ' ' . escapeshellarg($dbName) . ' < '
        . escapeshellarg(BASE_PATH . '/database/migrations/001_initial_schema.sql'));
    echo "bootstrapped scratch schema into " . $dbName . "\n";
}
$assert($tableCount >= 20 || (int) trim((string) shell_exec($mysql . ' ' . $credentials . ' -N -B -e '
    . escapeshellarg("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='" . $dbName . "'"))) >= 20,
    'The scratch database has no schema; import database/migrations/001_initial_schema.sql and rerun.');

$db = Database::connection();
$adminEmail = 'purge-smoke-admin@example.test';
$studentEmail = 'purge-smoke-student@example.test';
$phrases = [
    'assignments' => 'DELETE ALL ASSIGNMENTS',
    'quizzes' => 'DELETE ALL QUIZZES',
    'lessons' => 'DELETE ALL LESSONS',
];

/* --------------------------------------------------------------------- child process */
$run = isset($options['run']) && is_string($options['run']) ? $options['run'] : '';
if ($run !== '') {
    $assert(isset($phrases[$run]), 'Unknown purge target ' . $run . '.');
    $find = $db->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
    $find->execute([$adminEmail]);
    $adminId = (int) $find->fetchColumn();
    $assert($adminId > 0, 'The seeded admin is missing; run the parent first.');
    $_SESSION = ['user' => ['id' => $adminId, 'name' => 'Purge Smoke Admin', 'email' => $adminEmail, 'role' => 'admin']];
    $_POST = [
        '_csrf' => Security::csrfToken(),
        'confirm_phrase' => (($options['bad'] ?? '') === '1') ? 'DELETE EVERYTHING PLEASE' : $phrases[$run],
        'irreversible' => '1',
        'course_id' => '0',
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    register_shutdown_function(static function (): void {
        echo 'FLASH|' . str_replace("\n", ' ', (string) ($_SESSION['_flash_error'] ?? $_SESSION['_flash'] ?? 'none')) . "\n";
    });
    try {
        match ($run) {
            'assignments' => (new AssignmentController())->purgeAll(),
            'quizzes' => (new QuizController())->purgeAll(),
            'lessons' => (new CourseAdminController())->purgeLessons(),
        };
    } catch (Throwable $exception) {
        echo 'THROWN|' . $exception->getMessage() . "\n";
    }
    exit(0);
}

/* ------------------------------------------------------------------------- test data */
$tables = [
    'course_grades', 'certificates', 'notification_reads',
    'quiz_answers', 'quiz_attempt_options', 'quiz_attempt_questions', 'quiz_options',
    'quiz_retake_approvals', 'quiz_attempts', 'quiz_questions', 'quizzes',
    'assignment_submissions', 'assignments', 'lesson_progress', 'lessons', 'course_modules',
    'student_course_access', 'course_pricing_options', 'courses',
    'notification_recipients', 'notification_replies', 'notifications', 'audit_logs', 'email_logs',
];
foreach ($tables as $table) {
    // Tables a fresh schema does not have yet, or that hold no rows, are simply skipped.
    try {
        $db->exec('DELETE FROM ' . $table);
    } catch (PDOException $exception) {
        if ((int) ($exception->errorInfo[1] ?? 0) !== 1146) throw $exception;
    }
}
$db->exec('DELETE FROM users WHERE email IN (' . $db->quote($adminEmail) . ',' . $db->quote($studentEmail) . ')');

$fileHash = bin2hex(random_bytes(24));
$fileRelative = 'assignments/' . $fileHash . '.txt';
$uploadDir = BASE_PATH . '/storage/uploads/private/assignments';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0700, true);
}
$uploadPath = $uploadDir . '/' . $fileHash . '.txt';
file_put_contents($uploadPath, 'Purge smoke submission payload.');

try {
    $insert = $db->prepare("INSERT INTO users(full_name,email,password_hash,role,status,email_verified_at) VALUES(?,?,?,?,'active',UTC_TIMESTAMP())");
    $insert->execute(['Purge Smoke Admin', $adminEmail, 'unusable-hash', 'admin']);
    $adminId = (int) $db->lastInsertId();
    $insert->execute(['Purge Smoke Student', $studentEmail, 'unusable-hash', 'student']);
    $studentId = (int) $db->lastInsertId();

    $db->prepare("INSERT INTO courses(title,slug,visibility,created_by) VALUES('Purge Smoke Course','purge-smoke-course','published',?)")
        ->execute([$adminId]);
    $courseId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO course_modules(course_id,title) VALUES(?,?)')->execute([$courseId, 'Smoke module']);
    $moduleId = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO student_course_access(student_id,course_id,status,granted_by) VALUES(?,?,'active',?)")
        ->execute([$studentId, $courseId, $adminId]);

    $lessonInsert = $db->prepare("INSERT INTO lessons(module_id,title,body,status) VALUES(?,?,?,'published')");
    $lessonIds = [];
    foreach (['Lesson one', 'Lesson two', 'Lesson three'] as $title) {
        $lessonInsert->execute([$moduleId, $title, 'Body of ' . $title]);
        $lessonIds[] = (int) $db->lastInsertId();
    }
    $progress = $db->prepare('INSERT INTO lesson_progress(student_id,lesson_id,completed_at) VALUES(?,?,UTC_TIMESTAMP())');
    foreach ($lessonIds as $lessonId) {
        $progress->execute([$studentId, $lessonId]);
    }

    $quizIds = [];
    foreach ([1 => 'Smoke quiz A', 2 => 'Smoke quiz B'] as $week => $title) {
        $db->prepare("INSERT INTO quizzes(course_id,week_number,title,quiz_type,status,created_by) VALUES(?,?,?,'practice','released',?)")->execute([$courseId, $week, $title, $adminId]);
        $quizIds[] = (int) $db->lastInsertId();
    }
    $questionIds = [];
    $optionIds = [];
    foreach ($quizIds as $quizId) {
        $db->prepare('INSERT INTO quiz_questions(quiz_id,question_text,marks) VALUES(?,?,5)')->execute([$quizId, 'Which one is it?']);
        $questionId = (int) $db->lastInsertId();
        $questionIds[] = $questionId;
        $optionInsert = $db->prepare('INSERT INTO quiz_options(question_id,option_text,is_correct) VALUES(?,?,?)');
        $optionInsert->execute([$questionId, 'Right', 1]);
        $optionIds[] = (int) $db->lastInsertId();
        $optionInsert->execute([$questionId, 'Wrong', 0]);
    }

    $attemptIds = [];
    foreach ($quizIds as $index => $quizId) {
        $db->prepare("INSERT INTO quiz_attempts(quiz_id,student_id,started_at,deadline_at,status,score,total_marks,percentage,result)
            VALUES(?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP() + INTERVAL 10 MINUTE,'submitted',5,5,100,'pass')")->execute([$quizId, $studentId]);
        $attemptId = (int) $db->lastInsertId();
        $attemptIds[] = $attemptId;
        $db->prepare('INSERT INTO quiz_answers(attempt_id,question_id,selected_option_id,awarded_marks) VALUES(?,?,?,?)')
            ->execute([$attemptId, $questionIds[$index], $optionIds[$index], 5]);
        $db->prepare("INSERT INTO quiz_retake_approvals(quiz_id,student_id,request_status,student_requested_at) VALUES(?,?,'pending',UTC_TIMESTAMP())")
            ->execute([$quizId, $studentId]);
    }

    $assignmentInsert = $db->prepare("INSERT INTO assignments(course_id,title,instructions,max_marks,status,created_by) VALUES(?,?,'Brief',100,'published',?)");
    $assignmentIds = [];
    foreach (['Smoke assignment one', 'Smoke assignment two'] as $title) {
        $assignmentInsert->execute([$courseId, $title, $adminId]);
        $assignmentIds[] = (int) $db->lastInsertId();
    }
    $submissionInsert = $db->prepare("INSERT INTO assignment_submissions(assignment_id,student_id,submission_text,file_path,status,submitted_at)
        VALUES(?,?,?,?,'submitted',UTC_TIMESTAMP())");
    $submissionInsert->execute([$assignmentIds[0], $studentId, 'Written answer only', null]);
    $submissionInsert->execute([$assignmentIds[1], $studentId, 'Answer with a file', $fileRelative]);

    $notification = $db->prepare("INSERT INTO notifications(created_by,category,title,body,audience,source_type,source_id,link,published_at)
        VALUES(?,'lesson','Lesson one published','Body','selected_students','lesson',?,NULL,UTC_TIMESTAMP())");
    $notification->execute([$adminId, $lessonIds[0]]);
    $notificationId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO notification_recipients(notification_id,student_id,read_at) VALUES(?,?,NULL)')
        ->execute([$notificationId, $studentId]);

    /* ------------------------------------------------------------------- assertions */
    $count = static fn(string $table): int => (int) $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    $assert($count('lessons') === 3 && $count('quizzes') === 2 && $count('assignments') === 2 && $count('quiz_attempts') === 2, 'Seeding did not produce the expected fixture.');
    $badgeCheck = static fn(): int => (new NotificationService($db))->unreadByArea($studentId)['lessons'];
    $assert($badgeCheck() === 1, 'The lessons badge should count the seeded notification.');

    // The self command is the child process; exec() keeps the exit/redirect behaviour honest.
    $self = escapeshellarg(\PHP_BINARY);
    $script = escapeshellarg(__FILE__);
    $database = escapeshellarg('--database=' . $dbName);
    $invoke = static function (string $target, bool $bad = false) use ($self, $script, $database): string {
        $command = $self . ' ' . $script . ' ' . $database . ' --run=' . escapeshellarg($target) . ($bad ? ' --bad=1' : '') . ' 2>&1';
        return (string) shell_exec($command);
    };

    $rejected = $invoke('quizzes', true);
    $assert(str_contains($rejected, 'FLASH|') && str_contains($rejected, 'confirmation phrase'), 'A wrong phrase was not rejected with a readable reason: ' . trim($rejected));
    $assert($count('quizzes') === 2 && $count('quiz_attempts') === 2, 'A wrong phrase deleted data anyway.');

    $output = $invoke('assignments');
    $assert(str_contains($output, 'FLASH|Deleted 2 assignment(s) and 2 submission(s)'), 'Assignment purge reported: ' . trim($output));
    $assert($count('assignments') === 0 && $count('assignment_submissions') === 0, 'Assignment rows survived the purge.');
    $assert(!is_file($uploadPath), 'The stored submission file was left behind by the assignment purge.');
    $assert((int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE action='assignment.bulk_deleted'")->fetchColumn() === 1, 'No audit row for the assignment purge.');

    $output = $invoke('lessons');
    $assert(str_contains($output, 'FLASH|Deleted 3 lesson(s) and 3 progress record(s)'), 'Lesson purge reported: ' . trim($output));
    $assert($count('lessons') === 0 && $count('lesson_progress') === 0, 'Lesson rows survived the purge.');
    $assert($badgeCheck() === 0, 'The lessons badge still counts a notification for a purged lesson.');
    $assert((int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE action='lesson.bulk_deleted'")->fetchColumn() === 1, 'No audit row for the lesson purge.');

    $output = $invoke('quizzes');
    $assert(str_contains($output, 'FLASH|Deleted 2 quiz(s), 2 question(s) and 2 attempt record(s)'), 'Quiz purge reported: ' . trim($output));
    foreach (['quiz_answers', 'quiz_attempts', 'quiz_questions', 'quizzes', 'quiz_retake_approvals'] as $table) {
        $assert($count($table) === 0, $table . ' still holds rows after the quiz purge.');
    }
    $assert((int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE action='quiz.bulk_deleted'")->fetchColumn() === 1, 'No audit row for the quiz purge.');

    // Repeating a purge on an empty workspace must say so instead of failing.
    $output = $invoke('quizzes');
    $assert(str_contains($output, 'FLASH|There are no quizzes left to delete.'), 'An empty quiz purge reported: ' . trim($output));

    echo "purge tools: wrong phrase blocked, three workspaces cleared, files, notifications and audits handled\n";
    echo "PASS: bulk delete tools behave as designed on " . $dbName . "\n";
    $status = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . "\n");
    $status = 1;
} finally {
    if (is_file($uploadPath)) {
        @unlink($uploadPath);
    }
}

exit($status);
