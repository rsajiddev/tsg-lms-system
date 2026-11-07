<?php

use App\Core\Security;

/**
 * Guarded bulk-delete card, now collected onto the single Danger Zone page.
 *
 * It is deliberately always visible (a developer mid-testing cannot afford a hidden switch
 * that has to be remembered before delivery) but impossible to fire by accident: the exact
 * confirmation phrase must be typed, the irreversible warning must be ticked, and the submit
 * button stays disabled until both are true. The server repeats every check.
 *
 * Because several cards share one page here, the enabling script is emitted only once and
 * wired on DOMContentLoaded, so every card on the page is guarded by the same logic no matter
 * how many times this file is included.
 *
 * Locals: $purgeAction (path), $purgePhrase, $purgeTitle, $purgeDescription,
 *         $purgeCount (int), $purgeScopeCourses (array of courses, optional),
 *         $purgeButtonLabel (optional), $purgeAck (optional acknowledgement sentence).
 */
$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $value): string => Security::escape((string) $value);
$purgeAction = (string) ($purgeAction ?? '');
$purgePhrase = (string) ($purgePhrase ?? 'DELETE');
$purgeTitle = (string) ($purgeTitle ?? 'Delete everything');
$purgeDescription = (string) ($purgeDescription ?? 'This removes records permanently.');
$purgeCount = max(0, (int) ($purgeCount ?? 0));
$purgeScopeCourses = is_array($purgeScopeCourses ?? null) ? $purgeScopeCourses : [];
$purgeButtonLabel = (string) ($purgeButtonLabel ?? 'Delete permanently');
$purgeAck = (string) ($purgeAck ?? 'I understand this deletes the records permanently and cannot be undone.');
if ($purgeAction === '') {
    return;
}
$purgeId = substr(md5($purgeAction), 0, 8);
?>
<section class="admin-card admin-card-testing danger-card">
    <div class="admin-card-header">
        <div class="admin-card-title-group">
            <div class="admin-card-icon danger">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="m19 6-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
            </div>
            <div class="admin-card-titles">
                <h2><?= $escape($purgeTitle) ?></h2>
                <p><?= $escape($purgeDescription) ?> <strong><?= $purgeCount ?></strong> record(s) currently match this action.</p>
            </div>
        </div>
    </div>
    <form method="post" action="<?= $escape($baseUrl . $purgeAction) ?>" class="admin-form" data-purge-form>
        <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
        <input type="hidden" name="return_to" value="/admin/danger-zone">
        <?php if ($purgeScopeCourses !== []): ?>
            <div class="admin-field"><label class="admin-label" for="purge-course-<?= $escape($purgeId) ?>">Course</label><select class="admin-select" id="purge-course-<?= $escape($purgeId) ?>" name="course_id">
                    <option value="0">All courses</option>
                    <?php foreach ($purgeScopeCourses as $purgeCourse): ?><option value="<?= (int) $purgeCourse['id'] ?>"><?= $escape($purgeCourse['title']) ?></option><?php endforeach; ?>
                </select><span class="admin-hint">Leave on “All courses” to clear everything at once.</span></div>
        <?php endif; ?>
        <div class="admin-grid-2">
            <div class="admin-field"><label class="admin-label" for="purge-confirm-<?= $escape($purgeId) ?>">Type <code><?= $escape($purgePhrase) ?></code> to confirm <span class="req">*</span></label><input class="admin-input" id="purge-confirm-<?= $escape($purgeId) ?>" type="text" name="confirm_phrase" autocomplete="off" spellcheck="false" data-purge-phrase required placeholder="<?= $escape($purgePhrase) ?>"></div>
            <div class="admin-field"><label class="admin-label">&nbsp;</label><label class="purge-acknowledgement"><input type="checkbox" name="irreversible" value="1" data-purge-ack><span><?= $escape($purgeAck) ?></span></label></div>
        </div>
        <div class="form-actions"><button class="button button-danger" type="submit" data-purge-submit disabled><?= $escape($purgeButtonLabel) ?></button></div>
    </form>
</section>
<?php
// One shared script for the whole page. The guard keeps every included copy of this card from
// stacking duplicate listeners, and the DOMContentLoaded hook means cards rendered after this
// point are still wired, which a multi-card page needs.
if (empty($GLOBALS['purge_zone_script_emitted'])) {
    $GLOBALS['purge_zone_script_emitted'] = true;
?>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-purge-form]').forEach(form => {
            const phrase = form.querySelector('[data-purge-phrase]');
            const acknowledgement = form.querySelector('[data-purge-ack]');
            const submit = form.querySelector('[data-purge-submit]');
            if (!phrase || !acknowledgement || !submit) return;
            const expected = (phrase.placeholder || '').trim();
            const sync = () => {
                submit.disabled = phrase.value.trim() !== expected || !acknowledgement.checked;
            };
            phrase.addEventListener('input', sync);
            acknowledgement.addEventListener('change', sync);
            form.addEventListener('submit', event => {
                if (submit.disabled) {
                    event.preventDefault();
                    return;
                }
                if (!window.confirm('This will permanently delete ' + expected.toLowerCase() + '. Continue?')) {
                    event.preventDefault();
                }
            });
            sync();
        });
    });
</script>
<?php } ?>
