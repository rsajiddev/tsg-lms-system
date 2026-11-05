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
 * drives the real bulk delete endpoints the admin workspace exposes - in a child process each,
 * because a controller ends with a redirect. It proves that a wrong phrase deletes nothing, that
 * the right phrase empties every dependent table in the order the foreign keys demand, that the
 * stored submission file is removed, that the matching student notifications retire, and that an
 * audit row records the scale of the deletion. The last rounds cover the student directory
 * purge (accounts and everything filed under them, while the delivery log keeps its rows), the
 * Clear delivery log button on the recent activity report, the student's Mark all read shortcut,
 * the chat clear on either side of a conversation and the desk's phrase-guarded delete of the
 * messages, the Clear recent activity card that
 * empties the automated updates and the log while leaving the board standing, and the announcement
 * board purge that retires every notification with its recipients and replies. It
 * only ever runs against a scratch database.
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
        '/app/Services/AdminAttention.php',
        '/app/Services/MailService.php',
        '/app/Services/NotificationService.php',
        '/app/Services/ChatService.php',
        '/app/Services/CourseDuration.php',
        '/app/Services/AccessPlan.php',
        '/app/Services/LessonMedia.php',
        '/app/Services/CourseGrading.php',
        '/app/Services/AttendanceService.php',
        // AuthMiddleware consults the session registry on every authorised request, so a harness that
        // calls a controller has to load it - and the registry files its sign-in reports through the
        // protection service.
        '/app/Services/LessonProtection.php',
        '/app/Services/SessionRegistry.php',
        '/app/Controllers/AssignmentController.php',
        '/app/Controllers/ChatController.php',
        '/app/Controllers/NotificationController.php',
        '/app/Controllers/QuizController.php',
        '/app/Controllers/CourseAdminController.php',
        '/app/Controllers/StudentManagementController.php',
    ] as $file
) {
    require BASE_PATH . $file;
}

use App\Core\Database;
use App\Core\Env;
use App\Core\Security;
use App\Controllers\AssignmentController;
use App\Controllers\ChatController;
use App\Controllers\CourseAdminController;
use App\Controllers\NotificationController;
use App\Controllers\QuizController;
use App\Controllers\StudentManagementController;
use App\Services\ChatService;
use App\Services\NotificationService;

Env::load(BASE_PATH . '/.env');
$options = getopt('', ['database::', 'run::', 'bad::', 'nid::', 'sid::']);
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
    'students' => 'DELETE ALL STUDENTS',
    // The delivery log has one row per message and no wording to protect, so its Clear delivery log
    // button asks for a browser confirmation instead of a typed phrase.
    'emaillog' => '',
    'announcements' => 'DELETE ALL ANNOUNCEMENTS',
    // The report on the recent activity page empties both generated tables at once.
    'activity' => 'CLEAR RECENT ACTIVITY',
    // The student's Mark all read and the two clears have no phrase; their unsafe variant is a forged
    // request. Clearing is one endpoint both sides of a conversation use, and only the desk's delete
    // of the messages asks for a typed phrase.
    'readall' => '',
    'clarchat' => '',
    'admclearchat' => '',
    'admdeletechat' => 'DELETE THIS CONVERSATION',
];

