<?php

use App\Core\Security;

$courses = isset($courses) && is_array($courses) ? $courses : [];
$catalogueAvailable = isset($catalogueAvailable) ? (bool) $catalogueAvailable : false;
$baseUrl = isset($baseUrl) && is_string($baseUrl) ? rtrim($baseUrl, '/') : rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
// Passed into the course card: a signed-in student has already cleared the account step, so their
// card should offer the step that is actually left to do.
$currentUser = isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : [];
$isStudent = ($currentUser['role'] ?? null) === 'student';
// Set when an /enrol/{slug} link could not be honoured, so the visitor is not silently dropped back
// onto the catalogue with no idea what went wrong.
$flashError = isset($flashError) && is_string($flashError) && $flashError !== '' ? $flashError : null;
$heroImages = [
    ['file' => 'course-hero1.jpg', 'alt' => 'Learner studying a new skill'],
    ['file' => 'course-hero2.jpg', 'alt' => 'Learner practising and taking notes'],
    ['file' => 'course-hero3.jpg', 'alt' => 'Learners collaborating on a project'],
    ['file' => 'course-hero4.jpg', 'alt' => 'Student building a real-world project'],
    ['file' => 'course-hero5.jpg', 'alt' => 'Mentor guiding learners through a lesson'],
    ['file' => 'course-hero6.jpg', 'alt' => 'Graduate working with new skills'],
];
?>
<section class="catalog-hero" data-reveal>
    <div class="catalog-hero-copy">
        <p class="eyebrow">TECH SG ACADEMY · COURSE CATALOGUE</p>
        <h1>Find your next skill.<br><span>Build your future.</span></h1>
        <p class="catalog-hero-lead">Explore practical courses in web development, digital marketing and design, taught by people who build real projects.</p>
        <form class="catalog-search" action="#course-list" role="search" data-course-search-form>
            <label class="sr-only" for="catalog-course-search">Search courses</label>
            <input id="catalog-course-search" type="search" placeholder="What do you want to learn?" autocomplete="off" data-course-search-input>
            <button class="button" type="submit">Search <span aria-hidden="true">→</span></button>
        </form>
        <div class="catalog-hero-actions">
            <a class="catalog-hero-link" href="#course-list">Browse all courses <span aria-hidden="true">↓</span></a>
            <a class="catalog-hero-link" href="<?= Security::escape($baseUrl) ?>/about">How we teach <span aria-hidden="true">↗</span></a>
        </div>
    </div>
    <div class="catalog-hero-art" aria-label="Learn with Tech SG Academy">
        <span class="catalog-hero-dot-grid catalog-hero-dot-grid-one" aria-hidden="true"></span>
        <span class="catalog-hero-dot-grid catalog-hero-dot-grid-two" aria-hidden="true"></span>
        <span class="catalog-hero-orb catalog-hero-orb-one" aria-hidden="true"></span>
        <span class="catalog-hero-orb catalog-hero-orb-two" aria-hidden="true"></span>
        <div class="catalog-hero-gallery">
            <?php foreach ($heroImages as $heroIndex => $heroImage): ?>
                <figure class="catalog-hero-tile catalog-hero-tile-<?= (int) $heroIndex + 1 ?>" data-image-frame>
                    <div class="course-image-placeholder" aria-live="polite"><span class="course-image-spinner" aria-hidden="true"></span><span class="course-image-status">Loading learning image…</span></div>
                    <img src="<?= Security::escape($baseUrl) ?>/public/assets/images/<?= Security::escape($heroImage['file']) ?>" alt="<?= Security::escape($heroImage['alt']) ?>" loading="<?= $heroIndex < 3 ? 'eager' : 'lazy' ?>" decoding="async"<?= $heroIndex === 0 ? ' fetchpriority="high"' : '' ?> data-course-image>
                </figure>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php /* The catalogue's one message is a confirmation of what the visitor just tried to do, so it
          floats over the list instead of sitting under it. */ ?>
