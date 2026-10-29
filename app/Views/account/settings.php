<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $value): string => Security::escape((string) $value);
$user = is_array($user ?? null) ? $user : [];
$role = (string) ($role ?? 'student');
$idLabel = (string) ($idLabel ?? 'Student ID');
$publicId = (string) ($publicId ?? '—');
$pendingEmail = isset($pendingEmail) && is_string($pendingEmail) && $pendingEmail !== '' ? $pendingEmail : null;
$account = is_array($account ?? null) ? $account : [];
$minPassword = (int) ($minPassword ?? 12);
$otpMinutes = (int) ($otpMinutes ?? 10);
$message = $message ?? null;
$error = $error ?? null;
$moment = static function (?string $value): string {
    $value = trim((string) $value);
    if ($value === '') return '—';
    $timestamp = strtotime($value . ' UTC');
    return $timestamp === false ? '—' : date('j M Y', $timestamp);
};
$csrf = Security::csrfToken();
?>
<section class="dashboard-page">
    <div class="dashboard-topbar"><button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
        <div class="dashboard-breadcrumb"><a href="<?= $escape($baseUrl) ?>/dashboard">Workspace</a><i>/</i><strong>Account settings</strong></div>
    </div>

    <header class="dashboard-heading">
        <div>
            <p class="eyebrow"><?= $role === 'admin' ? 'ADMIN ACCOUNT' : 'MY ACCOUNT' ?></p>
            <h1>Account settings</h1>
            <p>Update the name, email address and password you sign in with. Your <?= $escape($idLabel) ?> is permanent and stays the same so your records always point to you.</p>
        </div>
    </header>

    <?php if ($message): ?><div class="admin-alert admin-alert-success" role="status"><?= $escape($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="admin-alert admin-alert-error" role="alert"><?= $escape($error) ?></div><?php endif; ?>

    <section class="admin-card">
        <div class="admin-card-header">
            <div class="admin-card-title-group">
                <div class="admin-card-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </div>
                <div class="admin-card-titles">
                    <h2>Your identity</h2>
                    <p>The details on file for this account. <?= $escape($idLabel) ?> cannot be changed.</p>
                </div>
            </div>
            <span class="count-pill"><?= $role === 'admin' ? 'Administrator' : 'Student' ?></span>
        </div>
        <div class="admin-grid-2">
            <div class="admin-field"><label class="admin-label" for="account-name-readonly">Name</label><input class="admin-input" id="account-name-readonly" type="text" value="<?= $escape($user['name'] ?? '') ?>" readonly></div>
            <div class="admin-field"><label class="admin-label" for="account-id-readonly"><?= $escape($idLabel) ?> <span class="admin-hint">read-only</span></label><input class="admin-input" id="account-id-readonly" type="text" value="<?= $escape($publicId) ?>" readonly></div>
            <div class="admin-field"><label class="admin-label" for="account-email-readonly">Email address</label><input class="admin-input" id="account-email-readonly" type="email" value="<?= $escape($user['email'] ?? '') ?>" readonly></div>
            <div class="admin-field"><label class="admin-label" for="account-since-readonly">Account history</label><input class="admin-input" id="account-since-readonly" type="text" value="Created <?= $escape($moment($account['created_at'] ?? null)) ?> · Last sign-in <?= $escape($moment($account['last_login_at'] ?? null)) ?>" readonly></div>
        </div>
    </section>

    <section class="admin-card">
        <div class="admin-card-header">
            <div class="admin-card-title-group">
                <div class="admin-card-icon indigo">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
                </div>
                <div class="admin-card-titles">
                    <h2>Display name</h2>
                    <p>This name appears on your certificates, submissions and class records.</p>
                </div>
            </div>
        </div>
        <form method="post" action="<?= $escape($baseUrl) ?>/account/name" class="admin-form">
            <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
            <div class="admin-field"><label class="admin-label" for="full_name">Full name <span class="req">*</span></label><input class="admin-input" id="full_name" name="full_name" type="text" required minlength="2" maxlength="150" value="<?= $escape($user['name'] ?? '') ?>" placeholder="Your name as you want it to appear"><span class="admin-hint">Between 2 and 150 characters.</span></div>
            <div class="form-actions"><button class="button" type="submit">Save name</button></div>
        </form>
    </section>

    <section class="admin-card">
        <div class="admin-card-header">
            <div class="admin-card-title-group">
                <div class="admin-card-icon emerald">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 6 10-6"/></svg>
                </div>
                <div class="admin-card-titles">
                    <h2>Email address</h2>
                    <p>You sign in with this address and every Academy notification is sent to it.</p>
                </div>
            </div>
            <span class="count-pill"><?= $escape($user['email'] ?? '') ?></span>
        </div>
        <?php if ($pendingEmail): ?>
            <div class="dashboard-note"><strong>Awaiting confirmation.</strong><span>We sent a six-digit code to <strong><?= $escape($pendingEmail) ?></strong>. Enter it below to finish the change. Your current address stays active until you do, and the code expires after <?= (int) $otpMinutes ?> minutes.</span></div>
            <form method="post" action="<?= $escape($baseUrl) ?>/account/email/confirm" class="admin-form">
                <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
                <div class="admin-field"><label class="admin-label" for="otp_code">Confirmation code <span class="req">*</span></label><input class="admin-input" id="otp_code" name="otp_code" type="text" inputmode="numeric" pattern="\d{6}" minlength="6" maxlength="6" required autocomplete="one-time-code" placeholder="000000"><span class="admin-hint">Enter the code exactly as it appears in the email.</span></div>
                <div class="form-actions"><button class="button" type="submit">Confirm new email address</button></div>
            </form>
        <?php endif; ?>
        <form method="post" action="<?= $escape($baseUrl) ?>/account/email/request" class="admin-form">
            <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
            <div class="admin-grid-2">
                <div class="admin-field"><label class="admin-label" for="new_email">New email address <span class="req">*</span></label><input class="admin-input" id="new_email" name="new_email" type="email" maxlength="190" required autocomplete="email" placeholder="you@example.com"></div>
                <div class="admin-field"><label class="admin-label" for="email_current_password">Current password <span class="req">*</span></label><input class="admin-input" id="email_current_password" name="current_password" type="password" required autocomplete="current-password" placeholder="Confirm it is really you"></div>
            </div>
            <span class="admin-hint">We send a confirmation code to the new address first, so a typo can never cost you access to your account. Sending a new code replaces the previous one.</span>
            <div class="form-actions"><button class="button<?= $pendingEmail !== null ? ' button-secondary' : '' ?>" type="submit"><?= $pendingEmail !== null ? 'Send a new code' : 'Send confirmation code' ?></button></div>
        </form>
    </section>

    <section class="admin-card">
        <div class="admin-card-header">
            <div class="admin-card-title-group">
                <div class="admin-card-icon amber">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                </div>
                <div class="admin-card-titles">
                    <h2>Password</h2>
                    <p>Choose a password only you know. It must be at least <?= (int) $minPassword ?> characters.</p>
                </div>
            </div>
        </div>
        <form method="post" action="<?= $escape($baseUrl) ?>/account/password" class="admin-form">
            <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
            <div class="admin-grid-2">
                <div class="admin-field"><label class="admin-label" for="current_password">Current password <span class="req">*</span></label><input class="admin-input" id="current_password" name="current_password" type="password" required autocomplete="current-password"></div>
                <div class="admin-field"><label class="admin-label" for="new_password">New password <span class="req">*</span></label><input class="admin-input" id="new_password" name="new_password" type="password" required minlength="<?= (int) $minPassword ?>" maxlength="4096" autocomplete="new-password"></div>
            </div>
            <div class="admin-field"><label class="admin-label" for="confirm_password">Repeat new password <span class="req">*</span></label><input class="admin-input" id="confirm_password" name="confirm_password" type="password" required minlength="<?= (int) $minPassword ?>" maxlength="4096" autocomplete="new-password"></div>
            <div class="form-actions"><button class="button" type="submit">Change password</button></div>
        </form>
    </section>

    <div class="dashboard-note settings-scope-note"><strong>Why is my <?= strtolower($escape($idLabel)) ?> locked?</strong><span>The ID is the reference your enrolment, attendance, submissions, grades and certificate are all filed under, so it is issued once and never edited. If a detail here looks wrong, contact the Academy team and we will correct it.</span></div>
</section>
