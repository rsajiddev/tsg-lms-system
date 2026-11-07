<?php

use App\Core\Security;

$escape = [Security::class, 'escape'];
$paymentValues = is_array($paymentValues ?? null) ? $paymentValues : [];
$paymentAccounts = is_array($paymentAccounts ?? null) ? $paymentAccounts : [];
if ($paymentAccounts === []) $paymentAccounts[] = array_fill_keys(['account_title','bank_name','account_number','iban','wallet_name','wallet_number','instructions'], '');
$revenueMonthOptions = is_array($revenueMonthOptions ?? null) ? $revenueMonthOptions : [];
$currentRevenueMonth = gmdate('Y-m');
$payments = is_array($payments ?? null) ? $payments : [];
$localTimezone = new DateTimeZone((string) (require BASE_PATH . '/config/app.php')['timezone']);
$paymentSubmittedAt = static function (mixed $value) use ($localTimezone): string {
    $utc = new DateTimeZone('UTC');
    return (new DateTimeImmutable((string) $value, $utc))
        ->setTimezone($localTimezone)
        ->format('M j, Y g:i A T');
};
$revenue = is_array($revenue ?? null) ? $revenue : [];
$revenueTotals = is_array($revenueTotals ?? null) ? $revenueTotals : [];
/* The developer split shown on the card matches the same configured percentage the reports use. */
$developerShareLabel = rtrim(rtrim(number_format((float) ($developerSharePercent ?? 15), 2, '.', ''), '0'), '.');
if ($developerShareLabel === '') $developerShareLabel = '0';
$flash = $message ?? null;
if (is_string($flash) && $flash !== '') $flash = ['type' => 'success', 'text' => $flash];
if (!is_array($flash)) $flash = null;

