<?php

use App\Core\Security;

$user = isset($user) && is_array($user) ? $user : [];
$student = isset($student) && is_array($student) ? $student : [];
$admin = isset($admin) && is_array($admin) ? $admin : [];
$baseUrl = isset($baseUrl) && is_string($baseUrl) ? rtrim($baseUrl, '/') : rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $value): string => Security::escape((string) $value);
$isAdmin = ($user['role'] ?? '') === 'admin';
$flashMessage = is_string($message ?? null) && $message !== '' ? $message : null;
$flashError = is_string($error ?? null) && $error !== '' ? $error : null;
$num = static fn(mixed $value): string => number_format((float) $value);
$dateLabel = static function (?string $value): string {
    if (!$value) return '—';
    $timestamp = strtotime($value);
    return $timestamp === false ? '—' : date('M j, Y', $timestamp);
};
$timeLabel = static function (?string $value): string {
    if (!$value) return '—';
    $timestamp = strtotime($value);
    return $timestamp === false ? '—' : date('g:i A', $timestamp);
};
$money = static fn(mixed $amount, mixed $currency): string => $escape((string) $currency . ' ' . number_format((float) $amount, 2));
/** Largest share of a set, used to scale bar charts without dividing by zero. */
$maxOf = static function (array $values): float {
    $values = array_map(static fn($value): float => (float) $value, $values);
    return $values === [] ? 0.0 : max(1.0, max($values));
};
$pct = static fn(float $value, float $total): float => $total > 0 ? min(100.0, round($value / $total * 100, 1)) : 0.0;
/** Conic-gradient stops for the donut charts. */
$donut = static function (array $segments): string {
    $total = array_sum(array_column($segments, 'value'));
    $stops = [];
    $cursor = 0.0;
    $used = 0.0;
    foreach ($segments as $index => $segment) {
        $share = $total > 0 ? ((float) $segment['value'] / $total) * 100 : 0.0;
        if ($total <= 0) $share = $index === 0 ? 100.0 : 0.0;
        $from = round($cursor, 2);
        $cursor += $share;
        $to = $index === count($segments) - 1 ? 100 : round($cursor, 2);
        $used += (float) $segment['value'];
        $stops[] = sprintf('%s %s%% %s%%', $segment['color'], $from, $to);
    }
    return 'conic-gradient(' . implode(', ', $stops) . ')';
};
$sparkline = static function (array $series): array {
    $values = array_map(static fn(array $point): float => (float) $point['total'], $series);
    $high = $values === [] ? 0.0 : max($values);
    $low = $values === [] ? 0.0 : min($values);
    $span = $high - $low;
    $count = count($values);
    $points = [];
    foreach ($values as $index => $value) {
        $x = $count > 1 ? ($index / ($count - 1)) * 100 : 50;
        $y = $span > 0 ? 30 - ((($value - $low) / $span) * 26) : 16;
        $points[] = round($x, 2) . ',' . round($y, 2);
    }
    return ['line' => implode(' ', $points), 'area' => ($points === [] ? '' : '0,32 ' . implode(' ', $points) . ' 100,32')];
};
$todayLabel = gmdate('D, M j');
$range = (int) ($admin['range'] ?? 14);
$attendance = $isAdmin ? ($admin['attendance'] ?? []) : ($student['attendance'] ?? []);
?>
<section class="dashboard-page dashboard-analytics-page" data-reveal>
    <div class="dashboard-topbar">
        <button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
        <div class="dashboard-breadcrumb"><span>Workspace</span><i>/</i><strong><?= $isAdmin ? 'Admin dashboard' : 'Student dashboard' ?></strong></div>
        <label class="dashboard-search"><span aria-hidden="true">⌕</span><input type="search" data-dashboard-search placeholder="Search dashboard..." aria-label="Search dashboard content"></label>
    </div>

    <div class="dashboard-heading analytics-heading">
        <div>
            <p class="eyebrow"><?= $isAdmin ? 'ACADEMY OVERVIEW' : 'YOUR LEARNING SPACE' ?></p>
            <h1>Welcome, <?= $escape($user['name'] ?? 'User') ?></h1>
            <p><?= $isAdmin
                    ? 'Live statistics on students, attendance, payments, assessments and course activity.'
                    : 'Mark today\'s attendance, then track your courses, lessons and assessment progress.' ?></p>
        </div>
        <?php if ($isAdmin): ?>
            <nav class="analytics-ranges" aria-label="Statistics period">
                <?php foreach ((array) ($admin['ranges'] ?? [7, 14, 30]) as $option): ?>
                    <a class="analytics-range<?= (int) $option === $range ? ' is-active' : '' ?>" href="<?= $escape($baseUrl) ?>/dashboard?range=<?= (int) $option ?>"><?= (int) $option ?> days</a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
    </div>

    <?php \App\Core\View::toast('success', $flashMessage); ?>
    <?php \App\Core\View::toast('error', $flashError); ?>
    <?php if ($isAdmin && !empty($admin['schemaNotice'])): ?>
        <div class="student-schema-notice"><strong>Some statistics are unavailable.</strong> The database is missing one or more newer tables — re-import <code>database/migrations/001_initial_schema.sql</code> to load them. Existing figures still render.</div>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
        <?php
        $students = (array) ($admin['students'] ?? []);
        $courses = (array) ($admin['courses'] ?? []);
        $payments = (array) ($admin['payments'] ?? []);
        $quizzes = (array) ($admin['quizzes'] ?? []);
        $assignments = (array) ($admin['assignments'] ?? []);
        $certificates = (array) ($admin['certificates'] ?? []);
        $revenue = (array) ($admin['revenue'] ?? []);
        $enrolmentSeries = (array) ($admin['enrolmentSeries'] ?? []);
        $attendanceToday = (array) ($attendance['today'] ?? []);
        $attendanceWindow = (array) ($attendance['window'] ?? []);
        $attendanceDays = (array) ($attendance['days'] ?? []);
        $paymentSplit = (array) ($payments['split'] ?? []);
        $resultSplit = ['pass' => (int) ($quizzes['passed'] ?? 0), 'fail' => (int) ($quizzes['failed'] ?? 0), 'in_progress' => (int) ($quizzes['inProgress'] ?? 0)];
        $certificateSplit = (array) ($certificates['split'] ?? []);
        $topCourses = (array) ($admin['topCourses'] ?? []);
        $alerts = (array) ($admin['alerts'] ?? []);
        $enrolmentMax = $maxOf(array_column($enrolmentSeries, 'total'));
        $attendanceMax = $maxOf(array_merge(array_column($attendanceDays, 'present'), array_column($attendanceDays, 'absent')));
        $revenueSpark = $sparkline((array) ($revenue['series'] ?? []));
        $kpi = [
            ['label' => 'Students', 'value' => $num($students['total'] ?? 0), 'hint' => $num($students['active'] ?? 0) . ' active · ' . $num($students['blocked'] ?? 0) . ' blocked', 'delta' => '+' . $num($students['newThisMonth'] ?? 0) . ' this month', 'tone' => 'blue', 'icon' => '👥'],
            ['label' => 'Present today', 'value' => $num($attendanceToday['present'] ?? 0), 'hint' => $num($attendanceToday['total'] ?? 0) . ' signed in today', 'delta' => ($attendance['available'] ?? false) ? number_format((float) ($attendanceToday['rate'] ?? 0), 1) . '% present rate' : 'Attendance off', 'tone' => 'emerald', 'icon' => '✓'],
            ['label' => 'Absent today', 'value' => $num($attendanceToday['absent'] ?? 0), 'hint' => $num($attendanceToday['pending'] ?? 0) . ' still inside the window', 'delta' => 'Auto-marked after grace', 'tone' => 'rose', 'icon' => '✕'],
            ['label' => 'Pending payments', 'value' => $num($payments['pending'] ?? 0), 'hint' => $num($payments['pendingOver24h'] ?? 0) . ' waiting over 24h', 'delta' => $num($payments['approvedThisMonth'] ?? 0) . ' approved this month', 'tone' => 'amber', 'icon' => '₨'],
            ['label' => 'Quiz attempts', 'value' => $num($quizzes['attempts'] ?? 0), 'hint' => $num($quizzes['passed'] ?? 0) . ' passed · ' . $num($quizzes['failed'] ?? 0) . ' failed', 'delta' => $quizzes['average'] === null ? 'No scores yet' : 'Avg ' . number_format((float) $quizzes['average'], 1) . '%', 'tone' => 'violet', 'icon' => '◎'],
            ['label' => 'Assignments to review', 'value' => $num($assignments['pendingReview'] ?? 0), 'hint' => $num($assignments['submitted'] ?? 0) . ' submitted · ' . $num($assignments['notSubmitted'] ?? 0) . ' pending', 'delta' => $num($assignments['late'] ?? 0) . ' late submissions', 'tone' => 'indigo', 'icon' => '✎'],
        ];
        ?>
        <div class="kpi-grid" aria-label="Key statistics">
            <?php foreach ($kpi as $item): ?>
                <article class="kpi-card kpi-<?= $escape($item['tone']) ?>">
                    <div class="kpi-top"><span class="kpi-icon" aria-hidden="true"><?= $escape($item['icon']) ?></span><span class="kpi-label"><?= $escape($item['label']) ?></span></div>
                    <strong class="kpi-value"><?= $escape($item['value']) ?></strong>
                    <p class="kpi-hint"><?= $escape($item['hint']) ?></p>
                    <span class="kpi-delta"><?= $escape($item['delta']) ?></span>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="analytics-grid">
            <section class="dashboard-panel chart-card chart-card-wide">
                <div class="dashboard-panel-heading">
                    <div>
                        <p class="eyebrow">GROWTH</p>
                        <h2>New course enrollments</h2>
                        <small>Last 6 months of access grants</small>
                    </div>
                    <a class="dashboard-text-link" href="<?= $escape($baseUrl) ?>/admin/students">Students <span aria-hidden="true">→</span></a>
                </div>
                <?php if ($enrolmentMax <= 1 && array_sum(array_column($enrolmentSeries, 'total')) === 0): ?>
                    <div class="dashboard-empty"><span class="dashboard-empty-icon">📈</span>
                        <div><h3>No enrollments recorded yet</h3><p>Grant course access after a verified payment and the chart fills automatically.</p></div>
                    </div>
                <?php else: ?>
                    <div class="chart-bars" role="img" aria-label="New enrollments per month for the last six months">
                        <?php foreach ($enrolmentSeries as $point):
                            $height = $pct((float) $point['total'], $enrolmentMax); ?>
                            <div class="chart-col">
                                <span class="chart-value"><?= $num($point['total']) ?></span>
                                <span class="chart-bar bar-blue" style="--bar-height: <?= max(4, $height) ?>%"></span>
                                <span class="chart-label"><?= $escape($point['label']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="dashboard-panel chart-card">
                <div class="dashboard-panel-heading">
                    <div>
                        <p class="eyebrow">ATTENDANCE</p>
                        <h2>Present vs absent</h2>
                        <small>Last <?= (int) $range ?> days</small>
                    </div>
                    <span class="chart-badge"><?= $num($attendanceWindow['rate'] ?? 0) ?>% rate</span>
                </div>
                <?php if (empty($attendance['available'])): ?>
                    <div class="dashboard-empty"><span class="dashboard-empty-icon">🗓</span>
                        <div><h3>Attendance is not enabled</h3><p>Import the latest schema to start recording daily student attendance.</p></div>
                    </div>
                <?php elseif ($attendanceMax <= 1 && array_sum(array_column($attendanceDays, 'present')) + array_sum(array_column($attendanceDays, 'absent')) === 0): ?>
                    <div class="dashboard-empty"><span class="dashboard-empty-icon">🗓</span>
                        <div><h3>No attendance yet</h3><p>Records appear as soon as students sign in and confirm presence.</p></div>
                    </div>
                <?php else: ?>
                    <div class="chart-bars chart-bars-paired" role="img" aria-label="Attendance present and absent per day">
                        <?php foreach ($attendanceDays as $index => $day):
                            $presentHeight = $pct((float) $day['present'], $attendanceMax);
                            $absentHeight = $pct((float) $day['absent'], $attendanceMax);
                            $labelEvery = count($attendanceDays) > 16 ? 5 : 2; ?>
                            <div class="chart-col" title="<?= $escape($day['date']) ?>: <?= (int) $day['present'] ?> present, <?= (int) $day['absent'] ?> absent">
                                <span class="chart-pair">
                                    <span class="chart-bar bar-emerald" style="--bar-height: <?= max(3, $presentHeight) ?>%"></span>
                                    <span class="chart-bar bar-rose" style="--bar-height: <?= max(3, $absentHeight) ?>%"></span>
                                </span>
                                <span class="chart-label"><?= $index % $labelEvery === 0 ? $escape(date('j M', strtotime($day['date']))) : '' ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="chart-legend">
                        <span><i class="legend-dot legend-emerald"></i>Present <?= $num($attendanceWindow['present'] ?? 0) ?></span>
                        <span><i class="legend-dot legend-rose"></i>Absent <?= $num($attendanceWindow['absent'] ?? 0) ?></span>
                    </div>
                <?php endif; ?>
            </section>

            <section class="dashboard-panel chart-card">
                <div class="dashboard-panel-heading">
                    <div>
                        <p class="eyebrow">REVENUE</p>
                        <h2>Approved payments</h2>
                        <small><?= $escape($revenue['currency'] ?? 'PKR') ?> · last 6 months</small>
                    </div>
                    <a class="dashboard-text-link" href="<?= $escape($baseUrl) ?>/admin/payments">Revenue <span aria-hidden="true">→</span></a>
                </div>
                <div class="revenue-figure">
                    <span>Total balance</span>
                    <strong><?= $escape($revenue['currency'] ?? 'PKR') ?> <?= number_format((float) ($revenue['lifetime'] ?? 0), 2) ?></strong>
                    <small><?= $escape($revenue['currency'] ?? 'PKR') ?> <?= number_format((float) ($revenue['month'] ?? 0), 2) ?> approved this month</small>
                </div>
                <?php if (($revenue['series'] ?? []) === []): ?>
                    <div class="chart-empty-line">No approved payments recorded yet.</div>
                <?php else: ?>
                    <svg class="chart-spark" viewBox="0 0 100 32" preserveAspectRatio="none" role="img" aria-label="Approved revenue trend for the last six months">
                        <?php if ($revenueSpark['area'] !== ''): ?><polygon class="spark-area" points="<?= $escape($revenueSpark['area']) ?>"></polygon><?php endif; ?>
                        <polyline class="spark-line" points="<?= $escape($revenueSpark['line']) ?>"></polyline>
                    </svg>
                    <div class="chart-legend chart-legend-months">
                        <?php foreach ((array) ($revenue['series'] ?? []) as $point): ?><span><?= $escape($point['label']) ?></span><?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if (count((array) ($revenue['currencies'] ?? [])) > 1): ?>
                    <p class="chart-footnote">Other currencies: <?php foreach ((array) $revenue['currencies'] as $index => $bucket): if ($index === 0) continue; ?><span><?= $escape($bucket['currency']) ?> <?= number_format((float) $bucket['lifetime'], 2) ?></span><?php endforeach; ?></p>
                <?php endif; ?>
            </section>

            <section class="dashboard-panel chart-card">
                <div class="dashboard-panel-heading">
                    <div>
                        <p class="eyebrow">PAYMENTS</p>
                        <h2>Verification status</h2>
                        <small><?= $num($payments['total'] ?? 0) ?> submissions all time</small>
                    </div>
                </div>
                <div class="donut-wrap">
                    <div class="donut" style="--donut: <?= $escape($donut([
                            ['value' => (int) ($paymentSplit['approved'] ?? 0), 'color' => 'var(--att-emerald)'],
                            ['value' => (int) ($paymentSplit['pending'] ?? 0), 'color' => 'var(--att-amber)'],
                            ['value' => (int) ($paymentSplit['rejected'] ?? 0), 'color' => 'var(--att-rose)'],
                        ])) ?>") role="img" aria-label="Payment status distribution">
                        <span class="donut-center"><strong><?= $num($payments['total'] ?? 0) ?></strong><small>Total</small></span>
                    </div>
                    <ul class="donut-legend">
                        <li><i class="legend-dot legend-emerald"></i><span>Approved</span><strong><?= $num($paymentSplit['approved'] ?? 0) ?></strong></li>
                        <li><i class="legend-dot legend-amber"></i><span>Pending</span><strong><?= $num($paymentSplit['pending'] ?? 0) ?></strong></li>
                        <li><i class="legend-dot legend-rose"></i><span>Rejected</span><strong><?= $num($paymentSplit['rejected'] ?? 0) ?></strong></li>
                    </ul>
                </div>
            </section>

            <section class="dashboard-panel chart-card">
                <div class="dashboard-panel-heading">
                    <div>
                        <p class="eyebrow">ASSESSMENTS</p>
                        <h2>Quiz results</h2>
                        <small>Last <?= (int) $range ?> days</small>
                    </div>
                    <a class="dashboard-text-link" href="<?= $escape($baseUrl) ?>/admin/quiz-reports">Reports <span aria-hidden="true">→</span></a>
                </div>
                <div class="donut-wrap">
                    <div class="donut" style="--donut: <?= $escape($donut([
                            ['value' => $resultSplit['pass'], 'color' => 'var(--att-emerald)'],
                            ['value' => $resultSplit['fail'], 'color' => 'var(--att-rose)'],
                            ['value' => $resultSplit['in_progress'], 'color' => 'var(--att-blue)'],
                        ])) ?>") role="img" aria-label="Quiz attempt results">
                        <span class="donut-center"><strong><?= $num(array_sum($resultSplit)) ?></strong><small>Attempts</small></span>
                    </div>
                    <ul class="donut-legend">
                        <li><i class="legend-dot legend-emerald"></i><span>Passed</span><strong><?= $num($resultSplit['pass']) ?></strong></li>
                        <li><i class="legend-dot legend-rose"></i><span>Failed</span><strong><?= $num($resultSplit['fail']) ?></strong></li>
                        <li><i class="legend-dot legend-blue"></i><span>In progress</span><strong><?= $num($resultSplit['in_progress']) ?></strong></li>
                    </ul>
                </div>
            </section>

            <section class="dashboard-panel chart-card">
                <div class="dashboard-panel-heading">
                    <div>
                        <p class="eyebrow">SUBMISSIONS</p>
                        <h2>Assignments &amp; certificates</h2>
                        <small>Against eligible enrollments</small>
                    </div>
                </div>
                <div class="meter-list">
                    <?php
                    $meters = [
                        ['label' => 'Assignments submitted', 'value' => (int) $assignments['submitted'], 'total' => (int) $assignments['expected'], 'tone' => 'emerald'],
                        ['label' => 'Awaiting your review', 'value' => (int) $assignments['pendingReview'], 'total' => max(1, (int) $assignments['submitted']), 'tone' => 'amber'],
                        ['label' => 'Quizzes released', 'value' => (int) $quizzes['released'], 'total' => max(1, (int) $quizzes['total']), 'tone' => 'blue'],
                        ['label' => 'Certificates issued', 'value' => (int) ($certificateSplit['issued'] ?? 0), 'total' => max(1, array_sum($certificateSplit)), 'tone' => 'violet'],
                        ['label' => 'Lessons completed', 'value' => (int) $courses['lessonsCompleted'], 'total' => max(1, (int) $courses['lessonsPublished']), 'tone' => 'indigo'],
                    ];
                    foreach ($meters as $meter):
                        $ratio = $meter['total'] > 0 ? min(100, (int) round($meter['value'] / $meter['total'] * 100)) : 0; ?>
                        <div class="meter-row">
                            <div class="meter-head"><span><?= $escape($meter['label']) ?></span><strong><?= $num($meter['value']) ?><?= $meter['label'] === 'Assignments submitted' ? ' / ' . $num($meter['total']) : '' ?> · <?= $ratio ?>%</strong></div>
                            <div class="meter" role="progressbar" aria-label="<?= $escape($meter['label']) ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $ratio ?>"><span class="meter-fill meter-<?= $escape($meter['tone']) ?>" style="--meter-width: <?= $ratio ?>%"></span></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="chart-footnote"><?= $num($assignments['notSubmitted'] ?? 0) ?> expected submissions still missing · <?= $num($certificateSplit['requested'] ?? 0) ?> certificate requests pending</p>
            </section>

            <section class="dashboard-panel chart-card">
                <div class="dashboard-panel-heading">
                    <div>
                        <p class="eyebrow">CATALOGUE</p>
                        <h2>Top courses</h2>
                        <small>Active enrollments</small>
                    </div>
                    <div class="dashboard-heading-links">
                        <a class="dashboard-text-link" href="<?= $escape($baseUrl) ?>/admin/lessons">Lessons <span aria-hidden="true">→</span></a>
                        <a class="dashboard-text-link" href="<?= $escape($baseUrl) ?>/admin/courses">Manage <span aria-hidden="true">→</span></a>
                    </div>
                </div>
                <?php if ($topCourses === []): ?>
                    <div class="dashboard-empty"><span class="dashboard-empty-icon">📚</span>
                        <div><h3>No courses yet</h3><p>Create a course and grant access to see the ranking.</p></div>
                    </div>
                <?php else: ?>
                    <?php $courseMax = $maxOf(array_column($topCourses, 'students')); ?>
                    <ol class="rank-list">
                        <?php foreach ($topCourses as $index => $courseRow):
                            $width = $pct((float) $courseRow['students'], $courseMax); ?>
                            <li class="rank-row">
                                <span class="rank-number"><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
                                <span class="rank-copy"><strong><?= $escape($courseRow['title']) ?></strong><small><?= $num($courseRow['students']) ?> active students</small>
                                    <span class="rank-track"><span class="rank-fill" style="--rank-width: <?= max(3, $width) ?>%"></span></span>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                    <p class="chart-footnote"><?= $num($courses['published'] ?? 0) ?> published · <?= $num($courses['draft'] ?? 0) ?> draft · <?= $num($courses['hidden'] ?? 0) ?> hidden or archived</p>
                <?php endif; ?>
            </section>

            <section class="dashboard-panel chart-card">
                <div class="dashboard-panel-heading">
                    <div>
                        <p class="eyebrow">OPERATIONS</p>
                        <h2>Needs attention</h2>
                        <small>Live queue checks</small>
                    </div>
                </div>
                <ul class="alert-list">
                    <?php foreach ($alerts as $alert): ?>
                        <li class="alert-row alert-<?= $escape($alert['level']) ?>">
                            <span class="alert-dot" aria-hidden="true"></span>
                            <span class="alert-copy"><strong><?= $escape($alert['title']) ?></strong><small><?= $escape($alert['detail']) ?></small></span>
                            <a class="alert-link" href="<?= $escape($baseUrl . $alert['href']) ?>">Open <span aria-hidden="true">→</span></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (!empty($attendance['available']) && (array) ($attendance['attention'] ?? []) !== []): ?>
                    <div class="attention-box">
                        <h3>Most absences this month</h3>
                        <ul>
                            <?php foreach ((array) $attendance['attention'] as $row): ?>
                                <li><span><?= $escape($row['full_name']) ?></span><strong><?= $num($row['absent_total']) ?> days</strong></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </section>

            <?php
            $studentAwaiting = max(0, (int) ($students['total'] ?? 0) - (int) ($students['active'] ?? 0) - (int) ($students['blocked'] ?? 0));
            $certificateTotal = array_sum($certificateSplit);
            ?>
            <section class="dashboard-panel chart-card">
                <div class="dashboard-panel-heading">
                    <div>
                        <p class="eyebrow">ACCOUNTS</p>
                        <h2>Student status</h2>
                        <small><?= $num($students['total'] ?? 0) ?> registered</small>
                    </div>
                    <a class="dashboard-text-link" href="<?= $escape($baseUrl) ?>/admin/students">Manage <span aria-hidden="true">→</span></a>
                </div>
                <div class="donut-wrap">
                    <div class="donut" style="--donut: <?= $escape($donut([
                            ['value' => (int) ($students['active'] ?? 0), 'color' => 'var(--att-emerald)'],
                            ['value' => $studentAwaiting, 'color' => 'var(--att-amber)'],
                            ['value' => (int) ($students['blocked'] ?? 0), 'color' => 'var(--att-rose)'],
                        ])) ?>") role="img" aria-label="Student account status distribution">
                        <span class="donut-center"><strong><?= $num($students['active'] ?? 0) ?></strong><small>Active</small></span>
                    </div>
                    <ul class="donut-legend">
                        <li><i class="legend-dot legend-emerald"></i><span>Active</span><strong><?= $num($students['active'] ?? 0) ?></strong></li>
                        <li><i class="legend-dot legend-amber"></i><span>Awaiting verification</span><strong><?= $num($studentAwaiting) ?></strong></li>
                        <li><i class="legend-dot legend-rose"></i><span>Suspended</span><strong><?= $num($students['blocked'] ?? 0) ?></strong></li>
                    </ul>
                </div>
                <div class="chart-stats">
                    <div><strong>+<?= $num($students['newThisMonth'] ?? 0) ?></strong><span>New this month</span></div>
                    <div><strong><?= $num($students['withAccess'] ?? 0) ?></strong><span>With course access</span></div>
                </div>
            </section>

            <section class="dashboard-panel chart-card">
                <div class="dashboard-panel-heading">
                    <div>
                        <p class="eyebrow">CERTIFICATES</p>
                        <h2>Issuance pipeline</h2>
                        <small><?= $num($certificateTotal) ?> requests all time</small>
                    </div>
                    <a class="dashboard-text-link" href="<?= $escape($baseUrl) ?>/admin/certificates">Review <span aria-hidden="true">→</span></a>
                </div>
                <div class="meter-list">
                    <?php
                    $certificateMeters = [
                        ['label' => 'Certificates issued', 'value' => (int) ($certificateSplit['issued'] ?? 0), 'tone' => 'violet'],
                        ['label' => 'Requests awaiting review', 'value' => (int) ($certificateSplit['requested'] ?? 0), 'tone' => 'amber'],
                        ['label' => 'Requests rejected', 'value' => (int) ($certificateSplit['rejected'] ?? 0), 'tone' => 'rose'],
                    ];
                    foreach ($certificateMeters as $meter):
                        $ratio = $certificateTotal > 0 ? min(100, (int) round($meter['value'] / $certificateTotal * 100)) : 0; ?>
                        <div class="meter-row">
                            <div class="meter-head"><span><?= $escape($meter['label']) ?></span><strong><?= $num($meter['value']) ?> · <?= $ratio ?>%</strong></div>
                            <div class="meter" role="progressbar" aria-label="<?= $escape($meter['label']) ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $ratio ?>"><span class="meter-fill meter-<?= $escape($meter['tone']) ?>" style="--meter-width: <?= $ratio ?>%"></span></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="chart-footnote"><?= $num($certificates['expired'] ?? 0) ?> issued certificates are past their expiry date</p>
            </section>

            <section class="dashboard-panel dashboard-searchable chart-card-wide" id="payments">
                <div class="dashboard-panel-heading">
                    <div>
                        <p class="eyebrow">PAYMENT ACTIVITY</p>
                        <h2>Recent payments</h2>
                    </div><span class="dashboard-live-label"><i></i> Live data</span>
                </div>
                <?php if (($admin['recentPayments'] ?? []) === []): ?>
                    <div class="dashboard-empty"><span class="dashboard-empty-icon">₨</span>
                        <div><h3>No payments yet</h3><p>Student payment submissions will appear here when they are recorded.</p></div>
                    </div>
                <?php else: ?>
                    <div class="dashboard-table-wrap">
                        <table class="dashboard-table">
                            <thead><tr><th>Student / course</th><th>Amount</th><th>Status</th><th>Received</th></tr></thead>
                            <tbody>
                                <?php foreach ($admin['recentPayments'] as $payment): ?>
                                    <tr>
                                        <td data-label="Student"><strong><?= $escape($payment['student_name']) ?></strong><small><?= $escape($payment['course_title']) ?></small></td>
                                        <td data-label="Amount"><?= $money($payment['amount'], $payment['currency']) ?></td>
                                        <td data-label="Status"><span class="dashboard-status status-<?= $escape($payment['status']) ?>"><?= $escape(ucfirst($payment['status'])) ?></span></td>
                                        <td data-label="Received"><?= $escape($dateLabel($payment['created_at'] ?? null)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section class="dashboard-panel dashboard-searchable chart-card-wide" id="quizzes">
                <div class="dashboard-panel-heading">
                    <div>
                        <p class="eyebrow">ASSESSMENT ACTIVITY</p>
                        <h2>Recent quiz attempts</h2>
                    </div>
                </div>
                <?php if (($admin['recentAttempts'] ?? []) === []): ?>
                    <div class="dashboard-empty"><span class="dashboard-empty-icon">✓</span>
                        <div><h3>No quiz attempts yet</h3><p>Student attempts and results will show here after quizzes are available.</p></div>
                    </div>
                <?php else: ?>
                    <div class="dashboard-list">
                        <?php foreach ($admin['recentAttempts'] as $attempt):
                            $result = (string) ($attempt['result'] ?? '');
                            $status = $attempt['status'] === 'in_progress' ? 'pending' : ($result === 'pass' ? 'approved' : ($result === 'fail' ? 'rejected' : $attempt['status'])); ?>
                            <article class="dashboard-list-row"><span class="dashboard-list-icon">Q</span>
                                <div class="dashboard-list-copy"><strong><?= $escape($attempt['quiz_title']) ?></strong><small><?= $escape($attempt['student_name']) ?> · <?= $escape($attempt['course_title']) ?><?= $attempt['percentage'] === null ? '' : ' · ' . number_format((float) $attempt['percentage'], 1) . '%' ?></small></div>
                                <span class="dashboard-status status-<?= $escape($status) ?>"><?= $escape(ucwords(str_replace('_', ' ', (string) $attempt['status']))) ?></span>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="assessment-report-link"><a class="dashboard-text-link" href="<?= $escape($baseUrl) ?>/admin/quiz-reports">See report <span aria-hidden="true">→</span></a></div>
            </section>
        </div>

        <div class="dashboard-note"><strong>Workspace overview</strong><span>Figures are calculated live from the academy database. Attendance is opened by each student sign-in and closes automatically when the grace window ends. Use the sidebar to manage courses, assessments, certificates, payments and settings.</span></div>

    <?php else: ?>
        <?php
        $attendanceHistory = (array) ($attendance['history'] ?? []);
        $attendanceDays = (array) ($attendance['days'] ?? []);
        $attendanceToday = (array) ($attendance['today'] ?? []);
        $attendanceStatus = (string) ($attendanceToday['status'] ?? 'none');
        $deadlineRaw = trim((string) ($attendanceToday['mark_deadline_at'] ?? ''));
        // Stored deadlines are UTC, so the remaining time is measured against the UTC instant.
        $deadlineTimestamp = $deadlineRaw === '' ? false : strtotime($deadlineRaw . ' UTC');
        $secondsLeft = $deadlineTimestamp === false ? 0 : max(0, $deadlineTimestamp - time());
        ?>
        <section class="attendance-card attendance-<?= $escape($attendanceStatus) ?>">
            <div class="attendance-main">
                <p class="eyebrow">TODAY · <?= $escape($todayLabel) ?></p>
                <h2>Attendance</h2>
                <?php if (empty($attendance['available'])): ?>
                    <p class="attendance-copy">Attendance tracking is not enabled on this server yet. The Academy will import the latest schema and this card will appear.</p>
                <?php elseif ($attendanceStatus === 'present'): ?>
                    <p class="attendance-copy">You are marked <strong>present</strong><?= $attendanceToday['marked_at'] ? ' at ' . $escape($timeLabel((string) $attendanceToday['marked_at'])) : '' ?>. Keep up the consistency.</p>
                    <span class="attendance-badge attendance-badge-present"><i></i> Present today</span>
                <?php elseif ($attendanceStatus === 'absent'): ?>
                    <p class="attendance-copy">Today's window closed before you confirmed presence, so this day is recorded as <strong>absent</strong>. Contact the Academy if you were present.</p>
                    <span class="attendance-badge attendance-badge-absent"><i></i> Absent today</span>
                <?php elseif ($attendanceStatus === 'pending'): ?>
                    <p class="attendance-copy">Confirm you are present within <strong><?= (int) ($attendance['grace_minutes'] ?? 5) ?> minutes</strong> of signing in. After the window closes the day is marked absent automatically.</p>
                    <form method="post" action="<?= $escape($baseUrl) ?>/student/attendance/mark" class="attendance-form" data-attendance-countdown="<?= (int) $secondsLeft ?>">
                        <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
                        <button class="button attendance-mark-button" type="submit">Mark me present</button>
                        <span class="attendance-timer" aria-live="polite"></span>
                    </form>
                <?php else: ?>
                    <p class="attendance-copy">Sign in starts your attendance window. Reload this page if today's card has not appeared yet.</p>
                <?php endif; ?>
            </div>
            <?php if (!empty($attendance['available'])): ?>
                <div class="attendance-stats">
                    <div><strong><?= $num($attendance['present'] ?? 0) ?></strong><span>Present · 30 days</span></div>
                    <div><strong><?= $num($attendance['absent'] ?? 0) ?></strong><span>Absent · 30 days</span></div>
                    <div><strong><?= number_format((float) ($attendance['rate'] ?? 0), 1) ?>%</strong><span>Attendance rate</span></div>
                    <div><strong><?= $num($attendance['streak'] ?? 0) ?></strong><span>Day streak</span></div>
                </div>
                <div class="attendance-strip" role="img" aria-label="Attendance for the last 30 days">
                    <?php foreach ($attendanceDays as $day): ?>
                        <span class="attendance-dot dot-<?= $escape($day['status']) ?>" title="<?= $escape($day['date']) ?>: <?= $escape(ucfirst($day['status'])) ?>"></span>
                    <?php endforeach; ?>
                </div>
                <div class="attendance-legend">
                    <span><i class="legend-dot legend-emerald"></i>Present</span>
                    <span><i class="legend-dot legend-rose"></i>Absent</span>
                    <span><i class="legend-dot legend-amber"></i>Waiting</span>
                    <span><i class="legend-dot legend-none"></i>No sign-in</span>
                </div>
            <?php endif; ?>
        </section>

        <div class="kpi-grid kpi-grid-student" aria-label="Your summary">
            <article class="kpi-card kpi-blue"><div class="kpi-top"><span class="kpi-icon" aria-hidden="true">📚</span><span class="kpi-label">My courses</span></div><strong class="kpi-value"><?= $num($student['activeCourseCount'] ?? 0) ?></strong><p class="kpi-hint">Active access grants</p></article>
            <article class="kpi-card kpi-emerald"><div class="kpi-top"><span class="kpi-icon" aria-hidden="true">✓</span><span class="kpi-label">Lessons completed</span></div><strong class="kpi-value"><?= $num($student['completedLessonCount'] ?? 0) ?></strong><p class="kpi-hint">Across your courses</p></article>
            <article class="kpi-card kpi-violet"><div class="kpi-top"><span class="kpi-icon" aria-hidden="true">◎</span><span class="kpi-label">Quiz attempts</span></div><strong class="kpi-value"><?= $num($student['quizAttempts'] ?? 0) ?></strong><p class="kpi-hint">Saved on your account</p></article>
            <article class="kpi-card kpi-indigo"><div class="kpi-top"><span class="kpi-icon" aria-hidden="true">✎</span><span class="kpi-label">Assignments</span></div><strong class="kpi-value"><?= $num($student['assignmentSubmissions'] ?? 0) ?></strong><p class="kpi-hint">Submissions recorded</p></article>
            <article class="kpi-card kpi-amber"><div class="kpi-top"><span class="kpi-icon" aria-hidden="true">₨</span><span class="kpi-label">Pending payments</span></div><strong class="kpi-value"><?= $num($student['pendingPaymentCount'] ?? 0) ?></strong><p class="kpi-hint">Awaiting verification</p></article>
            <article class="kpi-card kpi-rose"><div class="kpi-top"><span class="kpi-icon" aria-hidden="true">🔔</span><span class="kpi-label">Unread notices</span></div><strong class="kpi-value"><?= $num($student['unreadNotificationCount'] ?? 0) ?></strong><p class="kpi-hint">From the Academy</p></article>
        </div>

        <div class="dashboard-columns dashboard-student-overview">
            <section class="dashboard-panel dashboard-searchable" id="my-courses">
                <div class="dashboard-panel-heading">
                    <div>
                        <p class="eyebrow">YOUR ENROLLMENT</p>
                        <h2>My courses</h2>
                    </div><div class="dashboard-heading-links"><a class="dashboard-text-link" href="<?= $escape($baseUrl) ?>/student/lessons">All lessons →</a><a class="dashboard-text-link" href="<?= $escape($baseUrl) ?>/courses">Choose a course →</a></div>
                </div>
                <?php if (($student['courses'] ?? []) === []): ?>
                    <?php $pendingPayments = (int) ($student['pendingPaymentCount'] ?? 0); ?>
                    <div class="dashboard-empty"><span class="dashboard-empty-icon">＋</span>
                        <div>
                            <h3><?= $pendingPayments > 0 ? 'Your payment is being verified' : 'Your learning starts here' ?></h3>
                            <?php if ($pendingPayments > 0): ?>
                                <p>You have <?= $pendingPayments ?> payment <?= $pendingPayments === 1 ? '' : 's' ?> waiting for an administrator. Course access opens as soon as it is approved — you will get an email and a notification here, so there is nothing to buy again.</p><a class="dashboard-inline-link" href="<?= $escape($baseUrl) ?>/student/payments">See payment status →</a>
                            <?php else: ?>
                                <p>You do not have a course access grant yet. Choose a course, and enrollment opens with it already picked — pay, then the Academy enables access.</p><a class="dashboard-inline-link" href="<?= $escape($baseUrl) ?>/courses">Choose a course to enroll →</a>
                            <?php endif; ?>
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

            <?php if ($attendanceHistory !== []): ?>
                <section class="dashboard-panel" id="attendance-history">
                    <div class="dashboard-panel-heading">
                        <div>
                            <p class="eyebrow">RECORD</p>
                            <h2>Recent attendance</h2>
                        </div>
                    </div>
                    <ul class="attendance-history">
                        <?php foreach ($attendanceHistory as $record): ?>
                            <li>
                                <span><?= $escape($dateLabel((string) $record['attendance_date'])) ?></span>
                                <em class="attendance-chip chip-<?= $escape($record['status']) ?>"><?= $escape(ucfirst((string) $record['status'])) ?></em>
                                <small><?= $record['status'] === 'present' ? 'Marked ' . $escape($timeLabel($record['marked_at'] ?? null)) : 'Signed in ' . $escape($timeLabel($record['first_login_at'] ?? null)) ?></small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php /* No walkthrough here on purpose: the dashboard summarises the other pages, and each of
               those already closes with its own guide. */ ?>

</section>
