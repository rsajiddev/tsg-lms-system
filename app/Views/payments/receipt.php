<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $value): string => Security::escape((string) $value);
$payment = isset($payment) && is_array($payment) ? $payment : [];
$issuedAt = static function (?string $value): string {
    if (!$value) {
        return '—';
    }
    $timestamp = strtotime($value . ' UTC');
    return $timestamp === false ? '—' : date('g:i A · M j, Y', $timestamp);
};
$currency = strtoupper(trim((string) ($payment['currency'] ?? ''))) ?: 'PKR';
$amountLabel = $currency . ' ' . number_format((float) ($payment['amount'] ?? 0), 2);
$receiptNumber = (string) ($payment['receipt_number'] ?? '—');
?>
<section class="dashboard-page receipt-page" data-reveal>
    <div class="dashboard-topbar">
        <button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
        <div class="dashboard-breadcrumb"><a href="<?= $escape($baseUrl) ?>/dashboard">Workspace</a><i>/</i><a href="<?= $escape($baseUrl) ?>/student/payments">Payments</a><i>/</i><strong>Receipt</strong></div>
    </div>

    <div class="receipt-shell">
        <article class="receipt-card" role="document" aria-label="Payment receipt <?= $escape($receiptNumber) ?>">
            <div class="receipt-back"><a href="<?= $escape($baseUrl) ?>/student/payments" aria-label="Back to payments"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg></a></div>

            <div class="receipt-hero">
                <span class="receipt-check" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m5 13 4 4L19 7"/></svg></span>
                <p class="receipt-status">Payment Successful!</p>
                <h1 class="receipt-title">Official Receipt</h1>
                <p class="receipt-subtitle">Tech SG Academy · Course enrolment</p>
            </div>

            <div class="receipt-divider" aria-hidden="true"></div>

            <dl class="receipt-meta">
                <div><dt>Receipt No:</dt><dd><?= $escape($receiptNumber) ?></dd></div>
                <div><dt>Issued:</dt><dd><?= $escape($issuedAt($payment['receipt_issued_at'] ?? null)) ?></dd></div>
                <div><dt>Payment Method:</dt><dd>Verified bank / wallet transfer</dd></div>
            </dl>

            <div class="receipt-divider" aria-hidden="true"></div>

            <table class="receipt-table">
                <thead>
                    <tr><th scope="col">Items</th><th scope="col" class="receipt-table-num">Amount</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td><span class="receipt-qty">1x</span> <?= $escape($payment['course_title'] ?? 'Course enrolment') ?></td>
                        <td class="receipt-table-num"><?= $escape($amountLabel) ?></td>
                    </tr>
                    <tr class="receipt-item-note">
                        <td colspan="2">Course enrolment payment · fully verified by the Academy</td>
                    </tr>
                </tbody>
            </table>

            <div class="receipt-divider" aria-hidden="true"></div>

            <dl class="receipt-totals">
                <div class="receipt-total-row"><dt>Subtotal</dt><dd><?= $escape($amountLabel) ?></dd></div>
                <div class="receipt-total-row receipt-total-row--final"><dt>Total Paid</dt><dd><?= $escape($amountLabel) ?></dd></div>
            </dl>

            <div class="receipt-divider" aria-hidden="true"></div>

            <dl class="receipt-party">
                <div><dt>Billed to</dt><dd><strong><?= $escape($payment['student_name'] ?? 'Student') ?></strong><?php if (!empty($payment['student_email'])): ?><small><?= $escape($payment['student_email']) ?></small><?php endif; ?></dd></div>
                <div><dt>Transaction reference</dt><dd><?= $escape($payment['transaction_reference'] ?? '—') ?></dd></div>
            </dl>

            <p class="receipt-thanks">Thanks for learning with Tech SG Academy!</p>
            <p class="receipt-legal">Issued by Tech SG Studio (SMC-Pvt.) Ltd. · info@techsgstudio.com<br>This receipt confirms payment received for the course listed above.</p>

            <div class="receipt-actions">
                <a class="button receipt-action" href="<?= $escape($baseUrl) ?>/payments/receipt/download?payment_id=<?= (int) ($payment['id'] ?? 0) ?>">Download JPG receipt</a>
                <button class="button button-secondary receipt-action" type="button" onclick="window.print()">Print receipt</button>
                <a class="button button-ghost receipt-action" href="<?= $escape($baseUrl) ?>/student/payments">Back to Payments</a>
            </div>
        </article>
    </div>
</section>