/* Aggregate the per-currency revenue rows into readable totals for the KPI cards. */
$sumBy = static function (array $rows, string $key): array {
    $totals = [];
    foreach ($rows as $row) {
        $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
        if ($currency === '') $currency = '—';
        $totals[$currency] = ($totals[$currency] ?? 0.0) + (float) ($row[$key] ?? 0);
    }
    return $totals;
};
$lifetimeTotals = $sumBy($revenue, 'lifetime_revenue');
$monthlyTotals = $sumBy($revenue, 'monthly_revenue');
$developerTotals = $sumBy($revenue, 'lifetime_developer_share');
$developerMonthly = $sumBy($revenue, 'developer_share');
/* Render one line per currency so multi-currency totals never get merged or misread. */
$metricLines = static function (array $totals) use ($escape): string {
    if ($totals === []) return '<span class="revenue-kpi-line"><b>—</b></span>';
    $html = '';
    foreach ($totals as $currency => $value) {
        $html .= '<span class="revenue-kpi-line"><i>' . $escape((string) $currency) . '</i><b>' . number_format($value, 2) . '</b></span>';
    }
    return $html;
};
$inlineTotals = static function (array $totals): string {
    $parts = [];
    foreach ($totals as $currency => $value) $parts[] = $currency . ' ' . number_format($value, 2);
    return $parts === [] ? 'No approved revenue yet' : implode('  ·  ', $parts);
};
?>
<section class="dashboard-page admin-revenue-page" data-reveal>
  <header class="dashboard-heading revenue-page-header">
    <div>
      <button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
      <p class="eyebrow">ADMIN WORKSPACE</p>
      <h1>Revenue and payment</h1>
      <p>Track approved course revenue, manage payment details, review student submissions, and download monthly reports.</p>
      <aside class="revenue-reporting-tip" aria-label="Reporting tip">
        <span class="revenue-reporting-tip-label">REPORTING TIP</span>
        <p>Choose a month for its individual report, or download the six-month report for a complete month-by-month view. Revenue is shown separately for each currency.</p>
      </aside>
    </div>
    <aside class="admin-card revenue-actions-card" aria-labelledby="revenue-actions-title">
      <div class="revenue-actions-heading">
        <p class="eyebrow">REVENUE ACTIONS</p>
        <h2 id="revenue-actions-title">Reports &amp; notifications</h2>
      </div>
      <form class="revenue-month-export" method="get" action="<?= $escape($baseUrl) ?>/admin/payments/revenue-receipt">
        <label for="revenue-report-month">Choose a month</label>
        <select class="admin-input" id="revenue-report-month" name="period">
          <?php foreach ($revenueMonthOptions as $option): ?>
            <option value="<?= $escape($option['value'] ?? '') ?>" <?= ($option['value'] ?? '') === $currentRevenueMonth ? 'selected' : '' ?>><?= $escape($option['label'] ?? '') ?></option>
          <?php endforeach; ?>
        </select>
        <button class="button button-secondary revenue-action-button" type="submit">Download monthly PDF</button>
      </form>
      <a class="button revenue-six-month-download revenue-action-button" href="<?= $escape($baseUrl) ?>/admin/payments/revenue-receipt?period=six-months">Download last 6 months</a>
      <form class="revenue-notify-form" method="post" action="<?= $escape($baseUrl) ?>/admin/payments/revenue-notify">
        <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
        <button class="button revenue-action-button" type="submit"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h16v12H4z"/><path d="m4 7 8 6 8-6"/></svg>Notify developer</button>
      </form>
    </aside>
  </header>

  <?php /* The payment controller tags each confirmation, so the overlay can pick its colour. */ ?>
  <?php \App\Core\View::toast(($flash['type'] ?? '') === 'error' ? 'error' : 'success', $flash['text'] ?? null); ?>

  <section class="revenue-summary-shell" aria-label="Revenue summary"><div class="revenue-kpi-grid">
    <article class="revenue-kpi revenue-kpi--hero">
      <div class="revenue-kpi-top"><span class="revenue-kpi-label">Total revenue</span><span class="revenue-kpi-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M16 12h.01M3 9h16"/></svg></span></div>
      <div class="revenue-kpi-value"><?= $metricLines($lifetimeTotals) ?></div>
      <p class="revenue-kpi-foot"><span class="revenue-kpi-foot-label">All-time approved</span><span>This month · <?= $escape($inlineTotals($monthlyTotals)) ?></span></p>
    </article>

    <article class="revenue-kpi revenue-kpi--developer">
      <div class="revenue-kpi-top"><span class="revenue-kpi-label">Developer share · <?= $escape($developerShareLabel) ?>%</span><span class="revenue-kpi-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17 9 11l4 4 8-8"/><path d="M15 7h6v6"/></svg></span></div>
      <div class="revenue-kpi-value"><?= $metricLines($developerTotals) ?></div>
      <p class="revenue-kpi-foot"><span class="revenue-kpi-foot-label"><?= $escape($developerShareLabel) ?>% of all-time revenue</span><span>This month · <?= $escape($inlineTotals($developerMonthly)) ?></span></p>
    </article>

    <article class="revenue-kpi revenue-kpi--payments">
      <div class="revenue-kpi-top"><span class="revenue-kpi-label">Approved payments</span><span class="revenue-kpi-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="m9 9 2 2 4-4"/></svg></span></div>
      <div class="revenue-kpi-value"><span class="revenue-kpi-line"><b><?= number_format((int) ($revenueTotals['payment_count'] ?? 0)) ?></b></span></div>
      <p class="revenue-kpi-foot"><span class="revenue-kpi-foot-label">Verified transactions</span><span>Recorded across all courses</span></p>
    </article>

    <article class="revenue-kpi revenue-kpi--students">
      <div class="revenue-kpi-top"><span class="revenue-kpi-label">Enrolled students</span><span class="revenue-kpi-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/></svg></span></div>
      <div class="revenue-kpi-value"><span class="revenue-kpi-line"><b><?= number_format((int) ($revenueTotals['student_count'] ?? 0)) ?></b></span></div>
      <p class="revenue-kpi-foot"><span class="revenue-kpi-foot-label">Unique students</span><span>With an approved payment</span></p>
    </article>
  </div></section>

  <section class="admin-card approved-payment-history" aria-labelledby="approved-history-title">
    <div class="admin-card-header"><div class="admin-card-titles"><h2 id="approved-history-title">Student payment history</h2><p>Approved transactions with the student, course, amount and payment date.</p></div><span class="revenue-period-chip"><?= number_format(count($approvedPayments ?? [])) ?> recent</span></div>
    <?php if (empty($approvedPayments)): ?><div class="revenue-empty-state"><strong>No approved payments yet</strong><span>Verified student transactions will appear here.</span></div><?php else: ?>
      <div class="dashboard-table-wrap"><table class="dashboard-table approved-payments-table"><thead><tr><th>Student</th><th>Course</th><th>Amount paid</th><th>Transaction reference</th><th>Paid on</th></tr></thead><tbody>
      <?php foreach ($approvedPayments as $approved): ?><tr><td data-label="Student"><strong><?= $escape($approved['student_name']) ?></strong><small><?= $escape($approved['student_email']) ?></small></td><td data-label="Course"><?= $escape($approved['course_title']) ?></td><td data-label="Amount paid"><strong><?= $escape($approved['currency']) ?> <?= number_format((float)$approved['amount'],2) ?></strong></td><td data-label="Transaction reference"><small><?= $escape($approved['transaction_reference'] ?: '—') ?></small></td><td data-label="Paid on"><time datetime="<?= $escape($approved['paid_at']) ?>"><?= $escape(date('M j, Y · g:i A',strtotime((string)$approved['paid_at']))) ?></time></td></tr><?php endforeach ?>
      </tbody></table></div>
    <?php endif ?>
  </section>

  <section class="admin-card payment-settings-card">
    <div class="admin-card-header"><div class="admin-card-title-group"><div class="admin-card-icon violet" aria-hidden="true">₨</div><div class="admin-card-titles"><h2>Payment account details</h2><p>Manage the bank and wallet information students see when submitting a payment.</p></div></div></div>
    <form method="post" action="<?= $escape($baseUrl) ?>/admin/payments/settings" class="admin-form payment-accounts-form" data-payment-accounts-form>
      <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
      <div class="payment-account-editor-list" data-payment-account-list>
        <?php foreach ($paymentAccounts as $index => $account): ?>
          <fieldset class="payment-account-editor"><legend>Payment account <?= (int)$index + 1 ?></legend>
            <button class="button button-secondary payment-account-remove" type="button" data-remove-payment-account aria-label="Remove payment account <?= (int)$index + 1 ?>">Remove</button>
            <div class="admin-form-row"><div class="admin-field"><label class="admin-label">Account title</label><input class="admin-input" name="accounts[<?= (int)$index ?>][account_title]" maxlength="150" value="<?= $escape($account['account_title'] ?? '') ?>" placeholder="Account holder name"></div><div class="admin-field"><label class="admin-label">Bank name</label><input class="admin-input" name="accounts[<?= (int)$index ?>][bank_name]" maxlength="100" value="<?= $escape($account['bank_name'] ?? '') ?>" placeholder="Bank or provider"></div></div>
            <div class="admin-form-row"><div class="admin-field"><label class="admin-label">Account number</label><input class="admin-input" name="accounts[<?= (int)$index ?>][account_number]" maxlength="80" value="<?= $escape($account['account_number'] ?? '') ?>" autocomplete="off"></div><div class="admin-field"><label class="admin-label">IBAN</label><input class="admin-input" name="accounts[<?= (int)$index ?>][iban]" maxlength="80" value="<?= $escape($account['iban'] ?? '') ?>" autocomplete="off"></div></div>
            <div class="admin-form-row"><div class="admin-field"><label class="admin-label">Mobile wallet name</label><input class="admin-input" name="accounts[<?= (int)$index ?>][wallet_name]" maxlength="100" value="<?= $escape($account['wallet_name'] ?? '') ?>" placeholder="Optional"></div><div class="admin-field"><label class="admin-label">Mobile wallet number</label><input class="admin-input" name="accounts[<?= (int)$index ?>][wallet_number]" maxlength="80" value="<?= $escape($account['wallet_number'] ?? '') ?>" placeholder="Optional"></div></div>
            <div class="admin-field"><label class="admin-label">Student payment instructions</label><textarea class="admin-textarea" name="accounts[<?= (int)$index ?>][instructions]" rows="2" maxlength="255" placeholder="Include your transaction reference when submitting payment proof."><?= $escape($account['instructions'] ?? '') ?></textarea></div>
          </fieldset>
        <?php endforeach; ?>
      </div>
      <template data-payment-account-template><fieldset class="payment-account-editor"><legend>Payment account</legend><button class="button button-secondary payment-account-remove" type="button" data-remove-payment-account>Remove</button><div class="admin-form-row"><div class="admin-field"><label class="admin-label">Account title</label><input class="admin-input" data-field="account_title" maxlength="150" placeholder="Account holder name"></div><div class="admin-field"><label class="admin-label">Bank name</label><input class="admin-input" data-field="bank_name" maxlength="100" placeholder="Bank or provider"></div></div><div class="admin-form-row"><div class="admin-field"><label class="admin-label">Account number</label><input class="admin-input" data-field="account_number" maxlength="80" autocomplete="off"></div><div class="admin-field"><label class="admin-label">IBAN</label><input class="admin-input" data-field="iban" maxlength="80" autocomplete="off"></div></div><div class="admin-form-row"><div class="admin-field"><label class="admin-label">Mobile wallet name</label><input class="admin-input" data-field="wallet_name" maxlength="100" placeholder="Optional"></div><div class="admin-field"><label class="admin-label">Mobile wallet number</label><input class="admin-input" data-field="wallet_number" maxlength="80" placeholder="Optional"></div></div><div class="admin-field"><label class="admin-label">Student payment instructions</label><textarea class="admin-textarea" data-field="instructions" rows="2" maxlength="255"></textarea></div></fieldset></template>
      <div class="payment-account-editor-actions"><button class="button button-secondary" type="button" data-add-payment-account>+ Add another account</button><span class="admin-hint">Up to 12 accounts. Active accounts appear on the student payment page.</span></div>
      <div class="form-actions"><button class="button" type="submit">Save payment accounts</button></div>
    </form>
  </section>

  <section class="admin-card payment-review-section" aria-labelledby="payment-review-title">
    <div class="admin-card-header"><div class="admin-card-title-group"><div class="admin-card-icon amber" aria-hidden="true">✓</div><div class="admin-card-titles"><h2 id="payment-review-title">Payments awaiting review</h2><p>Check each student’s transaction and proof before granting course access.</p></div></div><span class="revenue-period-chip"><?= number_format(count($payments)) ?> pending</span></div>
    <?php if ($payments === []): ?>
      <div class="revenue-empty-state"><strong>No payments awaiting review</strong><span>New student submissions will appear here.</span></div>
    <?php else: ?>
      <div class="payment-review-list">
        <?php foreach ($payments as $payment): ?>
          <article class="dashboard-panel payment-review-card">
            <div class="dashboard-panel-heading"><div><p class="eyebrow">PAYMENT #<?= (int) ($payment['id'] ?? 0) ?> · <?= $escape($paymentSubmittedAt($payment['created_at'] ?? 'now')) ?></p><h3><?= $escape($payment['course_title'] ?? 'Course') ?></h3></div><span class="dashboard-status status-pending">Pending review</span></div>
            <div class="payment-review-meta"><div><small>Student</small><strong><?= $escape($payment['student_name'] ?? '') ?></strong><a href="mailto:<?= $escape($payment['student_email'] ?? '') ?>"><?= $escape($payment['student_email'] ?? '') ?></a></div><div><small>Submitted amount</small><strong><?= $escape($payment['currency'] ?? '') ?> <?= number_format((float) ($payment['amount'] ?? 0), 2) ?></strong></div><div><small>Transaction reference</small><strong><?= $escape($payment['transaction_reference'] ?? '') ?></strong></div></div>
            <form method="post" action="<?= $escape($baseUrl) ?>/admin/payments/decision" class="payment-decision-form" data-submit-progress-form data-progress-approval-only>
              <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
              <input type="hidden" name="payment_id" value="<?= (int) ($payment['id'] ?? 0) ?>">
              <div class="payment-review-note">
                <label class="admin-label" for="payment-review-note-<?= (int) ($payment['id'] ?? 0) ?>">Review note <span>(required to reject)</span></label>
                <textarea class="admin-textarea" id="payment-review-note-<?= (int) ($payment['id'] ?? 0) ?>" name="review_note" rows="2" maxlength="1000" placeholder="Explain why this payment cannot be approved."></textarea>
              </div>
              <div class="dashboard-actions">
                <?php if (!empty($payment['payment_proof_path'])): ?>
                  <a class="button button-secondary payment-receipt-link" href="<?= $escape($baseUrl) ?>/payments/proof?payment_id=<?= (int) $payment['id'] ?>" download>Download Submitted Proof</a>
                <?php endif; ?>
                <button class="button payment-approve-button" type="submit" name="decision" value="approved">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>
                  Verify &amp; Grant Access
                </button>
                <button class="button button-danger payment-reject-button" type="submit" name="decision" value="rejected">Reject</button>
              </div>
            </form>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <?php
  $usageEyebrow = 'Revenue desk';
  $usageTitle = 'How to use revenue and payment';
  $usageLead = 'A quick walkthrough of the workflow this page manages end to end.';
  $usageBullets = [
      'Account details feed the student form.',
      'Students submit proof.',
      'Verify before you approve.',
      'Rejections are explained.',
      'Receipts are generated per payment.',
      'Revenue stays in view.',
  ];
  require BASE_PATH . '/app/Views/components/usage-guide.php';
  ?>
