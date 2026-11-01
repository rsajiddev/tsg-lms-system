<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $value): string => Security::escape((string) $value);
$automated = is_array($automated ?? null) ? $automated : [];
$emailLog = is_array($emailLog ?? null) ? $emailLog : [];
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
    // Both tables above are reports the platform fills itself, so a test round writes straight into
    // them. This is the one card that empties them together without touching an announcement an
    // admin published by hand.
    $purgeAction = '/admin/activity/clear';
    $purgePhrase = 'CLEAR RECENT ACTIVITY';
    $purgeTitle = 'Clear recent activity';
    $purgeDescription = 'Removes the automated student updates with their recipient records and private replies, and empties the e-mail delivery log. Announcements you published stay on the board.';
    $purgeCount = $automatedTotal + $emailTotal;
    require BASE_PATH . '/app/Views/components/purge-zone.php';
    ?>

    <?php
    $usageEyebrow = 'Platform report';
    $usageTitle = 'How to use recent activity';
    $usageLead = 'Two read-only reports, plus the controls that empty them before the next test round.';
    $usageBullets = [
        'The first table is fan-out. Every lesson, test, assignment, payment decision, grade and certificate the platform published is listed with its recipients and how many of them are still unread.',
        'Open any update to check it. The link shows the notice with the list of students it reached, which is the quickest way to confirm the wording after a change.',
        'The unread column is student-side. It counts the same records the sidebar numbers do, so a row stuck above zero means someone has not looked yet.',
        'The delivery log proves the send, not the read. It shows the latest automated emails and whether the mail server accepted them; message bodies are never stored.',
        'Clear delivery log empties one table. Use it between test rounds to start the mail history again while every notification stays where it is.',
        'Clear recent activity empties both. Automated updates, their recipient rows, the replies filed under them and the whole log go, and the unread numbers in student sidebars drop with them.',
        'Announcements are never touched here. Anything you wrote on the Announcements page stays on the board, and its own clearing card lives there.',
    ];
    require BASE_PATH . '/app/Views/components/usage-guide.php';
    ?>

    <div class="dashboard-note"><strong>Nothing here is edited</strong><span>These tables are generated by the workspace, so this page offers no save control. Change what the platform does on the pages that produced the activity, and the report updates itself.</span></div>
</section>
