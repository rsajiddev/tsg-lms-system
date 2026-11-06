<?php

use App\Core\Security;

/**
 * Student Certificate & Learning Progress dashboard, rendered by StudentPortalController::progress().
 *
 * The numbers all come from StudentProgress, which measures them exactly the way the certificate gate
 * does - so the picture a student sees here can never drift away from whether the "Request certificate"
 * action on the course page is actually open to them.
 *
 * @var array<string, mixed> $summary
 * @var array<string, mixed> $user
 */
$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $value): string => Security::escape((string) $value);
$summary = is_array($summary ?? null) ? $summary : [];
$user = is_array($user ?? null) ? $user : [];
$student = is_array($summary['student'] ?? null) ? $summary['student'] : ['name' => '', 'id' => '', 'email' => ''];
$overall = is_array($summary['overall'] ?? null) ? $summary['overall'] : [];
$criteria = is_array($summary['criteria'] ?? null) ? $summary['criteria'] : ['grade' => 50, 'attendance' => 75];
$courses = is_array($summary['courses'] ?? null) ? array_values($summary['courses']) : [];
$flashMessage = is_string($message ?? null) && $message !== '' ? $message : null;
$flashError = is_string($error ?? null) && $error !== '' ? $error : null;

$num = static function (mixed $value, int $decimals = 1): string {
    if ($value === null || $value === '') {
        return '—';
    }
    return rtrim(rtrim(number_format((float) $value, $decimals, '.', ''), '0'), '.');
};
$pct = static function (mixed $value): int {
    return max(0, min(100, (int) round((float) $value)));
};
$formatDate = static function (?string $value): string {
    if (!$value) {
        return '—';
    }
    $timestamp = strtotime($value . ' UTC');
    return $timestamp === false ? '—' : date('M j, Y', $timestamp);
};

