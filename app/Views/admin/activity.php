<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $value): string => Security::escape((string) $value);
$automated = is_array($automated ?? null) ? $automated : [];
$emailLog = is_array($emailLog ?? null) ? $emailLog : [];
$viewLog = is_array($viewLog ?? null) ? $viewLog : [];
$viewLogTotal = (int) ($viewLogTotal ?? 0);
$viewRetentionDays = max(1, (int) ($viewRetentionDays ?? 30));
$unreadTotal = (int) ($unreadTotal ?? 0);
$automatedTotal = (int) ($automatedTotal ?? 0);
$emailTotal = (int) ($emailTotal ?? 0);
$message = $message ?? null;
$error = $error ?? null; ?>
<section class="dashboard-page admin-workspace">
    <header class="admin-header">
        <div class="admin-header-main"><button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
            <p class="eyebrow">ADMIN WORKSPACE</p>
            <h1>Recent activity</h1>
            <p>What the platform sent on its own and what the mail server accepted, on one report you can clear between test rounds.</p>
        </div>
    </header>
    <?php \App\Core\View::toast('success', $message ?? null); ?>
    <?php \App\Core\View::toast('error', $error ?? null); ?>
    <section class="admin-card">
        <div class="admin-card-header">
            <div class="admin-card-titles">
                <h2>Automated student updates</h2>
                <p><?= $unreadTotal > 0 ? $unreadTotal . ' unread notification(s) are still waiting in student workspaces.' : 'Every notification sent so far has been read.' ?> Lessons, tests, assignments, payments, grades and certificates announce themselves here.</p>
            </div>
            <form method="post" action="<?= $escape($baseUrl) ?>/admin/activity/automated/clear" class="student-status-form">
                <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
                <button class="student-action-button is-clear" type="submit"<?= $automatedTotal === 0 ? ' disabled' : '' ?>>Clear updates</button>
            </form>
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
            <form method="post" action="<?= $escape($baseUrl) ?>/admin/activity/email-log/clear" class="student-status-form" onsubmit="return confirm('Clear every recorded email delivery? The log starts again with the next message.')">
                <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
                <button class="student-action-button is-clear" type="submit"<?= $emailLog === [] ? ' disabled' : '' ?>>Clear delivery log</button>
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
    // The player writes this one. Nothing here is a judgement: a report never changes a student's watch
    // time or grade, it only tells the Academy that something around a recording was tried.
    ?>
    <section class="admin-card">
        <div class="admin-card-header">
            <div class="admin-card-titles">
                <h2>Lesson view log</h2>
                <p>The latest <?= count($viewLog) ?><?= $viewLogTotal > count($viewLog) ? ' of ' . $viewLogTotal : '' ?> unusual things tried around a lesson recording - kept for <?= $viewRetentionDays ?> day(s). It changes no student's watch time or grade.</p>
            </div>
            <form method="post" action="<?= $escape($baseUrl) ?>/admin/activity/view-log/clear" class="student-status-form" onsubmit="return confirm('Clear every recorded viewing report? The log starts again with the next blocked action.')">
                <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
                <button class="student-action-button is-clear" type="submit"<?= $viewLog === [] ? ' disabled' : '' ?>>Clear viewing log</button>
            </form>
        </div>
        <?php if ($viewLog === []): ?><div class="admin-empty-state">
                <h3>No viewing reports recorded yet</h3>
                <p>A blocked download, a refused fullscreen, a right-click on the player or a large jump forward appears here once students are watching, and the lesson_view_events table is imported.</p>
            </div><?php else: ?><div class="admin-table-wrapper"><table class="admin-table admin-table-stacked"><thead><tr><th>Student</th><th>What happened</th><th>Lesson</th><th>Course</th><th>Address</th><th>Reported</th></tr></thead><tbody>
                    <?php foreach ($viewLog as $row): ?><tr>
                            <td data-label="Student"><strong><?= $escape($row['student_name']) ?></strong><br><code><?= $escape($row['student_reference']) ?></code></td>
                            <td data-label="What happened" title="<?= $escape($row['detail'] ?? '') ?>"><span class="dashboard-status"><?= $escape(\App\Services\LessonProtection::label((string) $row['event_type'])) ?></span></td>
                            <td data-label="Lesson"><?= $row['lesson_title'] !== null ? $escape($row['lesson_title']) : 'Account session' ?></td>
                            <td data-label="Course"><?= $escape($row['course_title'] ?? '—') ?></td>
                            <td data-label="Address"><code><?= $escape($row['ip_address'] ?? '—') ?></code></td>
                            <td data-label="Reported"><?= $escape(date('M j, Y H:i', strtotime((string) $row['created_at']))) ?> UTC</td>
                        </tr><?php endforeach; ?>
                </tbody></table></div><?php endif; ?>
    </section>
    <?php
    $usageEyebrow = 'Platform report';
    $usageTitle = 'How to use recent activity';
    $usageLead = 'Two read-only reports, plus the controls that empty them before the next test round.';
    $usageBullets = [
        'The first table is fan-out.',
        'Open any update to check it.',
        'The unread column is student-side.',
        'The delivery log proves the send, not the read.',
        'The viewing log records player resistance.',
        'Clearing the viewing log empties a report.',
        'Clear delivery log empties one table.',
        'Clear recent activity empties both.',
        'Announcements are never touched here.',
    ];
    require BASE_PATH . '/app/Views/components/usage-guide.php';
    ?>

    <div class="dashboard-note"><strong>Nothing here is edited</strong><span>These tables are generated by the workspace, so this page offers no save control. Change what the platform does on the pages that produced the activity, and the report updates itself.</span></div>
</section>
