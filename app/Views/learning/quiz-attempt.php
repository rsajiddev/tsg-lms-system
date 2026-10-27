<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $v): string => Security::escape((string)$v); ?>
<section class="dashboard-page learning-page quiz-attempt-page" data-quiz-deadline="<?= (int)$deadlineEpoch ?>">
  <div class="dashboard-topbar"><button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
    <div class="dashboard-breadcrumb"><a href="<?= $escape($baseUrl) ?>/dashboard">Workspace</a><i>/</i><a href="<?= $escape($baseUrl) ?>/learning?course_id=<?= (int)$attempt['course_id'] ?>"><?= $escape($attempt['course_title']) ?></a><i>/</i><strong>Quiz attempt</strong></div>
  </div>
  <header class="dashboard-heading quiz-attempt-heading">
    <div>
      <p class="eyebrow">WEEK <?= (int)$attempt['week_number'] ?> ASSESSMENT</p>
      <h1><?= $escape($attempt['title']) ?></h1>
      <p><?= count($questions) ?> questions<?= $deadlineEpoch > 0 ? ' · ' . (int)ceil((int)$attempt['time_limit_seconds'] / 60) . ' minutes' : ' · No time limit' ?></p>
    </div>
    <?php if ($deadlineEpoch > 0): ?><div class="quiz-countdown" role="timer" aria-live="off"><span>TIME REMAINING</span><strong data-quiz-countdown>--:--</strong></div><?php endif; ?>
  </header>
  <div class="quiz-policy-notice"><?= $deadlineEpoch > 0 ? 'Your attempt submits when you finish or the timer expires. The server enforces the deadline.' : 'Practice mode allows unlimited retries. You can submit whenever you are ready.' ?></div>
  <form id="quiz-attempt-form" method="post" action="<?= $escape($baseUrl) ?>/learning/quiz/submit" class="quiz-questions-form"><input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>"><input type="hidden" name="attempt_id" value="<?= (int)$attempt['id'] ?>"><input type="hidden" name="submission_reason" value="manual" data-submission-reason>
    <?php foreach ($questions as $index => $question): ?><article class="dashboard-panel quiz-question-card">
        <header class="quiz-question-head">
          <span class="quiz-question-number">Question <?= $index + 1 ?></span>
          <small class="quiz-question-marks"><?= $escape($question['marks']) ?> mark(s)</small>
        </header>
        <h2 class="quiz-question-prompt"><?= nl2br($escape($question['question_text'])) ?></h2>
        <div class="quiz-answer-options"><?php foreach ($question['options'] as $optionIndex => $option): ?><label class="quiz-answer-option"><input type="radio" name="answers[<?= (int)$question['id'] ?>]" value="<?= (int)$option['id'] ?>"><span class="quiz-option-letter"><?= chr(65 + $optionIndex) ?></span><span class="quiz-option-text"><?= $escape($option['option_text']) ?></span><i class="quiz-option-check" aria-hidden="true"></i></label><?php endforeach; ?></div>
      </article><?php endforeach; ?>
    <div class="quiz-submit-row">
      <p>Unanswered questions receive zero marks.</p><button class="button" type="submit" data-quiz-submit>Submit answers</button>
    </div>
  </form>
</section>
<script>
  (() => {
    const page = document.querySelector('[data-quiz-deadline]');
    const form = document.getElementById('quiz-attempt-form');
    if (!page || !form) return;
    const deadline = Number(page.dataset.quizDeadline) * 1000;
    if (!deadline) {
      form.addEventListener('submit', () => {
        const button = form.querySelector('[data-quiz-submit]');
        if (button) {
          button.disabled = true;
          button.textContent = 'Submitting…';
        }
      });
      return;
    }
    const timer = form.closest('.quiz-attempt-page').querySelector('[data-quiz-countdown]');
    const reason = form.querySelector('[data-submission-reason]');
    let submitted = false;
    const submit = (why) => {
      if (submitted) return;
      submitted = true;
      reason.value = why;
      form.requestSubmit();
    };
    const tick = () => {
      const seconds = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
      timer.textContent = String(Math.floor(seconds / 60)).padStart(2, '0') + ':' + String(seconds % 60).padStart(2, '0');
      if (seconds === 0) submit('deadline');
    };
    tick();
    setInterval(tick, 1000);
    form.addEventListener('submit', () => {
      submitted = true;
      const button = form.querySelector('[data-quiz-submit]');
      if (button) {
        button.disabled = true;
        button.textContent = 'Submitting…';
      }
    });
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) submit('tab_hidden');
    });
    window.addEventListener('blur', () => submit('focus_lost'), {
      once: true
    });
    document.addEventListener('click', event => {
      const link = event.target.closest('a[href]');
      if (!link || link.target || link.getAttribute('href').startsWith('#')) return;
      const destination = new URL(link.href, window.location.href);
      if (destination.origin !== window.location.origin) return;
      event.preventDefault();
      submit('navigation');
    });
    window.addEventListener('pagehide', () => {
      if (!submitted) submit('navigation');
    });
  })();
</script>
