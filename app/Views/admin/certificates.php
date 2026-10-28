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
            <p>Review student certificate requests, issue signed JPG certificates, and publish public verification.</p>
        </div>
        <a class="button button-secondary" href="<?= $escape($baseUrl) ?>/admin/settings">Certificate settings</a>
    </header>

    <?php if ($message !== null): ?><div class="admin-alert admin-alert-success" role="status"><?= $escape($message) ?></div><?php endif; ?>
    <?php if ($error !== null): ?><div class="admin-alert admin-alert-error" role="alert"><?= $escape($error) ?></div><?php endif; ?>

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
                                                <a class="student-activity-link" href="<?= $escape($baseUrl) ?>/certificate/download?cert_id=<?= $escape(rawurlencode($publicId)) ?>">Download JPG <span aria-hidden="true">↓</span></a>
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
                                                    <span class="admin-hint">The ID and expiry are generated from Certificate settings, and the student is emailed automatically.</span>
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
</section>
