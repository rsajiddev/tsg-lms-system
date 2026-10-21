<?php
declare(strict_types=1);
namespace App\Services;

final class MailService
{
    public function sendOtp(string $email, string $code, string $purpose): bool
    {
        $from = getenv('MAIL_FROM_ADDRESS') ?: '';
        $fromName = getenv('MAIL_FROM_NAME') ?: 'TSG Academy';
        $fromName = str_replace(["\r", "\n"], '', $fromName);
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL) || !function_exists('mail')) {
            error_log('[MAIL] OTP delivery is unavailable; configure the approved mail transport and sender.');
            return false;
        }

        $subject = $purpose === 'verify_email' ? 'Verify your TSG Academy account' : 'Reset your TSG Academy password';
        $message = "Your verification code is: {$code}\n\nThis code expires in 10 minutes and can be used once. If you did not request this, you can ignore this message.";
        $headers = 'From: ' . sprintf('"%s" <%s>', addcslashes($fromName, '"\\'), $from) . "\r\n";
        $sent = @mail($email, $subject, $message, $headers);
        if (!$sent) {
            error_log('[MAIL] OTP delivery failed for a recipient; the code was not logged.');
        }
        return $sent;
    }
}