$studentId = trim((string) ($student['id'] ?? ''));
$completionPercent = $pct($overall['completion_percent'] ?? 0);
$weightedPercent = $pct($overall['weighted_percent'] ?? 0);
$eligibleCourses = (int) ($overall['courses_eligible'] ?? 0);
$issuedCertificates = (int) ($overall['certificates_issued'] ?? 0);
?>
<section class="dashboard-page progress-page" data-reveal>
    <div class="dashboard-topbar">
        <button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
        <div class="dashboard-breadcrumb"><a href="<?= $escape($baseUrl) ?>/dashboard">Workspace</a><i>/</i><strong>Certificate &amp; progress</strong></div>
    </div>

    <header class="dashboard-heading">
        <div>
            <p class="eyebrow">YOUR LEARNING PROGRESS</p>
            <h1>Certificate &amp; progress</h1>
            <p>How far you have come in every course, and exactly what a certificate still needs from you.</p>
        </div>
        <a class="button" href="<?= $escape($baseUrl) ?>/student/progress/report">↓ Download progress report (PDF)</a>
    </header>

    <?php \App\Core\View::toast('success', $flashMessage); ?>
    <?php \App\Core\View::toast('error', $flashError); ?>

    <section class="dashboard-panel progress-overall-panel">
        <div class="dashboard-panel-heading">
            <div>
                <p class="eyebrow">OVERALL PROGRESS</p>
                <h2>Where you stand</h2>
            </div>
            <?php if ($studentId !== ''): ?><span class="progress-student-id">Student ID <strong><?= $escape($studentId) ?></strong></span><?php endif; ?>
        </div>

        <div class="dashboard-metrics" aria-label="Overall progress">
            <article class="dashboard-metric"><span class="dashboard-metric-icon">01</span><span class="dashboard-metric-label">Enrolled courses</span><strong><?= number_format((int) ($overall['enrolled'] ?? 0)) ?></strong><small>Courses with active access</small></article>
            <article class="dashboard-metric"><span class="dashboard-metric-icon">02</span><span class="dashboard-metric-label">Completed</span><strong><?= number_format((int) ($overall['completed'] ?? 0)) ?></strong><small>All lessons finished</small></article>
            <article class="dashboard-metric"><span class="dashboard-metric-icon">03</span><span class="dashboard-metric-label">In progress</span><strong><?= number_format((int) ($overall['in_progress'] ?? 0)) ?></strong><small>Still being worked through</small></article>
            <article class="dashboard-metric"><span class="dashboard-metric-icon">04</span><span class="dashboard-metric-label">Certificates issued</span><strong><?= number_format($issuedCertificates) ?></strong><small>Officially awarded to you</small></article>
        </div>

        <div class="progress-bars">
            <div class="progress-bar-block">
                <div class="dashboard-progress-label"><span>Overall lesson completion</span><strong><?= $completionPercent ?>%</strong></div>
                <div class="dashboard-progress" role="progressbar" aria-label="Overall lesson completion" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $completionPercent ?>"><span style="width: <?= $completionPercent ?>%"></span></div>
            </div>
            <div class="progress-bar-block">
                <div class="dashboard-progress-label"><span>Average weighted grade</span><strong><?= $weightedPercent ?>%</strong></div>
                <div class="dashboard-progress" role="progressbar" aria-label="Average weighted grade" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $weightedPercent ?>"><span style="width: <?= $weightedPercent ?>%"></span></div>
            </div>
        </div>

        <?php if (($overall['enrolled'] ?? 0) > 0): ?>
            <div class="progress-eligibility-banner <?= $eligibleCourses > 0 ? 'is-ready' : 'is-not-ready' ?>">
                <span class="progress-eligibility-mark" aria-hidden="true"><?= $eligibleCourses > 0 ? '✓' : '⏳' ?></span>
                <div>
                    <strong><?= $eligibleCourses > 0
                        ? $eligibleCourses . ' of ' . (int) $overall['enrolled'] . ' course' . ((int) $overall['enrolled'] === 1 ? '' : 's') . ' ready for a certificate'
                        : 'No course has reached certificate level yet' ?></strong>
                    <p>Each course below lists the exact requirements left to complete. Certificates are reviewed and issued by the academy team.</p>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($courses === []): ?>
        <section class="dashboard-panel">
            <div class="dashboard-empty"><span class="dashboard-empty-icon">＋</span>
                <div>
                    <h3>Your progress starts here</h3>
                    <p>You are not enrolled in a course with active access yet. Choose a course from the catalogue, and once an administrator verifies your payment your lessons, quizzes and progress will begin to appear on this page.</p>
                    <a class="dashboard-inline-link" href="<?= $escape($baseUrl) ?>/courses">Browse courses →</a>
                </div>
            </div>
        </section>
    <?php else: ?>
        <?php foreach ($courses as $course):
            $courseId = (int) ($course['course_id'] ?? 0);
            $state = (string) ($course['state'] ?? 'in-progress');
            $video = is_array($course['video'] ?? null) ? $course['video'] : ['total' => 0, 'watched' => 0, 'remaining' => 0, 'percent' => 0];
            $quizzes = is_array($course['quizzes'] ?? null) ? $course['quizzes'] : ['total' => 0, 'attempted' => 0, 'pending' => 0, 'passed' => 0, 'obtained_percent' => 0, 'pass_percent' => 0, 'final_best' => null, 'final_passed' => null];
            $assignments = is_array($course['assignments'] ?? null) ? $course['assignments'] : ['total' => 0, 'submitted' => 0, 'reviewed' => 0, 'pending' => 0];
            $modules = is_array($course['modules'] ?? null) ? $course['modules'] : [];
            $grade = is_array($course['grade'] ?? null) ? $course['grade'] : ['overall_percent' => null, 'letter' => '—'];
            $certificate = is_array($course['certificate'] ?? null) ? $course['certificate'] : ['status' => '', 'id' => '', 'expires_at' => null, 'format' => null];
            $elig = is_array($course['eligibility'] ?? null) ? $course['eligibility'] : ['ok' => false, 'overall' => 0, 'remaining' => 0, 'requirements' => [], 'min_grade' => $criteria['grade'], 'min_attendance' => $criteria['attendance']];
            $videoPercent = $pct($video['percent'] ?? 0);
            $certStatus = (string) ($certificate['status'] ?? '');
            $certFormat = strtolower((string) ($certificate['format'] ?? '')) === 'pdf' ? 'PDF' : 'JPG';
            $overallPct = $grade['overall_percent'] === null ? null : $pct($grade['overall_percent']);
        ?>
            <section class="dashboard-panel progress-course">
                <div class="dashboard-panel-heading progress-course-head">
                    <div>
                        <p class="eyebrow">COURSE</p>
                        <h2><?= $escape($course['title']) ?></h2>
                        <p class="progress-course-sub"><?= $escape($course['level_label'] ?: 'All levels') ?><?= $course['duration_label'] ? ' · ' . $escape($course['duration_label']) : '' ?> · Access expires <?= $escape($formatDate($course['expires_at'] ?? null)) ?></p>
                    </div>
                    <div class="progress-course-badges">
                        <span class="dashboard-status <?= $state === 'completed' ? 'status-completed' : 'status-active' ?>"><?= $state === 'completed' ? 'Completed' : 'In progress' ?></span>
                        <?php if ($overallPct !== null): ?><span class="course-grade-badge"><?= $num($grade['overall_percent']) ?>% · <strong><?= $escape($grade['letter']) ?></strong></span><?php endif; ?>
                    </div>
                </div>

                <div class="progress-course-grid">
                    <div class="progress-modules">
                        <h3>Module-wise progress</h3>
                        <?php if ($modules === []): ?>
                            <p class="progress-muted">No published modules in this course yet.</p>
                        <?php else: ?>
                            <?php foreach ($modules as $module): $mp = $pct($module['percent'] ?? 0); ?>
                                <div class="progress-module">
                                    <div class="dashboard-progress-label"><span><?= $escape($module['title']) ?></span><strong><?= $mp ?>%</strong></div>
                                    <div class="dashboard-progress" role="progressbar" aria-label="<?= $escape($module['title']) ?> progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $mp ?>"><span style="width: <?= $mp ?>%"></span></div>
                                    <small class="progress-module-meta"><?= (int) $module['watched'] ?> of <?= (int) $module['lessons'] ?> lessons</small>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="progress-side">
                        <div class="progress-block">
                            <h3>Videos</h3>
                            <div class="progress-metric-row"><span>Watched</span><strong><?= (int) $video['watched'] ?> / <?= (int) $video['total'] ?></strong></div>
                            <div class="progress-metric-row"><span>Remaining</span><strong><?= (int) $video['remaining'] ?></strong></div>
                            <div class="dashboard-progress-label"><span>Video completion</span><strong><?= $videoPercent ?>%</strong></div>
                            <div class="dashboard-progress" role="progressbar" aria-label="Video completion" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $videoPercent ?>"><span style="width: <?= $videoPercent ?>%"></span></div>
                        </div>

                        <div class="progress-block">
                            <h3>Quizzes</h3>
                            <div class="progress-metric-row"><span>Attempted</span><strong><?= (int) $quizzes['attempted'] ?> / <?= (int) $quizzes['total'] ?></strong></div>
                            <div class="progress-metric-row"><span>Pending</span><strong><?= (int) $quizzes['pending'] ?></strong></div>
                            <div class="progress-metric-row"><span>Obtained</span><strong><?= $num($quizzes['obtained_percent']) ?>%</strong></div>
                            <div class="progress-metric-row"><span>Passing mark</span><strong><?= $num($quizzes['pass_percent']) ?>%</strong></div>
                            <div class="progress-metric-row"><span>Passed</span><strong><?= (int) $quizzes['passed'] ?> · <?= $quizzes['total'] > 0 && (int) $quizzes['passed'] >= (int) $quizzes['total'] ? 'Pass' : (((int) $quizzes['pending']) > 0 ? 'Pending' : 'Needs review') ?></strong></div>
                        </div>

                        <div class="progress-block">
                            <h3>Assignments</h3>
                            <div class="progress-metric-row"><span>Submitted</span><strong><?= (int) $assignments['submitted'] ?> / <?= (int) $assignments['total'] ?></strong></div>
                            <div class="progress-metric-row"><span>Reviewed</span><strong><?= (int) $assignments['reviewed'] ?></strong></div>
                            <div class="progress-metric-row"><span>Awaiting submission</span><strong><?= (int) $assignments['pending'] ?></strong></div>
                        </div>
                    </div>
                </div>

                <div class="progress-requirements">
                    <div class="progress-requirements-head">
                        <h3>Certificate eligibility</h3>
                        <span class="progress-requirements-count <?= ($elig['ok'] ?? false) ? 'is-done' : '' ?>"><?= ($elig['ok'] ?? false) ? 'Eligible now' : (int) ($elig['remaining'] ?? 0) . ' task' . ((int) ($elig['remaining'] ?? 0) === 1 ? '' : 's') . ' remaining' ?></span>
                    </div>
                    <ul class="progress-checklist">
                        <?php foreach (($elig['requirements'] ?? []) as $requirement): ?>
                            <li class="progress-check <?= ($requirement['met'] ?? false) ? 'is-met' : 'is-open' ?>">
                                <span class="progress-check-mark" aria-hidden="true"><?= ($requirement['met'] ?? false) ? '✓' : '○' ?></span>
                                <span class="progress-check-label"><?= $escape($requirement['label']) ?></span>
                                <span class="progress-check-detail"><?= $escape($requirement['detail']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <?php if ($certStatus === 'issued'): ?>
                        <div class="progress-certificate progress-certificate-issued">
                            <span class="certificate-chip certificate-chip-issued"><span aria-hidden="true">✓</span> Certificate issued</span>
                            <p>Certificate ID <strong><?= $escape($certificate['id']) ?></strong> · Valid until <?= $escape($formatDate($certificate['expires_at'] ?? null)) ?></p>
                            <div class="certificate-actions">
                                <a class="button button-secondary certificate-button" href="<?= $escape($baseUrl) ?>/certificate/download?cert_id=<?= $escape(rawurlencode((string) $certificate['id'])) ?>&course_id=<?= $courseId ?>">Download <?= $escape($certFormat) ?></a>
                                <a class="certificate-link" href="<?= $escape($baseUrl) ?>/verify?cert_id=<?= $escape(rawurlencode((string) $certificate['id'])) ?>" target="_blank" rel="noopener">Public verification page →</a>
                            </div>
                        </div>
                    <?php elseif ($certStatus === 'requested'): ?>
                        <div class="progress-certificate progress-certificate-pending">
                            <span class="certificate-chip certificate-chip-pending"><span aria-hidden="true">⏳</span> Awaiting review</span>
                            <p>Your request is with the academy team. You will get an email once the certificate is issued.</p>
                        </div>
                    <?php elseif ($certStatus === 'rejected'): ?>
                        <div class="progress-certificate progress-certificate-rejected">
                            <span class="certificate-chip certificate-chip-rejected"><span aria-hidden="true">✕</span> Request declined</span>
                            <p>Review the remaining tasks above, then request again from <a href="<?= $escape($baseUrl) ?>/student/courses">My courses</a>.</p>
                        </div>
                    <?php else: ?>
                        <div class="progress-certificate progress-certificate-none">
                            <p><?= ($elig['ok'] ?? false)
                                ? 'You meet every requirement — request your certificate from <a href="' . $escape($baseUrl) . '/student/courses">My courses</a>.'
                                : 'Complete the tasks above to unlock your certificate for this course.' ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="dashboard-note"><strong>How progress is measured</strong><span>The weighted grade and certificate requirements here are read live from the same records the academy reviews when it issues a certificate, so this page and your request always agree. Contact the academy team if a figure looks out of date.</span></div>
</section>
