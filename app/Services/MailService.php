<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * Sends every transactional email in the platform and records the attempt in email_logs.
 *
 * Configuration comes from the environment only, so a client installs the platform by
 * filling in the MAIL_* block: SMTP host/port/encryption, the mailbox and app password,
 * the display name, the sender address and the internal notification inboxes. Nothing in
 * this class hardcodes a personal address — unset notification inboxes fall back to the
 * sender address, so a single credential pair still reaches the right people.
 */
final class MailService
{
    /** Developer revenue-share percentage quoted in internal emails. */
    public const DEVELOPER_SHARE_PERCENT = 15;

    private ?PDO $resolved = null;

    public function __construct(private ?PDO $db = null)
    {
    }

    /* ------------------------------------------------------- event entry points */

    /**
     * One-time code for email verification, password reset or an email-address change.
     * The account id is optional but worth passing: it is what lets the admin email log,
     * and any later cleanup, tie the message back to one student instead of one address.
     */
    public function sendOtp(string $email, string $code, string $purpose, int $validForSeconds = 600, string $name = '', int $studentId = 0): bool
    {
        $messages = EmailMessages::otp($purpose, $name, $code, max(1, (int) ceil($validForSeconds / 60)));
        $messages[0]['to'] = $email;
        $messages[0]['student'] = $studentId;
        return $this->sendAll($messages);
    }

    /** Internal alert that a student completed registration, before any payment exists. */
    public function sendNewStudentAlert(string $studentName, string $studentEmail, int $studentId = 0): bool
    {
        return $this->sendAll(EmailMessages::registration([
            'student_id' => $studentId,
            'student_name' => $studentName,
            'student_email' => $studentEmail,
        ]));
    }

    /** Payment approval request received: student, HR/admin and developer each get a message. */
    public function sendPaymentSubmitted(array $payment): bool
    {
        return $this->sendAll(EmailMessages::paymentSubmitted($payment));
    }

    /** Admin decision: approval mails student, approving admin and developer; rejection mails the student. */
    public function sendPaymentDecision(array $payment): bool
    {
        return $this->sendAll(EmailMessages::paymentDecision($payment));
    }

    /** Monthly revenue summary with the per-student share breakdown. */
    public function sendDeveloperRevenueReport(array $report): bool
    {
        return $this->sendAll(EmailMessages::monthlyRevenue($report));
    }

    /** Certificate request raised by a student, sent to the certificate inbox. */
    public function sendCertificateRequest(array $request): bool
    {
        return $this->sendAll(EmailMessages::certificateRequest($request));
    }

    /** Certificate issued and waiting in the student workspace. */
    public function sendCertificateIssued(array $certificate): bool
    {
        return $this->sendAll(EmailMessages::certificateIssued($certificate));
    }

    /** Certificate request rejected, with the reason the student must correct. */
    public function sendCertificateRejected(array $certificate): bool
    {
        return $this->sendAll(EmailMessages::certificateRejected($certificate));
    }

    /**
     * Sends one event's messages in order. A message with no recipient is skipped: that
     * is how optional copies (for example the approving admin's record) stay silent when
     * the address is not configured, without reporting a failure to the caller.
     */
    private function sendAll(array $messages): bool
    {
        $delivered = true;
        $seen = [];
        foreach ($messages as $message) {
            if (!is_array($message)) continue;
            $to = trim((string) ($message['to'] ?? ''));
            if ($to === '') continue;
            $key = $to . '|' . (string) ($message['subject'] ?? '');
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $delivered = $this->send($to, (string) ($message['subject'] ?? ''), (array) ($message['view'] ?? []), (string) ($message['template'] ?? 'unknown'), isset($message['student']) ? (int) $message['student'] : null) && $delivered;
        }
        return $delivered;
    }

    /* ------------------------------------------------------- recipients & formatting */

    public static function hrAddress(): string
    {
        return self::address(['MAIL_ADMIN_NOTIFICATION_ADDRESS', 'MAIL_FROM_ADDRESS']);
    }

    /**
     * The developer inbox, named after the environment key so there is one place to look.
     * Blank means the company's own sending mailbox already receives these alerts.
     */
    public static function developerAddress(): string
    {
        return self::address(['DEVELOPER_NOTIFICATION_MAIL_ADDRESS', 'MAIL_ADMIN_NOTIFICATION_ADDRESS', 'MAIL_FROM_ADDRESS']);
    }

    public static function certificateAddress(): string
    {
        return self::address(['MAIL_CERTIFICATE_REQUEST_ADDRESS', 'MAIL_ADMIN_NOTIFICATION_ADDRESS', 'MAIL_FROM_ADDRESS']);
    }

    /** Greeting used in developer-facing mail; the name lives in the environment. */
    public static function developerGreeting(): string
    {
        $name = trim((string) (getenv('MAIL_DEVELOPER_NAME') ?: ''));
        return 'Dear ' . ($name !== '' ? $name : 'Development Partner') . ',';
    }

    /** Display form of a student account id, matching the ID shown in the dashboards. */
    public static function studentId(int $studentId): string
    {
        return self::accountReference($studentId, 'student');
    }

