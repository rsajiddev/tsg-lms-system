<?php
use App\Core\Security;
$escape = [Security::class, 'escape'];
$reports = is_array($reports ?? null) ? $reports : [];
$period = $period ?? ['key'=>'monthly'];
$baseUrl = $baseUrl ?? '';
$labels = ['students'=>'Students & regularity','attendance'=>'Attendance','quizzes'=>'Quiz performance','assignments'=>'Assignments','payments'=>'Payments'];
?>
<section class="dashboard-page admin-reports-page" data-reveal>
  <header class="dashboard-heading admin-report-heading">
    <div><p class="eyebrow">ACADEMY INSIGHTS</p><h1>Reports</h1><p>Review student activity and export branded JPG reports for the selected period.</p></div>
    <form class="admin-report-filter" method="get" action="<?= $escape($baseUrl) ?>/admin/reports" aria-label="Report period">
      <label for="admin-report-period">Period</label>
      <select class="admin-report-period-select" id="admin-report-period" name="period" data-auto-submit>
        <option value="weekly" <?= $period['key']==='weekly'?'selected':'' ?>>Weekly</option>
        <option value="monthly" <?= $period['key']==='monthly'?'selected':'' ?>>Monthly</option>
      </select>
    </form>
  </header>
  <article class="admin-card admin-report-all-card">
    <div class="admin-report-all-copy">
      <p class="eyebrow">FULL PERIOD PACK</p>
      <h2>All reports in one download</h2>
      <p>A single summarised JPG covering students, attendance, quizzes and assignments for the selected period. Payment details are intentionally excluded.</p>
    </div>
    <a class="button admin-report-all-download" href="<?= $escape($baseUrl) ?>/admin/reports/download?type=all&amp;period=<?= $escape($period['key']) ?>" download>Download All Reports</a>
  </article>
  <div class="admin-report-grid">
    <?php foreach ($labels as $type => $label): $report = $reports[$type] ?? []; ?>
      <article class="admin-card admin-report-card">
        <header><div><p class="eyebrow"><?= $escape($period['label']) ?> REPORT</p><h2><?= $escape($label) ?></h2><p><?= $escape($report['period_range'] ?? '') ?></p></div><a class="button admin-report-download" href="<?= $escape($baseUrl) ?>/admin/reports/download?type=<?= $escape($type) ?>&amp;period=<?= $escape($period['key']) ?>" download>Download JPG</a></header>
        <div class="admin-report-metrics"><?php foreach (($report['metrics'] ?? []) as $metric): ?><div><span><?= $escape($metric['label']) ?></span><strong><?= $escape($metric['value']) ?></strong></div><?php endforeach; ?></div>
        <?php if (($report['rows'] ?? []) === []): ?><p class="admin-report-empty">No <?= $escape(strtolower($label)) ?> records for this period yet.</p>
        <?php else: ?><div class="admin-report-preview" aria-label="Recent <?= $escape(strtolower($label)) ?> records">
          <?php foreach (array_slice($report['rows'], 0, 6) as $row): ?><div class="admin-report-row"><?php foreach (($report['columns'] ?? []) as $index => $column): ?><div><span><?= $escape($column) ?></span><strong><?= $escape($row[$index] ?? '—') ?></strong></div><?php endforeach; ?></div><?php endforeach; ?>
          <?php if (count($report['rows']) > 6): ?><p class="admin-report-more">Showing 6 recent entries. Download the JPG to review up to 60 records.</p><?php endif; ?>
        </div><?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
  <?php
  $usageEyebrow = 'Reporting desk'; $usageTitle = 'How to use reports';
  $usageLead = 'Use a weekly or monthly view to review saved records, then export the selected report as a JPG.';
  $usageBullets = ['Choose the period. Weekly reports run from Monday through Sunday; monthly reports cover the current calendar month.','Review each desk. Student regularity, attendance, quiz attempts, assignment submissions, and approved payments come from their saved records.','Export a branded image. Each download includes the TechSG Studio Academy header, reporting period, generation time, key totals, and record rows.'];
  require BASE_PATH . '/app/Views/components/usage-guide.php';
  ?>
</section>
