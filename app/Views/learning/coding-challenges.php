<?php
use App\Core\Security;
$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $value): string => Security::escape((string) $value);
$challenges = is_array($challenges ?? null) ? $challenges : [];
$course = is_array($course ?? null) ? $course : [];
$hasCodingCourseAccess = ($hasCodingCourseAccess ?? false) === true;
$message = is_string($message ?? null) ? $message : null;
?>
<section class="dashboard-page coding-student-page" data-reveal>
    <div class="dashboard-topbar"><button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button><div class="dashboard-breadcrumb"><a href="<?= $escape($baseUrl) ?>/student/courses">My courses</a><i>/</i><strong>Code challenges</strong></div></div>
    <header class="dashboard-heading"><div><p class="eyebrow">BROWSER PRACTICE</p><h1><?= $escape($course['title'] ?? 'Course') ?> challenges</h1><p>Write HTML, CSS and JavaScript, preview your page, and run the challenge checks.</p></div><?php if ((int) ($course['id'] ?? 0) > 0): ?><a class="dashboard-inline-link" href="<?= $escape($baseUrl) ?>/learning?course_id=<?= (int) $course['id'] ?>">Open course →</a><?php endif; ?></header>
    <?php if ($message): ?><div class="dashboard-note" role="status"><strong>Practice attempt saved</strong><span><?= $escape($message) ?></span></div><?php endif; ?>
    <?php if (!$hasCodingCourseAccess): ?><div class="dashboard-note" role="note"><strong>For web development students</strong><span>Coding challenges are available to students with active access to the Full Stack Web Development course.</span></div><?php else: ?><section class="dashboard-panel"><div class="dashboard-panel-heading"><div><p class="eyebrow">CHALLENGE SET</p><h2><?= count($challenges) ?> exercise<?= count($challenges) === 1 ? '' : 's' ?></h2></div></div>
    <?php if ($challenges === []): ?><div class="dashboard-empty"><span class="dashboard-empty-icon">&lt;/&gt;</span><div><h3>No published coding challenges</h3><p>Your instructor has not published code exercises for this course yet.</p></div></div><?php else: ?><div class="coding-student-list">
        <?php foreach ($challenges as $challenge): ?><article class="coding-student-card"><div class="coding-card-mark" aria-hidden="true">&lt;/&gt;</div><div class="coding-student-copy"><p class="eyebrow"><?= $escape($challenge['course_title'] ?? $course['title'] ?? 'COURSE') ?> · HTML · CSS · JAVASCRIPT</p><h3><?= $escape($challenge['title']) ?></h3><p><?= $escape($challenge['instructions']) ?></p><small><?= (int) $challenge['test_count'] ?> checks · <?= (int) $challenge['attempt_count'] ?> saved practice runs</small></div><div class="coding-student-action"><?php if ($challenge['latest_status']): ?><span class="dashboard-status <?= $challenge['latest_status'] === 'pass' ? 'status-active' : 'status-pending' ?>">Latest: <?= $escape(ucfirst($challenge['latest_status'])) ?></span><?php endif; ?><a class="button" href="<?= $escape($baseUrl) ?>/learning/challenge?challenge_id=<?= (int) $challenge['id'] ?>">Open editor</a></div></article><?php endforeach; ?>
    </div><?php endif; ?></section><?php endif; ?>
    <div class="dashboard-note"><strong>Browser-based practice</strong><span>Your code stays in a restricted preview frame. Pass/fail checks are for practice and are not official grades.</span></div>
</section>
