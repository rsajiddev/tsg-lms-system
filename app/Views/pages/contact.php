<?php

use App\Core\Security;

/**
 * Public contact page. The left column is the "get in touch" pitch with the academy's
 * direct details; the right column is the enquiry form. A submission is emailed to the
 * company inbox and acknowledged back to the sender by PublicPageController::submitContact.
 *
 * @var array<string, string> $values
 * @var bool $sent
 * @var string|null $error
 * @var string $baseUrl
 */
$old = static fn(string $key): string => Security::escape((string) ($values[$key] ?? ''));
?>
<section class="contact-shell" data-reveal>
    <div class="contact-intro">
        <p class="eyebrow">CONTACT</p>
        <h1 class="contact-title">Let’s get in touch.</h1>
        <p class="contact-lead">Don’t be afraid to say hello. Whether you are choosing a course, sorting out a payment or just want to know more about the Academy, our team usually replies within two working hours.</p>

        <div class="contact-facts">
            <div class="contact-fact">
                <span class="contact-fact-label">Phone</span>
                <a class="contact-fact-value" href="tel:+923069524047">+92 306 9524047</a>
            </div>
            <div class="contact-fact">
                <span class="contact-fact-label">WhatsApp</span>
                <a class="contact-fact-value" href="https://wa.me/923069524047" target="_blank" rel="noopener noreferrer">Chat with the team</a>
            </div>
            <div class="contact-fact">
                <span class="contact-fact-label">Email</span>
                <a class="contact-fact-value" href="mailto:info@techsgstudio.com">info@techsgstudio.com</a>
            </div>
            <div class="contact-fact">
                <span class="contact-fact-label">Office</span>
                <span class="contact-fact-value">Faisalabad, Punjab, Pakistan<br>Monday to Saturday · 10:00 AM – 6:00 PM (PKT)</span>
            </div>
        </div>
    </div>

    <div class="contact-form-panel">
        <h2 class="contact-form-title">Contact</h2>
        <p class="contact-form-sub">Great! We’re excited to hear from you. Tell us what you are interested in and we will start something special together.</p>

        <?php if ($sent): ?><?php \App\Core\View::toast('success', 'Thank you! Your message has been received — a member of our team will email you shortly.'); ?><?php endif; ?>
        <?php \App\Core\View::toast('error', $error); ?>

        <form method="post" action="<?= Security::escape($baseUrl) ?>/contact" class="contact-form" novalidate>
            <input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>">
            <div class="contact-form-grid">
                <label class="contact-field">Name
                    <input name="name" type="text" required minlength="2" maxlength="150" autocomplete="name" value="<?= $old('name') ?>">
                </label>
                <label class="contact-field">Email
                    <input name="email" type="email" required maxlength="190" autocomplete="email" value="<?= $old('email') ?>">
                </label>
                <label class="contact-field">Phone <small>(optional)</small>
                    <input name="phone" type="text" maxlength="40" autocomplete="tel" value="<?= $old('phone') ?>">
                </label>
                <label class="contact-field">Subject
                    <input name="subject" type="text" required minlength="2" maxlength="150" value="<?= $old('subject') ?>">
                </label>
            </div>
            <label class="contact-field">Tell us about what you’re interested in
                <textarea name="message" rows="5" required minlength="10" maxlength="2000" placeholder="Courses, fees, schedules or anything else…"><?= $old('message') ?></textarea>
            </label>
            <button class="button contact-send" type="submit">Send to us <span aria-hidden="true">→</span></button>
            <p class="contact-form-note">We only use your details to reply to your enquiry — never for anything else.</p>
        </form>
    </div>
</section>

<section class="contact-note" data-reveal>
    <p><strong>Tech SG Studio (SMC-Pvt.) Ltd.</strong> — registered with the Securities &amp; Exchange Commission of Pakistan, serving clients in Pakistan, the UK, the USA, the UAE and Europe. <a href="https://techsgstudio.com" target="_blank" rel="noopener noreferrer">Visit the company website ↗</a></p>
</section>
