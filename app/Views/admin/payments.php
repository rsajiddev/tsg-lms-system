<?php

use App\Core\Security;

$escape = [Security::class, 'escape'];
$paymentValues = is_array($paymentValues ?? null) ? $paymentValues : [];
$payments = is_array($payments ?? null) ? $payments : [];
$revenue = is_array($revenue ?? null) ? $revenue : [];
$revenueTotals = is_array($revenueTotals ?? null) ? $revenueTotals : [];
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
      <p>Track approved course revenue, manage payment details, and review student submissions.</p>
    </div>
    <div class="revenue-page-toolbar">
      <a class="button button-secondary" href="<?= $escape($baseUrl) ?>/admin/payments/revenue-receipt"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15V3m0 0 4 4m-4-4-4 4"/><path d="M4 13v5a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5"/></svg>Export</a>
      <form method="post" action="<?= $escape($baseUrl) ?>/admin/payments/revenue-notify"><input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>"><button class="button" type="submit"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h16v12H4z"/><path d="m4 7 8 6 8-6"/></svg>Notify developer</button></form>
    </div>
  </header>

  <?php if ($flash && !empty($flash['text'])): ?>
    <div class="admin-alert <?= ($flash['type'] ?? '') === 'error' ? 'admin-alert-error' : 'admin-alert-success' ?>" role="status"><?= $escape($flash['text']) ?></div>
  <?php endif; ?>

  <section class="revenue-kpi-grid" aria-label="Revenue summary">
    <article class="revenue-kpi revenue-kpi--hero">
      <div class="revenue-kpi-top"><span class="revenue-kpi-label">Total revenue</span><span class="revenue-kpi-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M16 12h.01M3 9h16"/></svg></span></div>
      <div class="revenue-kpi-value"><?= $metricLines($lifetimeTotals) ?></div>
      <p class="revenue-kpi-foot"><span class="revenue-kpi-foot-label">All-time approved</span><span>This month · <?= $escape($inlineTotals($monthlyTotals)) ?></span></p>
    </article>

    <article class="revenue-kpi revenue-kpi--developer">
      <div class="revenue-kpi-top"><span class="revenue-kpi-label">Developer share · 15%</span><span class="revenue-kpi-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17 9 11l4 4 8-8"/><path d="M15 7h6v6"/></svg></span></div>
      <div class="revenue-kpi-value"><?= $metricLines($developerTotals) ?></div>
      <p class="revenue-kpi-foot"><span class="revenue-kpi-foot-label">15% of all-time revenue</span><span>This month · <?= $escape($inlineTotals($developerMonthly)) ?></span></p>
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
  </section>

  <section class="admin-card payment-settings-card">
    <div class="admin-card-header"><div class="admin-card-title-group"><div class="admin-card-icon violet" aria-hidden="true">₨</div><div class="admin-card-titles"><h2>Payment account details</h2><p>Manage the bank and wallet information students see when submitting a payment.</p></div></div></div>
    <form method="post" action="<?= $escape($baseUrl) ?>/admin/payments/settings" class="admin-form">
      <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>">
      <div class="admin-form-row">
        <div class="admin-field"><label class="admin-label" for="payment-account-title">Account title</label><input class="admin-input" id="payment-account-title" name="payment_account_title" maxlength="150" value="<?= $escape($paymentValues['payment_account_title'] ?? '') ?>" placeholder="Account holder name"></div>
        <div class="admin-field"><label class="admin-label" for="payment-bank-name">Bank name</label><input class="admin-input" id="payment-bank-name" name="payment_bank_name" maxlength="100" value="<?= $escape($paymentValues['payment_bank_name'] ?? '') ?>" placeholder="Bank or provider"></div>
      </div>
      <div class="admin-form-row">
        <div class="admin-field"><label class="admin-label" for="payment-account-number">Account number</label><input class="admin-input" id="payment-account-number" name="payment_account_number" maxlength="80" value="<?= $escape($paymentValues['payment_account_number'] ?? '') ?>" autocomplete="off"></div>
        <div class="admin-field"><label class="admin-label" for="payment-iban">IBAN</label><input class="admin-input" id="payment-iban" name="payment_iban" maxlength="80" value="<?= $escape($paymentValues['payment_iban'] ?? '') ?>" autocomplete="off"></div>
      </div>
      <div class="admin-form-row">
        <div class="admin-field"><label class="admin-label" for="payment-wallet-name">Mobile wallet name</label><input class="admin-input" id="payment-wallet-name" name="payment_wallet_name" maxlength="100" value="<?= $escape($paymentValues['payment_wallet_name'] ?? '') ?>" placeholder="Optional"></div>
        <div class="admin-field"><label class="admin-label" for="payment-wallet-number">Mobile wallet number</label><input class="admin-input" id="payment-wallet-number" name="payment_wallet_number" maxlength="80" value="<?= $escape($paymentValues['payment_wallet_number'] ?? '') ?>" placeholder="Optional"></div>
      </div>
      <div class="admin-field"><label class="admin-label" for="payment-instructions">Student payment instructions</label><textarea class="admin-textarea" id="payment-instructions" name="payment_instructions" rows="3" maxlength="255" placeholder="For example: include your transaction reference when submitting payment proof."><?= $escape($paymentValues['payment_instructions'] ?? '') ?></textarea><span class="admin-hint">Maximum 255 characters. Leave account fields blank until verified account details are available.</span></div>
      <div class="form-actions"><button class="button" type="submit">Save payment details</button></div>
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
            <div class="dashboard-panel-heading"><div><p class="eyebrow">PAYMENT #<?= (int) ($payment['id'] ?? 0) ?> · <?= $escape(date('M j, Y g:i A', strtotime((string) ($payment['created_at'] ?? 'now')))) ?></p><h3><?= $escape($payment['course_title'] ?? 'Course') ?></h3></div><span class="dashboard-status status-pending">Pending review</span></div>
            <div class="payment-review-meta"><div><small>Student</small><strong><?= $escape($payment['student_name'] ?? '') ?></strong><a href="mailto:<?= $escape($payment['student_email'] ?? '') ?>"><?= $escape($payment['student_email'] ?? '') ?></a></div><div><small>Submitted amount</small><strong><?= $escape($payment['currency'] ?? '') ?> <?= number_format((float) ($payment['amount'] ?? 0), 2) ?></strong></div><div><small>Transaction reference</small><strong><?= $escape($payment['transaction_reference'] ?? '') ?></strong></div></div>
            <?php if (!empty($payment['payment_proof_path'])): ?><a class="button button-secondary payment-receipt-link" href="<?= $escape($baseUrl) ?>/payments/proof?payment_id=<?= (int) $payment['id'] ?>">View submitted payment proof</a><?php endif; ?>
            <form method="post" action="<?= $escape($baseUrl) ?>/admin/payments/decision" class="payment-decision-form"><input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>"><input type="hidden" name="payment_id" value="<?= (int) ($payment['id'] ?? 0) ?>"><label class="admin-label">Review note (optional)<textarea class="admin-textarea" name="review_note" rows="2" maxlength="1000" placeholder="If rejecting, explain what needs correction."></textarea></label><div class="dashboard-actions"><button class="button" type="submit" name="decision" value="approved">Verify payment &amp; grant access</button><button class="button button-secondary" type="submit" name="decision" value="rejected">Reject payment</button></div></form>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</section>
