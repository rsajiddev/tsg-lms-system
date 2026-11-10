<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\PurgeGuard;
use App\Core\Security;
use App\Core\View;
use App\Services\AccountIdentity;
use App\Services\LessonProtection;
use App\Services\NotificationService;
use App\Services\SessionRegistry;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Security, payment and moderation settings.
 *
 * Attendance and lesson watch-time limits used to live here. They now belong to the pages that
 * use them (Students and the Lesson library), so this controller only owns the authentication
 * rate limits below, which no other screen has a natural home for.
 */
final class AdminManagementController
{
    private const SETTINGS = [
        'otp_lifetime_seconds' => ['OTP validity', 30, 3600, 'seconds', 'How long a verification code stays usable before the student has to request a new one.'],
        'otp_resend_cooldown_seconds' => ['OTP resend wait', 10, 600, 'seconds', 'How long a student must wait before the same code can be sent again. Kept short so a slow mail server does not look like a broken form, and independent of the validity interval above.'],
        'otp_max_attempts' => ['OTP verification attempts', 1, 20, 'attempts', 'Wrong codes a student may enter before a new OTP has to be requested.'],
        'otp_resends_per_window' => ['OTP resend requests per window', 1, 20, 'requests', 'How many resend codes one phone number may trigger inside the window below.'],
        'otp_resend_window_minutes' => ['OTP resend limit window', 1, 1440, 'minutes', 'Length of the rolling window used to count OTP resend requests.'],
        'login_max_attempts' => ['Login attempts before lockout', 1, 20, 'attempts', 'Failed sign-in attempts tolerated before the account is locked.'],
        'login_lockout_minutes' => ['Login lockout window', 1, 1440, 'minutes', 'How long a locked account stays locked before a new attempt is allowed.'],
        'register_max_attempts' => ['Registration requests per window', 1, 50, 'requests', 'Sign-up requests a single visitor may send inside the window below.'],
        'register_window_minutes' => ['Registration limit window', 1, 1440, 'minutes', 'Length of the rolling window used to count registration requests.'],
        'password_reset_max_attempts' => ['Password reset requests per window', 1, 20, 'requests', 'Reset links one account may request inside the window below.'],
        'password_reset_window_minutes' => ['Password reset limit window', 1, 1440, 'minutes', 'Length of the rolling window used to count password reset requests.'],
    ];

    private const SETTING_GROUPS = [
        [
            'title' => 'One-time password (OTP)',
            'description' => 'Controls how long a verification code stays valid, how long a student waits to receive it again, and how many wrong codes are tolerated.',
            'keys' => ['otp_lifetime_seconds', 'otp_resend_cooldown_seconds', 'otp_max_attempts', 'otp_resends_per_window', 'otp_resend_window_minutes'],
        ],
        [
            'title' => 'Login protection',
            'description' => 'Locks an account for a cooling-off period after repeated failed sign-in attempts.',
            'keys' => ['login_max_attempts', 'login_lockout_minutes'],
        ],
        [
            'title' => 'Registration limits',
            'description' => 'Caps how many sign-up requests a single visitor can send, which slows down automated abuse.',
            'keys' => ['register_max_attempts', 'register_window_minutes'],
        ],
        [
            'title' => 'Password reset limits',
            'description' => 'Restricts how many reset links can be generated for one account inside a rolling window.',
            'keys' => ['password_reset_max_attempts', 'password_reset_window_minutes'],
        ],
    ];

