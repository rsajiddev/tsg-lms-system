<?php

use App\Core\Security;
use App\Services\ChatService;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $v): string => Security::escape((string)$v);
$replies = is_array($replies ?? null) ? $replies : [];
$notification = is_array($notification ?? null) ? $notification : [];
$user = is_array($user ?? null) ? $user : [];
$threads = is_array($threads ?? null) ? $threads : [];
$recipients = is_array($recipients ?? null) ? $recipients : [];
$recipient = is_array($recipient ?? null) ? $recipient : null;
$isAdmin = ($user['role'] ?? '') === 'admin';
$viewerId = (int)($user['id'] ?? 0);
$noticeId = (int)($notification['id'] ?? 0);
// Whose half of the thread is on screen. A student is always their own half; an administrator is
// looking at nothing until they pick a recipient, and then at exactly one student's half.
$threadStudentId = $isAdmin ? (int)($recipient['id'] ?? 0) : $viewerId;
$hasThread = $threadStudentId > 0;
$hasMessages = $replies !== [];

/**
 * Stored as UTC and printed with the same convention the rest of the workspace uses, so a reply
 * and the announcement it answers can be compared by eye.
 */
$stamp = static function (?string $at): string {
    $time = $at === null || $at === '' ? false : strtotime($at);
    return $time === false ? '—' : date('M j, Y · g:i A', $time);
};
$shortStamp = static function (?string $at): string {
    $time = $at === null || $at === '' ? false : strtotime($at);
    return $time === false ? '—' : (date('Y-m-d', $time) === date('Y-m-d') ? date('g:i A', $time) : date('M j', $time));
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
$preview = static function (?string $body): string {
    $text = trim(preg_replace('/\s+/u', ' ', (string)$body) ?? '');
    return $text === '' ? 'No message yet.' : mb_strimwidth($text, 0, 90, '…');
};
/**
 * A thread is listed the same way in both rails: the person you are talking to is the title for an
 * administrator and the Academy update is the title for a student, because that is what each of the
 * two readers actually recognises from one line.
 */
$threadUrl = static function (array $thread) use ($baseUrl, $isAdmin): string {
    $href = $baseUrl . '/notifications/view?notification_id=' . (int) $thread['id'];
    return $isAdmin ? $href . '&student_id=' . (int) $thread['student_id'] : $href;
};
$threadTitle = static function (array $thread) use ($isAdmin): string {
    return $isAdmin ? (string) ($thread['student_name'] ?? 'Student') : (string) ($thread['title'] ?? 'Conversation');
};
$threadIsOpen = static function (array $thread) use ($isAdmin, $noticeId, $threadStudentId): bool {
    return (int) $thread['id'] === $noticeId && (!$isAdmin || (int) $thread['student_id'] === $threadStudentId);
};
// Unread means different things per side, and both are the truth for that reader: the Academy wrote
// since the student last looked, or the student wrote and no answer has landed yet.
$threadIsNew = static function (array $thread) use ($isAdmin): bool {
    return $isAdmin ? ChatService::awaitsReply($thread) : (int) ($thread['unseen_count'] ?? 0) > 0;
};
$otherThreads = array_values(array_filter($threads, static fn(array $thread): bool => !$threadIsOpen($thread)));
$recentThreads = array_slice($otherThreads, 0, 5);
?>
<section class="dashboard-page conversation-page">
    <div class="dashboard-topbar"><button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
        <div class="dashboard-breadcrumb"><a href="<?= $escape($baseUrl) ?>/dashboard">Workspace</a><i>/</i><a href="<?= $escape($baseUrl) . ($isAdmin ? '/admin/chats' : '/student/chats') ?>">Chats</a><i>/</i><strong>Conversation</strong></div>
    </div>
    <header class="dashboard-heading">
        <div>
            <p class="eyebrow">CONVERSATION</p>
            <h1><?= $escape($notification['title'] ?? '') ?></h1>
            <p>Opened <?= $escape($stamp($notification['published_at'] ?? null)) ?> by <?= $escape($notification['author_name'] ?? 'the Academy') ?></p>
        </div>
        <a class="button button-secondary" href="<?= $escape($baseUrl) . ($isAdmin ? '/admin/chats' : '/student/chats') ?>">Back to chats</a>
    </header>

    <?php /* One chat client, three panes: the inbox of conversations on the left, the messages in the
             middle, and who you are talking to plus the recent chats on the right. Nothing here is a
             table of notifications - the reader is inside a thread and should be able to leave it
             without losing it. */ ?>
    <div class="conversation-panel chat-shell">
        <aside class="chat-rail chat-rail-inbox" aria-label="Your conversations">
            <div class="chat-rail-head">
                <p class="eyebrow">INBOX</p>
                <h2>Conversations</h2>
                <span class="count-pill"><?= count($threads) ?></span>
            </div>
            <?php if ($threads === []): ?>
                <p class="chat-rail-empty">No conversations yet. The first message written in any Academy update opens one here.</p>
            <?php else: ?>
                <ul class="chat-rail-list">
                    <?php foreach ($threads as $thread): $open = $threadIsOpen($thread); ?>
                        <li>
                            <a class="chat-rail-row<?= $open ? ' is-open' : '' ?><?= $threadIsNew($thread) ? ' is-new' : '' ?>" href="<?= $escape($threadUrl($thread)) ?>"<?= $open ? ' aria-current="true"' : '' ?>>
                                <span class="conversation-avatar<?= $isAdmin ? '' : ' conversation-avatar-academy' ?>" aria-hidden="true"><?= $escape($isAdmin ? $initials($thread['student_name'] ?? null) : 'TSG') ?></span>
                                <span class="chat-rail-copy">
                                    <span class="chat-rail-top"><strong><?= $escape($threadTitle($thread)) ?></strong><time datetime="<?= $escape($thread['last_at'] ?? '') ?>"><?= $escape($shortStamp($thread['last_at'] ?? null)) ?></time></span>
                                    <span class="chat-rail-preview"><?= $escape($preview($thread['last_message'] ?? null)) ?></span>
                                    <span class="chat-rail-foot"><?php if ($threadIsNew($thread)): ?><span class="chat-thread-badge"><?= $isAdmin ? 'Needs a reply' : (int) $thread['unseen_count'] . ' new' ?></span><?php endif; ?><small><?= (int) $thread['message_count'] ?> message<?= (int) $thread['message_count'] === 1 ? '' : 's' ?></small></span>
                                </span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <a class="chat-rail-foot-link" href="<?= $escape($baseUrl) . ($isAdmin ? '/admin/chats' : '/student/chats') ?>">Manage the chat list <span aria-hidden="true">→</span></a>
        </aside>

        <section class="chat-main">
            <header class="chat-main-head">
                <div class="chat-main-who">
                    <span class="conversation-avatar<?= $isAdmin ? '' : ' conversation-avatar-academy' ?>" aria-hidden="true"><?= $escape($isAdmin ? $initials($recipient['full_name'] ?? null) : 'TSG') ?></span>
                    <div>
                        <p class="chat-main-title"><?= $escape($isAdmin ? ($recipient['full_name'] ?? 'Select a student') : 'The Academy') ?></p>
                        <p class="chat-main-sub"><?= $escape($isAdmin ? ($recipient['email'] ?? 'Private thread with one student') : ($notification['title'] ?? '')) ?></p>
                    </div>
                </div>
                <?php if ($hasThread): ?>
                    <?php /* The same two clearing actions the chat list offers, because a reader who is
                              inside a conversation should be able to leave it from right here. */ ?>
                    <div class="chat-main-actions">
                        <?php if ($hasMessages): ?>
                            <form method="post" action="<?= $escape($baseUrl) . ($isAdmin ? '/admin/chats/delete' : '/student/chats/delete') ?>" class="student-status-form" onsubmit="return confirm('<?= $isAdmin ? 'Clear this conversation from your desk? The student keeps it, and their next message brings it back.' : 'Remove this conversation from your chats? A new message from the Academy brings it back.' ?>')">
                                <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
                                <input type="hidden" name="notification_id" value="<?= $noticeId ?>">
                                <?php if ($isAdmin): ?><input type="hidden" name="student_id" value="<?= $threadStudentId ?>"><?php endif; ?>
                                <button class="chat-delete-button" type="submit">Delete</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="<?= $escape($baseUrl) . ($isAdmin ? '/admin/chats/delete-all' : '/student/chats/delete-all') ?>" class="student-status-form" onsubmit="return confirm('<?= $isAdmin ? 'Clear every conversation from your desk? Nothing is deleted and a new message brings a conversation back.' : 'Remove every conversation from your chats? Nothing is deleted from Academy records and a new message brings a conversation back.' ?>')">
                            <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
                            <button class="chat-delete-button" type="submit">Delete all</button>
                        </form>
                    </div>
                <?php endif; ?>
            </header>

            <?php if (!$hasThread): ?>
                <div class="chat-main-picker-only">
                    <h2>Choose whose thread to read</h2>
                    <p>This update went to <?= count($recipients) ?> recipient(s). Pick one to open the private conversation you have with them, or start it.</p>
                </div>
            <?php else: ?>
                <?php /* The messages sit in their own window with the composer under it, the way every
                          chat app stacks them: the newest bubble is always the thing above where you type. */ ?>
                <div class="conversation-window chat-feed">
                    <article class="conversation-announcement">
                        <span class="conversation-avatar conversation-avatar-academy" aria-hidden="true">TSG</span>
                        <div class="conversation-announcement-main">
                            <p class="conversation-meta"><strong>Academy announcement</strong><span class="conversation-dot" aria-hidden="true">·</span><time datetime="<?= $escape($notification['published_at'] ?? '') ?>"><?= $escape($stamp($notification['published_at'] ?? null)) ?></time></p>
                            <div class="conversation-text"><?= nl2br($escape($notification['body'] ?? '')) ?></div>
                        </div>
                    </article>
                    <ul class="conversation-list" aria-label="Messages in this conversation">
                        <?php if ($replies === []): ?>
                            <li class="conversation-empty"><?= $isAdmin ? 'No messages from this student yet. Your reply opens the thread.' : 'No messages yet. Write the first one below and the Academy will reply here.' ?></li>
                        <?php endif; ?>
                        <?php $lastDay = null; foreach ($replies as $reply): $mine = (int)($reply['author_id'] ?? 0) === $viewerId; $day = date('Y-m-d', strtotime((string) $reply['created_at'])); ?>
                            <?php if ($day !== $lastDay): $lastDay = $day; ?>
                                <li class="conversation-day" aria-hidden="true"><?= $escape(date('M j, Y', strtotime((string) $reply['created_at']))) ?></li>
                            <?php endif; ?>
                            <?php /* Sender and time sit above the bubble rather than inside it, so the
                                     message itself stays the only thing in the coloured part - the way
                                     every chat app prints a thread. */ ?>
                            <li class="conversation-row<?= $mine ? ' is-mine' : '' ?>">
                                <span class="conversation-avatar<?= $reply['author_role'] === 'admin' ? ' conversation-avatar-academy' : '' ?>" aria-hidden="true"><?= $escape($initials($reply['author_name'])) ?></span>
                                <div class="conversation-stack">
                                    <p class="conversation-caption"><strong><?= $escape($reply['author_name']) ?></strong><span class="conversation-role"><?= $reply['author_role'] === 'admin' ? 'Academy team' : 'Student' ?></span><time datetime="<?= $escape($reply['created_at']) ?>"><?= $escape($stamp($reply['created_at'])) ?></time></p>
                                    <div class="conversation-bubble">
                                        <div class="conversation-text"><?= nl2br($escape($reply['message'])) ?></div>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <form method="post" action="<?= $escape($baseUrl) ?>/notifications/reply" class="conversation-composer">
                    <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
                    <input type="hidden" name="notification_id" value="<?= $noticeId ?>">
                    <?php if ($isAdmin): ?><input type="hidden" name="recipient_student_id" value="<?= $threadStudentId ?>"><?php endif; ?>
                    <label class="conversation-composer-label" for="conversation-message"><?= $isAdmin ? 'Reply privately to ' . $escape($recipient['full_name'] ?? 'this student') : 'Message the Academy' ?></label>
                    <div class="conversation-composer-bar">
                        <textarea class="conversation-input" id="conversation-message" name="message" rows="2" maxlength="3000" required placeholder="Write your message..."></textarea>
                        <button class="button conversation-send" type="submit" aria-label="Send message">Send<span aria-hidden="true">→</span></button>
                    </div>
                    <p class="conversation-composer-note">Only you and the Academy team can read this thread.</p>
                </form>
            <?php endif; ?>
        </section>

        <aside class="chat-rail chat-rail-side" aria-label="Conversation details">
            <?php if ($isAdmin): ?>
                <div class="chat-side-card">
                    <p class="eyebrow">STUDENT</p>
                    <?php if ($recipient): ?>
                        <p class="chat-side-name"><?= $escape($recipient['full_name']) ?></p>
                        <dl class="chat-side-facts">
                            <div><dt>E-mail</dt><dd><?= $escape($recipient['email']) ?></dd></div>
                            <div><dt>Academy ID</dt><dd><?= $escape($recipient['account_reference'] ?: \App\Services\AccountIdentity::derived((int) $recipient['id'])) ?></dd></div>
                            <div><dt>Messages</dt><dd><?= count($replies) ?> in this thread</dd></div>
                        </dl>
                    <?php else: ?>
                        <p class="chat-side-empty">Pick a recipient below to see their account and their side of the thread.</p>
                    <?php endif; ?>
                </div>
                <form method="get" action="<?= $escape($baseUrl) ?>/notifications/view" class="chat-side-card conversation-picker">
                    <input type="hidden" name="notification_id" value="<?= $noticeId ?>">
                    <label class="conversation-picker-label" for="conversation-recipient">Private reply thread
                        <select class="admin-select" id="conversation-recipient" name="student_id" required>
                            <option value="">Select a recipient</option><?php foreach ($recipients as $item): ?><option value="<?= (int)$item['id'] ?>" <?= (int)($recipient['id'] ?? 0) === (int)$item['id'] ? 'selected' : '' ?>><?= $escape($item['full_name']) ?> · <?= $escape($item['email']) ?></option><?php endforeach; ?>
                        </select></label>
                    <button class="button button-secondary" type="submit">Open thread</button>
                </form>
            <?php else: ?>
                <div class="chat-side-card">
                    <p class="eyebrow">THE UPDATE BEHIND THIS CHAT</p>
                    <p class="chat-side-name"><?= $escape($notification['title'] ?? '') ?></p>
                    <dl class="chat-side-facts">
                        <div><dt>Category</dt><dd><?= $escape($notification['category'] !== '' && $notification['category'] !== null ? ucfirst((string) $notification['category']) : 'Announcement') ?></dd></div>
                        <div><dt>Published</dt><dd><?= $escape($stamp($notification['published_at'] ?? null)) ?></dd></div>
                        <div><dt>From</dt><dd><?= $escape($notification['author_name'] ?? 'the Academy') ?></dd></div>
                    </dl>
                    <a class="chat-side-link" href="<?= $escape($baseUrl) ?>/notifications">All notifications <span aria-hidden="true">→</span></a>
                </div>
            <?php endif; ?>

            <?php /* Recent chats, one under the other: the reader can leave this thread without
                     losing it, because the way back is printed beside it. */ ?>
            <div class="chat-side-card">
                <p class="eyebrow">RECENT CHATS</p>
                <?php if ($recentThreads === []): ?>
                    <p class="chat-side-empty"><?= count($threads) > 1 ? 'No other conversations yet.' : 'Your other conversations will appear here.' ?></p>
                <?php else: ?>
                    <ul class="chat-recent-list">
                        <?php foreach ($recentThreads as $thread): ?>
                            <li><a class="chat-recent-row" href="<?= $escape($threadUrl($thread)) ?>">
                                    <span class="conversation-avatar<?= $isAdmin ? '' : ' conversation-avatar-academy' ?>" aria-hidden="true"><?= $escape($isAdmin ? $initials($thread['student_name'] ?? null) : 'TSG') ?></span>
                                    <span class="chat-recent-copy">
                                        <strong><?= $escape($threadTitle($thread)) ?></strong>
                                        <small><?= $escape($preview($thread['last_message'] ?? null)) ?></small>
                                    </span>
                                    <time datetime="<?= $escape($thread['last_at'] ?? '') ?>"><?= $escape($shortStamp($thread['last_at'] ?? null)) ?></time>
                                </a></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <div class="chat-side-note"><strong>Hidden, not deleted</strong><span>Clearing a chat only takes it off this list. The messages stay with the Academy, and a new reply brings the conversation straight back.</span></div>
        </aside>
    </div>

    <?php if ($hasMessages): ?>
        <?php /* A chat is read from its newest message, so the window opens at the bottom - the same
                  place the reply you just sent landed. Nothing else on the page moves. */ ?>
        <script>
            (() => {
                const thread = document.querySelector('.chat-feed');
                if (!thread) return;
                thread.scrollTop = thread.scrollHeight;
            })();
        </script>
    <?php endif; ?>
</section>
