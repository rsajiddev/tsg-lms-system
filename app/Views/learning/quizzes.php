<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $v): string => Security::escape((string)$v);
$course = $course ?? [];
$quizzes = $quizzes ?? []; ?>
<section class="dashboard-page learning-page quiz-list-page">
  <div class="dashboard-topbar"><button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
    <div class="dashboard-breadcrumb"><a href="<?= $escape($baseUrl) ?>/dashboard">Workspace</a><i>/</i><a href="<?= $escape($baseUrl) ?>/learning?course_id=<?= (int)$course['id'] ?>"><?= $escape($course['title']) ?></a><i>/</i><strong>Quizzes</strong></div>
  </div>
  <header class="dashboard-heading">
    <div>
      <p class="eyebrow">COURSE ASSESSMENTS</p>
      <h1><?= $escape($course['title']) ?> quizzes</h1>
    <p>Practice at your pace and track every graded attempt.</p>
    </div>
  </header>
  <?php if ($quizzes === []): ?><section class="dashboard-panel">
      <div class="dashboard-empty">
        <div>
          <h2>No quiz is currently available</h2>
          <p>Released assessments for this course will appear here.</p>
        </div>
      </div>
    </section><?php else: ?>
    <div class="quiz-student-list"><?php foreach ($quizzes as $quiz):
      $type = (string)($quiz['quiz_type'] ?? 'lesson');
      $used = (int)($quiz['used_attempts'] ?? 0);
      $retakeStatus = (string)($quiz['retake_status'] ?? '');
      $retakeUnused = $retakeStatus === 'approved' && empty($quiz['retake_used_at']);
      $remaining = $type === 'practice' ? null : ($type === 'final' ? (($used === 0 || $retakeUnused) ? 1 : 0) : max(0, (int)$quiz['attempt_limit'] - $used)); ?>
        <article class="dashboard-panel quiz-student-card">
          <div>
            <p class="eyebrow"><?= $escape(strtoupper($type)) ?><?= ($type === 'practice' && ($quiz['practice_topic'] ?? 'general') === 'php_mysql') ? ' · PHP/MYSQL' : '' ?> · WEEK <?= (int)$quiz['week_number'] ?> · <?= (int)$quiz['actual_question_count'] ?> QUESTIONS</p>
            <h2><?= $escape($quiz['title']) ?></h2>
            <p><?= $escape($quiz['instructions'] ?: ($type === 'practice' ? 'Retry these questions as often as you need.' : 'Complete this assessment before its closing time.')) ?></p>
            <div class="quiz-meta"><span><?= in_array($type, ['module', 'final'], true) ? (int)ceil((int)$quiz['time_limit_seconds'] / 60) . ' min · timed' : 'No time limit' ?></span><span><?= $remaining === null ? 'Unlimited retries' : $remaining . ' graded attempt(s) available' ?></span><?php if ($type === 'lesson'): ?><span>60% to pass</span><?php endif; ?></div>
          </div>
          <div class="quiz-student-actions">
            <?php if (!empty($quiz['active_attempt'])): ?><a class="button" href="<?= $escape($baseUrl) ?>/learning/quiz/attempt?attempt_id=<?= (int)$quiz['active_attempt'] ?>">Continue quiz</a>
            <?php elseif ($remaining === null || $remaining > 0): ?><form method="post" action="<?= $escape($baseUrl) ?>/learning/quiz/start"><input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>"><input type="hidden" name="quiz_id" value="<?= (int)$quiz['id'] ?>"><button class="button" type="submit"><?= $used > 0 ? 'Try again' : 'Start quiz' ?></button></form>
            <?php elseif ($type === 'final' && $retakeStatus === 'pending'): ?><span class="dashboard-status status-pending">Retake request pending</span>
            <?php elseif ($type === 'final' && $used === 1 && $retakeStatus !== 'approved'): ?><form method="post" action="<?= $escape($baseUrl) ?>/learning/quiz/retake-request" class="quiz-retake-request"><input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>"><input type="hidden" name="quiz_id" value="<?= (int)$quiz['id'] ?>"><label class="sr-only" for="retake-note-<?= (int)$quiz['id'] ?>">Reason for retake request</label><input class="admin-input" id="retake-note-<?= (int)$quiz['id'] ?>" name="student_note" maxlength="1000" placeholder="Optional note for the admin"><button class="button button-secondary" type="submit">Request retake</button></form>
            <?php else: ?><span class="dashboard-status status-closed">Attempts used</span><?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
