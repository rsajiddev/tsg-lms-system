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
    ['💼', 'Learn from practitioners', 'Every single course is engineered and delivered by active senior engineers at Tech SG Studio — professionals who build, scale, and deploy enterprise-grade software for international clients across Pakistan, the UK, the USA, the UAE, and Europe. Instead of academic theory, you master real-world workflows, production standards, and tools used in daily software development.'],
    ['🛠️', 'Project-based learning', 'We eliminate passive learning through hands-on development. Every theoretical concept is reinforced with practical exercises, graded assignments, and quizzes so you finish each module with functional code[cite: 1]. By completion, you build an industry-ready portfolio showcasing real-world software applications that confidently demonstrate your technical capabilities to hiring managers.'],
    ['💰', 'Affordable pricing', 'We offer accessible, high-caliber technical education with transparent structures designed for students, freelancers, and working professionals[cite: 1]. Choose between self-paced Recorded Access or interactive Live Zoom Classes[cite: 1]. You pay strictly for your chosen track with zero hidden fees or forced add-on bundles, delivering maximum value for your career investment.'],
    ['🎓', 'Verified certificates', 'Earn a verifiable credential upon completing course requirements, including practical projects, assignments, and the final assessment[cite: 1]. Each certificate features a unique verification code that employers and clients can instantly validate on our official portal, ensuring your hard-earned skill set stands up to professional scrutiny worldwide.'],
    ['🤝', 'Real mentorship', 'Never hit a dead end in your learning journey. Get direct access to senior engineers for code reviews, architectural feedback, and complex bug troubleshooting — leveraging the same mentorship framework that powers our internal engineering team[cite: 1]. Learn how seasoned developers think, structure logic, and solve high-priority technical issues.'],
    ['🌍', 'Global standards', 'Clean code architecture, robust security protocols, and performance optimization are core elements built directly into every module[cite: 1]. You master industry-standard code conventions, peer review habits, and deployment strategies required by top-tier international software houses, making your skills directly transferrable to global remote or local teams[cite: 1].'],
];

$reviews = isset($reviews) && is_array($reviews) ? $reviews : [];
$reviewValues = isset($reviewValues) && is_array($reviewValues) ? $reviewValues : [];
$reviewMessage = isset($reviewMessage) && is_string($reviewMessage) ? $reviewMessage : null;
$reviewSubmitted = !empty($reviewSubmitted);

$reviewType = in_array($reviewValues['reviewer_type'] ?? '', ['student', 'client'], true) ? $reviewValues['reviewer_type'] : 'student';
$oldReview = static fn(string $key): string => Security::escape((string) ($reviewValues[$key] ?? ''));

$checkIcon = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 12.5l5 5 10-11"/></svg>';
$faqIcon = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>';
?>
<section class="home-hero" data-reveal>
    <div class="hero-decor" aria-hidden="true">
        <span class="decor-blob decor-blob-1"></span>
        <span class="decor-blob decor-blob-2"></span>
        <span class="decor-blob decor-blob-3"></span>
        <span class="decor-shape decor-star decor-star-1"></span>
        <span class="decor-shape decor-star decor-star-2"></span>
        <span class="decor-shape decor-star decor-star-3"></span>
        <span class="decor-shape decor-sparkle decor-sparkle-1">✦</span>
        <span class="decor-shape decor-sparkle decor-sparkle-2">✦</span>
        <span class="decor-shape decor-plus">+</span>
        <span class="decor-shape decor-ring"></span>
        <span class="decor-shape decor-ring decor-ring-2"></span>
        <span class="decor-shape decor-square"></span>
        <span class="decor-shape decor-triangle"></span>
        <span class="decor-shape decor-dot"></span>
        <span class="decor-shape decor-dot decor-dot-2"></span>
        <span class="decor-shape decor-dot decor-dot-3"></span>
        <span class="decor-shape decor-cross">×</span>
    </div>
    <div class="home-copy">
        <p class="eyebrow">TECH SG ACADEMY · BY TECH SG STUDIO (SMC-PVT.) LTD.</p>
        <h1>Learn practical skills.<br><span>Build what’s next.</span></h1>
        <p class="lead">Project-based training from a SECP-registered software company — learn from developers who build for clients in Pakistan, the UK, the USA, the UAE and Europe.</p>
        <div class="hero-actions">
            <a class="button" href="<?= Security::escape($baseUrl) ?>/courses">Explore courses <span aria-hidden="true">→</span></a>
        </div>
        <div class="hero-trust">
            <div class="trust-pill">
                <span class="trust-num">01</span>
                <span class="trust-text">SECP Registered Company</span>
            </div>
            <div class="trust-pill">
                <span class="trust-num">02</span>
                <span class="trust-text">Project-Based Learning</span>
            </div>
            <div class="trust-pill">
                <span class="trust-num">03</span>
                <span class="trust-text">Certificate on Completion</span>
            </div>
        </div>
    </div>
    <div class="hero-art" aria-hidden="true">
        <div class="art-glow"></div>

        <div class="art-card art-card-main">
            <div class="art-card-top">
                <span class="art-mark">TSG</span>
                <span class="art-chip"><i></i>Live + Recorded</span>
            </div>
            <div class="art-features">
                <span class="art-feature"><i><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16v14H4z"></path><path d="M10 9.5l4.5 2.5L10 14.5z"></path></svg></i>Recorded lessons<strong>24</strong></span>
                <span class="art-feature"><i><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l9 5-9 5-9-5 9-5Z"></path><path d="M3 13l9 5 9-5"></path></svg></i>Hands-on projects<strong>06</strong></span>
                <span class="art-feature"><i><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-3"></path><path d="M9 3.5h6v3H9z"></path><path d="M8.5 13l2.2 2.2 4.3-4.4"></path></svg></i>Quizzes &amp; tasks<strong>12</strong></span>
            </div>
            <span class="art-label">Learning that moves you forward</span>
            <div class="art-progress">
                <span class="art-progress-head"><span>Course progress</span><strong>78%</strong></span>
                <span class="art-bar"><i></i></span>
            </div>
        </div>
        <span class="art-orbit"></span>
    </div>
