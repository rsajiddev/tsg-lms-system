<?php

use App\Core\Security;

$mode = isset($mode) && is_string($mode) ? $mode : 'error';
$message = isset($message) && is_string($message) ? $message : null;
$values = isset($values) && is_array($values) ? $values : [];
$resendSeconds = isset($resendSeconds) && is_int($resendSeconds) ? max(0, $resendSeconds) : 0;
$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$value = static fn(string $name): string => Security::escape((string) ($values[$name] ?? ''));

$copy = match ($mode) {
    'register' => ['heading' => 'Create your account', 'sub' => 'Start learning practical skills in minutes — registration is free.'],
    'verify' => ['heading' => 'Verify your email', 'sub' => 'Enter the six-digit code we sent to your inbox.'],
    'forgot' => ['heading' => 'Reset your password', 'sub' => 'We will email you a secure reset code.'],
    'reset' => ['heading' => 'Choose a new password', 'sub' => 'Set a new password to secure your account.'],
    default => ['heading' => 'Welcome back', 'sub' => 'Enter your email and password to access your account.'],
};

$eyeIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.5 12S6 5.8 12 5.8 21.5 12 21.5 12 18 18.2 12 18.2 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/></svg>';
$eyeToggle = '<button class="pw-toggle" type="button" data-toggle-password aria-label="Show password" aria-pressed="false">' . $eyeIcon . '</button>';
?>
<section class="auth-shell">
 <div class="auth-panel">
  <section class="auth-form-side">
    <div class="auth-top">
        <a class="auth-logo" href="<?= Security::escape($baseUrl) ?>/"><span class="brand-mark">TSG</span>Tech SG</a>
    </div>
    <header class="auth-heading">
      <h1><?= Security::escape($copy['heading']) ?></h1>
      <p class="auth-sub"><?= Security::escape($copy['sub']) ?></p>
    </header>
    <?php if ($message): ?><p class="form-message" role="status"><?= Security::escape($message) ?></p><?php endif; ?>
    <?php if ($mode === 'error'): ?><p>Return to the form and try again.</p>
    <?php elseif ($mode === 'register'): ?>
      <form method="post" action="<?= Security::escape($baseUrl) ?>/register">
        <input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>">
        <label class="field">Full name<span class="input-wrap"><input name="full_name" autocomplete="name" required minlength="2" maxlength="150" value="<?= $value('full_name') ?>"></span></label>
        <label class="field">Email<span class="input-wrap"><input name="email" type="email" autocomplete="email" required maxlength="190" value="<?= $value('email') ?>"></span></label>
        <label class="field">Password<span class="input-wrap"><input name="password" type="password" autocomplete="new-password" required minlength="12"><?= $eyeToggle ?></span></label>
        <label class="field">Confirm password<span class="input-wrap"><input name="password_confirmation" type="password" autocomplete="new-password" required minlength="12"><?= $eyeToggle ?></span></label>
        <button class="button auth-submit" type="submit">Create student account</button>
      </form>
      <p class="auth-links">Already have an account? <a href="<?= Security::escape($baseUrl) ?>/login">Log in</a> · <a href="<?= Security::escape($baseUrl) ?>/verify-email?email=<?= rawurlencode((string)($values['email'] ?? '')) ?>">Verify your email</a></p>
    <?php elseif ($mode === 'verify'): ?>
      <form method="post" action="<?= Security::escape($baseUrl) ?>/verify-email">
        <input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>">
        <label class="field">Email<span class="input-wrap"><input name="email" type="email" autocomplete="email" required value="<?= $value('email') ?>"></span></label>
        <label class="field">Six-digit code<span class="input-wrap"><input class="field-code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required value="<?= $value('code') ?>"></span></label>
        <button class="button auth-submit" type="submit">Verify email</button>
        <p class="otp-resend-row" data-otp-countdown="<?= $resendSeconds ?>"><span>Didn’t receive code?</span> <button class="text-button secondary-form" type="submit" formaction="<?= Security::escape($baseUrl) ?>/verify-email/resend" formnovalidate disabled>Resend</button><span class="otp-resend-timer" aria-live="polite"></span></p>
      </form>
      <p class="auth-links"><a href="<?= Security::escape($baseUrl) ?>/login">Back to log in</a></p>
    <?php elseif ($mode === 'login'): ?>
      <form method="post" action="<?= Security::escape($baseUrl) ?>/login">
        <input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>">
        <label class="field">Email<span class="input-wrap"><input name="email" type="email" autocomplete="username" required value="<?= $value('email') ?>"></span></label>
        <label class="field">Password<span class="input-wrap"><input name="password" type="password" autocomplete="current-password" required><?= $eyeToggle ?></span></label>
        <div class="auth-row">
          <label class="remember"><input type="checkbox" value="1">Remember me</label>
          <a href="<?= Security::escape($baseUrl) ?>/forgot-password">Forgot password?</a>
        </div>
        <button class="button auth-submit" type="submit">Log in</button>
      </form>
      <p class="auth-links">New here? <a href="<?= Security::escape($baseUrl) ?>/register">Create an account</a> · <a href="<?= Security::escape($baseUrl) ?>/verify-email">Verify your email</a></p>
    <?php elseif ($mode === 'forgot'): ?>
      <form method="post" action="<?= Security::escape($baseUrl) ?>/forgot-password">
        <input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>">
        <label class="field">Email<span class="input-wrap"><input name="email" type="email" autocomplete="email" required value="<?= $value('email') ?>"></span></label>
        <button class="button auth-submit" type="submit">Send reset code</button>
      </form>
      <p class="auth-links"><a href="<?= Security::escape($baseUrl) ?>/reset-password">I have a reset code</a> · <a href="<?= Security::escape($baseUrl) ?>/login">Back to log in</a></p>
    <?php elseif ($mode === 'reset'): ?>
      <form method="post" action="<?= Security::escape($baseUrl) ?>/reset-password">
        <input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>">
        <label class="field">Email<span class="input-wrap"><input name="email" type="email" autocomplete="email" required value="<?= $value('email') ?>"></span></label>
        <label class="field">Six-digit code<span class="input-wrap"><input class="field-code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required value="<?= $value('code') ?>"></span></label>
        <label class="field">New password<span class="input-wrap"><input name="password" type="password" autocomplete="new-password" required minlength="12"><?= $eyeToggle ?></span></label>
        <label class="field">Confirm new password<span class="input-wrap"><input name="password_confirmation" type="password" autocomplete="new-password" required minlength="12"><?= $eyeToggle ?></span></label>
        <button class="button auth-submit" type="submit">Update password</button>
      </form>
      <p class="auth-links"><a href="<?= Security::escape($baseUrl) ?>/login">Back to log in</a></p>
    <?php endif; ?>
    <div class="auth-foot">
      <span>Copyright © <?= date('Y') ?> Tech SG Studio (SMC-Pvt.) Ltd.</span>
      <a href="<?= Security::escape($baseUrl) ?>/privacy">Privacy Policy</a>
    </div>
  </section>
  <aside class="auth-visual">
    <a class="auth-logo auth-logo-light visual-brand" href="<?= Security::escape($baseUrl) ?>/" tabindex="-1"><span class="brand-mark">TSG</span>Tech SG</a>
    <div class="visual-copy">
      <h2>Learn skills that move your career forward.</h2>
      <p>Live and recorded IT training with real projects, assignments and verified certificates — from Tech SG Studio (SMC-Pvt.) Ltd.</p>
    </div>
    <div class="visual-mock" aria-hidden="true">
      <span class="mock-title"><i></i>Student dashboard</span>
      <div class="mock-stats">
        <div class="mock-stat"><small>Course progress</small><strong>72%</strong></div>
        <div class="mock-stat"><small>Live classes</small><strong>12</strong></div>
      </div>
      <div class="mock-chart"><i style="--h:34%"></i><i style="--h:58%"></i><i style="--h:42%"></i><i style="--h:70%"></i><i style="--h:52%"></i><i style="--h:88%"></i><i style="--h:64%"></i></div>
      <span class="mock-note"><i></i>Next live class · Web Development</span>
    </div>
  </aside>
 </div>
</section>
