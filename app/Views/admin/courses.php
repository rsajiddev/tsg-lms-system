<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $v): string => Security::escape((string) $v);
$courses = $courses ?? [];
$students = $students ?? [];
$message = $message ?? null;

$totalCourses = count($courses);
$publishedCourses = count(array_filter($courses, static fn(array $c): bool => ($c['visibility'] ?? '') === 'published'));
$totalLessons = (int) array_sum(array_column($courses, 'lesson_count'));
$totalStudents = count($students);
?>

<section class="dashboard-page admin-workspace" data-reveal>
    <header class="admin-header">
        <div class="admin-header-main">
            <p class="eyebrow">ADMIN WORKSPACE</p>
            <h1>Course management</h1>
            <p>Create course records, build curriculum modules and draft lessons, and manage verified student access.</p>
        </div>
        <a class="button button-secondary" href="<?= $escape($baseUrl) ?>/dashboard">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <line x1="19" y1="12" x2="5" y2="12"></line>
                <polyline points="12 19 5 12 12 5"></polyline>
            </svg>
            Back to dashboard
        </a>
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

                <div class="form-actions">
                    <button class="button" type="submit">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                            <polyline points="17 21 17 13 7 13 7 21"></polyline>
                            <polyline points="7 3 7 8 15 8"></polyline>
                        </svg>
                        Save lesson draft
                    </button>
                </div>
            </form>
        </section>
    </div>

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
            <div class="admin-table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th scope="col">Course Details</th>
                            <th scope="col">Visibility</th>
                            <th scope="col">Modules</th>
                            <th scope="col">Lessons</th>
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

            <div class="admin-form-row-4">
                <div class="admin-field">
                    <label class="admin-label" for="access-student">
                        Verified student <span class="req">*</span>
                    </label>
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

                <div class="admin-field">
                    <label class="admin-label" for="access-student-email">
                        Student email <span class="req">*</span>
                    </label>
                    <div class="input-with-icon">
                        <span class="field-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                                <polyline points="22,6 12,13 2,6"></polyline>
                            </svg>
                        </span>
                        <input class="admin-input icon-padded" id="access-student-email" type="email" readonly placeholder="student@example.com" value="<?= !empty($students) ? $escape($students[0]['email']) : '' ?>">
                    </div>
                </div>

                <div class="admin-field">
                    <label class="admin-label" for="access-course">
                        Target course <span class="req">*</span>
                    </label>
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

                <div class="admin-field">
                    <label class="admin-label" for="access-action">
                        Action permission <span class="req">*</span>
                    </label>
                    <select class="admin-select" id="access-action" name="access_action">
                        <option value="grant">Grant course access</option>
                        <option value="revoke">Revoke access</option>
                    </select>
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
