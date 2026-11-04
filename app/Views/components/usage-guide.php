<?php

use App\Core\Security;

/**
 * Shared "How to use ..." reference list, printed at the foot of a workspace page.
 *
 * Most readers never open a walkthrough, so this is a footnote rather than a feature: no card
 * chrome, one small heading line and a quiet list of ticked reference lines. Each line keeps its
 * short title and the sentence that explains it, but they run on one line so nothing here competes
 * with the work above it.
 *
 * View::render() or the including page supplies:
 * @var string $usageEyebrow  Small uppercase label above the heading (optional)
 * @var string $usageTitle    Heading; a plain-English promise reads best
 * @var string $usageLead     One-line intro shown on the heading line
 * @var array<int, string> $usageBullets  Reference lines; a leading "Short title." sentence is the line title
 */
$usageEyebrow = isset($usageEyebrow) && is_string($usageEyebrow) ? trim($usageEyebrow) : 'How this page works';
$usageTitle = isset($usageTitle) && is_string($usageTitle) ? trim($usageTitle) : 'How to use this page';
$usageLead = isset($usageLead) && is_string($usageLead) ? trim($usageLead) : '';
$usageBullets = isset($usageBullets) && is_array($usageBullets) ? array_values(array_filter($usageBullets, static fn(mixed $line): bool => is_string($line) && trim($line) !== '')) : [];
if ($usageBullets === []) {
    return;
}
$escape = static fn(mixed $value): string => Security::escape((string) $value);
// One inline tick reused by every line: a ring with a check inside, drawn with strokes so it takes
// the theme colour from CSS instead of carrying a hard-coded fill.
$tick = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9.4"/><path d="m8.3 12.4 2.6 2.6 4.9-5.6"/></svg>';
?>
<section class="admin-usage-card" aria-labelledby="admin-usage-title">
    <p class="admin-usage-eyebrow"><?= $escape($usageEyebrow) ?></p>
    <div class="admin-usage-head">
        <h2 class="admin-usage-title" id="admin-usage-title"><?= $escape($usageTitle) ?></h2>
        <?php if ($usageLead !== ''): ?><p class="admin-usage-lead"><?= $escape($usageLead) ?></p><?php endif; ?>
    </div>
    <ul class="admin-usage-list">
        <?php foreach ($usageBullets as $usageLine): ?>
            <?php
            $usageLine = trim($usageLine);
            $usageBreak = strpos($usageLine, '. ');
            // The first sentence doubles as the line title, so it is only lifted out when it reads
            // like one - a short lead-in ending in a full stop, not the middle of a long thought.
            $usageHasTitle = $usageBreak !== false && $usageBreak < 70 && strlen($usageLine) > $usageBreak + 2;
            ?>
            <li>
                <span class="admin-usage-check" aria-hidden="true"><?= $tick ?></span>
                <p class="admin-usage-copy"><?php if ($usageHasTitle): ?><strong><?= $escape(substr($usageLine, 0, $usageBreak)) ?>.</strong><span class="admin-usage-detail"><?= $escape(substr($usageLine, $usageBreak + 2)) ?></span><?php else: ?><span class="admin-usage-detail"><?= $escape($usageLine) ?></span><?php endif; ?></p>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