    /**
     * The ID to print for an account. It reads the stored reference, so a student sees the same
     * number in an email that they see on their settings page and verify their certificate with;
     * an account that predates the column still falls back to the old derived form.
     */
    public static function accountReference(int $userId, string $role = 'student'): string
    {
        return AccountIdentity::reference($userId, $role);
    }

    /** Amount wording used in email bodies: "Rs 45,000" locally, currency codes elsewhere. */
    public static function money(float $amount, string $currency): string
    {
        $code = strtoupper(trim($currency));
        $label = match ($code) {
            'PKR' => 'Rs ',
            '' => '',
            default => $code . ' ',
        };
        return $label . number_format($amount, $amount < 1000 && $amount != (int) $amount ? 2 : 0);
    }

    private static function address(array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string) (getenv($key) ?: ''));
            if (filter_var($value, FILTER_VALIDATE_EMAIL)) return $value;
        }
        return '';
    }

    /* ---------------------------------------------------------------------- internals */

    /**
     * Renders the view as HTML with a plain-text alternative and hands it to SMTP.
     * Every attempt is recorded in email_logs so delivery problems are visible in the
     * admin workspace instead of only in the PHP error log.
     */
    private function send(string $to, string $subject, array $view, string $template, ?int $relatedUserId = null): bool
    {
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->log($template, $to, $subject, 'failed', 'No valid recipient address is configured.', $relatedUserId);
            return false;
        }
        $config = $this->config();
        if ($config['error'] !== '') {
            $this->log($template, $to, $subject, 'failed', $config['error'], $relatedUserId);
            return false;
        }
        $safeSubject = $this->oneLine($subject);
        try {
            $html = EmailLayout::html($safeSubject, $this->sanitizeView($view));
            $text = EmailLayout::text($safeSubject, $this->sanitizeView($view));
            [$boundary, $payload] = $this->multipart($text, $html);
            $this->deliver($config, $to, $safeSubject, $boundary, $payload);
            $this->log($template, $to, $safeSubject, 'sent', '', $relatedUserId);
            return true;
        } catch (\Throwable $exception) {
            // Never log the recipient, message body, OTP or SMTP credentials.
            $this->log($template, $to, $safeSubject, 'failed', $this->oneLine($exception->getMessage()), $relatedUserId);
            error_log('[MAIL] Delivery failed for template "' . $template . '" (' . $exception::class . '). Check MAIL_* settings and the PHP error log.');
            return false;
        }
    }

    private function config(): array
    {
        $host = trim((string) (getenv('MAIL_HOST') ?: ''));
        $port = (int) (getenv('MAIL_PORT') ?: 587);
        $username = (string) (getenv('MAIL_USERNAME') ?: '');
        $password = (string) (getenv('MAIL_PASSWORD') ?: '');
        $encryption = strtolower(trim((string) (getenv('MAIL_ENCRYPTION') ?: 'tls')));
        $from = trim((string) (getenv('MAIL_FROM_ADDRESS') ?: ''));
        $fromName = trim((string) (getenv('MAIL_FROM_NAME') ?: EmailLayout::company()['short']));
        $replyTo = trim((string) (getenv('MAIL_REPLY_TO_ADDRESS') ?: ''));
        $error = '';
        if ($host === '' || $username === '' || $password === '') $error = 'SMTP is not configured; set MAIL_HOST, MAIL_USERNAME and MAIL_PASSWORD.';
        elseif (!filter_var($from, FILTER_VALIDATE_EMAIL)) $error = 'MAIL_FROM_ADDRESS is not a valid email address.';
        elseif (!in_array($encryption, ['tls', 'ssl', 'none'], true)) $error = 'MAIL_ENCRYPTION must be tls, ssl or none.';
        elseif ($port < 1 || $port > 65535) $error = 'MAIL_PORT is out of range.';
        elseif ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $error = 'MAIL_REPLY_TO_ADDRESS is not a valid email address.';
        return [
            'error' => $error,
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
            'encryption' => $encryption,
            'from' => $from,
            'from_name' => $fromName !== '' ? $fromName : EmailLayout::company()['short'],
            'reply_to' => $replyTo,
        ];
    }

    /** HTML plus plain-text alternative as one MIME payload; returns its boundary too. */
    private function multipart(string $text, string $html): array
    {
        $boundary = 'tsg-' . bin2hex(random_bytes(12));
        // Every line break in a MIME payload has to be a real CRLF. The part headers below used to
        // be single-quoted strings, so PHP sent the four characters \r\n as literal text: a mail
        // client then found no blank line separating a part's headers from its body, treated the
        // whole part as header noise and displayed the base64 instead of decoding it.
        $part = static fn(string $type, string $body): string => '--' . $boundary . "\r\n"
            . 'Content-Type: ' . $type . '; charset=UTF-8' . "\r\n"
            . 'Content-Transfer-Encoding: base64' . "\r\n"
            . "\r\n"
            . chunk_split(base64_encode($body), 76, "\r\n") . "\r\n";
        return [$boundary, $part('text/plain', $text) . $part('text/html', $html) . '--' . $boundary . '--' . "\r\n"];
    }

    /** Strips newlines from every string in a view so no value can inject headers. */
    private function sanitizeView(array $view): array
    {
        array_walk_recursive($view, function (&$value): void {
            if (is_string($value)) $value = $this->oneLine($value);
        });
        return $view;
    }

    private function oneLine(string $value): string
    {
        return trim(str_replace(["\r", "\n", "\0"], ' ', $value));
    }

    /** Inserts one email_logs row. Logging must never break delivery. */
    private function log(string $template, string $to, string $subject, string $status, string $error, ?int $relatedUserId = null): void
    {
        try {
            $db = $this->db();
            $db->prepare('INSERT INTO email_logs(template_key,recipient_email,subject,status,error_message,related_user_id) VALUES(?,?,?,?,?,?)')
                ->execute([substr($template, 0, 64), substr($this->oneLine($to), 0, 190), substr($this->oneLine($subject), 0, 200), $status, $error !== '' ? substr($error, 0, 255) : null, $relatedUserId !== null && $relatedUserId > 0 ? $relatedUserId : null]);
        } catch (\Throwable $exception) {
            error_log('[MAIL] email_logs write failed (' . $exception::class . ').');
        }
    }

    private function db(): PDO
    {
        if ($this->resolved instanceof PDO) return $this->resolved;
        $this->resolved = $this->db ?? \App\Core\Database::connection();
        return $this->resolved;
    }

    private function deliver(array $config, string $to, string $subject, string $boundary, string $body): void
    {
        $encryption = (string) $config['encryption'];
        $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => (string) $config['host'],
                'SNI_enabled' => true,
            ],
        ]);
        $socket = @stream_socket_client(
            $transport . $config['host'] . ':' . (int) $config['port'],
            $errorNumber,
            $errorMessage,
            15,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!is_resource($socket)) {
            throw new RuntimeException('SMTP connection could not be opened.');
        }

        try {
            stream_set_timeout($socket, 15);
            $this->expect($socket, [220]);
            $hello = preg_replace('/[^A-Za-z0-9.-]/', '', gethostname() ?: 'localhost') ?: 'localhost';
            $this->command($socket, 'EHLO ' . $hello, [250]);

            if ($encryption === 'tls') {
                $this->command($socket, 'STARTTLS', [220]);
                $cryptoEnabled = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($cryptoEnabled !== true) {
                    throw new RuntimeException('SMTP TLS negotiation failed.');
                }
                $this->command($socket, 'EHLO ' . $hello, [250]);
            }

            $this->command($socket, 'AUTH LOGIN', [334]);
            $this->command($socket, base64_encode((string) $config['username']), [334]);
            $this->command($socket, base64_encode((string) $config['password']), [235]);
            $this->command($socket, 'MAIL FROM:<' . $config['from'] . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);

            $encodedName = $this->headerText((string) $config['from_name']);
            $headers = [
                'Date: ' . date(DATE_RFC2822),
                'From: ' . $encodedName . ' <' . $config['from'] . '>',
                'To: <' . $to . '>',
                'Subject: ' . $this->headerText($subject),
                'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (preg_replace('/^smtp\./', '', (string) $config['host']) ?: 'localhost') . '>',
                'MIME-Version: 1.0',
                'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
                'Auto-Submitted: auto-generated',
                'X-Mailer: TSG Academy LMS',
            ];
            if ((string) $config['reply_to'] !== '') {
                array_splice($headers, 4, 0, ['Reply-To: <' . $config['reply_to'] . '>']);
            }
            $payload = implode("\r\n", $headers) . "\r\n\r\n" . $body;
            if (!str_ends_with($payload, "\r\n")) $payload .= "\r\n";
            $this->write($socket, $payload . ".\r\n");
            $this->expect($socket, [250]);
            $this->write($socket, "QUIT\r\n");
        } finally {
            fclose($socket);
        }
    }

    private function headerText(string $value): string
    {
        $clean = str_replace(["\r", "\n"], '', $value);
        return function_exists('mb_encode_mimeheader')
            ? mb_encode_mimeheader($clean, 'UTF-8', 'B', "\r\n")
            : $clean;
    }

    private function command($socket, string $command, array $expectedCodes): void
    {
        $this->write($socket, $command . "\r\n");
        $this->expect($socket, $expectedCodes);
    }

    private function write($socket, string $data): void
    {
        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $result = fwrite($socket, substr($data, $written));
            if ($result === false || $result === 0) {
                throw new RuntimeException('SMTP write failed.');
            }
            $written += $result;
        }
    }

    private function expect($socket, array $expectedCodes): string
    {
        $response = '';
        do {
            $line = fgets($socket, 2048);
            if ($line === false) {
                throw new RuntimeException('SMTP response timed out or connection closed.');
            }
            $response .= $line;
            $status = stream_get_meta_data($socket);
            if (!empty($status['timed_out'])) {
                throw new RuntimeException('SMTP response timed out.');
            }
        } while (strlen($line) >= 4 && $line[3] === '-');

        $code = (int) substr($line, 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            throw new RuntimeException('SMTP server rejected a command.');
        }
        return $response;
    }
}
