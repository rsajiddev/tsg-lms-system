<?php

use App\Core\Security;

$courses = isset($courses) && is_array($courses) ? $courses : [];
$baseUrl = isset($baseUrl) && is_string($baseUrl) ? rtrim($baseUrl, '/') : rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
// Same rule as the catalogue page: a signed-in student's card leads with the payment step.
$currentUser = isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : [];
$isStudent = ($currentUser['role'] ?? null) === 'student';

$faqs = [
    ['What is the difference between Live + Recorded and Recorded Only?', 'Live + Recorded includes scheduled Zoom classes and all recorded lectures. Recorded Only includes the recorded lectures, materials, assignments and quizzes, without live classes.'],
    ['When do I get access to my course?', 'Your course unlocks after your payment is confirmed. You will see it under My Courses in your dashboard.'],
    ['Do I need any experience?', 'No. Beginner courses start from the basics. You need a laptop or PC and a stable internet connection.'],
    ['What if I miss a live class?', 'Live + Recorded students can watch the recorded lectures, so you can catch up at your own pace.'],
    ['How do I get my certificate?', 'Meet the course requirements (progress, assignments and final quiz) and your certificate becomes available to download with a unique verification code.'],
    ['Can I upgrade from Recorded Only later?', 'Contact us and we will help you move to Live + Recorded.'],
];

$motivation = [
    ['flag', 'Consistency beats intensity', 'Thirty focused minutes a day beats a five-hour cram. Small daily wins compound into real, lasting skills.'],
    ['chart', 'Learn by building', 'Every lesson here ends in a real project. While you learn, your portfolio grows — proof of what you can do.'],
    ['archive', 'Progress over perfection', 'You don\'t have to be great to start. You start to become great — one lesson, one project at a time.'],
    ['spark', 'Keep showing up', 'A little focused practice each day builds confidence, momentum and skills that last.'],
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
    <span class="hero-watermark hero-watermark-1" aria-hidden="true">ACADEMY</span>
    <span class="hero-watermark hero-watermark-2" aria-hidden="true">ACADEMY</span>
    <p class="hero-side-label" aria-hidden="true"><span>ONLINE</span><small>2026</small></p>
    <span class="hero-arcs" aria-hidden="true"></span>
    <span class="hero-cube hero-cube-1" aria-hidden="true"></span>
    <span class="hero-cube hero-cube-2" aria-hidden="true"></span>
    <div class="home-copy">
        <p class="eyebrow">TECH SG ACADEMY</p>
        <h1>Learn practical skills.<br><span>Build what’s next.</span></h1>
        <div class="hero-lead-row">
            <span class="hero-rule" aria-hidden="true"></span>
            <p class="lead">Project-based training from a SECP-registered software company — learn from developers who build for clients in Pakistan, the UK, the USA, the UAE and Europe.</p>
        </div>
        <div class="hero-trust" aria-label="Academy highlights">
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
        <div class="hero-actions">
            <a class="button" href="<?= Security::escape($baseUrl) ?>/courses">Explore courses <span aria-hidden="true">→</span></a>
        </div>
    </div>
</section>
<section class="stats-band home-stats-band" data-reveal aria-labelledby="home-stats-title">
    <header class="home-stats-heading">
        <p class="eyebrow">TRACK RECORD</p>
        <h2 id="home-stats-title">Our Project Experience</h2>
    </header>
    <div class="home-stats-grid">
        <article class="stat-card home-stat-card home-stat-projects" data-step="01">
            <strong class="stat-num" data-count="13" data-suffix="+">13+</strong>
            <h3 class="stat-label">Projects delivered</h3>
            <p>Successfully delivered by our team</p>
            <span class="home-stat-step">STEP 01 <span aria-hidden="true">•</span></span>
        </article>
        <article class="stat-card home-stat-card home-stat-countries" data-step="02">
            <strong class="stat-num" data-count="5" data-suffix="+">5+</strong>
            <h3 class="stat-label">Countries served</h3>
            <p>Working with clients around the world</p>
            <span class="home-stat-step">STEP 02 <span aria-hidden="true">•</span></span>
        </article>
        <article class="stat-card home-stat-card home-stat-learning" data-step="03">
            <strong class="stat-num" data-count="100" data-suffix="%">100%</strong>
            <h3 class="stat-label">Practical learning</h3>
            <p>Project-based training from day one</p>
            <span class="home-stat-step">STEP 03 <span aria-hidden="true">•</span></span>
        </article>
    </div>
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
    <?php else: ?>
        <div class="course-grid home-course-grid">
            <?php foreach ($courses as $courseIndex => $course): ?>
                <?php require BASE_PATH . '/app/Views/components/course-card.php'; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
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
    <div class="motive-header">
        <div>
            <p class="motive-label"><span aria-hidden="true"></span>DAILY MOTIVATION<span aria-hidden="true"></span></p>
            <h2>Small steps. Lasting skills<span class="motive-dot">.</span></h2>
        </div>
    </div>
    <div class="motive-process">
        <?php foreach ($motivation as $motivationIndex => $motivationItem): ?>
            <article class="motive-card" data-reveal style="--reveal-delay: <?= Security::escape(number_format($motivationIndex * 0.07, 2)) ?>s">
                <span class="motive-num" aria-hidden="true"><?= Security::escape((string) ($motivationIndex + 1)) ?></span>
                <h3><?= Security::escape($motivationItem[1]) ?></h3>
                <p><?= Security::escape($motivationItem[2]) ?></p>
                <span class="motive-card-meta" aria-hidden="true">STEP <?= Security::escape(str_pad((string) ($motivationIndex + 1), 2, '0', STR_PAD_LEFT)) ?> <span>•</span></span>
            </article>
        <?php endforeach; ?>
        <svg class="motive-line" viewBox="0 0 1200 170" preserveAspectRatio="none" aria-hidden="true">
            <path class="motive-wave-shadow" d="M-20 115 C145 115 155 25 325 25 S505 160 675 160 S910 45 1040 45 S1150 120 1220 95"/>
            <path class="motive-wave-main" d="M-20 108 C145 108 155 18 325 18 S505 153 675 153 S910 38 1040 38 S1150 113 1220 88"/>
        </svg>
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
            <?php /* The review box's two outcomes use the same overlay as every other confirmation,
                      so one rule covers the whole site instead of one per form. */ ?>
            <?php if ($reviewSubmitted): ?><?php \App\Core\View::toast('success', 'Thank you! Your review was received and will appear on this page once our team approves it.'); ?><?php endif; ?>
            <?php \App\Core\View::toast('error', $reviewMessage); ?>
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
<?php
$ctaKicker = 'FIND YOUR COURSE';
$ctaTitle = 'Ready to start learning?';
$ctaDescription = 'Pick a practical course, create your account on the enrollment step and take the next step in your career.';
$ctaPrimaryLabel = 'Browse courses';
$ctaPrimaryHref = $baseUrl . '/courses';
require BASE_PATH . '/app/Views/components/academy-cta.php';
?>
