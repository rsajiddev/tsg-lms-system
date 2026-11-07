<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$studioPhoto = 'https://images.unsplash.com/photo-1556761175-4b46a572b786?auto=format&fit=crop&w=1800&q=85';
$collaborationPhoto = 'https://images.unsplash.com/photo-1522202176988-66273c2fd55f?auto=format&fit=crop&w=1000&q=80';
$markets = ['Pakistan', 'United Kingdom', 'United States', 'United Arab Emirates', 'Europe'];
$services = [
    ['number' => '01', 'title' => 'Website development', 'description' => 'Responsive websites built to make a clear impression and help businesses grow.'],
    ['number' => '02', 'title' => 'Software development', 'description' => 'Custom business tools, including POS, ERP and management systems.'],
    ['number' => '03', 'title' => 'Mobile applications', 'description' => 'Android and iOS apps designed around useful features and a considered user experience.'],
    ['number' => '04', 'title' => 'Digital marketing', 'description' => 'Google, Facebook and Instagram campaigns focused on reaching the right audience.'],
    ['number' => '05', 'title' => 'Domains and hosting', 'description' => 'The domain and hosting essentials businesses need to establish an online presence.'],
    ['number' => '06', 'title' => 'Business email', 'description' => 'Professional email accounts that use your own business domain.'],
];
$principles = [
    ['number' => '01', 'title' => 'Reliable quality', 'description' => 'Thoughtful, secure work built with modern tools and professional standards.'],
    ['number' => '02', 'title' => 'Practical pricing', 'description' => 'Cost-conscious solutions for growing businesses, without losing sight of quality.'],
    ['number' => '03', 'title' => 'Fresh thinking', 'description' => 'A young team brings curiosity, current ideas and a willingness to keep learning.'],
    ['number' => '04', 'title' => 'Clear delivery', 'description' => 'A shared plan, realistic milestones and communication throughout the work.'],
    ['number' => '05', 'title' => 'Support after launch', 'description' => 'We stay available to help after delivery, not only while a project is underway.'],
    ['number' => '06', 'title' => 'People first', 'description' => 'Direct, approachable communication for clients, learners and future interns.'],
];
$workStages = [
    ['number' => '01', 'title' => 'Discuss and plan', 'description' => 'Understand the goal, align on requirements and agree on a clear roadmap.'],
    ['number' => '02', 'title' => 'Design', 'description' => 'Shape a clean, modern experience around the brand and its intended audience.'],
    ['number' => '03', 'title' => 'Develop', 'description' => 'Build the solution with care, then test it against the agreed requirements.'],
    ['number' => '04', 'title' => 'Launch and support', 'description' => 'Go live with confidence and stay connected for post-delivery support.'],
];
?>
<div class="about-page">
    <section class="about-hero" aria-labelledby="about-title">
        <img class="about-hero-image" src="<?= Security::escape($studioPhoto) ?>" alt="" fetchpriority="high" decoding="async">
        <div class="about-hero-content">
            <p class="about-hero-kicker"><span>SECP-REGISTERED</span> DIGITAL STUDIO · PAKISTAN</p>
            <h1 id="about-title">Young minds.<br><span>Real skills.</span><br>World-class digital solutions.</h1>
            <p class="about-hero-lead">We are a team of young developers, designers and marketers building websites, software and apps for businesses in Pakistan and around the world.</p>
            <div class="about-hero-actions">
                <a class="about-button about-button-primary" href="<?= Security::escape($baseUrl) ?>/courses">Explore our courses <span aria-hidden="true">→</span></a>
                <a class="about-button about-button-quiet" href="<?= Security::escape($baseUrl) ?>/contact">Talk to our team <span aria-hidden="true">↗</span></a>
            </div>
            <p class="about-hero-signoff"><span aria-hidden="true"></span> WE STUDY. WE BUILD. WE DELIVER.</p>
        </div>
        <aside class="about-hero-note" aria-label="Company overview">
            <span class="about-hero-note-mark" aria-hidden="true">TSG</span>
            <div>
                <p>BUILT WITH PURPOSE</p>
                <strong>Professional work.<br>Practical learning.</strong>
            </div>
            <span class="about-hero-note-index" aria-hidden="true">01 / 04</span>
        </aside>
        <div class="about-hero-bottom" aria-hidden="true"><span>TECH SG STUDIO</span><span>JHANG · PUNJAB · PAKISTAN</span></div>
    </section>

    <section class="about-facts" aria-label="Tech SG Studio at a glance">
        <article class="about-fact">
            <span class="about-fact-label">OFFICIAL STATUS</span>
            <strong>SECP registered</strong>
            <span>Private limited company</span>
        </article>
        <article class="about-fact">
            <span class="about-fact-label">ESTABLISHED</span>
            <strong>2024</strong>
            <span>Building from Pakistan</span>
        </article>
        <article class="about-fact">
            <span class="about-fact-label">REGISTERED CITY</span>
            <strong>Jhang, Punjab</strong>
            <span>Pakistan</span>
        </article>
        <article class="about-fact about-fact-markets">
            <span class="about-fact-label">WORKING ACROSS</span>
            <strong>Five markets</strong>
            <span><?= Security::escape(implode(' · ', $markets)) ?></span>
        </article>
    </section>

    <section class="about-story" aria-labelledby="about-story-title">
        <div class="about-story-media">
            <img src="<?= Security::escape($collaborationPhoto) ?>" alt="Students collaborating around a laptop" loading="lazy" decoding="async">
            <div class="about-story-media-caption"><span>OUR STORY</span><strong>Learn by doing.<br>Grow by building.</strong></div>
        </div>
        <div class="about-story-copy">
            <p class="about-eyebrow">WHO WE ARE</p>
            <h2 id="about-story-title">We didn't wait to make an impact.</h2>
            <p class="about-story-lead">Tech SG Studio began with a simple belief: you don't need to wait for permission to build something meaningful.</p>
            <p>We started as student innovators with a shared interest in technology and a commitment to doing useful work. Today, our team brings together development, design and marketing to help businesses create and improve their digital presence.</p>
            <p>Our work spans Pakistan, the UK, the USA, the UAE and Europe. We keep the same focus wherever we work: understand the real need, build with care and make professional digital services more accessible.</p>
            <a class="about-text-link" href="https://techsgstudio.com" target="_blank" rel="noopener noreferrer">Discover Tech SG Studio <span aria-hidden="true">↗</span></a>
        </div>
    </section>

    <section class="about-purpose" aria-label="Our mission and vision">
        <div class="about-purpose-heading">
            <p class="about-eyebrow">WHY WE DO THE WORK</p>
            <h2>Purpose behind every project.</h2>
        </div>
        <div class="about-purpose-grid">
            <article class="about-purpose-card">
                <span class="about-purpose-number">01 / MISSION</span>
                <span class="about-purpose-symbol" aria-hidden="true">↗</span>
                <h3>Make good digital work more accessible.</h3>
                <p>We aim to empower businesses of every size with creative, professional-quality digital solutions at affordable prices, delivered with care and accountability.</p>
            </article>
            <article class="about-purpose-card">
                <span class="about-purpose-number">02 / VISION</span>
                <span class="about-purpose-symbol" aria-hidden="true">◎</span>
                <h3>Earn trust through the work we do.</h3>
                <p>We aspire to become a trusted student-led technology studio in Pakistan, recognised for innovation, quality and attentive service across digital disciplines.</p>
            </article>
        </div>
        <p class="about-purpose-footnote"><span aria-hidden="true"></span> A STUDENT-LED TEAM. PROFESSIONAL STANDARDS. A GLOBAL OUTLOOK.</p>
    </section>

    <section class="about-services" aria-labelledby="about-services-title">
        <div class="about-section-heading">
            <div>
                <p class="about-eyebrow">WHAT WE DO</p>
                <h2 id="about-services-title">Digital essentials, thoughtfully delivered.</h2>
            </div>
            <p>From a first website to the systems behind a growing business, we bring the right digital skills together.</p>
        </div>
        <div class="about-services-grid">
            <?php foreach ($services as $service): ?>
                <article class="about-service-card">
                    <span class="about-card-number"><?= Security::escape($service['number']) ?></span>
                    <span class="about-service-arrow" aria-hidden="true">↗</span>
                    <h3><?= Security::escape($service['title']) ?></h3>
                    <p><?= Security::escape($service['description']) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="about-standards" aria-labelledby="about-standards-title">
        <div class="about-section-heading">
            <div>
                <p class="about-eyebrow">OUR APPROACH</p>
                <h2 id="about-standards-title">Young energy. Professional standards.</h2>
            </div>
            <p>Good partnerships come from more than good ideas. They need reliability, transparency and respect for the people behind every project.</p>
        </div>
        <div class="about-principles-grid">
            <?php foreach ($principles as $principle): ?>
                <article class="about-principle">
                    <span><?= Security::escape($principle['number']) ?></span>
                    <div><h3><?= Security::escape($principle['title']) ?></h3><p><?= Security::escape($principle['description']) ?></p></div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="about-learning" aria-labelledby="about-learning-title">
        <div class="about-learning-copy">
            <p class="about-eyebrow">LEARN · GROW · EARN</p>
            <h2 id="about-learning-title">We don't just build.<br><span>We teach, too.</span></h2>
            <p>Our connection to the work is also our approach to learning: useful skills, real-world context and guidance from people who build digital products.</p>
            <ul>
                <li>Practical courses designed around in-demand skills</li>
                <li>Internship experience working alongside the team</li>
                <li>Mentorship and portfolio-building opportunities</li>
            </ul>
            <div class="about-learning-actions">
                <a class="about-button about-button-primary" href="<?= Security::escape($baseUrl) ?>/courses">Explore Academy courses <span aria-hidden="true">→</span></a>
                <a class="about-text-link" href="<?= Security::escape($baseUrl) ?>/contact">Ask about internships <span aria-hidden="true">↗</span></a>
            </div>
        </div>
        <div class="about-work">
            <div class="about-work-heading"><p class="about-eyebrow">HOW WE WORK</p><span>FROM IDEA TO LAUNCH</span></div>
            <ol>
                <?php foreach ($workStages as $stage): ?>
                    <li>
                        <span class="about-work-number"><?= Security::escape($stage['number']) ?></span>
                        <div><h3><?= Security::escape($stage['title']) ?></h3><p><?= Security::escape($stage['description']) ?></p></div>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
    </section>

    <section class="about-registration" aria-label="Company registration information">
        <div class="about-registration-mark" aria-hidden="true">SECP</div>
        <div class="about-registration-copy">
            <p class="about-eyebrow">A REGISTERED BUSINESS</p>
            <h2>Tech SG Studio (Pvt.) Ltd.</h2>
            <p>Registered with the Securities &amp; Exchange Commission of Pakistan. Company details are available through the official SECP eServices search.</p>
        </div>
        <a class="about-registration-link" href="https://eservices.secp.gov.pk/eServices/Search" target="_blank" rel="noopener noreferrer">Verify on the SECP portal <span aria-hidden="true">↗</span></a>
    </section>

    <?php
    $ctaKicker = 'LEARN · BUILD · GROW';
    $ctaTitle = 'Build your next chapter with us.';
    $ctaDescription = 'Explore practical courses shaped by real digital work, or get in touch to tell us what you want to build.';
    $ctaPrimaryLabel = 'Explore courses';
    $ctaPrimaryHref = $baseUrl . '/courses';
    require BASE_PATH . '/app/Views/components/academy-cta.php';
    ?>
</div>
