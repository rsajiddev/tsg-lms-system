<?php

use App\Core\Security;

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
$message = is_string($message ?? null) ? $message : null;
$queryUrl = static function (array $overrides = []) use ($search, $statusFilter): string {
    return http_build_query(array_merge(['q' => $search, 'status' => $statusFilter], $overrides));
};
?>
<section class="dashboard-page student-management-page" data-reveal>
    <header class="dashboard-heading">
        <div>
            <button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
            <p class="eyebrow">ADMIN WORKSPACE</p>
            <h1>Students</h1>
            <p>Review student accounts and recent sign-in activity.</p>
        </div>
    </header>

    <?php if ($message !== null && $message !== ''): ?><div class="admin-alert admin-alert-success" role="status"><?= Security::escape($message) ?></div><?php endif; ?>

    <div class="student-overview-grid" aria-label="Student account totals">
        <article class="student-overview-card student-overview-card-primary"><span class="student-overview-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m17-11a4 4 0 0 1 0 8m-6-12a4 4 0 1 1-8 0 4 4 0 0 1 8 0Zm5 14v-2a4 4 0 0 0-3-3.87" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span><div><span>Total students</span><strong><?= number_format((int) ($statusCounts['total'] ?? 0)) ?></strong><small>Accounts registered in the Academy</small></div></article>
        <article class="student-overview-card"><span class="student-overview-icon student-icon-active" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="m5 12 4 4L19 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span><div><span>Active</span><strong><?= number_format((int) ($statusCounts['active'] ?? 0)) ?></strong><small>Verified and able to sign in</small></div></article>
        <article class="student-overview-card"><span class="student-overview-icon student-icon-pending" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/><path d="M12 7v5l3 2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span><div><span>Pending verification</span><strong><?= number_format((int) ($statusCounts['pending'] ?? 0)) ?></strong><small>Awaiting email confirmation</small></div></article>
        <article class="student-overview-card"><span class="student-overview-icon student-icon-blocked" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/><path d="m6 6 12 12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span><div><span>Blocked</span><strong><?= number_format((int) ($statusCounts['suspended'] ?? 0)) ?></strong><small>Sign-in is disabled</small></div></article>
    </div>

    <?php if (empty($loginHistoryAvailable)): ?>
        <div class="student-schema-notice" role="status"><strong>Login history needs its database table.</strong><span>Import the updated <code>database/migrations/001_initial_schema.sql</code> into <code>tsg_academy_lms</code>. The student directory is available now; login totals and activity will appear after import.</span></div>
    <?php endif; ?>

    <section class="dashboard-panel" aria-label="Student directory">
        <div class="dashboard-panel-heading">
            <div>
                <p class="eyebrow">STUDENT DIRECTORY</p>
                <h2><?= number_format((int) ($totalStudents ?? 0)) ?> matching student<?= (int) ($totalStudents ?? 0) === 1 ? '' : 's' ?></h2>
                <p>Login totals include events recorded after this feature is installed.</p>
            </div>
        </div>
        <form class="student-filter-form" method="get" action="<?= Security::escape($baseUrl) ?>/admin/students">
            <label class="admin-label" for="student-search">Find a student<input class="admin-input" id="student-search" name="q" type="search" maxlength="100" value="<?= Security::escape($search) ?>" placeholder="Search student ID, name or email"></label>
            <label class="admin-label" for="student-status">Account status<select class="admin-select" id="student-status" name="status">
                    <option value="all" <?= $statusFilter === 'all' ? ' selected' : '' ?>>All statuses</option><?php foreach (['active', 'pending', 'suspended'] as $status): ?><option value="<?= $status ?>" <?= $statusFilter === $status ? ' selected' : '' ?>><?= Security::escape(ucfirst($status)) ?></option><?php endforeach; ?>
                </select></label>
            <button class="button" type="submit">Search students</button>
            <?php if ($search !== '' || $statusFilter !== 'all'): ?><a class="button button-secondary" href="<?= Security::escape($baseUrl) ?>/admin/students">Clear</a><?php endif; ?>
        </form>
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
                            <th>Email</th>
                            <th>Registered</th>
                            <th>Last login</th>
                            <th>Successful logins</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $student): ?><tr>
                                <td data-label="Student"><div class="student-table-identity"><span><strong><?= Security::escape($student['full_name']) ?></strong><small>Student ID · <?= (int) $student['id'] ?></small></span></div></td>
                                <td class="student-email-cell" data-label="Email"><?= Security::escape($student['email']) ?></td>
                                <td data-label="Registered"><?= Security::escape($student['created_at']) ?></td>
                                <td data-label="Last login"><?= Security::escape($student['last_login_at'] ?: 'No login yet') ?></td>
                                <td data-label="Successful logins"><span class="student-login-count"><?= $student['successful_logins'] === null ? '—' : number_format((int) $student['successful_logins']) ?></span></td>
                                <td data-label="Status"><span class="student-status-badge student-status-<?= Security::escape($student['status']) ?>"><?= Security::escape($student['status'] === 'suspended' ? 'Blocked' : ucfirst($student['status'])) ?></span></td>
                                <td class="student-row-actions" data-label="Actions"><a class="student-activity-link" href="<?= Security::escape($baseUrl) ?>/admin/students?<?= Security::escape($queryUrl(['student_id' => (int) $student['id'], 'page' => $page])) ?>">View details <span aria-hidden="true">→</span></a><?php if (in_array($student['status'], ['active', 'suspended'], true)): ?><form method="post" action="<?= Security::escape($baseUrl) ?>/admin/students/status" class="student-status-form" onsubmit="return confirm('<?= $student['status'] === 'active' ? 'Block' : 'Unblock' ?> this student account?')">
                                        <input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>">
                                        <input type="hidden" name="student_id" value="<?= (int) $student['id'] ?>">
                                        <input type="hidden" name="status" value="<?= $student['status'] === 'active' ? 'suspended' : 'active' ?>">
                                        <button class="student-action-button <?= $student['status'] === 'active' ? 'is-block' : 'is-unblock' ?>" type="submit"><?= $student['status'] === 'active' ? 'Block' : 'Unblock' ?></button>
                                    </form><?php endif; ?></td>
                            </tr><?php endforeach; ?>
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
                    <p><?= Security::escape($selectedStudent['email']) ?> <span class="student-detail-id">ID <?= (int) $selectedStudent['id'] ?></span></p>
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
            <h3>Recent sign-in activity <small>Latest 50 records · server time in UTC</small></h3>
            <?php if ($activity === []): ?>
                <div class="dashboard-empty"><div><h2><?= empty($loginHistoryAvailable) ? 'Login activity is not set up yet' : 'No sign-in history recorded yet' ?></h2><p><?= empty($loginHistoryAvailable) ? 'Import the updated 001_initial_schema.sql file into your existing database to enable sign-in history.' : 'New activity will appear after this feature is installed.' ?></p></div></div>
            <?php else: ?>
                <div class="admin-table-wrapper"><table class="admin-table student-activity-table">
                    <thead><tr><th>Result</th><th>Date and time (UTC)</th><th>IP address</th></tr></thead>
                    <tbody><?php foreach ($activity as $event): ?><tr><td data-label="Result"><span class="student-event-badge student-event-<?= Security::escape($event['event_type']) ?>"><?= Security::escape(ucfirst($event['event_type'])) ?></span></td><td data-label="Date and time (UTC)"><?= Security::escape($event['created_at']) ?></td><td data-label="IP address"><?= Security::escape($event['ip_address'] ?: 'Unavailable') ?></td></tr><?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</section>
