<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\Security;
use App\Core\View;

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
            View::render('admin/settings', ['title' => 'Admin settings', 'user' => $admin, 'settings' => self::SETTINGS, 'groups' => self::SETTING_GROUPS, 'values' => $values, 'message' => $_SESSION['_flash'] ?? null]);
        } catch (\PDOException $exception) {
            if ((string)$exception->getCode() !== '42S02') throw $exception;
            View::render('admin/settings', ['title' => 'Admin settings', 'user' => $admin, 'settings' => [], 'groups' => [], 'values' => [], 'message' => null, 'migrationRequired' => true]);
        }
        unset($_SESSION['_flash']);
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
        $rows = Database::connection()->query("SELECT id,reviewer_type,full_name,context_label,rating,content,status,created_at FROM reviews WHERE status='pending' ORDER BY created_at ASC,id ASC")->fetchAll();
        View::render('admin/reviews', ['title' => 'Review moderation', 'user' => $admin, 'reviews' => $rows, 'message' => $_SESSION['_flash'] ?? null]);
        unset($_SESSION['_flash']);
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
