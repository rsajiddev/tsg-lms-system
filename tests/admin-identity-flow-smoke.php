<?php
declare(strict_types=1);

/**
 * Admin identity flow smoke test.
 *
 * Covers the two writes an admin makes on their own settings page: the scheme every new Student
 * ID is minted from (with the optional re-issue that renumbers the students already on the roll
 * and carries their certificates along), and renaming the admin's own ID. Both are real POST
 * handlers, so they are executed in a child process - each one ends in a redirect and an exit,
 * which would take the parent down with it - and every claim is then read back out of the
 * database rather than out of the HTML. A wrong CSRF token, an ID that is already taken and a
 * student trying an admin endpoint must all leave the database exactly as they found it.
 *
 *   /Applications/XAMPP/xamppfiles/bin/php tests/admin-identity-flow-smoke.php --database=tsg_identity_check_tmp
 *
 * Add --drop=1 to remove the scratch database afterwards.
 */

namespace {
    define('BASE_PATH', dirname(__DIR__));
    foreach (
        [
            '/app/Core/Env.php',
            '/app/Core/View.php',
            '/app/Core/Security.php',
            '/app/Core/Database.php',
            '/app/Core/AuthMiddleware.php',
            '/app/Core/RateLimiter.php',
            '/app/Services/EmailLayout.php',
            '/app/Services/EmailMessages.php',
            '/app/Services/AccountIdentity.php',
            '/app/Services/AdminAttention.php',
            '/app/Services/MailService.php',
            '/app/Services/NotificationService.php',
            // AuthService and AccountController close the account's other devices on a password change,
            // so a harness that calls them has to load the registry - and the registry files its
            // sign-in reports through the protection service.
            '/app/Services/LessonProtection.php',
            '/app/Services/SessionRegistry.php',
            '/app/Services/AuthService.php',
            '/app/Controllers/AccountController.php',
        ] as $file
    ) {
        require BASE_PATH . $file;
    }

    use App\Core\Database;
    use App\Core\Env;
    use App\Core\Security;
    use App\Controllers\AccountController;
    use App\Services\AccountIdentity;

    Env::load(BASE_PATH . '/.env');
    $options = getopt('', ['database::', 'drop::', 'call::', 'csrf::', 'role::', 'reference::']);
    if (($options['database'] ?? '') !== '') {
        putenv('DB_NAME=' . $options['database']);
    }
    $dbName = (string) (getenv('DB_NAME') ?: '');
    $adminEmail = 'identity-smoke-admin@example.test';
    $studentEmail = 'identity-smoke-student@example.test';
    $secondStudentEmail = 'identity-smoke-second@example.test';

