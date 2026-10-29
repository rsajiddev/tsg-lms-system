<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $value): string => Security::escape((string) $value);
$settings = $settings ?? [];
$groups = $groups ?? [];
$values = $values ?? [];
$message = $message ?? null;
?>

<section class="dashboard-page admin-workspace" data-reveal>

    <header class="admin-header">
        <div class="admin-header-main">
            <button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
            <p class="eyebrow">ADMIN WORKSPACE</p>
            <h1>Security settings</h1>
            <p>Configure OTP expiry, resend timing and the authentication rate limits that apply to the whole academy. Everything on this page is enforced by the sign-in, registration and password-reset flows.</p>
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
                        <h2>Authentication limits</h2>
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

    <?php endif; ?>

    <div class="dashboard-note settings-scope-note"><strong>Looking for a rule you used to set here?</strong><span>Feature settings now live with the page that uses them: the <a class="dashboard-inline-link" href="<?= $escape($baseUrl) ?>/admin/students">attendance confirmation window on Students</a>, the <a class="dashboard-inline-link" href="<?= $escape($baseUrl) ?>/admin/lessons">lesson watch-time requirement on the Lesson library</a>, and the <a class="dashboard-inline-link" href="<?= $escape($baseUrl) ?>/admin/certificates">certificate prefix and validity on Certificates</a>.</span></div>

</section>
