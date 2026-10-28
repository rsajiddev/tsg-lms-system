<?php

use App\Core\Security;

/**
 * Public certificate verification page, rendered by CertificateController::verify().
 * @var string $query
 * @var array{state: string, certificate: array<string, mixed>|null} $result
 * @var string $contact
 */
$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $value): string => Security::escape((string) $value);
$query = is_string($query ?? null) ? $query : '';
$result = is_array($result ?? null) ? $result : ['state' => 'empty', 'certificate' => null];
$contact = is_string($contact ?? null) && $contact !== '' ? $contact : 'info@techsgstudio.com';
$certificate = is_array($result['certificate'] ?? null) ? $result['certificate'] : null;
$state = (string) ($result['state'] ?? 'empty');
$formatDate = static function (?string $value): string {
    if (!$value) {
        return '—';
    }
    $timestamp = strtotime($value . ' UTC');
    return $timestamp === false ? '—' : gmdate('j F Y', $timestamp);
};
?>
<section class="verify-hero">
    <div class="verify-hero-inner">
        <p class="verify-eyebrow"><span aria-hidden="true">🔍</span> Certificate verification</p>
        <h1>Verify a <span>Certificate</span></h1>
        <p>Confirm that a Tech SG Academy course certificate is genuine — instantly, with no email and no waiting.</p>
    </div>
</section>

<section class="verify-search-section">
    <form class="verify-search-card" method="get" action="<?= $escape($baseUrl) ?>/verify" role="search">
        <label class="sr-only" for="cert-id">Certificate ID</label>
        <div class="verify-search-field">
            <input id="cert-id" type="text" name="cert_id" value="<?= $escape($query) ?>" placeholder="TSG-CERT-2026-001" autocomplete="off" spellcheck="false" maxlength="64" required>
            <button class="button verify-search-button" type="submit">Verify</button>
        </div>
        <p class="verify-search-hint">The ID is printed at the bottom of your certificate, for example <strong>TSG-CERT-2026-001</strong>.</p>
    </form>
</section>