    /**
     * The sessions-and-video-protection card, saved by its own endpoint.
     *
     * `video_watermark_enabled` is the one non-numeric field, so it carries its own choices rather
     * than a range. The limits are stated in minutes and counts, never as a promise: the overlay is
     * drawn in the page and cannot brand a recording that is played somewhere else.
     */
    private const VIDEO_PROTECTION_SETTINGS = [
        SessionRegistry::MAX_ACTIVE_KEY => [
            'label' => 'Active sessions per account',
            'min' => SessionRegistry::MIN_MAX_ACTIVE,
            'max' => SessionRegistry::MAX_MAX_ACTIVE,
            'unit' => 'sessions',
            'hint' => 'The company rule is one. Raise it when students genuinely study on two devices; a new sign-in always displaces the oldest session, and the displaced one is signed out on its next click.',
        ],
        LessonProtection::WATERMARK_KEY => [
            'label' => 'Account watermark over the player',
            'choices' => ['1' => 'On - print the student name, Academy ID, time and trace code', '0' => 'Off'],
            'hint' => 'Drawn over the recording in this page. It is not burned into the video file, and it cannot appear on a screen recording made by another application.',
        ],
        LessonProtection::RETENTION_KEY => [
            'label' => 'Keep unusual viewing reports for',
            'min' => LessonProtection::MIN_RETENTION_DAYS,
            'max' => LessonProtection::MAX_RETENTION_DAYS,
            'unit' => 'days',
            'hint' => 'Blocked downloads, right-clicks, blocked fullscreen and large forward jumps, collected on the Recent activity page.',
        ],
        LessonProtection::LOCK_ON_COMPLETION_KEY => [
            'label' => 'Hide lesson videos once a course is certified',
            'choices' => ['0' => 'No - keep recordings available until the access period ends', '1' => 'Yes - hide the videos when a certificate has been issued'],
            'hint' => 'Applies only to an enrolment whose certificate has been issued (the certified status). Quizzes, assignments, grades and the certificate itself stay open either way; only the lesson videos are hidden, and they still stop for good when the access period expires.',
        ],
    ];

    /**
     * Other screens (payments, certificates, students, lesson library) share the app_settings
     * table with this page, so only the keys declared in SETTINGS are read or written here.
     */
    public function settings(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $db = Database::connection();
        try {
            $stored = [];
            foreach ($db->query('SELECT setting_key,setting_value FROM app_settings')->fetchAll() as $row) {
                $stored[(string) $row['setting_key']] = (string) $row['setting_value'];
            }
            $values = [];
            foreach (array_keys(self::SETTINGS) as $key) {
                if (isset($stored[$key])) $values[$key] = (int) $stored[$key];
            }
            // The handover cards quote what is still on the roll, so the counts ride along.
            $testingCounts = [
                'students' => (int) $db->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn(),
                'certificates' => (int) $db->query('SELECT COUNT(*) FROM certificates')->fetchColumn(),
            ];
            // The protection card answers with defaults rather than an empty box when its seeds have
            // not been imported, because a half-imported database must still be configurable.
            $protectionValues = [
                SessionRegistry::MAX_ACTIVE_KEY => isset($stored[SessionRegistry::MAX_ACTIVE_KEY])
                    ? (int) $stored[SessionRegistry::MAX_ACTIVE_KEY]
                    : SessionRegistry::DEFAULT_MAX_ACTIVE,
                LessonProtection::WATERMARK_KEY => isset($stored[LessonProtection::WATERMARK_KEY])
                    ? ($stored[LessonProtection::WATERMARK_KEY] === '0' ? '0' : '1')
                    : '1',
                LessonProtection::RETENTION_KEY => isset($stored[LessonProtection::RETENTION_KEY])
                    ? (int) $stored[LessonProtection::RETENTION_KEY]
                    : LessonProtection::DEFAULT_RETENTION_DAYS,
                // Off by default: recordings stay watchable until the access period ends unless the
                // Academy deliberately asks for the completion lock.
                LessonProtection::LOCK_ON_COMPLETION_KEY => isset($stored[LessonProtection::LOCK_ON_COMPLETION_KEY])
                    ? ($stored[LessonProtection::LOCK_ON_COMPLETION_KEY] === '1' ? '1' : '0')
                    : '0',
            ];
            View::render('admin/settings', ['title' => 'Admin settings', 'user' => $admin, 'settings' => self::SETTINGS, 'groups' => self::SETTING_GROUPS, 'values' => $values, 'videoProtection' => self::VIDEO_PROTECTION_SETTINGS, 'protectionValues' => $protectionValues, 'testingCounts' => $testingCounts, 'message' => $_SESSION['_flash'] ?? null, 'error' => $_SESSION['_flash_error'] ?? null]);
        } catch (\PDOException $exception) {
            if ((string)$exception->getCode() !== '42S02') throw $exception;
            View::render('admin/settings', ['title' => 'Admin settings', 'user' => $admin, 'settings' => [], 'groups' => [], 'values' => [], 'message' => null, 'error' => $_SESSION['_flash_error'] ?? null, 'migrationRequired' => true]);
        }
        unset($_SESSION['_flash'], $_SESSION['_flash_error']);
    }