    /* ------------------------------------------------------------------------ child mode */
    // One POST per process. Whatever the handler put in the session is printed on exit so the
    // parent can assert the message the admin would have been shown.
    $call = (string) ($options['call'] ?? '');
    if ($call !== '') {
        $db = Database::connection();
        $role = ($options['role'] ?? 'admin') === 'student' ? 'student' : 'admin';
        $who = ($options['role'] ?? 'admin') === 'student' ? $studentEmail : $adminEmail;
        $stmt = $db->prepare('SELECT id, full_name, email, role FROM lms_users WHERE email = ? LIMIT 1');
        $stmt->execute([$who]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        // The middleware calls session_regenerate_id() when it turns an intruder away, and that
        // wants a real session behind it - without one the run is still correct, just noisy.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = ['user' => ['id' => (int) ($account['id'] ?? 0), 'name' => (string) ($account['full_name'] ?? ''), 'email' => (string) ($account['email'] ?? ''), 'role' => (string) ($account['role'] ?? 'student')]];
        $_POST = ['_csrf' => ($options['csrf'] ?? '') === 'bad' ? 'not-a-real-token' : Security::csrfToken()];
        if ($call === 'save-scheme') {
            $_POST += ['student_id_prefix' => 'tsg-post', 'student_id_start' => '5', 'apply_to_existing' => '1'];
        } elseif ($call === 'save-admin-id') {
            $_POST += ['account_reference' => (string) ($options['reference'] ?? 'tsg-adm-post')];
        }
        register_shutdown_function(static function () use ($call): void {
            echo $call . '|' . json_encode([
                'flash' => $_SESSION['_flash'] ?? null,
                'error' => $_SESSION['_flash_error'] ?? null,
            ]);
        });
        $controller = new AccountController();
        if ($call === 'save-scheme') $controller->saveIdentityScheme();
        if ($call === 'save-admin-id') $controller->saveAdminId();
        exit(0);
    }

    /* ------------------------------------------------------------------------ parent mode */
    $failures = [];
    $assert = static function (bool $condition, string $passMessage, string $failMessage) use (&$failures): void {
        if ($condition) {
            echo '  ok ' . $passMessage . "\n";
            return;
        }
        $failures[] = $failMessage;
        echo '  FAIL ' . $failMessage . "\n";
    };

    // The child processes this suite spawns re-number real accounts, so the scratch guard cannot
    // be a recorded failure — it has to end the run before anything connects.
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

    $db = Database::connection();

    echo "\n-- the wiring the browser depends on\n";
    // The form actions live in a template and the routes in the front controller, so a mismatch
    // between them is invisible until somebody presses the button. Checked here instead.
    $routes = (string) file_get_contents(BASE_PATH . '/public/index.php');
    $assert(str_contains($routes, "\$router->post('/admin/account/identity-scheme', [\$account, 'saveIdentityScheme'])"),
        'POST /admin/account/identity-scheme is routed to AccountController::saveIdentityScheme.',
        'The scheme form posts to a path the router does not know.');
    $assert(str_contains($routes, "\$router->post('/admin/account/id', [\$account, 'saveAdminId'])"),
        'POST /admin/account/id is routed to AccountController::saveAdminId.',
        'The Admin ID form posts to a path the router does not know.');

    // Fixtures are replaced rather than accumulated, so a rerun sees the same starting point.
    $db->exec('DELETE FROM lms_audit_logs WHERE action LIKE \'account.%\'');
    foreach ([$adminEmail, $studentEmail, $secondStudentEmail] as $email) {
        $stmt = $db->prepare('SELECT id FROM lms_users WHERE email = ?');
        $stmt->execute([$email]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $leftover) {
            $db->exec('DELETE FROM lms_certificates WHERE student_id = ' . (int) $leftover);
            // A run that died mid-way can leave the scheme settings pointing at a fixture account.
            $db->exec('UPDATE lms_app_settings SET updated_by = NULL WHERE updated_by = ' . (int) $leftover);
        }
        $db->exec('DELETE FROM lms_users WHERE email = ' . $db->quote((string) $email));
    }
    $insert = $db->prepare("INSERT INTO lms_users(full_name,email,password_hash,role,status,email_verified_at) VALUES(?,?, 'unusable-hash',?,'active',UTC_TIMESTAMP())");
    $insert->execute(['Identity Smoke Admin', $adminEmail, 'admin']);
    $adminId = (int) $db->lastInsertId();
    $insert->execute(['Identity Smoke Student', $studentEmail, 'student']);
    $studentId = (int) $db->lastInsertId();
    $insert->execute(['Identity Smoke Second', $secondStudentEmail, 'student']);
    $secondStudentId = (int) $db->lastInsertId();
    $db->exec("INSERT INTO lms_courses(title,slug,visibility,created_by) VALUES('Identity Smoke Course','identity-smoke-course','published'," . $adminId . ")");
    $courseId = (int) $db->lastInsertId();
    $certificate = $db->prepare("INSERT INTO lms_certificates(certificate_id,student_id,course_id,status,issued_at) VALUES(?,?,?,'issued',UTC_TIMESTAMP())");
    $certificate->execute(['TSG-OLD-001', $studentId, $courseId]);
    $certificate->execute(['TSG-OLD-002', $secondStudentId, $courseId]);

    $run = static function (string $argument): array {
        $output = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --database=' . escapeshellarg((string) getenv('DB_NAME')) . ' ' . $argument);
        $pipe = strpos($output, '|');
        if ($pipe === false) return ['call' => $output, 'flash' => null, 'error' => null];
        $body = json_decode(substr($output, $pipe + 1), true);
        return ['call' => substr($output, 0, $pipe), 'flash' => $body['flash'] ?? null, 'error' => $body['error'] ?? null];
    };
    $stored = static function (int $id) use ($db): string {
        return (string) $db->query('SELECT account_reference FROM lms_users WHERE id = ' . $id)->fetchColumn();
    };
    $certificateId = static function (int $studentId) use ($db): string {
        return (string) $db->query('SELECT certificate_id FROM lms_certificates WHERE student_id = ' . $studentId)->fetchColumn();
    };
    $setting = static function (string $key) use ($db): string {
        return (string) $db->query('SELECT setting_value FROM lms_app_settings WHERE setting_key = ' . $db->quote($key))->fetchColumn();
    };

    echo "\n-- saving the scheme renumbers the roll\n";
    $result = $run('--call=save-scheme');
    $scheme = AccountIdentity::scheme($db);
    $assert($result['call'] === 'save-scheme' && $result['error'] === null, 'The scheme form was accepted and answered with a success flash.',
        'Saving the scheme failed: ' . json_encode($result));
    $assert($scheme['prefix'] === 'TSG-POST', 'The prefix was saved upper-cased as TSG-POST.', 'The saved prefix is ' . $scheme['prefix'] . '.');
    // The two fixture students are the newest on the roll, so they land at the end of the run.
    $total = (int) $db->query("SELECT COUNT(*) FROM lms_users WHERE role = 'student'")->fetchColumn();
    $assert($stored($studentId) === AccountIdentity::format('TSG-POST', 5 + $total - 2) && $stored($secondStudentId) === AccountIdentity::format('TSG-POST', 5 + $total - 1),
        'Both students were renumbered from the number the admin typed, oldest first.',
        'Reissuing left them at ' . $stored($studentId) . ' and ' . $stored($secondStudentId) . '.');
    $assert($certificateId($studentId) === $stored($studentId) && $certificateId($secondStudentId) === $stored($secondStudentId),
        'Each issued certificate carries its owner\'s new number, so the public page still finds it.',
        'The certificates were left behind at ' . $certificateId($studentId) . ' and ' . $certificateId($secondStudentId) . '.');
    $assert((int) $setting('student_id_next_number') === 5 + $total, 'The counter now sits past the renumbered roll at ' . $setting('student_id_next_number') . '.',
        'The counter is at ' . $setting('student_id_next_number') . ' instead of ' . (5 + $total) . '.');
    $assert(stripos((string) $result['flash'], 're-numbered') !== false, 'The flash reports how many students were re-numbered.',
        'The success flash does not mention the re-numbering: ' . json_encode($result['flash']));
    $assert((int) $db->query("SELECT COUNT(*) FROM lms_audit_logs WHERE action = 'account.identity_scheme_updated'")->fetchColumn() === 1,
        'The audit trail records who changed the scheme.', 'No audit row was written for the scheme change.');

    echo "\n-- a stale form does not write anything\n";
    $before = [$setting('student_id_prefix'), $stored($studentId), $scheme['next']];
    $result = $run('--call=save-scheme --csrf=bad');
    $assert($result['error'] !== null && stripos((string) $result['error'], 'expired') !== false,
        'A request without a valid CSRF token is refused with the session-expired notice.', 'A bad CSRF token was accepted: ' . json_encode($result));
    $assert($setting('student_id_prefix') === $before[0] && $stored($studentId) === $before[1] && AccountIdentity::scheme($db)['next'] === $before[2],
        'Nothing moved: the prefix, the student and the counter are all exactly as they were.',
        'A refused request still changed the database.');

    echo "\n-- an admin renaming their own ID\n";
    $result = $run('--call=save-admin-id');
    $assert($stored($adminId) === 'TSG-ADM-POST', 'The admin ID is stored upper-cased as ' . $stored($adminId) . '.',
        'The admin ID came out as ' . $stored($adminId) . '.');
    $assert($result['error'] === null && stripos((string) $result['flash'], 'TSG-ADM-POST') !== false,
        'The confirmation flash names the new ID back to the admin.', 'No usable confirmation: ' . json_encode($result));
    $assert((int) $db->query("SELECT COUNT(*) FROM lms_audit_logs WHERE action = 'account.id_updated'")->fetchColumn() === 1,
        'The ID change is audited with the account it belongs to.', 'The admin ID change was not audited.');
    $result = $run('--call=save-admin-id --reference=' . $stored($studentId));
    $assert($stored($adminId) === 'TSG-ADM-POST' && stripos((string) $result['error'], 'unique') !== false,
        'An ID another account already answers to is refused and the old one stays.',
        'Two accounts now share ' . $stored($adminId) . ': ' . json_encode($result));
    $result = $run("--call=save-admin-id --reference='not valid'");
    $assert($stored($adminId) === 'TSG-ADM-POST' && $result['error'] !== null,
        'An ID with characters a certificate cannot carry is refused before it is written.',
        'A malformed ID was stored: ' . json_encode($result));

    echo "\n-- a student cannot reach either write\n";
    $untouched = [$stored($studentId), $certificateId($studentId)];
    $result = $run('--call=save-scheme --role=student');
    $assert($result['flash'] === null && $result['error'] === null,
        'The student is bounced by the role guard, which answers before any handler code runs.',
        'A student posting to the scheme endpoint got a message back: ' . json_encode($result));
    $assert($setting('student_id_prefix') === 'TSG-POST' && $stored($studentId) === $untouched[0] && $certificateId($studentId) === $untouched[1],
        'A student posting to the scheme endpoint changes no setting, account or certificate.', 'A student was able to save the admin scheme.');
    $result = $run('--call=save-admin-id --role=student --reference=TSG-STU-STOLE');
    $assert($stored($studentId) === $untouched[0] && $untouched[0] !== 'TSG-STU-STOLE',
        'A student posting to the ID endpoint does not even rename themselves.',
        'A student rewrote their own ID through the admin endpoint.');

    echo "\n";
    if ($failures === []) {
        $db->exec('DELETE FROM lms_certificates WHERE course_id = ' . $courseId);
        $db->exec('DELETE FROM lms_courses WHERE id = ' . $courseId);
        $db->exec('DELETE FROM lms_audit_logs WHERE action LIKE \'account.%\'');
        // The scheme settings remember which admin saved them, and that FK is the last thing
        // holding the fixture accounts in place.
        $db->exec('UPDATE lms_app_settings SET updated_by = NULL WHERE updated_by IN (' . $adminId . ',' . $studentId . ',' . $secondStudentId . ')');
        $db->exec('DELETE FROM lms_users WHERE id IN (' . $adminId . ',' . $studentId . ',' . $secondStudentId . ')');
        echo "fixtures cleared\n";
    }
    if (($options['drop'] ?? '0') === '1') {
        shell_exec($mysql . ' ' . $credentials . ' -e ' . escapeshellarg('DROP DATABASE IF EXISTS `' . $dbName . '`'));
        echo "dropped " . $dbName . "\n";
    }

    if ($failures !== []) {
        fwrite(STDERR, 'FAIL: ' . count($failures) . " problem(s)\n - " . implode("\n - ", $failures) . "\n");
        exit(1);
    }
    echo "PASS: the admin identity writes behave as designed on " . $dbName . "\n";
    exit(0);
}
