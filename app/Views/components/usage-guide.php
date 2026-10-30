<?php

use App\Core\Security;

/**
 * Shared "How to use ..." guide card for admin workspace pages.
 *
 * View::render() or the including page supplies:
 * @var string $usageTitle    Card heading (without the component's own prefix)
 * @var string $usageLead     One-line intro shown under the heading
 * @var array<int, string> $usageBullets  Bullet points; a leading "Lead in." sentence is bolded automatically
 */
$usageTitle = isset($usageTitle) && is_string($usageTitle) ? trim($usageTitle) : 'How to use this page';
$usageLead = isset($usageLead) && is_string($usageLead) ? trim($usageLead) : '';
$usageBullets = isset($usageBullets) && is_array($usageBullets) ? array_values(array_filter($usageBullets, static fn(mixed $line): bool => is_string($line) && trim($line) !== '')) : [];
if ($usageBullets === []) {
    return;
}
?>
<section class="admin-card admin-usage-card" aria-labelledby="admin-usage-title">
    <div class="admin-card-header">
        <div class="admin-card-title-group">
            <div class="admin-card-icon blue" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="9"></circle>
                    <path d="M9.5 9a2.5 2.5 0 1 1 3.4 2.35c-.75.3-1.4.95-1.4 1.75V14"></path>
                    <path d="M12 17.25v.1"></path>
                </svg>
            </div>
            <div class="admin-card-titles">
                <h2 id="admin-usage-title"><?= Security::escape($usageTitle) ?></h2>
                <?php if ($usageLead !== ''): ?><p><?= Security::escape($usageLead) ?></p><?php endif; ?>
            </div>
        </div>
        <span class="count-pill"><?= count($usageBullets) ?> <?= count($usageBullets) === 1 ? 'point' : 'points' ?></span>
    </div>
    <ul class="admin-usage-list">
        <?php foreach ($usageBullets as $usageLine): ?>
            <?php
            $usageLine = trim($usageLine);
            $usageBreak = strpos($usageLine, '. ');
            $usageHtml = $usageBreak !== false && $usageBreak < 70
                ? '<strong>' . Security::escape(substr($usageLine, 0, $usageBreak + 1)) . '</strong> ' . Security::escape(substr($usageLine, $usageBreak + 2))
                : Security::escape($usageLine);
            ?>
            <li><span aria-hidden="true">✓</span><p><?= $usageHtml ?></p></li>
        <?php endforeach; ?>
    </ul>
</section>
