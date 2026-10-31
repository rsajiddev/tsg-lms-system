<?php

use App\Core\Security;

/**
 * Shared "How to use ..." guide card, rendered at the foot of a workspace page.
 *
 * The layout deliberately follows the public course page's enrollment walkthrough: a small
 * uppercase label, one plain-English promise as the heading, then numbered steps where each
 * step has a short title and the sentence that explains it. Same shape on every page means a
 * reader who has learned it once does not have to re-read anything.
 *
 * View::render() or the including page supplies:
 * @var string $usageEyebrow  Small uppercase label above the heading (optional)
 * @var string $usageTitle    Card heading; a plain-English promise reads best
 * @var string $usageLead     One-line intro shown under the heading
 * @var array<int, string> $usageBullets  Steps; a leading "Short title." sentence becomes the step heading
 */
$usageEyebrow = isset($usageEyebrow) && is_string($usageEyebrow) ? trim($usageEyebrow) : 'How this page works';
$usageTitle = isset($usageTitle) && is_string($usageTitle) ? trim($usageTitle) : 'How to use this page';
$usageLead = isset($usageLead) && is_string($usageLead) ? trim($usageLead) : '';
$usageBullets = isset($usageBullets) && is_array($usageBullets) ? array_values(array_filter($usageBullets, static fn(mixed $line): bool => is_string($line) && trim($line) !== '')) : [];
if ($usageBullets === []) {
    return;
}
$escape = static fn(mixed $value): string => Security::escape((string) $value);
?>
<section class="admin-card admin-usage-card" aria-labelledby="admin-usage-title">
    <p class="admin-usage-eyebrow"><?= $escape($usageEyebrow) ?></p>
    <h2 class="admin-usage-title" id="admin-usage-title"><?= $escape($usageTitle) ?></h2>
    <?php if ($usageLead !== ''): ?><p class="admin-usage-lead"><?= $escape($usageLead) ?></p><?php endif; ?>
    <ol class="admin-usage-list">
        <?php foreach ($usageBullets as $usageIndex => $usageLine): ?>
            <?php
            $usageLine = trim($usageLine);
            $usageBreak = strpos($usageLine, '. ');
            // The first sentence doubles as the step title, so it is only lifted out when it reads
            // like one - a short lead-in ending in a full stop, not the middle of a long thought.
            $usageHasTitle = $usageBreak !== false && $usageBreak < 70 && strlen($usageLine) > $usageBreak + 2;
            ?>
            <li>
                <span class="admin-usage-number" aria-hidden="true"><?= $usageIndex + 1 ?></span>
                <p class="admin-usage-copy"><?php if ($usageHasTitle): ?><strong><?= $escape(substr($usageLine, 0, $usageBreak)) ?></strong><span class="admin-usage-detail"><?= $escape(substr($usageLine, $usageBreak + 2)) ?></span><?php else: ?><span class="admin-usage-detail"><?= $escape($usageLine) ?></span><?php endif; ?></p>
            </li>
        <?php endforeach; ?>
    </ol>
</section>
