<?php
use App\Core\Security;
$courses = isset($courses) && is_array($courses) ? $courses : [];
$catalogueAvailable = isset($catalogueAvailable) ? (bool) $catalogueAvailable : false;
$baseUrl = isset($baseUrl) && is_string($baseUrl) ? rtrim($baseUrl, '/') : rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
?>
<section class="page-hero" data-reveal><p class="eyebrow">COURSE CATALOGUE</p><h1>Find a skill to take further.</h1><p>Explore courses currently published by Tech SG Academy. Every course includes recorded lectures, materials, assignments and quizzes — with a Live + Recorded option for scheduled Zoom classes.</p></section>
<?php if (!$catalogueAvailable): ?><section class="empty-state"><h2>Course catalogue is temporarily unavailable</h2><p>Please try again later or contact the Academy team for current course information.</p></section>
<?php elseif ($courses === []): ?><section class="empty-state"><h2>No published courses yet</h2><p>New courses will appear here once the Academy publishes them.</p><a class="button" href="<?= Security::escape($baseUrl) ?>/contact">Ask about upcoming courses</a></section>
<?php else: ?>
<section class="course-grid">
<?php $courseIndex = 0; foreach ($courses as $course):
    $category = (string) ($course['category'] ?? '');
    $categoryClass = match ($category) {
        'Web Development' => 'category-web',
        'Digital Marketing' => 'category-marketing',
        'Graphic Design' => 'category-design',
        default => 'category-general',
    };
?>
<article class="course-card" data-reveal style="--reveal-delay: <?= Security::escape(number_format($courseIndex * 0.07, 2)) ?>s">
  <div class="course-card-heading"><span class="course-category <?= $categoryClass ?>"><?= Security::escape($category !== '' ? $category : 'Tech SG Academy') ?></span><span class="course-heading-right"><?php if (($course['title'] ?? '') === 'Full Stack Web Development'): ?><span class="course-badge">Most popular</span><?php endif; ?><?php if (!empty($course['level_label'])): ?><span class="level-label"><?= Security::escape((string) $course['level_label']) ?></span><?php endif; ?></span></div>
  <h2><?= Security::escape((string) $course['title']) ?></h2>
  <p><?= Security::escape((string) ($course['short_description'] ?: $course['description'] ?: 'Course details will be shared by the Academy.')) ?></p>
  <div class="course-meta"><?php if (!empty($course['duration_label'])): ?><span><?= Security::escape((string) $course['duration_label']) ?></span><?php endif; ?></div>
  <div class="price-options"><?php if ($course['recorded_price'] !== null): ?><div><span>Recorded Only</span><strong><?= Security::escape((string) $course['currency']) ?> <?= number_format((float) $course['recorded_price'], 0) ?></strong></div><?php endif; ?><?php if ($course['live_price'] !== null): ?><div><span>Live + Recorded</span><strong><?= Security::escape((string) $course['currency']) ?> <?= number_format((float) $course['live_price'], 0) ?></strong></div><?php endif; ?></div>
  <?php $signedInUser = isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : []; ?>
  <a class="button course-cta" href="<?= Security::escape($baseUrl) ?><?= ($signedInUser['role'] ?? '') === 'student' ? '/payments/new?course_id=' . (int) $course['id'] : '/register' ?>"><?= ($signedInUser['role'] ?? '') === 'student' ? 'Enroll / submit payment' : 'Create student account' ?></a>
</article>
<?php $courseIndex++; endforeach; ?></section>
<?php endif; ?>
