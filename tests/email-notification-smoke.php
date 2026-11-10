<?php
declare(strict_types=1);

/**
 * Email and notification smoke test.
 *
 * Run against the local development database:  /Applications/XAMPP/xamppfiles/bin/php tests/email-notification-smoke.php
 * A scratch database can be targeted with:     ... --database=tsg_mail_check_tmp
 *
 * Nothing here opens a socket to SMTP. It checks the three layers that decide what a recipient
 * reads and who reads it: the environment keys that name the mailboxes, the template wording and
 * routing, and the in-app notification fan-out that drives the sidebar badges. Every row it
 * creates is removed again, including the scratch student.
 */

define('BASE_PATH', dirname(__DIR__));
foreach (
    [
        '/app/Core/Env.php',
        '/app/Core/Database.php',
        '/app/Services/EmailLayout.php',
        '/app/Services/EmailMessages.php',
        '/app/Services/AccountIdentity.php',
        '/app/Services/AdminAttention.php',
        '/app/Services/MailService.php',
        '/app/Services/NotificationService.php',
    ] as $file
) {
    require BASE_PATH . $file;
}

use App\Core\Database;
use App\Core\Env;
use App\Services\EmailLayout;
use App\Services\EmailMessages;
use App\Services\MailService;
use App\Services\NotificationService;

