<?php
$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
?>
<section class="page-hero" data-reveal><p class="eyebrow">ABOUT THE ACADEMY</p><h1>Young minds. Real skills. World-class digital solutions.</h1><p>Tech SG Academy is the training arm of Tech SG Studio (SMC-Pvt.) Ltd. — a SECP-registered software company from Faisalabad, Pakistan, serving clients across Pakistan, the UK, the USA, the UAE and Europe.</p></section>
<section class="stats-band" data-reveal aria-label="Studio highlights">
    <div class="stat-card"><span class="stat-num" data-count="13" data-suffix="+">13+</span><span class="stat-label">Projects delivered by our team</span></div>
    <div class="stat-card"><span class="stat-num" data-count="5" data-suffix="+">5+</span><span class="stat-label">Countries served worldwide</span></div>
    <div class="stat-card"><span class="stat-num">2024</span><span class="stat-label">Year the company was established</span></div>
    <div class="stat-card"><span class="stat-num" data-count="100" data-suffix="%">100%</span><span class="stat-label">Practical, industry-first training</span></div>
</section>
<section class="content-split" data-reveal>
    <div>
        <p class="eyebrow">WHY WE STARTED</p>
        <h2>Students who refused to wait to make an impact.</h2>
        <p>Tech SG Studio was born from a simple belief — you don’t need to wait for permission to build something great. We are a team of young developers, designers and marketers who started with passion, learned every day and built a real, registered company.</p>
        <p>Today the studio serves businesses across Pakistan, the UK, the USA, the UAE and beyond — and the Academy passes that same practical, industry-first approach on to students. We study in the morning and build at night. And we wouldn’t have it any other way.</p>
        <a class="button" href="<?= App\Core\Security::escape($baseUrl) ?>/courses">Explore courses</a>
    </div>
    <aside class="studio-card">
        <span class="brand-mark">TSG</span>
        <h2>Tech SG Studio (SMC-Pvt.) Ltd.</h2>
        <p>Officially incorporated, verified and active with the Securities &amp; Exchange Commission of Pakistan.</p>
        <div class="secp-list">
            <span>Registered with SECP — Securities &amp; Exchange Commission of Pakistan</span>
            <span>Company type — SMC-Private Limited (SMC-Pvt. Ltd.)</span>
            <span>Established 2024 · Registered office in Faisalabad, Punjab</span>
            <span>Status — Active &amp; compliant</span>
        </div>
        <a href="https://eservices.secp.gov.pk/eServices/Search" target="_blank" rel="noopener noreferrer">Verify on the SECP portal ↗</a>
    </aside>
</section>
<section class="why-section" data-reveal>
    <div class="section-heading"><div><p class="eyebrow">OUR VALUES</p><h2>What we stand for</h2></div></div>
    <div class="why-grid">
        <article class="why-card" data-reveal><span class="why-icon" aria-hidden="true">🔥</span><h3>Passionate</h3><p>We love what we do — and it shows in every project we ship and every class we teach.</p></article>
        <article class="why-card" data-reveal style="--reveal-delay: .06s"><span class="why-icon" aria-hidden="true">💡</span><h3>Innovative</h3><p>Fresh ideas, modern design trends and up-to-date technology in everything we build.</p></article>
        <article class="why-card" data-reveal style="--reveal-delay: .12s"><span class="why-icon" aria-hidden="true">🤝</span><h3>Reliable</h3><p>We deliver on promises — on agreed deadlines, with free support after delivery.</p></article>
        <article class="why-card" data-reveal style="--reveal-delay: .18s"><span class="why-icon" aria-hidden="true">📈</span><h3>Growing</h3><p>We learn every day — and our students and clients grow right along with us.</p></article>
    </div>
</section>
<section class="content-split" data-reveal>
    <div>
        <p class="eyebrow">HOW WE WORK</p>
        <h2>Simple, transparent and stress-free.</h2>
        <p>Every Academy course — and every client project behind it — follows the same four-step process.</p>
        <ul class="process-list">
            <li><span class="process-num">01</span><span><strong>Discuss &amp; Plan</strong><p>We listen to your requirements and plan a clear roadmap.</p></span></li>
            <li><span class="process-num">02</span><span><strong>Design</strong><p>Modern, clean work tailored to the brand and the goal.</p></span></li>
            <li><span class="process-num">03</span><span><strong>Develop</strong><p>Clean, well-structured code built by our team.</p></span></li>
            <li><span class="process-num">04</span><span><strong>Launch &amp; Support</strong><p>We launch, test and provide free support after delivery.</p></span></li>
        </ul>
    </div>
    <aside class="studio-card">
        <p class="big-quote">“We are proof that age is just a number. What matters is the work you do.”</p>
        <p class="quote-by">— Tech SG Studio Team</p>
    </aside>
</section>
<section class="cta-banner" data-reveal>
    <h2>Learn from people who build</h2>
    <p>Join the Academy and start with a course taught by working developers, designers and marketers.</p>
    <div class="cta-actions">
        <a class="cta-primary" href="<?= App\Core\Security::escape($baseUrl) ?>/register">Create free account</a>
        <a class="cta-ghost" href="<?= App\Core\Security::escape($baseUrl) ?>/courses">Browse courses</a>
    </div>
    <p class="cta-note">SECP-registered company · Real projects · Verified certificates</p>
</section>
