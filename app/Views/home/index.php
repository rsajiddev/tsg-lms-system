<?php
use App\Core\Security;

$courses = isset($courses) && is_array($courses) ? $courses : [];
$baseUrl = isset($baseUrl) && is_string($baseUrl) ? rtrim($baseUrl, '/') : rtrim((require BASE_PATH . '/config/app.php')['url'], '/');

$faqs = [
    ['What is the difference between Live + Recorded and Recorded Only?', 'Live + Recorded includes scheduled Zoom classes and all recorded lectures. Recorded Only includes the recorded lectures, materials, assignments and quizzes, without live classes.'],
    ['When do I get access to my course?', 'Your course unlocks after your payment is confirmed. You will see it under My Courses in your dashboard.'],
    ['Do I need any experience?', 'No. Beginner courses start from the basics. You need a laptop or PC and a stable internet connection.'],
    ['What if I miss a live class?', 'Live + Recorded students can watch the recorded lectures, so you can catch up at your own pace.'],
    ['How do I get my certificate?', 'Meet the course requirements (progress, assignments and final quiz) and your certificate becomes available to download with a unique verification code.'],
    ['Can I upgrade from Recorded Only later?', 'Contact us and we will help you move to Live + Recorded.'],
];

$motivation = [
    ['🎯', 'Consistency beats intensity', 'Thirty focused minutes a day beats a five-hour cram. Small daily wins compound into real, lasting skills.'],
    ['🛠️', 'Learn by building', 'Every lesson here ends in a real project. While you learn, your portfolio grows — proof of what you can do.'],
    ['🌱', 'Progress over perfection', 'You don’t have to be great to start. You start to become great — one lesson, one project at a time.'],
    ['🚀', 'Your future self is watching', 'The skills you practise today become the opportunities, income and confidence you enjoy tomorrow.'],
];

$reasons = [
    ['💼', 'Learn from practitioners', 'Every course is taught by the Tech SG Studio team — developers building real software for clients in Pakistan, the UK, the USA, the UAE and Europe.'],
    ['🛠️', 'Project-based learning', 'No dry theory marathons. You learn a concept and immediately apply it to projects, assignments and quizzes.'],
    ['💰', 'Affordable pricing', 'Professional training at prices that make sense — with Recorded Only and Live + Recorded options for every budget.'],
    ['🎓', 'Verified certificates', 'Meet the course requirements and download a certificate with a unique verification code you can share with employers.'],
    ['🤝', 'Real mentorship', 'Stuck on a lesson? Get guidance from senior developers — the same mentorship our internship program runs on.'],
    ['🌍', 'Global standards', 'Clean code, security and performance are baked into every lesson, so you learn to international standards.'],
];

$testimonials = [
    ['MZ', 'Muhammad J. Zafar', 'Website Development Client', 'Exceptional services by Tech SG Studio. Love their work and especially the commitment to time and quality was outstanding.'],
    ['F', 'Fatima', 'Website Development Client', 'We took web development services from Tech SG Studio and were amazed by the professional services received. They have a good engagement strategy and the response time is very quick.'],
    ['SK', 'Sarah Khan', 'Online Store Owner', 'They built my e-commerce store with a complete admin panel. The team was very cooperative and delivered right on time.'],
];

