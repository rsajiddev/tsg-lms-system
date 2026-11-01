<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\Security;
use App\Core\View;
use App\Services\ChatService;

final class ChatController {
    public function studentIndex(): void {
        $user=AuthMiddleware::requireRole('student'); $chat=new ChatService(Database::connection());
        $threadId=(int)($_GET['thread_id']??0); if(!$threadId) $threadId=$chat->studentThread((int)$user['id']);
        $this->renderThread($user,$chat,$threadId);
    }
    public function adminIndex(): void {
        $user=AuthMiddleware::requireRole('admin'); $chat=new ChatService(Database::connection());
        $studentId=(int)($_GET['student_id']??0); $threadId=(int)($_GET['thread_id']??0);
        if(!$threadId && $studentId>0) $threadId=$chat->studentThread($studentId);
        $this->renderThread($user,$chat,$threadId);
    }
    public function send(): void {
        $session=$_SESSION['user']??[]; $role=(string)($session['role']??''); $user=AuthMiddleware::requireRole($role==='admin'?'admin':'student');
        $this->csrf(); $chat=new ChatService(Database::connection()); $isAdmin=$role==='admin';
        $threadId=(int)($_POST['thread_id']??0);
        if(!$threadId && !$isAdmin) $threadId=$chat->studentThread((int)$user['id']);
        if($isAdmin && !$threadId) { $studentId=(int)($_POST['student_id']??0); $threadId=$chat->studentThread($studentId); }
        $thread=$chat->thread($threadId,(int)$user['id'],$isAdmin); if(!$thread){ http_response_code(404); return; }
        $chat->send($threadId,(int)$user['id'],(string)($_POST['message']??''));
        $this->redirect(($isAdmin?'/admin/chats':'/student/chats').'?thread_id='.$threadId);
    }
    private function renderThread(array $user,ChatService $chat,int $threadId): void {
        $isAdmin=$user['role']==='admin'; $thread=$threadId?$chat->thread($threadId,(int)$user['id'],$isAdmin):null;
        if($thread) $chat->markSeen($threadId,$isAdmin);
        View::render('chats/thread',['title'=>'Chats','user'=>$user,'thread'=>$thread,'threads'=>$isAdmin?$chat->adminThreads():$chat->studentThreads((int)$user['id']),'messages'=>$thread?$chat->messages($threadId):[],'students'=>$isAdmin?$chat->students():[],'waitingTotal'=>$isAdmin?$chat->waitingCount():0]);
    }
    private function csrf(): void { if(!Security::verifyCsrf($_POST['_csrf']??null)) throw new \RuntimeException('Your session expired. Refresh and try again.'); }
    private function redirect(string $path): never { header('Location: '.rtrim((require BASE_PATH.'/config/app.php')['url'],'/').$path); exit; }
}
