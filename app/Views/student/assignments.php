<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $value): string => Security::escape((string) $value);
$user = isset($user) && is_array($user) ? $user : [];
$assignments = is_array($assignments ?? null) ? $assignments : [];
$dueLabel = static function (?string $value): string {
    if (!$value) {
        return 'No deadline';
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? 'No deadline' : date('M j, Y H:i', $timestamp);
};
$statusLabel = static function (?string $value): string {
    $value = trim((string) $value);
    if ($value === '') {
        return 'Not submitted';
    }
    return ucwords(str_replace('_', ' ', $value));
};
?>
<section class="dashboard-page" data-reveal>
    <div class="dashboard-topbar">
        <button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
        <div class="dashboard-breadcrumb"><a href="<?= $escape($baseUrl) ?>/dashboard">Workspace</a><i>/</i><strong>Assignments</strong></div>
    </div>

    <header class="dashboard-heading">
        <div>
            <p class="eyebrow">PRACTICAL COURSE WORK</p>
            <h1>Assignments</h1>
            <p>Published tasks for your active courses, with your latest submission and feedback.</p>
        </div>
    </header>

    <section class="dashboard-panel">
        <div class="dashboard-panel-heading">
            <div>
                <p class="eyebrow">CATALOGUE</p>
                <h2>Your assignments</h2>
            </div><span class="count-pill"><?= count($assignments) ?> task<?= count($assignments) === 1 ? '' : 's' ?></span>
        </div>
        <?php if ($assignments === []): ?>
            <div class="dashboard-empty"><span class="dashboard-empty-icon">A</span>
                <div>
                    <h3>No assignments published yet</h3>
                    <p>Practical work released for your courses will appear here with its deadline.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="dashboard-list">
                <?php foreach ($assignments as $assignment):
                    $overdue = !empty($assignment['due_at']) && strtotime((string) $assignment['due_at']) < time();
                    $badge = $statusLabel($assignment['submission_status'] ?? null);
                ?>
                    <article class="dashboard-list-row student-assignment-row">
                        <span class="dashboard-list-icon">A<?= (int) $assignment['week_number'] ?: '' ?></span>
                        <div class="dashboard-list-copy">
                            <strong><?= $escape($assignment['title']) ?></strong>
                            <small><?= $escape($assignment['course_title']) ?><?= !empty($assignment['week_number']) ? ' · Week ' . (int) $assignment['week_number'] : '' ?> · <?= $escape($assignment['max_marks']) ?> marks</small>
                            <small><?= $dueLabel($assignment['due_at'] ?? null) ?><?= $overdue && ($assignment['status'] ?? '') !== 'published' ? ' (closed)' : '' ?></small>
                            <?php if (!empty($assignment['feedback'])): ?><small>Feedback: <?= $escape($assignment['feedback']) ?></small><?php endif; ?>
                        </div>
                        <div class="student-assignment-actions">
                            <span class="dashboard-status status-<?= $escape(($assignment['submission_status'] ?? '') !== '' ? str_replace('resubmit_requested', 'pending', (string) $assignment['submission_status']) : 'draft') ?>"><?= $escape($badge) ?></span>
                            <a class="dashboard-inline-link" href="<?= $escape($baseUrl) ?>/learning/assignments?course_id=<?= (int) $assignment['course_id'] ?>"><?= ($assignment['submission_status'] ?? '') === '' ? 'Submit work' : 'View &amp; resubmit' ?> <span aria-hidden="true">→</span></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <div class="dashboard-note"><strong>Submission window</strong><span>A published assignment accepts up to five submissions. Once its deadline passes the academy closes it and only the last reviewed attempt is kept.</span></div>

    <?php
    $usageEyebrow = 'Assignments';
    $usageTitle = 'How to use Assignments';
    $usageLead = 'Upload the work, meet the deadline and read the feedback your instructor leaves.';
    $usageBullets = [
        'Check the deadline first. Work sent after it is flagged as late, and the marking scheme can reduce the score.',
        'Upload a supported file. PDF, image, zip or text submissions are stored privately — only you and the academy can open them.',
        'You get five submissions at most. A fresh upload needs the academy to ask for a resubmission first, and they review the latest one.',
        'Feedback comes back here. A review puts the marks, the rubric scores and your instructor’s notes next to the submission.',
        'A resubmission request reopens the card. Read the note, fix the work and upload again — the number tells you it is waiting.',
    ];
    require BASE_PATH . '/app/Views/components/usage-guide.php';
    ?>
</section>
