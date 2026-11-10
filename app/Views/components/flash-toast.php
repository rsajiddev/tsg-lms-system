<?php

use App\Core\Security;
use App\Core\View;

/**
 * The confirmation overlay rendered once per page by layouts/footer.php.
 *
 * A flash message used to sit at the top of the page, where it read as part of the layout and
 * was easy to miss on a long page - exactly the moment it must not be missed. It is now a card
 * floated over the page, with the outcome stated in one word, the sentence underneath it and a
 * button that repeats the outcome, and it closes on its own after a few seconds.
 *
 * Nothing is printed unless a view queued a message with View::toast(), so a plain page render
 * costs no markup. Without JavaScript the card still shows and simply stays on screen.
 */
$flashToasts = View::toasts();
if ($flashToasts === []) {
    return;
}
$escape = static fn(mixed $value): string => Security::escape((string) $value);
// Each tone carries the outcome before anybody reads a word; the label repeats it in text so the
// card still works for a colour-blind reader and on a screen that renders the palette flat.
$flashTones = [
    'success' => ['label' => 'Success', 'role' => 'status', 'button' => 'Continue', 'icon' => '<circle cx="12" cy="12" r="9"/><path d="m8.4 12.4 2.5 2.5 4.7-5.4"/>'],
    'error' => ['label' => 'Error', 'role' => 'alert', 'button' => 'Try again', 'icon' => '<circle cx="12" cy="12" r="9"/><path d="M12 7.6v5.2"/><path d="M12 16.3v.1"/>'],
    'info' => ['label' => 'Information', 'role' => 'status', 'button' => 'Got it', 'icon' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.4"/><path d="M12 7.7v.1"/>'],
    'pending' => ['label' => 'Payment pending', 'role' => 'status', 'button' => 'Wait until payment is approved', 'icon' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'],
];
?>
<div class="flash-toast-layer" data-flash-layer>
    <div class="flash-toast-backdrop" data-flash-backdrop></div>
    <?php foreach ($flashToasts as $flashToast): ?>
        <?php $flashTone = $flashTones[$flashToast['tone']] ?? $flashTones['info']; ?>
        <div class="flash-toast-card is-<?= $escape($flashToast['tone']) ?>" data-flash-card role="<?= $escape($flashTone['role']) ?>">
            <span class="flash-toast-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $flashTone['icon'] ?></svg>
            </span>
            <p class="flash-toast-label"><?= $escape($flashTone['label']) ?></p>
            <p class="flash-toast-copy"><?= $escape($flashToast['text']) ?></p>
            <button class="flash-toast-action" type="button" data-flash-close><?= $escape($flashTone['button']) ?></button>
        </div>
    <?php endforeach; ?>
</div>
