<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $v): string => Security::escape((string)$v);
$students = is_array($students ?? null) ? $students : [];
$notifications = is_array($notifications ?? null) ? $notifications : [];
$automated = is_array($automated ?? null) ? $automated : [];
$emailLog = is_array($emailLog ?? null) ? $emailLog : [];
$unreadTotal = (int) ($unreadTotal ?? 0);
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
    <?php if ($message): ?><div class="admin-alert admin-alert-success" role="status"><?= $escape($message) ?></div><?php endif; ?>
    <?php if ($error ?? null): ?><div class="admin-alert admin-alert-error" role="alert"><?= $escape($error) ?></div><?php endif; ?>
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
                        <div class="notification-admin-meta"><span><?= $escape($n['recipient_count']) ?> recipients</span><span><?= $escape($n['reply_count']) ?> replies</span><a class="dashboard-inline-link" href="<?= $escape($baseUrl) ?>/notifications/view?notification_id=<?= (int)$n['id'] ?>">Open conversation</a></div>
                    </article><?php endforeach; ?></div><?php endif; ?>
    </section>
    <section class="admin-card">
        <div class="admin-card-header">
            <div class="admin-card-titles">
                <h2>Automated student updates</h2>
                <p><?= $unreadTotal > 0 ? $unreadTotal . ' unread notification(s) are still waiting in student workspaces.' : 'Every notification sent so far has been read.' ?> Lessons, tests, assignments, payments, grades and certificates announce themselves here.</p>
            </div>
        </div>
        <?php if ($automated === []): ?><div class="admin-empty-state">
                <h3>No automated updates yet</h3>
                <p>Publish a lesson, test, assignment or payment decision and the enrolled students are notified automatically.</p>
            </div><?php else: ?><div class="admin-table-wrapper"><table class="admin-table admin-table-stacked"><thead><tr><th>Update</th><th>Area</th><th>Recipients</th><th>Unread</th><th>Published</th></tr></thead><tbody>
                    <?php foreach ($automated as $row): ?><tr>
                            <td data-label="Update"><a href="<?= $escape($baseUrl) ?>/notifications/view?notification_id=<?= (int) $row['id'] ?>"><?= $escape($row['title']) ?></a></td>
                            <td data-label="Area"><span class="dashboard-status"><?= $escape(ucfirst((string) $row['category'])) ?></span></td>
                            <td data-label="Recipients"><?= (int) $row['recipient_count'] ?></td>
                            <td data-label="Unread"><?= (int) $row['unread_count'] ?></td>
                            <td data-label="Published"><?= $escape(date('M j, Y H:i', strtotime((string) $row['published_at']))) ?> UTC</td>
                        </tr><?php endforeach; ?>
                </tbody></table></div><?php endif; ?>
    </section>
    <section class="admin-card">
        <div class="admin-card-header">
            <div class="admin-card-titles">
                <h2>Email delivery log</h2>
                <p>The latest <?= count($emailLog) ?> automated emails and whether SMTP accepted them. Message bodies are never stored.</p>
            </div>
            <form method="post" action="<?= $escape($baseUrl) ?>/admin/announcements/email-log/clear" class="student-status-form" onsubmit="return confirm('Clear every recorded email delivery? The log starts again with the next message.')">
                <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
                <button class="student-action-button is-clear" type="submit"<?= $emailLog === [] ? ' disabled' : '' ?>>Clear activity</button>
            </form>
        </div>
        <?php if ($emailLog === []): ?><div class="admin-empty-state">
                <h3>No emails recorded yet</h3>
                <p>Every message the platform sends is logged here once the email_logs table is imported.</p>
            </div><?php else: ?><div class="admin-table-wrapper"><table class="admin-table admin-table-stacked"><thead><tr><th>Event</th><th>Recipient</th><th>Subject</th><th>Status</th><th>Sent</th></tr></thead><tbody>
                    <?php foreach ($emailLog as $row): ?><tr>
                            <td data-label="Event"><code><?= $escape($row['template_key']) ?></code></td>
                            <td data-label="Recipient"><?= $escape($row['recipient_email']) ?></td>
                            <td data-label="Subject" title="<?= $escape($row['error_message'] ?? '') ?>"><?= $escape($row['subject']) ?></td>
                            <td data-label="Status"><span class="dashboard-status status-<?= $row['status'] === 'sent' ? 'approved' : 'rejected' ?>"><?= $escape(strtoupper((string) $row['status'])) ?></span></td>
                            <td data-label="Sent"><?= $escape(date('M j, Y H:i', strtotime((string) $row['created_at']))) ?> UTC</td>
                        </tr><?php endforeach; ?>
                </tbody></table></div><?php endif; ?>
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
</section>