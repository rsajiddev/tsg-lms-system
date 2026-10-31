<?php

use App\Core\Security;

$baseUrl = isset($baseUrl) && is_string($baseUrl) ? rtrim($baseUrl, '/') : rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$course = isset($course) && is_array($course) ? $course : [];
$courseIndex = isset($courseIndex) && is_numeric($courseIndex) ? (float) $courseIndex : 0.0;
$courseTitle = (string) ($course['title'] ?? 'Academy course');
$courseCategory = trim((string) ($course['category'] ?? ''));
$courseDescription = trim((string) ($course['short_description'] ?? ''));
if ($courseDescription === '') $courseDescription = trim((string) ($course['description'] ?? ''));
if ($courseDescription === '') $courseDescription = 'Practical, project-based training designed to build useful skills.';
$courseCurrency = (string) ($course['currency'] ?? 'PKR');
$courseSlug = trim((string) ($course['slug'] ?? ''));
$courseId = (int) ($course['id'] ?? 0);
$courseLink = $baseUrl . '/courses/' . rawurlencode($courseSlug);
// A signed-in student is past the account step, so the card takes them to what is left to do.
// The details link stays on the card: reading the syllabus before paying is not a reason to start over.
$isEnrolledStudent = ($isStudent ?? false) === true && $courseId > 0;
?>
<article class="course-card course-card-unified" data-reveal style="--reveal-delay: <?= Security::escape(number_format($courseIndex * 0.07, 2)) ?>s">
    <?php require BASE_PATH . '/app/Views/components/course-image.php'; ?>
    <div class="course-card-content">
        <?php if ($courseCategory !== ''): ?><p class="course-card-category"><?= Security::escape($courseCategory) ?></p><?php endif; ?>
        <h2><?= Security::escape($courseTitle) ?></h2>
        <p class="course-card-description"><?= Security::escape($courseDescription) ?></p>
        <div class="course-card-meta">
            <?php if (!empty($course['duration_label'])): ?><span><?= Security::escape((string) $course['duration_label']) ?></span><?php endif; ?>
        </div>
        <div class="price-options course-card-prices">
            <?php if (($course['recorded_price'] ?? null) !== null): ?><div><span>Recorded Only</span><strong><?= Security::escape($courseCurrency) ?> <?= number_format((float) $course['recorded_price'], 0) ?></strong></div><?php endif; ?>
            <?php if (($course['live_price'] ?? null) !== null): ?><div><span>Live + Recorded</span><strong><?= Security::escape($courseCurrency) ?> <?= number_format((float) $course['live_price'], 0) ?></strong></div><?php endif; ?>
        </div>
        <?php if ($isEnrolledStudent): ?>
            <a class="button course-cta" href="<?= Security::escape($baseUrl) ?>/payments/new?course_id=<?= $courseId ?>">Submit payment for further onboarding</a>
            <a class="course-card-details-link" href="<?= Security::escape($courseLink) ?>">View course details <span aria-hidden="true">→</span></a>
        <?php else: ?>
            <a class="button course-cta" href="<?= Security::escape($courseLink) ?>">Explore course</a>
        <?php endif; ?>
    </div>
</article>
