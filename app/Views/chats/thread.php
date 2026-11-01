<?php
use App\Core\Security;
$baseUrl=rtrim((require BASE_PATH.'/config/app.php')['url'],'/'); $e=static fn($v)=>Security::escape((string)$v);
$isAdmin=($user['role']??'')==='admin'; $thread=is_array($thread??null)?$thread:null; $messages=is_array($messages??null)?$messages:[]; $threads=is_array($threads??null)?$threads:[]; $students=is_array($students??null)?$students:[];
$stamp=static fn($v)=>$v?date('M j · g:i A',strtotime($v)):'';
?>
<section class="dashboard-page chat-page">
 <div class="dashboard-topbar"><button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button><div class="dashboard-breadcrumb"><a href="<?= $e($baseUrl) ?>/dashboard">Workspace</a><i>/</i><strong>Chats</strong></div></div>
 <header class="dashboard-heading"><div><p class="eyebrow">PRIVATE MESSAGES</p><h1>Chats</h1><p><?= $isAdmin ? 'Select a student and send a private message.' : 'Message the Academy team directly and privately.' ?></p></div></header>
 <div class="professional-chat-layout">
  <aside class="professional-chat-list"><div class="professional-chat-list-head"><strong><?= $isAdmin?'Students':'Academy team' ?></strong><?php if($isAdmin): ?><form method="get" action="<?= $e($baseUrl) ?>/admin/chats"><select name="student_id" onchange="this.form.submit()"><option value="">Select student</option><?php foreach($students as $student): ?><option value="<?= (int)$student['id'] ?>" <?= (int)($thread['student_id']??0)===(int)$student['id']?'selected':'' ?>><?= $e($student['full_name']) ?></option><?php endforeach ?></select></form><?php endif ?></div>
   <?php if(!$isAdmin): ?><a class="professional-chat-contact<?= $thread?' is-active':'' ?>" href="<?= $e($baseUrl) ?>/student/chats"><span class="conversation-avatar conversation-avatar-academy">TSG</span><span><strong>The Academy</strong><small>Private support chat</small></span></a><?php endif ?>
   <?php foreach($threads as $item): if(!$isAdmin) continue; ?><a class="professional-chat-contact<?= (int)($thread['id']??0)===(int)$item['id']?' is-active':'' ?>" href="<?= $e($baseUrl) ?>/admin/chats?thread_id=<?= (int)$item['id'] ?>"><span class="conversation-avatar"><?= $e(mb_strtoupper(mb_substr((string)$item['student_name'],0,1))) ?></span><span><strong><?= $e($item['student_name']) ?></strong><small><?= $e(mb_strimwidth((string)$item['last_message'],0,44,'…')) ?></small></span></a><?php endforeach ?></aside>
  <main class="professional-chat-main">
   <?php if(!$thread): ?><div class="professional-chat-empty"><h2>Select a student</h2><p>Choose a student from the list to start a private conversation.</p></div><?php else: ?>
    <header class="professional-chat-head"><span class="conversation-avatar<?= $isAdmin?'':' conversation-avatar-academy' ?>"><?= $e($isAdmin?mb_strtoupper(mb_substr((string)$thread['student_name'],0,1)):'TSG') ?></span><div><strong><?= $e($isAdmin?$thread['student_name']:'The Academy') ?></strong><small><?= $isAdmin?$e($thread['student_email']):'Private conversation' ?></small></div></header>
    <div class="professional-chat-feed" id="chat-feed"><?php if($messages===[]): ?><div class="professional-chat-empty"><h2>Start the conversation</h2><p>Send a message to begin this private chat.</p></div><?php endif; foreach($messages as $message): $mine=(int)$message['author_id']===(int)$user['id']; ?><div class="professional-message<?= $mine?' is-mine':'' ?>"><div class="professional-bubble"><?= nl2br($e($message['message'])) ?></div><time><?= $e($stamp($message['created_at'])) ?></time></div><?php endforeach ?></div>
    <form method="post" action="<?= $e($baseUrl) ?>/chats/send" class="professional-composer"><input type="hidden" name="_csrf" value="<?= $e(Security::csrfToken()) ?>"><input type="hidden" name="thread_id" value="<?= (int)$thread['id'] ?>"><textarea name="message" rows="2" maxlength="3000" required placeholder="Type your message..."></textarea><button class="button professional-send" type="submit">Send <span aria-hidden="true">➜</span></button></form>
   <?php endif ?></main>
 </div>
</section>
<script>(()=>{const feed=document.getElementById('chat-feed');if(feed)feed.scrollTop=feed.scrollHeight})()</script>
