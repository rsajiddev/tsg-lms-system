<?php
use App\Core\Security;
$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $value): string => Security::escape((string) $value);
$challenge = is_array($challenge ?? null) ? $challenge : [];
$tests = json_decode((string) ($challenge['tests_json'] ?? '[]'), true);
$tests = is_array($tests) ? $tests : [];
$attempts = is_array($attempts ?? null) ? $attempts : [];
$message = is_string($message ?? null) ? $message : null;
?>
<section class="dashboard-page coding-editor-page" data-reveal data-coding-editor data-tests="<?= $escape(json_encode($tests, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>">
    <div class="dashboard-topbar"><button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button><div class="dashboard-breadcrumb"><a href="<?= $escape($baseUrl) ?>/student/courses">My courses</a><i>/</i><a href="<?= $escape($baseUrl) ?>/learning/challenges?course_id=<?= (int) $challenge['course_id'] ?>">Code challenges</a><i>/</i><strong><?= $escape($challenge['title']) ?></strong></div></div>
    <header class="dashboard-heading coding-editor-heading"><div><p class="eyebrow"><?= $escape($challenge['course_title']) ?> · PRACTICE LAB</p><h1><?= $escape($challenge['title']) ?></h1><p><?= nl2br($escape($challenge['instructions'])) ?></p></div><span class="coding-sandbox-badge">Sandboxed preview</span></header>
    <?php if ($message): ?><div class="dashboard-note" role="status"><strong>Saved</strong><span><?= $escape($message) ?></span></div><?php endif; ?>
    <div class="coding-workbench">
        <section class="dashboard-panel coding-editor-panel" aria-label="Code editor">
            <div class="dashboard-panel-heading"><div><p class="eyebrow">YOUR WORK</p><h2>Edit the files</h2></div><span class="count-pill">HTML · CSS · JS</span></div>
            <label class="coding-editor-label" for="coding-html">HTML</label><textarea id="coding-html" class="coding-editor-textarea" data-code-html spellcheck="false" autocomplete="off" autocapitalize="off"><?= $escape($challenge['starter_html']) ?></textarea>
            <label class="coding-editor-label" for="coding-css">CSS</label><textarea id="coding-css" class="coding-editor-textarea" data-code-css spellcheck="false" autocomplete="off" autocapitalize="off"><?= $escape($challenge['starter_css']) ?></textarea>
            <label class="coding-editor-label" for="coding-js">JavaScript</label><textarea id="coding-js" class="coding-editor-textarea" data-code-js spellcheck="false" autocomplete="off" autocapitalize="off"><?= $escape($challenge['starter_js']) ?></textarea>
            <div class="coding-editor-actions"><button class="button" type="button" data-run-preview>Run preview &amp; checks</button><span class="coding-run-status" data-run-status aria-live="polite">Make a change, then run the checks.</span></div>
            <div class="coding-test-results" data-test-results aria-live="polite"></div>
            <form method="post" action="<?= $escape($baseUrl) ?>/learning/challenge/submit" data-attempt-form>
                <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>"><input type="hidden" name="challenge_id" value="<?= (int) $challenge['id'] ?>"><input type="hidden" name="html_code"><input type="hidden" name="css_code"><input type="hidden" name="js_code"><input type="hidden" name="reported_status"><input type="hidden" name="test_results">
                <button class="button button-secondary" type="submit" data-save-attempt disabled>Save practice attempt</button>
            </form>
        </section>
        <section class="dashboard-panel coding-preview-panel"><div class="dashboard-panel-heading"><div><p class="eyebrow">LIVE PREVIEW</p><h2>Your page</h2></div><span class="coding-preview-indicator"><i></i> Isolated frame</span></div>
            <iframe title="Isolated coding challenge preview" data-code-preview sandbox="allow-scripts" referrerpolicy="no-referrer"></iframe>
            <p class="coding-preview-note">The isolated preview cannot access your account. Browser policy blocks network APIs and form submission; checks inspect the rendered page.</p>
        </section>
    </div>
    <section class="dashboard-panel coding-history-panel"><div class="dashboard-panel-heading"><div><p class="eyebrow">YOUR PRACTICE</p><h2>Recent saved attempts</h2></div></div>
        <?php if ($attempts === []): ?><p class="coding-history-empty">Saved practice runs will appear here.</p><?php else: ?><div class="coding-attempt-list"><?php foreach ($attempts as $attempt): $results = json_decode((string) $attempt['test_results'], true); $results = is_array($results) ? $results : []; $passed = count(array_filter($results, static fn($r): bool => is_array($r) && ($r['passed'] ?? false) === true)); ?><article><span class="dashboard-status <?= $attempt['reported_status'] === 'pass' ? 'status-active' : 'status-pending' ?>"><?= $escape(ucfirst($attempt['reported_status'])) ?> · <?= $passed ?>/<?= count($results) ?> checks</span><time><?= $escape($attempt['created_at']) ?> UTC</time></article><?php endforeach; ?></div><?php endif; ?>
        <p class="coding-practice-disclaimer">These results are learner-side practice feedback, not verified or official assessment marks.</p>
    </section>
</section>
<script src="<?= $escape($baseUrl) ?>/public/assets/js/coding-challenges.js?v=<?= (string) filemtime(BASE_PATH . '/public/assets/js/coding-challenges.js') ?>" defer></script>
