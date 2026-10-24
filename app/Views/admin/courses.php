<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $v): string => Security::escape((string) $v);
$courses = isset($courses) && is_array($courses) ? $courses : [];
$lessons = isset($lessons) && is_array($lessons) ? $lessons : [];
$students = isset($students) && is_array($students) ? $students : [];
$message = $message ?? null;

$totalCourses = count($courses);
$publishedCourses = count(array_filter($courses, static fn(array $c): bool => ($c['visibility'] ?? '') === 'published'));
$totalLessons = (int) array_sum(array_column($courses, 'lesson_count'));
$totalStudents = count($students);
?>

<section class="dashboard-page admin-workspace" data-reveal>
    <header class="admin-header">
        <div class="admin-header-main">
            <button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
            <p class="eyebrow">ADMIN WORKSPACE</p>
            <h1>Course management</h1>
            <p>Create course records, build curriculum modules and draft lessons, and manage verified student access.</p>
        </div>
    </header>

    <?php if ($message): ?>
        <div class="admin-alert admin-alert-success" role="status">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                <polyline points="22 4 12 14.01 9 11.01"></polyline>
            </svg>
            <span><?= $escape($message) ?></span>
        </div>
    <?php endif; ?>

    <!-- Summary KPI Metrics -->
    <div class="admin-stats-grid" aria-label="Course and Enrollment Overview">
        <article class="admin-stat-card">
            <div class="admin-stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                    <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                </svg>
            </div>
            <div class="admin-stat-body">
                <span class="admin-stat-value" data-count="<?= $totalCourses ?>"><?= $totalCourses ?></span>
                <span class="admin-stat-label">Total courses</span>
            </div>
        </article>

        <article class="admin-stat-card">
            <div class="admin-stat-icon green">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 16 14"></polyline>
                </svg>
            </div>
            <div class="admin-stat-body">
                <span class="admin-stat-value" data-count="<?= $publishedCourses ?>"><?= $publishedCourses ?></span>
                <span class="admin-stat-label">Published catalog</span>
            </div>
        </article>

        <article class="admin-stat-card">
            <div class="admin-stat-icon purple">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polygon points="12 2 2 7 12 12 22 7 12 2"></polygon>
                    <polyline points="2 17 12 22 22 17"></polyline>
                    <polyline points="2 12 12 17 22 12"></polyline>
                </svg>
            </div>
            <div class="admin-stat-body">
                <span class="admin-stat-value" data-count="<?= $totalLessons ?>"><?= $totalLessons ?></span>
                <span class="admin-stat-label">Active lessons</span>
            </div>
        </article>

        <article class="admin-stat-card">
            <div class="admin-stat-icon amber">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                </svg>
            </div>
            <div class="admin-stat-body">
                <span class="admin-stat-value" data-count="<?= $totalStudents ?>"><?= $totalStudents ?></span>
                <span class="admin-stat-label">Verified students</span>
            </div>
        </article>
    </div>

    <!-- 2-Column Section: Create Course & Add Lesson -->
    <div class="admin-grid-2">
        <!-- Create Course Card -->
        <section class="admin-card">
            <div class="admin-card-header">
                <div class="admin-card-title-group">
                    <div class="admin-card-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M12 20h9"></path>
                            <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
                        </svg>
                    </div>
                    <div class="admin-card-titles">
                        <h2>Create course</h2>
                        <p>Set up title, URL slug, and public visibility</p>
                    </div>
                </div>
            </div>

            <form method="post" action="<?= $escape($baseUrl) ?>/admin/courses/save" class="admin-form">
                <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">

                <div class="admin-field">
                    <label class="admin-label" for="course-title">
                        Course title <span class="req">*</span>
                    </label>
                    <input class="admin-input" id="course-title" name="title" required minlength="3" maxlength="200" placeholder="e.g. Full Stack Web Development">
                </div>

                <div class="admin-field">
                    <label class="admin-label" for="course-slug">
                        URL slug <span class="req">*</span>
                    </label>
                    <div class="slug-input-wrapper">
                        <span class="slug-prefix">/courses/</span>
                        <input class="admin-input" id="course-slug" name="slug" required pattern="[a-z0-9]+(-[a-z0-9]+)*" placeholder="full-stack-web-development">
                    </div>
                    <span class="admin-hint">Use lowercase letters, numbers, and hyphens only (e.g. graphic-design-basics).</span>
                </div>

                <div class="admin-form-row">
                    <div class="admin-field">
                        <label class="admin-label" for="course-category">
                            Category
                        </label>
                        <input class="admin-input" id="course-category" name="category" maxlength="100" placeholder="e.g. Web Development">
                    </div>

                    <div class="admin-field">
                        <label class="admin-label" for="course-visibility">
                            Visibility <span class="req">*</span>
                        </label>
                        <select class="admin-select" id="course-visibility" name="visibility">
                            <option value="draft">Draft (Private)</option>
                            <option value="published" selected>Published (Public)</option>
                            <option value="hidden">Hidden (Direct URL)</option>
                        </select>
                    </div>
                </div>

                <div class="admin-form-row">
                    <div class="admin-field"><label class="admin-label" for="course-duration">Duration</label><input class="admin-input" id="course-duration" name="duration_label" maxlength="100" placeholder="e.g. 3 Months"></div>
                    <div class="admin-field"><label class="admin-label" for="course-level">Level</label><input class="admin-input" id="course-level" name="level_label" maxlength="50" placeholder="e.g. Beginner"></div>
                </div>

                <div class="admin-form-row">
                    <div class="admin-field"><label class="admin-label" for="course-recorded-price">Recorded Only price (PKR) <span class="req">*</span></label><input class="admin-input" id="course-recorded-price" name="recorded_price" type="number" min="0" step="0.01" required value="0"></div>
                    <div class="admin-field"><label class="admin-label" for="course-live-price">Live + Recorded price (PKR) <span class="req">*</span></label><input class="admin-input" id="course-live-price" name="live_price" type="number" min="0" step="0.01" required value="0"></div>
                </div>

                <div class="admin-field">
                    <label class="admin-label" for="course-short-description">Card summary</label>
                    <textarea class="admin-textarea" id="course-short-description" name="short_description" rows="2" maxlength="500" placeholder="Short description shown on home and course cards..."></textarea>
                </div>

                <div class="admin-field">
                    <label class="admin-label" for="course-description">
                        Description
                    </label>
                    <textarea class="admin-textarea" id="course-description" name="description" rows="3" placeholder="Brief outline of syllabus, core competencies, and career outcomes..."></textarea>
                </div>

                <div class="form-actions">
                    <button class="button" type="submit">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <line x1="12" y1="5" x2="12" y2="19"></line>
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                        </svg>
                        Create course
                    </button>
                </div>
            </form>
        </section>

        <!-- Add Lesson Card -->
        <section class="admin-card">
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

                <div class="admin-field">
                    <label class="admin-label" for="lesson-course">
                        Target course <span class="req">*</span>
                    </label>
                    <select class="admin-select" id="lesson-course" name="course_id" required>
                        <?php if (empty($courses)): ?>
                            <option value="" disabled selected>No courses available (create one first)</option>
                        <?php else: ?>
                            <?php foreach ($courses as $index => $course): ?>
                                <option value="<?= (int) $course['id'] ?>" <?= $index === 0 ? 'selected' : '' ?>>
                                    <?= $escape($course['title']) ?> (<?= $escape($course['slug']) ?>)
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="admin-field">
                    <label class="admin-label" for="module-title">
                        Module title <span class="req">*</span>
                    </label>
                    <input class="admin-input" id="module-title" name="module_title" required maxlength="200" placeholder="e.g. Module 1: Foundations & Architecture">
                    <span class="admin-hint">Matches existing modules automatically or creates a new sequential section.</span>
                </div>

                <div class="admin-field">
                    <label class="admin-label" for="lesson-title">
                        Lesson title <span class="req">*</span>
                    </label>
                    <input class="admin-input" id="lesson-title" name="lesson_title" required maxlength="200" placeholder="e.g. Lesson 1: Introduction to Web Standards">
                </div>

                <div class="admin-field">
                    <label class="admin-label" for="lesson-body">
                        Lesson content
                    </label>
                    <textarea class="admin-textarea" id="lesson-body" name="body" rows="4" placeholder="Draft lecture summary, reading materials, code snippets, or video links..."></textarea>
                </div>

                <div class="admin-field">
                    <label class="admin-label" for="lesson-status">Publishing status</label>
                    <select class="admin-select" id="lesson-status" name="status">
                        <option value="draft" selected>Draft — visible to admins only</option>
                        <option value="published">Published — available to students with course access</option>
                    </select>
                </div>

                <div class="form-actions">
                    <button class="button" type="submit">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                            <polyline points="17 21 17 13 7 13 7 21"></polyline>
                            <polyline points="7 3 7 8 15 8"></polyline>
                        </svg>
                        Save lesson
                    </button>
                </div>
            </form>
        </section>
    </div>

    <!-- Lesson editing and publishing -->
    <section class="admin-card lesson-library">
        <div class="admin-card-header">
            <div class="admin-card-title-group">
                <div class="admin-card-icon violet"><span aria-hidden="true">▤</span></div>
                <div class="admin-card-titles"><h2>Lessons &amp; publishing</h2><p>Edit lesson content or change whether students can access it.</p></div>
            </div>
            <span class="count-pill"><?= count($lessons) ?> <?= count($lessons) === 1 ? 'lesson' : 'lessons' ?></span>
        </div>
        <?php if ($lessons === []): ?>
            <div class="admin-empty-state"><h3>No lessons yet</h3><p>Create a lesson above to start building course content.</p></div>
        <?php else: ?>
            <div class="lesson-library-list">
                <?php foreach ($lessons as $lesson): ?>
                    <details class="lesson-editor">
                        <summary><span><strong><?= $escape($lesson['title']) ?></strong><small><?= $escape($lesson['course_title']) ?> · <?= $escape($lesson['module_title']) ?></small></span><span class="table-badge <?= $lesson['status'] === 'published' ? 'published' : 'draft' ?>"><?= $escape(ucfirst($lesson['status'])) ?></span></summary>
                        <form method="post" action="<?= $escape($baseUrl) ?>/admin/courses/lessons/save" class="admin-form">
                            <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
                            <input type="hidden" name="lesson_id" value="<?= (int) $lesson['id'] ?>">
                            <input type="hidden" name="course_id" value="<?= (int) $lesson['course_id'] ?>">
                            <input type="hidden" name="module_id" value="<?= (int) $lesson['module_id'] ?>">
                            <input type="hidden" name="module_title" value="<?= $escape($lesson['module_title']) ?>">
                            <label class="admin-label">Lesson title<input class="admin-input" name="lesson_title" required maxlength="200" value="<?= $escape($lesson['title']) ?>"></label>
                            <label class="admin-label">Lesson content<textarea class="admin-textarea" name="body" rows="5" maxlength="500000"><?= $escape($lesson['body'] ?? '') ?></textarea></label>
                            <label class="admin-label">Publishing status<select class="admin-select" name="status"><option value="draft" <?= $lesson['status'] === 'draft' ? 'selected' : '' ?>>Draft</option><option value="published" <?= $lesson['status'] === 'published' ? 'selected' : '' ?>>Published</option></select></label>
                            <button class="button" type="submit">Save lesson changes</button>
                        </form>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- Courses Table Section -->
    <section class="admin-card">
        <div class="admin-card-header">
            <div class="admin-card-title-group">
                <div class="admin-card-icon emerald">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <line x1="8" y1="6" x2="21" y2="6"></line>
                        <line x1="8" y1="12" x2="21" y2="12"></line>
                        <line x1="8" y1="18" x2="21" y2="18"></line>
                        <line x1="3" y1="6" x2="3.01" y2="6"></line>
                        <line x1="3" y1="12" x2="3.01" y2="12"></line>
                        <line x1="3" y1="18" x2="3.01" y2="18"></line>
                    </svg>
                </div>
                <div class="admin-card-titles">
                    <h2>Academy courses</h2>
                    <p>Current directory of registered courses, catalog status, and lesson structure</p>
                </div>
            </div>
            <span class="count-pill"><?= $totalCourses ?> <?= $totalCourses === 1 ? 'course' : 'courses' ?></span>
        </div>

        <?php if (empty($courses)): ?>
            <div class="admin-empty-state">
                <div class="admin-empty-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                </div>
                <h3>No courses created yet</h3>
                <p>Get started by using the form above to create your first academy course record.</p>
            </div>
        <?php else: ?>
            <div class="admin-table-wrapper" id="admin-course-table">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th scope="col">Course Details</th>
                            <th scope="col">Visibility</th>
                            <th scope="col">Modules</th>
                            <th scope="col">Lessons</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($courses as $course): ?>
                            <tr>
                                <td>
                                    <div class="table-course-item">
                                        <div class="table-course-icon">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                                                <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                                            </svg>
                                        </div>
                                        <div class="table-course-info">
                                            <strong><?= $escape($course['title']) ?></strong>
                                            <small><?= $escape($course['slug']) ?><?php if (!empty($course['category'])): ?> &bull; <?= $escape($course['category']) ?><?php endif; ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                    $vis = strtolower((string) ($course['visibility'] ?? 'draft'));
                                    ?>
                                    <span class="table-badge <?= $vis === 'published' ? 'published' : ($vis === 'draft' ? 'draft' : 'hidden') ?>">
                                        <?= $escape(ucfirst($vis)) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="count-pill">
                                        <?= (int) $course['module_count'] ?> <?= (int) $course['module_count'] === 1 ? 'module' : 'modules' ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="count-pill">
                                        <?= (int) $course['lesson_count'] ?> <?= (int) $course['lesson_count'] === 1 ? 'lesson' : 'lessons' ?>
                                    </span>
                                </td>
                                <td class="admin-table-actions">
                                    <a class="admin-edit-trigger" href="#course-edit-<?= (int) $course['id'] ?>" data-admin-modal-open="course-edit-<?= (int) $course['id'] ?>">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M12 20h9"></path>
                                            <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
                                        </svg>
                                        Edit course
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <!-- Student Course Access Section -->
    <section class="admin-card">
        <div class="admin-card-header">
            <div class="admin-card-title-group">
                <div class="admin-card-icon indigo">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                    </svg>
                </div>
                <div class="admin-card-titles">
                    <h2>Student course access</h2>
                    <p>Grant or revoke course enrollment permissions for active verified students</p>
                </div>
            </div>
        </div>

        <form method="post" action="<?= $escape($baseUrl) ?>/admin/courses/access" class="admin-form">
            <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">

            <div class="admin-field-list">
                <div class="admin-field-row">
                    <div class="admin-field-row-text">
                        <span class="admin-label">Verified student <span class="req">*</span></span>
                        <span class="admin-hint">Only active learners with a confirmed email address can be selected.</span>
                    </div>
                    <div class="admin-field-row-control">
                        <select class="admin-select" id="access-student" name="student_id" required>
                            <?php if (empty($students)): ?>
                                <option value="" disabled selected>No active verified students</option>
                            <?php else: ?>
                                <?php foreach ($students as $index => $student): ?>
                                    <option value="<?= (int) $student['id'] ?>" data-email="<?= $escape($student['email']) ?>" <?= $index === 0 ? 'selected' : '' ?>>
                                        <?= $escape($student['full_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>

                <div class="admin-field-row">
                    <div class="admin-field-row-text">
                        <span class="admin-label">Student email <span class="req">*</span></span>
                        <span class="admin-hint">Filled in automatically from the selected student.</span>
                    </div>
                    <div class="admin-field-row-control">
                        <div class="input-with-icon">
                            <span class="field-icon">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                                    <polyline points="22,6 12,13 2,6"></polyline>
                                </svg>
                            </span>
                            <input class="admin-input icon-padded" id="access-student-email" type="email" readonly placeholder="student@example.com" value="<?= !empty($students) ? $escape($students[0]['email']) : '' ?>">
                        </div>
                    </div>
                </div>

                <div class="admin-field-row">
                    <div class="admin-field-row-text">
                        <span class="admin-label">Target course <span class="req">*</span></span>
                        <span class="admin-hint">The exact course whose access is granted or revoked.</span>
                    </div>
                    <div class="admin-field-row-control">
                        <select class="admin-select" id="access-course" name="course_id" required>
                            <?php if (empty($courses)): ?>
                                <option value="" disabled selected>No courses available</option>
                            <?php else: ?>
                                <?php foreach ($courses as $course): ?>
                                    <option value="<?= (int) $course['id'] ?>">
                                        <?= $escape($course['title']) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>

                <div class="admin-field-row">
                    <div class="admin-field-row-text">
                        <span class="admin-label">Action permission <span class="req">*</span></span>
                        <span class="admin-hint">Granting opens the course immediately, revoking closes it again.</span>
                    </div>
                    <div class="admin-field-row-control">
                        <select class="admin-select" id="access-action" name="access_action">
                            <option value="grant">Grant course access</option>
                            <option value="revoke">Revoke access</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="access-banner">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="16" x2="12" y2="12"></line>
                    <line x1="12" y1="8" x2="12.01" y2="8"></line>
                </svg>
                <span>Granting access immediately authorizes the student to view curriculum lessons and take course quizzes. Revoking access halts lesson permissions without wiping past test submissions.</span>
            </div>

            <div class="form-actions">
                <button class="button" type="submit">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                        <circle cx="8.5" cy="7" r="4"></circle>
                        <polyline points="17 11 19 13 23 9"></polyline>
                    </svg>
                    Update access
                </button>
            </div>
        </form>
    </section>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var studentSelect = document.getElementById('access-student');
        var emailInput = document.getElementById('access-student-email');
        if (studentSelect && emailInput) {
            var syncStudentEmail = function () {
                var selected = studentSelect.options[studentSelect.selectedIndex];
                if (selected && selected.getAttribute('data-email')) {
                    emailInput.value = selected.getAttribute('data-email');
                } else {
                    emailInput.value = '';
                }
            };
            studentSelect.addEventListener('change', syncStudentEmail);
            syncStudentEmail();
        }
    });
    </script>
