<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $value): string => Security::escape((string)$value);
$attempts = is_array($attempts ?? null) ? $attempts : [];
$search = is_string($search ?? null) ? $search : '';
$type = is_string($type ?? null) ? $type : 'all';
$resultFilter = is_string($resultFilter ?? null) ? $resultFilter : 'all';
$summary = is_array($summary ?? null) ? $summary : [];
$total = max(0, (int)($total ?? 0));
$page = max(1, (int)($page ?? 1));
$pages = max(1, (int)($pages ?? 1));
$formatDate = static function (?string $value): string {
    if ($value === null || $value === '') return '—';
    $timestamp = strtotime($value . ' UTC');
    return $timestamp === false ? '—' : gmdate('M j, Y · H:i', $timestamp) . ' UTC';
};
$formatDuration = static function (mixed $seconds): string {
    if ($seconds === null || !is_numeric($seconds)) return '—';
    $seconds = max(0, (int)$seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $remainingSeconds = $seconds % 60;
    if ($hours > 0) return sprintf('%dh %02dm', $hours, $minutes);
    if ($minutes > 0) return sprintf('%dm %02ds', $minutes, $remainingSeconds);
    return $remainingSeconds . ' sec';
};
$typeLabels = [
    'practice' => 'Practice · unlimited',
    'lesson' => 'Lesson / weekly quiz',
    'module' => 'Module test',
    'final' => 'Final test',
];
$queryForPage = static function (int $targetPage) use ($search, $type, $resultFilter): string {
    return http_build_query(['q' => $search, 'type' => $type, 'result' => $resultFilter, 'page' => $targetPage]);
};
?>
<section class="dashboard-page quiz-reports-page" data-reveal>
    <div class="dashboard-topbar">
        <button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
        <div class="dashboard-breadcrumb"><span>Workspace</span><i>/</i><strong>Quiz reports</strong></div>
        <form class="dashboard-search" method="get" action="<?= $escape($baseUrl) ?>/admin/quiz-reports" role="search">
            <span aria-hidden="true">⌕</span>
            <input type="search" name="q" value="<?= $escape($search) ?>" placeholder="Search students, courses or quizzes" aria-label="Search quiz reports">
            <input type="hidden" name="type" value="<?= $escape($type) ?>">
            <input type="hidden" name="result" value="<?= $escape($resultFilter) ?>">
        </form>
    </div>

    <header class="dashboard-heading">
        <div>
            <p class="eyebrow">ASSESSMENT ACTIVITY</p>
            <h1>Quiz reports</h1>
            <p>Review student results, completion time, quiz type and submission status.</p>
        </div>
    </header>

    <div class="quiz-report-metrics" aria-label="Filtered quiz report totals">
        <article class="quiz-report-metric"><span>Completed attempts</span><strong><?= number_format((int)($summary['total'] ?? 0)) ?></strong><small>Matching the current filters</small></article>
        <article class="quiz-report-metric quiz-report-metric-pass"><span>Passed</span><strong><?= number_format((int)($summary['passed'] ?? 0)) ?></strong><small>Met the quiz pass mark</small></article>
        <article class="quiz-report-metric quiz-report-metric-fail"><span>Failed</span><strong><?= number_format((int)($summary['failed'] ?? 0)) ?></strong><small>Below the quiz pass mark</small></article>
    </div>

    <section class="dashboard-panel quiz-report-panel" aria-label="Quiz attempt reports">
        <div class="dashboard-panel-heading quiz-report-heading">
            <div>
                <p class="eyebrow">STUDENT SUBMISSIONS</p>
                <h2><?= number_format($total) ?> attempt<?= $total === 1 ? '' : 's' ?></h2>
            </div>
            <a class="button button-secondary" href="<?= $escape($baseUrl) ?>/admin/quizzes">Manage quizzes</a>
        </div>

        <form class="quiz-report-filters" method="get" action="<?= $escape($baseUrl) ?>/admin/quiz-reports">
            <label class="quiz-report-search-field">Search
                <input class="admin-input" type="search" name="q" value="<?= $escape($search) ?>" placeholder="Student, email, course or quiz">
            </label>
            <label>Quiz type
                <select class="admin-select" name="type">
                    <option value="all" <?= $type === 'all' ? 'selected' : '' ?>>All quiz types</option>
                    <?php foreach ($typeLabels as $value => $label): ?><option value="<?= $escape($value) ?>" <?= $type === $value ? 'selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Outcome
                <select class="admin-select" name="result">
                    <option value="all" <?= $resultFilter === 'all' ? 'selected' : '' ?>>Pass and fail</option>
                    <option value="pass" <?= $resultFilter === 'pass' ? 'selected' : '' ?>>Passed</option>
                    <option value="fail" <?= $resultFilter === 'fail' ? 'selected' : '' ?>>Failed</option>
                </select>
            </label>
            <div class="quiz-report-filter-actions"><button class="button" type="submit">Apply filters</button><a class="button button-secondary" href="<?= $escape($baseUrl) ?>/admin/quiz-reports">Clear</a></div>
        </form>

        <?php if ($attempts === []): ?>
            <div class="dashboard-empty quiz-report-empty">
                <span class="dashboard-empty-icon" aria-hidden="true">✓</span>
                <div><h3>No completed quiz attempts found</h3><p>Try changing the filters. New submissions will appear here after students complete a quiz.</p></div>
            </div>
        <?php else: ?>
            <div class="quiz-report-list">
                <?php foreach ($attempts as $attempt):
                    $outcome = in_array($attempt['result'] ?? '', ['pass', 'fail'], true) ? (string)$attempt['result'] : 'pending';
                    $submissionStatus = (string)($attempt['status'] ?? 'submitted');
                    $quizType = (string)($attempt['quiz_type'] ?? 'lesson');
                    $correctCount = $attempt['correct_count'] === null ? '—' : (string)(int)$attempt['correct_count'];
                    $questionCount = max(0, (int)($attempt['question_count'] ?? 0));
                    $score = $attempt['score'] === null || $attempt['total_marks'] === null
                        ? '—'
                        : number_format((float)$attempt['score'], 2) . ' / ' . number_format((float)$attempt['total_marks'], 2);
                    $percentage = $attempt['percentage'] === null ? '—' : number_format((float)$attempt['percentage'], 1) . '%';
                    $weekLabel = $quizType === 'practice' ? 'Open practice' : (($attempt['week_number'] ?? null) ? 'Week ' . (int)$attempt['week_number'] : 'Assessment');
                ?>
                    <article class="quiz-report-card">
                        <div class="quiz-report-card-header">
                            <div class="quiz-report-card-title">
                                <span class="quiz-report-type-mark" aria-hidden="true">Q</span>
                                <div><p class="eyebrow">ATTEMPT #<?= (int)$attempt['id'] ?> · <?= $escape($typeLabels[$quizType] ?? 'Quiz') ?></p><h3><?= $escape($attempt['quiz_title'] ?? 'Quiz') ?></h3></div>
                            </div>
                            <span class="quiz-outcome-badge quiz-outcome-<?= $escape($outcome) ?>"><?= $escape($outcome === 'pending' ? 'Result unavailable' : ucfirst($outcome)) ?></span>
                        </div>
                        <div class="quiz-report-student">
                            <strong><?= $escape($attempt['student_name'] ?? 'Student') ?></strong>
                            <span>Student ID <?= (int)($attempt['student_id'] ?? 0) ?> · <?= $escape($attempt['student_email'] ?? '') ?></span>
                        </div>
                        <div class="quiz-report-course-line"><span>Course</span><strong><?= $escape($attempt['course_title'] ?? '—') ?></strong><span class="quiz-report-week"><?= $escape($weekLabel) ?></span></div>
                        <dl class="quiz-report-details">
                            <div><dt>Score</dt><dd><?= $escape($score) ?> <small>(<?= $escape($percentage) ?>)</small></dd></div>
                            <div><dt>Correct answers</dt><dd class="quiz-correct-count"><?= $escape($correctCount) ?> <small>/ <?= $questionCount ?> questions</small></dd></div>
                            <div><dt>Pass mark</dt><dd><?= number_format((float)($attempt['pass_mark_percent'] ?? 0), 1) ?>%</dd></div>
                            <div><dt>Time taken</dt><dd><?= $escape($formatDuration($attempt['duration_seconds'] ?? null)) ?></dd></div>
                            <div><dt>Started</dt><dd><?= $escape($formatDate($attempt['started_at'] ?? null)) ?></dd></div>
                            <div><dt>Submitted</dt><dd><?= $escape($formatDate($attempt['submitted_at'] ?? null)) ?></dd></div>
                        </dl>
                        <div class="quiz-report-card-footer">
                            <span class="quiz-submission-state">Submission: <?= $escape(ucwords(str_replace('_', ' ', $submissionStatus))) ?><?= !empty($attempt['submission_reason']) ? ' · ' . $escape(ucwords(str_replace('_', ' ', (string)$attempt['submission_reason']))) : '' ?></span>
                            <span><?= $questionCount ?> question<?= $questionCount === 1 ? '' : 's' ?> · attempt <?= (int)$attempt['id'] ?></span>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if ($pages > 1): ?>
                <nav class="quiz-report-pagination" aria-label="Quiz report pages">
                    <span>Page <?= $page ?> of <?= $pages ?></span>
                    <div>
                        <?php if ($page > 1): ?><a class="button button-secondary" href="<?= $escape($baseUrl) ?>/admin/quiz-reports?<?= $escape($queryForPage($page - 1)) ?>">Previous</a><?php endif; ?>
                        <?php if ($page < $pages): ?><a class="button" href="<?= $escape($baseUrl) ?>/admin/quiz-reports?<?= $escape($queryForPage($page + 1)) ?>">Next</a><?php endif; ?>
                    </div>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <?php
    $usageEyebrow = 'Attempt reports';
    $usageTitle = 'How to use quiz reports';
    $usageLead = 'A quick walkthrough of the workflow this page manages end to end.';
    $usageBullets = [
        'Search across everything. One query matches students, courses and quiz titles at the same time.',
        'Filter by quiz type. Practice, lesson, module and final attempts each carry their own policy, so splitting them keeps the numbers honest.',
        'Pass and fail chips narrow the list. Use them to pull weak spots or to shortlist the attempts that deserve a manual look.',
        'Open any card for detail. Each attempt expands to its score, pass mark, time taken and the exact start and submission timestamps in UTC.',
        'Timeouts are marked. The submission state shows whether a student answered, finished early or was submitted by the clock.',
        'Reports are read-only. Change a quiz itself on the Quiz management page — this list always mirrors the latest attempt history.',
    ];
    require BASE_PATH . '/app/Views/components/usage-guide.php';
    ?>
</section>
