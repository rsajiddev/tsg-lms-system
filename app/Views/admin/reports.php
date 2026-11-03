<?php
use App\Core\Security;
$escape = [Security::class, 'escape'];
$reports = is_array($reports ?? null) ? $reports : [];
$period = $period ?? ['key'=>'monthly'];
$baseUrl = $baseUrl ?? '';
$labels = ['students'=>'Students & regularity','attendance'=>'Attendance','quizzes'=>'Quiz performance','assignments'=>'Assignments','payments'=>'Payments'];
// Real figures for the overview card: headline metric + record count per desk.
$counts = [];
foreach ($labels as $type => $label) $counts[$type] = count($reports[$type]['rows'] ?? []);
$maxCount = $counts ? max(1, max($counts)) : 1;
$headline = [];
foreach ($labels as $type => $label) {
    $metric = $reports[$type]['metrics'][0] ?? null;
    $headline[$type] = ['label' => $metric['label'] ?? $label, 'value' => $metric['value'] ?? '0'];
}
$rangeLabel = $reports['students']['period_range'] ?? '';
?>
<section class="dashboard-page admin-reports-page" data-reveal>
  <header class="dashboard-heading admin-report-heading">
    <div><p class="eyebrow">ACADEMY INSIGHTS</p><h1>Reports</h1><p>Review student activity and export branded PDF reports for the selected period.</p></div>
    <form class="admin-report-filter" method="get" action="<?= $escape($baseUrl) ?>/admin/reports" aria-label="Report period">
      <label for="admin-report-period">Period</label>
      <select class="admin-report-period-select" id="admin-report-period" name="period" data-auto-submit>
        <option value="weekly" <?= $period['key']==='weekly'?'selected':'' ?>>Weekly</option>
        <option value="monthly" <?= $period['key']==='monthly'?'selected':'' ?>>Monthly</option>
      </select>
    </form>
  </header>
  <section class="dashboard-summary-card admin-report-summary">
    <div class="dashboard-summary-heading">
      <div><p class="eyebrow">ACADEMY INSIGHTS</p><h2>Reports overview</h2></div>
      <span><?= $escape($period['label']) ?><?= $rangeLabel !== '' ? ' · '.$escape($rangeLabel) : '' ?></span>
    </div>
    <div class="admin-report-summary-body">
      <div class="admin-report-summary-tiles">
        <?php foreach ($headline as $type => $h): ?>
          <div class="admin-report-summary-tile"><span><?= $escape($h['label']) ?></span><strong><?= $escape($h['value']) ?></strong></div>
        <?php endforeach; ?>
      </div>
      <div class="admin-report-summary-chart">
        <p class="admin-report-summary-chart-title">Records captured per report</p>
        <?php foreach ($counts as $type => $count): $pct = $maxCount > 0 ? (int) round($count / $maxCount * 100) : 0; ?>
          <div class="admin-report-bar">
            <span class="admin-report-bar-label"><?= $escape($labels[$type]) ?></span>
            <span class="admin-report-bar-track"><span class="admin-report-bar-fill" style="width:<?= $pct ?>%"></span></span>
            <span class="admin-report-bar-value"><?= (int)$count ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <div class="admin-report-grid">
    <?php foreach ($labels as $type => $label): $report = $reports[$type] ?? []; ?>
      <article class="admin-card admin-report-card">
        <header><div><p class="eyebrow"><?= $escape($period['label']) ?> REPORT</p><h2><?= $escape($label) ?></h2><p><?= $escape($report['period_range'] ?? '') ?></p></div><a class="button admin-report-download" href="<?= $escape($baseUrl) ?>/admin/reports/download?type=<?= $escape($type) ?>&amp;period=<?= $escape($period['key']) ?>" download>Download PDF</a></header>
        <div class="admin-report-metrics"><?php foreach (($report['metrics'] ?? []) as $metric): ?><div><span><?= $escape($metric['label']) ?></span><strong><?= $escape($metric['value']) ?></strong></div><?php endforeach; ?></div>
        <?php if (($report['rows'] ?? []) === []): ?><p class="admin-report-empty">No <?= $escape(strtolower($label)) ?> records for this period yet.</p>
        <?php else: ?><div class="admin-report-preview" aria-label="Recent <?= $escape(strtolower($label)) ?> records">
          <?php foreach (array_slice($report['rows'], 0, 6) as $row): ?><div class="admin-report-row"><?php foreach (($report['columns'] ?? []) as $index => $column): ?><div><span><?= $escape($column) ?></span><strong><?= $escape($row[$index] ?? '—') ?></strong></div><?php endforeach; ?></div><?php endforeach; ?>
          <?php if (count($report['rows']) > 6): ?><p class="admin-report-more">Showing 6 recent entries. Download the PDF to review up to 60 records.</p><?php endif; ?>
        </div><?php endif; ?>
      </article>
    <?php endforeach; ?>
    <article class="admin-card admin-report-card admin-report-all-card">
      <header><div><p class="eyebrow">FULL PERIOD PACK</p><h2>All reports</h2><p>Students · Attendance · Quizzes · Assignments</p></div><a class="button admin-report-download" href="<?= $escape($baseUrl) ?>/admin/reports/download?type=all&amp;period=<?= $escape($period['key']) ?>" download>Download PDF</a></header>
      <p class="admin-report-all-note">One branded PDF that summarises every desk for the <?= $escape(strtolower($period['label'])) ?> period — key totals and record counts in a single export.</p>
      <div class="admin-report-metrics">
        <?php foreach (['students','attendance','quizzes','assignments'] as $type): $metric = $reports[$type]['metrics'][0] ?? null; ?>
          <div><span><?= $escape($metric['label'] ?? $labels[$type]) ?></span><strong><?= $escape($metric['value'] ?? '0') ?></strong></div>
        <?php endforeach; ?>
      </div>
    </article>
  </div>
  <?php
  $usageEyebrow = 'Reporting desk'; $usageTitle = 'How to use reports';
  $usageLead = 'Use a weekly or monthly view to review saved records, then export the selected report as a PDF.';
  $usageBullets = ['Choose the period. Weekly reports run from Monday through Sunday; monthly reports cover the current calendar month.','Review each desk. Student regularity, attendance, quiz attempts, assignment submissions, and approved payments come from their saved records.','Export a branded document. Each download includes the TechSG Studio Academy header, reporting period, generation time, key totals, and record rows as a PDF.'];
  require BASE_PATH . '/app/Views/components/usage-guide.php';
  ?>
</section>
