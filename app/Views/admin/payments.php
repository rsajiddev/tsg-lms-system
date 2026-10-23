<?php use App\Core\Security; $escape = [Security::class, 'escape']; ?>
<section class="dashboard-page" data-reveal>
  <header class="dashboard-heading"><div><button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button><p class="eyebrow">ADMIN WORKSPACE</p><h1>Payment review</h1><p>Verify submitted transaction details before granting course access.</p></div></header>
  <?php if (!empty($message)): ?><div class="admin-alert admin-alert-success" role="status"><?= $escape($message) ?></div><?php endif; ?>
  <?php if ($payments === []): ?><section class="dashboard-panel"><div class="dashboard-empty"><div><h2>No payments awaiting review</h2><p>New student submissions will appear here.</p></div></div></section><?php else: ?>
    <div class="payment-review-list">
      <?php foreach ($payments as $payment): ?>
        <article class="dashboard-panel payment-review-card">
          <div class="dashboard-panel-heading"><div><p class="eyebrow">PAYMENT #<?= (int) $payment['id'] ?> · <?= $escape(date('M j, Y g:i A', strtotime($payment['created_at']))) ?></p><h2><?= $escape($payment['course_title']) ?></h2></div><span class="dashboard-status status-pending">Pending review</span></div>
          <div class="payment-review-meta"><div><small>Student</small><strong><?= $escape($payment['student_name']) ?></strong><a href="mailto:<?= $escape($payment['student_email']) ?>"><?= $escape($payment['student_email']) ?></a></div><div><small>Submitted amount</small><strong><?= $escape($payment['currency']) ?> <?= number_format((float) $payment['amount'], 2) ?></strong></div><div><small>Transaction reference</small><strong><?= $escape($payment['transaction_reference']) ?></strong></div></div>
          <?php if (!empty($payment['receipt_path'])): ?><a class="button button-secondary payment-receipt-link" href="<?= $escape($baseUrl) ?>/payments/receipt?payment_id=<?= (int) $payment['id'] ?>">Download private receipt</a><?php endif; ?>
          <form method="post" action="<?= $escape($baseUrl) ?>/admin/payments/decision" class="payment-decision-form"><input type="hidden" name="_csrf" value="<?= $escape(Security::csrfToken()) ?>"><input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>"><label class="admin-label">Review note (optional)<textarea class="admin-textarea" name="review_note" rows="2" maxlength="1000" placeholder="If rejecting, explain what needs correction."></textarea></label><div class="dashboard-actions"><button class="button" type="submit" name="decision" value="approved">Verify payment &amp; grant access</button><button class="button button-secondary" type="submit" name="decision" value="rejected">Reject payment</button></div></form>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
