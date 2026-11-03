<?php

use App\Core\Security;

/**
 * View::render() injects these values from CertificateController.
 * @var array<int, array<string, mixed>> $certificates
 * @var array<string, int> $counts
 * @var string $status
 * @var string $search
 * @var bool $schemaAvailable
 * @var string $contactAddress
 * @var array{days:int,min_days:int,max_days:int,idExample:string} $certificateValues
 * @var array{grade:int,attendance:int,validDays:int} $criteria
 * @var string|null $message
 * @var string|null $error
 */
$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $value): string => Security::escape((string) $value);
$certificates = is_array($certificates ?? null) ? $certificates : [];
$counts = is_array($counts ?? null) ? $counts : [];
$status = is_string($status ?? null) ? $status : 'all';
$search = is_string($search ?? null) ? $search : '';
$schemaAvailable = (bool) ($schemaAvailable ?? true);
$contactAddress = is_string($contactAddress ?? null) && $contactAddress !== '' ? $contactAddress : 'info@techsgstudio.com';
$certificateValues = is_array($certificateValues ?? null) ? $certificateValues : [];
$criteria = is_array($criteria ?? null) ? $criteria : ['grade' => 50, 'attendance' => 75, 'validDays' => 15];
$message = is_string($message ?? null) && $message !== '' ? $message : null;
$error = is_string($error ?? null) && $error !== '' ? $error : null;
$label = static function (?string $value): string {
    if (!$value) {
        return '—';
    }
    $timestamp = strtotime($value . ' UTC');
    return $timestamp === false ? '—' : gmdate('M j, Y', $timestamp);
};
$queryUrl = static function (array $overrides = []) use ($search, $status): string {
    return http_build_query(array_merge(['q' => $search, 'status' => $status], $overrides));
};
$filters = [
    'all' => 'All requests',
    'requested' => 'Awaiting review',
    'issued' => 'Valid',
    'expired' => 'Expired',
    'rejected' => 'Rejected',
];
?>
<section class="dashboard-page certificate-admin-page" data-reveal>
    <div class="dashboard-topbar">
        <button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
        <div class="dashboard-breadcrumb"><span>Workspace</span><i>/</i><strong>Certificates</strong></div>
        <form class="dashboard-search certificate-page-search" method="get" action="<?= $escape($baseUrl) ?>/admin/certificates" role="search">
            <span aria-hidden="true">⌕</span>
            <input type="search" name="q" value="<?= $escape($search) ?>" placeholder="Search student, course or certificate ID" aria-label="Search certificates">
            <input type="hidden" name="status" value="<?= $escape($status) ?>">
        </form>
    </div>

    <header class="dashboard-heading">
        <div>
            <p class="eyebrow">ADMIN WORKSPACE</p>
            <h1>Certificates</h1>
            <p>Review student certificate requests, issue signed JPG certificates, publish public verification, and set the identifier rules below.</p>
        </div>
        <a class="button button-secondary" href="<?= $escape($baseUrl) ?>/admin/settings">Security settings</a>
    </header>

    <?php \App\Core\View::toast('success', $message ?? null); ?>
    <?php \App\Core\View::toast('error', $error ?? null); ?>

    <section class="dashboard-summary-card certificate-eligibility-card" aria-labelledby="certificate-eligibility-title">
        <div class="dashboard-summary-heading certificate-eligibility-head">
            <div>
                <p class="eyebrow">ELIGIBILITY REQUIREMENTS</p>
                <h2 id="certificate-eligibility-title">How a certificate is earned</h2>
            </div>
            <span>Applies to every request</span>
        </div>
        <p class="certificate-eligibility-lead">A request is accepted only when a student clears all three bars. The same rules are published on the student's <a class="dashboard-inline-link" href="<?= $escape($baseUrl) ?>/student/courses">My courses</a> page, so nobody has to guess.</p>
        <div class="certificate-eligibility-grid">
            <article class="certificate-eligibility-item">
                <span class="certificate-eligibility-value"><?= (int) $criteria['grade'] ?>%</span>
                <strong>Overall grade</strong>
                <small>The weighted grade across video, quizzes, exercises, project and the final test must reach <?= (int) $criteria['grade'] ?>% or better.</small>
            </article>
            <article class="certificate-eligibility-item">
                <span class="certificate-eligibility-value"><?= (int) $criteria['attendance'] ?>%</span>
                <strong>Attendance</strong>
                <small>Days marked present, as a share of decided days since access was granted, must reach <?= (int) $criteria['attendance'] ?>%. Days still pending are ignored.</small>
            </article>
            <article class="certificate-eligibility-item">
                <span class="certificate-eligibility-value">100%</span>
                <strong>Lesson completion</strong>
                <small>Every published lesson in the course must be completed. Draft or removed lessons are never counted.</small>
            </article>
        </div>
    </section>

    <?php if (!$schemaAvailable): ?>
        <div class="student-schema-notice" role="status"><strong>The certificates table is not installed yet.</strong><span>Import the updated <code>database/migrations/001_initial_schema.sql</code> into <code>tsg_academy_lms</code>. Requests and issued certificates appear here straight after.</span></div>
    <?php else: ?>

        <section class="dashboard-panel certificate-list-panel" aria-label="Certificate requests">
            <div class="dashboard-panel-heading certificate-list-heading">
                <div>
                    <p class="eyebrow">CERTIFICATE QUEUE</p>
                    <h2><?= number_format((int) ($counts['all'] ?? 0)) ?> record<?= (int) ($counts['all'] ?? 0) === 1 ? '' : 's' ?></h2>
                </div>
            </div>

            <div class="certificate-filter-chips" role="group" aria-label="Filter certificates">
                <?php foreach ($filters as $key => $labelText): ?>
                    <a class="certificate-chip-link<?= $status === $key ? ' is-active' : '' ?>" href="<?= $escape($baseUrl) ?>/admin/certificates?<?= $escape($queryUrl(['status' => $key])) ?>"><?= $escape($labelText) ?><small><?= number_format((int) ($counts[$key] ?? 0)) ?></small></a>
                <?php endforeach; ?>
                <?php if ($search !== '' || $status !== 'all'): ?><a class="certificate-chip-link certificate-chip-clear" href="<?= $escape($baseUrl) ?>/admin/certificates">Clear filters</a><?php endif; ?>
            </div>

            <?php if ($certificates === []): ?>
                <div class="dashboard-empty">
                    <div>
                        <h2><?= $search !== '' || $status !== 'all' ? 'No matching certificates' : 'No certificate requests yet' ?></h2>
                        <p><?= $search !== '' || $status !== 'all' ? 'Try another name, course, certificate ID, or status filter.' : 'Students can request a certificate from My courses once every published lesson is complete.' ?></p>
                    </div>
                </div>
            <?php else: ?>
                <div class="admin-table-wrapper">
                    <table class="admin-table certificate-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Course</th>
                                <th>Request</th>
                                <th>Certificate</th>
                                <th class="certificate-col-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($certificates as $certificate):
                                $rowStatus = (string) $certificate['status'];
                                $isExpired = $rowStatus === 'issued' && !empty($certificate['expires_at']) && strtotime((string) $certificate['expires_at'] . ' UTC') <= time();
                                $badgeClass = $isExpired ? 'certificate-status certificate-status-expired' : 'certificate-status certificate-status-' . $rowStatus;
                                $badgeText = $isExpired ? 'Expired' : ucfirst($rowStatus);
                                $publicId = (string) ($certificate['certificate_id'] ?? '');
                            ?>
                                <tr>
                                    <td data-label="Student">
                                        <div class="certificate-student">
                                            <strong><?= $escape($certificate['student_name']) ?></strong>
                                            <small><?= $escape($certificate['student_email']) ?></small>
                                        </div>
                                    </td>
                                    <td data-label="Course">
                                        <div class="certificate-course">
                                            <strong><?= $escape($certificate['course_title']) ?></strong>
                                            <small><?= $escape($certificate['duration_label'] ?: 'Duration not set') ?><?= $certificate['level_label'] ? ' · ' . $escape($certificate['level_label']) : '' ?></small>
                                        </div>
                                    </td>
                                    <td data-label="Request">
                                        <div class="certificate-request">
                                            <span><?= $escape($label((string) $certificate['requested_at'])) ?></span>
                                            <?php if (!empty($certificate['student_remarks'])): ?><small title="<?= $escape($certificate['student_remarks']) ?>">“<?= $escape(mb_strimwidth((string) $certificate['student_remarks'], 0, 70, '…')) ?>”</small><?php endif; ?>
                                            <?php if (($certificate['overall_percent'] ?? null) !== null): $gradeNum = rtrim(rtrim(number_format((float) $certificate['overall_percent'], 1, '.', ''), '0'), '.'); $attNum = ($certificate['attendance_percent'] ?? null) !== null ? rtrim(rtrim(number_format((float) $certificate['attendance_percent'], 1, '.', ''), '0'), '.') : null; ?>
                                                <small class="certificate-grade">At request: <strong><?= $escape((string) ($certificate['letter_grade'] ?? '')) ?> · <?= $escape($gradeNum) ?>%</strong><?= $attNum !== null ? ' · attendance ' . $escape($attNum) . '%' : '' ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td data-label="Certificate">
                                        <div class="certificate-meta">
                                            <span class="<?= $escape($badgeClass) ?>"><?= $escape($badgeText) ?></span>
                                            <?php if ($publicId !== ''): ?>
                                                <small><?= $escape($publicId) ?></small>
                                                <small>Valid until <?= $escape($label((string) ($certificate['expires_at'] ?? ''))) ?></small>
                                            <?php endif; ?>
                                            <?php if (!empty($certificate['admin_remarks'])): ?><small class="certificate-admin-remark"><?= $escape($certificate['admin_remarks']) ?></small><?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="certificate-row-actions" data-label="Actions">
                                        <?php if ($rowStatus === 'issued'): ?>
                                            <?php if (!empty($certificate['has_file'])): ?>
                                                <a class="student-activity-link" href="<?= $escape($baseUrl) ?>/certificate/download?cert_id=<?= $escape(rawurlencode($publicId)) ?>&course_id=<?= (int) ($certificate['course_id'] ?? 0) ?>">Download JPG <span aria-hidden="true">↓</span></a>
                                            <?php endif; ?>
                                            <a class="student-activity-link" href="<?= $escape($baseUrl) ?>/verify?cert_id=<?= $escape(rawurlencode($publicId)) ?>" target="_blank" rel="noopener">View public record <span aria-hidden="true">→</span></a>
                                        <?php else: ?>
                                            <details class="certificate-review">
                                                <summary>Review request</summary>
                                                <form class="certificate-review-form" method="post" action="<?= $escape($baseUrl) ?>/admin/certificates/issue" enctype="multipart/form-data">
                                                    <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
                                                    <input type="hidden" name="certificate_id" value="<?= (int) $certificate['id'] ?>">
                                                    <label class="admin-label" for="certificate-file-<?= (int) $certificate['id'] ?>">Certificate image (JPG, max 5 MB)</label>
                                                    <input class="admin-input certificate-file-input" id="certificate-file-<?= (int) $certificate['id'] ?>" type="file" name="certificate_file" accept="image/jpeg" required>
                                                    <label class="admin-label" for="certificate-issue-remarks-<?= (int) $certificate['id'] ?>">Remarks shown on the public record (optional)</label>
                                                    <textarea class="admin-textarea" id="certificate-issue-remarks-<?= (int) $certificate['id'] ?>" name="admin_remarks" rows="2" maxlength="1000" placeholder="For example: awarded with distinction."></textarea>
                                                    <div class="certificate-review-actions">
                                                        <button class="button certificate-button" type="submit">Issue certificate</button>
                                                    </div>
                                                    <span class="admin-hint">The ID and expiry are generated from the Certificate settings section further down this page, and the student is emailed automatically.</span>
                                                </form>
                                                <form class="certificate-review-form certificate-reject-form" method="post" action="<?= $escape($baseUrl) ?>/admin/certificates/reject" onsubmit="return confirm('Reject this certificate request?')">
                                                    <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
                                                    <input type="hidden" name="certificate_id" value="<?= (int) $certificate['id'] ?>">
                                                    <label class="admin-label" for="certificate-reject-remarks-<?= (int) $certificate['id'] ?>">Reason for rejecting</label>
                                                    <input class="admin-input" id="certificate-reject-remarks-<?= (int) $certificate['id'] ?>" type="text" name="admin_remarks" maxlength="1000" required placeholder="What the student needs to correct">
                                                    <div class="certificate-review-actions">
                                                        <button class="student-action-button is-block" type="submit">Reject request</button>
                                                    </div>
                                                </form>
                                            </details>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <p class="student-privacy-note">Certificate images are stored outside the web root and served only through the verification page. Requests and decisions are recorded in the audit log. For delivery issues, contact <?= $escape($contactAddress) ?>.</p>
        </section>

    <?php endif; ?>

    <section class="admin-card certificate-settings-card">
        <div class="admin-card-header">
            <div class="admin-card-title-group">
                <div class="admin-card-icon violet">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="9" r="6"></circle>
                        <path d="M8.5 14.5 7 22l5-2.6L17 22l-1.5-7.5"></path>
                    </svg>
                </div>
                <div class="admin-card-titles">
                    <h2>Certificate settings</h2>
                    <p>Control how long an issued certificate stays verifiable.</p>
                </div>
            </div>
        </div>

        <form method="post" action="<?= $escape($baseUrl) ?>/admin/certificates/settings" class="admin-form">
            <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
            <div class="admin-form-row">
                <div class="admin-field">
                    <label class="admin-label" for="certificate-id-scheme">Certificate ID</label>
                    <input class="admin-input" id="certificate-id-scheme" type="text" value="Issued as <?= $escape($certificateValues['idExample'] ?? 'TSG-CER-001') ?>" readonly>
                    <span class="admin-hint">A certificate carries its owner's own account ID, so the student verifies it with the number they were given. The prefix and the number new students start at are set on your <a href="<?= $escape($baseUrl) ?>/admin/account">account settings</a> page.</span>
                </div>
                <div class="admin-field">
                    <label class="admin-label" for="certificate-available-days">Available for</label>
                    <div class="admin-number-field">
                        <input class="admin-input" id="certificate-available-days" name="certificate_available_days" type="number" min="<?= (int) ($certificateValues['min_days'] ?? 1) ?>" max="<?= (int) ($certificateValues['max_days'] ?? 3650) ?>" required value="<?= (int) ($certificateValues['days'] ?? 15) ?>" aria-label="Certificate availability in days">
                        <span class="admin-number-unit">days</span>
                    </div>
                    <span class="admin-hint">Counted from the issue date; verification and download stop working after expiry.</span>
                </div>
            </div>
            <div class="form-actions">
                <button class="button" type="submit">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                        <polyline points="17 21 17 13 7 13 7 21"></polyline>
                        <polyline points="7 3 7 8 15 8"></polyline>
                    </svg>
                    Save certificate settings
                </button>
                <span class="form-action-note">Changes apply to certificates issued from now on; already-issued IDs and expiry dates are never rewritten.</span>
            </div>
        </form>
    </section>

    <?php
    $usageEyebrow = 'Certificate desk';
    $usageTitle = 'How to use certificates';
    $usageLead = 'A quick walkthrough of the workflow this page manages end to end.';
    $usageBullets = [
        'Requests follow the eligibility bars. A student can request a certificate only after finishing lessons, the final test and the attendance requirement shown above.',
        'Issue with a signed upload. The certificate image is a signed JPG stored privately and attached to that student’s request only.',
        'Every certificate carries the student’s own ID. The number is built from the prefix and start value set on your account settings page.',
        'Verification is public. Anyone can confirm a certificate on the public verify page using its ID — the lookup is rate-protected.',
        'Availability has an expiry. The window in days is counted from the issue date; verification and downloads stop working afterwards.',
        'Settings apply forward only. Changing ID rules or the expiry window never rewrites certificates that are already issued.',
    ];
    require BASE_PATH . '/app/Views/components/usage-guide.php';
    ?>
</section>
