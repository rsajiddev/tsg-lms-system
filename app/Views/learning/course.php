<?php
use App\Core\Security;
$escape = [Security::class, 'escape'];
$course = $course ?? [];
$lessons = $lessons ?? [];
$modules = [];
foreach ($lessons as $lesson) $modules[$lesson['module_id']]['title'] = $lesson['module_title'];
foreach ($lessons as $lesson) $modules[$lesson['module_id']]['lessons'][] = $lesson;
$completedCount = count(array_filter($lessons, static fn(array $lesson): bool => (int) $lesson['completed'] === 1));
?>
<section class="dashboard-page learning-page">
  <div class="dashboard-topbar"><button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button><div class="dashboard-breadcrumb"><a href="<?= $escape($baseUrl) ?>/dashboard">Workspace</a><i>/</i><strong>My course</strong></div></div>
  <header class="dashboard-heading learning-heading"><div><p class="eyebrow">YOUR LEARNING SPACE</p><h1><?= $escape($course['title']) ?></h1><p><?= $escape($course['short_description'] ?: 'Continue through your published course lessons.') ?></p></div><span class="learning-progress-count"><?= $completedCount ?> / <?= count($lessons) ?> lessons complete</span></header>
  <section class="dashboard-panel learning-quiz-cta"><div><p class="eyebrow">WEEKLY ASSESSMENTS</p><h2>Quizzes and results</h2><p>Open released quizzes, continue an attempt, and review your scores.</p></div><a class="button" href="<?= $escape($baseUrl) ?>/learning/quizzes?course_id=<?= (int)$course['id'] ?>">View course quizzes</a></section>
  <section class="dashboard-panel learning-quiz-cta"><div><p class="eyebrow">PRACTICAL WORK</p><h2>Course assignments</h2><p>View instructions, submit your work, and check marks and instructor feedback.</p></div><a class="button" href="<?= $escape($baseUrl) ?>/learning/assignments?course_id=<?= (int)$course['id'] ?>">View assignments</a></section>
  <?php if ($modules === []): ?><section class="dashboard-panel"><div class="dashboard-empty"><div><h2>Lessons are being prepared</h2><p>Published lessons for this course will appear here when they are ready.</p></div></div></section><?php else: ?>
    <div class="learning-modules"><?php foreach ($modules as $module): ?><section class="dashboard-panel learning-module"><div class="dashboard-panel-heading"><div><p class="eyebrow">COURSE MODULE</p><h2><?= $escape($module['title']) ?></h2></div><span><?= count($module['lessons']) ?> <?= count($module['lessons']) === 1 ? 'lesson' : 'lessons' ?></span></div><div class="learning-lesson-list"><?php foreach ($module['lessons'] as $lesson): ?><a class="learning-lesson-row" href="<?= $escape($baseUrl) ?>/learning/lesson?lesson_id=<?= (int) $lesson['lesson_id'] ?>"><span class="learning-lesson-status <?= (int) $lesson['completed'] === 1 ? 'is-complete' : '' ?>"><?= (int) $lesson['completed'] === 1 ? '✓' : '▶' ?></span><span><strong><?= $escape($lesson['lesson_title']) ?></strong><small><?= (int) $lesson['completed'] === 1 ? 'Completed' : 'Open lesson' ?></small></span><span class="learning-lesson-arrow" aria-hidden="true">→</span></a><?php endforeach; ?></div></section><?php endforeach; ?></div>
  <?php endif; ?>
</section>
