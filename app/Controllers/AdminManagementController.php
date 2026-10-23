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
        'otp_lifetime_seconds' => ['OTP validity and resend wait (seconds)', 30, 3600],
        'otp_max_attempts' => ['OTP verification attempts', 1, 20],
        'login_max_attempts' => ['Login attempts before lockout', 1, 20],
        'login_lockout_minutes' => ['Login lockout window (minutes)', 1, 1440],
        'register_max_attempts' => ['Registration requests per window', 1, 50],
        'register_window_minutes' => ['Registration limit window (minutes)', 1, 1440],
        'password_reset_max_attempts' => ['Password reset requests per window', 1, 20],
        'password_reset_window_minutes' => ['Password reset limit window (minutes)', 1, 1440],
        'otp_resends_per_window' => ['OTP resend requests per window', 1, 20],
        'otp_resend_window_minutes' => ['OTP resend limit window (minutes)', 1, 1440],
    ];

    public function settings(): void
    {
        $admin=AuthMiddleware::requireRole('admin'); $db=Database::connection();
        try {
            $values=[]; foreach($db->query('SELECT setting_key,setting_value FROM app_settings')->fetchAll() as $row) $values[$row['setting_key']]=(int)$row['setting_value'];
            View::render('admin/settings',['title'=>'Security settings','user'=>$admin,'settings'=>self::SETTINGS,'values'=>$values,'message'=>$_SESSION['_flash']??null]);
        } catch (\PDOException $exception) {
            if ((string)$exception->getCode()!=='42S02') throw $exception;
            View::render('admin/settings',['title'=>'Security settings','user'=>$admin,'settings'=>[],'values'=>[],'message'=>null,'migrationRequired'=>true]);
        }
        unset($_SESSION['_flash']);
    }

    public function saveSettings(): void
    {
        $admin=AuthMiddleware::requireRole('admin');
        if(!Security::verifyCsrf($_POST['_csrf']??null)){http_response_code(419);return;}
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
