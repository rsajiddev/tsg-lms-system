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
/** Smooth cubic path through a set of {x,y} viewBox points, used by the multi-line trend chart. */
$smoothLine = static function (array $pts): string {
    $path = '';
    foreach ($pts as $index => $p) {
        if ($index === 0) { $path .= 'M' . $p['x'] . ',' . $p['y']; continue; }
        $prev = $pts[$index - 1];
        $cx = ($p['x'] - $prev['x']) * 0.28;
        $path .= sprintf(' C%.*F,%.*F %.*F,%.*F %.*F,%.*F', 2, $prev['x'] + $cx, 2, $prev['y'], 2, $p['x'] - $cx, 2, $p['y'], 2, $p['x'], 2, $p['y']);
    }
    return $path;
};
$todayLabel = gmdate('D, M j');
$range = (int) ($admin['range'] ?? 14);
$attendance = $isAdmin ? ($admin['attendance'] ?? []) : ($student['attendance'] ?? []);
?>
<section class="dashboard-page dashboard-analytics-page" data-reveal>
    <div class="dashboard-topbar">
        <button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
        <div class="dashboard-breadcrumb"><span>Workspace</span><i>/</i><strong><?= $isAdmin ? 'Admin dashboard' : 'Student dashboard' ?></strong></div>
        <div class="dashboard-search-wrap" data-dashboard-search-wrap data-search-url="<?= $escape($baseUrl) ?>/dashboard/search" data-search-base="<?= $escape($baseUrl) ?>">
            <label class="dashboard-search"><span aria-hidden="true">⌕</span><input type="search" data-dashboard-search autocomplete="off" placeholder="Search courses, students, payments…" aria-label="Search across dashboard data" aria-controls="dashboard-search-results" aria-expanded="false"></label>
            <div class="dashboard-search-results" id="dashboard-search-results" role="listbox" hidden><p class="dashboard-search-hint">Type at least two characters to search your dashboard.</p></div>
        </div>
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
            <form class="analytics-ranges" method="get" action="<?= $escape($baseUrl) ?>/dashboard" aria-label="Statistics period">
                <label for="analytics-range-select">Show stats for</label>
                <select class="analytics-range-select" id="analytics-range-select" name="range" data-auto-submit>
                    <?php foreach ((array) ($admin['ranges'] ?? [7, 14, 30]) as $option): ?>
                        <option value="<?= (int) $option ?>" <?= (int) $option === $range ? 'selected' : '' ?>>Last <?= (int) $option ?> days</option>
                    <?php endforeach; ?>
                </select>
            </form>
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
            ['label' => 'Students', 'value' => $num($students['total'] ?? 0), 'hint' => $num($students['active'] ?? 0) . ' active · ' . $num($students['blocked'] ?? 0) . ' blocked', 'delta' => '+' . $num($students['newThisMonth'] ?? 0) . ' this month', 'series' => 'students', 'color' => '#60a5fa', 'icon' => '👥'],
            ['label' => 'Present today', 'value' => $num($attendanceToday['present'] ?? 0), 'hint' => $num($attendanceToday['total'] ?? 0) . ' signed in today', 'delta' => ($attendance['available'] ?? false) ? number_format((float) ($attendanceToday['rate'] ?? 0), 1) . '% present rate' : 'Attendance off', 'series' => 'present', 'color' => '#34d399', 'icon' => '✓'],
            ['label' => 'Absent today', 'value' => $num($attendanceToday['absent'] ?? 0), 'hint' => $num($attendanceToday['pending'] ?? 0) . ' still inside the window', 'delta' => 'Auto-marked after grace', 'series' => 'absent', 'color' => '#fb7185', 'icon' => '✕'],
            ['label' => 'Pending payments', 'value' => $num($payments['pending'] ?? 0), 'hint' => $num($payments['pendingOver24h'] ?? 0) . ' waiting over 24h', 'delta' => $num($payments['approvedThisMonth'] ?? 0) . ' approved this month', 'series' => 'payments', 'color' => '#fbbf24', 'icon' => '₨'],
            ['label' => 'Quiz attempts', 'value' => $num($quizzes['attempts'] ?? 0), 'hint' => $num($quizzes['passed'] ?? 0) . ' passed · ' . $num($quizzes['failed'] ?? 0) . ' failed', 'delta' => $quizzes['average'] === null ? 'No scores yet' : 'Avg ' . number_format((float) $quizzes['average'], 1) . '%', 'series' => 'quizzes', 'color' => '#c084fc', 'icon' => '◎'],
            ['label' => 'Assignments to review', 'value' => $num($assignments['pendingReview'] ?? 0), 'hint' => $num($assignments['submitted'] ?? 0) . ' submitted · ' . $num($assignments['notSubmitted'] ?? 0) . ' pending', 'delta' => $num($assignments['late'] ?? 0) . ' late submissions', 'series' => 'assignments', 'color' => '#22d3ee', 'icon' => '✎'],
        ];
        ?>
        <?php
        // Multi-line trend: one real per-day activity series per headline metric, on a shared scale.
        $trend = (array) ($admin['snapshotTrend'] ?? []);
        $trendDates = array_values((array) ($trend['dates'] ?? []));
        $trendCount = count($trendDates);
        $trendStep = ($trendCount - 1) > 0 ? 90 / ($trendCount - 1) : 0;
        $trendX = static fn(int $index): float => $trendCount > 1 ? 7 + ($index * $trendStep) : 52;
        $trendSeries = [];
        $trendPeak = 0.0;
        foreach ($kpi as $item) {
            $vals = array_map(static fn($v): float => (float) $v, array_values((array) ($trend[$item['series']] ?? [])));
            if ($vals !== []) $trendPeak = max($trendPeak, max($vals));
            $trendSeries[] = ['color' => $item['color'], 'label' => $item['label'], 'values' => $vals];
        }
        $trendMax = $trendPeak > 0 ? $trendPeak : 1.0;
        $trendGrid = 4;
        $trendLabelEvery = max(1, (int) ceil($trendCount / 6));
        $trendHasData = $trendPeak > 0;
        $trendTodayX = $trendCount > 0 ? round($trendX($trendCount - 1), 2) : 52;
        $trendSpan = max(1, $trendCount);
        ?>
        <section class="dashboard-summary-card admin-dashboard-snapshot" aria-label="Key statistics">
        <div class="dashboard-summary-heading">
            <div><p class="eyebrow">ACADEMY AT A GLANCE</p><h2>Performance snapshot</h2></div>
            <span class="snapshot-range">Daily activity · last <?= $trendSpan ?> days</span>
        </div>
        <div class="snapshot-split">
            <div class="snapshot-chart">
                <?php if (!$trendHasData): ?>
                    <div class="dashboard-empty"><span class="dashboard-empty-icon">📊</span>
                        <div><h3>No activity in this window</h3><p>Trend lines fill in as students register, attend, pay and submit over the last <?= $trendSpan ?> days.</p></div>
                    </div>
                <?php else: ?>
                    <div class="snapshot-plot">
                        <svg class="snapshot-svg" viewBox="0 0 100 74" preserveAspectRatio="none" role="img" aria-label="Daily activity trend for each headline metric over the last <?= $trendSpan ?> days">
                            <?php for ($s = 0; $s <= $trendGrid; $s++): $gy = 60 - ($s / $trendGrid) * 50; ?>
                                <line class="sn-grid" x1="7" y1="<?= round($gy, 2) ?>" x2="97" y2="<?= round($gy, 2) ?>" vector-effect="non-scaling-stroke"></line>
                            <?php endfor; ?>
                            <line class="sn-guide" x1="<?= $trendTodayX ?>" y1="10" x2="<?= $trendTodayX ?>" y2="60" vector-effect="non-scaling-stroke"></line>
                            <?php foreach ($trendSeries as $series):
                                $pts = [];
                                foreach ($series['values'] as $i => $v) {
                                    $pts[] = ['x' => round($trendX($i), 2), 'y' => round(60 - ($v / $trendMax) * 50, 2)];
                                }
                                if ($pts === []) continue;
                            ?>
                                <path class="sn-line" d="<?= $escape($smoothLine($pts)) ?>" style="stroke: <?= $escape($series['color']) ?>" vector-effect="non-scaling-stroke"></path>
                            <?php endforeach; ?>
                        </svg>
                        <div class="snapshot-ylabels" aria-hidden="true">
                            <?php for ($s = $trendGrid; $s >= 0; $s--): ?>
                                <span><?= $num(round($trendMax * $s / $trendGrid)) ?></span>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <div class="snapshot-xlabels" aria-hidden="true">
                        <?php foreach ($trendDates as $i => $date):
                            $isHi = $i === $trendCount - 1;
                            // Always mark today; drop a periodic label that would sit right beside it.
                            $show = $isHi || ($i % $trendLabelEvery === 0 && abs(($trendCount - 1) - $i) > 1);
                            if (!$show) continue; ?>
                            <span style="--px: <?= round($trendX($i), 2) ?>%" class="<?= $isHi ? 'is-active' : '' ?>"><?= $escape(date('j M', strtotime($date))) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="snapshot-list" aria-label="Key statistics">
                <?php foreach ($kpi as $item): ?>
                    <article class="snapshot-row" style="--tile-accent: <?= $escape($item['color']) ?>">
                        <span class="snapshot-key"><i class="snapshot-dot" aria-hidden="true"></i><span class="snapshot-label"><?= $escape($item['label']) ?></span></span>
                        <div class="snapshot-figure">
                            <strong class="snapshot-value"><?= $escape($item['value']) ?></strong>
                            <span class="snapshot-hint"><?= $escape($item['hint']) ?></span>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
        </section>

        <div class="overview-layout admin-overview-layout">
        <div class="analytics-grid">
            <section class="dashboard-panel chart-card chart-card-wide">
                <div class="dashboard-panel-heading">
                    <div>
                        <p class="eyebrow">GROWTH</p>
                        <h2>New course enrollments</h2>
                        <small>Last 6 months of access grants</small>
                    </div>
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
                    <?php
                    // Smooth SVG line chart (present per day) with gradient area, gridlines and a today tooltip.
                    $attSeries = array_values($attendanceDays);
                    $attCount = count($attSeries);
                    $attStep = ($attCount - 1) > 0 ? 90 / ($attCount - 1) : 0;
                    $attX = static fn(int $index): float => $attCount > 1 ? 7 + ($index * $attStep) : 52;
                    $attY = static fn(float $value): float => 60 - ($pct($value, $attendanceMax) / 100 * 50);
                    $attPts = [];
                    foreach ($attSeries as $index => $day) {
                        $attPts[] = ['x' => round($attX($index), 2), 'yp' => round($attY((float) $day['present']), 2), 'ya' => round($attY((float) $day['absent']), 2), 'present' => (int) $day['present'], 'absent' => (int) $day['absent'], 'date' => (string) $day['date'], 'label' => date('j M', strtotime((string) $day['date']))];
                    }
                    // The window always ends today, so the newest day gets the highlight band and tooltip.
                    $attHi = $attCount > 0 ? $attCount - 1 : 0;
                    $buildPath = static function (array $pts, string $key): string {
                        $path = '';
                        foreach ($pts as $index => $p) {
                            if ($index === 0) { $path .= 'M' . $p['x'] . ',' . $p[$key]; continue; }
                            $prev = $pts[$index - 1];
                            $cx = ($p['x'] - $prev['x']) * 0.28;
                            $path .= sprintf(' C%.*F,%.*F %.*F,%.*F %.*F,%.*F', 2, $prev['x'] + $cx, 2, $prev[$key], 2, $p['x'] - $cx, 2, $p[$key], 2, $p['x'], 2, $p[$key]);
                        }
                        return $path;
                    };
                    $attPath = $buildPath($attPts, 'yp');
                    $attPathAbsent = $buildPath($attPts, 'ya');
                    $attArea = $attPath . sprintf(' L%.2F,70 L%.2F,70 Z', $attPts[count($attPts) - 1]['x'], $attPts[0]['x']);
                    $attGridSteps = 4;
                    $attLabelEvery = max(1, (int) ceil($attCount / 6));
                    ?>
                    <div class="chart-line" role="img" aria-label="Attendance present trend for the last <?= (int) $range ?> days">
                        <div class="chart-line-plot">
                            <svg class="chart-line-svg" viewBox="0 0 100 74" preserveAspectRatio="none">
                                <defs>
                                    <linearGradient id="attLineFill" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="0%" stop-color="var(--att-blue)" stop-opacity=".22"></stop>
                                        <stop offset="100%" stop-color="var(--att-blue)" stop-opacity="0"></stop>
                                    </linearGradient>
                                </defs>
                                <?php for ($s = 0; $s <= $attGridSteps; $s++):
                                    $gy = 60 - ($s / $attGridSteps) * 50; ?>
                                    <line class="line-grid" x1="7" y1="<?= round($gy, 2) ?>" x2="97" y2="<?= round($gy, 2) ?>" vector-effect="non-scaling-stroke"></line>
                                <?php endfor; ?>
                                <rect class="line-band" x="<?= round($attPts[$attHi]['x'] - $attStep / 2, 2) ?>" y="10" width="<?= round(max(4, $attStep - 1.5), 2) ?>" height="60" rx="1.5"></rect>
                                <path class="line-fill" d="<?= $escape($attArea) ?>"></path>
                                <path class="line-stroke-secondary" d="<?= $escape($attPathAbsent) ?>" vector-effect="non-scaling-stroke"></path>
                                <path class="line-stroke" d="<?= $escape($attPath) ?>" vector-effect="non-scaling-stroke"></path>
                                <line class="line-guide" x1="<?= $attPts[$attHi]['x'] ?>" y1="<?= $attPts[$attHi]['yp'] ?>" x2="<?= $attPts[$attHi]['x'] ?>" y2="60" vector-effect="non-scaling-stroke"></line>
                            </svg>
                            <span class="line-dot" style="--px: <?= round($attPts[$attHi]['x'], 2) ?>%; --py: <?= round($attPts[$attHi]['yp'] / 74 * 100, 2) ?>%" aria-hidden="true"></span>
                            <div class="chart-line-ylabels" aria-hidden="true">
                                <?php for ($s = $attGridSteps; $s >= 0; $s--): ?>
                                    <span><?= $num(round($attendanceMax * $s / $attGridSteps)) ?></span>
                                <?php endfor; ?>
                            </div>
                        </div>
                        <div class="chart-line-labels" aria-hidden="true">
                            <?php foreach ($attPts as $index => $p):
                                $isHi = $index === $attHi;
                                // Always mark today; drop a periodic label that would sit right beside it.
                                $show = $isHi || ($index % $attLabelEvery === 0 && abs($attHi - $index) > 1);
                                if (!$show) continue; ?>
                                <span style="--px: <?= $p['x'] ?>%" title="<?= $escape($p['date']) ?>: <?= $p['present'] ?> present, <?= $p['absent'] ?> absent" class="<?= $isHi ? 'is-active' : '' ?>"><?= $escape($p['label']) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="chart-legend">
                        <span><i class="legend-dot legend-blue"></i>Present <?= $num($attendanceWindow['present'] ?? 0) ?></span>
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
                        ['label' => 'Certificates revoked', 'value' => (int) ($certificateSplit['revoked'] ?? 0), 'total' => max(1, array_sum($certificateSplit)), 'tone' => 'rose'],
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
                </div>
                <div class="meter-list">
                    <?php
                    $certificateMeters = [
                        ['label' => 'Certificates issued', 'value' => (int) ($certificateSplit['issued'] ?? 0), 'tone' => 'violet'],
                        ['label' => 'Requests awaiting review', 'value' => (int) ($certificateSplit['requested'] ?? 0), 'tone' => 'amber'],
                        ['label' => 'Requests rejected', 'value' => (int) ($certificateSplit['rejected'] ?? 0), 'tone' => 'rose'],
                        ['label' => 'Certificates revoked', 'value' => (int) ($certificateSplit['revoked'] ?? 0), 'tone' => 'rose'],
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
            </section>
        </div>
        <?php require BASE_PATH . '/app/Views/components/dashboard-tools.php'; ?>
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
        // Four-way split of the 30-day window for the attendance donut; days without any record
        // are the remainder of the window, which is why "No sign-in" never needs its own column.
        $attendanceWindowDays = max(1, count($attendanceDays));
        $attendanceSplit = [
            'present' => (int) ($attendance['present'] ?? 0),
            'absent' => (int) ($attendance['absent'] ?? 0),
            'pending' => (int) ($attendance['pending'] ?? 0),
            'none' => max(0, $attendanceWindowDays - (int) ($attendance['present'] ?? 0) - (int) ($attendance['absent'] ?? 0) - (int) ($attendance['pending'] ?? 0)),
        ];
        // Today's attendance is a metric tile like the ones below, so the panel shows a value
        // instead of a sentence: the mark time when present, the state otherwise.
        $attendanceTile = [
            'present' => ['tone' => 'emerald', 'icon' => '✓', 'label' => 'Present today', 'value' => $attendanceToday['marked_at'] ? $timeLabel((string) $attendanceToday['marked_at']) : 'Marked', 'hint' => 'Confirmed at sign-in · keep the streak going'],
            'absent' => ['tone' => 'rose', 'icon' => '✕', 'label' => 'Absent today', 'value' => 'Not marked', 'hint' => 'The window closed before you confirmed presence'],
            'pending' => ['tone' => 'amber', 'icon' => '◔', 'label' => 'Waiting to confirm', 'value' => 'Open now', 'hint' => 'Mark present within ' . (int) ($attendance['grace_minutes'] ?? 5) . ' minutes of signing in'],
            'none' => ['tone' => 'slate', 'icon' => '◷', 'label' => 'Attendance', 'value' => 'No record', 'hint' => 'Sign in starts today\'s attendance window'],
        ];
        $todayTile = $attendanceTile[$attendanceStatus] ?? $attendanceTile['none'];
        // Weekly trend: the 30-day window folded into 7-day buckets (oldest first) so the bars
        // stay comparable even when the newest bucket is a partial week.
        $attendanceWeeks = [];
        foreach ($attendanceDays as $index => $day) {
            $bucket = intdiv($index, 7);
            if (!isset($attendanceWeeks[$bucket])) {
                $attendanceWeeks[$bucket] = ['start' => (string) $day['date'], 'present' => 0, 'total' => 0];
            }
            $attendanceWeeks[$bucket]['total']++;
            if ((string) $day['status'] === 'present') $attendanceWeeks[$bucket]['present']++;
        }
        $recentWeeks = array_slice(array_values($attendanceWeeks), -5);
        // Forecast: consecutive present days needed to lift the counted rate to the 90% goal.
        $attendanceTarget = 90.0;
        $attendanceCounted = $attendanceSplit['present'] + $attendanceSplit['absent'];
        $attendanceRate = (float) ($attendance['rate'] ?? 0);
        $forecastNeeded = ($attendanceCounted > 0 && $attendanceRate < $attendanceTarget)
            ? (int) max(1, ceil(($attendanceTarget * $attendanceCounted - 100 * $attendanceSplit['present']) / (100 - $attendanceTarget)))
            : 0;
        if ($attendanceCounted === 0) {
            $forecastMessage = 'Mark present to start tracking your attendance rate.';
        } elseif ($attendanceRate >= $attendanceTarget) {
            $forecastMessage = 'You are past the 90% goal at ' . number_format($attendanceRate, 1) . '% — keep the streak alive.';
        } else {
            $forecastMessage = $forecastNeeded . ' more present ' . ($forecastNeeded === 1 ? 'day' : 'days') . ' in a row lifts you to 90%.';
        }
        // Segment metadata drives the hover tooltip; hex colours are passed through because the
        // conic-gradient itself is built from CSS variables the script cannot resolve.
        $donutSegments = [
            ['label' => 'Present', 'value' => $attendanceSplit['present'], 'color' => '#059669'],
            ['label' => 'Absent', 'value' => $attendanceSplit['absent'], 'color' => '#e11d48'],
            ['label' => 'Waiting', 'value' => $attendanceSplit['pending'], 'color' => '#d97706'],
            ['label' => 'No sign-in', 'value' => $attendanceSplit['none'], 'color' => '#33507a'],
        ];
        $donutTotal = array_sum(array_column($donutSegments, 'value'));
        foreach ($donutSegments as $index => $segment) {
            $donutSegments[$index]['pct'] = $donutTotal > 0 ? (int) round($segment['value'] / $donutTotal * 100) : 0;
        }
        $donutData = json_encode($donutSegments, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
        ?>
        <section class="dashboard-summary-card student-dashboard-hero" aria-label="Attendance and learning summary">
            <div class="dashboard-summary-heading"><div><p class="eyebrow">TODAY · <?= $escape($todayLabel) ?></p><h2>Attendance &amp; learning snapshot</h2></div><span>Personal overview</span></div>
            <div class="attendance-hero<?= empty($attendance['available']) ? ' attendance-hero-single' : '' ?>">
                <div class="attendance-main">
                    <?php if (empty($attendance['available'])): ?>
                        <article class="hero-tile hero-tile-slate">
                            <div class="hero-tile-top"><span class="hero-tile-icon" aria-hidden="true">◷</span><span class="hero-tile-label">Attendance</span></div>
                            <strong class="hero-tile-value">Not enabled</strong>
                            <p class="hero-tile-hint">Attendance tracking is not set up on this server yet. The Academy will import the latest schema and this tile fills in.</p>
                        </article>
                    <?php else: ?>
                        <article class="hero-tile hero-tile-<?= $escape($todayTile['tone']) ?>">
                            <div class="hero-tile-top"><span class="hero-tile-icon" aria-hidden="true"><?= $escape($todayTile['icon']) ?></span><span class="hero-tile-label"><?= $escape($todayTile['label']) ?></span></div>
                            <strong class="hero-tile-value"><?= $escape($todayTile['value']) ?></strong>
                            <?php if ($attendanceStatus === 'pending'): ?>
                                <form method="post" action="<?= $escape($baseUrl) ?>/student/attendance/mark" class="attendance-form" data-attendance-countdown="<?= (int) $secondsLeft ?>">
                                    <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
                                    <button class="button attendance-mark-button" type="submit">Mark me present</button>
                                    <span class="attendance-timer" aria-live="polite"></span>
                                </form>
                            <?php else: ?>
                                <p class="hero-tile-hint"><?= $escape($todayTile['hint']) ?></p>
                            <?php endif; ?>
                        </article>
                        <article class="hero-tile hero-tile-day-streak">
                            <div class="hero-tile-top"><span class="hero-tile-icon" aria-hidden="true">🔥</span><span class="hero-tile-label">Day streak</span></div>
                            <strong class="hero-tile-value"><?= $num($attendance['streak'] ?? 0) ?></strong>
                            <p class="hero-tile-hint">Consecutive present days · <?= number_format((float) ($attendance['rate'] ?? 0), 1) ?>% overall rate</p>
                        </article>
                    <?php endif; ?>
                </div>
                <?php if (!empty($attendance['available'])): ?>
                    <div class="attendance-chart">
                        <div class="donut-wrap" data-donut-tooltip>
                            <div class="donut" data-donut-segments='<?= $escape($donutData) ?>' style="--donut: <?= $escape($donut([
                                    ['value' => $attendanceSplit['present'], 'color' => 'var(--att-emerald)'],
                                    ['value' => $attendanceSplit['absent'], 'color' => 'var(--att-rose)'],
                                    ['value' => $attendanceSplit['pending'], 'color' => 'var(--att-amber)'],
                                    ['value' => $attendanceSplit['none'], 'color' => '#33507a'],
                                ])) ?>") role="img" aria-label="Attendance mix over the last 30 days: <?= $attendanceSplit['present'] ?> present, <?= $attendanceSplit['absent'] ?> absent, <?= $attendanceSplit['pending'] ?> waiting, <?= $attendanceSplit['none'] ?> without sign-in">
                                <span class="donut-center"><strong><?= number_format((float) ($attendance['rate'] ?? 0), 1) ?>%</strong><small>Attendance rate</small></span>
                            </div>
                            <ul class="donut-legend">
                                <li><i class="legend-dot legend-emerald"></i><span>Present</span><strong><?= $num($attendanceSplit['present']) ?></strong></li>
                                <li><i class="legend-dot legend-rose"></i><span>Absent</span><strong><?= $num($attendanceSplit['absent']) ?></strong></li>
                                <li><i class="legend-dot legend-amber"></i><span>Waiting</span><strong><?= $num($attendanceSplit['pending']) ?></strong></li>
                                <li><i class="legend-dot legend-white"></i><span>No sign-in</span><strong><?= $num($attendanceSplit['none']) ?></strong></li>
                            </ul>
                            <div class="donut-tooltip" role="status" aria-hidden="true"></div>
                        </div>
                        <p class="chart-footnote">Hover a slice for details · last <?= $num($attendanceWindowDays) ?> days</p>
                    </div>
                <?php endif; ?>
            </div>
            <?php if (!empty($attendance['available'])): ?>
                <div class="attendance-insights">
                    <section class="insight-card insight-week" aria-label="Weekly attendance trend">
                        <div class="insight-head"><p class="eyebrow">WEEKLY TREND</p><h3>Present days per week</h3></div>
                        <?php if ($recentWeeks === []): ?>
                            <p class="insight-empty">No attendance recorded yet — your weekly bars fill in as you sign in.</p>
                        <?php else: ?>
                            <div class="week-bars" role="img" aria-label="Present days per week over the last 30 days">
                                <?php foreach ($recentWeeks as $week):
                                    $height = $pct((float) $week['present'], 7);
                                    $weekLabel = date('j M', strtotime((string) $week['start'])); ?>
                                    <div class="week-bar-col" title="Week of <?= $escape($weekLabel) ?>: <?= (int) $week['present'] ?> of <?= (int) $week['total'] ?> days present">
                                        <span class="week-bar-value"><?= $num($week['present']) ?></span>
                                        <span class="week-bar" style="--week-height: <?= max(5, $height) ?>%"></span>
                                        <span class="week-bar-label"><?= $escape($weekLabel) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                    <section class="insight-card insight-forecast" aria-label="Attendance forecast">
                        <div class="insight-head"><p class="eyebrow">FORECAST</p><h3>Path to 90%</h3></div>
                        <p class="forecast-lead"><?= $escape($forecastMessage) ?></p>
                        <div class="forecast-meter" role="progressbar" aria-label="Attendance rate towards the 90% goal" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) round($attendanceRate) ?>">
                            <span class="forecast-fill" style="--forecast-width: <?= min(100, $attendanceRate > 0 ? $attendanceRate / $attendanceTarget * 100 : 0) ?>%"></span>
                        </div>
                        <div class="forecast-scale"><span>Now <?= number_format($attendanceRate, 1) ?>%</span><span>Goal 90%</span></div>
                    </section>
                </div>
            <?php endif; ?>
            <div class="kpi-grid kpi-grid-student" aria-label="Your summary">
                <article class="kpi-card kpi-blue"><div class="kpi-top"><span class="kpi-icon" aria-hidden="true">📚</span><span class="kpi-label">My courses</span></div><strong class="kpi-value"><?= $num($student['activeCourseCount'] ?? 0) ?></strong><p class="kpi-hint">Active access grants</p></article>
                <article class="kpi-card kpi-emerald"><div class="kpi-top"><span class="kpi-icon" aria-hidden="true">✓</span><span class="kpi-label">Lessons completed</span></div><strong class="kpi-value"><?= $num($student['completedLessonCount'] ?? 0) ?></strong><p class="kpi-hint">Across your courses</p></article>
                <article class="kpi-card kpi-violet"><div class="kpi-top"><span class="kpi-icon" aria-hidden="true">◎</span><span class="kpi-label">Quiz attempts</span></div><strong class="kpi-value"><?= $num($student['quizAttempts'] ?? 0) ?></strong><p class="kpi-hint">Saved on your account</p></article>
                <article class="kpi-card kpi-indigo"><div class="kpi-top"><span class="kpi-icon" aria-hidden="true">✎</span><span class="kpi-label">Assignments</span></div><strong class="kpi-value"><?= $num($student['assignmentSubmissions'] ?? 0) ?></strong><p class="kpi-hint">Submissions recorded</p></article>
                <article class="kpi-card kpi-amber"><div class="kpi-top"><span class="kpi-icon" aria-hidden="true">₨</span><span class="kpi-label">Pending payments</span></div><strong class="kpi-value"><?= $num($student['pendingPaymentCount'] ?? 0) ?></strong><p class="kpi-hint">Awaiting verification</p></article>
                <article class="kpi-card kpi-rose"><div class="kpi-top"><span class="kpi-icon" aria-hidden="true">🔔</span><span class="kpi-label">Unread notices</span></div><strong class="kpi-value"><?= $num($student['unreadNotificationCount'] ?? 0) ?></strong><p class="kpi-hint">From the Academy</p></article>
            </div>
        </section>

        <div class="overview-layout student-overview-layout">
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
        <?php require BASE_PATH . '/app/Views/components/dashboard-tools.php'; ?>
        </div>
    <?php endif; ?>

    <?php /* No walkthrough here on purpose: the dashboard summarises the other pages, and each of
               those already closes with its own guide. */ ?>

</section>
