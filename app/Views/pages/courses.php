<?php

use App\Core\Security;

$courses = isset($courses) && is_array($courses) ? $courses : [];
$catalogueAvailable = isset($catalogueAvailable) ? (bool) $catalogueAvailable : false;
$baseUrl = isset($baseUrl) && is_string($baseUrl) ? rtrim($baseUrl, '/') : rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$courseCategories = array_values(array_unique(array_filter(array_map(
    static fn(array $course): string => trim((string) ($course['category'] ?? '')),
    $courses
))));
?>
<section class="catalog-hero" data-reveal>
    <div class="catalog-hero-copy">
        <p class="eyebrow">TECH SG ACADEMY · COURSE CATALOGUE</p>
        <h1>Learn future-ready skills.<br><span>Build your career.</span></h1>
        <p class="catalog-hero-lead">Practical, project-based learning from people who build real software. Find a course, build your skills, and put them to work.</p>
        <div class="catalog-hero-actions">
            <a class="button" href="#course-list">Explore courses <span aria-hidden="true">→</span></a>
            <a class="button button-secondary" href="<?= Security::escape($baseUrl) ?>/about">How we teach</a>
        </div>
        <div class="catalog-hero-stats" aria-label="Academy course highlights">
            <div><strong><?= number_format(count($courses)) ?></strong><span>published courses</span></div>
            <div><strong>Project based</strong><span>learn by building</span></div>
        </div>
    </div>
    <div class="catalog-hero-art" aria-label="Learn with Tech SG Academy">
        <figure class="catalog-hero-photo catalog-hero-photo-one" data-image-frame>
            <div class="course-image-placeholder" aria-live="polite"><span class="course-image-spinner" aria-hidden="true"></span><span class="course-image-status">Loading learning image…</span></div>
            <img src="<?= Security::escape($baseUrl) ?>/public/assets/images/course-hero1.jpg" alt="Learner studying a new skill" loading="eager" decoding="async" fetchpriority="high" data-course-image>
        </figure>
        <figure class="catalog-hero-photo catalog-hero-photo-two" data-image-frame>
            <div class="course-image-placeholder" aria-live="polite"><span class="course-image-spinner" aria-hidden="true"></span><span class="course-image-status">Loading learning image…</span></div>
            <img src="<?= Security::escape($baseUrl) ?>/public/assets/images/course-hero2.jpg" alt="Learner practising and taking notes" loading="eager" decoding="async" data-course-image>
        </figure>
        <?php if ($courseCategories !== []): ?>
            <div class="catalog-hero-categories"><p>Explore skills</p><div><?php foreach (array_slice($courseCategories, 0, 6) as $category): ?><span><?= Security::escape($category) ?></span><?php endforeach; ?></div></div>
        <?php endif; ?>
    </div>
</section>

<?php if (!$catalogueAvailable): ?>
    <section class="empty-state"><h2>Course catalogue is temporarily unavailable</h2><p>Please try again later or contact the Academy team for current course information.</p></section>
<?php elseif ($courses === []): ?>
    <section class="empty-state"><h2>No published courses yet</h2><p>New courses will appear here once the Academy publishes them.</p><a class="button" href="<?= Security::escape($baseUrl) ?>/contact">Ask about upcoming courses</a></section>
<?php else: ?>
    <section class="catalog-courses" id="course-list" aria-labelledby="catalog-course-title">
        <div class="section-heading"><div><p class="eyebrow">CHOOSE YOUR NEXT SKILL</p><h2 id="catalog-course-title">Courses from the Academy</h2><p>Compare learning formats and choose the pace that works for you.</p></div></div>
        <div class="course-grid catalog-course-grid">
            <?php foreach ($courses as $courseIndex => $course): ?>
                <?php require BASE_PATH . '/app/Views/components/course-card.php'; ?>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="course-benefits" data-reveal aria-labelledby="course-benefits-title">
        <div class="course-benefits-heading"><p class="eyebrow">WHY TECH SG ACADEMY</p><h2 id="course-benefits-title">A better way to build practical skills.</h2><p>Learn with structure, support, and work you can show.</p></div>
        <div class="course-benefit-grid">
            <article class="course-benefit-card"><span class="course-benefit-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/><path d="M8 7h8M8 11h7"/></svg></span><h3>Learn from practitioners</h3><p>Lessons draw on real workflows used to plan and build software.</p></article>
            <article class="course-benefit-card"><span class="course-benefit-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="m12 3 2.6 5.3L20 9l-4 3.9.9 5.6-4.9-2.6-4.9 2.6.9-5.6L4 9l5.4-.7L12 3Z"/><path d="M5 21h14"/></svg></span><h3>Build while you learn</h3><p>Projects and assignments help turn new concepts into hands-on practice.</p></article>
            <article class="course-benefit-card"><span class="course-benefit-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></svg></span><h3>Choose your pace</h3><p>Study with recorded lessons or add scheduled live sessions.</p></article>
            <article class="course-benefit-card"><span class="course-benefit-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M12 3 19 6v5c0 4.6-3 8-7 10-4-2-7-5.4-7-10V6l7-3Z"/><path d="m9 12 2 2 4-4"/></svg></span><h3>Track your progress</h3><p>Keep lessons, quizzes, assignments, and feedback together in your account.</p></article>
        </div>
    </section>
<?php endif; ?>

<?php
$ctaTitle = 'Build skills you can put to work';
$ctaDescription = 'Learn with Tech SG Academy through practical courses guided by developers, designers and marketers who build real projects.';
$ctaPrimaryLabel = 'Create free account';
$ctaPrimaryHref = $baseUrl . '/register';
$ctaSecondaryLabel = 'Ask about a course';
$ctaSecondaryHref = $baseUrl . '/contact';
$ctaNote = 'Practical learning · Clear course pricing · Academy support';
require BASE_PATH . '/app/Views/components/academy-cta.php';
?>
