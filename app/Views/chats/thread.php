<?php
use App\Core\Security;
$baseUrl=rtrim((require BASE_PATH.'/config/app.php')['url'],'/'); $e=static fn($v)=>Security::escape((string)$v);
$isAdmin=($user['role']??'')==='admin'; $thread=is_array($thread??null)?$thread:null; $messages=is_array($messages??null)?$messages:[]; $threads=is_array($threads??null)?$threads:[]; $students=is_array($students??null)?$students:[];
$waitingTotal=(int)($waitingTotal??0); $waitingFilter=(bool)($waitingFilter??false);
$stamp=static fn($v)=>$v?date('M j · g:i A',strtotime($v)):'';
$dots='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/></svg>';
$smile='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9.2"/><path d="M8.6 14.4c.9 1.1 2.1 1.7 3.4 1.7s2.5-.6 3.4-1.7"/><path d="M9.2 9.6h.01"/><path d="M14.8 9.6h.01"/></svg>';
/* The picker is a fixed grid of forty-eight rather than a library: the page stays dependency-free,
   and a chat with a school does not need a thousand glyphs. They are written as code points so this
   file stays plain ASCII - a variation selector lost in editing would leave a box nobody can read. */
$emojiGroups=[
    'Reactions'=>["\u{1F44D}","\u{1F44E}","\u{1F64F}","\u{1F44F}","\u{1F91D}","\u{1F4AA}","\u{2728}","\u{1F389}","\u{2764}\u{FE0F}","\u{2B50}","\u{1F525}","\u{1F64C}"],
    'Faces'=>["\u{1F600}","\u{1F604}","\u{1F642}","\u{1F609}","\u{1F60A}","\u{1F60D}","\u{1F914}","\u{1F605}","\u{1F634}","\u{1F622}","\u{1F621}","\u{1F92F}"],
    'Study and work'=>["\u{1F4DA}","\u{270D}\u{FE0F}","\u{1F3AF}","\u{23F0}","\u{1F4C5}","\u{1F4A1}","\u{1F50D}","\u{1F4DE}","\u{1F4E7}","\u{1F4C4}","\u{1F4BB}","\u{2615}"],
    'The Academy'=>["\u{1F393}","\u{1F9D1}\u{200D}\u{1F3EB}","\u{1F9E0}","\u{1F3C6}","\u{2705}","\u{274C}","\u{26A0}\u{FE0F}","\u{1F4CC}","\u{1F514}","\u{1F4AC}","\u{1F5C2}\u{FE0F}","\u{1F680}"],
];
?>
<section class="dashboard-page chat-page">
 <div class="dashboard-topbar"><button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button><div class="dashboard-breadcrumb"><a href="<?= $e($baseUrl) ?>/dashboard">Workspace</a><i>/</i><strong>Chats</strong></div></div>
 <header class="dashboard-heading"><div><p class="eyebrow">PRIVATE MESSAGES</p><h1>Chats</h1><p><?= $isAdmin ? 'Select a student and send a private message.' : 'Message the Academy team directly and privately.' ?></p></div></header>
 <?php /* A clear or a delete reports itself the way the rest of the workspace does: one toast, read
          from the flash the controller left behind. */ ?>
 <?php \App\Core\View::toast('success', $message ?? null); ?><?php \App\Core\View::toast('error', $error ?? null); ?>
 <div class="professional-chat-layout">
  <aside class="professional-chat-list"><div class="professional-chat-list-head"><strong><?= $isAdmin?'Students':'Academy team' ?></strong><?php if($isAdmin): ?><form method="get" action="<?= $e($baseUrl) ?>/admin/chats"><select name="student_id" onchange="this.form.submit()"><option value="">Select student</option><?php foreach($students as $student): ?><option value="<?= (int)$student['id'] ?>" <?= (int)($thread['student_id']??0)===(int)$student['id']?'selected':'' ?>><?= $e($student['full_name']) ?></option><?php endforeach ?></select></form><?php endif ?></div>
   <?php if($isAdmin): /* The desk reads two ways, and the waiting number has to be the one the list
                            below actually answers to - so it is a link, not a caption. */ ?><div class="chat-filter-chips"><a class="chat-filter-chip<?= $waitingFilter?'':' is-active' ?>" href="<?= $e($baseUrl) ?>/admin/chats">All conversations</a><a class="chat-filter-chip<?= $waitingFilter?' is-active':'' ?>" href="<?= $e($baseUrl) ?>/admin/chats?filter=waiting">Waiting for you <small><?= (int)$waitingTotal ?></small></a></div><?php endif ?>
   <?php if(!$isAdmin): ?><a class="professional-chat-contact<?= $thread?' is-active':'' ?>" href="<?= $e($baseUrl) ?>/student/chats"><span class="conversation-avatar conversation-avatar-academy">TSG</span><span><strong>The Academy</strong><small>Private support chat</small></span></a><?php endif ?>
   <?php if($threads===[]): ?><p class="professional-chat-none"><?= $isAdmin?($waitingFilter?'Nothing is waiting for an answer.':'No conversation has been started yet.'):'Your conversation with the Academy is always here.' ?></p><?php endif ?>
   <?php foreach($threads as $item): if(!$isAdmin) continue; ?><a class="professional-chat-contact<?= (int)($thread['id']??0)===(int)$item['id']?' is-active':'' ?>" href="<?= $e($baseUrl) ?>/admin/chats?thread_id=<?= (int)$item['id'] ?>"><span class="conversation-avatar"><?= $e(mb_strtoupper(mb_substr((string)$item['student_name'],0,1))) ?></span><span><strong><?= $e($item['student_name']) ?></strong><small><?= $e(mb_strimwidth((string)$item['last_message'],0,44,'…')) ?></small></span></a><?php endforeach ?></aside>
  <main class="professional-chat-main">
   <?php if(!$thread): ?><div class="professional-chat-empty"><h2>Select a student</h2><p>Choose a student from the list to start a private conversation.</p></div><?php else: ?>
    <header class="professional-chat-head"><span class="conversation-avatar<?= $isAdmin?'':' conversation-avatar-academy' ?>"><?= $e($isAdmin?mb_strtoupper(mb_substr((string)$thread['student_name'],0,1)):'TSG') ?></span><div class="chat-head-copy"><strong><?= $e($isAdmin?$thread['student_name']:'The Academy') ?></strong><small><?= $isAdmin?$e($thread['student_email']):'Private conversation' ?></small></div>
     <?php /* Everything a reader can do to the conversation as a whole lives behind one quiet button,
              because none of it belongs next to the Send button. */ ?><div class="chat-thread-menu">
      <button class="chat-menu-toggle" type="button" data-chat-panel-toggle="chat-thread-menu" aria-haspopup="true" aria-expanded="false" aria-label="More chat actions"><?= $dots ?></button>
      <div class="chat-menu" id="chat-thread-menu" data-chat-focus-first hidden>
       <p class="chat-menu-label">This conversation</p>
       <form method="post" action="<?= $e($baseUrl) ?>/chats/clear" class="chat-menu-form" onsubmit="return confirm('Clear this chat for <?= $isAdmin?'the desk':'you' ?> only? Nothing is deleted - <?= $isAdmin?'the student':'the Academy' ?> keeps reading every message, and a new message brings this chat back here.')">
        <input type="hidden" name="_csrf" value="<?= $e(Security::csrfToken()) ?>"><input type="hidden" name="thread_id" value="<?= (int)$thread['id'] ?>">
        <button class="chat-menu-item" type="submit">Clear this chat<small>Hide it on this side only</small></button>
       </form>
       <?php if($isAdmin): ?><a class="chat-menu-item is-danger" href="#chat-delete-confirm" data-admin-modal-open="chat-delete-confirm">Delete conversation<small>Delete the messages for both sides</small></a><?php endif ?>
      </div>
     </div>
    </header>
    <div class="professional-chat-feed" id="chat-feed"><?php if($messages===[]): ?><div class="professional-chat-empty"><h2><?= $isAdmin?'Start the conversation':'No messages on this side right now' ?></h2><p><?= $isAdmin?'Send a message to begin this private chat.':'This chat is cleared for you. The Academy still reads it, and a new message from them will show here.' ?></p></div><?php endif; foreach($messages as $message): $mine=(int)$message['author_id']===(int)$user['id']; ?><div class="professional-message<?= $mine?' is-mine':'' ?>"><div class="professional-bubble"><?= nl2br($e($message['message'])) ?></div><time><?= $e($stamp($message['created_at'])) ?></time></div><?php endforeach ?></div>
    <form method="post" action="<?= $e($baseUrl) ?>/chats/send" class="professional-composer" data-chat-composer>
     <input type="hidden" name="_csrf" value="<?= $e(Security::csrfToken()) ?>"><input type="hidden" name="thread_id" value="<?= (int)$thread['id'] ?>">
     <div class="chat-emoji-anchor">
      <button class="chat-emoji-toggle" type="button" data-chat-panel-toggle="chat-emoji-panel" aria-haspopup="true" aria-expanded="false" aria-label="Add an emoji"><?= $smile ?></button>
      <?php /* The picker is a panel of buttons, never form inputs: only the textarea carries the
                message, so a stray click cannot send anything. */ ?><div class="chat-emoji-popover" id="chat-emoji-panel" hidden>
       <?php foreach($emojiGroups as $emojiLabel=>$emojiSet): ?><p class="chat-emoji-group"><?= $e($emojiLabel) ?></p><div class="chat-emoji-grid"><?php foreach($emojiSet as $emojiGlyph): ?><button class="chat-emoji" type="button" data-emoji="<?= $e($emojiGlyph) ?>" aria-label="Insert <?= $e($emojiGlyph) ?>"><?= $e($emojiGlyph) ?></button><?php endforeach ?></div><?php endforeach ?>
      </div>
     </div>
     <textarea name="message" rows="2" maxlength="3000" required placeholder="Type your message..." data-chat-input></textarea><button class="button professional-send" type="submit">Send <span aria-hidden="true">➜</span></button>
    </form>
   <?php endif ?></main>
 </div>
 <?php if($thread && $isAdmin): ?>
  <div class="admin-modal" id="chat-delete-confirm" data-admin-modal role="dialog" aria-modal="true" aria-labelledby="chat-delete-confirm-title">
   <a class="admin-modal-backdrop" href="#chat-delete-confirm" data-admin-modal-close aria-label="Close the delete confirmation"></a>
   <div class="admin-modal-dialog">
    <div class="admin-modal-header">
     <div class="admin-card-title-group"><div class="admin-card-icon amber"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg></div><div class="admin-card-titles"><p class="eyebrow">PERMANENT</p><h2 id="chat-delete-confirm-title">Delete this conversation</h2></div></div>
     <a class="admin-modal-close" href="#chat-delete-confirm" data-admin-modal-close aria-label="Close the delete confirmation"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></a>
    </div>
    <form method="post" action="<?= $e($baseUrl) ?>/admin/chats/delete" class="admin-form admin-modal-body">
     <input type="hidden" name="_csrf" value="<?= $e(Security::csrfToken()) ?>"><input type="hidden" name="thread_id" value="<?= (int)$thread['id'] ?>">
     <p class="identity-confirm-lead">Every message between you and <strong><?= $e($thread['student_name']) ?></strong> is deleted on both sides. The student keeps writing to the same chat, and the next message starts an empty page.</p>
     <ul class="identity-confirm-points">
      <li>This cannot be undone - nothing is moved to a bin and no copy is kept.</li>
      <li>Clearing the chat is the safe action, and it hides the messages on your desk only.</li>
      <li>Type the phrase exactly as it is printed, or nothing is deleted.</li>
     </ul>
     <div class="admin-field"><label class="admin-label" for="chat-delete-phrase">Type <code>DELETE THIS CONVERSATION</code> to confirm <span class="req">*</span></label><input class="admin-input" id="chat-delete-phrase" name="confirm_phrase" type="text" autocomplete="off" spellcheck="false" placeholder="DELETE THIS CONVERSATION" required></div>
     <div class="admin-field"><label class="admin-label">&nbsp;</label><label class="purge-acknowledgement"><input type="checkbox" name="irreversible" value="1" required><span>I understand these messages go for the student as well, and nothing is kept.</span></label></div>
     <div class="form-actions"><a class="button button-ghost" href="#chat-delete-confirm" data-admin-modal-close>Cancel</a><button class="button button-danger" type="submit">Delete conversation</button></div>
    </form>
   </div>
  </div>
 <?php endif; ?>
