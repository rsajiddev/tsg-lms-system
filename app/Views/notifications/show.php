<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $v): string => Security::escape((string)$v);
$replies = is_array($replies ?? null) ? $replies : [];
$notification = is_array($notification ?? null) ? $notification : [];
$user = is_array($user ?? null) ? $user : [];
$isAdmin = ($user['role'] ?? '') === 'admin';
$viewerId = (int)($user['id'] ?? 0);
// A thread that already holds messages is a conversation and lives on the chat page; an empty one is
// still just the notice, so the breadcrumb names the page the reader came from.
$hasMessages = (is_array($replies ?? null) ? count($replies) : 0) > 0;

/**
 * Stored as UTC and printed with the same convention the rest of the workspace uses, so a reply
 * and the announcement it answers can be compared by eye.
 */
$stamp = static function (?string $at): string {
    $time = $at === null || $at === '' ? false : strtotime($at);
    return $time === false ? '—' : date('M j, Y · g:i A', $time);
};
// Two letters are enough to tell whose bubble this is, and they survive a long name.
$initials = static function (?string $name): string {
    $words = preg_split('/\s+/u', trim((string)$name)) ?: [];
    $letters = '';
    foreach (array_slice($words, 0, 2) as $word) {
        $letters .= mb_strtoupper(mb_substr((string)$word, 0, 1));
    }
    return $letters !== '' ? $letters : 'TSG';
};
?>
<section class="dashboard-page conversation-page">
    <div class="dashboard-topbar"><button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
        <div class="dashboard-breadcrumb"><a href="<?= $escape($baseUrl) ?>/dashboard">Workspace</a><i>/</i><?php if ($hasMessages): ?><a href="<?= $escape($baseUrl) . ($isAdmin ? '/admin/chats' : '/student/chats') ?>">Chats</a><?php elseif ($isAdmin): ?><a href="<?= $escape($baseUrl) ?>/admin/announcements">Announcements</a><?php else: ?><a href="<?= $escape($baseUrl) ?>/notifications">Notifications</a><?php endif; ?><i>/</i><strong>Conversation</strong></div>
    </div>
    <header class="dashboard-heading">
        <div>
            <p class="eyebrow"><?= $hasMessages ? 'CONVERSATION' : 'PRIVATE CONVERSATION' ?></p>
            <h1><?= $escape($notification['title'] ?? '') ?></h1>
            <p>Opened <?= $escape($stamp($notification['published_at'] ?? null)) ?> by <?= $escape($notification['author_name'] ?? 'the Academy') ?></p>
        </div>
        <?php if (!$isAdmin): ?><a class="button button-secondary" href="<?= $escape($baseUrl) ?>/student/chats">Back to chats</a><?php elseif (!empty($recipient)): ?><a class="button button-secondary" href="<?= $escape($baseUrl) ?>/admin/chats">Back to chats</a><?php endif; ?>
    </header>
    <section class="dashboard-panel conversation-panel">
        <article class="conversation-announcement">
            <span class="conversation-avatar conversation-avatar-academy" aria-hidden="true">TSG</span>
            <div class="conversation-announcement-main">
                <p class="conversation-meta"><strong>Academy announcement</strong><span class="conversation-dot" aria-hidden="true">·</span><time datetime="<?= $escape($notification['published_at'] ?? '') ?>"><?= $escape($stamp($notification['published_at'] ?? null)) ?></time></p>
                <div class="conversation-text"><?= nl2br($escape($notification['body'] ?? '')) ?></div>
            </div>
        </article>
        <?php if ($isAdmin): ?>
            <form method="get" action="<?= $escape($baseUrl) ?>/notifications/view" class="conversation-picker">
                <input type="hidden" name="notification_id" value="<?= (int)($notification['id'] ?? 0) ?>">
                <label class="conversation-picker-label" for="conversation-recipient">Private reply thread
                    <select class="admin-select" id="conversation-recipient" name="student_id" required>
                        <option value="">Select a recipient</option><?php foreach (($recipients ?? []) as $item): ?><option value="<?= (int)$item['id'] ?>" <?= (int)($recipient['id'] ?? 0) === (int)$item['id'] ? 'selected' : '' ?>><?= $escape($item['full_name']) ?> · <?= $escape($item['email']) ?></option><?php endforeach; ?>
                    </select></label>
                <button class="button button-secondary" type="submit">Open thread</button>
            </form>
        <?php endif; ?>
        <?php if (!$isAdmin || !empty($recipient)): ?>
            <?php /* The messages sit in their own window with the composer under it, the way every
                      chat app stacks them: the newest bubble is always the thing above where you type. */ ?>
            <div class="conversation-window">
                <ul class="conversation-list" aria-label="Messages in this conversation">
                    <?php if ($replies === []): ?>
                        <li class="conversation-empty"><?= $isAdmin ? 'No messages from this student yet. Your reply opens the thread.' : 'No messages yet. Write the first one below and the Academy will reply here.' ?></li>
                    <?php endif; ?>
                    <?php $lastDay = null; foreach ($replies as $reply): $mine = (int)($reply['author_id'] ?? 0) === $viewerId; $day = date('Y-m-d', strtotime((string) $reply['created_at'])); ?>
                        <?php if ($day !== $lastDay): $lastDay = $day; ?>
                            <li class="conversation-day" aria-hidden="true"><?= $escape(date('M j, Y', strtotime((string) $reply['created_at']))) ?></li>
                        <?php endif; ?>
                        <li class="conversation-row<?= $mine ? ' is-mine' : '' ?>">
                            <span class="conversation-avatar<?= $reply['author_role'] === 'admin' ? ' conversation-avatar-academy' : '' ?>" aria-hidden="true"><?= $escape($initials($reply['author_name'])) ?></span>
                            <div class="conversation-bubble">
                                <p class="conversation-meta"><strong><?= $escape($reply['author_name']) ?></strong><span class="conversation-role"><?= $reply['author_role'] === 'admin' ? 'Academy team' : 'Student' ?></span><time datetime="<?= $escape($reply['created_at']) ?>"><?= $escape($stamp($reply['created_at'])) ?></time></p>
                                <div class="conversation-text"><?= nl2br($escape($reply['message'])) ?></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if (!$isAdmin || !empty($recipient)): ?>
            <form method="post" action="<?= $escape($baseUrl) ?>/notifications/reply" class="conversation-composer">
                <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
                <input type="hidden" name="notification_id" value="<?= (int)($notification['id'] ?? 0) ?>">
                <?php if ($isAdmin): ?><input type="hidden" name="recipient_student_id" value="<?= (int)$recipient['id'] ?>"><?php endif; ?>
                <label class="conversation-composer-label" for="conversation-message"><?= $isAdmin ? 'Reply privately to ' . $escape($recipient['full_name'] ?? 'this student') : 'Message the Academy' ?></label>
                <textarea class="conversation-input" id="conversation-message" name="message" rows="3" maxlength="3000" required placeholder="Write your message..."></textarea>
                <div class="conversation-composer-foot"><span>Only you and the Academy team can read this thread.</span><button class="button conversation-send" type="submit">Send reply<span aria-hidden="true">→</span></button></div>
            </form>
        <?php endif; ?>
    </section>
    <?php if ($replies !== []): ?>
        <?php /* A chat is read from its newest message, so the window opens at the bottom - the same
                  place the reply you just sent landed. Nothing else on the page moves. */ ?>
        <script>
            (() => {
                const thread = document.querySelector('.conversation-window');
                if (!thread) return;
                thread.scrollTop = thread.scrollHeight;
            })();
        </script>
    <?php endif; ?>
</section>
