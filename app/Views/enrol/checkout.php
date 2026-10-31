<?php

use App\Core\Security;

$state = isset($state) && ($state === 'otp' || $state === 'details') ? $state : 'details';
$course = is_array($course ?? null) ? $course : [];
$values = isset($values) && is_array($values) ? $values : [];
$resendSeconds = isset($resendSeconds) && is_int($resendSeconds) ? max(0, $resendSeconds) : 0;
$otpLifetime = max(1, (int) ($otpLifetime ?? 600));
$otpMinutes = max(1, (int) ceil($otpLifetime / 60));
// A sixty second code is not "1 minutes", and the line is read by a person in a hurry.
$otpExpiryLabel = $otpMinutes === 1 ? '1 minute' : $otpMinutes . ' minutes';
$error = isset($error) && is_string($error) && $error !== '' ? $error : null;
$message = isset($message) && is_string($message) && $message !== '' ? $message : null;
$baseUrl = isset($baseUrl) && is_string($baseUrl) ? rtrim($baseUrl, '/') : rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$value = static fn(string $name): string => Security::escape((string) ($values[$name] ?? ''));

$courseTitle = trim((string) ($course['title'] ?? ''));
$currency = trim((string) ($course['currency'] ?? '')) ?: 'PKR';
$prices = [];
if (($course['recorded_price'] ?? null) !== null) $prices[] = ['label' => 'Recorded only', 'price' => (float) $course['recorded_price']];
if (($course['live_price'] ?? null) !== null) $prices[] = ['label' => 'Live + recorded', 'price' => (float) $course['live_price']];

// The steps are printed once, above both halves, so a student always knows how far the process has
// left to run — the old flow dropped them into an empty dashboard with no way to tell that the
// course they came for was still two steps away.
$steps = [['label' => 'Your details'], ['label' => 'Verify email'], ['label' => 'Submit payment'], ['label' => 'Course access']];
$currentStep = $state === 'otp' ? 1 : 0;

$eyeIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.5 12S6 5.8 12 5.8 21.5 12 21.5 12 18 18.2 12 18.2 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/></svg>';
$eyeToggle = '<button class="pw-toggle" type="button" data-toggle-password aria-label="Show password" aria-pressed="false">' . $eyeIcon . '</button>';

