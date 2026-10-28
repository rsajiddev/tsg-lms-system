<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$escape = static fn(mixed $value): string => Security::escape((string) $value);
$user = isset($user) && is_array($user) ? $user : [];
$payments = is_array($payments ?? null) ? $payments : [];
$pendingCount = (int) ($pendingCount ?? 0);
$approvedCount = (int) ($approvedCount ?? 0);
$rejectedCount = (int) ($rejectedCount ?? 0);
$dateLabel = static function (?string $value): string {
    if (!$value) {
        return '—';
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? '—' : date('M j, Y', $timestamp);
};
$money = static fn(mixed $amount, mixed $currency): string => $escape((string) $currency . ' ' . number_format((float) $amount, 2));
?>
<section class="dashboard-page" data-reveal>
    <div class="dashboard-topbar">
        <button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
        <div class="dashboard-breadcrumb"><a href="<?= $escape($baseUrl) ?>/dashboard">Workspace</a><i>/</i><strong>Payments</strong></div>
    </div>

    <header class="dashboard-heading">
        <div>
            <p class="eyebrow">ACCOUNT &amp; FEES</p>
            <h1>Payments</h1>
            <p>Submit a transaction reference and track every review decision made by the academy.</p>
        </div>
        <a class="button" href="<?= $escape($baseUrl) ?>/payments/new">Submit payment</a>
    </header>

    <div class="dashboard-metrics" aria-label="Payment summary">
        <article class="dashboard-metric"><span class="dashboard-metric-icon">01</span><span class="dashboard-metric-label">Awaiting review</span><strong><?= number_format($pendingCount) ?></strong><small>Submissions the academy has not verified</small></article>
        <article class="dashboard-metric"><span class="dashboard-metric-icon">02</span><span class="dashboard-metric-label">Approved</span><strong><?= number_format($approvedCount) ?></strong><small>Access granted after verification</small></article>
        <article class="dashboard-metric"><span class="dashboard-metric-icon">03</span><span class="dashboard-metric-label">Rejected</span><strong><?= number_format($rejectedCount) ?></strong><small>Resubmit with corrected details</small></article>
        <article class="dashboard-metric"><span class="dashboard-metric-icon">04</span><span class="dashboard-metric-label">Total submissions</span><strong><?= number_format(count($payments)) ?></strong><small>All payment records on your account</small></article>
    </div>

    <section class="dashboard-panel">
        <div class="dashboard-panel-heading">
            <div>
                <p class="eyebrow">HISTORY</p>
                <h2>Payment submissions</h2>
            </div>
        </div>
        <?php if ($payments === []): ?>
            <div class="dashboard-empty"><span class="dashboard-empty-icon">₨</span>
                <div>
                    <h3>No payments recorded</h3>
                    <p>Once you submit a transaction reference it will appear here with its review status.</p><a class="dashboard-inline-link" href="<?= $escape($baseUrl) ?>/payments/new">Submit your first payment →</a>
                </div>
            </div>
        <?php else: ?>
            <div class="dashboard-table-wrap">
                <table class="dashboard-table">
                    <thead>
                        <tr>
                            <th scope="col">Course</th>
                            <th scope="col">Amount</th>
                            <th scope="col">Reference</th>
                            <th scope="col">Status</th>
                            <th scope="col">Submitted</th>
                            <th scope="col">Payment proof</th>
                            <th scope="col">Academy receipt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td><strong><?= $escape($payment['course_title']) ?></strong><?php if (!empty($payment['review_note'])): ?><small><?= $escape($payment['review_note']) ?></small><?php endif; ?></td>
                                <td><?= $money($payment['amount'], $payment['currency']) ?></td>
                                <td><small><?= $escape($payment['transaction_reference'] ?: '—') ?></small></td>
                                <td><span class="dashboard-status status-<?= $escape($payment['status']) ?>"><?= $escape(ucfirst($payment['status'])) ?></span><?php if ($payment['status'] !== 'pending'): ?><small>Reviewed <?= $escape($dateLabel($payment['reviewed_at'] ?? null)) ?></small><?php endif; ?></td>
                                <td><small><?= $escape($dateLabel($payment['created_at'] ?? null)) ?></small></td>
                                <td><?php if (!empty($payment['payment_proof_path'])): ?><a class="dashboard-inline-link" href="<?= $escape($baseUrl) ?>/payments/proof?payment_id=<?= (int) $payment['id'] ?>">View uploaded proof</a><?php else: ?><small>—</small><?php endif; ?></td>
                                <td><?php if ($payment['status'] === 'approved' && !empty($payment['receipt_number'])): ?><a class="dashboard-inline-link" href="<?= $escape($baseUrl) ?>/payments/receipt?payment_id=<?= (int) $payment['id'] ?>">Download JPG receipt</a><?php else: ?><small><?= $payment['status'] === 'pending' ? 'After approval' : 'Not issued' ?></small><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <div class="dashboard-note"><strong>How review works</strong><span>The academy verifies your transaction reference before course access is granted. Approved submissions unlock the course in <a href="<?= $escape($baseUrl) ?>/student/courses">My courses</a>.</span></div>
</section>
