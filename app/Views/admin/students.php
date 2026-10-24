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

    <?php if (empty($loginHistoryAvailable)): ?>
        <div class="student-schema-notice" role="status"><strong>Login history needs its database table.</strong><span>Import the updated <code>database/migrations/001_initial_schema.sql</code> into <code>tsg_academy_lms</code>. The student directory is available now; login totals and activity will appear after import.</span></div>
    <?php endif; ?>

    <?php if ($selectedStudent): ?>
        <section class="dashboard-panel student-activity-panel" aria-labelledby="student-activity-title">
            <div class="dashboard-panel-heading">
                <div><p class="eyebrow">STUDENT ACCOUNT</p><h2 id="student-activity-title"><?= Security::escape($selectedStudent['full_name']) ?></h2><p><?= Security::escape($selectedStudent['email']) ?></p></div>
                <a class="button button-secondary" href="<?= Security::escape($baseUrl) ?>/admin/students?<?= Security::escape($queryUrl()) ?>">Close activity</a>
            </div>
            <div class="student-activity-summary">
                <span><strong>Status</strong><?= Security::escape(ucfirst($selectedStudent['status'])) ?></span>
                <span><strong>Registered</strong><?= Security::escape($selectedStudent['created_at']) ?></span>
                <span><strong>Last successful login</strong><?= Security::escape($selectedStudent['last_login_at'] ?: 'No successful login recorded') ?></span>
            </div>
            <h3>Recent sign-in activity <small>Latest 50 records · server time in UTC</small></h3>
            <?php if ($activity === []): ?>
                <div class="dashboard-empty"><div><h2><?= empty($loginHistoryAvailable) ? 'Login activity is not set up yet' : 'No sign-in history recorded yet' ?></h2><p><?= empty($loginHistoryAvailable) ? 'Import the updated 001_initial_schema.sql file into your existing database to enable sign-in history.' : 'New activity will appear after this feature is installed.' ?></p></div></div>
            <?php else: ?>
                <div class="admin-table-wrapper"><table class="admin-table student-activity-table"><thead><tr><th>Result</th><th>Date and time (UTC)</th><th>IP address</th></tr></thead><tbody>
                    <?php foreach ($activity as $event): ?><tr><td><span class="student-event-badge student-event-<?= Security::escape($event['event_type']) ?>"><?= Security::escape(ucfirst($event['event_type'])) ?></span></td><td><?= Security::escape($event['created_at']) ?></td><td><?= Security::escape($event['ip_address'] ?: 'Unavailable') ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="dashboard-panel" aria-label="Student directory">
        <div class="dashboard-panel-heading"><div><p class="eyebrow">STUDENT DIRECTORY</p><h2><?= number_format((int) ($totalStudents ?? 0)) ?> matching student<?= (int) ($totalStudents ?? 0) === 1 ? '' : 's' ?></h2><p>Login totals include events recorded after this feature is installed.</p></div></div>
        <form class="student-filter-form" method="get" action="<?= Security::escape($baseUrl) ?>/admin/students">
            <label class="admin-label" for="student-search">Search name or email<input class="admin-input" id="student-search" name="q" type="search" maxlength="100" value="<?= Security::escape($search) ?>" placeholder="e.g. Ayesha or student@example.com"></label>
            <label class="admin-label" for="student-status">Account status<select class="admin-select" id="student-status" name="status"><option value="all"<?= $statusFilter === 'all' ? ' selected' : '' ?>>All statuses</option><?php foreach (['active','pending','suspended'] as $status): ?><option value="<?= $status ?>"<?= $statusFilter === $status ? ' selected' : '' ?>><?= Security::escape(ucfirst($status)) ?></option><?php endforeach; ?></select></label>
            <button class="button" type="submit">Apply filters</button>
            <?php if ($search !== '' || $statusFilter !== 'all'): ?><a class="button button-secondary" href="<?= Security::escape($baseUrl) ?>/admin/students">Clear</a><?php endif; ?>
        </form>
        <?php if ($students === []): ?>
            <div class="dashboard-empty"><div><h2>No students found</h2><p>Try another name, email, or status filter.</p></div></div>
        <?php else: ?>
            <div class="admin-table-wrapper"><table class="admin-table student-directory-table"><thead><tr><th>Student</th><th>Status</th><th>Registered</th><th>Last login</th><th>Successful logins</th><th>Failed attempts</th><th></th></tr></thead><tbody>
                <?php foreach ($students as $student): ?><tr>
                    <td><strong><?= Security::escape($student['full_name']) ?></strong><small><?= Security::escape($student['email']) ?></small></td>
                    <td><span class="student-status-badge student-status-<?= Security::escape($student['status']) ?>"><?= Security::escape(ucfirst($student['status'])) ?></span></td>
                    <td><?= Security::escape($student['created_at']) ?></td>
                    <td><?= Security::escape($student['last_login_at'] ?: 'Never') ?></td>
                    <td><?= $student['successful_logins'] === null ? '—' : number_format((int) $student['successful_logins']) ?></td>
                    <td><?= $student['failed_logins'] === null ? '—' : number_format((int) $student['failed_logins']) ?></td>
                    <td><a class="student-activity-link" href="<?= Security::escape($baseUrl) ?>/admin/students?<?= Security::escape($queryUrl(['student_id' => (int) $student['id'], 'page' => $page])) ?>">View activity</a></td>
                </tr><?php endforeach; ?>
            </tbody></table></div>
            <?php if ($pages > 1): ?><nav class="student-pagination" aria-label="Student directory pages"><span>Page <?= $page ?> of <?= $pages ?></span><div><?php if ($page > 1): ?><a class="button button-secondary" href="<?= Security::escape($baseUrl) ?>/admin/students?<?= Security::escape($queryUrl(['page' => $page - 1])) ?>">Previous</a><?php endif; ?><?php if ($page < $pages): ?><a class="button button-secondary" href="<?= Security::escape($baseUrl) ?>/admin/students?<?= Security::escape($queryUrl(['page' => $page + 1])) ?>">Next</a><?php endif; ?></div></nav><?php endif; ?>
        <?php endif; ?>
        <p class="student-privacy-note">For privacy and security, this page never displays passwords or OTPs. Failed attempts for unknown email addresses are not linked to a student account.</p>
    </section>
</section>