<section class="verify-result-section">
    <?php if ($state === 'empty'): ?>
        <div class="verify-stats">
            <article><strong>Instant</strong><span>Online verification</span></article>
            <article><strong>Courses &amp; workshops</strong><span>Both verified here</span></article>
            <article><strong>Free</strong><span>For everyone</span></article>
        </div>
    <?php elseif ($state === 'valid' && is_array($certificate)): ?>
        <article class="verify-card verify-card-valid">
            <header class="verify-card-head">
                <span class="verify-card-mark" aria-hidden="true">✓</span>
                <div>
                    <h2>Certificate verified</h2>
                    <p>This certificate is genuine and was issued by Tech SG Academy.</p>
                </div>
            </header>
            <div class="verify-card-body">
                <p class="verify-awarded">Awarded to</p>
                <h3 class="verify-name"><?= $escape($certificate['student_name']) ?></h3>
                <p class="verify-course"><?= $escape($certificate['course_title']) ?></p>
                <dl class="verify-grid">
                    <div><dt>Certificate ID</dt><dd class="verify-id"><?= $escape($certificate['certificate_id']) ?></dd></div>
                    <div><dt>Date of issue</dt><dd><?= $escape($formatDate((string) ($certificate['issued_at'] ?? ''))) ?></dd></div>
                    <div><dt>Course duration</dt><dd><?= $escape($certificate['duration_label'] ?: 'Not specified') ?></dd></div>
                    <div><dt>Level</dt><dd><?= $escape($certificate['level_label'] ?: 'All levels') ?></dd></div>
                    <div><dt>Valid until</dt><dd><?= $escape($formatDate((string) ($certificate['expires_at'] ?? ''))) ?></dd></div>
                    <div><dt>Status</dt><dd class="verify-ok">✓ Valid</dd></div>
                </dl>
                <?php if (!empty($certificate['admin_remarks'])): ?>
                    <p class="verify-remarks"><strong>Academy remarks</strong><span><?= $escape($certificate['admin_remarks']) ?></span></p>
                <?php endif; ?>
                <div class="verify-authenticity">
                    <span class="verify-authenticity-icon" aria-hidden="true">🛡</span>
                    <div>
                        <h4>Authenticity confirmed</h4>
                        <p>For employers, universities, and institutions.</p>
                        <ul>
                            <li>Record found in the official Tech SG Academy certificate register</li>
                            <li>Certificate is active and has not been revoked</li>
                            <li>Name, course and issue date above come directly from our records</li>
                        </ul>
                    </div>
                </div>
                <?php if (!empty($certificate['has_file'])): ?>
                    <div class="verify-actions">
                        <a class="button verify-download" href="<?= $escape($baseUrl) ?>/certificate/download?cert_id=<?= $escape(rawurlencode((string) $certificate['certificate_id'])) ?>">↓ Download certificate</a>
                        <a class="button button-secondary" href="<?= $escape($baseUrl) ?>/courses">Browse our courses</a>
                    </div>
                <?php endif; ?>
            </div>
            <footer class="verify-card-foot">Verified live from our records on <?= $escape(gmdate('j F Y')) ?>.</footer>
        </article>
    <?php elseif ($state === 'expired' && is_array($certificate)): ?>
        <article class="verify-card verify-card-expired">
            <header class="verify-card-head">
                <span class="verify-card-mark" aria-hidden="true">⏱</span>
                <div>
                    <h2>Certificate expired</h2>
                    <p>This certificate was genuine but its verification window has closed.</p>
                </div>
            </header>
            <div class="verify-card-body">
                <dl class="verify-grid">
                    <div><dt>Certificate ID</dt><dd class="verify-id"><?= $escape($certificate['certificate_id']) ?></dd></div>
                    <div><dt>Held by</dt><dd><?= $escape($certificate['student_name']) ?></dd></div>
                    <div><dt>Course</dt><dd><?= $escape($certificate['course_title']) ?></dd></div>
                    <div><dt>Expired on</dt><dd><?= $escape($formatDate((string) ($certificate['expires_at'] ?? ''))) ?></dd></div>
                </dl>
                <p class="verify-help">The certificate can no longer be downloaded or shared as valid proof. To renew the verification period, contact <a href="mailto:<?= $escape($contact) ?>"><?= $escape($contact) ?></a> with the certificate ID.</p>
            </div>
        </article>
    <?php else: ?>
        <?php
        $headings = [
            'invalid' => ['That does not look like a certificate ID', 'Certificate IDs use letters, digits and hyphens only — for example TSG-CERT-2026-001.'],
            'limited' => ['Too many checks from this connection', 'Please wait a few minutes and try again.'],
            'unavailable' => ['Verification is temporarily unavailable', 'Our certificate register cannot be reached right now. Please try again shortly.'],
        ];
        $heading = $headings[$state] ?? ['Certificate not found', 'No issued certificate matches that ID in the Tech SG Academy register.'];
        ?>
        <article class="verify-card verify-card-unknown">
            <header class="verify-card-head">
                <span class="verify-card-mark" aria-hidden="true">!</span>
                <div>
                    <h2><?= $escape($heading[0]) ?></h2>
                    <p><?= $escape($heading[1]) ?></p>
                </div>
            </header>
            <div class="verify-card-body">
                <p class="verify-help">Check the spelling against the printed certificate, or contact <a href="mailto:<?= $escape($contact) ?>"><?= $escape($contact) ?></a> and we will confirm the record for you.</p>
                <?php if ($query !== ''): ?><p class="verify-tried">Searched for: <strong><?= $escape($query) ?></strong></p><?php endif; ?>
            </div>
        </article>
    <?php endif; ?>
</section>

<section class="verify-how">
    <h2>How verification works</h2>
    <div class="verify-how-grid">
        <article><span class="verify-step" aria-hidden="true">1</span><h3>Finish your course</h3><p>Complete every published lesson, then request your certificate from <strong>My courses</strong> in your student workspace.</p></article>
        <article><span class="verify-step" aria-hidden="true">2</span><h3>The academy issues it</h3><p>An admin reviews the request and publishes a signed certificate with its own ID and validity period.</p></article>
        <article><span class="verify-step" aria-hidden="true">3</span><h3>Anyone can confirm it</h3><p>Share the certificate ID or the verification link — employers and institutions can check it here without an account.</p></article>
    </div>
</section>
