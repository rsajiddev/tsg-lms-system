<?php

use App\Core\Security;

$baseUrl = isset($baseUrl) && is_string($baseUrl) ? rtrim($baseUrl, '/') : rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$course = isset($course) && is_array($course) ? $course : [];
$modules = isset($modules) && is_array($modules) ? $modules : [];
$courseTitle = trim((string) ($course['title'] ?? 'Academy course'));
$description = trim((string) ($course['description'] ?? ''));
if ($description === '') $description = trim((string) ($course['short_description'] ?? ''));
$category = trim((string) ($course['category'] ?? ''));
$currency = trim((string) ($course['pricing_currency'] ?? $course['currency'] ?? 'PKR'));
$currentUser = isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : [];
$isStudent = ($currentUser['role'] ?? null) === 'student';
$enrolHref = $isStudent
    ? $baseUrl . '/payments/new?course_id=' . rawurlencode((string) ($course['id'] ?? ''))
    : $baseUrl . '/login';
$enrolLabel = $isStudent ? 'Choose your learning option' : 'Sign in to enrol';
$lessonCount = array_sum(array_map(static fn(array $module): int => count($module['lessons'] ?? []), $modules));
?>
<section class="course-detail-page" aria-labelledby="course-detail-title">
    <nav class="course-detail-breadcrumb" aria-label="Breadcrumb">
        <a href="<?= Security::escape($baseUrl) ?>/">Home</a><span aria-hidden="true">/</span>
        <a href="<?= Security::escape($baseUrl) ?>/courses">Courses</a><span aria-hidden="true">/</span>
        <span aria-current="page"><?= Security::escape($courseTitle) ?></span>
    </nav>

    <header class="course-detail-hero">
        <div class="course-detail-hero-copy">
            <?php if ($category !== ''): ?><p class="eyebrow"><?= Security::escape($category) ?> · TECH SG ACADEMY</p><?php endif; ?>
            <h1 id="course-detail-title"><?= Security::escape($courseTitle) ?></h1>
            <?php if ($description !== ''): ?><p class="course-detail-lead"><?= Security::escape($description) ?></p><?php endif; ?>
            <div class="course-detail-facts" aria-label="Course details">
                <?php if (!empty($course['duration_label'])): ?><span><?= Security::escape((string) $course['duration_label']) ?></span><?php endif; ?>
                <?php if (!empty($course['level_label'])): ?><span><?= Security::escape((string) $course['level_label']) ?></span><?php endif; ?>
                <span>Certificate on completion</span>
            </div>
        </div>
        <div class="course-detail-hero-image">
            <?php $courseImageFrameClass = 'course-detail-image'; require BASE_PATH . '/app/Views/components/course-image.php'; ?>
        </div>
    </header>

    <div class="course-detail-layout">
        <div class="course-detail-main">
            <article class="course-detail-panel">
                <p class="eyebrow">COURSE OVERVIEW</p>
                <h2>About this course</h2>
                <p class="course-detail-body"><?= Security::escape($description !== '' ? $description : 'Explore practical learning designed around useful, real-world skills.') ?></p>
                <div class="course-detail-outcomes">
                    <div><span aria-hidden="true">✓</span><p>Learn through structured lessons and practical activities.</p></div>
                    <div><span aria-hidden="true">✓</span><p>Track your progress in your student dashboard.</p></div>
                    <div><span aria-hidden="true">✓</span><p>Get course access after payment verification.</p></div>
                </div>
            </article>

            <article class="course-detail-panel course-detail-curriculum" id="curriculum">
                <div class="course-detail-section-heading">
                    <div><p class="eyebrow">LEARNING PATH</p><h2>Course curriculum</h2></div>
                    <span><?= number_format($lessonCount) ?> published <?= $lessonCount === 1 ? 'lesson' : 'lessons' ?></span>
                </div>
                <?php if ($modules === []): ?>
                    <div class="course-detail-empty"><strong>Curriculum is being prepared</strong><p>Published modules and lessons will appear here as they become available.</p></div>
                <?php else: ?>
                    <div class="course-detail-modules">
                        <?php foreach ($modules as $moduleIndex => $module): ?>
                            <?php $moduleLessons = isset($module['lessons']) && is_array($module['lessons']) ? $module['lessons'] : []; ?>
                            <section class="course-detail-module">
                                <div class="course-detail-module-title"><span><?= str_pad((string) ($moduleIndex + 1), 2, '0', STR_PAD_LEFT) ?></span><h3><?= Security::escape((string) ($module['title'] ?? 'Course module')) ?></h3></div>
                                <?php if ($moduleLessons !== []): ?><ul><?php foreach ($moduleLessons as $lessonTitle): ?><li><span aria-hidden="true">▶</span><?= Security::escape((string) $lessonTitle) ?></li><?php endforeach; ?></ul><?php else: ?><p class="course-detail-module-note">Lessons are being prepared for this module.</p><?php endif; ?>
                            </section>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>
        </div>

        <aside class="course-detail-sidebar" aria-label="Enrol in this course">
            <div class="course-detail-panel course-detail-enrol">
                <p class="eyebrow">START LEARNING</p>
                <h2>Choose your learning mode</h2>
                <div class="course-detail-prices">
                    <?php if (($course['recorded_price'] ?? null) !== null): ?><div><span>Recorded Only</span><strong><?= Security::escape($currency) ?> <?= number_format((float) $course['recorded_price'], 0) ?></strong></div><?php endif; ?>
                    <?php if (($course['live_price'] ?? null) !== null): ?><div><span>Live + Recorded</span><strong><?= Security::escape($currency) ?> <?= number_format((float) $course['live_price'], 0) ?></strong></div><?php endif; ?>
                    <?php if (($course['recorded_price'] ?? null) === null && ($course['live_price'] ?? null) === null): ?><p>Contact the Academy for current course pricing.</p><?php endif; ?>
                </div>
                <a class="button course-detail-enrol-button" href="<?= Security::escape($enrolHref) ?>"><?= Security::escape($enrolLabel) ?></a>
                <p class="course-detail-enrol-note"><span aria-hidden="true">✓</span> Access is enabled after an administrator verifies your payment.</p>
                <a class="course-detail-curriculum-link" href="#curriculum">View course curriculum <span aria-hidden="true">↓</span></a>
            </div>
            <div class="course-detail-help"><strong>Have a question?</strong><p>Our Academy team can help you choose the right learning option.</p><a href="<?= Security::escape($baseUrl) ?>/contact">Contact the Academy <span aria-hidden="true">→</span></a></div>
        </aside>
    </div>
</section>