</section>
<section class="stats-band" data-reveal aria-label="Academy highlights">
    <div class="stat-card"><span class="stat-num" data-count="13" data-suffix="+">13+</span><span class="stat-label">Projects delivered by our team</span></div>
    <div class="stat-card"><span class="stat-num" data-count="5" data-suffix="+">5+</span><span class="stat-label">Countries served worldwide</span></div>
    <div class="stat-card"><span class="stat-num" data-count="100" data-suffix="%">100%</span><span class="stat-label">Practical, project-based learning</span></div>
    <div class="stat-card"><span class="stat-num" data-count="2" data-suffix="h">2h</span><span class="stat-label">Average response time</span></div>
</section>
<section class="home-courses" data-reveal>
    <div class="section-heading">
        <div>
            <p class="eyebrow">START LEARNING</p>
            <h2>Courses from the Academy</h2>
        </div><a class="text-link" href="<?= Security::escape($baseUrl) ?>/courses">View catalogue <span aria-hidden="true">→</span></a>
    </div>
    <?php if ($courses === []): ?><div class="empty-state">
            <h3>Courses are being prepared</h3>
            <p>Published courses will appear here. For current training information, get in touch with the Academy.</p><a href="<?= Security::escape($baseUrl) ?>/contact">Contact the Academy →</a>
        </div>
    <?php else: ?><div class="course-grid home-course-grid"><?php $courseIndex = 0;
                                                            foreach ($courses as $course): ?><article class="course-card" data-reveal style="--reveal-delay: <?= Security::escape(number_format($courseIndex * 0.07, 2)) ?>s">
                    <p class="eyebrow"><?= Security::escape((string) ($course['category'] ?: 'TECH SG ACADEMY')) ?></p>
                    <h3><?= Security::escape((string) $course['title']) ?></h3>
                    <p><?= Security::escape((string) ($course['short_description'] ?: 'Explore the course catalogue for details.')) ?></p><?php if (!empty($course['duration_label'])): ?><p class="course-duration"><?= Security::escape((string) $course['duration_label']) ?></p><?php endif; ?><div class="price-options"><?php if ($course['recorded_price'] !== null): ?><div><span>Recorded Only</span><strong><?= Security::escape((string) $course['currency']) ?> <?= number_format((float) $course['recorded_price'], 0) ?></strong></div><?php endif; ?><?php if ($course['live_price'] !== null): ?><div><span>Live + Recorded</span><strong><?= Security::escape((string) $course['currency']) ?> <?= number_format((float) $course['live_price'], 0) ?></strong></div><?php endif; ?></div><?php $signedInUser = isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : []; ?><a class="button course-cta" href="<?= Security::escape($baseUrl) ?><?= ($signedInUser['role'] ?? '') === 'student' ? '/payments/new?course_id=' . (int) $course['id'] : '/register' ?>"><?= ($signedInUser['role'] ?? '') === 'student' ? 'Enroll / submit payment' : 'Create student account' ?></a>
                </article><?php $courseIndex++;
                                                            endforeach; ?></div><?php endif; ?>
