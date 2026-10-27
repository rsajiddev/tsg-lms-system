<?php

use App\Core\Security;

$user = isset($user) && is_array($user) ? $user : [];
$student = isset($student) && is_array($student) ? $student : [];
$admin = isset($admin) && is_array($admin) ? $admin : [];
$baseUrl = isset($baseUrl) && is_string($baseUrl) ? rtrim($baseUrl, '/') : rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $value): string => Security::escape((string) $value);
$dateLabel = static function (?string $value): string {
    if (!$value) return '—';
    $timestamp = strtotime($value);
    return $timestamp === false ? '—' : date('M j, Y', $timestamp);
};
$money = static fn(mixed $amount, mixed $currency): string => $escape((string) $currency . ' ' . number_format((float) $amount, 2));
?>
<section class="dashboard-page" data-reveal>
    <div class="dashboard-topbar">
        <button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
        <div class="dashboard-breadcrumb"><span>Workspace</span><i>/</i><strong><?= $user['role'] === 'admin' ? 'Admin dashboard' : 'Student dashboard' ?></strong></div>
        <label class="dashboard-search"><span aria-hidden="true">⌕</span><input type="search" data-dashboard-search placeholder="Search dashboard..." aria-label="Search dashboard content"></label>
    </div>
    <div class="dashboard-heading">
        <div>
            <p class="eyebrow"><?= $user['role'] === 'admin' ? 'ADMIN WORKSPACE' : 'YOUR LEARNING SPACE' ?></p>
            <h1>Welcome, <?= $escape($user['name'] ?? 'User') ?></h1>
            <p><?= $user['role'] === 'admin'
                    ? 'A live overview of students, courses, payments, and learning activity.'
                    : 'Track your course access and lesson progress here. Use the sidebar to open payments, quizzes, assignments, and notifications.' ?></p>
        </div>
    </div>

    <?php if ($user['role'] === 'admin'): ?>
        <div class="dashboard-metrics" aria-label="Admin summary">
            <article class="dashboard-metric"><span class="dashboard-metric-icon">01</span><span class="dashboard-metric-label">Student accounts</span><strong><?= number_format((int) ($admin['studentCount'] ?? 0)) ?></strong><small>Registered student accounts</small></article>
            <article class="dashboard-metric"><span class="dashboard-metric-icon">02</span><span class="dashboard-metric-label">Published courses</span><strong><?= number_format((int) ($admin['publishedCourseCount'] ?? 0)) ?></strong><small>Visible in the public catalogue</small></article>
            <article class="dashboard-metric dashboard-metric-warn"><span class="dashboard-metric-icon">03</span><span class="dashboard-metric-label">Payments to review</span><strong><?= number_format((int) ($admin['pendingPaymentCount'] ?? 0)) ?></strong><small>Awaiting transaction verification</small></article>
            <article class="dashboard-metric"><span class="dashboard-metric-icon">04</span><span class="dashboard-metric-label">Quizzes to prepare</span><strong><?= number_format((int) ($admin['awaitingQuizCount'] ?? 0)) ?></strong><small>Draft or scheduled quizzes</small></article>
        </div>

        <div class="dashboard-columns">
            <section class="dashboard-panel dashboard-searchable" id="payments">
                <div class="dashboard-panel-heading">
                    <div>
                        <p class="eyebrow">PAYMENT ACTIVITY</p>
                        <h2>Recent payments</h2>
                    </div><span class="dashboard-live-label"><i></i> Live data</span>
                </div>
                <?php if (($admin['recentPayments'] ?? []) === []): ?>
                    <div class="dashboard-empty"><span class="dashboard-empty-icon">₨</span>
                        <div>
                            <h3>No payments yet</h3>
                            <p>Student payment submissions will appear here when they are recorded.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="dashboard-table-wrap">
                        <table class="dashboard-table">
                            <thead>
                                <tr>
                                    <th>Student / course</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Received</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($admin['recentPayments'] as $payment): ?>
                                    <tr>
                                        <td><strong><?= $escape($payment['student_name']) ?></strong><small><?= $escape($payment['course_title']) ?></small></td>
                                        <td><?= $money($payment['amount'], $payment['currency']) ?></td>
                                        <td><span class="dashboard-status status-<?= $escape($payment['status']) ?>"><?= $escape(ucfirst($payment['status'])) ?></span></td>
                                        <td><?= $escape($dateLabel($payment['created_at'] ?? null)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section class="dashboard-panel dashboard-searchable" id="quizzes">
                <div class="dashboard-panel-heading">
                    <div>
                        <p class="eyebrow">ASSESSMENT ACTIVITY</p>
                        <h2>Recent quiz attempts</h2>
                    </div>
                </div>
                <?php if (($admin['recentAttempts'] ?? []) === []): ?>
                    <div class="dashboard-empty"><span class="dashboard-empty-icon">✓</span>
                        <div>
                            <h3>No quiz attempts yet</h3>
                            <p>Student attempts and results will show here after quizzes are available.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="dashboard-list">
                        <?php foreach ($admin['recentAttempts'] as $attempt): ?>
                            <article class="dashboard-list-row"><span class="dashboard-list-icon">Q</span>
                                <div class="dashboard-list-copy"><strong><?= $escape($attempt['quiz_title']) ?></strong><small><?= $escape($attempt['student_name']) ?> · <?= $escape($attempt['course_title']) ?></small></div><span class="dashboard-status status-<?= $escape($attempt['status']) ?>"><?= $escape(ucwords(str_replace('_', ' ', $attempt['status']))) ?></span>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="assessment-report-link"><a class="dashboard-text-link" href="<?= $escape($baseUrl) ?>/admin/quiz-reports">See report <span aria-hidden="true">→</span></a></div>
            </section>
        </div>
        <div class="dashboard-note"><strong>Workspace overview</strong><span>Use the sidebar to manage courses, assignments, quizzes, announcements, payment reviews, reviews, and security settings. This dashboard reflects records already stored in the database.</span></div>

    <?php else: ?>
        <div class="dashboard-metrics dashboard-metrics-student" aria-label="Your learning summary">
            <article class="dashboard-metric"><span class="dashboard-metric-icon">01</span><span class="dashboard-metric-label">My courses</span><strong><?= number_format((int) ($student['activeCourseCount'] ?? 0)) ?></strong><small>Courses assigned to your account</small></article>
            <article class="dashboard-metric"><span class="dashboard-metric-icon">02</span><span class="dashboard-metric-label">Pending payments</span><strong><?= number_format((int) ($student['pendingPaymentCount'] ?? 0)) ?></strong><small>Awaiting admin verification</small></article>
            <article class="dashboard-metric"><span class="dashboard-metric-icon">03</span><span class="dashboard-metric-label">Completed lessons</span><strong><?= number_format((int) ($student['completedLessonCount'] ?? 0)) ?></strong><small>Lessons completed across your courses</small></article>
        </div>

        <div class="dashboard-columns dashboard-student-overview">
            <section class="dashboard-panel dashboard-searchable" id="my-courses">
                <div class="dashboard-panel-heading">
                    <div>
                        <p class="eyebrow">YOUR ENROLMENT</p>
                        <h2>My courses</h2>
                    </div><a class="dashboard-text-link" href="<?= $escape($baseUrl) ?>/courses">Browse catalogue →</a>
                </div>
                <?php if (($student['courses'] ?? []) === []): ?>
                    <div class="dashboard-empty"><span class="dashboard-empty-icon">＋</span>
                        <div>
                            <h3>Your learning starts here</h3>
                            <p>You do not have a course access grant yet. Browse the catalogue to explore available courses.</p><a class="dashboard-inline-link" href="<?= $escape($baseUrl) ?>/courses">Explore published courses →</a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="dashboard-course-list">
                        <?php foreach ($student['courses'] as $course):
                            $lessons = (int) $course['lesson_count'];
                            $completed = (int) $course['completed_lessons'];
                            $progress = $lessons > 0 ? min(100, (int) round(($completed / $lessons) * 100)) : 0;
                            $courseImageFrameClass = 'dashboard-course-image';
                        ?>
                            <article class="dashboard-course-card">
                                <?php require BASE_PATH . '/app/Views/components/course-image.php'; ?>
                                <div class="dashboard-course-top"><span class="dashboard-status status-active">Access active</span></div>
                                <p class="dashboard-course-category"><?= $escape($course['category'] ?: 'Academy course') ?></p>
                                <h3><?= $escape($course['title']) ?></h3>
                                <p class="dashboard-course-meta"><?= $escape($course['level_label'] ?: 'All levels') ?><?= $course['duration_label'] ? ' · ' . $escape($course['duration_label']) : '' ?></p>
                                <div class="dashboard-progress-label"><span>Lesson progress</span><strong><?= $lessons > 0 ? $progress . '%' : 'Not started' ?></strong></div>
                                <div class="dashboard-progress" role="progressbar" aria-label="<?= $escape($course['title']) ?> lesson progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $progress ?>"><span style="width: <?= $progress ?>%"></span></div>
                                <small class="dashboard-course-foot"><?= $lessons > 0 ? number_format($completed) . ' of ' . number_format($lessons) . ' lessons completed' : 'Course lessons are being prepared' ?></small>
                                <a class="dashboard-inline-link" href="<?= $escape($baseUrl) ?>/learning?course_id=<?= (int) $course['id'] ?>">Open course →</a>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

        </div>
    <?php endif; ?>

</section>
