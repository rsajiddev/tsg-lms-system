<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $value): string => Security::escape((string) $value);
$user = isset($user) && is_array($user) ? $user : [];
$courses = is_array($courses ?? null) ? $courses : [];
$grantedLabel = static function (?string $value): string {
    if (!$value) {
        return '—';
    }
    $timestamp = strtotime($value . ' UTC');
    return $timestamp === false ? '—' : date('M j, Y', $timestamp);
};
?>
<section class="dashboard-page" data-reveal>
    <div class="dashboard-topbar">
        <button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
        <div class="dashboard-breadcrumb"><a href="<?= $escape($baseUrl) ?>/dashboard">Workspace</a><i>/</i><strong>My courses</strong></div>
    </div>

    <header class="dashboard-heading">
        <div>
            <p class="eyebrow">YOUR LEARNING SPACE</p>
            <h1>My courses</h1>
            <p>Every course your access has been granted for, with your lesson progress.</p>
        </div>
        <a class="button" href="<?= $escape($baseUrl) ?>/courses">Browse catalogue</a>
    </header>

    <section class="dashboard-panel">
        <div class="dashboard-panel-heading">
            <div>
                <p class="eyebrow">ENROLMENT</p>
                <h2><?= count($courses) ?> course<?= count($courses) === 1 ? '' : 's' ?></h2>
            </div>
        </div>
        <?php if ($courses === []): ?>
            <div class="dashboard-empty"><span class="dashboard-empty-icon">＋</span>
                <div>
                    <h3>Your learning starts here</h3>
                    <p>You do not have a course access grant yet. Browse the catalogue to explore available courses.</p><a class="dashboard-inline-link" href="<?= $escape($baseUrl) ?>/courses">Explore published courses →</a>
                </div>
            </div>
        <?php else: ?>
            <div class="dashboard-course-list">
                <?php foreach ($courses as $course):
                    $lessons = (int) $course['lesson_count'];
                    $completed = (int) $course['completed_lessons'];
                    $progress = $lessons > 0 ? min(100, (int) round(($completed / $lessons) * 100)) : 0;
                ?>
                    <article class="dashboard-course-card">
                        <div class="dashboard-course-top"><span class="dashboard-course-mark">TSG</span><span class="dashboard-status status-active">Access active</span></div>
                        <p class="dashboard-course-category"><?= $escape($course['category'] ?: 'Academy course') ?></p>
                        <h3><?= $escape($course['title']) ?></h3>
                        <p class="dashboard-course-meta"><?= $escape($course['level_label'] ?: 'All levels') ?><?= $course['duration_label'] ? ' · ' . $escape($course['duration_label']) : '' ?></p>
                        <div class="dashboard-progress-label"><span>Lesson progress</span><strong><?= $lessons > 0 ? $progress . '%' : 'Not started' ?></strong></div>
                        <div class="dashboard-progress" role="progressbar" aria-label="<?= $escape($course['title']) ?> lesson progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $progress ?>"><span style="width: <?= $progress ?>%"></span></div>
                        <small class="dashboard-course-foot"><?= $lessons > 0 ? number_format($completed) . ' of ' . number_format($lessons) . ' lessons completed' : 'Course lessons are being prepared' ?> · Access granted <?= $escape($grantedLabel($course['granted_at'] ?? null)) ?> · Expires <?= $escape($grantedLabel($course['expires_at'] ?? null)) ?></small>
                        <a class="dashboard-inline-link" href="<?= $escape($baseUrl) ?>/learning?course_id=<?= (int) $course['id'] ?>">Open course →</a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <div class="dashboard-note"><strong>Need help?</strong><span>Lessons open only for courses with an active access grant. If a course is missing here, contact the academy team.</span></div>
</section>
