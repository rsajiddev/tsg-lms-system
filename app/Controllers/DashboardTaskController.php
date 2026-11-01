<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\Security;

final class DashboardTaskController
{
    private function user(): array { $role=(string)($_SESSION['user']['role']??''); return AuthMiddleware::requireRole(in_array($role,['student','admin'],true)?$role:'student'); }
    private function checkCsrf(): void { if(!Security::verifyCsrf($_POST['_csrf']??null)) throw new \RuntimeException('Your session expired. Refresh and try again.'); }
    private function back(): never { header('Location: '.rtrim((require BASE_PATH.'/config/app.php')['url'],'/').'/dashboard'); exit; }
    public function add(): void { $u=$this->user();$this->checkCsrf();$title=trim((string)($_POST['title']??''));if($title!==''&&mb_strlen($title)<=240)Database::connection()->prepare('INSERT INTO dashboard_tasks(user_id,title) VALUES(?,?)')->execute([(int)$u['id'],$title]);$this->back(); }
    public function toggle(): void { $u=$this->user();$this->checkCsrf();$db=Database::connection();$id=(int)($_POST['task_id']??0);$stmt=$db->prepare('SELECT is_done FROM dashboard_tasks WHERE id=? AND user_id=?');$stmt->execute([$id,(int)$u['id']]);$current=$stmt->fetchColumn();if($current!==false){$done=(int)$current===0?1:0;$db->prepare('UPDATE dashboard_tasks SET is_done=?, reminder_at=IF(?=1,NULL,reminder_at), reminder_sent_at=IF(?=1,NULL,reminder_sent_at) WHERE id=? AND user_id=?')->execute([$done,$done,$done,$id,(int)$u['id']]);}$this->back(); }
    public function delete(): void { $u=$this->user();$this->checkCsrf();Database::connection()->prepare('DELETE FROM dashboard_tasks WHERE id=? AND user_id=?')->execute([(int)($_POST['task_id']??0),(int)$u['id']]);$this->back(); }
    public function reminder(): void { $u=$this->user();$this->checkCsrf();$id=(int)($_POST['task_id']??0);$value=trim((string)($_POST['reminder_at']??''));$timestamp=$value===''?false:strtotime($value);if($timestamp!==false&&$timestamp>time()){$utc=gmdate('Y-m-d H:i:s',$timestamp);Database::connection()->prepare('UPDATE dashboard_tasks SET reminder_at=?,reminder_sent_at=NULL WHERE id=? AND user_id=? AND is_done=0')->execute([$utc,$id,(int)$u['id']]);}$this->back(); }
}