</section>
<section class="learning-modes" data-reveal>
    <div class="section-heading">
        <div>
            <p class="eyebrow">CHOOSE YOUR FORMAT</p>
            <h2>Learning that fits your schedule</h2>
        </div>
    </div>
    <div class="mode-grid">
        <article class="mode-card mode-recorded" data-reveal><span class="mode-symbol">01</span>
            <h3>Recorded Only</h3>
            <p>Learn at your own pace with recorded lectures, materials, assignments and quizzes.</p>
        </article>
        <article class="mode-card mode-live" data-reveal style="--reveal-delay: .08s"><span class="mode-symbol">02</span>
            <h3>Live + Recorded</h3>
            <p>Join scheduled live Zoom classes and revisit every session through the recordings.</p>
        </article>
    </div>
</section>
<section class="why-section why-orbit-section" data-reveal aria-label="Why Tech SG Academy">
    <div class="why-grid">
        <?php foreach ($reasons as $reasonIndex => $reason): ?><article class="why-card" data-reveal style="--reveal-delay: <?= Security::escape(number_format($reasonIndex * 0.06, 2)) ?>s"><span class="why-icon" aria-hidden="true"><?= $reason[0] ?></span>
                <h3><?= Security::escape($reason[1]) ?></h3>
                <p><?= Security::escape($reason[2]) ?></p>
            </article><?php endforeach; ?>
    </div>
</section>
<section class="motive-section" data-reveal>
    <div class="section-heading">
        <div>
            <p class="eyebrow">DAILY MOTIVATION</p>
            <h2>A little push for every study session</h2>
        </div>
    </div>
    <div class="motive-grid">
        <?php foreach ($motivation as $motiveIndex => $motive): ?><article class="motive-card" data-reveal style="--reveal-delay: <?= Security::escape(number_format($motiveIndex * 0.08, 2)) ?>s"><span class="motive-spark" aria-hidden="true">✦</span><span class="motive-emoji" aria-hidden="true"><?= $motive[0] ?></span>
                <h3><?= Security::escape($motive[1]) ?></h3>
                <p><?= Security::escape($motive[2]) ?></p>
            </article><?php endforeach; ?>
    </div>
</section>
<section class="dashboard-section" data-reveal>
    <div class="section-heading">
        <div>
            <p class="eyebrow">YOUR LEARNING SPACE</p>
            <h2>One dashboard for everything</h2>
            <p class="dashboard-lead">Your classes, lessons, results and receipts, organised in one place.</p>
        </div>
    </div>
    <div class="dashboard-grid">
        <article class="dash-card dash-progress" data-reveal>
            <h3>Progress tracking</h3>
            <p>See lessons completed and your overall percentage, per course.</p>
            <div class="dash-bars">
                <div class="dash-bar"><span>Web Development</span><strong>68%</strong><span class="dash-track"><i style="--fill: 68%"></i></span></div>
                <div class="dash-bar"><span>Digital Marketing</span><strong>42%</strong><span class="dash-track"><i style="--fill: 42%"></i></span></div>
                <div class="dash-bar"><span>Graphic Design</span><strong>15%</strong><span class="dash-track"><i style="--fill: 15%"></i></span></div>
            </div>
        </article>
        <article class="dash-card dash-schedule" data-reveal style="--reveal-delay: .06s">
            <h3>Class schedule</h3>
            <p>Upcoming live classes with date, time and a one-click Zoom join.</p>
        </article>
        <article class="dash-card" data-reveal style="--reveal-delay: .12s">
            <h3>Assignments</h3>
            <p>Submit files and read teacher feedback.</p>
        </article>
        <article class="dash-card" data-reveal style="--reveal-delay: .18s">
            <h3>Timed quizzes</h3>
            <p>Instant results after you submit.</p>
        </article>
        <article class="dash-card dash-wide" data-reveal style="--reveal-delay: .24s">
            <h3>Payments and receipts</h3>
            <p>Transaction history and receipts whenever you need them.</p>
        </article>
        <article class="dash-card dash-wide" data-reveal style="--reveal-delay: .3s">
            <h3>Support tickets</h3>
            <p>Ask your teacher or the admin team and track the reply.</p>
        </article>
    </div>
