<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\Security;
use App\Core\View;
use PDO;
use RuntimeException;

final class NotificationController
{
    public function adminIndex(): void
    {
        $admin=AuthMiddleware::requireRole('admin');$db=Database::connection();
        $students=$db->query("SELECT id,full_name,email FROM users WHERE role='student' AND status='active' AND email_verified_at IS NOT NULL ORDER BY full_name")->fetchAll();
        $items=$db->query('SELECT n.id,n.title,n.body,n.audience,n.published_at,n.created_at,(SELECT COUNT(*) FROM notification_recipients nr WHERE nr.notification_id=n.id) AS recipient_count,(SELECT COUNT(*) FROM notification_replies r WHERE r.notification_id=n.id) AS reply_count FROM notifications n ORDER BY n.created_at DESC,n.id DESC LIMIT 100')->fetchAll();
        View::render('admin/notifications',['title'=>'Announcements','user'=>$admin,'students'=>$students,'notifications'=>$items,'message'=>$_SESSION['_flash']??null]);unset($_SESSION['_flash']);
    }

    public function publish(): void
    {
        $admin=AuthMiddleware::requireRole('admin');$this->csrf();$title=trim((string)($_POST['title']??''));$body=trim((string)($_POST['body']??''));$audience=(string)($_POST['audience']??'all_students');$selected=$_POST['student_ids']??[];
        if(mb_strlen($title)<3||mb_strlen($title)>200||mb_strlen($body)<2||mb_strlen($body)>10000||!in_array($audience,['all_students','selected_students'],true)||!is_array($selected))throw new RuntimeException('Enter a title, message, and valid recipient group.');
        foreach($selected as $item)if(!is_scalar($item))throw new RuntimeException('Recipient selection is invalid.');
        $ids=array_values(array_unique(array_filter(array_map('intval',$selected),static fn(int $id):bool=>$id>0)));if(count($ids)>500)throw new RuntimeException('Select no more than 500 students per announcement.');$db=Database::connection();$db->beginTransaction();
        try{
            if($audience==='selected_students'&&$ids===[])throw new RuntimeException('Select at least one verified student.');
            $insert=$db->prepare('INSERT INTO notifications(created_by,title,body,audience,published_at) VALUES(?,?,?,?,UTC_TIMESTAMP())');$insert->execute([(int)$admin['id'],$title,$body,$audience]);$notificationId=(int)$db->lastInsertId();
            if($audience==='all_students'){$db->prepare("INSERT INTO notification_recipients(notification_id,student_id) SELECT ?,id FROM users WHERE role='student' AND status='active' AND email_verified_at IS NOT NULL")->execute([$notificationId]);}
            else{
                $marks=implode(',',array_fill(0,count($ids),'?'));$lookup=$db->prepare("SELECT id FROM users WHERE role='student' AND status='active' AND email_verified_at IS NOT NULL AND id IN ($marks)");$lookup->execute($ids);$valid=array_map('intval',$lookup->fetchAll(PDO::FETCH_COLUMN));
                if(count($valid)!==count($ids))throw new RuntimeException('One or more selected accounts are not active verified students.');
                $recipient=$db->prepare('INSERT INTO notification_recipients(notification_id,student_id) VALUES(?,?)');foreach($valid as $studentId)$recipient->execute([$notificationId,$studentId]);
            }
            $db->prepare("INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,metadata) VALUES(?,'announcement.published','notification',?,JSON_OBJECT('audience',?,'recipients',?))")->execute([(int)$admin['id'],$notificationId,$audience,$audience==='all_students'?'all':count($ids)]);
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        $_SESSION['_flash']='Announcement published to its selected recipients.';$this->redirect('/admin/announcements');
    }

    public function studentIndex(): void
    {
        $student=AuthMiddleware::requireRole('student');$stmt=Database::connection()->prepare('SELECT n.id,n.title,n.body,n.published_at,nr.read_at FROM notification_recipients nr JOIN notifications n ON n.id=nr.notification_id WHERE nr.student_id=? AND n.published_at IS NOT NULL ORDER BY n.published_at DESC,n.id DESC LIMIT 100');$stmt->execute([(int)$student['id']]);
        View::render('notifications/index',['title'=>'My notifications','user'=>$student,'notifications'=>$stmt->fetchAll(),'message'=>$_SESSION['_flash']??null]);unset($_SESSION['_flash']);
    }

    public function show(): void
    {
        $user=$this->currentUser();$id=filter_input(INPUT_GET,'notification_id',FILTER_VALIDATE_INT)?:0;$db=Database::connection();
        $recipientId=(int)$user['id'];$recipients=[];
        if($user['role']==='student'){$check=$db->prepare('SELECT 1 FROM notification_recipients nr JOIN notifications n ON n.id=nr.notification_id WHERE nr.notification_id=? AND nr.student_id=? AND n.published_at IS NOT NULL');$check->execute([$id,$recipientId]);if(!$check->fetchColumn()){$this->notFound();return;}$db->prepare('UPDATE notification_recipients SET read_at=COALESCE(read_at,UTC_TIMESTAMP()) WHERE notification_id=? AND student_id=?')->execute([$id,$recipientId]);}
        $stmt=$db->prepare('SELECT n.id,n.title,n.body,n.published_at,u.full_name AS author_name FROM notifications n JOIN users u ON u.id=n.created_by WHERE n.id=? AND n.published_at IS NOT NULL');$stmt->execute([$id]);$notification=$stmt->fetch();if(!$notification){$this->notFound();return;}
        if($user['role']==='admin'){
            $recipientsStmt=$db->prepare('SELECT u.id,u.full_name,u.email FROM notification_recipients nr JOIN users u ON u.id=nr.student_id WHERE nr.notification_id=? ORDER BY u.full_name');$recipientsStmt->execute([$id]);$recipients=$recipientsStmt->fetchAll();
            $recipientId=filter_input(INPUT_GET,'student_id',FILTER_VALIDATE_INT)?:0;
            if($recipientId>0){$allowed=$db->prepare('SELECT 1 FROM notification_recipients WHERE notification_id=? AND student_id=?');$allowed->execute([$id,$recipientId]);if(!$allowed->fetchColumn()){$this->notFound();return;}}
            else $recipientId=0;
        }else{$recipientId=(int)$user['id'];}
        $replies=[];
        if($recipientId>0){$replyStmt=$db->prepare('SELECT r.id,r.message,r.created_at,u.full_name AS author_name,u.role AS author_role FROM notification_replies r JOIN users u ON u.id=r.student_id WHERE r.notification_id=? AND r.recipient_student_id=? ORDER BY r.created_at,r.id');$replyStmt->execute([$id,$recipientId]);$replies=$replyStmt->fetchAll();}
        $recipient=null;foreach($recipients as $item)if((int)$item['id']===$recipientId){$recipient=$item;break;}
        View::render('notifications/show',['title'=>'Announcement','user'=>$user,'notification'=>$notification,'replies'=>$replies,'recipients'=>$recipients,'recipient'=>$recipient,'message'=>$_SESSION['_flash']??null]);unset($_SESSION['_flash']);
    }

    public function reply(): void
    {
        $user=$this->currentUser();$this->csrf();$id=(int)($_POST['notification_id']??0);$message=trim((string)($_POST['message']??''));if($id<1||mb_strlen($message)<1||mb_strlen($message)>3000)throw new RuntimeException('Write a reply of up to 3,000 characters.');$db=Database::connection();
        $notification=$db->prepare('SELECT id FROM notifications WHERE id=? AND published_at IS NOT NULL');$notification->execute([$id]);if(!$notification->fetchColumn()){$this->notFound();return;}
        $recipientId=(int)$user['id'];
        if($user['role']==='admin'){$recipientId=(int)($_POST['recipient_student_id']??0);$scope=$db->prepare('SELECT 1 FROM notification_recipients WHERE notification_id=? AND student_id=?');$scope->execute([$id,$recipientId]);if($recipientId<1||!$scope->fetchColumn()){$this->notFound();return;}}
        else{$scope=$db->prepare('SELECT 1 FROM notification_recipients WHERE notification_id=? AND student_id=?');$scope->execute([$id,$recipientId]);if(!$scope->fetchColumn()){$this->notFound();return;}}
        $db->prepare('INSERT INTO notification_replies(notification_id,student_id,recipient_student_id,message) VALUES(?,?,?,?)')->execute([$id,(int)$user['id'],$recipientId,$message]);
        $db->prepare("INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id) VALUES(?,'notification.replied','notification',?)")->execute([(int)$user['id'],$id]);
        $_SESSION['_flash']='Reply added to this private conversation.';$this->redirect('/notifications/view?notification_id='.$id.($user['role']==='admin'?'&student_id='.$recipientId:''));
    }

    private function currentUser(): array{$session=$_SESSION['user']??null;if(!is_array($session)||!in_array($session['role']??'',['admin','student'],true))$this->redirect('/login');return AuthMiddleware::requireRole((string)$session['role']);}
    private function csrf(): void{if(!Security::verifyCsrf($_POST['_csrf']??null))throw new RuntimeException('Your session expired. Refresh and try again.');}
    private function notFound(): void{http_response_code(404);View::render('errors/404',['title'=>'Notification unavailable','user'=>$_SESSION['user']??[]]);}
    private function redirect(string $path): never{header('Location: '.rtrim((require BASE_PATH.'/config/app.php')['url'],'/').$path);exit;}
}
