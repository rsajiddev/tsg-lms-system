<?php

use App\Core\Security;
use App\Services\ReportSharing;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $value): string => Security::escape((string) $value);
$user = isset($user) && is_array($user) ? $user : [];
$shares = is_array($shares ?? null) ? $shares : [];
$dateTimeLabel = static function (?string $value): string {
    if (!$value) {
        return '—';
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? '—' : date('M j, Y · g:i A', $timestamp);
};
// The share row carries the window as it was the moment it was sent, so the page can name the
// same period the document describes — even if that is now months ago.
$rangeLabel = static function (array $share): string {
    $period = ReportSharing::periodFromBounds(
        (string) ($share['period_key'] ?? 'monthly'),
        (string) ($share['period_start'] ?? ''),
        (string) ($share['period_end'] ?? '')
    );
    return $period['start_date'] . ' – ' . $period['end_date'];
};
$latest = $shares === [] ? '—' : $rangeLabel($shares[0]);
?>
<section class="dashboard-page student-reports-page" data-reveal>
    <div class="dashboard-topbar">
        <button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
        <div class="dashboard-breadcrumb"><a href="<?= $escape($baseUrl) ?>/dashboard">Workspace</a><i>/</i><strong>Reports</strong></div>
    </div>

    <header class="dashboard-heading">
        <div>
            <p class="eyebrow">ACADEMY RECORDS</p>
            <h1>Reports</h1>
            <p>Every report an administrator has shared with you, ready to download as a PDF.</p>
        </div>
    </header>

    <section class="dashboard-summary-card" aria-label="Report summary">
        <div class="dashboard-summary-heading">
            <div><p class="eyebrow">SHARED WITH YOU</p><h2>Your report shelf</h2></div>
            <span><?= number_format(count($shares)) ?> report<?= count($shares) === 1 ? '' : 's' ?></span>
        </div>
        <div class="dashboard-metrics" aria-label="Report totals">
            <article class="dashboard-metric"><span class="dashboard-metric-icon">01</span><span class="dashboard-metric-label">Reports shared</span><strong><?= number_format(count($shares)) ?></strong><small>Documents the academy sent to you</small></article>
            <article class="dashboard-metric"><span class="dashboard-metric-icon">02</span><span class="dashboard-metric-label">Most recent period</span><strong><?= $escape($latest) ?></strong><small>The window the newest report covers</small></article>
            <article class="dashboard-metric"><span class="dashboard-metric-icon">03</span><span class="dashboard-metric-label">What you see</span><strong>Your own</strong><small>Attendance, tests and submissions — never another student's</small></article>
        </div>
    </section>

    <section class="dashboard-panel">
        <div class="dashboard-panel-heading">
            <div><h2>Available reports</h2><p>Each download is built fresh from your saved records for the period shown.</p></div>
        </div>
        <?php if ($shares === []): ?>
            <div class="dashboard-empty"><span class="dashboard-empty-icon">◷</span>
                <div>
                    <h3>No report shared yet</h3>
                    <p>When an administrator sends you a report, it appears here with a download link and a notice in your inbox.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="dashboard-table-wrap">
                <table class="dashboard-table">
                    <thead>
                        <tr>
                            <th scope="col">Report</th>
                            <th scope="col">Period</th>
                            <th scope="col">Sent by</th>
                            <th scope="col">Sent on</th>
                            <th scope="col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($shares as $share): ?>
                            <tr>
                                <td data-label="Report"><strong><?= $escape(ReportSharing::label((string) $share['report_type'])) ?></strong><small><?= $escape(ucfirst((string) $share['period_key'])) ?> report</small></td>
                                <td data-label="Period"><small><?= $escape($rangeLabel($share)) ?></small></td>
                                <td data-label="Sent by"><small><?= $escape($share['sender_name'] ?? '—') ?></small></td>
                                <td data-label="Sent on"><small><?= $escape($dateTimeLabel($share['created_at'] ?? null)) ?></small></td>
                                <td data-label="Download"><a class="dashboard-inline-link" href="<?= $escape($baseUrl) ?>/student/reports/download?share_id=<?= (int) $share['id'] ?>">Download PDF</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <?php
    $usageEyebrow = 'Reports';
    $usageTitle = 'How to use Reports';
    $usageLead = 'Reports the academy has prepared for you, one download each.';
    $usageBullets = [
        'An administrator sends a report; you do not request one. When something is shared, it lands here and a notice appears in your inbox.',
        'Every document holds only your records. Attendance, quiz attempts and assignment submissions are yours alone, never the class list.',
        'The period is the one that was chosen when it was sent. A report shared in November still describes November, however late you open it.',
        'Downloads are rebuilt when you ask for them, so a correction the academy saves later is reflected the next time you download.',
        'A badge on this entry counts the reports that arrived since you last looked; opening the page settles it.',
    ];
    require BASE_PATH . '/app/Views/components/usage-guide.php';
    ?>
</section>