</section>
<section class="testi-section" id="reviews">
    <div class="section-heading">
        <div>
            <p class="eyebrow">STUDENT &amp; CLIENT REVIEWS</p>
            <h2>What our students and clients say</h2>
        </div>
    </div>
    <?php if ($reviews === []): ?>
        <div class="empty-state" data-reveal>
            <div>
                <h3>No approved reviews yet</h3>
                <p>Student and client reviews will appear here after they are approved.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="review-marquee" data-reveal aria-label="Reviews from our students and clients">
            <div class="review-track">
                <?php // Cards render twice so the marquee loops seamlessly.
                $renderReviewCards = static function (bool $clone) use ($reviews, $checkIcon): void {
                    foreach ($reviews as $review) {
                        $reviewRating = max(1, min(5, (int) $review['rating']));
                        $reviewWords = preg_split('/\s+/', trim((string) $review['full_name'])) ?: [];
                        $reviewInitials = strtoupper(mb_substr((string) ($reviewWords[0] ?? 'T'), 0, 1) . mb_substr((string) ($reviewWords[1] ?? ''), 0, 1));
                ?><article class="testi-card" <?= $clone ? ' aria-hidden="true"' : '' ?>>
                            <div class="testi-top"><span class="testi-stars" aria-label="Rated <?= Security::escape((string) $reviewRating) ?> out of 5"><?= Security::escape(str_repeat('★', $reviewRating) . str_repeat('☆', 5 - $reviewRating)) ?></span><span class="testi-type type-<?= Security::escape((string) $review['reviewer_type']) ?>"><?= $review['reviewer_type'] === 'client' ? 'Client' : 'Student' ?></span></div>
                            <blockquote>“<?= Security::escape((string) $review['content']) ?>”</blockquote>
                            <div class="testi-who"><span class="testi-ava" aria-hidden="true"><?= Security::escape($reviewInitials) ?></span><span><span class="testi-name"><?= Security::escape((string) $review['full_name']) ?></span><span class="testi-role"><?= Security::escape((string) ($review['context_label'] ?: ($review['reviewer_type'] === 'client' ? 'Client' : 'Student'))) ?></span></span><span class="testi-verified"><?= $checkIcon ?>Verified</span></div>
                        </article><?php
                                }
                            };
                            $renderReviewCards(false);
                            $renderReviewCards(true);
                                    ?>
            </div>
        </div>
    <?php endif; ?>
    <div class="review-lower" data-reveal>
        <div class="review-intro">
            <p class="eyebrow">WHY YOUR REVIEW MATTERS</p>
            <h3>Real feedback keeps the Academy honest</h3>
            <p>Reviews on this page are not marketing copy. Every line comes from someone who sat through our classes or trusted Tech SG Studio with a live project, and nothing is polished beyond a typo fix. When you share your experience — the good and the frustrating — you help the next student pick the right course and help us fix what we get wrong.</p>
            <p class="review-sign">Submitted reviews go live after a quick moderation check, usually within a day.<br>— Team Tech SG Academy</p>
        </div>
        <div class="review-box" id="review-form">
            <div class="review-box-head">
                <h3>Share your experience</h3>
                <p>Took a course or worked with our team? Students and clients are both welcome to leave a review — it appears here after a quick check by our team.</p>
            </div>
            <?php if ($reviewSubmitted): ?><p class="review-banner review-success" role="status">Thank you! Your review was received and will appear on this page once our team approves it.</p><?php endif; ?>
            <?php if ($reviewMessage !== null): ?><p class="review-banner review-error" role="alert"><?= Security::escape($reviewMessage) ?></p><?php endif; ?>
            <form method="post" action="<?= Security::escape($baseUrl) ?>/reviews" class="review-form">
                <input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>">
                <div class="reviewer-type" role="radiogroup" aria-label="I am a">
                    <label class="review-type"><input type="radio" name="reviewer_type" value="student" data-context-placeholder="e.g. Web Development student" <?= $reviewType === 'student' ? ' checked' : '' ?>><span>🎓 I'm a student</span></label>
                    <label class="review-type"><input type="radio" name="reviewer_type" value="client" data-context-placeholder="e.g. Founder, Tech SG Studio" <?= $reviewType === 'client' ? ' checked' : '' ?>><span>💼 I'm a client</span></label>
                </div>
                <div class="review-fields">
                    <label class="field">Your name<input class="review-input" name="full_name" required minlength="2" maxlength="150" value="<?= $oldReview('full_name') ?>"></label>
                    <label class="field">Course or project <small>(optional)</small><input class="review-input" id="review-context" name="context_label" maxlength="120" placeholder="e.g. Web Development student" value="<?= $oldReview('context_label') ?>"></label>
                </div>
                <fieldset class="review-rating">
                    <legend>Your rating</legend>
                    <div class="star-rating">
                        <?php $oldRating = (int) ($reviewValues['rating'] ?? 5);
                        for ($star = 5; $star >= 1; $star--): ?><input type="radio" id="rating-<?= $star ?>" name="rating" value="<?= $star ?>" <?= $oldRating === $star ? ' checked' : '' ?>><label for="rating-<?= $star ?>" aria-label="<?= $star ?> star<?= $star > 1 ? 's' : '' ?>">★</label><?php endfor; ?>
                    </div>
                </fieldset>
                <label class="field">Your review<textarea class="review-input" name="content" rows="4" required minlength="15" maxlength="1500" placeholder="What did you learn, or what did we build for you?"><?= $oldReview('content') ?></textarea></label>
                <button class="button review-submit" type="submit">Submit review</button>
                <p class="review-note">Honest feedback only — reviews are moderated before publishing.</p>
            </form>
        </div>
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
                <details class="faq-item" <?= $faqIndex === 0 ? ' open' : '' ?>>
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
