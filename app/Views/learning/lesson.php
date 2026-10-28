<?php

use App\Core\Security;

$escape = [Security::class, 'escape'];
$media = isset($media) && is_array($media) ? $media : [];
$media += [
    'available' => false, 'video_url' => null, 'provider' => 'none', 'embed_url' => null, 'duration' => null,
    'duration_label' => '—', 'watched' => 0, 'watched_label' => '0:00', 'position' => 0, 'required' => 0,
    'required_label' => '—', 'ratio' => 0.0, 'completed' => false, 'has_video' => false, 'trackable' => false,
    'rule_applies' => false, 'percent' => 90, 'heartbeat' => 10,
];
$playerEndpoint = $escape((string) ($baseUrl ?? '')) . '/learning/lesson/watch';
?>
<section class="dashboard-page learning-page">
  <div class="dashboard-topbar"><button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
    <div class="dashboard-breadcrumb"><a href="<?= $escape($baseUrl) ?>/dashboard">Workspace</a><i>/</i><a href="<?= $escape($baseUrl) ?>/learning?course_id=<?= (int) $lesson['course_id'] ?>"><?= $escape($lesson['course_title']) ?></a><i>/</i><strong>Lesson</strong></div>
  </div>
  <header class="dashboard-heading learning-heading">
    <div>
      <p class="eyebrow"><?= $escape($lesson['module_title']) ?></p>
      <h1><?= $escape($lesson['lesson_title']) ?></h1>
      <p><?= $escape($lesson['course_title']) ?></p>
    </div>
  </header>
  <?php if (!empty($message)): ?><div class="admin-alert admin-alert-success" role="status"><?= $escape($message) ?></div><?php endif; ?>
  <?php if (!empty($error)): ?><div class="admin-alert admin-alert-error" role="alert"><?= $escape($error) ?></div><?php endif; ?>
  <?php if (isset($_GET['completed'])): ?><div class="admin-alert admin-alert-success" role="status">Lesson marked as complete.</div><?php endif; ?>
  <?php if ($media['has_video']): ?>
    <article class="dashboard-panel lesson-player<?= $media['trackable'] ? ' lesson-player-file' : ' lesson-player-embed' ?>" data-lesson-player
             data-endpoint="<?= $playerEndpoint ?>"
             data-lesson-id="<?= (int) $lesson['lesson_id'] ?>"
             data-csrf="<?= $escape(Security::csrfToken()) ?>"
             data-heartbeat="<?= (int) $media['heartbeat'] ?>"
             data-duration="<?= (int) $media['duration'] ?>"
             data-position="<?= (int) $media['position'] ?>"
             data-trackable="<?= $media['trackable'] ? '1' : '0' ?>"
             data-completed="<?= ($media['completed'] || (int) $lesson['completed'] === 1) ? '1' : '0' ?>">
      <?php if ($media['trackable'] && $media['embed_url']): ?>
        <video class="lesson-video" controls preload="metadata" playsinline src="<?= $media['embed_url'] ?>">
          Your browser cannot play this video. <a href="<?= $media['embed_url'] ?>">Open the recording directly</a>.
        </video>
      <?php elseif ($media['embed_url']): ?>
        <div class="lesson-video-frame">
          <iframe class="lesson-iframe" src="<?= $media['embed_url'] ?>" title="<?= $escape($lesson['lesson_title']) ?> lesson video" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; picture-in-picture" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>
        </div>
      <?php endif; ?>
      <div class="lesson-watch">
        <div class="lesson-watch-head">
          <span class="lesson-watch-title"><?= $media['duration'] ? 'Watch progress' : 'Time watched' ?></span>
          <strong class="lesson-watch-text" data-watch-text><?= $escape($media['watched_label']) ?><?= $media['duration'] ? ' / ' . $escape($media['duration_label']) : '' ?></strong>
        </div>
        <div class="lesson-watch-track" role="progressbar" aria-label="Lesson video watched" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) round($media['ratio']) ?>"><span class="lesson-watch-fill" data-watch-fill style="--watch-width: <?= max(0, min(100, (float) $media['ratio'])) ?>%"></span></div>
        <p class="lesson-watch-note" data-watch-note><?php
            if ((int) $lesson['completed'] === 1) {
                echo 'You have completed this lesson.';
            } elseif ($media['rule_applies']) {
                echo 'Watch ' . (int) $media['percent'] . '% of this recording (' . $escape($media['required_label']) . ') to unlock completion.';
            } else {
                echo 'This recording has no registered length, so the lesson is completed manually below.';
            }
        ?></p>
      </div>
      <?php if (!$media['trackable']): ?>
        <p class="lesson-player-hint"><?= $escape(ucfirst($media['provider'])) ?> videos cannot report their playhead, so watched time is measured while this tab stays open and active.</p>
      <?php endif; ?>
    </article>
  <?php endif; ?>
  <article class="dashboard-panel learning-content"><?php if (trim((string) ($lesson['body'] ?? '')) !== ''): ?><div class="learning-body"><?= nl2br($escape($lesson['body'])) ?></div><?php else: ?><div class="dashboard-empty">
        <div>
          <h2>Lesson content is being prepared</h2>
          <p>Your course team has not added the lesson materials yet.</p>
        </div>
      </div><?php endif; ?><div class="learning-actions"><a class="button button-secondary" href="<?= $escape($baseUrl) ?>/learning?course_id=<?= (int) $lesson['course_id'] ?>">Back to course</a><?php if ((int) $lesson['completed'] !== 1): ?><form method="post" action="<?= $escape($baseUrl) ?>/learning/complete"><input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>"><input type="hidden" name="lesson_id" value="<?= (int) $lesson['lesson_id'] ?>"><button class="button" type="submit" data-complete-button><?= $media['rule_applies'] ? 'Complete lesson' : 'Mark lesson complete' ?></button><?php if ($media['rule_applies']): ?><span class="learning-actions-hint" data-unlock-hint>Unlocks after <?= (int) $media['percent'] ?>% watched (<?= $escape($media['required_label']) ?>)</span><?php endif; ?></form><?php endif; ?><span class="learning-completed-label" data-completed-label<?= (int) $lesson['completed'] === 1 ? '' : ' hidden' ?>>✓ Lesson completed</span></div>
  </article>
</section>