<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $v): string => Security::escape((string)$v);
$students = is_array($students ?? null) ? $students : [];
$notifications = is_array($notifications ?? null) ? $notifications : [];
$message = $message ?? null;
$error = $error ?? null; ?>
<section class="dashboard-page admin-workspace">
    <header class="admin-header">
        <div class="admin-header-main"><button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
            <p class="eyebrow">ADMIN WORKSPACE</p>
            <h1>Announcements</h1>
            <p>Publish academy updates to all verified students or selected recipients.</p>
        </div>
    </header>
    <?php \App\Core\View::toast('success', $message ?? null); ?>
    <?php \App\Core\View::toast('error', $error ?? null); ?>
    <section class="admin-card">
        <div class="admin-card-header">
            <div class="admin-card-titles">
                <h2>Compose announcement</h2>
                <p>Messages are stored in the LMS and linked to recipient accounts.</p>
            </div>
        </div>
        <form method="post" action="<?= $escape($baseUrl) ?>/admin/announcements/publish" class="admin-form"><input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
            <div class="admin-field"><label class="admin-label" for="announcement-title">Title <span class="req">*</span></label><input class="admin-input" id="announcement-title" name="title" required minlength="3" maxlength="200" placeholder="Write a short, clear headline"></div>
            <div class="admin-field"><label class="admin-label" for="announcement-body">Message <span class="req">*</span></label><textarea class="admin-textarea" id="announcement-body" name="body" rows="5" required maxlength="10000" placeholder="Share the update students need to read."></textarea></div>
            <div class="admin-field"><label class="admin-label" for="announcement-audience">Recipients <span class="req">*</span></label><select class="admin-select" id="announcement-audience" name="audience" data-notification-audience>
                    <option value="all_students">All active verified students</option>
                    <option value="selected_students">Choose students</option>
                </select></div>
            <fieldset class="notification-recipient-picker" data-notification-recipient-picker hidden>
                <legend class="admin-label">Select recipients</legend><?php if ($students === []): ?><p class="admin-hint">There are no active verified student accounts.</p><?php else: foreach ($students as $student): ?><label><input type="checkbox" name="student_ids[]" value="<?= (int)$student['id'] ?>"><span><strong><?= $escape($student['full_name']) ?></strong><small><?= $escape($student['email']) ?></small></span></label><?php endforeach;
                                                                                                                                                                                                                                                                                                                                                                                                                        endif; ?>
            </fieldset>
            <div class="form-actions"><button class="button" type="submit">Publish announcement</button></div>
        </form>
    </section>
    <section class="admin-card">
        <div class="admin-card-header">
            <div class="admin-card-titles">
                <h2>Published announcements</h2>
                <p>Latest <?= count($notifications) ?> announcement(s)</p>
            </div>
        </div>
        <?php if ($notifications === []): ?><div class="admin-empty-state">
                <h3>No announcements yet</h3>
                <p>Published messages and their private replies will appear here.</p>
            </div><?php else: ?><div class="notification-admin-list"><?php foreach ($notifications as $n): ?><article class="notification-admin-card">
                        <div>
                            <p class="eyebrow"><?= $n['audience'] === 'all_students' ? 'ALL VERIFIED STUDENTS' : 'SELECTED STUDENTS' ?> · <?= $escape(date('M j, Y H:i', strtotime($n['published_at'] ?? $n['created_at']))) ?></p>
                            <h3><?= $escape($n['title']) ?></h3>
                            <p><?= $escape(mb_strimwidth($n['body'], 0, 240, '…')) ?></p>
                        </div>
                        <div class="notification-admin-meta"><span><?= $escape($n['recipient_count']) ?> recipients</span><a class="dashboard-inline-link" href="<?= $escape($baseUrl) ?>/notifications/view?notification_id=<?= (int)$n['id'] ?>">Open announcement</a></div>
                    </article><?php endforeach; ?></div><?php endif; ?>
    </section>
    <section class="admin-card">
        <div class="admin-card-header">
            <div class="admin-card-titles">
                <h2>What happens after you publish</h2>
                <p>The board is only the sending side. Reading and answering lives on two other desks.</p>
            </div>
        </div>
        <div class="announcement-routing">
            <div class="announcement-routing-item"><strong>Chats</strong><span>Every private thread a student has opened, with the ones still waiting on an Academy answer counted in the sidebar.</span><a class="dashboard-inline-link" href="<?= $escape($baseUrl) ?>/admin/chats">Open the chat desk <span aria-hidden="true">→</span></a></div>
            <div class="announcement-routing-item"><strong>Recent activity</strong><span>The automated student updates the platform sent by itself and the e-mail delivery log, with the controls that clear both.</span><a class="dashboard-inline-link" href="<?= $escape($baseUrl) ?>/admin/activity">Open recent activity <span aria-hidden="true">→</span></a></div>
        </div>
    </section>
    <?php
    // The board is the one workspace a test round fills without meaning to: every published lesson,
    // quiz, assignment and payment decision leaves a notice behind. This clears all of it at once.
    $purgeAction = '/admin/announcements/purge';
    $purgePhrase = 'DELETE ALL ANNOUNCEMENTS';
    $purgeTitle = 'Clear the announcement board';
    $purgeDescription = 'Removes every announcement and automated student update with its recipient records and private replies, which also clears the unread badges in student sidebars.';
    $purgeCount = (int) ($notificationTotal ?? 0);
    require BASE_PATH . '/app/Views/components/purge-zone.php';
    ?>

    <?php
    $usageEyebrow = 'Announcement board';
    $usageTitle = 'How to use the announcement board';
    $usageLead = 'A quick walkthrough of the workflow this page manages end to end.';
    $usageBullets = [
        'Choose the audience first. All active verified students reaches every verified account, while Choose students lets you tick the exact list from the same active set.',
        'The title is what gets read. Students see it in their inbox and in the sidebar number that lights up against it, so keep the headline short and put the detail in the message.',
        'Publishing is the point of no editing. A sent announcement has no draft or unpublish control here, so proof-read the body before you press Publish.',
        'Announcements are read-only Academy news for students.',
        'Every private message is handled on Chats, where you can select a student and reply directly.',
        'Automated updates live on Recent activity. Lessons, tests, assignments, payments, grades and certificates notify students on their own, and that report is now the last entry in the sidebar.',
        'The board is built to be emptied. Clearing it removes every announcement and automated update with its replies, which also resets the unread numbers in student sidebars.',
    ];
    require BASE_PATH . '/app/Views/components/usage-guide.php';
    ?>
</section>
