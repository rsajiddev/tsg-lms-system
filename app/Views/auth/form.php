<?php

use App\Core\Security;

$title = isset($title) && is_string($title) ? $title : 'TSG Academy';
$mode = isset($mode) && is_string($mode) ? $mode : 'error';
$message = isset($message) && is_string($message) ? $message : null;
$values = isset($values) && is_array($values) ? $values : [];
$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$value = static fn(string $name): string => Security::escape((string) ($values[$name] ?? ''));

$copy = match ($mode) {
    'register' => ['eyebrow' => 'JOIN TECH SG ACADEMY', 'heading' => 'Create your account', 'sub' => 'Start learning practical skills in minutes — registration is free.'],
    'verify' => ['eyebrow' => 'ALMOST THERE', 'heading' => 'Verify your email', 'sub' => 'Enter the six-digit code we sent to your inbox.'],
    'forgot' => ['eyebrow' => 'ACCOUNT RECOVERY', 'heading' => 'Reset your password', 'sub' => 'We will email you a secure reset code.'],
    'reset' => ['eyebrow' => 'ACCOUNT RECOVERY', 'heading' => 'Choose a new password', 'sub' => 'Set a new password to secure your account.'],
    default => ['eyebrow' => 'WELCOME BACK', 'heading' => 'Sign in to continue', 'sub' => 'Access your Tech SG learning workspace.'],
};

$icons = [
    'mail' => '<svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><rect x="3" y="5.5" width="18" height="13" rx="3"/><path d="M4 7.5l8 5.6 8-5.6"/></svg>',
    'lock' => '<svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><rect x="4.5" y="10" width="15" height="10.5" rx="3"/><path d="M8 10V7.6a4 4 0 0 1 8 0V10"/></svg>',
    'user' => '<svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="8.2" r="3.6"/><path d="M5 20a7 7 0 0 1 14 0"/></svg>',
    'code' => '<svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M9 4L7 20M17 4l-2 16M4.5 9h16M3.5 15h16"/></svg>',
    'eye' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.5 12S6 5.8 12 5.8 21.5 12 21.5 12 18 18.2 12 18.2 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/></svg>',
    'shield' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l7 3v5c0 4.5-3 7.6-7 9-4-1.4-7-4.5-7-9V6l7-3z"/><path d="M9.5 11.7l1.9 1.9 3.6-3.8"/></svg>',
    'sun' => '<svg class="icon-sun" viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4.2"/><path d="M12 2.5v2.4M12 19.1v2.4M2.5 12h2.4M19.1 12h2.4M5 5l1.7 1.7M17.3 17.3L19 19M19 5l-1.7 1.7M6.7 17.3L5 19"/></svg>',
    'moon' => '<svg class="icon-moon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.4 14.2A8.3 8.3 0 0 1 9.8 3.6a8.3 8.3 0 1 0 10.6 10.6z"/></svg>',
];

$weekDays = [];
$sunday = strtotime('today') - ((int) date('w')) * 86400;
$todayKey = date('Y-m-d');
for ($dayOffset = 0; $dayOffset < 7; $dayOffset++) {
    $stamp = $sunday + $dayOffset * 86400;
    $weekDays[] = ['label' => date('D', $stamp), 'num' => date('j', $stamp), 'isToday' => date('Y-m-d', $stamp) === $todayKey];
}

