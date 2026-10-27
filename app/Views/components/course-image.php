<?php

use App\Core\Security;

$baseUrl = isset($baseUrl) && is_string($baseUrl) ? rtrim($baseUrl, '/') : rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$course = isset($course) && is_array($course) ? $course : [];
$courseImage = trim((string) ($course['image_path'] ?? ''));
$stockImages = ['assets/images/graphic-design.jpg', 'assets/images/digital-marketing.jpg', 'assets/images/web-development.jpg'];
if (!in_array($courseImage, $stockImages, true) && !preg_match('~^assets/uploads/courses/[a-f0-9]{32}\.(?:jpg|png|webp)$~', $courseImage)) {
    $categoryKey = strtolower((string) ($course['category'] ?? '') . ' ' . (string) ($course['title'] ?? ''));
    $courseImage = match (true) {
        str_contains($categoryKey, 'design') => 'assets/images/graphic-design.jpg',
        str_contains($categoryKey, 'marketing') => 'assets/images/digital-marketing.jpg',
        str_contains($categoryKey, 'web'), str_contains($categoryKey, 'development') => 'assets/images/web-development.jpg',
        default => 'assets/images/web-development.jpg',
    };
}
$courseImageFrameClass = trim((string) ($courseImageFrameClass ?? 'course-card-image'));
$courseImageAlt = (string) ($course['title'] ?? 'Academy course') . ' course';
?>
<div class="<?= Security::escape($courseImageFrameClass) ?>" data-image-frame>
    <div class="course-image-placeholder" aria-live="polite"><span class="course-image-spinner" aria-hidden="true"></span><span class="course-image-status">Loading course image…</span></div>
    <img class="course-card-image-media" src="<?= Security::escape($baseUrl . '/public/' . $courseImage) ?>" alt="<?= Security::escape($courseImageAlt) ?>" loading="lazy" decoding="async" data-course-image>
</div>
