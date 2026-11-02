<?php

use App\Core\Security;

/**
 * View::render() injects these values from CourseAdminController::lessons().
 * @var array<int, array<string, mixed>> $lessons
 * @var array<int, array<string, mixed>> $courses
 * @var array<string, mixed> $metrics
 * @var int $watchPercent
 * @var int $watchMinPercent
 * @var int $watchMaxPercent
 */
$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $value): string => Security::escape((string) $value);
$lessons = is_array($lessons ?? null) ? $lessons : [];
$courses = is_array($courses ?? null) ? $courses : [];
$metrics = is_array($metrics ?? null) ? $metrics : [];
$videosAvailable = isset($videosAvailable) && (bool) $videosAvailable;
$watchAvailable = isset($watchAvailable) && (bool) $watchAvailable;
$watchPercent = max(0, (int) ($watchPercent ?? 90));
$watchMin = max(1, (int) ($watchMinPercent ?? 10));
$watchMax = max($watchMin, (int) ($watchMaxPercent ?? 100));
$search = is_string($search ?? null) ? $search : '';
$courseId = (int) ($courseId ?? 0);
$status = is_string($status ?? null) ? $status : 'all';
$videoFilter = is_string($videoFilter ?? null) ? $videoFilter : 'all';
$total = max(0, (int) ($total ?? 0));
$page = max(1, (int) ($page ?? 1));
$pages = max(1, (int) ($pages ?? 1));
$message = $message ?? null;
$error = $error ?? null;

