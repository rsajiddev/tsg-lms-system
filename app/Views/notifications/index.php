<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $v): string => Security::escape((string)$v);
$notifications = is_array($notifications ?? null) ? $notifications : [];
$message = $message ?? null;
$error = $error ?? null;
// Each notification carries the workspace area it belongs to, so the list reads like the sidebar.
$categoryLabels = [
    'announcement' => 'Academy announcement',
    'lesson' => 'New lesson',
    'quiz' => 'Assessment',
    'assignment' => 'Assignment',
    'course' => 'Course update',
    'payment' => 'Payment',
    'grade' => 'Grade',
    'certificate' => 'Certificate',
];
$unreadCount = 0;
foreach ($notifications as $notification) if (($notification['read_at'] ?? null) === null) $unreadCount++; ?>
<section class="dashboard-page">
    <div class="dashboard-topbar"><button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
        <div class="dashboard-breadcrumb"><a href="<?= $escape($baseUrl) ?>/dashboard">Workspace</a><i>/</i><strong>Notifications</strong></div>
    </div>
    <header class="dashboard-heading">
        <div>
            <p class="eyebrow">ACADEMY UPDATES</p>
            <h1>My notifications</h1>
            <p><?= $unreadCount > 0 ? $unreadCount . ' unread update(s) from the Academy, your courses, tests and payments.' : 'Announcements and course updates from the Academy. You are up to date.' ?></p>
        </div>
        <?php /* Counting each notice read is the only way the sidebar numbers come down, so the
                  shortcut belongs next to the count it clears - and only while there is one. */ ?>
        <?php if ($unreadCount > 0): ?><form method="post" action="<?= $escape($baseUrl) ?>/notifications/read-all" class="student-status-form"><input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>"><button class="button button-secondary" type="submit">Mark all read</button></form><?php endif; ?>
    </header><?php \App\Core\View::toast('success', $message ?? null); ?><?php \App\Core\View::toast('error', $error ?? null); ?>
    <?php if ($notifications === []): ?><section class="dashboard-panel">
            <div class="dashboard-empty">
                <div>
                    <h2>You are all caught up</h2>
                    <p>New Academy announcements will appear here.</p>
                </div>
            </div>
        </section><?php else: ?><section class="notification-list"><?php foreach ($notifications as $n): $isUnread = ($n['read_at'] ?? null) === null; $category = $categoryLabels[(string) ($n['category'] ?? '')] ?? 'Academy update'; ?><a class="dashboard-panel notification-student-card <?= $isUnread ? 'is-unread' : '' ?>" href="<?= $escape($baseUrl) ?>/notifications/view?notification_id=<?= (int)$n['id'] ?>"><span class="notification-unread-dot" aria-hidden="true"></span>
                    <div>
                        <p class="eyebrow"><?= $escape(($isUnread ? 'NEW · ' : '') . $category) ?> · <?= $escape(date('M j, Y', strtotime((string) $n['published_at']))) ?></p>
                        <h2><?= $escape($n['title']) ?></h2>
                        <p><?= $escape(mb_strimwidth((string) $n['body'], 0, 200, '…')) ?></p><span class="dashboard-inline-link">Open update →</span>
                    </div>
                </a><?php endforeach; ?></section><?php endif; ?>

    <?php
    $usageEyebrow = 'Academy updates';
    $usageTitle = 'How to use your inbox';
    $usageLead = 'Everything the Academy has published to you, newest first.';
    $usageBullets = [
        'Open any card to read it in full. Opening marks that update as read, so the number beside the page it points at comes down.',
        'News lives here and is read-only. Private messages are available only on Chats.',
        'Mark all read when you are done. The button beside the count clears every unread item in one action.',
        'The sidebar numbers follow the link. A new lesson, test, assignment or payment decision counts on the page it points at, so a marked assignment shows up on Assignments.',
        'To contact the Academy, use Chats in the sidebar. Notifications do not open or create a conversation.',
        'Nothing disappears here. Read updates stay in the list, so an old announcement is never lost.',
    ];
    require BASE_PATH . '/app/Views/components/usage-guide.php';
    ?>
</section>
