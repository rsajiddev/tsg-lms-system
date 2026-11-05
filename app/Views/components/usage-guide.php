<?php

use App\Core\Security;

/**
 * Shared "How to use ..." reference list, printed at the foot of a workspace page.
 *
 * Most readers never open a walkthrough, so this is a footnote rather than a feature: no card
 * chrome, one small heading line and a single quiet column of short reference lines, each marked
 * with an arrow. A line is a pointer, not a paragraph - the page above it already carries the
 * detail - so every entry is kept to one short phrase and nothing here competes with the work.
 *
 * View::render() or the including page supplies:
 * @var string $usageEyebrow  Small uppercase label above the heading (optional)
 * @var string $usageTitle    Heading; a plain-English promise reads best
 * @var string $usageLead     One-line intro shown on the heading line
 * @var array<int, string> $usageBullets  Short reference lines; anything past the first full stop
 *                                        is dropped, so a line only ever reads as its title
 */
$usageEyebrow = isset($usageEyebrow) && is_string($usageEyebrow) ? trim($usageEyebrow) : 'How this page works';
$usageTitle = isset($usageTitle) && is_string($usageTitle) ? trim($usageTitle) : 'How to use this page';
$usageLead = isset($usageLead) && is_string($usageLead) ? trim($usageLead) : '';
$usageBullets = isset($usageBullets) && is_array($usageBullets) ? array_values(array_filter($usageBullets, static fn(mixed $line): bool => is_string($line) && trim($line) !== '')) : [];
$escape = static fn(mixed $value): string => Security::escape((string) $value);
// Each line is cut at its first full stop: the title is the whole pointer, and the sentence that
// used to explain it is no longer printed. A line with no full stop is shown as it was written.
$usageLines = [];
foreach ($usageBullets as $usageLine) {
    $usageLine = trim($usageLine);
    $usageBreak = strpos($usageLine, '. ');
    if ($usageBreak !== false && $usageBreak < 70) {
        $usageLine = substr($usageLine, 0, $usageBreak + 1);
    }
    if ($usageLine !== '') {
        $usageLines[] = $usageLine;
    }
}
if ($usageLines === []) {
    return;
}
// One inline arrow in a soft ring, reused by every line and drawn with strokes so the colour
// comes from CSS rather than a hard-coded fill.
$arrow = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9.4"/><path d="M8.2 12h6.6"/><path d="m11.9 9.2 2.8 2.8-2.8 2.8"/></svg>';
?>
<section class="admin-usage-card" aria-labelledby="admin-usage-title">
    <p class="admin-usage-eyebrow"><?= $escape($usageEyebrow) ?></p>
    <div class="admin-usage-head">
        <h2 class="admin-usage-title" id="admin-usage-title"><?= $escape($usageTitle) ?></h2>
        <?php if ($usageLead !== ''): ?><p class="admin-usage-lead"><?= $escape($usageLead) ?></p><?php endif; ?>
    </div>
    <ul class="admin-usage-list">
        <?php foreach ($usageLines as $usageLine): ?>
            <li>
                <span class="admin-usage-check" aria-hidden="true"><?= $arrow ?></span>
                <p class="admin-usage-copy"><?= $escape($usageLine) ?></p>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
