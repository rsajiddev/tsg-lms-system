<?php

use App\Core\Security;

$baseUrl = isset($baseUrl) && is_string($baseUrl)
    ? rtrim($baseUrl, '/')
    : rtrim((require BASE_PATH . '/config/app.php')['url'], '/');

$ctaKicker = isset($ctaKicker) && is_string($ctaKicker) ? $ctaKicker : 'LEARN WITH TECH SG ACADEMY';
$ctaTitle = isset($ctaTitle) && is_string($ctaTitle) ? $ctaTitle : 'Ready to start learning?';
$ctaDescription = isset($ctaDescription) && is_string($ctaDescription) ? $ctaDescription : 'Explore practical courses taught by people who build real projects.';
$ctaPrimaryLabel = isset($ctaPrimaryLabel) && is_string($ctaPrimaryLabel) ? $ctaPrimaryLabel : 'Browse courses';
$ctaPrimaryHref = isset($ctaPrimaryHref) && is_string($ctaPrimaryHref) ? $ctaPrimaryHref : $baseUrl . '/courses';
?>
<section class="cta-banner" data-reveal>
    <div class="cta-content">
        <p class="cta-kicker"><span aria-hidden="true"></span><?= Security::escape($ctaKicker) ?></p>
        <h2><?= Security::escape($ctaTitle) ?></h2>
        <p class="cta-description"><?= Security::escape($ctaDescription) ?></p>
        <div class="cta-actions">
            <a class="cta-primary" href="<?= Security::escape($ctaPrimaryHref) ?>"><?= Security::escape($ctaPrimaryLabel) ?> <span class="cta-arrow" aria-hidden="true">→</span></a>
        </div>
        <p class="cta-signature">TECH SG ACADEMY <span aria-hidden="true">·</span> LEARN · BUILD · GROW</p>
    </div>
</section>
