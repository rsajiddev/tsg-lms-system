<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $value): string => Security::escape((string) $value);
$threads = is_array($threads ?? null) ? $threads : [];
$threadTotal = (int) ($threadTotal ?? 0);
$waitingTotal = (int) ($waitingTotal ?? 0);
$waitingOnly = (bool) ($waitingOnly ?? false);
$message = $message ?? null;
$error = $error ?? null;
// Same stamp format the conversation page uses, so a list entry and the message inside it match.
$stamp = static function (?string $at): string {
    $time = $at === null || $at === '' ? false : strtotime($at);
    return $time === false ? '—' : date('M j, Y · g:i A', $time);
};
$initials = static function (?string $name): string {
    $words = preg_split('/\s+/u', trim((string) $name)) ?: [];
    $letters = '';
    foreach (array_slice($words, 0, 2) as $word) {
        $letters .= mb_strtoupper(mb_substr((string) $word, 0, 1));
    }
    return $letters !== '' ? $letters : 'STU';
};
$preview = static function (?string $body): string {
    $text = trim(preg_replace('/\s+/u', ' ', (string) $body) ?? '');
    return $text === '' ? 'No message yet.' : mb_strimwidth($text, 0, 120, '…');
}; ?>
<section class="dashboard-page admin-workspace">
    <header class="admin-header">
        <div class="admin-header-main"><button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
            <p class="eyebrow">ADMIN WORKSPACE</p>
            <h1>Chats</h1>
            <p>Every private conversation a student has started, newest activity first. The sidebar number counts the threads still waiting on an answer from you.</p>
        </div>
    </header>
    <?php \App\Core\View::toast('success', $message ?? null); ?>
    <?php \App\Core\View::toast('error', $error ?? null); ?>
    <section class="admin-card">
        <div class="admin-card-header chat-desk-header">
            <div class="admin-card-titles">
                <h2>Conversations</h2>
                <p><?= $waitingTotal > 0 ? number_format($waitingTotal) . ' thread(s) are waiting for an Academy reply.' : 'Nothing is waiting on a reply right now.' ?></p>
            </div>
            <div class="chat-filter-chips" role="group" aria-label="Filter conversations">
                <a class="chat-filter-chip<?= $waitingOnly ? '' : ' is-active' ?>" href="<?= $escape($baseUrl) ?>/admin/chats">All<small><?= number_format($threadTotal) ?></small></a>
                <a class="chat-filter-chip<?= $waitingOnly ? ' is-active' : '' ?>" href="<?= $escape($baseUrl) ?>/admin/chats?filter=waiting">Waiting for you<small><?= number_format($waitingTotal) ?></small></a>
            </div>
            <?php /* The desk clears the way a student's list does. Both actions hide a thread from
                      this list and lower the badge; neither touches a message or a student record. */ ?>
            <?php if ($threads !== []): ?><form method="post" action="<?= $escape($baseUrl) ?>/admin/chats/delete-all" class="student-status-form" onsubmit="return confirm('Clear every conversation from your desk? Nothing is deleted and a new message from a student brings a conversation back.')"><input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>"><button class="chat-delete-button" type="submit">Delete all</button></form><?php endif; ?>
        </div>
        <?php if ($threads === []): ?><div class="admin-empty-state">
                <h3><?= $waitingOnly ? 'No thread is waiting on you' : 'No conversations yet' ?></h3>
                <p><?= $waitingOnly ? 'Every conversation has an Academy answer as its last message.' : 'A student who replies to any Academy update opens a thread here. Announcements are published on their own page.' ?></p>
            </div><?php else: ?>
            <div class="chat-thread-list"><?php foreach ($threads as $thread): $waiting = \App\Services\ChatService::awaitsReply($thread); ?>
                    <article class="chat-thread-row<?= $waiting ? ' is-unread' : '' ?>">
                        <a class="chat-thread-main" href="<?= $escape($baseUrl) ?>/notifications/view?notification_id=<?= (int) $thread['id'] ?>&student_id=<?= (int) $thread['student_id'] ?>">
                            <span class="conversation-avatar" aria-hidden="true"><?= $escape($initials($thread['student_name'] ?? null)) ?></span>
                            <span class="chat-thread-copy">
                                <span class="chat-thread-top"><strong><?= $escape($thread['student_name']) ?></strong><time datetime="<?= $escape($thread['last_at']) ?>"><?= $escape($stamp($thread['last_at'] ?? null)) ?></time></span>
                                <span class="chat-thread-subject"><?= $escape($thread['title']) ?> · <?= $escape($thread['student_email']) ?></span>
                                <span class="chat-thread-preview"><?= $escape($preview($thread['last_message'] ?? null)) ?></span>
                                <span class="chat-thread-foot"><span><?= (int) $thread['message_count'] ?> message<?= (int) $thread['message_count'] === 1 ? '' : 's' ?></span><span class="dashboard-status status-<?= $waiting ? 'pending' : 'approved' ?>"><?= $waiting ? 'Waiting for the Academy' : 'Answered' ?></span></span>
                            </span>
                        </a>
                        <div class="chat-thread-action">
                            <a class="dashboard-inline-link chat-thread-open" href="<?= $escape($baseUrl) ?>/notifications/view?notification_id=<?= (int) $thread['id'] ?>&student_id=<?= (int) $thread['student_id'] ?>">Open <span aria-hidden="true">→</span></a>
                            <form method="post" action="<?= $escape($baseUrl) ?>/admin/chats/delete" onsubmit="return confirm('Clear this conversation from your desk? The student keeps it, and their next message brings it back.')">
                                <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
                                <input type="hidden" name="notification_id" value="<?= (int) $thread['id'] ?>">
                                <input type="hidden" name="student_id" value="<?= (int) $thread['student_id'] ?>">
                                <button class="chat-delete-button" type="submit" aria-label="Clear conversation with <?= $escape($thread['student_name']) ?>">Delete</button>
                            </form>
                        </div>
                    </article><?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php
    $usageEyebrow = 'Conversations';
    $usageTitle = 'How to use the chat desk';
    $usageLead = 'One page for every private thread, so a student message is never just a number again.';
    $usageBullets = [
        'Start with the number on Chats. It counts the threads whose last message came from a student, so a clear list means every question has an answer.',
        'Open any conversation to read it. The thread page is a chat: the messages take the wide half of the page, and the student, the recipient picker and the recent chats sit beside them.',
        'Reply in the thread. Your message reaches that student only, moves their Chats number and reopens the conversation if they had cleared it from their list.',
        'Use the Waiting filter when the list grows. It hides the threads you have already answered so the open ones are the only thing on screen.',
        'Delete takes one thread off this desk, Delete all clears the whole desk. Both are bookkeeping for you: the student still sees the conversation, and their next message puts it back here and back on the badge.',
        'Announcements live elsewhere. Publishing and the board controls stay on the Announcements page; this desk is for reading, replying and tidying.',
    ];
    require BASE_PATH . '/app/Views/components/usage-guide.php';
    ?>

    <div class="dashboard-note"><strong>Threads are student records</strong><span>Conversations are kept so a question and its answer stay together. Clearing this desk hides a thread from your list only - the real removal tools stay on the Announcements and Recent activity pages, behind a typed confirmation.</span></div>
</section>
