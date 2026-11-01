<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $value): string => Security::escape((string) $value);
$threads = is_array($threads ?? null) ? $threads : [];
$unreadTotal = (int) ($unreadTotal ?? 0);
$message = $message ?? null;
$error = $error ?? null;
// Same stamp format the conversation page uses, so a list entry and the message inside it match.
$stamp = static function (?string $at): string {
    $time = $at === null || $at === '' ? false : strtotime($at);
    return $time === false ? '—' : date('M j, Y · g:i A', $time);
};
// One line of the newest message is enough to recognise a thread, the way any chat list works.
$preview = static function (?string $body): string {
    $text = trim(preg_replace('/\s+/u', ' ', (string) $body) ?? '');
    return $text === '' ? 'No message yet.' : mb_strimwidth($text, 0, 120, '…');
}; ?>
<section class="dashboard-page">
    <div class="dashboard-topbar"><button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
        <div class="dashboard-breadcrumb"><a href="<?= $escape($baseUrl) ?>/dashboard">Workspace</a><i>/</i><strong>Chats</strong></div>
    </div>
    <header class="dashboard-heading">
        <div>
            <p class="eyebrow">PRIVATE CONVERSATIONS</p>
            <h1>Chats</h1>
            <p><?= $threads === [] ? 'Conversations you have started with the Academy will appear here.' : ($unreadTotal > 0 ? $unreadTotal . ' unread message(s) across ' . count($threads) . ' conversation(s).' : count($threads) . ' conversation(s), all caught up.') ?></p>
        </div>
        <?php /* Two list-wide shortcuts, each offered only while there is something to do. Marking
                  read touches the conversations, never the inbox, so the two counters stay apart. */ ?>
        <?php if ($threads !== []): ?><div class="chat-heading-actions">
                <?php if ($unreadTotal > 0): ?><form method="post" action="<?= $escape($baseUrl) ?>/student/chats/read-all" class="student-status-form"><input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>"><button class="button button-secondary" type="submit">Read all</button></form><?php endif; ?>
                <form method="post" action="<?= $escape($baseUrl) ?>/student/chats/delete-all" class="student-status-form" onsubmit="return confirm('Remove every conversation from your chat list? Nothing is deleted from Academy records and a new message brings a conversation back.')"><input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>"><button class="button button-secondary" type="submit">Delete all</button></form>
            </div><?php endif; ?>
    </header><?php \App\Core\View::toast('success', $message ?? null); ?><?php \App\Core\View::toast('error', $error ?? null); ?>
    <section class="dashboard-panel">
        <div class="dashboard-panel-heading">
            <div>
                <p class="eyebrow">INBOX</p>
                <h2>Your conversations</h2>
            </div><span class="count-pill"><?= count($threads) ?> chat<?= count($threads) === 1 ? '' : 's' ?></span>
        </div>
        <?php if ($threads === []): ?>
            <div class="dashboard-empty"><span class="dashboard-empty-icon">C</span>
                <div>
                    <h3>No conversations yet</h3>
                    <p>Open any Academy update in <a class="dashboard-inline-link" href="<?= $escape($baseUrl) ?>/notifications">Notifications</a> and reply to it — the thread will live here.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="chat-thread-list"><?php foreach ($threads as $thread): $unseen = (int) ($thread['unseen_count'] ?? 0) > 0; ?>
                    <article class="chat-thread-row<?= $unseen ? ' is-unread' : '' ?>">
                        <a class="chat-thread-main" href="<?= $escape($baseUrl) ?>/notifications/view?notification_id=<?= (int) $thread['id'] ?>">
                            <span class="conversation-avatar conversation-avatar-academy" aria-hidden="true">TSG</span>
                            <span class="chat-thread-copy">
                                <span class="chat-thread-top"><strong><?= $escape($thread['title']) ?></strong><time datetime="<?= $escape($thread['last_at']) ?>"><?= $escape($stamp($thread['last_at'] ?? null)) ?></time></span>
                                <span class="chat-thread-preview"><?= $escape($preview($thread['last_message'] ?? null)) ?></span>
                                <span class="chat-thread-foot"><span><?= (int) $thread['message_count'] ?> message<?= (int) $thread['message_count'] === 1 ? '' : 's' ?></span><?php if ($unseen): ?><span class="chat-thread-badge"><?= (int) $thread['unseen_count'] ?> new</span><?php endif; ?></span>
                            </span>
                        </a>
                        <form method="post" action="<?= $escape($baseUrl) ?>/student/chats/delete" class="chat-thread-action" onsubmit="return confirm('Remove this conversation from your chats? A new message from the Academy brings it back.')">
                            <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
                            <input type="hidden" name="notification_id" value="<?= (int) $thread['id'] ?>">
                            <button class="chat-delete-button" type="submit" aria-label="Remove conversation: <?= $escape($thread['title']) ?>">Delete</button>
                        </form>
                    </article><?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php
    $usageEyebrow = 'Conversations';
    $usageTitle = 'How to use your chats';
    $usageLead = 'Only the threads that hold messages are listed here, newest activity first.';
    $usageBullets = [
        'Open a conversation to read it. Opening marks the thread as read, so the number on Chats comes down as you work through the list.',
        'Reply inside the thread. Your message reaches the Academy team and their answer comes back to the same conversation - no one else sees it.',
        'Read all clears the number at once. It marks every conversation seen without you opening them one by one, and leaves your notifications untouched.',
        'Delete takes one conversation off this list. The messages stay with the Academy, and the thread reappears the moment someone writes in it again.',
        'Delete all clears the list. Every conversation on your account is hidden in one action; no message is destroyed and no Academy record is changed.',
        'News stays in Notifications. Announcements, lesson, test, assignment and payment updates are read in your inbox - Chats holds only the conversations.',
    ];
    require BASE_PATH . '/app/Views/components/usage-guide.php';
    ?>

    <div class="dashboard-note"><strong>Hidden, not deleted</strong><span>Chats you remove disappear from your list only. The Academy keeps the full thread, and a fresh reply from the team brings the conversation straight back here.</span></div>
</section>
