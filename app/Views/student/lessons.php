<?php

use App\Core\Security;
use App\Services\LessonMedia;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $value): string => Security::escape((string) $value);
$lessons = is_array($lessons ?? null) ? $lessons : [];
$courses = is_array($courses ?? null) ? $courses : [];
$summary = is_array($summary ?? null) ? $summary : [];
$watchAvailable = isset($watchAvailable) && (bool) $watchAvailable;
$watchPercent = max(0, (int) ($watchPercent ?? 90));
$search = is_string($search ?? null) ? $search : '';
$courseId = (int) ($courseId ?? 0);
$status = is_string($status ?? null) ? $status : 'all';
$total = max(0, (int) ($total ?? 0));
$page = max(1, (int) ($page ?? 1));
$pages = max(1, (int) ($pages ?? 1));
$message = $message ?? null;
$error = $error ?? null;

$totalLessons = max(0, (int) ($summary['total'] ?? 0));
$completedLessons = max(0, (int) ($summary['completed'] ?? 0));
$inProgressLessons = max(0, (int) ($summary['in_progress'] ?? 0));
$notStartedLessons = max(0, $totalLessons - $completedLessons - $inProgressLessons);
$overallPercent = $totalLessons > 0 ? (int) round($completedLessons / $totalLessons * 100) : 0;
$queryUrl = static function (array $overrides = []) use ($search, $courseId, $status): string {
    return http_build_query(array_merge([
        'q' => $search !== '' ? $search : null,
        'course_id' => $courseId ?: null,
        'status' => $status !== 'all' ? $status : null,
    ], $overrides));
};
$filtersActive = $search !== '' || $courseId > 0 || $status !== 'all';
$lastWatchedLabel = static function (?string $value): string {
    if ($value === null || $value === '') {
        return '';
    }
    $timestamp = strtotime($value . ' UTC');
    return $timestamp === false ? '' : date('M j', $timestamp);
};
?>
<section class="dashboard-page my-lessons-page" data-reveal>
    <div class="dashboard-topbar">
        <button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
        <div class="dashboard-breadcrumb"><a href="<?= $escape($baseUrl) ?>/dashboard">Workspace</a><i>/</i><strong>Lessons</strong></div>
        <form class="dashboard-search" method="get" action="<?= $escape($baseUrl) ?>/student/lessons" role="search">
            <span aria-hidden="true">⌕</span>
            <input type="search" name="q" value="<?= $escape($search) ?>" placeholder="Search your lessons" aria-label="Search lessons">
            <input type="hidden" name="course_id" value="<?= $courseId ?: '' ?>">
            <input type="hidden" name="status" value="<?= $escape($status) ?>">
        </form>
    </div>

    <header class="dashboard-heading">
        <div>
            <p class="eyebrow">MY LEARNING</p>
            <h1>Lessons</h1>
            <p>Every published lesson from your active courses in one place — no need to open each course to find where you left off.</p>
        </div>
        <a class="button button-secondary" href="<?= $escape($baseUrl) ?>/student/courses">My courses</a>
    </header>

    <?php \App\Core\View::toast('success', $message ?? null); ?>
    <?php \App\Core\View::toast('error', $error ?? null); ?>

    <section class="my-lesson-summary" aria-label="Lesson progress overview">
        <div class="my-lesson-summary-count">
            <strong><?= number_format($completedLessons) ?></strong>
            <span>of <?= number_format($totalLessons) ?> lesson<?= $totalLessons === 1 ? '' : 's' ?> complete</span>
        </div>
        <div class="my-lesson-summary-bar">
            <span class="my-lesson-summary-track" style="--my-lesson-fill: <?= $overallPercent ?>%" role="img" aria-label="<?= $overallPercent ?> percent of your lessons complete"><span class="my-lesson-summary-fill"></span></span>
            <div class="my-lesson-summary-meta">
                <span class="my-lesson-chip my-lesson-chip-progress"><?= number_format($inProgressLessons) ?> in progress</span>
                <span class="my-lesson-chip my-lesson-chip-todo"><?= number_format($notStartedLessons) ?> not started</span>
                <span class="my-lesson-summary-percent"><?= $overallPercent ?>%</span>
            </div>
        </div>
    </section>

    <section class="dashboard-panel my-lesson-panel" aria-label="Lesson list">
        <div class="dashboard-panel-heading my-lesson-heading">
            <div>
                <p class="eyebrow"><?= $filtersActive ? 'FILTERED RESULTS' : 'ALL ACTIVE COURSES' ?></p>
                <h2><?= number_format($total) ?> lesson<?= $total === 1 ? '' : 's' ?></h2>
            </div>
            <form class="my-lesson-filters" method="get" action="<?= $escape($baseUrl) ?>/student/lessons">
                <input type="hidden" name="q" value="<?= $escape($search) ?>">
                <label class="sr-only" for="my-lesson-course">Course</label>
                <select class="admin-select" id="my-lesson-course" name="course_id" onchange="this.form.submit()">
                    <option value="0">All courses</option>
                    <?php foreach ($courses as $course): ?><option value="<?= (int) $course['id'] ?>" <?= $courseId === (int) $course['id'] ? 'selected' : '' ?>><?= $escape($course['title']) ?></option><?php endforeach; ?>
                </select>
                <label class="sr-only" for="my-lesson-status">Lesson status</label>
                <select class="admin-select" id="my-lesson-status" name="status" onchange="this.form.submit()">
                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All statuses</option>
                    <option value="in-progress" <?= $status === 'in-progress' ? 'selected' : '' ?>>In progress</option>
                    <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="not-started" <?= $status === 'not-started' ? 'selected' : '' ?>>Not started</option>
                </select>
                <?php if ($filtersActive): ?><a class="button button-secondary" href="<?= $escape($baseUrl) ?>/student/lessons">Clear</a><?php endif; ?>
            </form>
        </div>

        <?php if ($lessons === []): ?>
            <div class="dashboard-empty my-lesson-empty">
                <span class="dashboard-empty-icon" aria-hidden="true">◷</span>
                <div>
                    <h3><?= $filtersActive ? 'No lesson matches these filters' : 'No lessons are available yet' ?></h3>
                    <p><?= $filtersActive ? 'Try another course or status filter.' : 'Lessons appear here as soon as your teacher publishes them and your course access is active.' ?></p>
                </div>
            </div>
        <?php else: ?>
            <div class="my-lesson-list">
                <?php foreach ($lessons as $lesson):
                    $lessonId = (int) $lesson['id'];
                    $duration = $lesson['video_duration_seconds'] === null ? null : (int) $lesson['video_duration_seconds'];
                    $watched = max(0, (int) ($lesson['watched_seconds'] ?? 0));
                    $completed = $lesson['completed_at'] !== null && $lesson['completed_at'] !== '';
                    $ratio = $duration !== null && $duration > 0 ? min(100, (int) round($watched / $duration * 100)) : null;
                    $state = $completed ? 'completed' : ($ratio !== null && $ratio > 0 ? 'progress' : 'todo');
                ?>
                    <article class="my-lesson-row my-lesson-<?= $state ?>">
                        <div class="my-lesson-main">
                            <p class="eyebrow"><?= $escape($lesson['course_title']) ?> · <?= $escape($lesson['module_title']) ?></p>
                            <h3><?= $escape($lesson['title']) ?></h3>
                            <p class="my-lesson-meta">
                                <?php if ($duration !== null): ?>
                                    <span>Video <?= $escape(LessonMedia::formatDuration($duration)) ?></span>
                                    <?php if (!$completed): ?><span>· watch <?= $watchPercent ?>% to complete</span><?php endif; ?>
                                <?php else: ?>
                                    <span>Reading lesson · no video length registered</span>
                                <?php endif; ?>
                                <?php if (!empty($lesson['last_watched'])): ?><span>· last watched <?= $escape($lastWatchedLabel((string) $lesson['last_watched'])) ?></span><?php endif; ?>
                            </p>
                        </div>
                        <div class="my-lesson-status">
                            <?php if ($completed): ?>
                                <span class="my-lesson-state my-lesson-state-completed"><span aria-hidden="true">✓</span> Completed</span>
                            <?php elseif ($ratio !== null): ?>
                                <span class="my-lesson-state my-lesson-state-progress"><?= $ratio ?>% watched</span>
                                <span class="my-lesson-track" style="--my-lesson-fill: <?= $ratio ?>%" role="img" aria-label="<?= $ratio ?> percent watched"><span class="my-lesson-fill"></span></span>
                                <small><?= $escape(LessonMedia::formatDuration($watched)) ?> of <?= $escape(LessonMedia::formatDuration($duration)) ?></small>
                            <?php elseif ($watchAvailable): ?>
                                <span class="my-lesson-state my-lesson-state-todo">Not started</span>
                            <?php endif; ?>
                        </div>
                        <a class="button<?= $completed ? ' button-secondary' : '' ?>" href="<?= $escape($baseUrl) ?>/learning/lesson?lesson_id=<?= $lessonId ?>"><?= $completed ? 'Review lesson' : ($state === 'progress' ? 'Resume lesson' : 'Open lesson') ?></a>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php if ($pages > 1): ?>
                <nav class="my-lesson-pagination" aria-label="Lesson pages">
                    <span>Page <?= $page ?> of <?= $pages ?></span>
                    <div>
                        <?php if ($page > 1): ?><a class="button button-secondary" href="<?= $escape($baseUrl) ?>/student/lessons?<?= $escape($queryUrl(['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
                        <?php if ($page < $pages): ?><a class="button" href="<?= $escape($baseUrl) ?>/student/lessons?<?= $escape($queryUrl(['page' => $page + 1])) ?>">Next</a><?php endif; ?>
                    </div>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <?php
    $usageEyebrow = 'Lessons';
    $usageTitle = 'How to use the lesson list';
    $usageLead = 'Every published lesson in the courses you can open right now.';
    $usageBullets = [
        'Start from the lesson title. The video, notes and materials open in the player with your position restored.',
        'Progress saves itself. The player records where you stopped, so closing the tab costs you nothing.',
        'Some lessons need watch time. Where the academy sets a rule, the lesson completes once you have watched the required share.',
        'Mark the rest when you are done. Lessons without a tracked video are completed by hand so the progress bar stays accurate.',
        'Narrow it by course. The filters keep the list to the lessons you can open today.',
    ];
    require BASE_PATH . '/app/Views/components/usage-guide.php';
    ?>

    <?php if ($watchAvailable): ?><p class="dashboard-note"><strong>Watch time is tracked for you</strong><span>Progress is saved while the lesson video plays, so you can close the page and resume where you stopped. A lesson unlocks its completion button after <?= $watchPercent ?>% of the video.</span></p><?php endif; ?>
</section>