$eyeToggle = '<button class="pw-toggle" type="button" data-toggle-password aria-label="Show password" aria-pressed="false">' . $icons['eye'] . '</button>';
?>
<section class="auth-shell">
 <div class="auth-panel" data-auth-panel>
  <section class="auth-form-side">
    <div class="auth-top">
        <a class="auth-logo" href="<?= Security::escape($baseUrl) ?>/"><span class="brand-mark">TSG</span>Tech SG</a>
        <button class="theme-toggle" type="button" data-theme-toggle aria-label="Toggle appearance" aria-pressed="false"><?= $icons['sun'] . $icons['moon'] ?></button>
    </div>
    <header class="auth-heading">
      <p class="eyebrow"><?= Security::escape($copy['eyebrow']) ?></p>
      <h1><?= Security::escape($copy['heading']) ?></h1>
      <p class="auth-sub"><?= Security::escape($copy['sub']) ?></p>
    </header>
    <?php if ($message): ?><p class="form-message" role="status"><?= Security::escape($message) ?></p><?php endif; ?>
    <?php if ($mode === 'error'): ?><p>Return to the form and try again.</p>
    <?php elseif ($mode === 'register'): ?>
      <form method="post" action="<?= Security::escape($baseUrl) ?>/register">
        <input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>">
        <label class="field">Full name<span class="input-wrap"><?= $icons['user'] ?><input name="full_name" autocomplete="name" required minlength="2" maxlength="150" value="<?= $value('full_name') ?>"></span></label>
        <label class="field">Email<span class="input-wrap"><?= $icons['mail'] ?><input name="email" type="email" autocomplete="email" required maxlength="190" value="<?= $value('email') ?>"></span></label>
        <label class="field">Password<span class="input-wrap"><?= $icons['lock'] ?><input name="password" type="password" autocomplete="new-password" required minlength="12"><?= $eyeToggle ?></span></label>
        <label class="field">Confirm password<span class="input-wrap"><?= $icons['lock'] ?><input name="password_confirmation" type="password" autocomplete="new-password" required minlength="12"><?= $eyeToggle ?></span></label>
        <button class="button auth-submit" type="submit">Create student account</button>
      </form>
      <p class="auth-links">Already have an account? <a href="<?= Security::escape($baseUrl) ?>/login">Sign in</a> · <a href="<?= Security::escape($baseUrl) ?>/verify-email">Verify an existing account</a></p>
    <?php elseif ($mode === 'verify'): ?>
      <form method="post" action="<?= Security::escape($baseUrl) ?>/verify-email">
        <input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>">
        <label class="field">Email<span class="input-wrap"><?= $icons['mail'] ?><input name="email" type="email" autocomplete="email" required value="<?= $value('email') ?>"></span></label>
        <label class="field">Six-digit code<span class="input-wrap"><?= $icons['code'] ?><input class="field-code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required value="<?= $value('code') ?>"></span></label>
        <button class="button auth-submit" type="submit">Verify email</button>
      </form>
      <form method="post" action="<?= Security::escape($baseUrl) ?>/verify-email/resend" class="secondary-form">
        <input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>">
        <input type="hidden" name="email" value="<?= $value('email') ?>">
        <button class="text-button" type="submit">Resend verification code</button>
      </form>
      <p class="auth-links"><a href="<?= Security::escape($baseUrl) ?>/login">Back to sign in</a></p>
    <?php elseif ($mode === 'login'): ?>
      <form method="post" action="<?= Security::escape($baseUrl) ?>/login">
        <input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>">
        <label class="field">Email<span class="input-wrap"><?= $icons['mail'] ?><input name="email" type="email" autocomplete="username" required value="<?= $value('email') ?>"></span></label>
        <label class="field">Password<span class="input-wrap"><?= $icons['lock'] ?><input name="password" type="password" autocomplete="current-password" required><?= $eyeToggle ?></span></label>
        <div class="auth-row">
          <label class="remember"><input type="checkbox" value="1">Remember me</label>
          <a href="<?= Security::escape($baseUrl) ?>/forgot-password">Forgot password?</a>
        </div>
        <button class="button auth-submit" type="submit">Sign in</button>
      </form>
      <p class="auth-links">New to the Academy? <a href="<?= Security::escape($baseUrl) ?>/register">Create student account</a></p>
    <?php elseif ($mode === 'forgot'): ?>
      <form method="post" action="<?= Security::escape($baseUrl) ?>/forgot-password">
        <input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>">
        <label class="field">Email<span class="input-wrap"><?= $icons['mail'] ?><input name="email" type="email" autocomplete="email" required value="<?= $value('email') ?>"></span></label>
        <button class="button auth-submit" type="submit">Send reset code</button>
      </form>
      <p class="auth-links"><a href="<?= Security::escape($baseUrl) ?>/reset-password">I have a reset code</a> · <a href="<?= Security::escape($baseUrl) ?>/login">Back to sign in</a></p>
    <?php elseif ($mode === 'reset'): ?>
      <form method="post" action="<?= Security::escape($baseUrl) ?>/reset-password">
        <input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>">
        <label class="field">Email<span class="input-wrap"><?= $icons['mail'] ?><input name="email" type="email" autocomplete="email" required value="<?= $value('email') ?>"></span></label>
        <label class="field">Six-digit code<span class="input-wrap"><?= $icons['code'] ?><input class="field-code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required value="<?= $value('code') ?>"></span></label>
        <label class="field">New password<span class="input-wrap"><?= $icons['lock'] ?><input name="password" type="password" autocomplete="new-password" required minlength="12"><?= $eyeToggle ?></span></label>
        <label class="field">Confirm new password<span class="input-wrap"><?= $icons['lock'] ?><input name="password_confirmation" type="password" autocomplete="new-password" required minlength="12"><?= $eyeToggle ?></span></label>
        <button class="button auth-submit" type="submit">Update password</button>
      </form>
      <p class="auth-links"><a href="<?= Security::escape($baseUrl) ?>/login">Back to sign in</a></p>
    <?php endif; ?>
    <div class="auth-foot">
      <span>© <?= date('Y') ?> Tech SG Studio</span>
      <span class="secure-note"><?= $icons['shield'] ?>Secure access</span>
    </div>
  </section>
  <aside class="auth-visual">
    <div class="visual-top">
      <a class="auth-logo auth-logo-light" href="<?= Security::escape($baseUrl) ?>/" tabindex="-1"><span class="brand-mark">TSG</span>Tech SG</a>
      <button class="theme-toggle" type="button" data-theme-toggle aria-label="Toggle appearance" aria-pressed="false"><?= $icons['sun'] . $icons['moon'] ?></button>
    </div>
    <div class="visual-intro" aria-hidden="true">
      <p class="eyebrow"><?= Security::escape($copy['eyebrow']) ?></p>
      <h2><?= Security::escape($copy['heading']) ?></h2>
      <p class="visual-sub"><?= Security::escape($copy['sub']) ?></p>
    </div>
    <img class="auth-photo" src="https://res.cloudinary.com/d3pmuxrv/image/upload/v1792608403/tsg-lms-login.jpg" alt="" loading="eager" fetchpriority="high">
    <span class="glass-card glass-task" aria-hidden="true"><span class="task-dot"></span><span><strong>Live class · Web Development</strong><small>09:30 – 10:00 AM</small></span></span>
    <span class="glass-card week-strip" aria-hidden="true"><?php foreach ($weekDays as $day): ?><span class="wd<?= $day['isToday'] ? ' today' : '' ?>"><?= Security::escape($day['label']) ?><strong><?= Security::escape($day['num']) ?></strong></span><?php endforeach; ?></span>
    <span class="glass-card glass-avatars" aria-hidden="true"><span class="av av-1">AM</span><span class="av av-2">JL</span><span class="av av-3">RK</span></span>
    <span class="glass-card glass-due" aria-hidden="true"><strong>Frontend assignment</strong><small>Due in 1 hour</small><span class="av-row"><span class="av av-1">AM</span><span class="av av-2">JL</span><span class="av av-3">RK</span></span></span>
  </aside>
 </div>
</section>
