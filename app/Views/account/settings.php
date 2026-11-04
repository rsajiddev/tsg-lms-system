<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $value): string => Security::escape((string) $value);
$user = is_array($user ?? null) ? $user : [];
$role = (string) ($role ?? 'student');
$idLabel = (string) ($idLabel ?? 'Student ID');
$publicId = (string) ($publicId ?? '—');
$scheme = is_array($scheme ?? null) ? $scheme : [];
$schemePrefix = (string) ($scheme['prefix'] ?? 'TSG-CER');
$schemeStart = (int) ($scheme['start'] ?? 1);
$schemeExample = (string) ($scheme['example'] ?? 'TSG-CER-001');
$schemeStudents = (int) ($scheme['students'] ?? 0);
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
// Same show/hide control the sign-in and enrolment screens use, so a password typed here can be
// checked before it is submitted. main.js looks for the input inside the wrapper around it.
$eyeIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.5 12S6 5.8 12 5.8 21.5 12 21.5 12 18 18.2 12 18.2 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/></svg>';
$eyeToggle = '<button class="pw-toggle" type="button" data-toggle-password aria-label="Show password" aria-pressed="false">' . $eyeIcon . '</button>';
?>
<section class="dashboard-page account-settings-page">
    <div class="dashboard-topbar"><button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
        <div class="dashboard-breadcrumb"><a href="<?= $escape($baseUrl) ?>/dashboard">Workspace</a><i>/</i><strong>Account settings</strong></div>
    </div>

    <header class="dashboard-heading">
        <div>
            <p class="eyebrow"><?= $role === 'admin' ? 'ADMIN ACCOUNT' : 'MY ACCOUNT' ?></p>
            <h1>Account settings</h1>
            <?php if ($role === 'admin'): ?>
                <p>Update the name, email address and password you sign in with. Your own Admin ID and the ID scheme every student is issued are set on this page, because those are the numbers printed on Academy documents.</p>
            <?php else: ?>
                <p>Update the name, email address and password you sign in with. Your <?= $escape($idLabel) ?> is issued by the Academy and printed on your certificate, so it stays the same and your records always point to you.</p>
            <?php endif; ?>
        </div>
    </header>

    <?php \App\Core\View::toast('success', $message ?? null); ?>
    <?php \App\Core\View::toast('error', $error ?? null); ?>

    <section class="admin-card" id="account-identity-card">
        <div class="admin-card-header">
            <div class="admin-card-title-group">
                <div class="admin-card-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </div>
                <div class="admin-card-titles">
                    <h2>Your identity</h2>
                    <?php if ($role === 'admin'): ?>
                        <p>The details on file for this account. You set your own <?= $escape($idLabel) ?> - it is confirmed before it changes.</p>
                    <?php else: ?>
                        <p>The details on file for this account. <?= $escape($idLabel) ?> is set by the Academy team.</p>
                    <?php endif; ?>
                </div>
            </div>
            <span class="count-pill"><?= $role === 'admin' ? 'Administrator' : 'Student' ?></span>
        </div>
        <div class="admin-grid-2">
            <div class="admin-field"><label class="admin-label" for="account-name-readonly">Name</label><input class="admin-input" id="account-name-readonly" type="text" value="<?= $escape($user['name'] ?? '') ?>" readonly></div>
            <?php if ($role === 'admin'): ?>
                <div class="admin-field"><label class="admin-label" for="account-id-input"><?= $escape($idLabel) ?> <span class="admin-hint">editable</span></label><input class="admin-input admin-id-input" id="account-id-input" type="text" name="account_reference" form="admin-id-form" value="<?= $escape($publicId) ?>" maxlength="64" autocomplete="off" spellcheck="false" pattern="[A-Za-z][A-Za-z0-9-]{2,63}" required><span class="admin-hint">Letters, digits and single hyphens, starting with a letter. Every ID has to be unique.</span></div>
            <?php else: ?>
                <div class="admin-field"><label class="admin-label" for="account-id-readonly"><?= $escape($idLabel) ?> <span class="admin-hint">read-only</span></label><input class="admin-input" id="account-id-readonly" type="text" value="<?= $escape($publicId) ?>" readonly></div>
            <?php endif; ?>
            <div class="admin-field"><label class="admin-label" for="account-email-readonly">Email address</label><input class="admin-input" id="account-email-readonly" type="email" value="<?= $escape($user['email'] ?? '') ?>" readonly></div>
            <div class="admin-field"><label class="admin-label" for="account-since-readonly">Account history</label><input class="admin-input" id="account-since-readonly" type="text" value="Created <?= $escape($moment($account['created_at'] ?? null)) ?> · Last sign-in <?= $escape($moment($account['last_login_at'] ?? null)) ?>" readonly></div>
        </div>
        <?php if ($role === 'admin'): ?>
            <div class="form-actions"><a class="button button-secondary" href="#admin-id-change" data-admin-modal-open="admin-id-change">Change <?= $escape($idLabel) ?></a></div>
        <?php endif; ?>
    </section>

    <?php if ($role === 'admin'): ?>
    <section class="admin-card" id="student-id-scheme-card">
        <div class="admin-card-header">
            <div class="admin-card-title-group">
                <div class="admin-card-icon emerald">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 7h10"/><path d="M7 12h10"/><path d="M7 17h6"/><rect x="3" y="3" width="18" height="18" rx="3"/></svg>
                </div>
                <div class="admin-card-titles">
                    <h2>Student ID scheme</h2>
                    <p>The prefix and the number every Student ID - and every certificate issued under one - is minted from.</p>
                </div>
            </div>
            <span class="count-pill"><?= $escape($schemeExample) ?></span>
        </div>
        <form method="post" action="<?= $escape($baseUrl) ?>/admin/account/identity-scheme" id="student-id-scheme-form" class="admin-form">
            <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
            <div class="admin-grid-2">
                <div class="admin-field"><label class="admin-label" for="student_id_prefix">ID prefix <span class="req">*</span></label><input class="admin-input" id="student_id_prefix" name="student_id_prefix" type="text" value="<?= $escape($schemePrefix) ?>" maxlength="24" autocomplete="off" spellcheck="false" pattern="[A-Za-z][A-Za-z0-9]*(?:-[A-Za-z0-9]+)*" required><span class="admin-hint">Saved in uppercase. <strong>TSG-CER</strong> produces TSG-CER-001, TSG-CER-002, and so on.</span></div>
                <div class="admin-field"><label class="admin-label" for="student_id_start">First number <span class="req">*</span></label><input class="admin-input" id="student_id_start" name="student_id_start" type="number" min="1" max="9999999" step="1" value="<?= (int) $schemeStart ?>" required><span class="admin-hint">The number the next student is issued with. One already taken is skipped rather than handed out twice.</span></div>
            </div>
            <?php if ($schemeStudents > 0): ?>
                <label class="purge-acknowledgement" for="apply_to_existing"><input type="checkbox" id="apply_to_existing" name="apply_to_existing" value="1"><span>Re-issue IDs for the <?= (int) $schemeStudents ?> student<?= $schemeStudents === 1 ? '' : 's' ?> already on the roll, oldest account first, and carry their issued certificates to the new numbers.</span></label>
            <?php else: ?>
                <p class="admin-hint">No students are on the roll yet, so saving only sets the scheme for the first sign-up.</p>
            <?php endif; ?>
            <div class="form-actions"><a class="button" href="#student-id-scheme-confirm" data-admin-modal-open="student-id-scheme-confirm">Save scheme</a></div>
        </form>
    </section>
    <?php endif; ?>

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
                <div class="admin-field"><label class="admin-label" for="email_current_password">Current password <span class="req">*</span></label><span class="admin-input-wrap"><input class="admin-input" id="email_current_password" name="current_password" type="password" required autocomplete="current-password" placeholder="Confirm it is really you"><?= $eyeToggle ?></span></div>
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
                <div class="admin-field"><label class="admin-label" for="current_password">Current password <span class="req">*</span></label><span class="admin-input-wrap"><input class="admin-input" id="current_password" name="current_password" type="password" required autocomplete="current-password"><?= $eyeToggle ?></span></div>
                <div class="admin-field"><label class="admin-label" for="new_password">New password <span class="req">*</span></label><span class="admin-input-wrap"><input class="admin-input" id="new_password" name="new_password" type="password" required minlength="<?= (int) $minPassword ?>" maxlength="4096" autocomplete="new-password"><?= $eyeToggle ?></span></div>
            </div>
            <div class="admin-field"><label class="admin-label" for="confirm_password">Repeat new password <span class="req">*</span></label><span class="admin-input-wrap"><input class="admin-input" id="confirm_password" name="confirm_password" type="password" required minlength="<?= (int) $minPassword ?>" maxlength="4096" autocomplete="new-password"><?= $eyeToggle ?></span></div>
            <div class="form-actions"><button class="button" type="submit">Change password</button></div>
        </form>
    </section>

    <?php if ($role === 'admin'): ?>
        <div class="dashboard-note settings-scope-note"><strong>Why the <?= strtolower($escape($idLabel)) ?> matters</strong><span>The ID is the number a person verifies their certificate with, so it is unique across the Academy and never reused. Changing yours, or re-issuing student IDs, moves the documents already issued as it goes - anyone still holding an old number will be told no certificate matches it.</span></div>
    <?php else: ?>
        <div class="dashboard-note settings-scope-note"><strong>Why is my <?= strtolower($escape($idLabel)) ?> locked?</strong><span>The ID is the reference your enrollment, attendance, submissions, grades and certificate are all filed under, and it doubles as the certificate number on the public verification page. The Academy team sets it, so if a detail here looks wrong, contact them and it will be corrected.</span></div>
    <?php endif; ?>

    <?php if ($role === 'admin'): ?>
    <div class="admin-modal" id="admin-id-change" data-admin-modal role="dialog" aria-modal="true" aria-labelledby="admin-id-change-title">
        <a class="admin-modal-backdrop" href="#account-identity-card" data-admin-modal-close aria-label="Close the ID change"></a>
        <div class="admin-modal-dialog">
            <div class="admin-modal-header">
                <div class="admin-card-title-group">
                    <div class="admin-card-icon amber">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>
                    </div>
                    <div class="admin-card-titles"><p class="eyebrow">CONFIRM</p><h2 id="admin-id-change-title">Change your Admin ID</h2></div>
                </div>
                <a class="admin-modal-close" href="#account-identity-card" data-admin-modal-close aria-label="Close the ID change">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </a>
            </div>
            <form id="admin-id-form" method="post" action="<?= $escape($baseUrl) ?>/admin/account/id" class="admin-form admin-modal-body">
                <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
                <p class="identity-confirm-lead">This account is quoted as <strong><?= $escape($publicId) ?></strong>. Confirming will rename it to <strong class="identity-confirm-value" data-confirm-value="account-id-input"><?= $escape($publicId) ?></strong>.</p>
                <ul class="identity-confirm-points">
                    <li>Your receipts, notification emails and workspace records start showing the new number.</li>
                    <li>Anything already issued under the old number is moved to the new one, so it keeps verifying.</li>
                    <li>The old number stops working. Anyone holding it will be told no record matches.</li>
                    <li>It has to be unique - if another account answers to it, nothing is saved.</li>
                </ul>
                <div class="form-actions">
                    <a class="button button-ghost" href="#account-identity-card" data-admin-modal-close>Cancel</a>
                    <button class="button button-danger" type="submit">Confirm new ID</button>
                </div>
            </form>
        </div>
    </div>

    <div class="admin-modal" id="student-id-scheme-confirm" data-admin-modal role="dialog" aria-modal="true" aria-labelledby="student-id-scheme-confirm-title">
        <a class="admin-modal-backdrop" href="#student-id-scheme-card" data-admin-modal-close aria-label="Close the scheme confirmation"></a>
        <div class="admin-modal-dialog">
            <div class="admin-modal-header">
                <div class="admin-card-title-group">
                    <div class="admin-card-icon amber">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>
                    </div>
                    <div class="admin-card-titles"><p class="eyebrow">CONFIRM</p><h2 id="student-id-scheme-confirm-title">Save the Student ID scheme</h2></div>
                </div>
                <a class="admin-modal-close" href="#student-id-scheme-card" data-admin-modal-close aria-label="Close the scheme confirmation">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </a>
            </div>
            <div class="admin-form admin-modal-body">
                <p class="identity-confirm-lead">These are the values about to be saved:</p>
                <dl class="identity-confirm-grid">
                    <div><dt>Prefix</dt><dd class="identity-confirm-value" data-confirm-value="student_id_prefix"><?= $escape($schemePrefix) ?></dd></div>
                    <div><dt>First number</dt><dd class="identity-confirm-value" data-confirm-value="student_id_start"><?= (int) $schemeStart ?></dd></div>
                    <div><dt>Re-issue existing students</dt><dd class="identity-confirm-value" data-confirm-value="apply_to_existing"><?= $schemeStudents > 0 ? 'No' : 'Not applicable' ?></dd></div>
                </dl>
                <ul class="identity-confirm-points">
                    <li>Students who sign up from now on are issued IDs in this format, one number at a time.</li>
                    <li>Without re-issue, the students already on the roll keep the IDs printed on their documents.</li>
                    <li>With re-issue, every student is numbered from the first number above, oldest account first, and their issued certificates follow them.</li>
                </ul>
                <div class="form-actions">
                    <a class="button button-ghost" href="#student-id-scheme-card" data-admin-modal-close>Cancel</a>
                    <button class="button button-danger" type="submit" form="student-id-scheme-form">Confirm and save</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</section>
