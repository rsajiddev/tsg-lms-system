<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $value): string => Security::escape((string) $value);
$courses = is_array($courses ?? null) ? $courses : [];
$challenges = is_array($challenges ?? null) ? $challenges : [];
$message = is_string($message ?? null) ? $message : null;
$testExample = "[\n  {\"name\":\"Page heading exists\",\"selector\":\"h1\",\"assertion\":\"exists\"},\n  {\"name\":\"Heading has expected text\",\"selector\":\"h1\",\"assertion\":\"text_contains\",\"expected\":\"Hello\"}\n]";
?>
<section class="dashboard-page coding-admin-page" data-reveal>
    <div class="dashboard-topbar"><button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button><div class="dashboard-breadcrumb"><a href="<?= $escape($baseUrl) ?>/dashboard">Workspace</a><i>/</i><strong>Coding challenges</strong></div></div>
    <header class="dashboard-heading"><div><p class="eyebrow">PRACTICE LAB</p><h1>Coding challenges</h1><p>Create browser-based HTML, CSS and JavaScript exercises with live previews and test cases.</p></div></header>
    <?php \App\Core\View::toast('success', $message ?? null); ?>
    <?php if ($courses === []): ?><div class="dashboard-note"><strong>Create a course first</strong><span>Coding challenges are attached to courses.</span></div><?php else: ?>
    <section class="dashboard-panel coding-admin-form-panel">
        <div class="dashboard-panel-heading"><div><p class="eyebrow">CHALLENGE BUILDER</p><h2>New challenge</h2></div></div>
        <form method="post" action="<?= $escape($baseUrl) ?>/admin/coding-challenges/save" class="admin-form coding-challenge-form">
            <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
            <div class="admin-form-row-3">
                <div class="admin-field"><label class="admin-label" for="challenge-course">Course</label><select class="admin-select" id="challenge-course" name="course_id" required><?php foreach ($courses as $course): ?><option value="<?= (int) $course['id'] ?>"><?= $escape($course['title']) ?></option><?php endforeach; ?></select></div>
                <div class="admin-field"><label class="admin-label" for="challenge-title">Challenge title</label><input class="admin-input" id="challenge-title" name="title" maxlength="200" required></div>
                <div class="admin-field"><label class="admin-label" for="challenge-status">Status</label><select class="admin-select" id="challenge-status" name="status"><option value="draft">Draft</option><option value="published">Published</option></select></div>
            </div>
            <div class="admin-field"><label class="admin-label" for="challenge-instructions">Instructions</label><textarea class="admin-textarea" id="challenge-instructions" name="instructions" rows="3" maxlength="10000" placeholder="Describe the task and expected outcome"></textarea></div>
            <div class="coding-starter-grid">
                <div class="admin-field"><label class="admin-label" for="challenge-html">Starter HTML</label><textarea class="admin-textarea coding-code-input" id="challenge-html" name="starter_html" rows="8" maxlength="100000"><main><h1>Hello</h1></main></textarea></div>
                <div class="admin-field"><label class="admin-label" for="challenge-css">Starter CSS</label><textarea class="admin-textarea coding-code-input" id="challenge-css" name="starter_css" rows="8" maxlength="100000">body { font-family: sans-serif; }</textarea></div>
                <div class="admin-field"><label class="admin-label" for="challenge-js">Starter JavaScript</label><textarea class="admin-textarea coding-code-input" id="challenge-js" name="starter_js" rows="8" maxlength="100000"></textarea></div>
            </div>
            <div class="admin-field"><label class="admin-label" for="challenge-tests">Test cases (JSON)</label><textarea class="admin-textarea coding-code-input" id="challenge-tests" name="tests_json" rows="7" required><?= $escape($testExample) ?></textarea><span class="admin-hint">Supported assertions: exists, text_contains, attribute_equals, style_equals. Keep checks focused on the visible page output.</span></div>
            <button class="button" type="submit">Save challenge</button>
        </form>
    </section>
    <?php endif; ?>
    <section class="dashboard-panel coding-admin-library"><div class="dashboard-panel-heading"><div><p class="eyebrow">LIBRARY</p><h2><?= count($challenges) ?> challenge<?= count($challenges) === 1 ? '' : 's' ?></h2></div></div>
        <?php if ($challenges === []): ?><div class="dashboard-empty"><span class="dashboard-empty-icon">&lt;/&gt;</span><div><h3>No challenges yet</h3><p>Save a draft or publish the first course exercise above.</p></div></div><?php else: ?>
            <div class="coding-challenge-admin-list">
            <?php foreach ($challenges as $challenge): ?>
                <details class="coding-admin-item" id="challenge-<?= (int) $challenge['id'] ?>">
                    <summary><span><strong><?= $escape($challenge['title']) ?></strong><small><?= $escape($challenge['course_title']) ?> · <?= (int) $challenge['attempt_count'] ?> practice attempts</small></span><span class="dashboard-status status-<?= $escape($challenge['status']) ?>"><?= $escape(ucfirst($challenge['status'])) ?></span></summary>
                    <form method="post" action="<?= $escape($baseUrl) ?>/admin/coding-challenges/save" class="admin-form coding-challenge-form">
                        <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>"><input type="hidden" name="id" value="<?= (int) $challenge['id'] ?>">
                        <div class="admin-form-row-3"><div class="admin-field"><label class="admin-label">Course</label><select class="admin-select" name="course_id" required><?php foreach ($courses as $course): ?><option value="<?= (int) $course['id'] ?>"<?= (int) $course['id'] === (int) $challenge['course_id'] ? ' selected' : '' ?>><?= $escape($course['title']) ?></option><?php endforeach; ?></select></div><div class="admin-field"><label class="admin-label">Challenge title</label><input class="admin-input" name="title" maxlength="200" value="<?= $escape($challenge['title']) ?>" required></div><div class="admin-field"><label class="admin-label">Status</label><select class="admin-select" name="status"><option value="draft"<?= $challenge['status'] === 'draft' ? ' selected' : '' ?>>Draft</option><option value="published"<?= $challenge['status'] === 'published' ? ' selected' : '' ?>>Published</option></select></div></div>
                        <div class="admin-field"><label class="admin-label">Instructions</label><textarea class="admin-textarea" name="instructions" rows="3" maxlength="10000"><?= $escape($challenge['instructions']) ?></textarea></div>
                        <div class="coding-starter-grid"><div class="admin-field"><label class="admin-label">Starter HTML</label><textarea class="admin-textarea coding-code-input" name="starter_html" rows="8" maxlength="100000"><?= $escape($challenge['starter_html']) ?></textarea></div><div class="admin-field"><label class="admin-label">Starter CSS</label><textarea class="admin-textarea coding-code-input" name="starter_css" rows="8" maxlength="100000"><?= $escape($challenge['starter_css']) ?></textarea></div><div class="admin-field"><label class="admin-label">Starter JavaScript</label><textarea class="admin-textarea coding-code-input" name="starter_js" rows="8" maxlength="100000"><?= $escape($challenge['starter_js']) ?></textarea></div></div>
                        <div class="admin-field"><label class="admin-label">Test cases (JSON)</label><textarea class="admin-textarea coding-code-input" name="tests_json" rows="7" required><?= $escape($challenge['tests_json']) ?></textarea></div><button class="button" type="submit">Update challenge</button>
                    </form>
                </details>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php
    $usageEyebrow = 'Practice lab';
    $usageTitle = 'How to use coding challenges';
    $usageLead = 'A quick walkthrough of the workflow this page manages end to end.';
    $usageBullets = [
        'Attach the challenge to a course.',
        'Drafts stay private.',
        'Ship starter code.',
        'Write test cases in JSON.',
        'Results are practice signals.',
        'Edit any time from the library.',
    ];
    require BASE_PATH . '/app/Views/components/usage-guide.php';
    ?>
    <?php /* The standing caveat belongs under the guide, where a reader has just finished
              reading about how challenges are scored - not in the middle of the workflow. */ ?>
    <div class="dashboard-note"><strong>Practice checks</strong><span>Challenge code runs in a sandboxed browser preview. Test results are learner practice signals and are not official graded marks.</span></div>
</section>
