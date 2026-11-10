<?php

use App\Core\Security;

$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
$reviews = $reviews ?? [];
$contactMessages = is_array($contactMessages ?? null) ? $contactMessages : [];
$message = $message ?? null;
?>
<section class="dashboard-page" data-reveal>
    <header class="dashboard-heading">
        <div><button class="dashboard-menu-toggle" type="button" data-dashboard-toggle aria-expanded="false" aria-controls="dashboard-sidebar" aria-label="Open dashboard menu"><span></span><span></span><span></span></button>
            <p class="eyebrow">ADMIN WORKSPACE</p>
            <h1>Review moderation</h1>
            <p>Approve reviews to publish them on the homepage, or reject them.</p>
        </div>
    </header>
    <?php \App\Core\View::toast('success', $message ?? null); ?>
    <?php if ($reviews === []): ?><section class="dashboard-panel">
            <div class="dashboard-empty">
                <div>
                    <h2>No reviews awaiting approval</h2>
                    <p>New submitted reviews will appear here.</p>
                </div>
            </div>
        </section><?php else: ?>
        <div class="review-moderation-list"><?php foreach ($reviews as $review): ?><article class="dashboard-panel review-moderation-card">
                    <div class="review-moderation-heading">
                        <div>
                            <p class="eyebrow"><?= Security::escape(ucfirst($review['reviewer_type'])) ?> · <?= (int)$review['rating'] ?>/5 stars</p>
                            <h2><?= Security::escape($review['full_name']) ?></h2>
                        </div>
                        <time><?= Security::escape($review['created_at']) ?></time>
                    </div>
                    <p class="review-moderation-context"><?= Security::escape($review['context_label'] ?? '') ?></p>
                    <blockquote class="review-moderation-quote"><?= nl2br(Security::escape($review['content'])) ?></blockquote>
                    <div class="review-moderation-actions"><?php foreach (['approved' => 'Approve and publish', 'rejected' => 'Reject'] as $decision => $label): ?><form method="post" action="<?= Security::escape($baseUrl) ?>/admin/reviews/decision"><input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>"><input type="hidden" name="review_id" value="<?= (int)$review['id'] ?>"><input type="hidden" name="decision" value="<?= Security::escape($decision) ?>"><button class="button <?= $decision === 'rejected' ? 'button-review-reject' : 'button-review-approve' ?>" type="submit"><?= Security::escape($label) ?></button></form><?php endforeach; ?></div>
                </article><?php endforeach; ?></div><?php endif; ?>
    <section class="dashboard-panel contact-inbox" aria-labelledby="contact-inbox-title">
        <div class="dashboard-panel-heading">
            <div>
                <p class="eyebrow">INBOX</p>
                <h2 id="contact-inbox-title">Contact messages</h2>
                <p>Messages submitted through the public contact form.</p>
            </div>
            <div class="contact-inbox-actions">
                <span class="dashboard-status"><?= number_format(count($contactMessages)) ?> received</span>
                <?php if ($contactMessages !== []): ?>
                    <form method="post" action="<?= Security::escape($baseUrl) ?>/admin/reviews/contact-messages/clear" onsubmit="return confirm('Clear all contact messages from this inbox? This cannot be undone.');">
                        <input type="hidden" name="_csrf" value="<?= Security::escape(Security::csrfToken()) ?>">
                        <button class="student-action-button is-clear" type="submit">Clear inbox</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($contactMessages === []): ?>
            <div class="dashboard-empty">
                <div>
                    <h2>No contact messages yet</h2>
                    <p>New messages from the contact form will appear here.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="contact-inbox-list">
                <?php foreach ($contactMessages as $contactMessage): ?>
                    <article class="contact-message-card">
                        <div class="contact-message-heading">
                            <div>
                                <p class="eyebrow"><?= Security::escape($contactMessage['sender_name']) ?></p>
                                <h3><?= Security::escape($contactMessage['subject']) ?></h3>
                            </div>
                            <time><?= Security::escape($contactMessage['submitted_at']) ?></time>
                        </div>
                        <div class="contact-message-sender">
                            <a href="mailto:<?= Security::escape($contactMessage['sender_email']) ?>"><?= Security::escape($contactMessage['sender_email']) ?></a>
                            <?php if (trim((string) $contactMessage['phone']) !== ''): ?>
                                <a href="tel:<?= Security::escape($contactMessage['phone']) ?>"><?= Security::escape($contactMessage['phone']) ?></a>
                            <?php endif; ?>
                        </div>
                        <p class="contact-message-content"><?= nl2br(Security::escape($contactMessage['message'])) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</section>