    /**
     * The Danger Zone: every guarded bulk-delete tool the workspaces used to scatter now lives on
     * one page, so permanent deletion has a single, deliberate home. This method only reads - it
     * gathers the live record count behind each card and the course list the scoped cards need, so
     * an admin sees exactly how much each button would remove before typing a phrase. The forms on
     * this page still post to each workspace's own endpoint, which repeats every server-side check;
     * nothing about the deletion itself changes.
     */
    public function dangerZone(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $db = Database::connection();
        $count = static fn(string $sql): int => (int) ($db->query($sql)->fetchColumn() ?: 0);
        $danger = [
            'students' => $count("SELECT COUNT(*) FROM users WHERE role='student'"),
            'certificates' => $count('SELECT COUNT(*) FROM certificates'),
            'assignments' => $count('SELECT COUNT(*) FROM assignments'),
            'quizzes' => $count('SELECT COUNT(*) FROM quizzes'),
            'lessons' => $count('SELECT COUNT(*) FROM lessons'),
            'announcements' => $count('SELECT COUNT(*) FROM notifications'),
            'automated' => $count("SELECT COUNT(*) FROM notifications WHERE category <> 'announcement' AND category <> '' AND category IS NOT NULL"),
        ];
        // The e-mail log may not exist yet on a partially imported database, so a missing table
        // reads as zero rather than breaking the whole page.
        try {
            $danger['emails'] = $count('SELECT COUNT(*) FROM email_logs');
        } catch (Throwable $exception) {
            $danger['emails'] = 0;
        }
        $danger['activity'] = $danger['automated'] + $danger['emails'];
        // The scoped cards (assignments, quizzes, lessons) keep their per-course dropdown, and the
        // Clear All Data and Reset cards need the live ID scheme, so the same lookups the workspace
        // pages make are made once here.
        $danger['courses'] = $db->query('SELECT id,title FROM courses ORDER BY title')->fetchAll(PDO::FETCH_ASSOC);
        $scheme = AccountIdentity::scheme($db);
        $next = (int) ($db->query('SELECT setting_value FROM app_settings WHERE setting_key = ' . $db->quote(AccountIdentity::NEXT_KEY))->fetchColumn() ?: 1);
        $danger['idNext'] = $next;
        $danger['idPreview'] = AccountIdentity::preview($scheme, $next);
        $danger['idPrefix'] = (string) $scheme['prefix'];
        $danger['idWithYear'] = !empty($scheme['include_year']);
        View::render('admin/danger-zone', ['title' => 'Danger Zone', 'user' => $admin, 'danger' => $danger, 'message' => $_SESSION['_flash'] ?? null, 'error' => $_SESSION['_flash_error'] ?? null]);
        unset($_SESSION['_flash'], $_SESSION['_flash_error']);
    }

