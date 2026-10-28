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
?>
<section class="dashboard-page admin-revenue-page" data-reveal>
  <header class="dashboard-heading">
    <div>
      <button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
      <p class="eyebrow">ADMIN WORKSPACE</p>
      <h1>Revenue and payment</h1>
      <p>Track approved course revenue, manage payment details, and review student submissions.</p>
    </div>
  </header>

  <?php if ($flash && !empty($flash['text'])): ?>
    <div class="admin-alert <?= ($flash['type'] ?? '') === 'error' ? 'admin-alert-error' : 'admin-alert-success' ?>" role="status"><?= $escape($flash['text']) ?></div>
  <?php endif; ?>

  <section class="revenue-summary-grid" aria-label="Revenue summary">
    <article class="revenue-summary-card"><span class="revenue-summary-icon" aria-hidden="true">♙</span><div><p>Enrolled students</p><strong><?= number_format((int) ($revenueTotals['student_count'] ?? 0)) ?></strong><small>Students with an approved payment</small></div></article>
    <article class="revenue-summary-card"><span class="revenue-summary-icon revenue-summary-icon-violet" aria-hidden="true">₨</span><div><p>Approved payments</p><strong><?= number_format((int) ($revenueTotals['payment_count'] ?? 0)) ?></strong><small>Recorded across all courses</small></div></article>
  </section>

  <section class="admin-card revenue-report-card">
    <div class="admin-card-header revenue-report-header">
      <div class="admin-card-title-group"><div class="admin-card-icon" aria-hidden="true">↗</div><div class="admin-card-titles"><p class="eyebrow">MONTHLY REPORT</p><h2>Revenue overview</h2><p>Approved payments for <?= $escape(gmdate('F Y')) ?> · amounts remain separated by currency.</p></div></div>
      <span class="revenue-period-chip">Developer share · 15%</span>
    </div>
    <?php if ($revenue === []): ?>
      <div class="revenue-empty-state"><strong>No approved revenue this month</strong><span>Monthly totals and the developer share will appear here after a payment is approved.</span></div>
    <?php else: ?>
      <div class="revenue-table-wrap"><table class="revenue-table"><thead><tr><th>Currency</th><th>Payments</th><th>Students</th><th>Monthly revenue</th><th>Developer 15%</th><th>All-time revenue</th><th>All-time developer 15%</th></tr></thead><tbody>
        <?php foreach ($revenue as $row): ?>
          <tr><td><strong><?= $escape($row['currency'] ?? '') ?></strong></td><td><?= number_format((int) ($row['payment_count'] ?? 0)) ?></td><td><?= number_format((int) ($row['student_count'] ?? 0)) ?></td><td><strong><?= $escape($row['currency'] ?? '') ?> <?= number_format((float) ($row['monthly_revenue'] ?? 0), 2) ?></strong></td><td class="revenue-share-cell"><?= $escape($row['currency'] ?? '') ?> <?= number_format((float) ($row['developer_share'] ?? 0), 2) ?></td><td><?= $escape($row['currency'] ?? '') ?> <?= number_format((float) ($row['lifetime_revenue'] ?? 0), 2) ?></td><td class="revenue-share-cell"><?= $escape($row['currency'] ?? '') ?> <?= number_format((float) ($row['lifetime_developer_share'] ?? 0), 2) ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
    <div class="revenue-report-actions">
      <a class="button button-secondary" href="<?= $escape($baseUrl) ?>/admin/payments/revenue-receipt">Download revenue JPG</a>
      <form method="post" action="<?= $escape($baseUrl) ?>/admin/payments/revenue-notify"><input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>"><button class="button" type="submit">Notify developer</button></form>
    </div>
    <p class="revenue-report-note">Monthly and all-time revenue and developer shares are shown separately by currency. Email delivery uses the developer notification address configured in the server environment.</p>
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