<?php \App\Core\View::toast('error', $flashError); ?>

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
        <p class="catalog-search-empty" data-course-search-empty aria-live="polite" hidden>No courses match your search. Try another keyword.</p>
    </section>
    <section class="course-benefits" data-reveal aria-labelledby="course-benefits-title">
        <div class="course-benefits-intro">
            <div class="course-benefits-aside">
                <p class="eyebrow">WHY TECH SG ACADEMY</p>
                <h2 id="course-benefits-title">Why<br>learn<br>with us?</h2>
                <ul aria-label="Our learning principles">
                    <li>Practical by design</li>
                    <li>Guided by practitioners</li>
                    <li>Built around progress</li>
                    <li>Focused on real skills</li>
                </ul>
            </div>
            <div class="course-benefits-copy">
                <p class="course-benefits-lead">Build skills you can use, with learning shaped by the work software teams do every day.</p>
                <p>Explore focused courses, practise through projects and assignments, and keep your learning in one place. Choose recorded lessons for flexibility or add scheduled live sessions when you want a guided classroom experience.</p>
            </div>
        </div>
        <div class="course-benefit-grid" aria-label="Academy highlights">
            <article class="course-benefit-card">
                <span class="course-benefit-watermark" aria-hidden="true"><?= Security::escape((string) count($courses)) ?></span>
                <strong><?= Security::escape((string) count($courses)) ?></strong>
                <div class="course-benefit-caption"><h3>Courses to explore</h3><p>Choose a focused course designed to help you build useful, career-ready skills step by step.</p></div>
            </article>
            <article class="course-benefit-card">
                <span class="course-benefit-watermark" aria-hidden="true">02</span>
                <strong>2</strong>
                <div class="course-benefit-caption"><h3>Ways to learn</h3><p>Pick recorded lessons for flexibility, or join scheduled live sessions and revisit them later.</p></div>
            </article>
            <article class="course-benefit-card">
                <span class="course-benefit-watermark" aria-hidden="true">BUILD</span>
                <strong class="course-benefit-word">Build</strong>
                <div class="course-benefit-caption"><h3>Learn by doing</h3><p>Apply each concept through practical projects, assignments, and quizzes as you progress.</p></div>
            </article>
        </div>
    </section>
    <section class="course-learning-path" aria-labelledby="course-path-title">
        <div class="course-format-heading">
            <p class="eyebrow">LEARN · PRACTISE · PROGRESS</p>
            <h2 id="course-path-title">A clear path from your first lesson to real work.</h2>
            <p>Each course brings the learning essentials together, so you can focus on building skills and seeing your progress.</p>
        </div>
        <div class="course-path-grid">
            <article class="course-path-card">
                <span class="course-path-number" aria-hidden="true">01</span>
                <div><h3>Follow structured lessons</h3><p>Move through organized lectures and course materials that build your understanding one concept at a time.</p></div>
            </article>
            <article class="course-path-card">
                <span class="course-path-number" aria-hidden="true">02</span>
                <div><h3>Put concepts into practice</h3><p>Use assignments, quizzes, and practical projects to test what you have learned and strengthen your skills.</p></div>
            </article>
            <article class="course-path-card">
                <span class="course-path-number" aria-hidden="true">03</span>
                <div><h3>Track your progress</h3><p>Keep lessons, results, and feedback together in your student dashboard as you work towards completion.</p></div>
            </article>
            <article class="course-path-card course-path-card-accent">
                <span class="course-path-number" aria-hidden="true">04</span>
                <div><h3>Earn a verified certificate</h3><p>Complete the course requirements and receive a certificate with a unique code employers can verify.</p></div>
            </article>
        </div>
    </section>
<?php endif; ?>

<?php
$ctaKicker = 'FIND YOUR COURSE';
$ctaTitle = 'Build skills you can put to work';
$ctaDescription = 'Learn with Tech SG Academy through practical courses guided by developers, designers and marketers who build real projects.';
$ctaPrimaryLabel = 'Choose a course to enroll';
$ctaPrimaryHref = '#course-list';
require BASE_PATH . '/app/Views/components/academy-cta.php';
?>
