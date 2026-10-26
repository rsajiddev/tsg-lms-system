<?php

use App\Core\Security;

$baseUrl = isset($baseUrl) && is_string($baseUrl)
    ? rtrim($baseUrl, '/')
    : rtrim((require BASE_PATH . '/config/app.php')['url'], '/');

$ctaTitle = isset($ctaTitle) && is_string($ctaTitle) ? $ctaTitle : 'Ready to start learning?';
$ctaDescription = isset($ctaDescription) && is_string($ctaDescription) ? $ctaDescription : 'Explore practical courses taught by people who build real projects.';
$ctaPrimaryLabel = isset($ctaPrimaryLabel) && is_string($ctaPrimaryLabel) ? $ctaPrimaryLabel : 'Create free account';
$ctaPrimaryHref = isset($ctaPrimaryHref) && is_string($ctaPrimaryHref) ? $ctaPrimaryHref : $baseUrl . '/register';
$ctaSecondaryLabel = isset($ctaSecondaryLabel) && is_string($ctaSecondaryLabel) ? $ctaSecondaryLabel : 'Talk to our team';
$ctaSecondaryHref = isset($ctaSecondaryHref) && is_string($ctaSecondaryHref) ? $ctaSecondaryHref : $baseUrl . '/contact';
$ctaNote = isset($ctaNote) && is_string($ctaNote) ? $ctaNote : 'Practical learning · Academy support';
?>
<section class="cta-banner" data-reveal>
    <div class="cta-company">
        <a class="cta-brand" href="<?= Security::escape($baseUrl) ?>/" aria-label="Tech SG Academy home">
            <span class="cta-brand-mark" aria-hidden="true">TSG</span>
            <span><strong>Tech SG Academy</strong><small>By Tech SG Studio (SMC-PVT.) LTD.</small></span>
        </a>
        <p class="cta-company-description">A software company building practical learning experiences in web development, digital marketing and design. Learn from professionals working on real projects.</p>
        <div class="cta-social" aria-label="Follow Tech SG Studio">
            <a href="https://facebook.com/techsgstudio" target="_blank" rel="noopener noreferrer" aria-label="Tech SG Studio on Facebook">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13.4 21v-8h2.7l.4-3h-3.1V8.1c0-.9.3-1.5 1.6-1.5h1.7V3.9c-.3 0-1.3-.1-2.5-.1-2.5 0-4.2 1.5-4.2 4.3V10H7.2v3h2.8v8z"/></svg>
            </a>
            <a href="https://instagram.com/techsgstudio" target="_blank" rel="noopener noreferrer" aria-label="Tech SG Studio on Instagram">
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle class="cta-social-dot" cx="17.5" cy="6.5" r="1"/></svg>
            </a>
            <a href="https://linkedin.com/company/techsgstudio" target="_blank" rel="noopener noreferrer" aria-label="Tech SG Studio on LinkedIn">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.5 8.5V19M6.5 5.3v.1M10.5 19v-6.1c0-2.2 1.3-3.5 3.2-3.5s3.1 1.3 3.1 3.5V19M10.5 10v9M4.5 8.5h4M4.5 19h4"/><circle cx="6.5" cy="5.5" r="1.5"/></svg>
            </a>
            <a href="https://wa.me/923069524047" target="_blank" rel="noopener noreferrer" aria-label="Contact Tech SG Studio on WhatsApp">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.2 11.8a8.2 8.2 0 0 1-12.1 7.1L4 20l1.1-4A8.2 8.2 0 1 1 20.2 11.8Z"/><path d="M9 8.3c.2-.4.4-.4.7-.4h.4c.2 0 .3.1.4.4l.7 1.7c.1.2.1.4-.1.6l-.5.6c-.2.2-.2.4 0 .6.5.8 1.2 1.5 2 2 .2.1.4.1.6-.1l.7-.8c.2-.2.4-.2.6-.1l1.6.8c.3.1.4.3.3.6-.1.5-.5 1.2-1 1.4-.6.3-1.3.5-2.2.2a9.8 9.8 0 0 1-3.5-2.2 9 9 0 0 1-2.1-3.1c-.3-.9.1-1.7.4-2.2Z"/></svg>
            </a>
            <a href="https://youtube.com/@techsgstudio" target="_blank" rel="noopener noreferrer" aria-label="Tech SG Studio on YouTube">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 8.2a2.5 2.5 0 0 0-1.7-1.8C17.8 6 12 6 12 6s-5.8 0-7.3.4A2.5 2.5 0 0 0 3 8.2 25 25 0 0 0 2.6 12c0 1.3.1 2.6.4 3.8a2.5 2.5 0 0 0 1.7 1.8C6.2 18 12 18 12 18s5.8 0 7.3-.4a2.5 2.5 0 0 0 1.7-1.8c.3-1.2.4-2.5.4-3.8s-.1-2.6-.4-3.8Z"/><path class="cta-social-play" d="m10 9 5 3-5 3z"/></svg>
            </a>
        </div>
        <a class="cta-company-contact" href="mailto:info@techsgstudio.com">info@techsgstudio.com <span aria-hidden="true">↗</span></a>
    </div>
    <div class="cta-content">
        <p class="cta-kicker">LEARN WITH TECH SG ACADEMY</p>
        <h2><?= Security::escape($ctaTitle) ?></h2>
        <p><?= Security::escape($ctaDescription) ?></p>
        <div class="cta-actions">
            <a class="cta-primary" href="<?= Security::escape($ctaPrimaryHref) ?>"><?= Security::escape($ctaPrimaryLabel) ?> <span aria-hidden="true">→</span></a>
            <a class="cta-ghost" href="<?= Security::escape($ctaSecondaryHref) ?>"><?= Security::escape($ctaSecondaryLabel) ?></a>
        </div>
        <p class="cta-note"><?= Security::escape($ctaNote) ?></p>
    </div>
</section>
