<?php
use App\Core\Security;
$baseUrl=rtrim((require BASE_PATH.'/config/app.php')['url'],'/'); $values=$values??[]; $settings=$settings??[]; $message=$message??null;
?>
<section class="dashboard-page" data-reveal><header class="dashboard-heading"><div><button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button><p class="eyebrow">ADMIN WORKSPACE</p><h1>Security settings</h1><p>Configure OTP expiry, resend timing, and authentication rate limits.</p></div></header>
<?php if($message): ?><p class="form-message" role="status"><?= Security::escape($message) ?></p><?php endif; ?>
<?php if(!empty($migrationRequired)): ?><section class="dashboard-panel"><h2>Database update required</h2><p>The current database is missing <code>app_settings</code>. In phpMyAdmin, select <code>tsg_academy_lms</code> and import the updated <code>database/migrations/001_initial_schema.sql</code>. This schema uses create-if-missing statements and preserves existing records.</p></section><?php else: ?>
<section class="dashboard-panel"><form method="post" action="<?= Security::escape($baseUrl) ?>/admin/settings" class="stack-form"><input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>">
<?php foreach($settings as $key=>[$label,$min,$max]): ?><label class="field"><?= Security::escape($label) ?><input type="number" name="<?= Security::escape($key) ?>" min="<?= (int)$min ?>" max="<?= (int)$max ?>" required value="<?= (int)($values[$key]??0) ?>"><small><?= $key==='otp_lifetime_seconds'?'One shared interval: current OTP expires and resend becomes available at the same time.':'Applies to all new authentication requests.' ?></small></label><?php endforeach; ?>
<button class="button" type="submit">Save security settings</button></form></section></section>
<?php endif; ?>
