<?php

use App\Core\Security;

/**
 * Public certificate verification page, rendered by CertificateController::verify().
 *
 * A certificate is issued under its owner's account ID, so one ID can legitimately open several
 * certificates - one per course - and every match is listed.
 *
 * @var string $query
 * @var array{state: string, certificates: array<int, array<string, mixed>>} $result
 * @var string $contact
 */
$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $value): string => Security::escape((string) $value);
$query = is_string($query ?? null) ? $query : '';
$result = is_array($result ?? null) ? $result : ['state' => 'empty', 'certificates' => []];
$contact = is_string($contact ?? null) && $contact !== '' ? $contact : 'info@techsgstudio.com';
$certificates = is_array($result['certificates'] ?? null) ? array_values($result['certificates']) : [];
$state = (string) ($result['state'] ?? 'empty');
$formatDate = static function (?string $value): string {
    if (!$value) {
        return '—';
    }
    $timestamp = strtotime($value . ' UTC');
    return $timestamp === false ? '—' : gmdate('j F Y', $timestamp);
};
?>
<div class="verify-page">
<section class="verify-hero" aria-labelledby="verify-page-title">
    <div class="verify-hero-wordmark" aria-hidden="true"><span>TSG</span><span>ACADEMY</span></div>
    <div class="verify-hero-inner">
        <p class="verify-eyebrow"><span aria-hidden="true">01</span> TECH SG ACADEMY · CERTIFICATE REGISTER</p>
        <h1 id="verify-page-title">Verify a <span>Certificate</span></h1>
        <p>Check official Tech SG Academy certificates instantly, with no email and no waiting.</p>
    </div>
</section>

<section class="verify-search-section">
    <form class="verify-search-card" method="get" action="<?= $escape($baseUrl) ?>/verify" role="search">
        <div class="verify-search-heading">
            <div>
                <p class="verify-section-kicker">INSTANT RECORD LOOKUP</p>
                <h2>Search the certificate register</h2>
            </div>
            <span class="verify-search-badge">PUBLIC LOOKUP</span>
        </div>
        <label class="verify-search-label" for="cert-id">Academy ID</label>
        <div class="verify-search-field">
            <span class="verify-search-icon" aria-hidden="true"></span>
            <input id="cert-id" type="text" name="cert_id" value="<?= $escape($query) ?>" placeholder="e.g. TSG-CER-001" autocomplete="off" spellcheck="false" maxlength="64" required>
            <button class="button verify-search-button" type="submit">Verify certificate <span aria-hidden="true">→</span></button>
        </div>
        <p class="verify-search-hint">Search the official certificate register with the student's Academy ID, for example <strong>TSG-CER-001</strong>.</p>
    </form>
</section>

