<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $value): string => Security::escape((string) $value);
$settings = $settings ?? [];
$groups = $groups ?? [];
$values = $values ?? [];
$paymentValues = $paymentValues ?? [];
$message = $message ?? null;
?>

<section class="dashboard-page admin-workspace" data-reveal>

    <header class="admin-header">
        <div class="admin-header-main">
            <button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
            <p class="eyebrow">ADMIN WORKSPACE</p>
            <h1>Security settings</h1>
            <p>Configure OTP expiry, resend timing, and authentication rate limits for the whole academy.</p>
        </div>
    </header>

    <?php if ($message): ?>
        <div class="admin-alert admin-alert-success" role="status">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                <polyline points="22 4 12 14.01 9 11.01"></polyline>
            </svg>
            <span><?= $escape($message) ?></span>
        </div>
    <?php endif; ?>

    <?php if (!empty($migrationRequired)): ?>
        <section class="admin-card">
            <div class="admin-card-header">
                <div class="admin-card-title-group">
                    <div class="admin-card-icon amber">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                            <line x1="12" y1="9" x2="12" y2="13"></line>
                            <line x1="12" y1="17" x2="12.01" y2="17"></line>
                        </svg>
                    </div>
                    <div class="admin-card-titles">
                        <h2>Database update required</h2>
                        <p>The current database is missing the <code>app_settings</code> table.</p>
                    </div>
                </div>
            </div>
            <div class="admin-notice">
                <p>In phpMyAdmin, select <code>tsg_academy_lms</code> and import the updated <code>database/migrations/001_initial_schema.sql</code>. This schema uses create-if-missing statements and preserves existing records.</p>
            </div>
        </section>
    <?php else: ?>

        <section class="admin-card">
            <div class="admin-card-header">
                <div class="admin-card-title-group">
                    <div class="admin-card-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                    </div>
                    <div class="admin-card-titles">
                        <h2>Authentication security</h2>
                        <p>Every limit below applies to all new authentication requests.</p>
                    </div>
                </div>
                <span class="count-pill"><?= count($settings) ?> controls</span>
            </div>

            <form method="post" action="<?= $escape($baseUrl) ?>/admin/settings" class="admin-form">
                <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">

                <?php foreach ($groups as $group): ?>
                    <fieldset class="admin-setting-group">
                        <legend class="admin-setting-group-title"><?= $escape($group['title']) ?></legend>
                        <p class="admin-setting-group-desc"><?= $escape($group['description']) ?></p>

                        <?php foreach ($group['keys'] as $key):
                            if (!isset($settings[$key])) {
                                continue;
                            }
                            [$label, $min, $max, $unit, $hint] = $settings[$key];
                            ?>
                            <div class="admin-setting-row">
                                <div class="admin-setting-row-text">
                                    <span class="admin-label"><?= $escape($label) ?></span>
                                    <span class="admin-hint"><?= $escape($hint) ?></span>
                                </div>
                                <div class="admin-setting-row-control">
                                    <div class="admin-number-field">
                                        <input class="admin-input" type="number" name="<?= $escape($key) ?>" min="<?= (int) $min ?>" max="<?= (int) $max ?>" required value="<?= (int) ($values[$key] ?? 0) ?>" aria-label="<?= $escape($label) ?>">
                                        <span class="admin-number-unit"><?= $escape($unit) ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endforeach; ?>

                <div class="form-actions">
                    <button class="button" type="submit">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                            <polyline points="17 21 17 13 7 13 7 21"></polyline>
                            <polyline points="7 3 7 8 15 8"></polyline>
                        </svg>
                        Save security settings
                    </button>
                </div>
            </form>
        </section>

        <section class="admin-card">
            <div class="admin-card-header">
                <div class="admin-card-title-group">
                    <div class="admin-card-icon violet" aria-hidden="true">₨</div>
                    <div class="admin-card-titles">
                        <h2>Course payment account</h2>
                        <p>These details appear to signed-in students before they submit a course payment.</p>
                    </div>
                </div>
            </div>
            <form method="post" action="<?= $escape($baseUrl) ?>/admin/settings" class="admin-form">
                <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
                <input type="hidden" name="form_type" value="payment_details">
                <div class="admin-form-row">
                    <div class="admin-field"><label class="admin-label" for="payment-account-title">Account title</label><input class="admin-input" id="payment-account-title" name="payment_account_title" maxlength="150" value="<?= $escape($paymentValues['payment_account_title'] ?? '') ?>" placeholder="Account holder name"></div>
                    <div class="admin-field"><label class="admin-label" for="payment-bank-name">Bank name</label><input class="admin-input" id="payment-bank-name" name="payment_bank_name" maxlength="100" value="<?= $escape($paymentValues['payment_bank_name'] ?? '') ?>" placeholder="Bank or provider"></div>
                </div>
                <div class="admin-form-row">
                    <div class="admin-field"><label class="admin-label" for="payment-account-number">Account number</label><input class="admin-input" id="payment-account-number" name="payment_account_number" maxlength="80" value="<?= $escape($paymentValues['payment_account_number'] ?? '') ?>" autocomplete="off"></div>
                    <div class="admin-field"><label class="admin-label" for="payment-iban">IBAN</label><input class="admin-input" id="payment-iban" name="payment_iban" maxlength="80" value="<?= $escape($paymentValues['payment_iban'] ?? '') ?>" autocomplete="off"></div>
                </div>
                <div class="admin-form-row">
                    <div class="admin-field"><label class="admin-label" for="payment-wallet-name">Mobile wallet name</label><input class="admin-input" id="payment-wallet-name" name="payment_wallet_name" maxlength="100" value="<?= $escape($paymentValues['payment_wallet_name'] ?? '') ?>" placeholder="Optional"></div>
                    <div class="admin-field"><label class="admin-label" for="payment-wallet-number">Mobile wallet number</label><input class="admin-input" id="payment-wallet-number" name="payment_wallet_number" maxlength="80" value="<?= $escape($paymentValues['payment_wallet_number'] ?? '') ?>" placeholder="Optional"></div>
                </div>
                <div class="admin-field"><label class="admin-label" for="payment-instructions">Student payment instructions</label><textarea class="admin-textarea" id="payment-instructions" name="payment_instructions" rows="3" maxlength="255" placeholder="For example: include your transaction reference when submitting the receipt."><?= $escape($paymentValues['payment_instructions'] ?? '') ?></textarea><span class="admin-hint">Maximum 255 characters. Leave account fields blank until verified account details are available.</span></div>
                <div class="form-actions"><button class="button" type="submit">Save payment details</button></div>
            </form>
        </section>

    <?php endif; ?>

</section>