</section>

<!-- Course editor overlays live outside the reveal-animated section so their
     fixed positioning is always measured against the viewport. -->
<?php foreach ($courses as $course):
    $courseId = (int) $course['id'];
    $modalVisibility = strtolower((string) ($course['visibility'] ?? 'draft'));
    ?>
    <div class="admin-modal" id="course-edit-<?= $courseId ?>" data-admin-modal role="dialog" aria-modal="true" aria-labelledby="course-edit-<?= $courseId ?>-title">
        <a class="admin-modal-backdrop" href="#admin-course-table" data-admin-modal-close aria-label="Close the course editor"></a>
        <div class="admin-modal-dialog">
            <div class="admin-modal-header">
                <div class="admin-card-title-group">
                    <div class="admin-card-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M12 20h9"></path>
                            <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
                        </svg>
                    </div>
                    <div class="admin-card-titles">
                        <p class="eyebrow">EDIT COURSE</p>
                        <h2 id="course-edit-<?= $courseId ?>-title"><?= $escape($course['title']) ?></h2>
                    </div>
                </div>
                <a class="admin-modal-close" href="#admin-course-table" data-admin-modal-close aria-label="Close the course editor">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </a>
            </div>

            <form method="post" action="<?= $escape($baseUrl) ?>/admin/courses/save" class="admin-form admin-modal-body">
                <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
                <input type="hidden" name="id" value="<?= $courseId ?>">

                <div class="admin-form-row">
                    <div class="admin-field">
                        <label class="admin-label" for="course-edit-<?= $courseId ?>-title-field">Title <span class="req">*</span></label>
                        <input class="admin-input" id="course-edit-<?= $courseId ?>-title-field" name="title" required maxlength="200" value="<?= $escape($course['title']) ?>">
                    </div>
                    <div class="admin-field">
                        <label class="admin-label" for="course-edit-<?= $courseId ?>-slug">URL slug <span class="req">*</span></label>
                        <div class="slug-input-wrapper">
                            <span class="slug-prefix">/courses/</span>
                            <input class="admin-input" id="course-edit-<?= $courseId ?>-slug" name="slug" required pattern="[a-z0-9]+(-[a-z0-9]+)*" value="<?= $escape($course['slug']) ?>">
                        </div>
                    </div>
                </div>

                <div class="admin-form-row">
                    <div class="admin-field">
                        <label class="admin-label" for="course-edit-<?= $courseId ?>-category">Category</label>
                        <input class="admin-input" id="course-edit-<?= $courseId ?>-category" name="category" maxlength="100" value="<?= $escape($course['category'] ?? '') ?>">
                    </div>
                    <div class="admin-field">
                        <label class="admin-label" for="course-edit-<?= $courseId ?>-visibility">Visibility <span class="req">*</span></label>
                        <select class="admin-select" id="course-edit-<?= $courseId ?>-visibility" name="visibility">
                            <?php foreach (['draft' => 'Draft (Private)', 'published' => 'Published (Public)', 'hidden' => 'Hidden (Direct URL)', 'archived' => 'Archived'] as $value => $text): ?>
                                <option value="<?= $value ?>" <?= $modalVisibility === $value ? 'selected' : '' ?>><?= $text ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="admin-form-row">
                    <div class="admin-field">
                        <label class="admin-label" for="course-edit-<?= $courseId ?>-duration">Duration</label>
                        <input class="admin-input" id="course-edit-<?= $courseId ?>-duration" name="duration_label" maxlength="100" value="<?= $escape($course['duration_label'] ?? '') ?>">
                    </div>
                    <div class="admin-field">
                        <label class="admin-label" for="course-edit-<?= $courseId ?>-level">Level</label>
                        <input class="admin-input" id="course-edit-<?= $courseId ?>-level" name="level_label" maxlength="50" value="<?= $escape($course['level_label'] ?? '') ?>">
                    </div>
                </div>

                <div class="admin-form-row">
                    <div class="admin-field">
                        <label class="admin-label" for="course-edit-<?= $courseId ?>-recorded">Recorded Only price (PKR) <span class="req">*</span></label>
                        <input class="admin-input" id="course-edit-<?= $courseId ?>-recorded" name="recorded_price" type="number" min="0" step="0.01" required value="<?= $escape($course['recorded_price'] ?? '0') ?>">
                    </div>
                    <div class="admin-field">
                        <label class="admin-label" for="course-edit-<?= $courseId ?>-live">Live + Recorded price (PKR) <span class="req">*</span></label>
                        <input class="admin-input" id="course-edit-<?= $courseId ?>-live" name="live_price" type="number" min="0" step="0.01" required value="<?= $escape($course['live_price'] ?? '0') ?>">
                    </div>
                </div>

                <div class="admin-field">
                    <label class="admin-label" for="course-edit-<?= $courseId ?>-summary">Card summary</label>
                    <textarea class="admin-textarea" id="course-edit-<?= $courseId ?>-summary" name="short_description" rows="2" maxlength="500" placeholder="Short description shown on home and course cards..."><?= $escape($course['short_description'] ?? '') ?></textarea>
                </div>

                <div class="admin-field">
                    <label class="admin-label" for="course-edit-<?= $courseId ?>-description">Description</label>
                    <textarea class="admin-textarea" id="course-edit-<?= $courseId ?>-description" name="description" rows="4" placeholder="Brief outline of syllabus, core competencies, and career outcomes..."><?= $escape($course['description'] ?? '') ?></textarea>
                </div>

                <div class="form-actions">
                    <a class="button button-ghost" href="#admin-course-table" data-admin-modal-close>Cancel</a>
                    <button class="button" type="submit">Save course details</button>
                </div>
            </form>
        </div>
    </div>
<?php endforeach; ?>
