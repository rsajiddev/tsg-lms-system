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
$viewEndpoint = $escape((string) ($baseUrl ?? '')) . '/learning/lesson/view';
$watermark = isset($watermark) && is_array($watermark) ? $watermark : [];
$watermark += ['enabled' => false, 'name' => '', 'reference' => '—', 'time' => '', 'trace' => '000000', 'label' => ''];
// null unless this enrolment is on the Live + Recorded plan and the lesson holds a class address.
// The controller decided that; the template only prints what it was handed.
$liveClass = isset($liveClass) && is_array($liveClass) ? $liveClass : null;
$recordedOnlyLiveLesson = !empty($recordedOnlyLiveLesson);
// meeting_at is a naive UTC instant, so it is read on the UTC clock and printed on the UTC clock.
// Two students in two countries therefore read the same start time, and it is labelled as what it is.
$classLabel = static function (?string $value): string {
    if ($value === null || $value === '') return '';
    $stamp = strtotime($value . ' UTC');
    return $stamp === false ? '' : gmdate('D, M j, Y, g:i A', $stamp) . ' UTC';
};
// Drawn only while it is switched on. An overlay is absolutely positioned, so leaving it in the
// page and hiding it would need a [hidden] override of its own for no benefit.
$watermarkOverlay = static function () use ($escape, $watermark): string {
    if (!$watermark['enabled'] || trim((string) $watermark['label']) === '') return '';
    // Two copies of the same mark, one solid in the corner and one faint across the other, because a
    // crop that removes one still leaves the other to identify the recording.
    $line = '<b>' . $escape((string) $watermark['label']) . '</b>'
        . '<i><span data-wm-time>' . $escape((string) $watermark['time']) . '</span> · <span data-wm-trace>' . $escape((string) $watermark['trace']) . '</span></i>';
    return '<div class="lesson-watermark" data-watermark-overlay aria-hidden="true">'
        . '<span class="lesson-watermark-mark lesson-watermark-strong">' . $line . '</span>'
        . '<span class="lesson-watermark-mark lesson-watermark-faint">' . $line . '</span>'
        . '</div>';
};
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
  <?php \App\Core\View::toast('success', $message ?? null); ?>
  <?php \App\Core\View::toast('error', $error ?? null); ?>
  <?php /* The completion button comes back here on a redirect, so the confirmation rides the query. */ ?>
  <?php if (isset($_GET['completed'])): ?><?php \App\Core\View::toast('success', 'Lesson marked as complete.'); ?><?php endif; ?>
  <?php if ($liveClass !== null): ?>
    <?php $classWhen = $classLabel($liveClass['at'] ?? null); ?>
    <article class="dashboard-panel lesson-live-class">
      <div class="lesson-live-class-copy">
        <p class="eyebrow"><?= ($liveClass['platform'] ?? null) === 'zoom' ? 'ZOOM LIVE CLASS' : (($liveClass['platform'] ?? null) === 'google_meet' ? 'GOOGLE MEET LIVE CLASS' : 'LIVE CLASS') ?></p>
        <h2>Live session for this lesson</h2>
        <?php if ($classWhen === ''): ?>
          <p>The Academy has not set a start time for this class, so check your course announcements for when it begins.<?php if ($media['has_video']): ?> The recording below stays available either way.<?php endif; ?></p>
        <?php elseif (strtotime((string) $liveClass['at'] . ' UTC') <= time()): ?>
          <p>This class was scheduled for <?= $escape($classWhen) ?>, so its time has passed.<?php if ($media['has_video']): ?> The recording below covers what was taught in it.<?php else: ?> Review the lesson content below.<?php endif; ?></p>
        <?php else: ?>
          <p>This lesson runs live at <?= $escape($classWhen) ?>. The button opens the meeting in a new tab.<?php if ($media['has_video']): ?> The recording below is waiting for you whatever time you join.<?php endif; ?></p>
        <?php endif; ?>
      </div>
      <a class="button" href="<?= $escape($liveClass['url']) ?>" target="_blank" rel="noopener noreferrer">Open live class</a>
    </article>
  <?php endif; ?>
  <?php if ($media['has_video']): ?>
    <article class="dashboard-panel lesson-player<?= $media['trackable'] ? ' lesson-player-file' : ' lesson-player-embed' ?>" data-lesson-player
             data-endpoint="<?= $playerEndpoint ?>"
             data-view-endpoint="<?= $viewEndpoint ?>"
             data-lesson-id="<?= (int) $lesson['lesson_id'] ?>"
             data-csrf="<?= $escape(Security::csrfToken()) ?>"
             data-heartbeat="<?= (int) $media['heartbeat'] ?>"
             data-duration="<?= (int) $media['duration'] ?>"
             data-position="<?= (int) $media['position'] ?>"
             data-trackable="<?= $media['trackable'] ? '1' : '0' ?>"
             data-watermark="<?= $watermark['enabled'] && trim((string) $watermark['label']) !== '' ? '1' : '0' ?>"
             data-student-label="<?= $escape((string) $watermark['label']) ?>"
             data-trace="<?= $escape((string) $watermark['trace']) ?>"
             data-completed="<?= ($media['completed'] || (int) $lesson['completed'] === 1) ? '1' : '0' ?>">
      <?php if ($media['trackable'] && $media['embed_url']): ?>
        <?php /* The shell is the fullscreen target: a <video> cannot hold a child overlay, and full-screening the element alone would leave the watermark behind on the page. */ ?>
        <div class="lesson-video-shell" data-video-shell>
          <video class="lesson-video" controls controlsList="nodownload" disablepictureinpicture preload="metadata" playsinline src="<?= $media['embed_url'] ?>">
            Your browser cannot play this video. <a href="<?= $media['embed_url'] ?>">Open the recording directly</a>.
          </video>
          <?= $watermarkOverlay() ?>
        </div>
      <?php elseif ($media['embed_url']): ?>
        <div class="lesson-video-shell" data-video-shell>
          <div class="lesson-video-frame">
            <iframe class="lesson-iframe" src="<?= $media['embed_url'] ?>" title="<?= $escape($lesson['lesson_title']) ?> lesson video" loading="lazy" allow="accelerometer; autoplay; clipboard-write" referrerpolicy="strict-origin-when-cross-origin"></iframe>
          </div>
          <?= $watermarkOverlay() ?>
        </div>
      <?php endif; ?>
      <div class="lesson-watch">
        <div class="lesson-watch-head">
          <span class="lesson-watch-title"><?= $media['duration'] ? 'Watch progress' : 'Time watched' ?></span>
          <strong class="lesson-watch-text" data-watch-text><?= $escape($media['watched_label']) ?><?= $media['duration'] ? ' / ' . $escape($media['duration_label']) : '' ?></strong>
        </div>
        <div class="lesson-watch-track" role="progressbar" aria-label="Lesson video watched" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) round($media['ratio']) ?>"><span class="lesson-watch-fill" data-watch-fill style="--watch-width: <?= max(0, min(100, (float) $media['ratio'])) ?>%"></span></div>
        <p class="lesson-watch-note" data-watch-note><?php
            if ($recordedOnlyLiveLesson) {
                echo 'This is a live class. Its recording will appear here for your plan after the Academy adds it.';
            } elseif ((int) $lesson['completed'] === 1) {
                echo 'You have completed this lesson.';
            } elseif ($media['rule_applies']) {
                echo 'Watch ' . (int) $media['percent'] . '% of this recording (' . $escape($media['required_label']) . ') to unlock completion.';
            } else {
                echo 'This recording has no registered length, so the lesson is completed manually below.';
            }
        ?></p>
      </div>
      <?php if (!$media['trackable']): ?>
        <p class="lesson-player-hint"><?= $escape(ucfirst($media['provider'])) ?> videos cannot report their playhead, so watched time is measured while this tab stays open and active. Their own fullscreen and picture-in-picture controls are switched off here, so the account mark stays on screen.</p>
      <?php endif; ?>
      <?php /* Filled by public/assets/js/main.js when the player refuses something. A line inside the card
              rather than a floating toast, because the toast overlay is printed by PHP and has no script-side
              injector, and a warning about the recording in front of the student belongs beside it. No display
              rule is set here, so the browser's own [hidden] handling is what keeps it out of the way. */ ?>
      <p class="lesson-protection-note" data-protection-note hidden></p>
    </article>
  <?php endif; ?>
  <?php if ($recordedOnlyLiveLesson): ?><p class="learning-plan-note">This lesson is scheduled as a live class. Your Recorded Only access will include it once the recording is added.</p><?php endif; ?>
  <article class="dashboard-panel learning-content"><?php if (trim((string) ($lesson['body'] ?? '')) !== ''): ?><div class="learning-body"><?= nl2br($escape($lesson['body'])) ?></div><?php else: ?><div class="dashboard-empty">
        <div>
          <h2>Lesson content is being prepared</h2>
          <p>Your course team has not added the lesson materials yet.</p>
        </div>
      </div><?php endif; ?><div class="learning-actions"><a class="button button-secondary" href="<?= $escape($baseUrl) ?>/learning?course_id=<?= (int) $lesson['course_id'] ?>">Back to course</a><?php if ((int) $lesson['completed'] !== 1 && !$recordedOnlyLiveLesson): ?><form method="post" action="<?= $escape($baseUrl) ?>/learning/complete"><input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>"><input type="hidden" name="lesson_id" value="<?= (int) $lesson['lesson_id'] ?>"><button class="button" type="submit" data-complete-button><?= $media['rule_applies'] ? 'Complete lesson' : 'Mark lesson complete' ?></button><?php if ($media['rule_applies']): ?><span class="learning-actions-hint" data-unlock-hint>Unlocks after <?= (int) $media['percent'] ?>% watched (<?= $escape($media['required_label']) ?>)</span><?php endif; ?></form><?php endif; ?><span class="learning-completed-label" data-completed-label<?= (int) $lesson['completed'] === 1 ? '' : ' hidden' ?>>✓ Lesson completed</span></div>
  </article>
</section>
