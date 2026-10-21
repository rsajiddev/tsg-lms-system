<?php
use App\Core\Security;
$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$value = static fn(string $name): string => Security::escape((string) ($values[$name] ?? ''));
?>
<section class="auth-card">
  <p class="eyebrow">TSG ACADEMY LMS</p>
  <h1><?= Security::escape($title) ?></h1>
  <?php if ($message): ?><p class="form-message" role="status"><?= Security::escape($message) ?></p><?php endif; ?>
  <?php if ($mode === 'error'): ?><p>Return to the form and try again.</p>
  <?php elseif ($mode === 'register'): ?>
    <form method="post" action="<?= Security::escape($baseUrl) ?>/register">
      <input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>">
      <label>Full name<input name="full_name" autocomplete="name" required minlength="2" maxlength="150" value="<?= $value('full_name') ?>"></label>
      <label>Email<input name="email" type="email" autocomplete="email" required maxlength="190" value="<?= $value('email') ?>"></label>
      <label>Password<input name="password" type="password" autocomplete="new-password" required minlength="12"></label>
      <label>Confirm password<input name="password_confirmation" type="password" autocomplete="new-password" required minlength="12"></label>
      <button class="button" type="submit">Create student account</button>
    </form>
    <p class="auth-links"><a href="<?= Security::escape($baseUrl) ?>/verify-email">Verify an existing account</a> · <a href="<?= Security::escape($baseUrl) ?>/login">Sign in</a></p>
  <?php elseif ($mode === 'verify'): ?>
    <form method="post" action="<?= Security::escape($baseUrl) ?>/verify-email">
      <input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>">
      <label>Email<input name="email" type="email" autocomplete="email" required value="<?= $value('email') ?>"></label>
      <label>Six-digit code<input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required value="<?= $value('code') ?>"></label>
      <button class="button" type="submit">Verify email</button>
    </form>
    <form method="post" action="<?= Security::escape($baseUrl) ?>/verify-email/resend" class="secondary-form">
      <input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>">
      <input type="hidden" name="email" value="<?= $value('email') ?>">
      <button class="text-button" type="submit">Resend verification code</button>
    </form>
  <?php elseif ($mode === 'login'): ?>
    <form method="post" action="<?= Security::escape($baseUrl) ?>/login">
      <input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>">
      <label>Email<input name="email" type="email" autocomplete="username" required value="<?= $value('email') ?>"></label>
      <label>Password<input name="password" type="password" autocomplete="current-password" required></label>
      <button class="button" type="submit">Sign in</button>
    </form>
    <p class="auth-links"><a href="<?= Security::escape($baseUrl) ?>/forgot-password">Forgot password?</a> · <a href="<?= Security::escape($baseUrl) ?>/register">Create student account</a></p>
  <?php elseif ($mode === 'forgot'): ?>
    <form method="post" action="<?= Security::escape($baseUrl) ?>/forgot-password">
      <input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>">
      <label>Email<input name="email" type="email" autocomplete="email" required value="<?= $value('email') ?>"></label>
      <button class="button" type="submit">Send reset code</button>
    </form>
    <p class="auth-links"><a href="<?= Security::escape($baseUrl) ?>/reset-password">I have a reset code</a></p>
  <?php elseif ($mode === 'reset'): ?>
    <form method="post" action="<?= Security::escape($baseUrl) ?>/reset-password">
      <input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>">
      <label>Email<input name="email" type="email" autocomplete="email" required value="<?= $value('email') ?>"></label>
      <label>Six-digit code<input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required value="<?= $value('code') ?>"></label>
      <label>New password<input name="password" type="password" autocomplete="new-password" required minlength="12"></label>
      <label>Confirm new password<input name="password_confirmation" type="password" autocomplete="new-password" required minlength="12"></label>
      <button class="button" type="submit">Update password</button>
    </form>
  <?php endif; ?>
</section>
