<?php
use App\Core\Security;
$escape = [Security::class, 'escape'];
$paymentDetails = $paymentDetails ?? [];
$hasPaymentDetails = count(array_filter($paymentDetails, static fn($value): bool => trim((string) $value) !== '')) > 0;
$options = [];
if ($course['recorded_price'] !== null && (float) $course['recorded_price'] > 0) $options[] = ['code'=>'recorded_only','label'=>'Recorded Only','price'=>$course['recorded_price']];
if ($course['live_price'] !== null && (float) $course['live_price'] > 0) $options[] = ['code'=>'live_recorded','label'=>'Live + Recorded','price'=>$course['live_price']];
?>
<section class="dashboard-page payment-page">
  <div class="dashboard-topbar"><button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button><div class="dashboard-breadcrumb"><a href="<?= $escape($baseUrl) ?>/dashboard">Workspace</a><i>/</i><strong>Submit payment</strong></div></div>
  <header class="dashboard-heading"><div><p class="eyebrow">COURSE ENROLMENT</p><h1>Submit payment</h1><p>Send your transaction details and receipt for admin verification.</p></div></header>
  <?php if (!empty($message)): ?><div class="admin-alert payment-alert-<?= $escape($messageType ?? 'success') ?>" role="<?= ($messageType ?? 'success') === 'error' ? 'alert' : 'status' ?>"><?= $escape($message) ?></div><?php endif; ?>
  <div class="payment-layout">
    <section class="dashboard-panel">
      <div class="dashboard-panel-heading"><div><p class="eyebrow">SELECTED COURSE</p><h2><?= $escape($course['title']) ?></h2></div></div>
      <?php if ($hasPaymentDetails): ?>
        <div class="payment-help"><strong>Payment account details</strong>
          <dl class="payment-account-details">
            <?php foreach (['payment_account_title'=>'Account title','payment_bank_name'=>'Bank / provider','payment_account_number'=>'Account number','payment_iban'=>'IBAN','payment_wallet_name'=>'Mobile wallet','payment_wallet_number'=>'Wallet number'] as $key=>$label): ?>
              <?php if (!empty($paymentDetails[$key])): ?><div><dt><?= $escape($label) ?></dt><dd><?= $escape($paymentDetails[$key]) ?></dd></div><?php endif; ?>
            <?php endforeach; ?>
          </dl>
          <?php if (!empty($paymentDetails['payment_instructions'])): ?><p><?= nl2br($escape($paymentDetails['payment_instructions'])) ?></p><?php endif; ?>
          <p>Access is granted only after an administrator verifies the submitted transaction.</p>
        </div>
      <?php else: ?>
        <p class="payment-help">Payment account details have not been configured yet. Please contact Tech SG Academy before transferring. Access is granted only after an administrator verifies the submitted transaction.</p>
      <?php endif; ?>
      <?php if ($hasPendingPayment): ?><div class="dashboard-empty"><div><h3>Payment is awaiting review</h3><p>Please wait for the Academy team to verify your submitted transaction before sending another payment.</p></div></div>
      <?php elseif ($options === []): ?><div class="dashboard-empty"><div><h3>Pricing is not available</h3><p>Please contact the Academy team to confirm course pricing.</p></div></div>
      <?php else: ?>
        <form method="post" action="<?= $escape($baseUrl) ?>/payments" enctype="multipart/form-data" class="payment-form">
          <input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>"><input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
          <label class="admin-label" for="payment-option">Learning option<select class="admin-select" id="payment-option" name="option_code" required><?php foreach ($options as $option): ?><option value="<?= $escape($option['code']) ?>"><?= $escape($option['label']) ?> — <?= $escape($course['currency']) ?> <?= number_format((float) $option['price'], 2) ?></option><?php endforeach; ?></select></label>
          <label class="admin-label" for="payment-reference">Transaction reference / ID<input class="admin-input" id="payment-reference" name="transaction_reference" required minlength="3" maxlength="190" autocomplete="off"></label>
          <label class="admin-label" for="payment-receipt">Payment receipt <span>PDF, JPG, PNG or WebP · max 5 MB</span><input class="admin-input" id="payment-receipt" name="receipt" type="file" accept="application/pdf,image/jpeg,image/png,image/webp" required></label>
          <button class="button" type="submit">Submit for verification</button>
        </form>
      <?php endif; ?>
    </section>
    <section class="dashboard-panel">
      <div class="dashboard-panel-heading"><div><p class="eyebrow">PAYMENT HISTORY</p><h2>Recent submissions</h2></div></div>
      <?php if ($payments === []): ?><div class="dashboard-compact-empty"><p>No payment submissions for this course yet.</p></div><?php else: ?><div class="dashboard-list"><?php foreach ($payments as $payment): ?><article class="dashboard-list-row"><span class="dashboard-list-icon">₨</span><div class="dashboard-list-copy"><strong><?= $escape($course['currency']) ?> <?= number_format((float) $payment['amount'], 2) ?></strong><small>Reference: <?= $escape($payment['transaction_reference']) ?> · <?= $escape(date('M j, Y', strtotime($payment['created_at']))) ?></small><?php if (!empty($payment['review_note'])): ?><small><?= $escape($payment['review_note']) ?></small><?php endif; ?><?php if (!empty($payment['id'])): ?><a class="dashboard-inline-link" href="<?= $escape($baseUrl) ?>/payments/receipt?payment_id=<?= (int) $payment['id'] ?>">Download submitted receipt</a><?php endif; ?></div><span class="dashboard-status status-<?= $escape($payment['status']) ?>"><?= $escape(ucfirst($payment['status'])) ?></span></article><?php endforeach; ?></div><?php endif; ?>
    </section>
  </div>
</section>
