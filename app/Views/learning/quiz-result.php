<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $v): string => Security::escape((string)$v);
/** @var array<string, mixed> $attempt */
$attempt = isset($attempt) && is_array($attempt) ? $attempt : [];
$answers = is_array($answers ?? null) ? $answers : [];
$percentage = max(0.0, min(100.0, (float)$attempt['percentage']));
$isPass = ($attempt['result'] ?? '') === 'pass';
$ringRadius = 84;
$ringCircumference = 2 * M_PI * $ringRadius;
$ringOffset = $ringCircumference * (1 - $percentage / 100);
$resultIcon = static fn(string $name): string => match ($name) {
  'check' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>',
  'x' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>',
  'target' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r="1" fill="currentColor" stroke="none"/></svg>',
  'list' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 6h13M8 12h13M8 18h13"/><circle cx="3.5" cy="6" r="1.2" fill="currentColor" stroke="none"/><circle cx="3.5" cy="12" r="1.2" fill="currentColor" stroke="none"/><circle cx="3.5" cy="18" r="1.2" fill="currentColor" stroke="none"/></svg>',
  'clock' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></svg>',
  'user' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6.5 8-6.5s8 2.5 8 6.5"/></svg>',
  'book' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4.5C10.5 3.1 8.3 2.5 5 2.5c-.8 0-1.5.7-1.5 1.5v13c0 .8.7 1.5 1.5 1.5 3.3 0 5.5.6 7 2 1.5-1.4 3.7-2 7-2 .8 0 1.5-.7 1.5-1.5V4c0-.8-.7-1.5-1.5-1.5-3.3 0-5.5.6-7 2Z"/><path d="M12 4.5v16"/></svg>',
  default => '',
};
?>
<section class="dashboard-page learning-page quiz-result-page">
  <div class="dashboard-topbar"><button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
    <div class="dashboard-breadcrumb"><a href="<?= $escape($baseUrl) ?>/dashboard">Workspace</a><i>/</i><a href="<?= $escape($baseUrl) ?>/learning/quizzes?course_id=<?= (int)$attempt['course_id'] ?>"><?= $escape($attempt['course_title']) ?> quizzes</a><i>/</i><strong>Result</strong></div>
  </div>
  <section class="dashboard-panel quiz-result-card">
    <header class="quiz-result-hero <?= $isPass ? 'is-pass' : 'is-fail' ?>">
      <div class="quiz-result-hero-text">
        <p class="eyebrow">WEEK <?= (int)$attempt['week_number'] ?> · QUIZ RESULT</p>
        <h1><?= $escape($attempt['title']) ?></h1>
        <span class="quiz-result-verdict"><?= $isPass ? $resultIcon('check') : $resultIcon('x') ?><?= $escape(ucfirst($attempt['result'] ?? 'pending')) ?></span>
      </div>
      <div class="quiz-result-ring" role="img" aria-label="Score: <?= number_format($percentage, 1) ?> percent">
        <svg viewBox="0 0 200 200" aria-hidden="true">
          <circle class="quiz-result-ring-track" cx="100" cy="100" r="<?= $ringRadius ?>"/>
          <circle class="quiz-result-ring-value" cx="100" cy="100" r="<?= $ringRadius ?>" stroke-dasharray="<?= number_format($ringCircumference, 2) ?>" stroke-dashoffset="<?= number_format($ringOffset, 2) ?>"/>
        </svg>
        <div class="quiz-result-ring-center">
          <strong><?= number_format($percentage, 1) ?></strong>
          <span>percent</span>
        </div>
      </div>
      <div class="quiz-result-stats">
        <div class="quiz-result-stat">
          <span class="quiz-result-stat-icon"><?= $resultIcon('target') ?></span>
          <div><small>Score</small><strong><?= number_format((float)$attempt['score'], 2) ?> <em>/ <?= number_format((float)$attempt['total_marks'], 2) ?></em></strong></div>
        </div>
        <div class="quiz-result-stat">
          <span class="quiz-result-stat-icon"><?= $resultIcon('list') ?></span>
          <div><small>Correct answers</small><strong><?= (int)$attempt['correct_count'] ?> <em>/ <?= (int)$attempt['question_count'] ?></em></strong></div>
        </div>
        <div class="quiz-result-stat">
          <span class="quiz-result-stat-icon"><?= $resultIcon('clock') ?></span>
          <div><small>Submitted</small><strong><?= $escape(date('M j, Y g:i A', strtotime($attempt['submitted_at'] . ' UTC'))) ?></strong></div>
        </div>
      </div>
    </header>
    <div class="quiz-result-identity" aria-label="Student and course details">
      <div><span class="quiz-result-identity-icon"><?= $resultIcon('user') ?></span><div><small>Student</small><strong><?= $escape($attempt['student_name'] ?? 'Student') ?></strong><span>Student ID <?= $escape($attempt['student_reference'] ?? '—') ?></span></div></div>
      <div><span class="quiz-result-identity-icon"><?= $resultIcon('book') ?></span><div><small>Course</small><strong><?= $escape($attempt['course_title']) ?></strong></div></div>
    </div><?php if ($attempt['status'] === 'timed_out'): ?><p class="quiz-result-note">The server deadline expired. Your saved answers were graded.</p><?php elseif ($attempt['status'] === 'auto_submitted'): ?><p class="quiz-result-note">This attempt was submitted when the quiz page lost focus or was hidden.</p><?php endif; ?><div class="quiz-result-actions"><a class="button" href="<?= $escape($baseUrl) ?>/learning/quizzes?course_id=<?= (int)$attempt['course_id'] ?>">Back to course quizzes</a></div>
  </section>
  <?php if ($answers !== []): ?><section class="dashboard-panel quiz-answer-review">
    <div class="quiz-answer-review-heading"><p class="eyebrow">ANSWER REVIEW</p><h2>Review your answers</h2><p>Correct answers and explanations are shown below.</p></div>
    <div class="quiz-answer-review-list">
      <?php foreach ($answers as $index => $answer): $isCorrect = $answer['selected_option_id'] !== null && (int)$answer['selected_option_id'] === (int)$answer['correct_option_id']; ?>
        <article class="quiz-answer-review-item <?= $isCorrect ? 'is-correct' : 'is-incorrect' ?>">
          <p class="eyebrow quiz-answer-result-label"><?= $isCorrect ? '✓ CORRECT ANSWER' : '✕ INCORRECT ANSWER' ?> <span>QUESTION <?= $index + 1 ?></span></p>
          <h3><?= nl2br($escape($answer['question_text'])) ?></h3>
          <p><strong>Your answer:</strong> <?= $answer['selected_option_text'] !== null ? $escape($answer['selected_option_text']) : 'No answer submitted' ?></p>
          <?php if (!$isCorrect): ?><p><strong>Correct answer:</strong> <?= $escape($answer['correct_option_text'] ?? 'Unavailable') ?></p><?php endif; ?>
          <?php if (!empty($answer['explanation'])): ?><div class="quiz-answer-explanation"><strong>Explanation</strong><p><?= nl2br($escape($answer['explanation'])) ?></p></div><?php elseif (!$isCorrect): ?><div class="quiz-answer-explanation"><strong>Explanation</strong><p>No explanation was provided for this question.</p></div><?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  </section><?php endif; ?>
</section>
