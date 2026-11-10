<?php
use App\Core\Security;
$escape = [Security::class, 'escape'];
$paymentDetails = $paymentDetails ?? [];
$paymentAccounts = is_array($paymentAccounts ?? null) ? $paymentAccounts : [];
if ($paymentAccounts === [] && count(array_filter($paymentDetails, static fn($value): bool => trim((string) $value) !== '')) > 0) $paymentAccounts[] = ['account_title'=>$paymentDetails['payment_account_title'] ?? '', 'bank_name'=>$paymentDetails['payment_bank_name'] ?? '', 'account_number'=>$paymentDetails['payment_account_number'] ?? '', 'iban'=>$paymentDetails['payment_iban'] ?? '', 'wallet_name'=>$paymentDetails['payment_wallet_name'] ?? '', 'wallet_number'=>$paymentDetails['payment_wallet_number'] ?? '', 'instructions'=>$paymentDetails['payment_instructions'] ?? ''];
$hasPaymentDetails = $paymentAccounts !== [];
$options = [];
if ($course['recorded_price'] !== null && (float) $course['recorded_price'] > 0) $options[] = ['code'=>'recorded_only','label'=>'Recorded Only','price'=>$course['recorded_price']];
if ($course['live_price'] !== null && (float) $course['live_price'] > 0) $options[] = ['code'=>'live_recorded','label'=>'Live + Recorded','price'=>$course['live_price']];
// The submission being reviewed is in the history block below, but the student looking at this
// screen wants it at the top: what was sent, and what happens next.
$pendingPayment = null;
foreach ($payments as $payment) {
    if (($payment['status'] ?? '') === 'pending') { $pendingPayment = $payment; break; }
}
$paymentDetailSymbols = ['account_title' => 'ID', 'bank_name' => '▣', 'account_number' => '#', 'iban' => '⌁', 'wallet_name' => '◉', 'wallet_number' => '☎'];
?>
<section class="dashboard-page payment-page payment-checkout-page">
  <header class="payment-hero-banner">
    <div class="payment-hero-copy"><p class="eyebrow">COURSE ENROLMENT</p><h1>Complete your enrolment</h1><p>Submit your payment details and proof securely. Your course access will open after the Academy verifies the transaction.</p><div class="payment-hero-pills"><span><?= $escape($course['title']) ?></span><span>Secure manual verification</span></div></div>
    <div class="payment-hero-status"><span class="payment-hero-status-icon" aria-hidden="true">✓</span><strong>Access after approval</strong><small>We will email you when your course is ready.</small></div>
  </header>
  <?php \App\Core\View::toast((string) ($messageType ?? 'success'), $message ?? null); ?>
  <div class="payment-layout">
    <section class="dashboard-panel">
      <div class="dashboard-panel-heading"><div><p class="eyebrow">SELECTED COURSE</p><h2><?= $escape($course['title']) ?></h2></div></div>
      <?php if ($hasPaymentDetails): ?>
        <div class="payment-help"><strong>Payment account details</strong>
          <div class="student-payment-accounts">
            <?php foreach ($paymentAccounts as $accountIndex => $account): ?><article class="student-payment-account"><h3><span class="payment-bank-icon" aria-hidden="true">▣</span><?= $escape($account['bank_name'] ?: ($account['wallet_name'] ?: 'Payment option')) ?></h3>
              <dl class="payment-account-details">
                <?php foreach (['account_title'=>'Account title','bank_name'=>'Bank / provider','account_number'=>'Account number','iban'=>'IBAN','wallet_name'=>'Mobile wallet','wallet_number'=>'Wallet number'] as $key=>$label): ?>
                  <?php if (!empty($account[$key])): ?><div><dt><span class="payment-detail-symbol" aria-hidden="true"><?= $paymentDetailSymbols[$key] ?></span><?= $escape($label) ?></dt><dd><?= $escape($account[$key]) ?></dd></div><?php endif; ?>
                <?php endforeach; ?>
              </dl>
              <?php if (!empty($account['instructions'])): ?><p><?= nl2br($escape($account['instructions'])) ?></p><?php endif; ?>
            </article><?php endforeach; ?>
          </div>
          <p>Access is granted only after an administrator verifies the submitted transaction.</p>
        </div>
      <?php else: ?>
        <p class="payment-help">Payment account details have not been configured yet. Please contact Tech SG Academy before transferring. Access is granted only after an administrator verifies the submitted transaction.</p>
      <?php endif; ?>
      <?php if ($hasPendingPayment): ?>
        <section class="payment-pending-card" aria-labelledby="payment-pending-title">
          <div class="payment-pending-heading">
            <span class="payment-pending-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg></span>
            <div><p class="eyebrow">PAYMENT STATUS</p><h3 id="payment-pending-title">Payment pending approval</h3><p>Your payment has been received and is now being checked by our team.</p></div>
            <span class="payment-pending-badge">Pending</span>
          </div>
          <div class="payment-pending-progress" aria-label="Payment approval progress"><span class="is-complete"></span><span class="is-current"></span><span></span></div>
          <ol class="payment-pending-steps">
            <li class="is-complete"><span>1</span><strong>Submitted</strong><small>Payment received</small></li>
            <li class="is-current"><span>2</span><strong>Under review</strong><small>Our team is checking it</small></li>
            <li><span>3</span><strong>Access granted</strong><small>After approval</small></li>
          </ol>
          <?php if ($pendingPayment !== null): ?><dl class="payment-pending-summary"><div><dt>Course</dt><dd><?= $escape($course['title']) ?></dd></div><div><dt>Amount</dt><dd><?= $escape($course['currency']) ?> <?= number_format((float) $pendingPayment['amount'], 2) ?></dd></div><div><dt>Reference</dt><dd><?= $escape($pendingPayment['transaction_reference']) ?></dd></div><div><dt>Submitted</dt><dd><?= $escape(date('M j, Y', strtotime($pendingPayment['created_at']))) ?></dd></div></dl><?php endif; ?>
          <div class="payment-pending-note"><strong>What happens next?</strong><p>Please wait while the Academy verifies your transaction. You will receive an email and notification as soon as your course access is approved. No further action is required.</p></div>
        </section>
      <?php elseif ($options === []): ?><div class="dashboard-empty"><div><h3>Pricing is not available</h3><p>Please contact the Academy team to confirm course pricing.</p></div></div>
      <?php else: ?>
        <form method="post" action="<?= $escape($baseUrl) ?>/payments" enctype="multipart/form-data" class="payment-form" data-submit-progress-form>
          <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>"><input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
          <label class="admin-label" for="payment-option">Learning option<select class="admin-select" id="payment-option" name="option_code" required><?php foreach ($options as $option): ?><option value="<?= $escape($option['code']) ?>"><?= $escape($option['label']) ?> — <?= $escape($course['currency']) ?> <?= number_format((float) $option['price'], 2) ?></option><?php endforeach; ?></select></label>
          <label class="admin-label" for="payment-reference">Transaction reference / ID<input class="admin-input" id="payment-reference" name="transaction_reference" required minlength="3" maxlength="190" autocomplete="off"></label>
          <label class="admin-label" for="payment-proof">Payment proof <span>PDF, JPG, PNG or WebP · max 5 MB</span><input class="admin-input" id="payment-proof" name="payment_proof" type="file" accept="application/pdf,image/jpeg,image/png,image/webp" required></label>
          <button class="button" type="submit">Submit for verification</button>
        </form>
      <?php endif; ?>
    </section>
  </div>
</section>
<div class="submit-progress-overlay" data-submit-progress-overlay hidden role="dialog" aria-modal="true" aria-live="polite" aria-label="Submitting payment">
  <div class="submit-progress-card">
    <span class="submit-progress-spinner" aria-hidden="true"></span>
    <p data-submit-progress-message>Your payment approval request is being sent to the admin. Please wait.</p>
  </div>
</div>