<section class="verify-result-section">
    <?php if ($state === 'empty'): ?>
        <div class="verify-stats-heading">
            <p class="verify-section-kicker">CLEAR · SIMPLE · ACCESSIBLE</p>
            <h2>Verification you can trust</h2>
        </div>
        <div class="verify-stats">
            <article><span class="verify-stat-number">01</span><strong>Instant</strong><span>Online verification</span></article>
            <article><span class="verify-stat-number">02</span><strong>Courses &amp; workshops</strong><span>Both verified here</span></article>
            <article><span class="verify-stat-number">03</span><strong>Free</strong><span>For everyone</span></article>
        </div>
    <?php elseif (in_array($state, ['valid', 'expired', 'revoked'], true) && $certificates !== []): ?>
        <?php if (count($certificates) > 1): ?>
            <p class="verify-multiple">This ID was issued for <strong><?= count($certificates) ?></strong> certificates by Tech SG Academy. Every one of them is listed below.</p>
        <?php endif; ?>
        <?php foreach ($certificates as $cardIndex => $certificate): ?>
            <?php
            $revoked = (string) ($certificate['certificate_status'] ?? '') === 'revoked';
            $lapsed = !$revoked && (string) ($certificate['expires_at'] ?? '') !== '' && (string) $certificate['expires_at'] <= gmdate('Y-m-d H:i:s');
            $certFormat = strtolower((string) ($certificate['file_ext'] ?? '')) === 'pdf' ? 'PDF' : 'JPG';
            $studentId = trim((string) ($certificate['student_id'] ?? ''));
            $cardClass = $revoked ? 'verify-card-revoked' : ($lapsed ? 'verify-card-expired' : 'verify-card-valid');
            $cardHeading = $revoked ? 'Certificate revoked' : ($lapsed ? 'Certificate expired' : 'Certificate verified');
            ?>
            <article class="verify-card <?= $cardClass ?>">
                <header class="verify-card-head">
                    <span class="verify-card-mark" aria-hidden="true"><?= $revoked ? '×' : ($lapsed ? '⏱' : '✓') ?></span>
                    <div>
                        <h2><?= $escape($cardHeading) ?></h2>
                        <p><?= $revoked ? 'This certificate was revoked by Tech SG Academy and is no longer valid.' : ($lapsed ? 'This certificate was genuine but its verification window has closed.' : 'This certificate is genuine and was issued by Tech SG Academy.') ?></p>
                    </div>
                </header>
                <div class="verify-card-body">
                    <p class="verify-awarded">Awarded to</p>
                    <h3 class="verify-name"><?= $escape($certificate['student_name']) ?></h3>
                    <p class="verify-course"><?= $escape($certificate['course_title']) ?></p>
                    <dl class="verify-grid">
                        <div><dt>Student name</dt><dd><?= $escape($certificate['student_name']) ?></dd></div>
                        <div><dt>Student ID</dt><dd class="verify-id"><?= $escape($studentId !== '' ? $studentId : $certificate['certificate_id']) ?></dd></div>
                        <div><dt>Course name</dt><dd><?= $escape($certificate['course_title']) ?></dd></div>
                        <div><dt>Certificate ID</dt><dd class="verify-id"><?= $escape($certificate['certificate_id']) ?></dd></div>
                        <div><dt>Issue date</dt><dd><?= $escape($formatDate((string) ($certificate['issued_at'] ?? ''))) ?></dd></div>
                        <div><dt>Course duration</dt><dd><?= $escape($certificate['duration_label'] ?: 'Not specified') ?></dd></div>
                        <div><dt>Level</dt><dd><?= $escape($certificate['level_label'] ?: 'All levels') ?></dd></div>
                        <div><dt>Valid until</dt><dd><?= $escape($formatDate((string) ($certificate['expires_at'] ?? ''))) ?></dd></div>
                        <div><dt>Certificate status</dt><dd><?= $revoked ? 'Revoked' : $escape(ucfirst((string) ($certificate['certificate_status'] ?? 'issued'))) ?></dd></div>
                        <div><dt>Verification status</dt><dd class="<?= ($lapsed || $revoked) ? 'verify-lapsed' : 'verify-ok' ?>"><?= $revoked ? '× Revoked' : ($lapsed ? '⏱ Expired' : '✓ Verified') ?></dd></div>
                    </dl>
                    <?php if (!empty($certificate['admin_remarks'])): ?>
                        <p class="verify-remarks"><strong>Academy remarks</strong><span><?= $escape($certificate['admin_remarks']) ?></span></p>
                    <?php endif; ?>
                    <?php if ($revoked): ?>
                        <p class="verify-help">Revoked on <?= $escape($formatDate((string) ($certificate['revoked_at'] ?? ''))) ?>. Reason: <?= $escape((string) ($certificate['revoked_reason'] ?? 'Contact the Academy for details.')) ?></p>
                    <?php elseif (!$lapsed): ?>
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
                                <a class="button verify-download" href="<?= $escape($baseUrl) ?>/certificate/download?cert_id=<?= $escape(rawurlencode((string) $certificate['certificate_id'])) ?>&course_id=<?= (int) ($certificate['course_id'] ?? 0) ?>">↓ Download <?= $escape($certFormat) ?> certificate</a>
                                <?php if ($cardIndex === 0): ?><a class="button button-secondary" href="<?= $escape($baseUrl) ?>/courses">Browse our courses</a><?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="verify-help">The certificate can no longer be downloaded or shared as valid proof. To renew the verification period, contact <a href="mailto:<?= $escape($contact) ?>"><?= $escape($contact) ?></a> with the certificate ID.</p>
                    <?php endif; ?>
                </div>
                <footer class="verify-card-foot">Record checked live from our register on <?= $escape(gmdate('j F Y')) ?>.</footer>
            </article>
        <?php endforeach; ?>
    <?php else: ?>
        <?php
        $headings = [
            'invalid' => ['Search is too long', 'Enter an Academy ID up to 64 characters.'],
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
                <p class="verify-help">Check the Academy ID spelling. Contact <a href="mailto:<?= $escape($contact) ?>"><?= $escape($contact) ?></a> if you need help.</p>
                <?php if ($query !== ''): ?><p class="verify-tried">Searched for: <strong><?= $escape($query) ?></strong></p><?php endif; ?>
            </div>
        </article>
    <?php endif; ?>
</section>

<section class="verify-how">
    <p class="verify-section-kicker">A SIMPLE, TRANSPARENT PROCESS</p>
    <h2>How verification works</h2>
    <p class="verify-how-intro">Every certificate is checked against the Academy's records, so anyone can confirm its details with confidence.</p>
    <div class="verify-how-grid">
        <article><span class="verify-step" aria-hidden="true">01</span><h3>Finish your course</h3><p>Complete every published lesson, then request your certificate from <strong>My courses</strong> in your student workspace.</p></article>
        <article><span class="verify-step" aria-hidden="true">02</span><h3>The academy issues it</h3><p>An admin reviews the request and publishes a signed certificate under your Academy ID, with its own validity period.</p></article>
        <article><span class="verify-step" aria-hidden="true">03</span><h3>Anyone can confirm it</h3><p>Share your Academy ID or the verification link — employers and institutions can check every certificate behind it here without an account.</p></article>
    </div>
</section>
<?php
$ctaKicker = 'EARN YOUR CERTIFICATE';
$ctaTitle = 'Your certificate is proof of real work';
$ctaDescription = 'Enroll in a practical course, finish every lesson and request your own verified Tech SG Academy certificate.';
$ctaPrimaryLabel = 'Browse courses';
$ctaPrimaryHref = $baseUrl . '/courses';
require BASE_PATH . '/app/Views/components/academy-cta.php';
?>
</div>
