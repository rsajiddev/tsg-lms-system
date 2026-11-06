<?php

use App\Core\Security;

$escape = [Security::class, 'escape'];
$course = $course ?? [];
$lessons = $lessons ?? [];
// Whether this course runs live classes and this enrolment may not open them - decided by the
// controller, which is also the only place that reads the raw plan code.
$liveNotice = isset($liveNotice) && (bool) $liveNotice;
// Whether the completion lock has hidden this course's recordings - the certificate is issued and
// the Academy has switched the lock on. Decided by the controller; the page only renders it.
$videosLocked = isset($videosLocked) && (bool) $videosLocked;
$modules = [];
foreach ($lessons as $lesson) $modules[$lesson['module_id']]['title'] = $lesson['module_title'];
foreach ($lessons as $lesson) $modules[$lesson['module_id']]['lessons'][] = $lesson;
$completedCount = count(array_filter($lessons, static fn(array $lesson): bool => (int) $lesson['completed'] === 1));
$grade = is_array($grade ?? null) ? $grade : null;
$weights = is_array($weights ?? null) ? $weights : [];
$eligibility = is_array($eligibility ?? null) ? $eligibility : null;
$fmtPct = static fn($value): string => rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');
$gradeMask = $grade !== null ? (int) $grade['components_mask'] : 0;
$gradeRows = [
    ['Video lessons', 'video_pct', 'video', 1],
    ['Quizzes', 'quiz_pct', 'quiz', 2],
    ['Exercises', 'exercise_pct', 'exercise', 4],
    ['Project', 'project_pct', 'project', 8],
    ['Final test', 'final_pct', 'final', 16],
];
?>
<section class="dashboard-page learning-page">
  <div class="dashboard-topbar"><button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
    <div class="dashboard-breadcrumb"><a href="<?= $escape($baseUrl) ?>/dashboard">Workspace</a><i>/</i><strong>My course</strong></div>
  </div>
  <header class="dashboard-heading learning-heading">
    <div>
      <p class="eyebrow">YOUR LEARNING SPACE</p>
      <h1><?= $escape($course['title']) ?></h1>
      <p><?= $escape($course['short_description'] ?: 'Continue through your published course lessons.') ?></p>
    </div><span class="learning-progress-count"><?= $completedCount ?> / <?= count($lessons) ?> lessons complete</span>
  </header>
  <section class="dashboard-panel grade-panel">
    <div class="dashboard-panel-heading">
      <div><p class="eyebrow">COMPLETION &amp; GRADING</p><h2>Your progress &amp; grade</h2></div>
      <?php if ($grade !== null): ?><span class="grade-overall">Overall <strong><?= $escape($grade['letter_grade']) ?></strong> · <?= $escape($fmtPct($grade['overall_pct'])) ?>%</span><?php endif; ?>
    </div>
    <?php if ($grade === null): ?>
      <p class="grade-empty">Your grade updates automatically as you watch lessons, submit quizzes and get assignments reviewed.</p>
    <?php else: ?>
      <div class="grade-rows">
        <?php foreach ($gradeRows as $row): [$label, $column, $key, $bit] = $row; $present = ($gradeMask & $bit) === $bit; $pct = (float) $grade[$column]; ?>
          <div class="grade-row<?= $present ? '' : ' grade-row-absent' ?>">
            <span class="grade-row-label"><?= $escape($label) ?><small><?= $present ? $escape((string) (int) ($weights[$key] ?? 0)) . '% weight' : 'Not offered in this course' ?></small></span>
            <div class="grade-bar" role="progressbar" aria-valuenow="<?= (int) round($present ? $pct : 0) ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?= $escape($label) ?> completion"><span style="--grade-fill: <?= $present ? (int) max(0, min(100, round($pct))) : 0 ?>%"></span></div>
            <strong class="grade-row-value"><?= $present ? $escape($fmtPct($pct)) . '%' : '—' ?></strong>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if ($eligibility !== null): ?>
        <div class="grade-eligibility<?= $eligibility['ok'] ? ' grade-eligibility-ok' : '' ?>">
          <?php if ($eligibility['ok']): ?>
            <p class="grade-eligibility-title">You are eligible for a certificate.</p>
            <a class="button button-secondary" href="<?= $escape($baseUrl) ?>/student/courses">Request it in My courses</a>
          <?php else: ?>
            <p class="grade-eligibility-title">Your certificate unlocks once you meet every requirement:</p>
            <ul class="grade-eligibility-list">
              <?php foreach ($eligibility['missing'] as $reason): ?><li><?= $escape($reason) ?></li><?php endforeach; ?>
              <?php if ($eligibility['attendance'] === null): ?><li>Attendance is measured from your first confirmed sign-in.</li><?php endif; ?>
            </ul>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </section>
  <section class="dashboard-panel learning-quiz-cta">
    <div>
      <p class="eyebrow">WEEKLY ASSESSMENTS</p>
      <h2>Quizzes and results</h2>
      <p>Open released quizzes, continue an attempt, and review your scores.</p>
    </div><a class="button" href="<?= $escape($baseUrl) ?>/learning/quizzes?course_id=<?= (int)$course['id'] ?>">View course quizzes</a>
  </section>
  <section class="dashboard-panel learning-quiz-cta">
    <div>
      <p class="eyebrow">PRACTICAL WORK</p>
      <h2>Course assignments</h2>
      <p>View instructions, submit your work, and check marks and instructor feedback.</p>
    </div><a class="button" href="<?= $escape($baseUrl) ?>/learning/assignments?course_id=<?= (int)$course['id'] ?>">View assignments</a>
  </section>
  <?php if ($modules === []): ?><section class="dashboard-panel">
      <div class="dashboard-empty">
        <div>
          <h2>Lessons are being prepared</h2>
          <p>Published lessons for this course will appear here when they are ready.</p>
        </div>
      </div>
    </section><?php else: ?>
    <div class="learning-modules"><?php if ($videosLocked): ?><p class="learning-plan-note learning-videos-locked">This course is complete and its certificate has been issued, so the lesson recordings are no longer open here. Your quizzes, assignments, grades and certificate all stay available.</p><?php endif; ?><?php if ($liveNotice): ?><p class="learning-plan-note">This course runs live classes. Your enrolment is on the Recorded Only plan, which holds every recording, assignment and quiz but not the meeting addresses - talk to the Academy if you want to move to Live + Recorded.</p><?php endif; ?><?php foreach ($modules as $module): ?><section class="dashboard-panel learning-module">
          <div class="dashboard-panel-heading">
            <div>
              <p class="eyebrow">COURSE MODULE</p>
              <h2><?= $escape($module['title']) ?></h2>
            </div><span><?= count($module['lessons']) ?> <?= count($module['lessons']) === 1 ? 'lesson' : 'lessons' ?></span>
          </div>
          <div class="learning-lesson-list"><?php foreach ($module['lessons'] as $lesson): ?><?php if ($videosLocked): ?><div class="learning-lesson-row is-locked" aria-disabled="true"><span class="learning-lesson-status is-locked">&#128274;</span><span><strong><?= $escape($lesson['lesson_title']) ?></strong><small>Recording hidden<?= (int) $lesson['completed'] === 1 ? ' - watched' : '' ?></small></span></div><?php else: ?><a class="learning-lesson-row" href="<?= $escape($baseUrl) ?>/learning/lesson?lesson_id=<?= (int) $lesson['lesson_id'] ?>"><span class="learning-lesson-status <?= (int) $lesson['completed'] === 1 ? 'is-complete' : '' ?>"><?= (int) $lesson['completed'] === 1 ? '✓' : '▶' ?></span><span><strong><?= $escape($lesson['lesson_title']) ?></strong><small><?= (int) $lesson['completed'] === 1 ? 'Completed' : 'Open lesson' ?><?php if (!empty($lesson['has_live'])): ?> · live class<?php endif; ?></small></span><span class="learning-lesson-arrow" aria-hidden="true">→</span></a><?php endif; ?><?php endforeach; ?></div>
        </section><?php endforeach; ?></div>
  <?php endif; ?>
</section>