$checkIcon = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 12.5l5 5 10-11"/></svg>';
$faqIcon = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>';
?>
<section class="home-hero" data-reveal>
    <div class="home-copy"><p class="eyebrow">TECH SG ACADEMY · BY TECH SG STUDIO (SMC-PVT.) LTD.</p><h1>Learn practical skills.<br><span>Build what’s next.</span></h1><p class="lead">Project-based training from a SECP-registered software company — learn from developers who build for clients in Pakistan, the UK, the USA, the UAE and Europe.</p><div class="hero-actions"><a class="button" href="<?= Security::escape($baseUrl) ?>/courses">Explore courses <span aria-hidden="true">→</span></a><a class="button button-secondary" href="<?= Security::escape($baseUrl) ?>/about">About the Academy</a></div><div class="hero-trust"><span class="trust-chip"><?= $checkIcon ?>SECP Registered Company</span><span class="trust-chip"><?= $checkIcon ?>Project-Based Learning</span><span class="trust-chip"><?= $checkIcon ?>Certificate on Completion</span></div></div>
    <div class="hero-art" aria-hidden="true"><div class="art-glow"></div><div class="art-card art-card-main"><span class="art-mark">TSG</span><span class="art-lines"><i></i><i></i><i></i></span><span class="art-label">Learning that moves you forward</span></div><div class="art-card art-card-small"><span class="art-spark">✦</span><span>Ideas into practice</span></div><span class="art-orbit"></span></div>
</section>
<section class="stats-band" data-reveal aria-label="Academy highlights">
    <div class="stat-card"><span class="stat-num" data-count="13" data-suffix="+">13+</span><span class="stat-label">Projects delivered by our team</span></div>
    <div class="stat-card"><span class="stat-num" data-count="5" data-suffix="+">5+</span><span class="stat-label">Countries served worldwide</span></div>
    <div class="stat-card"><span class="stat-num" data-count="100" data-suffix="%">100%</span><span class="stat-label">Practical, project-based learning</span></div>
    <div class="stat-card"><span class="stat-num" data-count="2" data-suffix="h">2h</span><span class="stat-label">Average response time</span></div>
</section>
<section class="home-courses" data-reveal><div class="section-heading"><div><p class="eyebrow">START LEARNING</p><h2>Courses from the Academy</h2></div><a class="text-link" href="<?= Security::escape($baseUrl) ?>/courses">View catalogue <span aria-hidden="true">→</span></a></div>
<?php if ($courses === []): ?><div class="empty-state"><h3>Courses are being prepared</h3><p>Published courses will appear here. For current training information, get in touch with the Academy.</p><a href="<?= Security::escape($baseUrl) ?>/contact">Contact the Academy →</a></div>
<?php else: ?><div class="course-grid home-course-grid"><?php $courseIndex = 0; foreach ($courses as $course): ?><article class="course-card" data-reveal style="--reveal-delay: <?= Security::escape(number_format($courseIndex * 0.07, 2)) ?>s"><p class="eyebrow"><?= Security::escape((string) ($course['category'] ?: 'TECH SG ACADEMY')) ?></p><h3><?= Security::escape((string) $course['title']) ?></h3><p><?= Security::escape((string) ($course['short_description'] ?: 'Explore the course catalogue for details.')) ?></p><?php if (!empty($course['duration_label'])): ?><p class="course-duration"><?= Security::escape((string) $course['duration_label']) ?></p><?php endif; ?><div class="price-options"><?php if ($course['recorded_price'] !== null): ?><div><span>Recorded Only</span><strong><?= Security::escape((string) $course['currency']) ?> <?= number_format((float) $course['recorded_price'], 0) ?></strong></div><?php endif; ?><?php if ($course['live_price'] !== null): ?><div><span>Live + Recorded</span><strong><?= Security::escape((string) $course['currency']) ?> <?= number_format((float) $course['live_price'], 0) ?></strong></div><?php endif; ?></div><a class="button course-cta" href="<?= Security::escape($baseUrl) ?>/register">Create student account</a></article><?php $courseIndex++; endforeach; ?></div><?php endif; ?></section>
<section class="learning-modes" data-reveal><div class="section-heading"><div><p class="eyebrow">CHOOSE YOUR FORMAT</p><h2>Learning that fits your schedule</h2></div></div><div class="mode-grid"><article class="mode-card mode-recorded" data-reveal><span class="mode-symbol">01</span><h3>Recorded Only</h3><p>Learn at your own pace with recorded lectures, materials, assignments and quizzes.</p></article><article class="mode-card mode-live" data-reveal style="--reveal-delay: .08s"><span class="mode-symbol">02</span><h3>Live + Recorded</h3><p>Join scheduled live Zoom classes and revisit every session through the recordings.</p></article></div></section>
<section class="why-section" data-reveal>
    <div class="section-heading"><div><p class="eyebrow">WHY TECH SG ACADEMY</p><h2>Learn skills the industry actually uses</h2></div></div>
    <div class="why-grid">
        <?php foreach ($reasons as $reasonIndex => $reason): ?><article class="why-card" data-reveal style="--reveal-delay: <?= Security::escape(number_format($reasonIndex * 0.06, 2)) ?>s"><span class="why-icon" aria-hidden="true"><?= $reason[0] ?></span><h3><?= Security::escape($reason[1]) ?></h3><p><?= Security::escape($reason[2]) ?></p></article><?php endforeach; ?>
    </div>