/* --------------------------------------------------------------------- child process */
$run = isset($options['run']) && is_string($options['run']) ? $options['run'] : '';
if ($run !== '') {
    $assert(isset($phrases[$run]), 'Unknown purge target ' . $run . '.');
    $find = $db->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
    $find->execute([$adminEmail]);
    $adminId = (int) $find->fetchColumn();
    $assert($adminId > 0, 'The seeded admin is missing; run the parent first.');
    if (in_array($run, ['readall', 'clarchat'], true)) {
        // These are the student's own actions, so this child signs in as whichever student the parent
        // left standing and the unsafe variant is a request without a valid token.
        $studentRow = $db->query("SELECT id,email FROM users WHERE role='student' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $studentSessionId = (int) ($studentRow['id'] ?? 0);
        $assert($studentSessionId > 0, 'No student account is left to act with; run the parent first.');
        $_SESSION = ['user' => ['id' => $studentSessionId, 'name' => 'Purge Smoke Reader', 'email' => (string) $studentRow['email'], 'role' => 'student']];
        $_POST = ['_csrf' => (($options['bad'] ?? '') === '1') ? 'not-a-token' : Security::csrfToken()];
        if ($run === 'clarchat') {
            // Clearing a conversation is addressed by the thread it belongs to.
            $_POST['thread_id'] = (string) (int) ($options['nid'] ?? 0);
        }
    } else {
        $_SESSION = ['user' => ['id' => $adminId, 'name' => 'Purge Smoke Admin', 'email' => $adminEmail, 'role' => 'admin']];
        $_POST = [
            '_csrf' => Security::csrfToken(),
            'irreversible' => '1',
            'course_id' => '0',
        ];
        if ($phrases[$run] === '') {
            // Nothing to mistype here: the unsafe variant is a request without a valid token.
            if (($options['bad'] ?? '') === '1') $_POST['_csrf'] = 'not-a-token';
        } else {
            $_POST['confirm_phrase'] = (($options['bad'] ?? '') === '1') ? 'DELETE EVERYTHING PLEASE' : $phrases[$run];
        }
        if ($run === 'admclearchat' || $run === 'admdeletechat') {
            // Both desk actions are addressed by the thread, and the thread belongs to a student whose
            // id the parent passes along.
            $_POST['thread_id'] = (string) (int) ($options['nid'] ?? 0);
            $_POST['student_id'] = (string) (int) ($options['sid'] ?? 0);
        }
    }
    $_SERVER['REQUEST_METHOD'] = 'POST';
    register_shutdown_function(static function (): void {
        echo 'FLASH|' . str_replace("\n", ' ', (string) ($_SESSION['_flash_error'] ?? $_SESSION['_flash'] ?? 'none')) . "\n";
    });
    try {
        match ($run) {
            'assignments' => (new AssignmentController())->purgeAll(),
            'quizzes' => (new QuizController())->purgeAll(),
            'lessons' => (new CourseAdminController())->purgeLessons(),
            'students' => (new StudentManagementController())->purgeAll(),
            'emaillog' => (new NotificationController())->clearEmailLog(),
            'activity' => (new NotificationController())->clearActivity(),
            'announcements' => (new NotificationController())->purgeAll(),
            'readall' => (new NotificationController())->markAllRead(),
            'clarchat' => (new ChatController())->clear(),
            'admclearchat' => (new ChatController())->clear(),
            'admdeletechat' => (new ChatController())->deleteThread(),
        };
    } catch (Throwable $exception) {
        echo 'THROWN|' . $exception->getMessage() . "\n";
    }
    exit(0);
}

/* ------------------------------------------------------------------------- test data */
$tables = [
    'course_grades', 'certificates', 'notification_reads',
    // A conversation holds its messages with a cascade and its thread with a plain foreign key, so
    // both tables are emptied before any account is removed.
    'chat_messages', 'chat_threads',
    'quiz_answers', 'quiz_attempt_options', 'quiz_attempt_questions', 'quiz_options',
    'quiz_retake_approvals', 'quiz_attempts', 'quiz_questions', 'quizzes',
    'assignment_submissions', 'assignments', 'lesson_progress', 'lessons', 'course_modules',
    // Both protection tables hang off a student, and one of them off a lesson, so they are emptied
    // before either parent - the same order the purges themselves keep.
    'lesson_view_events', 'user_sessions',
    'payments', 'attendance_records', 'email_otps', 'coding_challenge_attempts', 'login_events',
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
$db->exec('DELETE FROM users WHERE email IN (' . $db->quote($adminEmail) . ',' . $db->quote($studentEmail) . ",'purge-smoke-reader@example.test')");

$fileHash = bin2hex(random_bytes(24));
$fileRelative = 'smoke-purge/' . $fileHash . '.txt';
// A folder of the run's own, never one the site writes to: a directory this command-line account
// creates is unreadable and unwritable by the account serving the site (and the other way round),
// so borrowing a real upload folder made the test depend on who had touched it last.
$uploadDir = BASE_PATH . '/storage/uploads/private/smoke-purge';
if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0700, true)) {
    fwrite(STDERR, "FAIL: the scratch upload folder {$uploadDir} could not be created. Remove it or make this account able to write storage/uploads/private.\n");
    exit(1);
}
$uploadPath = $uploadDir . '/' . $fileHash . '.txt';
file_put_contents($uploadPath, 'Purge smoke submission payload.');
$assert(is_file($uploadPath), 'The fixture upload could not be written to ' . $uploadDir . '. Remove that folder or let this account write into it; a purge test that stores nothing proves nothing.');
// Set later, for the second round that clears the student accounts themselves.
$proofPath = '';

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

    // The two tables the video protection feature adds. One viewing report is deliberately filed with
    // no lesson behind it, because an account-level report is not about a recording and a lesson purge
    // has no claim on it.
    $viewEvent = $db->prepare('INSERT INTO lesson_view_events(student_id,lesson_id,event_type,ip_address,detail) VALUES(?,?,?,?,?)');
    $viewEvent->execute([$studentId, $lessonIds[0], 'download_blocked', '127.0.0.1', 'Save video as']);
    $viewEvent->execute([$studentId, $lessonIds[1], 'context_menu', '127.0.0.1', 'Right click on the player']);
    $viewEvent->execute([$studentId, null, 'session_cap', '127.0.0.1', '1 older session(s) signed out by this sign-in.']);
    $db->prepare('INSERT INTO user_sessions(user_id,session_hash,ip_address,created_at,last_seen_at) VALUES(?,?,?,?,UTC_TIMESTAMP())')
        ->execute([$studentId, hash('sha256', 'purge-smoke-session'), '127.0.0.1', gmdate('Y-m-d H:i:s')]);

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
    $assert($count('lesson_view_events') === 3 && $count('user_sessions') === 1, 'The protection fixtures did not load.');
    $badgeCheck = static fn(): int => (new NotificationService($db))->unreadByArea($studentId)['lessons'];
    $assert($badgeCheck() === 1, 'The lessons badge should count the seeded notification.');

    // The self command is the child process; exec() keeps the exit/redirect behaviour honest.
    $self = escapeshellarg(\PHP_BINARY);
    $script = escapeshellarg(__FILE__);
    $database = escapeshellarg('--database=' . $dbName);
    $invoke = static function (string $target, bool $bad = false, int $notificationId = 0, int $studentId = 0) use ($self, $script, $database): string {
        $command = $self . ' ' . $script . ' ' . $database . ' --run=' . escapeshellarg($target)
            . ($bad ? ' --bad=1' : '') . ($notificationId > 0 ? ' --nid=' . $notificationId : '')
            . ($studentId > 0 ? ' --sid=' . $studentId : '') . ' 2>&1';
        $output = (string) shell_exec($command);
        // The child writes and deletes real files behind this process's back, and PHP replays
        // cached stat() results for paths already looked at - a stale is_file() entry would make
        // the run believe a removed upload is still on disk.
        clearstatcache();
        return $output;
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
    // A removed lesson takes the reports filed against it. The account-level row has no lesson behind
    // it, so it is not the lesson purge's to take - and the audit row has to say how many went.
    $assert($count('lesson_view_events') === 1, 'The lesson purge left a viewing report of its own lessons behind, or took one that was not about a lesson: ' . $count('lesson_view_events') . ' row(s) left.');
    $assert((int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE action='lesson.bulk_deleted' AND JSON_EXTRACT(metadata,'$.view_events_deleted')=2")->fetchColumn() === 1,
        'The lesson purge audit row does not count the two viewing reports it removed.');
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

    /* ------------------------------------------------- the student directory purge */
    // A second round of student-owned records, because the content purges above already cleared
    // the lesson, quiz and assignment rows an account would otherwise be filed under.
    $proofHash = bin2hex(random_bytes(24));
    $proofPath = $uploadDir . '/' . $proofHash . '.txt';
    file_put_contents($proofPath, 'Work that has to disappear with the account.');
    $db->prepare("INSERT INTO assignments(course_id,title,instructions,max_marks,status,created_by) VALUES(?,?,'Brief',100,'published',?)")
        ->execute([$courseId, 'Post-purge assignment', $adminId]);
    $lateAssignmentId = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO assignment_submissions(assignment_id,student_id,submission_text,file_path,status,submitted_at)
        VALUES(?,?,?,?,'submitted',UTC_TIMESTAMP())")->execute([$lateAssignmentId, $studentId, 'Uploaded work', 'smoke-purge/' . $proofHash . '.txt']);
    $db->prepare("INSERT INTO payments(student_id,course_id,amount,currency,transaction_reference,status,reviewed_by,reviewed_at)
        VALUES(?,?,25000,'PKR','PURGE-SMOKE-1','approved',?,UTC_TIMESTAMP())")->execute([$studentId, $courseId, $adminId]);
    $paymentId = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO certificates(student_id,course_id,status) VALUES(?,?,'requested')")->execute([$studentId, $courseId]);
    $db->prepare("INSERT INTO course_grades(student_id,course_id,overall_pct,letter_grade) VALUES(?,?,92.50,'A+')
        ON DUPLICATE KEY UPDATE overall_pct=92.50, letter_grade='A+'")->execute([$studentId, $courseId]);
    $db->prepare("INSERT INTO attendance_records(student_id,attendance_date,status,first_login_at,mark_deadline_at)
        VALUES(?,?,'present',UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([$studentId, date('Y-m-d')]);
    $db->prepare("INSERT INTO email_otps(user_id,purpose,otp_hash,expires_at) VALUES(?, 'verify_email','not-a-real-hash',UTC_TIMESTAMP() + INTERVAL 10 MINUTE)")->execute([$studentId]);
    $db->prepare("INSERT INTO login_events(user_id,event_type,ip_address) VALUES(?, 'success','127.0.0.1')")->execute([$studentId]);
    $db->prepare("INSERT INTO email_logs(template_key,recipient_email,subject,status,related_user_id)
        VALUES('registration.student',?,'Welcome to the academy','sent',?)")->execute([$studentEmail, $studentId]);
    $db->prepare("INSERT INTO notifications(created_by,category,title,body,audience,source_type,source_id,published_at)
        VALUES(?,'payment','Payment approval requested','Body','selected_students','payment',?,UTC_TIMESTAMP())")->execute([$adminId, $paymentId]);
    $paymentNotificationId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO notification_recipients(notification_id,student_id,read_at) VALUES(?,?,NULL)')->execute([$paymentNotificationId, $studentId]);
    $db->prepare("INSERT INTO notifications(created_by,title,body,audience,category,published_at)
        VALUES(?,?,?,'all_students','announcement',UTC_TIMESTAMP())")->execute([$adminId, 'Kept announcement', 'Body']);
    // The account is signed in while it is removed, and one viewing report is still on file from the
    // lesson round above - both belong to the account and leave with it.
    $db->prepare('INSERT INTO user_sessions(user_id,session_hash,ip_address,created_at,last_seen_at) VALUES(?,?,?,?,UTC_TIMESTAMP())')
        ->execute([$studentId, hash('sha256', 'purge-smoke-session-two'), '127.0.0.1', gmdate('Y-m-d H:i:s')]);
    $db->prepare("INSERT INTO lesson_view_events(student_id,lesson_id,event_type,ip_address,detail) VALUES(?,NULL,'seek_ahead','127.0.0.1','A head start at the last heartbeat')")->execute([$studentId]);

    $assert($count('payments') === 1 && $count('certificates') === 1 && $count('email_logs') === 1 && $count('attendance_records') === 1,
        'The student fixture round did not load.');

    $output = $invoke('students', true);
    $assert(str_contains($output, 'FLASH|') && str_contains($output, 'confirmation phrase'), 'A wrong phrase was not rejected with a readable reason: ' . trim($output));
    $assert($count('users') === 2 && $count('payments') === 1, 'A wrong phrase deleted student data anyway.');

    $output = $invoke('students');
    $assert(str_contains($output, 'FLASH|Deleted 1 student account(s)'), 'Student purge reported: ' . trim($output));
    $assert(str_contains($output, 'sessions and certificates'), 'The student purge does not say it took the account\'s sessions with it: ' . trim($output));
    $assert(str_contains($output, '1 uploaded file(s) were removed'), 'Student purge did not report the removed submission file: ' . trim($output));
    $assert(!is_file($proofPath), 'The stored submission file survived the student purge.');
    $assert($count('users') === 1 && (string) $db->query('SELECT role FROM users')->fetchColumn() === 'admin',
        'The student purge must leave the admin account and remove every student.');
    foreach (['assignment_submissions', 'attendance_records', 'certificates', 'course_grades', 'email_otps', 'login_events',
        'notification_recipients', 'payments', 'quiz_retake_approvals', 'student_course_access',
        // A live session row is the one record that would otherwise outlive the account it identifies
        // and keep a hash of nobody's sign-in on file.
        'user_sessions', 'lesson_view_events'] as $table) {
        $assert($count($table) === 0, $table . ' still holds rows after the student purge.');
    }
    // Course content belongs to the academy, not to the removed accounts, and stays.
    $assert($count('courses') === 1 && $count('assignments') === 1, 'The student purge deleted course content.');
    $assert((int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE actor_user_id=" . $studentId)->fetchColumn() === 0,
        'Audit rows of a deleted account survived the purge.');
    $assert((int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE action='student.bulk_deleted'")->fetchColumn() === 1,
        'No audit row for the student purge.');
    $logRow = $db->query('SELECT related_user_id FROM email_logs')->fetch();
    $assert($logRow !== false && $logRow['related_user_id'] === null,
        'The delivery log has to keep its rows and drop only the link to the deleted account.');
    $assert((int) $db->query("SELECT COUNT(*) FROM notifications WHERE source_type='payment'")->fetchColumn() === 0,
        'A notification still points at a payment that no longer exists.');
    $assert((int) $db->query("SELECT COUNT(*) FROM notifications WHERE title='Kept announcement'")->fetchColumn() === 1,
        'The student purge deleted an announcement it had no claim on.');

    $output = $invoke('students');
    $assert(str_contains($output, 'FLASH|There are no student accounts to delete.'), 'An empty student purge reported: ' . trim($output));

    /* --------------------------------------------------- the recent activity report */
    $output = $invoke('emaillog', true);
    $assert(str_contains($output, 'FLASH|Your session expired'), 'The delivery log clear accepted a request without a valid token: ' . trim($output));
    $assert($count('email_logs') === 1, 'An unauthenticated request emptied the delivery log.');

    $output = $invoke('emaillog');
    $assert(str_contains($output, 'FLASH|Cleared 1 e-mail delivery record(s)'), 'Delivery log clear reported: ' . trim($output));
    $assert($count('email_logs') === 0, 'email_logs still holds rows after Clear activity.');
    $assert((int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE action='email_log.cleared'")->fetchColumn() === 1,
        'No audit row for the delivery log clear.');

    $output = $invoke('emaillog');
    $assert(str_contains($output, 'FLASH|The delivery log was already empty.'), 'An empty delivery log reported: ' . trim($output));

    /* ------------------------------------------------ the student's Mark all read */
    // The student purge above removed the fixture account, so a fresh reader is created: unread
    // notices refiled under the surviving announcement are exactly what the inbox shortcut is for.
    // A forged request has to leave that state alone.
    $readerEmail = 'purge-smoke-reader@example.test';
    $db->prepare("INSERT INTO users(full_name,email,password_hash,role,status,email_verified_at) VALUES('Purge Smoke Reader',?,'unusable-hash','student','active',UTC_TIMESTAMP())")
        ->execute([$readerEmail]);
    $readerId = (int) $db->lastInsertId();
    $keptId = (int) $db->query("SELECT id FROM notifications WHERE title='Kept announcement'")->fetchColumn();
    $db->prepare('INSERT INTO notification_recipients(notification_id,student_id,read_at) VALUES(?,?,NULL)')
        ->execute([$keptId, $readerId]);
    $unread = static fn(): int => (int) $db->query('SELECT COUNT(*) FROM notification_recipients WHERE read_at IS NULL')->fetchColumn();
    $output = $invoke('readall', true);
    $assert(str_contains($output, 'FLASH|Your session expired'), 'The Mark all read shortcut accepted a request without a valid token: ' . trim($output));
    $assert($unread() === 1, 'An unauthenticated request marked the inbox read anyway.');

    $output = $invoke('readall');
    $assert(str_contains($output, 'FLASH|Marked 1 notification(s) as read.'), 'Mark all read reported: ' . trim($output));
    $assert($unread() === 0, 'A badge counter survived Mark all read.');
    $assert((string) $db->query('SELECT read_at FROM notification_recipients')->fetchColumn() !== '',
        'The recipient row was stamped but the counter says otherwise; the badge would keep counting it.');

    /* -------------------------------------------------- the two sides clearing one chat */
    // A real conversation, in the tables the chat itself uses: the reader's question and the Academy's
    // answer. Clearing is measured from both ends because its whole promise is that one side's list
    // empties while the other side keeps reading exactly the same rows.
    $chats = new ChatService($db);
    $threadId = $chats->studentThread($readerId);
    // Messages are written a full minute apart and the marks are moved to whole minutes, so the reopen
    // below measures the rule rather than the second a request happened to land in.
    $messageInsert = $db->prepare('INSERT INTO chat_messages(thread_id,sender_id,message,created_at) VALUES(?,?,?,UTC_TIMESTAMP() - INTERVAL ? MINUTE)');
    $messageInsert->execute([$threadId, $readerId, 'Is the replay available?', 30]);
    $messageInsert->execute([$threadId, $adminId, 'Yes, it is posted under the lesson.', 20]);
    $assert(count($chats->messages($threadId, 'student')) === 2 && count($chats->messages($threadId, 'admin')) === 2,
        'Both sides read both seeded messages.');
    $assert($chats->unreadCount($readerId) === 1 && $chats->waitingCount() === 0,
        'The Academy answer is unread for the student, and an answered thread is not waiting for the desk.');
    $db->prepare("INSERT INTO email_logs(template_key,recipient_email,subject,status,related_user_id)
        VALUES('notification.lesson',?,'A new lesson is open','sent',?)")->execute([$readerEmail, $readerId]);

    $output = $invoke('clarchat', true, $threadId);
    $assert(str_contains($output, 'FLASH|Your session expired'), 'The chat clear accepted a request without a valid token: ' . trim($output));
    $assert(count($chats->messages($threadId, 'student')) === 2, 'An unauthenticated request cleared the chat anyway.');

    $output = $invoke('clarchat', false, $threadId);
    $assert(str_contains($output, 'FLASH|Cleared for you. 2 messages are hidden on this side only, the Academy still reads them'),
        'The student clear reported: ' . trim($output));
    $assert($chats->messages($threadId, 'student') === [], 'The student still reads what they cleared.');
    $assert(count($chats->messages($threadId, 'admin')) === 2 && count($chats->adminThreads()) === 1,
        'The student clear reached into the Academy\'s copy.');
    $assert($chats->studentThreads($readerId) === [], 'A cleared chat stayed on the student list.');
    $assert($chats->unreadCount($readerId) === 0, 'The Chats badge outlived the list it counts.');
    $assert($count('chat_messages') === 2, 'Clearing deleted message rows instead of hiding them.');

    /* ------------------------------------------------------ the same clear from the desk */
    // One endpoint, two sides: what the desk loses is the desk's own view, and the student keeps the
    // conversation exactly where they left it.
    $output = $invoke('admclearchat', false, $threadId, $readerId);
    $assert(str_contains($output, 'FLASH|Cleared for you. 2 messages are hidden on this side only, the student still reads them'),
        'The desk clear reported: ' . trim($output));
    $assert($chats->adminThreads() === [] && $chats->messages($threadId, 'admin') === [], 'The desk still lists what it cleared.');
    $assert($chats->adminThreadCount() === 1, 'Clearing the desk deleted the thread itself, so the student could not write again.');
    $assert($count('chat_messages') === 2 && $chats->messages($threadId, 'student') === [],
        'The desk clear moved the student\'s own view of the conversation.');
    $assert((int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE action='chat.cleared'")->fetchColumn() === 1,
        'The desk clear left no audit row.');

    // A second clear has nothing left to hide, and has to say so instead of reporting a number.
    $output = $invoke('admclearchat', false, $threadId, $readerId);
    $assert(str_contains($output, 'FLASH|This chat has nothing to clear.'), 'An already cleared chat reported: ' . trim($output));

    // The rule that makes a clear safe: a message newer than the mark brings the thread back on its own.
    $db->exec('UPDATE chat_threads SET student_cleared_at = UTC_TIMESTAMP() - INTERVAL 10 MINUTE, admin_cleared_at = UTC_TIMESTAMP() - INTERVAL 10 MINUTE WHERE id=' . $threadId);
    $messageInsert->execute([$threadId, $readerId, 'One more question, please.', 5]);
    $backOnDesk = $chats->adminThreads();
    $assert(count($backOnDesk) === 1 && (int) ($backOnDesk[0]['id'] ?? 0) === $threadId && ChatService::awaitsReply($backOnDesk[0]),
        'A new message from the student puts the conversation back on the desk, waiting for an answer.',
        'The desk read ' . json_encode(array_map(static fn(array $thread): int => (int) $thread['id'], $backOnDesk)) . ' after the student wrote again.');
    $assert(count($chats->messages($threadId, 'student')) === 1 && $chats->waitingCount() === 1,
        'The reopened chat came back holding only the new message.');

    /* -------------------------------------------------- the desk's permanent delete */
    // The one action that is not offered to a student, so it carries the same phrase guard as every
    // other bulk delete here - and it stops at the messages, because the thread is where the student's
    // next question has to land.
    $output = $invoke('admdeletechat', true, $threadId, $readerId);
    $assert(str_contains($output, 'FLASH|') && str_contains($output, 'confirmation phrase'), 'A wrong phrase was not rejected with a readable reason: ' . trim($output));
    $assert($count('chat_messages') === 3, 'A wrong phrase deleted messages anyway.');

    $output = $invoke('admdeletechat', false, $threadId, $readerId);
    $assert(str_contains($output, 'FLASH|3 messages were deleted from this conversation for both sides.'),
        'The conversation delete reported: ' . trim($output));
    $assert($count('chat_messages') === 0 && $chats->messages($threadId, 'admin') === [] && $chats->messages($threadId, 'student') === [],
        'The delete left a message on one side of the conversation.');
    $assert($chats->adminThreadCount() === 1 && $chats->studentThread($readerId) === $threadId,
        'The thread row has to outlive its messages, or the student would be sent to a dead link.');
    $assert((int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE action='chat.messages_deleted'")->fetchColumn() === 1,
        'No audit row for the conversation delete.');

    $output = $invoke('admdeletechat', false, $threadId, $readerId);
    $assert(str_contains($output, 'FLASH|This conversation had no messages left to delete.'),
        'An empty conversation delete reported: ' . trim($output));

    /* ---------------------------------------------------- clearing the activity report */
    // One automated update of the report's own, with a recipient row and a private reply under it.
    // The chat round above no longer seeds notifications, and a report round should not depend on
    // another section's fixtures anyway: it has to bring its own generated activity.
    $db->prepare("INSERT INTO notifications(created_by,category,title,body,audience,published_at)
        VALUES(?,'lesson','Lesson replay opened','A new lesson is open for you.','selected_students',UTC_TIMESTAMP())")->execute([$adminId]);
    $automatedId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO notification_recipients(notification_id,student_id,read_at) VALUES(?,?,NULL)')
        ->execute([$automatedId, $readerId]);
    $db->prepare('INSERT INTO notification_replies(notification_id,student_id,recipient_student_id,message) VALUES(?,?,?,?)')
        ->execute([$automatedId, $readerId, $adminId, 'Is the replay on the lesson page?']);
    // Both generated tables go together here, and nothing an admin published by hand may go with
    // them - so the numbers are read back from the database rather than assumed, and the surviving
    // announcement is checked by name.
    $automatedSql = "category <> 'announcement' AND category <> '' AND category IS NOT NULL";
    $expectedAutomated = (int) $db->query('SELECT COUNT(*) FROM notifications WHERE ' . $automatedSql)->fetchColumn();
    $expectedRecipients = (int) $db->query('SELECT COUNT(*) FROM notification_recipients nr JOIN notifications n ON n.id = nr.notification_id WHERE n.' . $automatedSql)->fetchColumn();
    $expectedReplies = (int) $db->query('SELECT COUNT(*) FROM notification_replies r JOIN notifications n ON n.id = r.notification_id WHERE n.' . $automatedSql)->fetchColumn();
    $expectedEmails = $count('email_logs');
    $expectedTotal = $count('notifications');
    $expectedFlash = sprintf(
        'Cleared %d automated update(s) with %d recipient record(s), %d private reply/replies and %d e-mail delivery record(s). Published announcements were left on the board.',
        $expectedAutomated, $expectedRecipients, $expectedReplies, $expectedEmails
    );

    $output = $invoke('activity', true);
    $assert(str_contains($output, 'FLASH|') && str_contains($output, 'confirmation phrase'), 'A wrong phrase was not rejected with a readable reason: ' . trim($output));
    $assert($count('notifications') === $expectedTotal, 'A wrong phrase deleted automated updates anyway.');

    $output = $invoke('activity');
    $assert(str_contains($output, 'FLASH|' . $expectedFlash), 'Clear recent activity reported: ' . trim($output));
    $assert((int) $db->query('SELECT COUNT(*) FROM notifications WHERE ' . $automatedSql)->fetchColumn() === 0,
        'Automated updates survived the activity clear.');
    $assert($count('email_logs') === 0 && $count('notification_replies') === 0, 'The activity clear left the log or the replies behind.');
    $assert((int) $db->query("SELECT COUNT(*) FROM notifications WHERE title='Kept announcement'")->fetchColumn() === 1,
        'The activity clear deleted an announcement it has no claim on.');
    // Clearing the activity report has no claim on a private conversation: the thread above stays
    // standing with its messages already deleted by the desk's own action.
    $assert($count('chat_threads') === 1 && $count('chat_messages') === 0,
        'The activity clear reached into the chat tables it has no claim on.');
    $assert((int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE action='activity.cleared'")->fetchColumn() === 1,
        'No audit row for the activity clear.');

    $output = $invoke('activity');
    $assert(str_contains($output, 'FLASH|Recent activity was already empty.'), 'An empty activity report said: ' . trim($output));

    /* ----------------------------------------------------- the announcement board */
    // A private reply filed under the surviving announcement, so the board purge is proven to take
    // the child rows with it instead of stranding a foreign key.
    $db->prepare('INSERT INTO notification_replies(notification_id,student_id,recipient_student_id,message) VALUES(?,?,?,?)')
        ->execute([$keptId, $readerId, $readerId, 'A question about the announcement.']);

    $output = $invoke('announcements', true);
    $assert(str_contains($output, 'FLASH|') && str_contains($output, 'confirmation phrase'), 'A wrong phrase was not rejected with a readable reason: ' . trim($output));
    $assert($count('notifications') === 1 && $count('notification_replies') === 1, 'A wrong phrase deleted announcements anyway.');

    $output = $invoke('announcements');
    $assert(str_contains($output, 'FLASH|Deleted 1 announcement(s) with 1 recipient record(s) and 1 private reply'), 'Announcement board purge reported: ' . trim($output));
    foreach (['notifications', 'notification_recipients', 'notification_replies'] as $table) {
        $assert($count($table) === 0, $table . ' still holds rows after the announcement board purge.');
    }
    $assert((int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE action='announcement.bulk_deleted'")->fetchColumn() === 1,
        'No audit row for the announcement board purge.');

    $output = $invoke('announcements');
    $assert(str_contains($output, 'FLASH|The announcement board is already empty.'), 'An empty board reported: ' . trim($output));

    echo "purge tools: wrong phrase blocked, workspaces cleared, accounts purged with files, notifications and audits handled, delivery log cleared, inbox marked read, chats cleared on each side without losing a message and deleted by the desk behind its phrase, activity report cleared around the board, announcement board purged\n";
    echo "PASS: bulk delete tools behave as designed on " . $dbName . "\n";
    $status = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . "\n");
    $status = 1;
} finally {
    foreach ([$uploadPath, $proofPath] as $scratchFile) {
        if ($scratchFile !== '' && is_file($scratchFile)) {
            @unlink($scratchFile);
        }
    }
    // The scratch folder always goes, so the next run finds it exactly as empty as this one made it.
    if (is_dir($uploadDir)) {
        @rmdir($uploadDir);
    }
}

exit($status);