$addedLabel = static function (?string $value): string {
    if ($value === null || $value === '') {
        return '—';
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? '—' : date('M j, Y', $timestamp);
};
$durationLabel = static fn(mixed $seconds): string => \App\Services\LessonMedia::formatDuration($seconds === null ? null : (int) $seconds);
$providerLabels = ['file' => 'Uploaded file', 'youtube' => 'YouTube', 'vimeo' => 'Vimeo', 'embed' => 'Embedded player', 'none' => 'No video'];
$queryUrl = static function (array $overrides = []) use ($search, $courseId, $status, $videoFilter): string {
    $query = http_build_query(array_merge([
        'q' => $search,
        'course_id' => $courseId ?: null,
        'status' => $status !== 'all' ? $status : null,
        'video' => $videoFilter !== 'all' ? $videoFilter : null,
    ], $overrides));
    return $query;
};
$filtersActive = $search !== '' || $courseId > 0 || $status !== 'all' || $videoFilter !== 'all';
?>
<section class="dashboard-page lesson-library-page" data-reveal>
    <div class="dashboard-topbar">
        <button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
        <div class="dashboard-breadcrumb"><a href="<?= $escape($baseUrl) ?>/dashboard">Workspace</a><i>/</i><strong>Lessons</strong></div>
        <form class="dashboard-search" method="get" action="<?= $escape($baseUrl) ?>/admin/lessons" role="search">
            <span aria-hidden="true">⌕</span>
            <input type="search" name="q" value="<?= $escape($search) ?>" placeholder="Search lesson, module or course" aria-label="Search lessons">
            <input type="hidden" name="course_id" value="<?= $courseId ?: '' ?>">
            <input type="hidden" name="status" value="<?= $escape($status) ?>">
            <input type="hidden" name="video" value="<?= $escape($videoFilter) ?>">
        </form>
    </div>

    <header class="dashboard-heading">
        <div>
            <p class="eyebrow">ADMIN WORKSPACE</p>
            <h1>Lesson library</h1>
            <p>Every lesson across every course in one searchable list, with its publish state, recorded video and completion reach.</p>
        </div>
        <a class="button" href="#lesson-add">Add a lesson</a>
    </header>

    <?php \App\Core\View::toast('success', $message ?? null); ?>
    <?php \App\Core\View::toast('error', $error ?? null); ?>
    <?php if (!$videosAvailable): ?><div class="admin-alert course-image-migration-notice" role="status"><strong>Lesson video columns are missing from this database.</strong><span>Back up your database, then import the updated <code>database/migrations/001_initial_schema.sql</code>. Lessons still appear here; video fields unlock after the import.</span></div><?php endif; ?>

    <section class="admin-card lesson-lib-panel-card dashboard-summary-card" aria-labelledby="lesson-lib-metrics-title">
        <div class="lesson-lib-panel-head dashboard-summary-heading">
            <div>
                <p class="eyebrow">AT A GLANCE</p>
                <h2 id="lesson-lib-metrics-title">Library metrics</h2>
            </div>
            <span class="count-pill">Reflects active filters</span>
        </div>
        <div class="lesson-lib-metrics-grid" aria-label="Lesson totals for the current filters">
            <div class="lesson-lib-metric">
                <div class="admin-stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg></div>
                <div class="lesson-lib-metric-body"><span class="admin-stat-value" data-count="<?= (int) ($metrics['total'] ?? 0) ?>"><?= number_format((int) ($metrics['total'] ?? 0)) ?></span><span class="admin-stat-label">Lessons found</span></div>
            </div>
            <div class="lesson-lib-metric">
                <div class="admin-stat-icon green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg></div>
                <div class="lesson-lib-metric-body"><span class="admin-stat-value" data-count="<?= (int) ($metrics['published'] ?? 0) ?>"><?= number_format((int) ($metrics['published'] ?? 0)) ?></span><span class="admin-stat-label">Published to students</span></div>
            </div>
            <div class="lesson-lib-metric">
                <div class="admin-stat-icon purple"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2.5" y="5" width="19" height="14" rx="3"/><path d="m10 9.5 5 2.5-5 2.5z"/></svg></div>
                <div class="lesson-lib-metric-body"><span class="admin-stat-value" data-count="<?= (int) ($metrics['with_video'] ?? 0) ?>"><?= number_format((int) ($metrics['with_video'] ?? 0)) ?></span><span class="admin-stat-label">Lessons with a video</span></div>
            </div>
            <div class="lesson-lib-metric">
                <div class="admin-stat-icon amber"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></div>
                <div class="lesson-lib-metric-body"><span class="admin-stat-value" data-count="<?= (int) ($metrics['missing_length'] ?? 0) ?>"><?= number_format((int) ($metrics['missing_length'] ?? 0)) ?></span><span class="admin-stat-label">Videos missing a length</span></div>
            </div>
        </div>
    </section>

    <!-- Authoring form: the list below is the read view of exactly these records. -->
    <section class="admin-card lesson-add-card" id="lesson-add">
        <div class="admin-card-header">
            <div class="admin-card-title-group">
                <div class="admin-card-icon violet">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="12" y1="18" x2="12" y2="12"></line>
                        <line x1="9" y1="15" x2="15" y2="15"></line>
                    </svg>
                </div>
                <div class="admin-card-titles">
                    <h2>Add lesson</h2>
                    <p>Attach modules and draft lessons to existing courses</p>
                </div>
            </div>
        </div>

        <form method="post" action="<?= $escape($baseUrl) ?>/admin/courses/lessons/save" class="admin-form">
            <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">

            <div class="admin-form-row">
                <div class="admin-field">
                    <label class="admin-label" for="lesson-course">Target course <span class="req">*</span></label>
                    <select class="admin-select" id="lesson-course" name="course_id" required>
                        <?php if ($courses === []): ?>
                            <option value="" disabled selected>No courses available (create one first)</option>
                        <?php else: ?>
                            <?php foreach ($courses as $index => $course): ?>
                                <option value="<?= (int) $course['id'] ?>" <?= $index === 0 ? 'selected' : '' ?>><?= $escape($course['title']) ?> (<?= $escape($course['slug'] ?? '') ?>)</option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <span class="admin-hint">The lesson is published inside this course's curriculum.</span>
                </div>

                <div class="admin-field">
                    <label class="admin-label" for="module-title">Module title <span class="req">*</span></label>
                    <input class="admin-input" id="module-title" name="module_title" required maxlength="200" placeholder="e.g. Module 1: Foundations &amp; Architecture">
                    <span class="admin-hint">Matches existing modules automatically or creates a new sequential section.</span>
                </div>
            </div>

            <div class="admin-form-row">
                <div class="admin-field">
                    <label class="admin-label" for="lesson-title">Lesson title <span class="req">*</span></label>
                    <input class="admin-input" id="lesson-title" name="lesson_title" required maxlength="200" placeholder="e.g. Lesson 1: Introduction to Web Standards">
                </div>

                <div class="admin-field">
                    <label class="admin-label" for="lesson-status">Publishing status</label>
                    <select class="admin-select" id="lesson-status" name="status">
                        <option value="draft" selected>Draft — visible to admins only</option>
                        <option value="published">Published — available to students with course access</option>
                    </select>
                </div>
            </div>

            <div class="admin-field">
                <label class="admin-label" for="lesson-body">Lesson content</label>
                <textarea class="admin-textarea" id="lesson-body" name="body" rows="4" placeholder="Draft lecture summary, reading materials, code snippets, or video links..."></textarea>
            </div>

            <?php if ($videosAvailable): ?>
                <div class="admin-form-row">
                    <div class="admin-field">
                        <label class="admin-label" for="lesson-video-url">Lesson video link</label>
                        <input class="admin-input" id="lesson-video-url" name="video_url" maxlength="600" placeholder="https://www.youtube.com/watch?v=... or https://cdn.example.com/lesson.mp4">
                        <span class="admin-hint">Direct .mp4 / .webm files track the playhead exactly. YouTube and Vimeo links are re-written to an embed player.</span>
                    </div>

                    <div class="admin-field">
                        <label class="admin-label" for="lesson-video-duration">Video length</label>
                        <input class="admin-input" id="lesson-video-duration" name="video_duration" maxlength="8" placeholder="e.g. 18:30">
                        <span class="admin-hint">Needed for the watch-time rule. Accepts seconds, mm:ss or h:mm:ss.</span>
                    </div>
                </div>
            <?php endif; ?>

            <div class="form-actions">
                <button class="button" type="submit">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                        <polyline points="17 21 17 13 7 13 7 21"></polyline>
                        <polyline points="7 3 7 8 15 8"></polyline>
                    </svg>
                    Save lesson
                </button>
                <span class="form-action-note">Saved lessons appear in the library below and can be edited there without leaving this page. The counters above always reflect the active filters.</span>
            </div>
        </form>
    </section>

    <!-- Completion rule for the videos listed below, kept here because only this page manages them. -->
    <section class="admin-card lesson-rule-card">
        <div class="admin-card-header">
            <div class="admin-card-title-group">
                <div class="admin-card-icon amber">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="9"></circle>
                        <polyline points="12 7 12 12 15 14"></polyline>
                    </svg>
                </div>
                <div class="admin-card-titles">
                    <h2>Watch-time rule</h2>
                    <p>The share of a lesson video a student must watch before the lesson can be marked complete. It only applies to lessons that have a registered length.</p>
                </div>
            </div>
            <span class="count-pill">Currently <?= $watchPercent ?>%</span>
        </div>

        <form method="post" action="<?= $escape($baseUrl) ?>/admin/lessons/watch-rule" class="admin-form">
            <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
            <div class="admin-setting-row">
                <div class="admin-setting-row-text">
                    <span class="admin-label">Lesson watch requirement</span>
                    <span class="admin-hint">Saved changes apply to the next heartbeat a student sends, without editing any lesson.</span>
                </div>
                <div class="admin-setting-row-control">
                    <div class="admin-number-field">
                        <input class="admin-input" type="number" name="lesson_watch_completion_percent" min="<?= $watchMin ?>" max="<?= $watchMax ?>" required value="<?= $watchPercent ?>" aria-label="Lesson watch requirement in percent">
                        <span class="admin-number-unit">%</span>
                    </div>
                </div>
            </div>
            <div class="form-actions">
                <button class="button" type="submit">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                        <polyline points="17 21 17 13 7 13 7 21"></polyline>
                        <polyline points="7 3 7 8 15 8"></polyline>
                    </svg>
                    Save watch rule
                </button>
                <span class="form-action-note">A lower percentage lets students complete lessons sooner; the completion timestamps already recorded are never recalculated.</span>
            </div>
        </form>
    </section>

    <section class="dashboard-panel lesson-lib-panel" aria-label="Lesson list">
        <div class="dashboard-panel-heading lesson-lib-heading">
            <div>
                <p class="eyebrow"><?= $filtersActive ? 'FILTERED RESULTS' : 'ALL COURSES' ?></p>
                <h2><?= number_format($total) ?> lesson<?= $total === 1 ? '' : 's' ?></h2>
            </div>
            <a class="button button-secondary" href="<?= $escape($baseUrl) ?>/admin/courses">Manage courses</a>
        </div>

        <form class="lesson-lib-filters" method="get" action="<?= $escape($baseUrl) ?>/admin/lessons">
            <label class="lesson-lib-search-field">Search
                <input class="admin-input" type="search" name="q" value="<?= $escape($search) ?>" placeholder="Lesson, module or course title">
            </label>
            <label>Course
                <select class="admin-select" name="course_id">
                    <option value="0">All courses</option>
                    <?php foreach ($courses as $course): ?><option value="<?= (int) $course['id'] ?>" <?= $courseId === (int) $course['id'] ? 'selected' : '' ?>><?= $escape($course['title']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Status
                <select class="admin-select" name="status">
                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Draft and published</option>
                    <option value="published" <?= $status === 'published' ? 'selected' : '' ?>>Published</option>
                    <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft</option>
                </select>
            </label>
            <?php if ($videosAvailable): ?><label>Video
                <select class="admin-select" name="video">
                    <option value="all" <?= $videoFilter === 'all' ? 'selected' : '' ?>>Any video state</option>
                    <option value="with" <?= $videoFilter === 'with' ? 'selected' : '' ?>>Has a video</option>
                    <option value="without" <?= $videoFilter === 'without' ? 'selected' : '' ?>>No video</option>
                    <option value="no-length" <?= $videoFilter === 'no-length' ? 'selected' : '' ?>>Video without length</option>
                </select>
            </label><?php endif; ?>
            <div class="lesson-lib-filter-actions"><button class="button" type="submit">Apply filters</button><?php if ($filtersActive): ?><a class="button button-secondary" href="<?= $escape($baseUrl) ?>/admin/lessons">Clear</a><?php endif; ?></div>
        </form>

        <?php if ($lessons === []): ?>
            <div class="dashboard-empty lesson-lib-empty">
                <span class="dashboard-empty-icon" aria-hidden="true">◷</span>
                <div>
                    <h3><?= $filtersActive ? 'No lesson matches these filters' : 'No lessons have been written yet' ?></h3>
                    <p><?= $filtersActive ? 'Try a different course, status or video filter.' : 'Lessons live inside course modules. Add the first one with the form at the top of this page.' ?></p>
                </div>
            </div>
        <?php else: ?>
            <div class="admin-table-wrapper">
                <table class="admin-table lesson-lib-table">
                    <thead>
                        <tr>
                            <th scope="col">Lesson</th>
                            <th scope="col">Status</th>
                            <th scope="col">Video</th>
                            <th scope="col">Completion</th>
                            <th scope="col">Added</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lessons as $lesson):
                            $lessonId = (int) $lesson['id'];
                            $videoUrl = is_string($lesson['video_url'] ?? null) && $lesson['video_url'] !== '' ? $lesson['video_url'] : null;
                            $provider = (string) ($lesson['video_provider'] ?? 'none');
                            $duration = $lesson['video_duration_seconds'] === null ? null : (int) $lesson['video_duration_seconds'];
                            $enrolled = max(0, (int) $lesson['enrolled_count']);
                            $completed = max(0, (int) $lesson['completed_count']);
                            $rate = $enrolled > 0 ? (int) round($completed / $enrolled * 100) : 0;
                        ?>
                            <tr>
                                <td data-label="Lesson">
                                    <div class="lesson-lib-identity">
                                        <strong><?= $escape($lesson['title']) ?></strong>
                                        <small><?= $escape($lesson['course_title']) ?> · <?= $escape($lesson['module_title']) ?> · position <?= (int) $lesson['sort_order'] ?></small>
                                    </div>
                                </td>
                                <td data-label="Status"><span class="table-badge <?= $lesson['status'] === 'published' ? 'published' : 'draft' ?>"><?= $escape(ucfirst((string) $lesson['status'])) ?></span></td>
                                <td data-label="Video">
                                    <?php if ($videoUrl === null): ?>
                                        <span class="lesson-lib-video-none">No video</span>
                                    <?php else: ?>
                                        <div class="lesson-lib-video">
                                            <strong><?= $escape($providerLabels[$provider] ?? 'Linked video') ?></strong>
                                            <?php if ($duration !== null): ?>
                                                <small><?= $escape($durationLabel($duration)) ?> · watch rule needs <?= $watchPercent ?>%</small>
                                            <?php else: ?>
                                                <small class="lesson-lib-video-warning">Length not set — completion stays manual</small>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Completion">
                                    <div class="lesson-lib-reach">
                                        <span class="lesson-lib-reach-text"><strong><?= number_format($completed) ?></strong> of <?= number_format($enrolled) ?> enrolled</span>
                                        <span class="lesson-lib-reach-track" style="--lesson-fill: <?= $rate ?>%" role="img" aria-label="<?= $rate ?>% of enrolled students completed this lesson"><span class="lesson-lib-reach-fill"></span></span>
                                        <span class="lesson-lib-reach-value"><?= $enrolled > 0 ? $rate . '%' : '—' ?></span>
                                    </div>
                                    <?php if ($watchAvailable && (int) $lesson['watching_count'] > 0): ?><small class="lesson-lib-watching"><?= number_format((int) $lesson['watching_count']) ?> student<?= (int) $lesson['watching_count'] === 1 ? '' : 's' ?> part-way through</small><?php endif; ?>
                                </td>
                                <td data-label="Added"><small><?= $escape($addedLabel($lesson['created_at'] ?? null)) ?></small></td>
                                <td data-label="Actions" class="lesson-lib-actions"><a class="dashboard-inline-link" href="#lesson-<?= $lessonId ?>">Edit lesson <span aria-hidden="true">↓</span></a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($pages > 1): ?>
                <nav class="lesson-lib-pagination" aria-label="Lesson pages">
                    <span>Page <?= $page ?> of <?= $pages ?></span>
                    <div>
                        <?php if ($page > 1): ?><a class="button button-secondary" href="<?= $escape($baseUrl) ?>/admin/lessons?<?= $escape($queryUrl(['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
                        <?php if ($page < $pages): ?><a class="button" href="<?= $escape($baseUrl) ?>/admin/lessons?<?= $escape($queryUrl(['page' => $page + 1])) ?>">Next</a><?php endif; ?>
                    </div>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <!-- Inline editors for the lessons listed above. They used to sit on the course page, which
         meant opening every course accordion to find one lesson. -->
    <section class="admin-card lesson-editor-card">
        <div class="admin-card-header">
            <div class="admin-card-title-group">
                <div class="admin-card-icon violet">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 20h9"></path>
                        <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
                    </svg>
                </div>
                <div class="admin-card-titles">
                    <h2>Lesson editing &amp; publishing</h2>
                    <p>Edit lesson content or change whether students can access it. This list follows the filters above — <a class="dashboard-inline-link" href="<?= $escape($baseUrl) ?>/admin/lessons">clear them</a> to reach every lesson.</p>
                </div>
            </div>
            <span class="count-pill"><?= count($lessons) ?> <?= count($lessons) === 1 ? 'lesson' : 'lessons' ?></span>
        </div>
        <?php if ($lessons === []): ?>
            <div class="admin-empty-state">
                <h3>No lessons to edit</h3>
                <p>Add a lesson with the form above, or clear the filters, to see the lesson editors here.</p>
            </div>
        <?php else: ?>
            <div class="lesson-library-list">
                <?php foreach ($lessons as $lesson): ?>
                    <details class="lesson-editor" id="lesson-<?= (int) $lesson['id'] ?>">
                        <summary><span><strong><?= $escape($lesson['title']) ?></strong><small><?= $escape($lesson['course_title']) ?> · <?= $escape($lesson['module_title']) ?><?php if ($videosAvailable && !empty($lesson['video_url'])): ?> · video <?= $lesson['video_duration_seconds'] !== null ? $escape($durationLabel((int) $lesson['video_duration_seconds'])) : 'length not set' ?><?php endif; ?></small></span><span class="table-badge <?= $lesson['status'] === 'published' ? 'published' : 'draft' ?>"><?= $escape(ucfirst((string) $lesson['status'])) ?></span></summary>
                        <form method="post" action="<?= $escape($baseUrl) ?>/admin/courses/lessons/save" class="admin-form">
                            <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
                            <input type="hidden" name="lesson_id" value="<?= (int) $lesson['id'] ?>">
                            <input type="hidden" name="course_id" value="<?= (int) $lesson['course_id'] ?>">
                            <input type="hidden" name="module_id" value="<?= (int) $lesson['module_id'] ?>">
                            <input type="hidden" name="module_title" value="<?= $escape($lesson['module_title']) ?>">
                            <label class="admin-label">Lesson title<input class="admin-input" name="lesson_title" required maxlength="200" value="<?= $escape($lesson['title']) ?>"></label>
                            <label class="admin-label">Lesson content<textarea class="admin-textarea" name="body" rows="5" maxlength="500000"><?= $escape($lesson['body'] ?? '') ?></textarea></label>
                            <?php if ($videosAvailable): ?>
                                <label class="admin-label">Lesson video link<input class="admin-input" name="video_url" maxlength="600" value="<?= $escape($lesson['video_url'] ?? '') ?>" placeholder="https://www.youtube.com/watch?v=... or https://cdn.example.com/lesson.mp4"></label>
                                <label class="admin-label">Video length<input class="admin-input" name="video_duration" maxlength="8" value="<?= $escape($lesson['video_duration_seconds'] !== null && $lesson['video_duration_seconds'] !== '' ? $durationLabel((int) $lesson['video_duration_seconds']) : '') ?>" placeholder="18:30"><span class="admin-hint">Seconds, mm:ss or h:mm:ss. Leave empty when the length is unknown — the lesson then stays manually completable.</span></label>
                            <?php endif; ?>
                            <label class="admin-label">Publishing status<select class="admin-select" name="status">
                                    <option value="draft" <?= $lesson['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
                                    <option value="published" <?= $lesson['status'] === 'published' ? 'selected' : '' ?>>Published</option>
                                </select></label>
                            <button class="button" type="submit">Save lesson changes</button>
                        </form>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

  <?php
  // Testing tool: the lesson library is where draft content piles up fastest while a course is
  // being written, so it gets the same guarded bulk delete as assignments and quizzes.
  $purgeAction = '/admin/lessons/purge';
  $purgePhrase = 'DELETE ALL LESSONS';
  $purgeTitle = 'Delete all lessons';
  $purgeDescription = 'Removes every lesson with its module placement and the student watch progress recorded against it.';
  $purgeScopeCourses = $courses;
  require BASE_PATH . '/app/Views/components/purge-zone.php';
  ?>

    <!-- The walkthrough always closes the page, below the bulk delete card. -->
    <?php
    $usageEyebrow = 'Lesson library';
    $usageTitle = 'How to use the Lesson library';
    $usageLead = 'A quick walkthrough of the workflow this page manages end to end.';
    $usageBullets = [
        'Author in drafts first. Every lesson is saved as a draft and stays invisible to students until its status is switched to Published.',
        'Modules are matched by title. Reusing an existing module title adds the lesson to that section; a new title creates the next sequential module in the course.',
        'Register a video length. Direct .mp4 / .webm files track the playhead exactly, while YouTube and Vimeo links are re-written to an embed player — the length is required either way.',
        'Videos without a length stay manual. Students complete those lessons by hand, and the “Videos missing a length” counter above lists everything that still needs fixing.',
        'The watch-time rule applies course-wide. It sets the share of a video a student must watch before the lesson auto-completes, and takes effect from the next heartbeat a student sends.',
        'Filters drive the whole page. Search, course, status and video filters update the counters, the lesson table and the inline editors below it in one pass.',
        'Track completion reach. The completion column compares finished students against enrollments, so weak spots in a module are easy to spot.',
    ];
    require BASE_PATH . '/app/Views/components/usage-guide.php';
    ?>
</section>