    public function saveSettings(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            return;
        }
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $upsert = $db->prepare('INSERT INTO app_settings(setting_key,setting_value,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)');
            foreach (self::SETTINGS as $key => [$label, $min, $max]) {
                $raw = $_POST[$key] ?? '';
                if (!is_string($raw) || !ctype_digit($raw) || (int)$raw < $min || (int)$raw > $max) throw new \RuntimeException($label . ' must be between ' . $min . ' and ' . $max . '.');
                $upsert->execute([$key, $raw, (int)$admin['id']]);
            }
            $db->prepare("INSERT INTO audit_logs(actor_user_id,action,entity_type,metadata) VALUES(?,'security.settings_updated','app_settings',JSON_OBJECT('keys',?))")->execute([(int)$admin['id'], implode(',', array_keys(self::SETTINGS))]);
            $db->commit();
            $_SESSION['_flash'] = 'Security settings saved.';
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        header('Location: ' . rtrim((require BASE_PATH . '/config/app.php')['url'], '/') . '/admin/settings');
        exit;
    }

    /**
     * Saves only the three video-protection keys.
     *
     * The page's other endpoint, /admin/settings, deliberately does not know about these: a form
     * that posts the authentication limits must not be able to switch the watermark off by omitting
     * a field, which is what a single shared save handler would do.
     */
    public function saveVideoProtection(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            return;
        }
        $db = Database::connection();
        $clean = [];
        foreach (self::VIDEO_PROTECTION_SETTINGS as $key => $spec) {
            // A field the form did not post keeps whatever is already stored, so a save that only
            // reaches some of the card can never blank or flip the rest by omission.
            if (!array_key_exists($key, $_POST)) continue;
            $raw = $_POST[$key] ?? '';
            if (!is_string($raw)) $raw = '';
            $raw = trim($raw);
            if (isset($spec['choices'])) {
                if (!array_key_exists($raw, $spec['choices'])) {
                    $_SESSION['_flash_error'] = $spec['label'] . ' needs a clear answer. Choose one of the options and save again.';
                    $this->backToSettings();
                }
                $clean[$key] = $raw;
                continue;
            }
            if (!ctype_digit($raw) || (int) $raw < $spec['min'] || (int) $raw > $spec['max']) {
                $_SESSION['_flash_error'] = $spec['label'] . ' must be between ' . $spec['min'] . ' and ' . $spec['max'] . ' ' . $spec['unit'] . '.';
                $this->backToSettings();
            }
            $clean[$key] = $raw;
        }
        $db->beginTransaction();
        try {
            $upsert = $db->prepare('INSERT INTO app_settings(setting_key,setting_value,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)');
            foreach ($clean as $key => $value) {
                $upsert->execute([$key, $value, (int) $admin['id']]);
            }
            $db->prepare("INSERT INTO audit_logs(actor_user_id,action,entity_type,metadata) VALUES(?,'settings.video_protection_updated','app_settings',JSON_OBJECT('keys',?))")
                ->execute([(int) $admin['id'], implode(',', array_keys($clean))]);
            $db->commit();
            $_SESSION['_flash'] = 'Session and video protection settings saved.';
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            $_SESSION['_flash_error'] = 'The protection settings could not be saved. Try again.';
            error_log('[VIDEO PROTECTION] Settings save failed: ' . $exception->getMessage());
        }
        $this->backToSettings();
    }

    private function backToSettings(string $path = '/admin/settings'): never
    {
        header('Location: ' . rtrim((require BASE_PATH . '/config/app.php')['url'], '/') . $path);
        exit;
    }

    /**
     * Testing tool: clears everything the testing produced so the project can be handed over from
     * a zero state.
     *
     * Every student account goes with the whole graph filed under it - enrolments, payments,
     * attempts, submissions, progress, grades, attendance, certificates, chats, sessions and the
     * uploaded files - the same graph the Students directory purge walks, plus the shared report
     * links that hold the last foreign key back. What stays is exactly what a fresh launch needs:
     * the admin accounts and their credentials, every app setting (including the configured ID
     * format), all courses and content, and the email delivery log. The ID counter restarts from
     * the configured scheme because the accounts it counted for no longer exist.
     */
    public function clearAllData(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        try {
            $this->performClearAllData($admin);
        } catch (Throwable $exception) {
            $_SESSION['_flash_error'] = $exception->getMessage();
            $this->backToSettings(PurgeGuard::returnPath('/admin/settings'));
        }
    }

    private function performClearAllData(array $admin): void
    {
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            throw new RuntimeException('Your session expired. Refresh the page and try again.');
        }
        PurgeGuard::assertConfirmed('CLEAR ALL DATA');
        $db = Database::connection();
        $total = (int) $db->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn();
        if ($total === 0) {
            $_SESSION['_flash'] = 'There is no student data to clear. The directory is already empty.';
            $this->backToSettings(PurgeGuard::returnPath('/admin/settings'));
        }
        $students = "(SELECT id FROM users WHERE role='student')";
        $paths = $db->query("SELECT file_path FROM assignment_submissions WHERE student_id IN $students AND file_path IS NOT NULL
            UNION SELECT payment_proof_path FROM payments WHERE student_id IN $students AND payment_proof_path IS NOT NULL
            UNION SELECT file_path FROM certificates WHERE student_id IN $students AND file_path IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
        $sources = [
            'assignment_submission' => array_map('intval', $db->query("SELECT id FROM assignment_submissions WHERE student_id IN $students")->fetchAll(PDO::FETCH_COLUMN)),
            'payment' => array_map('intval', $db->query("SELECT id FROM payments WHERE student_id IN $students")->fetchAll(PDO::FETCH_COLUMN)),
            'certificate_request' => array_map('intval', $db->query("SELECT id FROM certificates WHERE student_id IN $students")->fetchAll(PDO::FETCH_COLUMN)),
        ];
        $files = PurgeGuard::removeStoredFiles(is_array($paths) ? $paths : []);
        $deleted = [];
        $db->beginTransaction();
        try {
            $statements = [
                'assignment_submissions' => 'DELETE FROM assignment_submissions WHERE student_id IN ' . $students,
                'attendance_records' => 'DELETE FROM attendance_records WHERE student_id IN ' . $students,
                'certificates' => 'DELETE FROM certificates WHERE student_id IN ' . $students,
                'course_grades' => 'DELETE FROM course_grades WHERE student_id IN ' . $students,
                'email_otps' => 'DELETE FROM email_otps WHERE user_id IN ' . $students,
                'lesson_progress' => 'DELETE FROM lesson_progress WHERE student_id IN ' . $students,
                'notification_replies' => 'DELETE FROM notification_replies WHERE student_id IN ' . $students . ' OR recipient_student_id IN ' . $students,
                'notification_recipients' => 'DELETE FROM notification_recipients WHERE student_id IN ' . $students,
                'payments' => 'DELETE FROM payments WHERE student_id IN ' . $students,
                'quiz_attempts' => 'DELETE FROM quiz_attempts WHERE student_id IN ' . $students,
                'coding_challenge_attempts' => 'DELETE FROM coding_challenge_attempts WHERE student_id IN ' . $students,
                'quiz_retake_approvals' => 'DELETE FROM quiz_retake_approvals WHERE student_id IN ' . $students,
                'report_shares' => 'DELETE FROM report_shares WHERE student_id IN ' . $students,
                'student_course_access' => 'DELETE FROM student_course_access WHERE student_id IN ' . $students,
                'login_events' => 'DELETE FROM login_events WHERE user_id IN ' . $students,
                'audit_logs' => 'DELETE FROM audit_logs WHERE actor_user_id IN ' . $students,
            ];
            foreach ($statements as $table => $sql) {
                $deleted[$table] = (int) $db->exec($sql);
            }
            // Same tolerance as the directory purge: a database that predates these tables is
            // missing nothing the clear depends on, and a half-imported schema must not stall it.
            foreach ([
                'lesson_view_events' => 'DELETE FROM lesson_view_events WHERE student_id IN ' . $students,
                'user_sessions' => 'DELETE FROM user_sessions WHERE user_id IN ' . $students,
                'chat_threads' => 'DELETE FROM chat_threads WHERE student_id IN ' . $students,
            ] as $table => $sql) {
                try {
                    $deleted[$table] = (int) $db->exec($sql);
                } catch (PDOException $exception) {
                    if ((string) $exception->getCode() !== '42S02') throw $exception;
                    $deleted[$table] = 0;
                }
            }
            $deleted['email_logs_unlinked'] = (int) $db->exec("UPDATE email_logs SET related_user_id=NULL WHERE related_user_id IN $students");
            $deleted['accounts'] = (int) $db->exec("DELETE FROM users WHERE role='student'");
            // With the directory empty the counter may restart: the numbers it handed out belong
            // to accounts that no longer exist, and the next launch starts from the saved scheme.
            $db->prepare('UPDATE app_settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?')
                ->execute(['1', (int) $admin['id'], AccountIdentity::NEXT_KEY]);
            $db->prepare("INSERT INTO audit_logs(actor_user_id,action,entity_type,metadata) VALUES(?, 'system.data_cleared', 'user', JSON_OBJECT('accounts',?, 'payments',?, 'submissions',?, 'certificates',?, 'files_removed',?))")
                ->execute([(int) $admin['id'], $deleted['accounts'], $deleted['payments'], $deleted['assignment_submissions'], $deleted['certificates'], $files]);
            $db->commit();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
        // Announcements pointing at removed requests would lead an admin to a dead page. An empty
        // id list is skipped: forgetSource() reads that as "every notification of this type".
        try {
            $notifier = new NotificationService($db);
            foreach ($sources as $sourceType => $sourceIds) {
                if ($sourceIds !== []) {
                    $notifier->forgetSource((string) $sourceType, $sourceIds);
                }
            }
        } catch (Throwable $exception) {
            error_log('[SYSTEM CLEAR] Notification retire skipped: ' . $exception->getMessage());
        }
        $_SESSION['_flash'] = sprintf(
            'Cleared %d student account(s) with their enrollments, payments, attempts, submissions, grades, attendance, sessions, chats and certificates.%s The ID counter restarts from the saved scheme, so the next sign-up is number one. Admin accounts, courses, content and settings were kept.',
            $deleted['accounts'],
            $files > 0 ? ' ' . $files . ' uploaded file(s) were removed from storage.' : ''
        );
        $this->backToSettings(PurgeGuard::returnPath('/admin/settings'));
    }

    /**
     * Testing tool: re-arms the student ID counter without deleting a single record.
     *
     * A launch that configured TSG-CER-2026 with serial 001 should hand out exactly that first
     * number, even if testing burned through IDs beforehand. The reset never rewinds into a
     * number somebody already holds: any serial still in use on an account is walked past, so a
     * reset cannot manufacture a duplicate and the issue() lock keeps handing out unique numbers
     * from the new position onward.
     */
    public function resetIdCounter(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        try {
            $this->performResetCounter($admin);
        } catch (Throwable $exception) {
            $_SESSION['_flash_error'] = $exception->getMessage();
            $this->backToSettings(PurgeGuard::returnPath('/admin/settings'));
        }
    }

    private function performResetCounter(array $admin): void
    {
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            throw new RuntimeException('Your session expired. Refresh the page and try again.');
        }
        PurgeGuard::assertConfirmed('RESET ID COUNTER');
        $db = Database::connection();
        $scheme = AccountIdentity::scheme($db);
        $rawStart = trim((string) ($_POST['student_id_start'] ?? ''));
        if ($rawStart === '') {
            // No number typed: resume after the highest serial the current scheme still has on it,
            // so a reset with records standing is a repair, never a rewind into a used number.
            $start = 1;
            $suffix = $scheme['include_year'] ? '-' . gmdate('Y') . '-' : '-';
            $serials = $db->query('SELECT account_reference FROM users WHERE role="student" AND account_reference IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
            foreach ((array) $serials as $reference) {
                $reference = trim((string) $reference);
                if (!str_starts_with($reference, $scheme['prefix'] . $suffix)) continue;
                $tail = (int) substr($reference, strrpos($reference, '-') + 1);
                $start = max($start, $tail + 1);
            }
        } elseif (!ctype_digit($rawStart) || (int) $rawStart < 1 || (int) $rawStart > AccountIdentity::MAX_SEQUENCE) {
            throw new RuntimeException('Enter the serial number new IDs should start from, between 1 and ' . AccountIdentity::MAX_SEQUENCE . '.');
        } else {
            $start = (int) $rawStart;
        }
        // Protection against duplicates: walk past every number an account still carries.
        $taken = $db->prepare('SELECT 1 FROM users WHERE account_reference = ? LIMIT 1');
        $clashed = 0;
        while ($start <= AccountIdentity::MAX_SEQUENCE) {
            $taken->execute([AccountIdentity::format($scheme['prefix'], $start, $scheme['include_year'] ? (int) gmdate('Y') : null)]);
            if (!$taken->fetch()) break;
            $start++;
            $clashed++;
        }
        if ($start > AccountIdentity::MAX_SEQUENCE) {
            throw new RuntimeException('The ID sequence is exhausted up to ' . AccountIdentity::MAX_SEQUENCE . '. Raise the prefix scheme before resetting.');
        }
        $db->prepare('INSERT INTO app_settings(setting_key,setting_value,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)')
            ->execute([AccountIdentity::NEXT_KEY, (string) $start, (int) $admin['id']]);
        $db->prepare("INSERT INTO audit_logs(actor_user_id,action,entity_type,metadata) VALUES(?, 'account.id_counter_reset', 'app_settings', JSON_OBJECT('start',?, 'skipped_taken',?))")
            ->execute([(int) $admin['id'], $start, $clashed]);
        $_SESSION['_flash'] = 'ID counter reset. The next new ID will be ' . AccountIdentity::preview($scheme, $start)
            . ($clashed > 0 ? ' (' . $clashed . ' number(s) already in use were skipped - no existing record was touched).' : '.')
            . ' Existing students and issued certificates keep their IDs.';
        $this->backToSettings(PurgeGuard::returnPath('/admin/settings'));
    }

    public function savePaymentDetails(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            return;
        }
        $this->savePaymentSettings($admin, '/admin/payments');
    }

    private function savePaymentSettings(array $admin, string $redirectPath): never
    {
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $accounts = $_POST['accounts'] ?? [];
            if (!is_array($accounts) || count($accounts) > 12) throw new \RuntimeException('Add no more than 12 payment accounts.');
            $columns = ['account_title' => 150, 'bank_name' => 100, 'account_number' => 80, 'iban' => 80, 'wallet_name' => 100, 'wallet_number' => 80, 'instructions' => 255];
            $clean = [];
            foreach ($accounts as $account) {
                if (!is_array($account)) continue;
                $row = [];
                foreach ($columns as $key => $max) {
                    $value = trim((string)($account[$key] ?? ''));
                    if (mb_strlen($value) > $max) throw new \RuntimeException(ucwords(str_replace('_', ' ', $key)) . ' must be ' . $max . ' characters or fewer.');
                    $row[$key] = $value;
                }
                if (implode('', $row) !== '') $clean[] = $row;
            }
            if (count($clean) > 12) throw new \RuntimeException('Add no more than 12 payment accounts.');

            $db->exec('DELETE FROM payment_accounts');
            $insert = $db->prepare('INSERT INTO payment_accounts(account_title,bank_name,account_number,iban,wallet_name,wallet_number,instructions,sort_order) VALUES(?,?,?,?,?,?,?,?)');
            foreach ($clean as $order => $row) $insert->execute([$row['account_title'], $row['bank_name'], $row['account_number'], $row['iban'], $row['wallet_name'], $row['wallet_number'], $row['instructions'], $order]);

            // Keep the original settings keys in sync for older integrations that still read them.
            $legacy = $clean[0] ?? array_fill_keys(array_keys($columns), '');
            $upsert = $db->prepare('INSERT INTO app_settings(setting_key,setting_value,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)');
            $legacyMap = ['payment_account_title'=>'account_title','payment_bank_name'=>'bank_name','payment_account_number'=>'account_number','payment_iban'=>'iban','payment_wallet_name'=>'wallet_name','payment_wallet_number'=>'wallet_number','payment_instructions'=>'instructions'];
            foreach ($legacyMap as $key => $field) $upsert->execute([$key, $legacy[$field], (int)$admin['id']]);
            $db->prepare("INSERT INTO audit_logs(actor_user_id,action,entity_type,metadata) VALUES(?,'payment.settings_updated','payment_accounts',JSON_OBJECT('account_count',?))")->execute([(int)$admin['id'], count($clean)]);
            $db->commit();
            $_SESSION['_flash'] = count($clean) . ' payment account' . (count($clean) === 1 ? '' : 's') . ' saved.';
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        header('Location: ' . rtrim((require BASE_PATH . '/config/app.php')['url'], '/') . $redirectPath);
        exit;
    }

    public function reviews(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        $db = Database::connection();
        $rows = $db->query("SELECT id,reviewer_type,full_name,context_label,rating,content,status,created_at FROM reviews WHERE status='pending' ORDER BY created_at ASC,id ASC")->fetchAll();
        $contactMessages = $db->query('SELECT id,sender_name,sender_email,phone,subject,message,submitted_at FROM contact_messages ORDER BY submitted_at DESC,id DESC')->fetchAll();
        View::render('admin/reviews', ['title' => 'Review moderation', 'user' => $admin, 'reviews' => $rows, 'contactMessages' => $contactMessages, 'message' => $_SESSION['_flash'] ?? null]);
        unset($_SESSION['_flash']);
    }

    public function clearContactInbox(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            return;
        }

        $db = Database::connection();
        $db->beginTransaction();
        try {
            $deleted = (int) $db->exec('DELETE FROM contact_messages');
            $db->prepare("INSERT INTO audit_logs(actor_user_id,action,entity_type,metadata) VALUES(?, 'contact_inbox.cleared', 'contact_message', JSON_OBJECT('deleted_rows', ?))")
                ->execute([(int) $admin['id'], $deleted]);
            $db->commit();
            $_SESSION['_flash'] = $deleted > 0
                ? 'Cleared ' . number_format($deleted) . ' contact message(s) from the inbox.'
                : 'The contact inbox was already empty.';
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }

        header('Location: ' . rtrim((require BASE_PATH . '/config/app.php')['url'], '/') . '/admin/reviews');
        exit;
    }

    public function reviewDecision(): void
    {
        $admin = AuthMiddleware::requireRole('admin');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            return;
        }
        $id = (int)($_POST['review_id'] ?? 0);
        $decision = (string)($_POST['decision'] ?? '');
        if ($id < 1 || !in_array($decision, ['approved', 'rejected'], true)) {
            http_response_code(400);
            return;
        }
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("UPDATE reviews SET status=? WHERE id=? AND status='pending'");
            $stmt->execute([$decision, $id]);
            if ($stmt->rowCount() !== 1) throw new \RuntimeException('This review is no longer pending.');
            $db->prepare('INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id) VALUES(?,?,\'review\',?)')->execute([(int)$admin['id'], 'review.' . $decision, $id]);
            $db->commit();
            $_SESSION['_flash'] = 'Review ' . ($decision === 'approved' ? 'approved and visible on the homepage.' : 'rejected.');
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        header('Location: ' . rtrim((require BASE_PATH . '/config/app.php')['url'], '/') . '/admin/reviews');
        exit;
    }
}
