<?php

use App\Core\Security;
use App\Services\AccountIdentity;

/**
 * View::render() injects these values from StudentManagementController.
 * @var array<int, array<string, mixed>> $students
 * @var array<int, array<string, mixed>> $activity
 * @var string $search
 * @var string $statusFilter
 * @var int $page
 * @var int $pages
 * @var int $totalStudents
 * @var array<string, int|string|null> $statusCounts
 * @var array<string, mixed>|null $selectedStudent
 * @var array<string, int|string|null> $selectedLoginCounts
 * @var bool $loginHistoryAvailable
 * @var int $graceMinutes
 * @var int $graceMinMinutes
 * @var int $graceMaxMinutes
 * @var string|null $message
 * @var string|null $error
 */
$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$students = is_array($students ?? null) ? $students : [];
$activity = is_array($activity ?? null) ? $activity : [];
$search = is_string($search ?? null) ? $search : '';
$statusFilter = is_string($statusFilter ?? null) ? $statusFilter : 'all';
$page = max(1, (int) ($page ?? 1));
$pages = max(1, (int) ($pages ?? 1));
$selectedStudent = is_array($selectedStudent ?? null) ? $selectedStudent : null;
$statusCounts = is_array($statusCounts ?? null) ? $statusCounts : [];
$selectedLoginCounts = is_array($selectedLoginCounts ?? null) ? $selectedLoginCounts : [];
$graceMinutes = max(1, (int) ($graceMinutes ?? 5));
$graceMin = max(1, (int) ($graceMinMinutes ?? 1));
$graceMax = max($graceMin, (int) ($graceMaxMinutes ?? 120));
$message = is_string($message ?? null) ? $message : null;
$error = is_string($error ?? null) && $error !== '' ? $error : null;
$queryUrl = static function (array $overrides = []) use ($search, $statusFilter): string {
    return http_build_query(array_merge(['q' => $search, 'status' => $statusFilter], $overrides));
};
// The ID an admin quotes for a student is the Academy ID on the account — the same number the
// student sees in their own settings and on a certificate. The row id is internal bookkeeping.
$accountId = static function (array $account): string {
    $stored = trim((string) ($account['account_reference'] ?? ''));
    return $stored !== '' ? $stored : AccountIdentity::derived((int) $account['id']);
};
?>
<section class="dashboard-page student-management-page" data-reveal>
    <div class="dashboard-topbar">
        <button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
        <div class="dashboard-breadcrumb"><span>Workspace</span><i>/</i><strong>Students</strong></div>
        <form class="dashboard-search student-page-search" method="get" action="<?= Security::escape($baseUrl) ?>/admin/students" role="search">
            <span aria-hidden="true">⌕</span>
            <input type="search" name="q" value="<?= Security::escape($search) ?>" placeholder="Search student ID, name or email" aria-label="Search students">
            <input type="hidden" name="status" value="<?= Security::escape($statusFilter) ?>">
        </form>
    </div>
    <header class="dashboard-heading">
        <div>
            <p class="eyebrow">ADMIN WORKSPACE</p>
            <h1>Students</h1>
            <p>Review student accounts and recent sign-in activity.</p>
        </div>
    </header>

    <?php \App\Core\View::toast('success', $message ?? null); ?>
    <?php \App\Core\View::toast('error', $error ?? null); ?>

    <div class="student-overview-grid" aria-label="Student account totals">
        <article class="student-overview-card student-overview-card-primary"><span class="student-overview-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m17-11a4 4 0 0 1 0 8m-6-12a4 4 0 1 1-8 0 4 4 0 0 1 8 0Zm5 14v-2a4 4 0 0 0-3-3.87" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span><div><span>Total students</span><strong><?= number_format((int) ($statusCounts['total'] ?? 0)) ?></strong><small>Accounts registered in the Academy</small></div></article>
        <article class="student-overview-card"><span class="student-overview-icon student-icon-active" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="m5 12 4 4L19 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span><div><span>Active</span><strong><?= number_format((int) ($statusCounts['active'] ?? 0)) ?></strong><small>Verified and able to sign in</small></div></article>
        <article class="student-overview-card"><span class="student-overview-icon student-icon-pending" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/><path d="M12 7v5l3 2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span><div><span>Pending verification</span><strong><?= number_format((int) ($statusCounts['pending'] ?? 0)) ?></strong><small>Awaiting email confirmation</small></div></article>
        <article class="student-overview-card"><span class="student-overview-icon student-icon-blocked" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/><path d="m6 6 12 12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span><div><span>Blocked</span><strong><?= number_format((int) ($statusCounts['suspended'] ?? 0)) ?></strong><small>Sign-in is disabled</small></div></article>
    </div>

    <?php if (empty($loginHistoryAvailable)): ?>
        <div class="student-schema-notice" role="status"><strong>Login history needs its database table.</strong><span>Import the updated <code>database/migrations/001_initial_schema.sql</code> into <code>tsg_academy_lms</code>. The student directory is available now; login totals and activity will appear after import.</span></div>
    <?php endif; ?>

    <section class="admin-card student-attendance-card">
        <div class="admin-card-header">
            <div class="admin-card-title-group">
                <div class="admin-card-icon amber">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="9"></circle>
                        <polyline points="12 7 12 12 15 14"></polyline>
                    </svg>
                </div>
                <div class="admin-card-titles">
                    <h2>Attendance confirmation window</h2>
                    <p>How long a student has to confirm presence after signing in before the day is recorded as absent. Attendance feeds the certificate eligibility bar.</p>
                </div>
            </div>
        </div>

        <form method="post" action="<?= Security::escape($baseUrl) ?>/admin/students/attendance" class="admin-form">
            <input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>">
            <div class="admin-setting-row">
                <div class="admin-setting-row-text">
                    <span class="admin-label">Attendance grace window</span>
                    <span class="admin-hint">Applies from the next sign-in. Shorter windows make the daily attendance record close sooner. Allowed: <?= $graceMin ?> to <?= $graceMax ?> minutes.</span>
                </div>
                <div class="admin-setting-row-control">
                    <div class="admin-number-field">
                        <input class="admin-input" type="number" name="attendance_grace_minutes" min="<?= $graceMin ?>" max="<?= $graceMax ?>" required value="<?= $graceMinutes ?>" aria-label="Attendance grace window in minutes">
                        <span class="admin-number-unit">minutes</span>
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
                    Save attendance window
                </button>
            </div>
        </form>
    </section>

    <section class="dashboard-panel student-directory-panel" aria-label="Student directory">
        <div class="dashboard-panel-heading student-directory-heading">
            <div class="student-directory-title">
                <p class="eyebrow">STUDENT DIRECTORY</p>
                <h2><?= number_format((int) ($totalStudents ?? 0)) ?> matching student<?= (int) ($totalStudents ?? 0) === 1 ? '' : 's' ?></h2>
            </div>
            <form class="student-status-control" method="get" action="<?= Security::escape($baseUrl) ?>/admin/students">
                <input type="hidden" name="q" value="<?= Security::escape($search) ?>">
                <label class="sr-only" for="student-status">Account status</label>
                <select class="admin-select student-status-filter" id="student-status" name="status" onchange="this.form.submit()">
                    <option value="all" <?= $statusFilter === 'all' ? ' selected' : '' ?>>All statuses</option><?php foreach (['active', 'pending', 'suspended'] as $status): ?><option value="<?= $status ?>" <?= $statusFilter === $status ? ' selected' : '' ?>><?= Security::escape(ucfirst($status)) ?></option><?php endforeach; ?>
                </select>
                <?php if ($search !== '' || $statusFilter !== 'all'): ?><a class="button button-secondary student-filter-clear" href="<?= Security::escape($baseUrl) ?>/admin/students">Clear</a><?php endif; ?>
            </form>
        </div>
        <p class="student-directory-hint">Use <strong>View activity</strong> to see a student's account status and sign-in history.</p>
        <?php if ($students === []): ?>
            <div class="dashboard-empty">
                <div>
                    <h2>No students found</h2>
                    <p>Try another name, email, or status filter.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="admin-table-wrapper">
                <table class="admin-table student-directory-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th class="student-col-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $student): $studentName = (string) $student['full_name']; ?>
                            <tr>
                                <td data-label="Student"><div class="student-table-identity"><span><strong><?= Security::escape($studentName) ?></strong><small>ID · <?= Security::escape($accountId($student)) ?></small></span></div></td>
                                <td class="student-row-actions" data-label="Actions"><a class="student-activity-link" href="<?= Security::escape($baseUrl) ?>/admin/students?<?= Security::escape($queryUrl(['student_id' => (int) $student['id'], 'page' => $page])) ?>">View activity <span aria-hidden="true">→</span></a><?php if (in_array($student['status'], ['active', 'suspended'], true)): ?><form method="post" action="<?= Security::escape($baseUrl) ?>/admin/students/status" class="student-status-form" onsubmit="return confirm('<?= $student['status'] === 'active' ? 'Block' : 'Unblock' ?> this student account?')">
                                        <input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>">
                                        <input type="hidden" name="student_id" value="<?= (int) $student['id'] ?>">
                                        <input type="hidden" name="status" value="<?= $student['status'] === 'active' ? 'suspended' : 'active' ?>">
                                        <button class="student-action-button <?= $student['status'] === 'active' ? 'is-block' : 'is-unblock' ?>" type="submit"><?= $student['status'] === 'active' ? 'Block' : 'Unblock' ?></button>
                                    </form><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($pages > 1): ?><nav class="student-pagination" aria-label="Student directory pages"><span>Page <?= $page ?> of <?= $pages ?></span>
                    <div><?php if ($page > 1): ?><a class="button button-secondary" href="<?= Security::escape($baseUrl) ?>/admin/students?<?= Security::escape($queryUrl(['page' => $page - 1])) ?>">Previous</a><?php endif; ?><?php if ($page < $pages): ?><a class="button button-secondary" href="<?= Security::escape($baseUrl) ?>/admin/students?<?= Security::escape($queryUrl(['page' => $page + 1])) ?>">Next</a><?php endif; ?></div>
                </nav><?php endif; ?>
        <?php endif; ?>
        <p class="student-privacy-note">For privacy and security, this page never displays passwords or OTPs. Failed attempts for unknown email addresses are not linked to a student account.</p>
    </section>

    <?php if ($selectedStudent): ?>
        <section class="dashboard-panel student-activity-panel" aria-labelledby="student-activity-title">
            <div class="dashboard-panel-heading">
                <div>
                    <p class="eyebrow">STUDENT ACCOUNT</p>
                    <h2 id="student-activity-title"><?= Security::escape($selectedStudent['full_name']) ?></h2>
                    <p><?= Security::escape($selectedStudent['email']) ?> <span class="student-detail-id">ID <?= Security::escape($accountId($selectedStudent)) ?></span></p>
                </div>
                <a class="button button-secondary" href="<?= Security::escape($baseUrl) ?>/admin/students?<?= Security::escape($queryUrl()) ?>">Close activity</a>
            </div>
            <div class="student-activity-summary">
                <span><strong>Account status</strong><b class="student-status-badge student-status-<?= Security::escape($selectedStudent['status']) ?>"><?= Security::escape($selectedStudent['status'] === 'suspended' ? 'Blocked' : ucfirst($selectedStudent['status'])) ?></b></span>
                <span><strong>Registered</strong><?= Security::escape($selectedStudent['created_at']) ?></span>
                <span><strong>Last successful login</strong><?= Security::escape($selectedStudent['last_login_at'] ?: 'No successful login recorded') ?></span>
                <span><strong>Successful sign-ins</strong><?= empty($loginHistoryAvailable) ? 'Not installed' : number_format((int) ($selectedLoginCounts['successful'] ?? 0)) ?></span>
                <span><strong>Failed / blocked attempts</strong><?= empty($loginHistoryAvailable) ? 'Not installed' : number_format((int) ($selectedLoginCounts['failed'] ?? 0)) ?></span>
            </div>
            <div class="student-activity-toolbar">
                <h3>Recent sign-in activity <small>Latest 50 records · server time in UTC</small></h3>
                <?php if (!empty($loginHistoryAvailable) && $activity !== []): ?>
                    <form method="post" action="<?= Security::escape($baseUrl) ?>/admin/students/clear-activity" class="student-status-form" onsubmit="return confirm('Clear all recorded sign-in activity for this student? This cannot be undone.')">
                        <input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>">
                        <input type="hidden" name="student_id" value="<?= (int) $selectedStudent['id'] ?>">
                        <button class="student-action-button is-clear" type="submit">Clear activity</button>
                    </form>
                <?php endif; ?>
            </div>
            <?php if ($activity === []): ?>
                <div class="dashboard-empty"><div><h2><?= empty($loginHistoryAvailable) ? 'Login activity is not set up yet' : 'No sign-in history recorded yet' ?></h2><p><?= empty($loginHistoryAvailable) ? 'Import the updated 001_initial_schema.sql file into your existing database to enable sign-in history.' : 'New sign-in activity will appear here as it happens.' ?></p></div></div>
            <?php else: ?>
                <div class="admin-table-wrapper"><table class="admin-table student-activity-table">
                    <thead><tr><th>Result</th><th>Date and time (UTC)</th><th>IP address</th></tr></thead>
                    <tbody><?php foreach ($activity as $event): ?><tr><td data-label="Result"><span class="student-event-badge student-event-<?= Security::escape($event['event_type']) ?>"><?= Security::escape(ucfirst($event['event_type'])) ?></span></td><td data-label="Date and time (UTC)"><?= Security::escape($event['created_at']) ?></td><td data-label="IP address"><?= Security::escape($event['ip_address'] ?: 'Unavailable') ?></td></tr><?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php
    // Testing tool: clears the whole student directory so the next sign-up can be followed end to end.
    $purgeAction = '/admin/students/purge';
    $purgePhrase = 'DELETE ALL STUDENTS';
    $purgeTitle = 'Delete all students';
    $purgeDescription = 'Removes every student account with its enrollments, attendance, submissions, quiz attempts, payments, grades, certificates and uploaded files. Admin accounts, courses and the email delivery log stay untouched.';
    $purgeCount = (int) ($statusCounts['total'] ?? 0);
    require BASE_PATH . '/app/Views/components/purge-zone.php';
    ?>
</section>
