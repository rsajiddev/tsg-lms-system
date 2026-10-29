<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $v): string => Security::escape((string)$v);
$notifications = is_array($notifications ?? null) ? $notifications : [];
$message = $message ?? null;
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
    </header><?php if ($message): ?><div class="admin-alert admin-alert-success" role="status"><?= $escape($message) ?></div><?php endif; ?>
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
                        <p><?= $escape(mb_strimwidth((string) $n['body'], 0, 240, '…')) ?></p><span class="dashboard-inline-link">Read and reply →</span>
                    </div>
                </a><?php endforeach; ?></section><?php endif; ?>
</section>