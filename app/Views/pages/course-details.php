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
// A guest is not sent to a bare sign-in form, which used to lose the course they came for. They go
// into the course-bound checkout, and the course is remembered until the payment step.
$enrolHref = $isStudent
    ? $baseUrl . '/payments/new?course_id=' . rawurlencode((string) ($course['id'] ?? ''))
    : $baseUrl . '/enrol/' . rawurlencode((string) ($course['slug'] ?? ''));
$enrolLabel = $isStudent ? 'Choose your learning option' : 'Enroll now';
// The lesson count is printed as a fact, not as a curriculum list: the module and lesson titles
// belong to the student workspace, and a public page that lists them invites a look at content
// nobody has paid for.
$lessonCount = array_sum(array_map(static fn(array $module): int => count($module['lessons'] ?? []), $modules));
$facts = [];
if (!empty($course['duration_label'])) $facts[] = (string) $course['duration_label'];
if (!empty($course['level_label'])) $facts[] = (string) $course['level_label'];
if ($lessonCount > 0) $facts[] = $lessonCount . ' ' . ($lessonCount === 1 ? 'lesson' : 'lessons');
$facts[] = 'Certificate on completion';
// Only the prices that exist. A course with no pricing option at all is a "contact us" case, and the
// enrollment card says so instead of printing an empty row.
$prices = [];
if (($course['recorded_price'] ?? null) !== null) $prices[] = ['label' => 'Recorded Only', 'price' => (float) $course['recorded_price']];
if (($course['live_price'] ?? null) !== null) $prices[] = ['label' => 'Live + Recorded', 'price' => (float) $course['live_price']];
$lowestPrice = $prices === [] ? null : min(array_column($prices, 'price'));
$enrolSteps = [
    ['label' => 'Create your account', 'copy' => 'One page, and the course you picked travels with you through every step.'],
    ['label' => 'Confirm your email', 'copy' => 'A six-digit code proves the address is yours before anything is charged.'],
    ['label' => 'Send your payment proof', 'copy' => 'Transfer to the Academy account and upload the receipt or screenshot.'],
    ['label' => 'An admin opens access', 'copy' => 'Your lessons, quizzes and assignments unlock, and you get an email and a notification.'],
];
$includes = [
    ['title' => 'Structured lessons', 'copy' => 'Published lessons open in order, with video, notes and practical activities.'],
    ['title' => 'Quizzes and assignments', 'copy' => 'Each course carries graded work that feeds your completion percentage.'],
    ['title' => 'Progress tracking', 'copy' => 'Your dashboard shows watch time, submitted work and what is still pending.'],
    ['title' => 'Verified certificate', 'copy' => 'Meet the grade and attendance bar and request a certificate with your Academy ID.'],
];
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
                <?php foreach ($facts as $fact): ?><span><?= Security::escape($fact) ?></span><?php endforeach; ?>
            </div>
            <?php if ($lowestPrice !== null): ?>
                <p class="course-detail-price-tease">From <strong><?= Security::escape($currency) ?> <?= number_format($lowestPrice, 0) ?></strong> · <?= count($prices) ?> learning option<?= count($prices) === 1 ? '' : 's' ?></p>
            <?php else: ?>
                <p class="course-detail-price-tease">Contact the Academy for current course pricing</p>
            <?php endif; ?>
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

            <article class="course-detail-panel">
                <p class="eyebrow">WHAT THE COURSE INCLUDES</p>
                <h2>Everything you get with access</h2>
                <div class="course-detail-includes">
                    <?php foreach ($includes as $index => $include): ?>
                        <div>
                            <span aria-hidden="true"><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
                            <p><strong><?= Security::escape($include['title']) ?></strong><?= Security::escape($include['copy']) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="course-detail-panel">
                <p class="eyebrow">HOW ENROLLMENT WORKS</p>
                <h2>Four steps from this page to your first lesson</h2>
                <ol class="course-detail-flow">
                    <?php foreach ($enrolSteps as $index => $enrolStep): ?>
                        <li>
                            <span aria-hidden="true"><?= $index + 1 ?></span>
                            <p><strong><?= Security::escape($enrolStep['label']) ?></strong><?= Security::escape($enrolStep['copy']) ?></p>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </article>
        </div>

        <aside class="course-detail-sidebar" aria-label="Enroll in this course">
            <div class="course-detail-panel course-detail-enrol">
                <p class="eyebrow">START LEARNING</p>
                <h2>Choose your learning mode</h2>
                <div class="course-detail-prices">
                    <?php foreach ($prices as $price): ?>
                        <div><span><?= Security::escape($price['label']) ?></span><strong><?= Security::escape($currency) ?> <?= number_format($price['price'], 0) ?></strong></div>
                    <?php endforeach; ?>
                    <?php if ($prices === []): ?><p>Contact the Academy for current course pricing.</p><?php endif; ?>
                </div>
                <a class="button course-detail-enrol-button" href="<?= Security::escape($enrolHref) ?>"><?= Security::escape($enrolLabel) ?></a>
                <p class="course-detail-enrol-note"><span aria-hidden="true">✓</span> <?= $isStudent ? 'Access is enabled after an administrator verifies your payment.' : 'You create your account and confirm your email on the next step, then pay. Access is enabled after an administrator verifies your payment.' ?></p>
                <div class="course-detail-help">
                    <strong>Have a question?</strong>
                    <p>Our Academy team can help you choose the right learning option.</p>
                    <a href="<?= Security::escape($baseUrl) ?>/contact">Contact the Academy <span aria-hidden="true">→</span></a>
                </div>
            </div>
        </aside>
    </div>
</section>