</section>
<section class="motive-section" data-reveal>
    <div class="section-heading"><div><p class="eyebrow">DAILY MOTIVATION</p><h2>A little push for every study session</h2></div></div>
    <div class="motive-grid">
        <?php foreach ($motivation as $motiveIndex => $motive): ?><article class="motive-card" data-reveal style="--reveal-delay: <?= Security::escape(number_format($motiveIndex * 0.08, 2)) ?>s"><span class="motive-spark" aria-hidden="true">✦</span><span class="motive-emoji" aria-hidden="true"><?= $motive[0] ?></span><h3><?= Security::escape($motive[1]) ?></h3><p><?= Security::escape($motive[2]) ?></p></article><?php endforeach; ?>
    </div>
</section>
<section class="testi-section" data-reveal>
    <div class="section-heading"><div><p class="eyebrow">TRUST &amp; PROOF</p><h2>Trusted by clients, loved by learners</h2></div></div>
    <div class="testi-grid">
        <?php foreach ($testimonials as $testiIndex => $testi): ?><article class="testi-card" data-reveal style="--reveal-delay: <?= Security::escape(number_format($testiIndex * 0.08, 2)) ?>s"><span class="testi-stars" aria-label="Rated 5 out of 5">★★★★★</span><blockquote>“<?= Security::escape($testi[3]) ?>”</blockquote><div class="testi-who"><span class="testi-ava" aria-hidden="true"><?= Security::escape($testi[0]) ?></span><span><span class="testi-name"><?= Security::escape($testi[1]) ?></span><span class="testi-role"><?= Security::escape($testi[2]) ?></span></span><span class="testi-verified"><?= $checkIcon ?>Verified</span></div></article><?php endforeach; ?>
    </div>
</section>
<section class="faq-section" data-reveal>
    <div class="faq-grid">
        <div class="faq-intro">
            <p class="eyebrow">NEED ANSWERS</p>
            <h2>Frequently asked questions</h2>
            <p>Cannot find your answer? <a class="text-link" href="<?= Security::escape($baseUrl) ?>/contact">Contact us.</a></p>
        </div>
        <div class="faq-list">
            <?php foreach ($faqs as $faqIndex => $faq): ?>
            <details class="faq-item"<?= $faqIndex === 0 ? ' open' : '' ?>>
                <summary><?= Security::escape($faq[0]) ?><span class="faq-icon" aria-hidden="true"><?= $faqIcon ?></span></summary>
                <p class="faq-answer"><?= Security::escape($faq[1]) ?></p>
            </details>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<section class="cta-banner" data-reveal>
    <h2>Ready to start learning?</h2>
    <p>Create your account in a minute and pick your first course.</p>
    <div class="cta-actions">
        <a class="cta-primary" href="<?= Security::escape($baseUrl) ?>/register">Create free account</a>
        <a class="cta-ghost" href="<?= Security::escape($baseUrl) ?>/contact">Talk to us</a>
    </div>
    <p class="cta-note">Free registration · No hidden fees · Mon–Sat support</p>
</section>
