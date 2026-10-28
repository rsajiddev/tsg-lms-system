<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $value): string => Security::escape((string) $value);
$settings = $settings ?? [];
$groups = $groups ?? [];
$values = $values ?? [];
$certificateValues = is_array($certificateValues ?? null) ? $certificateValues : [];
$message = $message ?? null;
?>

<section class="dashboard-page admin-workspace" data-reveal>

    <header class="admin-header">
        <div class="admin-header-main">
            <button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
            <p class="eyebrow">ADMIN WORKSPACE</p>
            <h1>Security settings</h1>
            <p>Configure OTP expiry, resend timing, authentication rate limits, and the attendance grace window for the whole academy.</p>
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
                        <h2>Authentication &amp; attendance limits</h2>
                        <p>Every limit below applies to all new requests from the moment you save it.</p>
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

        <section class="admin-card certificate-settings-card">
            <div class="admin-card-header">
                <div class="admin-card-title-group">
                    <div class="admin-card-icon emerald">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="9" r="6"></circle>
                            <path d="M8.5 14.5 7 22l5-2.6L17 22l-1.5-7.5"></path>
                        </svg>
                    </div>
                    <div class="admin-card-titles">
                        <h2>Certificate settings</h2>
                        <p>Control the identifier format and validity period used when an admin issues a student certificate.</p>
                    </div>
                </div>
            </div>

            <form method="post" action="<?= $escape($baseUrl) ?>/admin/settings" class="admin-form">
                <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
                <input type="hidden" name="form_type" value="certificate_settings">
                <div class="admin-form-row">
                    <div class="admin-field">
                        <label class="admin-label" for="certificate-id-prefix">Certificate ID prefix</label>
                        <input class="admin-input" id="certificate-id-prefix" name="certificate_id_prefix" maxlength="24" required autocomplete="off" spellcheck="false" value="<?= $escape($certificateValues['certificate_id_prefix'] ?? 'TSG-CERT') ?>" placeholder="TSG-CERT">
                        <span class="admin-hint">2-24 uppercase letters, digits, or single hyphens. New IDs are issued as PREFIX-YEAR-SEQUENCE.</span>
                    </div>
                    <div class="admin-field">
                        <label class="admin-label" for="certificate-available-days">Available for</label>
                        <div class="admin-number-field">
                            <input class="admin-input" id="certificate-available-days" name="certificate_available_days" type="number" min="1" max="3650" required value="<?= (int) ($certificateValues['certificate_available_days'] ?? 15) ?>" aria-label="Certificate availability in days">
                            <span class="admin-number-unit">days</span>
                        </div>
                        <span class="admin-hint">Counted from the issue date; verification and download stop working after expiry.</span>
                    </div>
                </div>
                <div class="form-actions">
                    <button class="button" type="submit">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                            <polyline points="17 21 17 13 7 13 7 21"></polyline>
                            <polyline points="7 3 7 8 15 8"></polyline>
                        </svg>
                        Save certificate settings
                    </button>
                </div>
            </form>
        </section>

    <?php endif; ?>

</section>