Env::load(BASE_PATH . '/.env');
$options = getopt('', ['database::']);
if (isset($options['database']) && is_string($options['database']) && $options['database'] !== '') {
    putenv('DB_NAME=' . $options['database']);
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$check = static function (array $messages, string $event, int $expected, bool $withLegal = false) use ($assert): void {
    $assert(count($messages) === $expected, $event . ' should produce ' . $expected . ' message(s), got ' . count($messages) . '.');
    foreach ($messages as $message) {
        $subject = (string) ($message['subject'] ?? '');
        $view = (array) ($message['view'] ?? []);
        $assert($subject !== '', $event . ' has an empty subject.');
        $html = EmailLayout::html($subject, $view);
        $text = EmailLayout::text($subject, $view);
        $assert(str_starts_with(strtolower($html), '<!doctype html'), $event . ' HTML is missing its doctype.');
        $assert(str_contains($html, EmailLayout::company()['name']), $event . ' HTML omits the company signature.');
        // Only the student-facing mails carry the legal block; transactional OTP and internal
        // alerts stay short, but every mail has to point back at the company site.
        $assert(str_contains($html, EmailLayout::company()['site']), $event . ' HTML omits the company site link.');
        if ($withLegal) {
            foreach (['privacy', 'terms', 'refund'] as $link) {
                $assert(str_contains($html, EmailLayout::company()[$link]), $event . ' mail omits the ' . $link . ' link.');
            }
        }
        $assert(str_contains($text, 'Best regards,'), $event . ' plain-text fallback has no signature.');
        foreach (['[Student Name]', '[Student ID]', '[Course Name]', '[Amount]', '[Date & Time]', '[Month Year]', '[15% Amount]'] as $token) {
            $assert(!str_contains($html, $token) && !str_contains($text, $token), $event . ' left ' . $token . ' unreplaced.');
        }
    }
};

$legalLinksForStudent = static function (array $messages): bool {
    foreach ($messages as $message) {
        if (($message['view']['audience'] ?? '') !== 'student') continue;
        $html = EmailLayout::html((string) $message['subject'], (array) $message['view']);
        $company = EmailLayout::company();
        return str_contains($html, $company['privacy']) && str_contains($html, $company['terms']) && str_contains($html, $company['refund']);
    }
    return false;
};

$failures = 0;
try {
    /* ------------------------------------------------------------------ 1. environment */
    foreach (['MAIL_USERNAME', 'MAIL_FROM_ADDRESS'] as $key) {
        $value = trim((string) (getenv($key) ?: ''));
        $assert(filter_var($value, FILTER_VALIDATE_EMAIL) !== false, $key . ' is not a usable address (' . var_export($value, true) . ').');
        $assert(!str_contains($value, '#'), $key . ' still carries an inline comment.');
    }
    $assert(filter_var(MailService::hrAddress(), FILTER_VALIDATE_EMAIL) !== false, 'The HR inbox does not resolve to an address.');
    $assert(filter_var(MailService::certificateAddress(), FILTER_VALIDATE_EMAIL) !== false, 'The certificate inbox does not resolve to an address.');
    $assert(filter_var(MailService::developerAddress(), FILTER_VALIDATE_EMAIL) !== false, 'The developer inbox does not resolve to an address.');
    // Company alerts (HR, certificate) resolve to the one sending mailbox, so a company that
    // swaps in its no-reply address receives all of it. Developer alerts are kept separate: they
    // follow MAIL_DEVELOPER_ADDRESS and must NOT collapse onto the company mailbox when that
    // address is configured - that mix-up is exactly what this restores protection against.
    $sending = trim((string) (getenv('MAIL_FROM_ADDRESS') ?: ''));
    foreach (['hr' => MailService::hrAddress(), 'certificates' => MailService::certificateAddress()] as $label => $resolved) {
        $assert($resolved === $sending, 'The ' . $label . ' inbox does not resolve to MAIL_FROM_ADDRESS.');
    }
    $developer = trim((string) (getenv('MAIL_DEVELOPER_ADDRESS') ?: ''));
    if (filter_var($developer, FILTER_VALIDATE_EMAIL)) {
        $assert(MailService::developerAddress() === $developer, 'The developer inbox does not resolve to MAIL_DEVELOPER_ADDRESS.');
        $assert(MailService::developerAddress() !== $sending, 'The developer inbox must stay separate from the company mailbox.');
    } else {
        // With no developer address configured the copies fall back to the company mailbox rather
        // than being dropped, so the developer still receives them instead of losing mail.
        $assert(MailService::developerAddress() === $sending, 'With MAIL_DEVELOPER_ADDRESS unset the developer inbox should fall back to MAIL_FROM_ADDRESS.');
    }
    printf("environment: from=%s hr=%s developer=%s certificates=%s\n", $sending, MailService::hrAddress(), MailService::developerAddress(), MailService::certificateAddress());

    /* -------------------------------------------------------------------- 2. templates */
    $student = ['student_id' => 42, 'student_name' => 'Ayesha Khan', 'student_email' => 'student@example.test'];
    $payment = $student + [
        'course_title' => 'Full Stack Web Development',
        'amount' => 45000,
        'currency' => 'PKR',
        'reference' => 'TSG-PAY-0001',
        'submitted_at' => gmdate('Y-m-d H:i:s'),
    ];
    $check(EmailMessages::otp('verify_email', $student['student_name'], '482913', 10), 'otp.verify_email', 1);
    $check(EmailMessages::registration($student), 'registration', 2);
    $check(EmailMessages::paymentSubmitted($payment), 'payment.submitted', 3);
    $assert($legalLinksForStudent(EmailMessages::paymentSubmitted($payment)), 'The student payment-request mail must carry the three legal links.');
    $recipients = array_map(static fn(array $m): string => (string) $m['to'], EmailMessages::paymentSubmitted($payment));
    $assert(in_array($student['student_email'], $recipients, true), 'A payment request must reach the student.');
    $assert(in_array(MailService::developerAddress(), $recipients, true), 'A payment request must reach the developer inbox.');
    $assert(in_array(MailService::hrAddress(), $recipients, true), 'A payment request must reach the HR copy of the sending inbox.');
    $approved = EmailMessages::paymentDecision($payment + ['decision' => 'approved', 'note' => 'Receipt verified.', 'decided_at' => gmdate('Y-m-d H:i:s'), 'receipt_number' => 'TSG-RCP-0001', 'reviewer_name' => 'Admin', 'reviewer_email' => 'admin@example.test']);
    $check($approved, 'payment.approved', 3);
    $assert($legalLinksForStudent($approved), 'The student approval mail must carry the three legal links.');
    $approvedHtml = EmailLayout::html((string) $approved[0]['subject'], (array) $approved[0]['view']);
    // The mail quotes whatever ID the account carries, so the assertion asks the same service the
    // template asks instead of hard-coding a number that only holds for an account with no ID yet.
    $assert(str_contains($approvedHtml, \App\Services\AccountIdentity::reference(42, 'student')), 'The approval mail must show the Student ID.');
    $assert(str_contains($approvedHtml, 'student@example.test'), 'The approval mail must repeat the sign-in email address.');
    $check(EmailMessages::paymentDecision($payment + ['decision' => 'rejected', 'note' => 'The receipt is unreadable.', 'decided_at' => gmdate('Y-m-d H:i:s'), 'receipt_number' => '—', 'reviewer_name' => 'Admin', 'reviewer_email' => 'admin@example.test']), 'payment.rejected', 1);
    $check(EmailMessages::monthlyRevenue([
        'period' => 'October 2026', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'currency' => 'PKR',
        'student_count' => 2, 'total_revenue' => 90000, 'developer_share' => 13500,
        'rows' => [$student + ['course_title' => 'Full Stack Web Development', 'amount' => 45000], ['student_id' => 43, 'student_name' => 'Bilal Ahmed', 'course_title' => 'Graphic Design', 'amount' => 45000]],
    ]), 'revenue.monthly_summary', 1);
    $summary = EmailMessages::monthlyRevenue(['period' => 'October 2026', 'currency' => 'PKR', 'rows' => [$student + ['course_title' => 'Full Stack Web Development', 'amount' => 45000]]]);
    $assert(str_contains(EmailLayout::html((string) $summary[0]['subject'], (array) $summary[0]['view']), '15%'), 'The developer summary must state the 15% share.');
    $check(EmailMessages::certificateRequest($payment + ['reference' => 7, 'remarks' => 'Completed the final project.']), 'certificate.requested', 1);
    $check(EmailMessages::certificateIssued($student + ['course_title' => 'Full Stack Web Development', 'certificate_id' => 'TSG-CERT-0007', 'issued_on' => gmdate('Y-m-d'), 'expires_on' => gmdate('Y-m-d', strtotime('+1 year')), 'verify_url' => 'https://techsgstudio.com/verify?id=TSG-CERT-0007']), 'certificate.issued', 1);
    $check(EmailMessages::certificateRejected($student + ['course_title' => 'Full Stack Web Development', 'remarks' => 'Attendance is below the requirement.']), 'certificate.rejected', 1);
    $check(EmailMessages::certificateRevoked($student + ['course_title' => 'Full Stack Web Development', 'certificate_id' => 'TSG-CERT-0007', 'reason' => 'Issued in error.']), 'certificate.revoked', 1);
    $contact = EmailMessages::contactReceived(['name' => 'Ayesha Khan', 'email' => 'visitor@example.test', 'phone' => '+92 300 1234567', 'subject' => 'Course enquiry', 'body' => 'I would like to know the fee for the live batch.', 'submitted_at' => gmdate('Y-m-d H:i:s')]);
    $check($contact, 'contact.received', 2);
    $contactRecipients = array_map(static fn(array $m): string => (string) $m['to'], $contact);
    $assert(in_array('visitor@example.test', $contactRecipients, true), 'The contact sender must receive an acknowledgement.');
    $assert(in_array(MailService::hrAddress(), $contactRecipients, true), 'The contact enquiry must reach the company inbox.');
    echo "templates: official email events rendered as HTML and plain text\n";

    /* ----------------------------------------------------------- 2b. the MIME envelope */
    // Getting the words right is only half of a message: the envelope has to be valid MIME or a
    // mail client shows the reader the base64 instead of the mail. This is the exact shape that
    // broke once, when the part headers were built from single-quoted strings and PHP put the
    // four characters \r\n on the wire as text, leaving each part with no header/body separator.
    $multipart = new ReflectionMethod(MailService::class, 'multipart');
    $multipart->setAccessible(true);
    [$boundary, $payload] = $multipart->invoke(new MailService(), 'Plain body with a Ünicode word.', '<p>HTML body with a Ünicode word.</p>');
    $assert(!str_contains($payload, '\\r\\n'), 'The MIME payload uses real CRLFs; a literal \\r\\n means no mail client can find a part header.');
    $sections = array_values(array_filter(array_slice(explode('--' . $boundary, $payload), 1), static fn(string $section): bool => trim($section, "\r\n-") !== ''));
    $assert(count($sections) === 2 && str_ends_with(rtrim($payload, "\r\n"), '--' . $boundary . '--'), 'The payload holds exactly two alternatives and closes its boundary.');
    foreach (['text/plain' => 'Plain body with a Ünicode word.', 'text/html' => '<p>HTML body with a Ünicode word.</p>'] as $type => $original) {
        $section = trim((string) array_shift($sections), "\r\n");
        $pieces = preg_split("/\r\n\r\n/", $section, 2) ?: [];
        $headerLines = explode("\r\n", (string) ($pieces[0] ?? ''));
        $assert(isset($pieces[1])
            && in_array('Content-Type: ' . $type . '; charset=UTF-8', $headerLines, true)
            && in_array('Content-Transfer-Encoding: base64', $headerLines, true),
            'The ' . $type . ' part declares its type and encoding on its own header lines.');
        $assert((string) base64_decode(trim((string) $pieces[1]), true) === $original, 'The ' . $type . ' body decodes back to exactly what was rendered.');
    }
    echo "mime: both alternatives are separated by real CRLFs and decode cleanly\n";

    /* ----------------------------------------------------------------- 3. notifications */
    $db = Database::connection();
    foreach (['notifications', 'notification_recipients', 'email_logs'] as $table) {
        $assert((bool) $db->query('SHOW TABLES LIKE ' . $db->quote($table))->fetchColumn(), $table . ' is missing; import database/migrations/001_initial_schema.sql.');
    }
    $studentId = 0;
    $adminId = 0;
    try {
        // Publishing with no actor of its own falls back to the senior admin, so a database that
        // has never had one - a scratch schema straight from the migration - needs an admin before
        // the fan-out can be asked for anything at all.
        $seniorAdmin = (int) ($db->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active' AND email_verified_at IS NOT NULL ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($seniorAdmin === 0) {
            $db->prepare("INSERT INTO users(full_name,email,password_hash,role,status,email_verified_at) VALUES('Notification Smoke Admin',?, 'unusable-hash','admin','active', UTC_TIMESTAMP())")
                ->execute(['mail-smoke-admin-' . bin2hex(random_bytes(6)) . '@example.test']);
            $adminId = (int) $db->lastInsertId();
        }
        $db->prepare("INSERT INTO users(full_name,email,password_hash,role,status,email_verified_at) VALUES(?,?,?,?, 'active', UTC_TIMESTAMP())")
            ->execute(['Notification Smoke Test', 'mail-smoke-' . bin2hex(random_bytes(6)) . '@example.test', 'unusable-hash', 'student']);
        $studentId = (int) $db->lastInsertId();
        $service = new NotificationService($db);
        $cases = [
            [NotificationService::ANNOUNCEMENT, 'announcement', 901, 'notifications'],
            [NotificationService::LESSON, 'lesson', 902, 'lessons'],
            [NotificationService::QUIZ, 'quiz', 903, 'quizzes'],
            [NotificationService::ASSIGNMENT, 'assignment', 904, 'assignments'],
            [NotificationService::PAYMENT, 'payment', 905, 'payments'],
            [NotificationService::COURSE, 'course', 906, 'courses'],
        ];
        foreach ($cases as [$category, $source, $sourceId, $area]) {
            $id = $service->notifyStudents([$studentId], $category, 'Smoke ' . $category, 'Body for ' . $category, $source, $sourceId, '/smoke', 0);
            $assert($id > 0, 'No notification id came back for ' . $category . '.');
        }
        $areas = $service->unreadByArea($studentId);
        foreach ($cases as [$category, $source, $sourceId, $area]) {
            $assert(($areas[$area] ?? 0) >= 1, 'The ' . $area . ' sidebar badge stayed at zero after a ' . $category . ' notification.');
        }
        $assert($service->unreadTotal($studentId) >= count($cases), 'The unread total does not match the fan-out.');

        // Re-publishing the same source within the coalescing window must refresh, not stack up.
        $before = (int) $db->query('SELECT COUNT(*) FROM notifications WHERE source_type = \'lesson\'')->fetchColumn();
        $service->notifyStudents([$studentId], NotificationService::LESSON, 'Smoke lesson', 'Updated body', 'lesson', 902, '/smoke', 0);
        $after = (int) $db->query('SELECT COUNT(*) FROM notifications WHERE source_type = \'lesson\'')->fetchColumn();
        $assert($after === $before, 'A repeated lesson update created a second notification instead of refreshing it.');

        $assert($service->forgetSource('lesson', [902]) === 1, 'forgetSource() did not retire the lesson notification.');
        $areas = $service->unreadByArea($studentId);
        $assert(($areas['lessons'] ?? 0) === 0, 'The lessons badge kept counting after the source was purged.');
        echo "notifications: 6 categories badge correctly, coalesce and purge work\n";
    } finally {
        if ($studentId > 0) {
            $db->prepare('DELETE FROM notification_recipients WHERE student_id=?')->execute([$studentId]);
        }
        $db->prepare('DELETE FROM notifications WHERE source_id IN (901,902,903,904,905,906) AND title LIKE ?')->execute(['Smoke %']);
        // The accounts answer foreign keys of their own, so they go last - the admin holds the
        // notifications it just published until those rows are gone.
        if ($studentId > 0) {
            $db->prepare('DELETE FROM users WHERE id=?')->execute([$studentId]);
        }
        if ($adminId > 0) {
            $db->prepare('DELETE FROM users WHERE id=?')->execute([$adminId]);
        }
    }
} catch (Throwable $exception) {
    $failures = 1;
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . "\n");
}

echo $failures === 0 ? "PASS: email and notification wiring is intact\n" : "FAILED\n";
exit($failures);
