<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $value): string => Security::escape((string) $value);
$user = isset($user) && is_array($user) ? $user : [];
$quizzes = is_array($quizzes ?? null) ? $quizzes : [];
$attempts = is_array($attempts ?? null) ? $attempts : [];
$totalAttemptLimit = 0;
$totalAttemptRemaining = 0;
foreach ($quizzes as $quizSummary) {
    $summaryLimit = (int) ($quizSummary['attempt_limit'] ?? 0);
    $totalAttemptLimit += $summaryLimit;
    $totalAttemptRemaining += max(0, $summaryLimit - (int) ($quizSummary['used_attempts'] ?? 0));
}
$minutes = static fn(mixed $seconds): int => max(1, (int) ceil((int) $seconds / 60));
$dateTimeLabel = static function (?string $value): string {
    if (!$value) {
        return '—';
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? '—' : date('M j, Y H:i', $timestamp);
};
?>
<section class="dashboard-page" data-reveal>
    <div class="dashboard-topbar">
        <button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
        <div class="dashboard-breadcrumb"><a href="<?= $escape($baseUrl) ?>/dashboard">Workspace</a><i>/</i><strong>Quizzes &amp; results</strong></div>
    </div>

    <header class="dashboard-heading">
        <div>
            <p class="eyebrow">COURSE ASSESSMENTS</p>
            <h1>Quizzes &amp; results</h1>
            <p>Every quiz released for your active courses, plus your complete attempt history.</p>
        </div>
    </header>

    <section class="dashboard-panel">
        <div class="dashboard-panel-heading quiz-attempts-heading">
            <div>
                <p class="eyebrow">OPEN NOW</p>
                <h2>Available quizzes</h2>
            </div>
            <?php if ($quizzes !== []): ?><span class="quiz-attempts-summary"><?= $totalAttemptRemaining ?> of <?= $totalAttemptLimit ?> attempt<?= $totalAttemptLimit === 1 ? '' : 's' ?> left</span><?php endif; ?>
        </div>
        <?php if ($quizzes === []): ?>
            <div class="dashboard-empty"><span class="dashboard-empty-icon">✓</span>
                <div>
                    <h3>No quiz is currently available</h3>
                    <p>Released assessments for your courses will appear here on their unlock date.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="dashboard-list">
                <?php foreach ($quizzes as $quiz): ?>
                    <article class="dashboard-list-row quiz-list-row">
                        <div class="dashboard-list-copy">
                            <strong><?= $escape($quiz['title']) ?></strong>
                            <small><?= $escape($quiz['course_title']) ?> · <?= (int) $quiz['question_count'] ?> questions · <?= $minutes($quiz['time_limit_seconds']) ?> min</small>
                            <small>Closes <?= $escape($dateTimeLabel($quiz['close_at'] ?? null)) ?></small>
                        </div>
                        <a class="quiz-open-btn" href="<?= $escape($baseUrl) ?>/learning/quizzes?course_id=<?= (int) $quiz['course_id'] ?>">Open quiz</a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="dashboard-panel">
        <div class="dashboard-panel-heading">
            <div>
                <p class="eyebrow">YOUR RECORD</p>
                <h2>Attempt history</h2>
            </div><span class="count-pill"><?= count($attempts) ?> attempt<?= count($attempts) === 1 ? '' : 's' ?></span>
        </div>
        <?php if ($attempts === []): ?>
            <div class="dashboard-empty"><span class="dashboard-empty-icon">◷</span>
                <div>
                    <h3>No attempts yet</h3>
                    <p>Your quiz attempts and graded results will appear here.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="dashboard-table-wrap">
                <table class="dashboard-table">
                    <thead>
                        <tr>
                            <th scope="col">Quiz</th>
                            <th scope="col">Course</th>
                            <th scope="col">Score</th>
                            <th scope="col">Result</th>
                            <th scope="col">Submitted</th>
                            <th scope="col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attempts as $attempt): ?>
                            <tr>
                                <td><strong>Week <?= (int) $attempt['week_number'] ?></strong><small><?= $escape($attempt['quiz_title']) ?></small></td>
                                <td><small><?= $escape($attempt['course_title']) ?></small></td>
                                <td><strong><?= $attempt['percentage'] !== null ? number_format((float) $attempt['percentage'], 1) . '%' : '—' ?></strong><?php if ($attempt['score'] !== null): ?><small><?= $escape($attempt['score']) ?> / <?= $escape($attempt['total_marks']) ?></small><?php endif; ?></td>
                                <td><span class="dashboard-status status-<?= $escape($attempt['status']) ?>"><?= $escape(ucwords(str_replace('_', ' ', (string) $attempt['status']))) ?></span></td>
                                <td><small><?= $escape($dateTimeLabel($attempt['submitted_at'] ?? null)) ?></small></td>
                                <td><?php if ($attempt['result'] !== null): ?><a class="dashboard-inline-link" href="<?= $escape($baseUrl) ?>/learning/quiz/result?attempt_id=<?= (int) $attempt['id'] ?>"><?= $escape(ucfirst($attempt['result'])) ?> · View</a><?php else: ?><small>—</small><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <?php
    $usageEyebrow = 'Quizzes & results';
    $usageTitle = 'How to use Quizzes & results';
    $usageLead = 'Every test you can open right now, and every attempt you have already made.';
    $usageBullets = [
        'Open a test inside its window.',
        'The clock runs on the server.',
        'Your score appears straight away.',
        'Ask for a retake when it counts.',
        'A number here is work, not a warning.',
    ];
    require BASE_PATH . '/app/Views/components/usage-guide.php';
    ?>

    <div class="dashboard-note"><strong>Grading is server-side</strong><span>Scores are calculated when your attempt is submitted and the deadline is enforced by the server, even if you leave the page.</span></div>
</section>