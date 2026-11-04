<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\AuthMiddleware;
use App\Core\Database;
use App\Core\PurgeGuard;
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
        // The desk can be read two ways: everything anybody has written, or only the conversations
        // whose newest message is a student waiting for an answer. The filter is a view choice, so it
        // travels back to the page in the link rather than being stored anywhere.
        $waitingOnly=($_GET['filter']??'')==='waiting';
        $this->renderThread($user,$chat,$threadId,$waitingOnly);
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
    /**
     * Clears the conversation for the side that asked, and only for that side. The messages stay in
     * the database because the other side is still reading them, so this is the one chat action that
     * can be offered to a student without any risk of destroying a record.
     */
    public function clear(): void {
        $session=$_SESSION['user']??[]; $role=(string)($session['role']??''); $user=AuthMiddleware::requireRole($role==='admin'?'admin':'student');
        $isAdmin=$role==='admin';
        try { $this->performClear($user,$isAdmin); }
        // An expired session or a stolen thread id should land the reader back on their own chat with
        // a reason, not on an error page they have to navigate out of.
        catch (\Throwable $exception) { $_SESSION['_flash_error']=$exception->getMessage(); $this->redirect(($isAdmin?'/admin/chats':'/student/chats')); }
    }

    private function performClear(array $user,bool $isAdmin): void {
        $this->csrf(); $chat=new ChatService(Database::connection());
        $threadId=(int)($_POST['thread_id']??0); $thread=$chat->thread($threadId,(int)$user['id'],$isAdmin);
        if(!$thread){ http_response_code(404); return; }
        $hidden=$chat->clear($threadId,$isAdmin?'admin':'student');
        if($isAdmin) $this->audit((int)$user['id'],'chat.cleared',$threadId,$hidden);
        $back=($isAdmin?'/admin/chats':'/student/chats').'?thread_id='.$threadId;
        if($hidden<1){ $_SESSION['_flash']='This chat has nothing to clear.'; $this->redirect($back); }
        // Saying what was not done matters as much as the count, because the other side of the
        // conversation cannot tell a clear from a deletion.
        $_SESSION['_flash']='Cleared for you. '.$hidden.' message'.$this->plural($hidden).' are hidden on this side only, '
            .($isAdmin?'the student':'the Academy').' still reads them, and a new message brings this chat back here too.';
        $this->redirect($back);
    }
    /**
     * The one permanent action on a conversation, kept on the administrator's side and behind the
     * same typed phrase as every other delete tool. The thread row survives, so the student's next
     * message opens a clean page instead of a broken link.
     */
    public function deleteThread(): void {
        $user=AuthMiddleware::requireRole('admin');
        try { $this->performDeleteThread($user); }
        catch (\Throwable $exception) { $_SESSION['_flash_error']=$exception->getMessage(); $this->redirect('/admin/chats'); }
    }

    private function performDeleteThread(array $user): void {
        $this->csrf();
        PurgeGuard::assertConfirmed('DELETE THIS CONVERSATION');
        $chat=new ChatService(Database::connection()); $threadId=(int)($_POST['thread_id']??0);
        $thread=$chat->thread($threadId,(int)$user['id'],true); if(!$thread){ http_response_code(404); return; }
        $deleted=$chat->deleteMessages($threadId);
        $this->audit((int)$user['id'],'chat.messages_deleted',$threadId,$deleted);
        $_SESSION['_flash']=$deleted<1
            ? 'This conversation had no messages left to delete.'
            : $deleted.' message'.$this->plural($deleted).' were deleted from this conversation for both sides. The student can still write, and the next message starts a clean page.';
        $this->redirect('/admin/chats?thread_id='.$threadId);
    }
    private function renderThread(array $user,ChatService $chat,int $threadId,bool $waitingOnly=false): void {
        $isAdmin=$user['role']==='admin'; $side=$isAdmin?'admin':'student'; $thread=$threadId?$chat->thread($threadId,(int)$user['id'],$isAdmin):null;
        if($thread) $chat->markSeen($threadId,$isAdmin);
        // The two actions above report in a flash, so the page has to carry it the way every other
        // page here does: read it, hand it to the view, and take it out of the session.
        View::render('chats/thread',['title'=>'Chats','user'=>$user,'thread'=>$thread,'threads'=>$isAdmin?$chat->adminThreads($waitingOnly):$chat->studentThreads((int)$user['id']),'messages'=>$thread?$chat->messages($threadId,$side):[],'students'=>$isAdmin?$chat->students():[],'waitingTotal'=>$isAdmin?$chat->waitingCount():0,'waitingFilter'=>$isAdmin&&$waitingOnly,'message'=>$_SESSION['_flash']??null,'error'=>$_SESSION['_flash_error']??null]);
        unset($_SESSION['_flash'],$_SESSION['_flash_error']);
    }
    /** Who cleared what is worth one line in the log, and a log that will not take it is not a reason
        to tell the administrator their desk still holds the conversation. */
    private function audit(int $adminId,string $action,int $threadId,int $count): void {
        try {
            Database::connection()->prepare("INSERT INTO audit_logs(actor_user_id,action,entity_type,metadata) VALUES(?,?,'chat_thread',JSON_OBJECT('thread_id',?,'messages',?))")
                ->execute([$adminId,$action,$threadId,$count]);
        } catch (\Throwable $exception) {
            error_log('[CHATS] '.$action.' was not audited: '.$exception->getMessage());
        }
    }
    /** One message is a message, two are messages - and a count that reads wrong is a count nobody
        trusts. */
    private function plural(int $count): string { return $count===1?'':'s'; }
    private function csrf(): void { if(!Security::verifyCsrf($_POST['_csrf']??null)) throw new \RuntimeException('Your session expired. Refresh and try again.'); }
    private function redirect(string $path): never { header('Location: '.rtrim((require BASE_PATH.'/config/app.php')['url'],'/').$path); exit; }
}
