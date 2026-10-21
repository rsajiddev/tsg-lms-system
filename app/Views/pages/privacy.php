<?php $baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/'); ?>
<section class="page-hero" data-reveal><p class="eyebrow">LEGAL</p><h1>Privacy Policy</h1><p>How Tech SG Academy collects, uses and protects your personal information.</p></section>
<section class="legal-placeholder" data-reveal>
    <p>Our full privacy policy is being finalised and will be published on this page shortly.</p>
    <p>Your account data is handled by Tech SG Studio (SMC-Pvt.) Ltd. — for any privacy questions or data requests, please get in touch.</p>
    <a class="button" href="<?= App\Core\Security::escape($baseUrl) ?>/contact">Contact the Academy</a>
</section>
