<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class MailService
{
    public function sendOtp(string $email, string $code, string $purpose, int $validForSeconds = 600): bool
    {
        $host = trim((string) (getenv('MAIL_HOST') ?: ''));
        $port = (int) (getenv('MAIL_PORT') ?: 587);
        $username = (string) (getenv('MAIL_USERNAME') ?: '');
        $password = (string) (getenv('MAIL_PASSWORD') ?: '');
        $encryption = strtolower(trim((string) (getenv('MAIL_ENCRYPTION') ?: 'tls')));
        $from = trim((string) (getenv('MAIL_FROM_ADDRESS') ?: ''));
        $fromName = trim((string) (getenv('MAIL_FROM_NAME') ?: 'TSG Academy'));

        if ($host === '' || $username === '' || $password === '' || $from === ''
            || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || !filter_var($from, FILTER_VALIDATE_EMAIL)
            || !in_array($encryption, ['tls', 'ssl'], true)
            || $port < 1 || $port > 65535
        ) {
            error_log('[MAIL] SMTP is not configured with a valid host, credentials, encryption mode, and sender.');
            return false;
        }

        $subject = $purpose === 'verify_email'
            ? 'Verify your TSG Academy account'
            : 'Reset your TSG Academy password';
        $intro = $purpose === 'verify_email'
            ? 'Use this code to verify your TSG Academy account:'
            : 'Use this code to reset your TSG Academy password:';
        $body = $intro . "\r\n\r\n{$code}\r\n\r\nThis code expires in {$validForSeconds} seconds and can be used once. If you did not request this, you can ignore this message.";

        try {
            $this->deliver($host, $port, $username, $password, $encryption, $from, $fromName, $email, $subject, $body);
            return true;
        } catch (\Throwable $exception) {
            // Do not log the recipient, OTP, SMTP credentials, or server response.
            error_log('[MAIL] SMTP OTP delivery failed (' . $exception::class . '). Check MAIL_* settings and the local PHP error log.');
            return false;
        }
    }

    public function sendNewStudentAlert(string $studentName, string $studentEmail): bool
    {
        $adminEmail = trim((string) (getenv('MAIL_ADMIN_NOTIFICATION_ADDRESS') ?: ''));
        $host = trim((string) (getenv('MAIL_HOST') ?: ''));
        $port = (int) (getenv('MAIL_PORT') ?: 587);
        $username = (string) (getenv('MAIL_USERNAME') ?: '');
        $password = (string) (getenv('MAIL_PASSWORD') ?: '');
        $encryption = strtolower(trim((string) (getenv('MAIL_ENCRYPTION') ?: 'tls')));
        $from = trim((string) (getenv('MAIL_FROM_ADDRESS') ?: ''));
        $fromName = trim((string) (getenv('MAIL_FROM_NAME') ?: 'TSG Academy'));

        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL) || $host === '' || $username === '' || $password === ''
            || !filter_var($from, FILTER_VALIDATE_EMAIL) || !in_array($encryption, ['tls', 'ssl'], true)
            || $port < 1 || $port > 65535
        ) {
            error_log('[MAIL] New-student alert is unavailable; check MAIL_ADMIN_NOTIFICATION_ADDRESS and SMTP configuration.');
            return false;
        }

        $safeName = trim(str_replace(["\r", "\n"], '', $studentName));
        $safeEmail = trim(str_replace(["\r", "\n"], '', $studentEmail));
        try {
            $this->deliver(
                $host,
                $port,
                $username,
                $password,
                $encryption,
                $from,
                $fromName,
                $adminEmail,
                'New student account registration',
                "A student registration was submitted for TSG Academy.\r\n\r\nName: {$safeName}\r\nEmail: {$safeEmail}\r\n\r\nThe student must verify their email before signing in."
            );
            return true;
        } catch (\Throwable $exception) {
            error_log('[MAIL] New-student alert delivery failed (' . $exception::class . '). Check MAIL_* settings.');
            return false;
        }
    }

    public function sendPaymentSubmitted(string $studentEmail, string $studentName, string $courseTitle, string $amount, string $currency, string $reference): bool
    {
        $name = $this->mailSafeText($studentName);
        $course = $this->mailSafeText($courseTitle);
        $reference = $this->mailSafeText($reference);
        $summary = "Student: {$name}\r\nCourse: {$course}\r\nAmount: {$currency} {$amount}\r\nTransaction reference: {$reference}";
        $studentSent = $this->sendConfiguredEmail($studentEmail, 'Payment submission received', "Hello {$name},\r\n\r\nYour payment details for {$course} have been received and are awaiting verification. We will notify you when the review is complete.");
        $adminAddress = trim((string) (getenv('MAIL_ADMIN_NOTIFICATION_ADDRESS') ?: ''));
        $adminSent = $this->sendConfiguredEmail($adminAddress, 'Course payment awaiting review', "A student submitted a course payment for manual verification.\r\n\r\n{$summary}");
        return $studentSent && $adminSent;
    }

    public function sendPaymentDecision(string $studentEmail, string $studentName, string $courseTitle, string $amount, string $currency, bool $approved, string $note = ''): bool
    {
        $name = $this->mailSafeText($studentName);
        $course = $this->mailSafeText($courseTitle);
        $note = $this->mailSafeText($note);
        if ($approved) {
            $studentBody = "Hello {$name},\r\n\r\nYour payment of {$currency} {$amount} for {$course} has been verified. Course access is now active in your student dashboard.";
            $studentSubject = 'Payment verified — course access is active';
        } else {
            $studentBody = "Hello {$name},\r\n\r\nYour payment for {$course} could not be verified. You may submit corrected payment details from the course catalogue.";
            if ($note !== '') $studentBody .= "\r\n\r\nReview note: {$note}";
            $studentSubject = 'Update about your course payment';
        }
        $studentSent = $this->sendConfiguredEmail($studentEmail, $studentSubject, $studentBody);
        if (!$approved) return $studentSent;
        $responsibleAddress = trim((string) (getenv('MAIL_PAYMENT_APPROVAL_NOTIFICATION_ADDRESS') ?: ''));
        $adminBody = "A student course payment has been verified and course access granted.\r\n\r\nStudent: {$name}\r\nEmail: " . $this->mailSafeText($studentEmail) . "\r\nCourse: {$course}\r\nVerified amount: {$currency} {$amount}";
        $responsibleSent = $this->sendConfiguredEmail($responsibleAddress, 'Course payment verified and enrollment activated', $adminBody);
        return $studentSent && $responsibleSent;
    }

    private function sendConfiguredEmail(string $to, string $subject, string $body): bool
    {
        $host = trim((string) (getenv('MAIL_HOST') ?: ''));
        $port = (int) (getenv('MAIL_PORT') ?: 587);
        $username = (string) (getenv('MAIL_USERNAME') ?: '');
        $password = (string) (getenv('MAIL_PASSWORD') ?: '');
        $encryption = strtolower(trim((string) (getenv('MAIL_ENCRYPTION') ?: 'tls')));
        $from = trim((string) (getenv('MAIL_FROM_ADDRESS') ?: ''));
        $fromName = trim((string) (getenv('MAIL_FROM_NAME') ?: 'TSG Academy'));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || $host === '' || $username === '' || $password === ''
            || !filter_var($from, FILTER_VALIDATE_EMAIL) || !in_array($encryption, ['tls','ssl'], true)
            || $port < 1 || $port > 65535) {
            error_log('[MAIL] Transactional email is unavailable; check recipient and MAIL_* configuration.');
            return false;
        }
        try {
            $this->deliver($host, $port, $username, $password, $encryption, $from, $fromName, $to, $subject, $body);
            return true;
        } catch (\Throwable $exception) {
            error_log('[MAIL] Transactional email delivery failed (' . $exception::class . '). Check SMTP configuration.');
            return false;
        }
    }

    private function mailSafeText(string $value): string
    {
        return trim(str_replace(["\r", "\n", "\0"], ' ', $value));
    }

    private function deliver(
        string $host,
        int $port,
        string $username,
        string $password,
        string $encryption,
        string $from,
        string $fromName,
        string $to,
        string $subject,
        string $body
    ): void {
        $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $host,
                'SNI_enabled' => true,
            ],
        ]);
        $socket = @stream_socket_client(
            $transport . $host . ':' . $port,
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
            $this->command($socket, base64_encode($username), [334]);
            $this->command($socket, base64_encode($password), [235]);
            $this->command($socket, 'MAIL FROM:<' . $from . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);

            $safeFromName = str_replace(["\r", "\n"], '', $fromName);
            $encodedName = function_exists('mb_encode_mimeheader')
                ? mb_encode_mimeheader($safeFromName, 'UTF-8', 'B', "\r\n")
                : $safeFromName;
            $encodedSubject = function_exists('mb_encode_mimeheader')
                ? mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n")
                : $subject;
            $headers = [
                'Date: ' . date(DATE_RFC2822),
                'From: ' . $encodedName . ' <' . $from . '>',
                'To: <' . $to . '>',
                'Subject: ' . $encodedSubject,
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
            ];
            $normalizedBody = str_replace(["\r\n", "\r"], "\n", $body);
            $normalizedBody = str_replace("\n", "\r\n", $normalizedBody);
            $normalizedBody = preg_replace('/^\./m', '..', $normalizedBody) ?? $normalizedBody;
            $payload = implode("\r\n", $headers) . "\r\n\r\n" . $normalizedBody;
            if (!str_ends_with($payload, "\r\n")) $payload .= "\r\n";
            $this->write($socket, $payload . ".\r\n");
            $this->expect($socket, [250]);
            $this->write($socket, "QUIT\r\n");
        } finally {
            fclose($socket);
        }
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
