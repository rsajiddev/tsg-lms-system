<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\Security;
use App\Core\View;

final class AdminManagementController
{
    private const SETTINGS = [
        'otp_lifetime_seconds' => ['OTP validity and resend wait', 30, 3600, 'seconds', 'One shared interval: the current OTP expires and resend becomes available at the same time.'],
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
            'description' => 'Controls how long a verification code stays valid, how many wrong codes are tolerated, and how often a code can be resent.',
            'keys' => ['otp_lifetime_seconds', 'otp_max_attempts', 'otp_resends_per_window', 'otp_resend_window_minutes'],
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

    private const PAYMENT_SETTINGS = [
        'payment_account_title' => ['Account title', 150],
        'payment_bank_name' => ['Bank name', 100],
        'payment_account_number' => ['Account number', 80],
        'payment_iban' => ['IBAN', 80],
        'payment_wallet_name' => ['Mobile wallet name', 100],
        'payment_wallet_number' => ['Mobile wallet number', 80],
        'payment_instructions' => ['Payment instructions', 255],
    ];

    public function settings(): void
    {
        $admin=AuthMiddleware::requireRole('admin'); $db=Database::connection();
        try {
            $values=[]; $paymentValues=[];
            foreach($db->query('SELECT setting_key,setting_value FROM app_settings')->fetchAll() as $row) {
                if (isset(self::PAYMENT_SETTINGS[$row['setting_key']])) $paymentValues[$row['setting_key']]=$row['setting_value'];
                else $values[$row['setting_key']]=(int)$row['setting_value'];
            }
            View::render('admin/settings',['title'=>'Admin settings','user'=>$admin,'settings'=>self::SETTINGS,'groups'=>self::SETTING_GROUPS,'values'=>$values,'paymentValues'=>$paymentValues,'message'=>$_SESSION['_flash']??null]);
        } catch (\PDOException $exception) {
            if ((string)$exception->getCode()!=='42S02') throw $exception;
            View::render('admin/settings',['title'=>'Admin settings','user'=>$admin,'settings'=>[],'groups'=>[],'values'=>[],'paymentValues'=>[],'message'=>null,'migrationRequired'=>true]);
        }
        unset($_SESSION['_flash']);
    }

    public function saveSettings(): void
    {
        $admin=AuthMiddleware::requireRole('admin');
        if(!Security::verifyCsrf($_POST['_csrf']??null)){http_response_code(419);return;}
        if (($_POST['form_type'] ?? '') === 'payment_details') {
            $this->savePaymentSettings($admin);
        }
        $db=Database::connection(); $db->beginTransaction();
        try{
            $upsert=$db->prepare('INSERT INTO app_settings(setting_key,setting_value,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)');
            foreach(self::SETTINGS as $key=>[$label,$min,$max]){
                $raw=$_POST[$key]??''; if(!is_string($raw)||!ctype_digit($raw)||(int)$raw<$min||(int)$raw>$max) throw new \RuntimeException($label.' must be between '.$min.' and '.$max.'.');
                $upsert->execute([$key,$raw,(int)$admin['id']]);
            }
            $db->prepare("INSERT INTO audit_logs(actor_user_id,action,entity_type,metadata) VALUES(?,'security.settings_updated','app_settings',JSON_OBJECT('keys',?))")->execute([(int)$admin['id'],implode(',',array_keys(self::SETTINGS))]);
            $db->commit(); $_SESSION['_flash']='Security settings saved.';
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        header('Location: '.rtrim((require BASE_PATH.'/config/app.php')['url'],'/').'/admin/settings'); exit;
    }

    private function savePaymentSettings(array $admin): never
    {
        $db=Database::connection(); $db->beginTransaction();
        try {
            $upsert=$db->prepare('INSERT INTO app_settings(setting_key,setting_value,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)');
            $saved=[];
            foreach(self::PAYMENT_SETTINGS as $key=>[$label,$max]) {
                $value=trim((string)($_POST[$key]??''));
                if(mb_strlen($value)>$max) throw new \RuntimeException($label.' must be '.$max.' characters or fewer.');
                $upsert->execute([$key,$value,(int)$admin['id']]); $saved[]=$key;
            }
            $db->prepare("INSERT INTO audit_logs(actor_user_id,action,entity_type,metadata) VALUES(?,'payment.settings_updated','app_settings',JSON_OBJECT('keys',?))")->execute([(int)$admin['id'],implode(',',$saved)]);
            $db->commit(); $_SESSION['_flash']='Payment account details saved.';
        } catch(\Throwable $e) { if($db->inTransaction())$db->rollBack(); throw $e; }
        header('Location: '.rtrim((require BASE_PATH.'/config/app.php')['url'],'/').'/admin/settings'); exit;
    }

    public function reviews(): void
    {
        $admin=AuthMiddleware::requireRole('admin'); $rows=Database::connection()->query("SELECT id,reviewer_type,full_name,context_label,rating,content,status,created_at FROM reviews WHERE status='pending' ORDER BY created_at ASC,id ASC")->fetchAll();
        View::render('admin/reviews',['title'=>'Review moderation','user'=>$admin,'reviews'=>$rows,'message'=>$_SESSION['_flash']??null]); unset($_SESSION['_flash']);
    }

    public function reviewDecision(): void
    {
        $admin=AuthMiddleware::requireRole('admin');
        if(!Security::verifyCsrf($_POST['_csrf']??null)){http_response_code(419);return;}
        $id=(int)($_POST['review_id']??0); $decision=(string)($_POST['decision']??'');
        if($id<1||!in_array($decision,['approved','rejected'],true)){http_response_code(400);return;}
        $db=Database::connection(); $db->beginTransaction();
        try{$stmt=$db->prepare("UPDATE reviews SET status=? WHERE id=? AND status='pending'");$stmt->execute([$decision,$id]);
            if($stmt->rowCount()!==1) throw new \RuntimeException('This review is no longer pending.');
            $db->prepare('INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id) VALUES(?,?,\'review\',?)')->execute([(int)$admin['id'],'review.'.$decision,$id]);$db->commit();$_SESSION['_flash']='Review '.($decision==='approved'?'approved and visible on the homepage.':'rejected.');
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        header('Location: '.rtrim((require BASE_PATH.'/config/app.php')['url'],'/').'/admin/reviews');exit;
    }
}