</section>
<?php
    $usageTitle=$isAdmin?'How to use the chat desk':'How to use your chats';
    $usageLead=$isAdmin
        ? 'One private line per student, and the desk only ever shows what you can still read.'
        : 'Your line to the Academy, kept in one place.';
    $usageBullets=$isAdmin?[
        'Pick a student. The dropdown opens that student\'s private line, and the list holds every conversation with a message you can still see.',
        'Waiting for you. The chip counts the conversations whose newest message came from a student and no reply has gone out.',
        'Clearing is for you only. Clear this chat hides its messages from your desk; the student keeps reading the same conversation, and their next message brings it back.',
        'Deleting needs the phrase. Delete conversation removes the messages for both sides and cannot be undone.',
    ]:[
        'Write to the Academy. Only your own conversation is listed here, and the team answers on this same page.',
        'One line, never two. Everything you send goes into the same thread, so an old answer is never lost in a second chat.',
        'Clearing is for you only. Clear this chat hides the messages on your side; the Academy still has them, and a new reply shows here again.',
        'Emoji are part of the message. They are sent as text, so the Academy reads exactly what you saw.',
    ];
    require BASE_PATH.'/app/Views/components/usage-guide.php';
?>
<script>(()=>{const feed=document.getElementById('chat-feed');if(feed)feed.scrollTop=feed.scrollHeight})()</script>
