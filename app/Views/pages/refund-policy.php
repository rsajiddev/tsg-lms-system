<?php $baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/'); ?>
<section class="page-hero" data-reveal>
    <p class="eyebrow">LEGAL</p>
    <h1>Refund Policy</h1>
    <p>How refunds work for course enrolments at Tech SG Academy.</p>
</section>
<section class="legal-placeholder" data-reveal>
    <p>Our complete refund policy is being finalised and will be published on this page shortly.</p>
    <p>For payment or refund questions in the meantime, reach out to the Academy team on WhatsApp or by email.</p>
    <a class="button" href="<?= App\Core\Security::escape($baseUrl) ?>/contact">Contact the Academy</a>
</section>