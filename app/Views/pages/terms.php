<?php $baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/'); ?>
<section class="page-hero" data-reveal>
    <p class="eyebrow">LEGAL</p>
    <h1>Terms &amp; Conditions</h1>
    <p>The terms that govern your use of Tech SG Academy, course enrolment and student accounts.</p>
</section>
<section class="legal-placeholder" data-reveal>
    <p>Our detailed terms and conditions are being finalised and will be published on this page shortly.</p>
    <p>If you have a question about enrolment, payments or course access in the meantime, the Academy team will be happy to help.</p>
    <a class="button" href="<?= App\Core\Security::escape($baseUrl) ?>/contact">Contact the Academy</a>
</section>