// The "sending" panel is only honest straight after a code was actually requested. A wrong-code
// attempt re-renders this same card, and claiming an email is on its way then would be a lie.
$isSending = $state === 'otp' && $error === null;
?>
<section class="enrol-shell">
  <header class="enrol-topbar">
    <a class="auth-logo" href="<?= Security::escape($baseUrl) ?>/"><span class="brand-mark">TSG</span>Tech SG</a>
    <a class="enrol-back" href="<?= Security::escape($baseUrl) ?>/courses"><span aria-hidden="true">←</span> All courses</a>
  </header>

  <ol class="enrol-steps" aria-label="Enrollment progress">
    <?php foreach ($steps as $index => $step): ?>
      <li class="enrol-step<?= $index === $currentStep ? ' is-current' : '' ?><?= $index < $currentStep ? ' is-done' : '' ?>"<?= $index === $currentStep ? ' aria-current="step"' : '' ?>>
        <span class="enrol-step-mark" aria-hidden="true"><?= $index < $currentStep ? '✓' : (string) ($index + 1) ?></span><?= Security::escape($step['label']) ?>
      </li>
    <?php endforeach; ?>
  </ol>

  <div class="enrol-grid">
    <?php /* Everything except the form sits on the page itself: the choice, the money and the road
              ahead are information, not something to fill in, so they are not boxed. */ ?>
    <div class="enrol-intro">
      <p class="eyebrow">Enrollment · step <?= $currentStep + 1 ?> of <?= count($steps) ?></p>
      <h1 class="enrol-title"><?= $state === 'otp' ? 'Confirm your email' : 'Start your enrollment' ?></h1>
      <p class="enrol-lede"><?= $state === 'otp'
        ? 'Enter the six-digit code we emailed to the address you just used. The account stays locked until it matches.'
        : 'Create the account that your receipt, progress and certificate will be issued against, then confirm it by email. Payment proof comes after.' ?></p>

      <?php if ($courseTitle !== ''): ?>
        <dl class="enrol-summary">
          <div class="enrol-summary-main">
            <dt>Enrolling in</dt>
            <dd><?= Security::escape($courseTitle) ?></dd>
          </div>
          <?php foreach ($prices as $price): ?>
            <div><dt><?= Security::escape($price['label']) ?></dt><dd><?= Security::escape($currency) ?> <?= number_format($price['price'], 0) ?></dd></div>
          <?php endforeach; ?>
        </dl>
      <?php endif; ?>

      <section class="enrol-how-block">
        <h2>How enrollment works</h2>
        <p>Pay by bank transfer, and an administrator verifies the transaction before your course opens. Nothing is charged by the Academy website itself.</p>
        <ol class="enrol-how">
          <li><span aria-hidden="true">1</span><div><strong>Create the account</strong><small>It stays locked until your email is confirmed.</small></div></li>
          <li><span aria-hidden="true">2</span><div><strong>Enter the emailed code</strong><small>Six digits, valid for <?= Security::escape($otpExpiryLabel) ?>.</small></div></li>
          <li><span aria-hidden="true">3</span><div><strong>Send your payment details</strong><small>Transfer to the Academy account, then upload the proof.</small></div></li>
          <li><span aria-hidden="true">4</span><div><strong>Wait for approval</strong><small>You are emailed and notified here as soon as access is granted.</small></div></li>
        </ol>
      </section>
    </div>

    <div class="enrol-card">
      <header class="enrol-card-head">
        <h2><?= $state === 'otp' ? 'Enter your code' : 'Create your account' ?></h2>
        <p><?= $state === 'otp'
          ? 'One field, six digits. Use Resend if the mail is slow.'
          : 'Takes about a minute. You will not have to fill any of this in again.' ?></p>
      </header>

      <?php if ($error !== null): ?><p class="enrol-note enrol-note-error" role="alert"><?= Security::escape($error) ?></p><?php endif; ?>
      <?php if ($message !== null): ?><p class="enrol-note" role="status"><?= Security::escape($message) ?></p><?php endif; ?>

      <?php if ($state === 'details'): ?>
        <form method="post" action="<?= Security::escape($baseUrl) ?>/enrol" class="enrol-form" data-enrol-form>
          <input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>">
          <label class="field">Full name<span class="input-wrap"><input name="full_name" autocomplete="name" required minlength="2" maxlength="150" value="<?= $value('full_name') ?>"></span></label>
          <label class="field">Email<span class="input-wrap"><input name="email" type="email" autocomplete="email" required maxlength="190" value="<?= $value('email') ?>"></span></label>
          <label class="field">Password<span class="input-wrap"><input name="password" type="password" autocomplete="new-password" required minlength="12"><?= $eyeToggle ?></span></label>
          <label class="field">Confirm password<span class="input-wrap"><input name="password_confirmation" type="password" autocomplete="new-password" required minlength="12"><?= $eyeToggle ?></span></label>
          <p class="enrol-mail-hint">Use an address you can open right now — the verification code goes there, and the receipt and certificate are quoted against this account.</p>
          <button class="button auth-submit" type="submit"><span class="enrol-button-label">Create account and send code</span></button>
          <div class="enrol-sending" role="status" hidden>
            <span class="enrol-spinner" aria-hidden="true"></span>
            <p><strong>Sending your verification code…</strong><small>This can take a few seconds while the mail server responds. Keep this page open.</small></p>
          </div>
        </form>
        <p class="auth-links">Already enrolled? <a href="<?= Security::escape($baseUrl) ?>/login">Log in</a></p>
      <?php else: ?>
        <form method="post" action="<?= Security::escape($baseUrl) ?>/enrol/verify" class="enrol-form" data-enrol-form>
          <input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>">
          <input type="hidden" name="email" value="<?= $value('email') ?>">
          <input type="hidden" name="full_name" value="<?= $value('full_name') ?>">
          <?php if ($isSending): ?>
            <div class="enrol-sending enrol-sending-live" role="status">
              <span class="enrol-spinner" aria-hidden="true"></span>
              <p><strong>Emailing the code to <?= $value('email') ?></strong><small>Nothing in your inbox after a minute? Use Resend below — you do not have to wait for the code to expire first.</small></p>
            </div>
          <?php endif; ?>
          <label class="field">Six-digit code<span class="input-wrap"><input class="field-code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required placeholder="000000" value="<?= $value('code') ?>"></span></label>
          <button class="button auth-submit" type="submit"><span class="enrol-button-label">Verify and continue to payment</span></button>
          <?php /* One centred line for both escapes: a person who did not get the code is looking
                    for the way out, and two stacked links under the button read like an error. */ ?>
          <p class="otp-resend-row" data-otp-countdown="<?= $resendSeconds ?>"><span>Didn’t receive code?</span> <button class="text-button secondary-form" type="submit" formaction="<?= Security::escape($baseUrl) ?>/enrol/verify/resend" formnovalidate<?= $resendSeconds > 0 ? ' disabled' : '' ?>>Resend</button><span class="otp-resend-timer" aria-live="polite"></span><span class="otp-resend-divider" aria-hidden="true">|</span> <button class="text-button enrol-edit-email" type="submit" formaction="<?= Security::escape($baseUrl) ?>/enrol/back" formnovalidate>Wrong email? Go back</button></p>
        </form>
        <p class="auth-links">After this you go straight to the payment step for <strong><?= Security::escape($courseTitle) ?></strong>.</p>
      <?php endif; ?>

      <div class="enrol-card-foot">
        <span>Copyright © <?= date('Y') ?> Tech SG Studio (SMC-Pvt.) Ltd.</span>
        <a href="<?= Security::escape($baseUrl) ?>/privacy">Privacy Policy</a>
      </div>
    </div>
  </div>
</section>