</section>
<div class="submit-progress-overlay" data-submit-progress-overlay hidden role="dialog" aria-modal="true" aria-live="polite" aria-label="Approving payment">
  <div class="submit-progress-card">
    <span class="submit-progress-spinner" aria-hidden="true"></span>
    <p data-submit-progress-message>Your payment is being approved. Please wait.</p>
  </div>
</div>
<script>
(() => {
  const form = document.querySelector('[data-payment-accounts-form]');
  if (!form) return;
  const list = form.querySelector('[data-payment-account-list]');
  const template = form.querySelector('[data-payment-account-template]');
  const renumber = () => list.querySelectorAll('.payment-account-editor').forEach((card, index) => {
    card.querySelector('legend').textContent = `Payment account ${index + 1}`;
    card.querySelectorAll('[data-field]').forEach(input => { input.name = `accounts[${index}][${input.dataset.field}]`; });
    card.querySelectorAll('input[name], textarea[name]').forEach(input => {
      if (input.dataset.field) return;
      const field = input.name.match(/\]\[([^\]]+)\]$/)?.[1];
      if (field) input.name = `accounts[${index}][${field}]`;
    });
    card.querySelector('[data-remove-payment-account]').setAttribute('aria-label', `Remove payment account ${index + 1}`);
  });
  form.addEventListener('click', event => {
    if (event.target.closest('[data-add-payment-account]')) {
      if (list.children.length >= 12) return;
      list.append(template.content.cloneNode(true)); renumber();
    }
    if (event.target.closest('[data-remove-payment-account]')) {
      const card = event.target.closest('.payment-account-editor');
      if (list.children.length > 1) card.remove();
      else card.querySelectorAll('input,textarea').forEach(input => { input.value = ''; });
      renumber();
    }
  });
  renumber();
})();
</script>
