<?php

use App\Core\Security;

/**
 * View::render() injects these values from StudentPortalController::courses().
 * @var array<int, array<string, mixed>> $courses
 * @var array{grade:int,attendance:int} $certificateCriteria
 */
$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $value): string => Security::escape((string) $value);
$user = isset($user) && is_array($user) ? $user : [];
$courses = is_array($courses ?? null) ? $courses : [];
$certificateCriteria = is_array($certificateCriteria ?? null) ? $certificateCriteria : ['grade' => 50, 'attendance' => 75];
$certificatesAvailable = (bool) ($certificatesAvailable ?? false);
$gradesAvailable = (bool) ($gradesAvailable ?? false);
$flashMessage = is_string($message ?? null) && $message !== '' ? $message : null;
$flashError = is_string($error ?? null) && $error !== '' ? $error : null;
$grantedLabel = static function (?string $value): string {
    if (!$value) {
        return '—';
    }
    $timestamp = strtotime($value . ' UTC');
    return $timestamp === false ? '—' : date('M j, Y', $timestamp);
};
?>
<section class="dashboard-page my-courses-page" data-reveal>
    <div class="dashboard-topbar">
        <button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
        <div class="dashboard-breadcrumb"><a href="<?= $escape($baseUrl) ?>/dashboard">Workspace</a><i>/</i><strong>My courses</strong></div>
    </div>

    <header class="dashboard-heading">
        <div>
            <p class="eyebrow">YOUR LEARNING SPACE</p>
            <h1>My courses</h1>
            <p>Every course your access has been granted for, with your lesson progress.</p>
        </div>
        <a class="button" href="<?= $escape($baseUrl) ?>/courses">Choose a course</a>
    </header>

    <?php \App\Core\View::toast('success', $flashMessage); ?>
    <?php \App\Core\View::toast('error', $flashError); ?>

    <?php if ($certificatesAvailable): ?>
        <details class="dashboard-panel certificate-criteria-panel">
            <summary>
                <span class="certificate-criteria-summary"><strong>What you need for a certificate</strong><small>Three requirements per course — click to see the exact bars</small></span>
                <span class="certificate-criteria-toggle" aria-hidden="true">＋</span>
            </summary>
            <div class="certificate-criteria-grid">
                <article class="certificate-criteria-item">
                    <span class="certificate-criteria-value"><?= (int) $certificateCriteria['grade'] ?>%</span>
                    <strong>Overall grade</strong>
                    <small>Weighted from lesson videos, quizzes, exercises, your project and the final test.</small>
                </article>
                <article class="certificate-criteria-item">
                    <span class="certificate-criteria-value"><?= (int) $certificateCriteria['attendance'] ?>%</span>
                    <strong>Attendance</strong>
                    <small>Present days as a share of the days decided since your access was granted.</small>
                </article>
                <article class="certificate-criteria-item">
                    <span class="certificate-criteria-value">100%</span>
                    <strong>Lesson completion</strong>
                    <small>Every published lesson in the course must be marked complete.</small>
                </article>
            </div>
            <p class="certificate-criteria-note">Request the certificate from the course card below once all three are met. The academy reviews each request before issuing.</p>
        </details>
    <?php endif; ?>

    <section class="dashboard-panel">
        <div class="dashboard-panel-heading">
            <div>
                <p class="eyebrow">ENROLLMENT</p>
                <h2><?= count($courses) ?> course<?= count($courses) === 1 ? '' : 's' ?></h2>
            </div>
        </div>
        <?php if ($courses === []): ?>
            <div class="dashboard-empty"><span class="dashboard-empty-icon">＋</span>
                <div>
                    <h3>Your learning starts here</h3>
                    <p>You do not have a course access grant yet. Pick a course from the catalogue — enrollment opens with that course already chosen, and access is granted once an administrator verifies your payment.</p><a class="dashboard-inline-link" href="<?= $escape($baseUrl) ?>/courses">Choose a course to enroll →</a>
                </div>
            </div>
        <?php else: ?>
            <div class="dashboard-course-list">
                <?php foreach ($courses as $course):
                    $lessons = (int) $course['lesson_count'];
                    $completed = (int) $course['completed_lessons'];
                    $progress = $lessons > 0 ? min(100, (int) round(($completed / $lessons) * 100)) : 0;
                    $courseImageFrameClass = 'dashboard-course-image';
                    $certificateStatus = (string) ($course['certificate_status'] ?? '');
                    $certificateNumber = (string) ($course['certificate_id'] ?? '');
                    $certificateRemarks = (string) ($course['admin_remarks'] ?? '');
                    $certificateExpiry = $course['certificate_expires_at'] ?? null;
                    $certificateExpired = $certificateStatus === 'issued' && $certificateExpiry && strtotime((string) $certificateExpiry . ' UTC') <= time();
                    $courseComplete = $lessons > 0 && $completed >= $lessons;
                    $accessStatus = (string) ($course['access_status'] ?? 'active');
                    $statusMap = [
                        'completed' => ['Completed', 'status-completed'],
                        'certified' => ['Certified', 'status-certified'],
                    ];
                    [$statusLabel, $statusClass] = $statusMap[$accessStatus] ?? ['Access active', 'status-active'];
                    $overallPct = $gradesAvailable && isset($course['overall_pct']) && $course['overall_pct'] !== null ? (float) $course['overall_pct'] : null;
                    $overallLabel = $overallPct === null ? '' : rtrim(rtrim(number_format($overallPct, 1, '.', ''), '0'), '.') . '%';
                ?>
                    <article class="dashboard-course-card">
                        <?php require BASE_PATH . '/app/Views/components/course-image.php'; ?>
                        <div class="dashboard-course-top"><span class="dashboard-status <?= $escape($statusClass) ?>"><?= $escape($statusLabel) ?></span><?php if ($overallPct !== null): ?><span class="course-grade-badge" title="Weighted course grade"><?= $escape($overallLabel) ?> · <strong><?= $escape($course['letter_grade'] ?? '') ?></strong></span><?php endif; ?></div>
                        <p class="dashboard-course-category"><?= $escape($course['category'] ?: 'Academy course') ?></p>
                        <h3><?= $escape($course['title']) ?></h3>
                        <p class="dashboard-course-meta"><?= $escape($course['level_label'] ?: 'All levels') ?><?= $course['duration_label'] ? ' · ' . $escape($course['duration_label']) : '' ?></p>
                        <div class="dashboard-progress-label"><span>Lesson progress</span><strong><?= $lessons > 0 ? $progress . '%' : 'Not started' ?></strong></div>
                        <div class="dashboard-progress" role="progressbar" aria-label="<?= $escape($course['title']) ?> lesson progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $progress ?>"><span style="width: <?= $progress ?>%"></span></div>
                        <small class="dashboard-course-foot"><?= $lessons > 0 ? number_format($completed) . ' of ' . number_format($lessons) . ' lessons completed' : 'Course lessons are being prepared' ?> · Access granted <?= $escape($grantedLabel($course['granted_at'] ?? null)) ?> · Expires <?= $escape($grantedLabel($course['expires_at'] ?? null)) ?></small>
                        <a class="dashboard-inline-link" href="<?= $escape($baseUrl) ?>/learning?course_id=<?= (int) $course['id'] ?>">Open course →</a>

                        <?php if ($certificatesAvailable): ?>
                            <div class="course-certificate">
                                <?php if ($certificateStatus === 'issued' && !$certificateExpired): ?>
                                    <span class="certificate-chip certificate-chip-issued"><span aria-hidden="true">✓</span> Certificate issued</span>
                                    <p class="certificate-line"><small>Certificate ID</small><strong><?= $escape($certificateNumber) ?></strong></p>
                                    <p class="certificate-line"><small>Valid until</small><strong><?= $escape($grantedLabel($certificateExpiry)) ?></strong></p>
                                    <div class="certificate-actions">
                                        <a class="button button-secondary certificate-button" href="<?= $escape($baseUrl) ?>/certificate/download?cert_id=<?= $escape(rawurlencode($certificateNumber)) ?>&course_id=<?= (int) ($course['id'] ?? 0) ?>">Download JPG</a>
                                        <a class="certificate-link" href="<?= $escape($baseUrl) ?>/verify?cert_id=<?= $escape(rawurlencode($certificateNumber)) ?>" target="_blank" rel="noopener">Public verification page →</a>
                                    </div>
                                <?php elseif ($certificateStatus === 'issued' && $certificateExpired): ?>
                                    <span class="certificate-chip certificate-chip-expired"><span aria-hidden="true">⏱</span> Certificate expired</span>
                                    <p class="certificate-note">This certificate expired on <?= $escape($grantedLabel($certificateExpiry)) ?> and is no longer downloadable. Contact the academy team to renew it.</p>
                                <?php elseif ($certificateStatus === 'requested'): ?>
                                    <span class="certificate-chip certificate-chip-pending"><span aria-hidden="true">⏳</span> Awaiting review</span>
                                    <p class="certificate-note">Your request is with the academy team. You will get an email once the certificate is issued.</p>
                                <?php elseif ($certificateStatus === 'rejected'): ?>
                                    <span class="certificate-chip certificate-chip-rejected"><span aria-hidden="true">✕</span> Request declined</span>
                                    <?php if ($certificateRemarks !== ''): ?><p class="certificate-note"><?= $escape($certificateRemarks) ?></p><?php endif; ?>
                                    <details class="certificate-request-details">
                                        <summary>Request the certificate again</summary>
                                        <form method="post" action="<?= $escape($baseUrl) ?>/student/certificates/request" class="certificate-request-form">
                                            <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
                                            <input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
                                            <label class="admin-label" for="certificate-remarks-<?= (int) $course['id'] ?>">Remarks for the review team (optional)</label>
                                            <textarea class="admin-textarea" id="certificate-remarks-<?= (int) $course['id'] ?>" name="remarks" rows="3" maxlength="1000" placeholder="Anything the team should know about your submission."></textarea>
                                            <button class="button certificate-button" type="submit">Send request</button>
                                        </form>
                                    </details>
                                <?php elseif ($courseComplete): ?>
                                    <details class="certificate-request-details">
                                        <summary>Request certificate</summary>
                                        <form method="post" action="<?= $escape($baseUrl) ?>/student/certificates/request" class="certificate-request-form">
                                            <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
                                            <input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
                                            <label class="admin-label" for="certificate-remarks-<?= (int) $course['id'] ?>">Remarks for the review team (optional)</label>
                                            <textarea class="admin-textarea" id="certificate-remarks-<?= (int) $course['id'] ?>" name="remarks" rows="3" maxlength="1000" placeholder="For example: the name spelling to print on the certificate."></textarea>
                                            <button class="button certificate-button" type="submit">Send request</button>
                                        </form>
                                    </details>
                                <?php else: ?>
                                    <p class="certificate-note certificate-note-muted">Complete all <?= number_format($lessons) ?> published lessons to unlock your certificate.</p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <div class="dashboard-note"><strong>Need help?</strong><span>Lessons open only for courses with an active access grant. If a course is missing here, contact the academy team.</span></div>

    <?php
    $usageEyebrow = 'My courses';
    $usageTitle = 'How to use My courses';
    $usageLead = 'The courses you are enrolled in, how long each one stays open and what is unlocked inside it.';
    $usageBullets = [
        'Access has a window. Every card shows the date the course closes; its lessons, tests and assignments close with it.',
        'Open the course page. The card links to the full learning workspace where the modules, lessons and materials live.',
        'Progress is counted per course. The bar compares the lessons you finished against everything published in that course so far.',
        'A missing course means no access yet. Enrol from the public course page or send your payment proof, and the academy opens it for you.',
        'Grades and certificates land here. Once a course is complete, its result and the certificate request appear on the same card.',
    ];
    require BASE_PATH . '/app/Views/components/usage-guide.php';
    ?>
</section>
