<?php

use App\Core\Security;

$escape = [Security::class, 'escape']; ?>
<section class="dashboard-page" data-reveal>
  <header class="dashboard-heading">
    <div><button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
      <p class="eyebrow">ADMIN WORKSPACE</p>
      <h1>Payment review</h1>
      <p>Verify submitted transaction details before granting course access.</p>
    </div>
  </header>
  <?php if (!empty($message)): ?><div class="admin-alert admin-alert-success" role="status"><?= $escape($message) ?></div><?php endif; ?>
  <?php $paymentValues = is_array($paymentValues ?? null) ? $paymentValues : []; ?>
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
  <?php if ($payments === []): ?><section class="dashboard-panel">
      <div class="dashboard-empty">
        <div>
          <h2>No payments awaiting review</h2>
          <p>New student submissions will appear here.</p>
        </div>
      </div>
    </section><?php else: ?>
    <div class="payment-review-list">
      <?php foreach ($payments as $payment): ?>
        <article class="dashboard-panel payment-review-card">
          <div class="dashboard-panel-heading">
            <div>
              <p class="eyebrow">PAYMENT #<?= (int) $payment['id'] ?> · <?= $escape(date('M j, Y g:i A', strtotime($payment['created_at']))) ?></p>
              <h2><?= $escape($payment['course_title']) ?></h2>
            </div><span class="dashboard-status status-pending">Pending review</span>
          </div>
          <div class="payment-review-meta">
            <div><small>Student</small><strong><?= $escape($payment['student_name']) ?></strong><a href="mailto:<?= $escape($payment['student_email']) ?>"><?= $escape($payment['student_email']) ?></a></div>
            <div><small>Submitted amount</small><strong><?= $escape($payment['currency']) ?> <?= number_format((float) $payment['amount'], 2) ?></strong></div>
            <div><small>Transaction reference</small><strong><?= $escape($payment['transaction_reference']) ?></strong></div>
          </div>
          <?php if (!empty($payment['payment_proof_path'])): ?><a class="button button-secondary payment-receipt-link" href="<?= $escape($baseUrl) ?>/payments/proof?payment_id=<?= (int) $payment['id'] ?>">View submitted payment proof</a><?php endif; ?>
          <form method="post" action="<?= $escape($baseUrl) ?>/admin/payments/decision" class="payment-decision-form"><input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>"><input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>"><label class="admin-label">Review note (optional)<textarea class="admin-textarea" name="review_note" rows="2" maxlength="1000" placeholder="If rejecting, explain what needs correction."></textarea></label>
            <div class="dashboard-actions"><button class="button" type="submit" name="decision" value="approved">Verify payment &amp; grant access</button><button class="button button-secondary" type="submit" name="decision" value="rejected">Reject payment</button></div>
          </form>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
