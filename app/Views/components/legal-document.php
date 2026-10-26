<?php

use App\Core\Security;

$legalPage = is_array($legalPage ?? null) ? $legalPage : [];
$legalTitle = is_string($legalPage['title'] ?? null) ? $legalPage['title'] : 'Academy policy';
$legalSummary = is_string($legalPage['summary'] ?? null) ? $legalPage['summary'] : '';
$legalUpdated = is_string($legalPage['updated'] ?? null) ? $legalPage['updated'] : '';
$legalSource = is_string($legalPage['source'] ?? null) ? $legalPage['source'] : '';
$legalSections = is_array($legalPage['sections'] ?? null) ? $legalPage['sections'] : [];
$legalHighlights = is_array($legalPage['highlights'] ?? null) ? $legalPage['highlights'] : [];
$legalClosing = is_string($legalPage['closing'] ?? null) ? $legalPage['closing'] : '';
$baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
?>
<section class="legal-hero" data-reveal>
    <div class="legal-hero-copy">
        <p class="eyebrow">ACADEMY POLICIES</p>
        <h1><?= Security::escape($legalTitle) ?></h1>
        <p><?= Security::escape($legalSummary) ?></p>
    </div>
    <div class="legal-hero-mark" aria-hidden="true">
        <svg viewBox="0 0 48 48" fill="none">
            <path d="M14 6h14l8 8v27H14a4 4 0 0 1-4-4V10a4 4 0 0 1 4-4Z" />
            <path d="M28 6v9h8M17 23h16M17 29h16M17 35h10" />
            <path d="m6 19 3 3 5-6" />
        </svg>
    </div>
</section>

<?php if ($legalHighlights !== []): ?>
    <section class="legal-highlights" aria-label="Refund policy overview" data-reveal>
        <?php foreach ($legalHighlights as $highlight): ?>
            <?php if (!is_array($highlight)) continue; ?>
            <article class="legal-highlight">
                <strong><?= Security::escape(is_string($highlight['value'] ?? null) ? $highlight['value'] : '') ?></strong>
                <span><?= Security::escape(is_string($highlight['label'] ?? null) ? $highlight['label'] : '') ?></span>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<section class="legal-layout">
    <aside class="legal-sidebar">
        <nav class="legal-toc" aria-label="On this page">
            <span class="legal-toc-kicker">DOCUMENT GUIDE</span>
            <h2>On this page</h2>
            <ol>
                <?php foreach ($legalSections as $index => $section): ?>
                    <?php if (!is_array($section)) continue; ?>
                    <li><a href="#legal-section-<?= (int) $index + 1 ?>"><span><?= str_pad((string) ((int) $index + 1), 2, '0', STR_PAD_LEFT) ?></span><?= Security::escape(is_string($section['title'] ?? null) ? $section['title'] : '') ?></a></li>
                <?php endforeach; ?>
            </ol>
        </nav>
        <div class="legal-help-card">
            <span class="legal-help-icon" aria-hidden="true">?</span>
            <h3>Need help?</h3>
            <p>Contact the Academy team if you have a question about this policy.</p>
            <a href="<?= Security::escape($baseUrl) ?>/contact">Contact us <span aria-hidden="true">↗</span></a>
        </div>
    </aside>

    <article class="legal-document" aria-label="<?= Security::escape($legalTitle) ?>">
        <header class="legal-document-header">
            <div>
                <span class="legal-document-label">OFFICIAL POLICY</span>
                <p>Last updated <strong><?= Security::escape($legalUpdated) ?></strong></p>
            </div>
            <?php if ($legalSource !== ''): ?>
                <a href="<?= Security::escape($legalSource) ?>" target="_blank" rel="noopener noreferrer">View company source <span aria-hidden="true">↗</span></a>
            <?php endif; ?>
        </header>

        <?php foreach ($legalSections as $index => $section): ?>
            <?php if (!is_array($section)) continue; ?>
            <?php
            $sectionTitle = is_string($section['title'] ?? null) ? $section['title'] : '';
            $paragraphs = is_array($section['paragraphs'] ?? null) ? $section['paragraphs'] : [];
            $points = is_array($section['points'] ?? null) ? $section['points'] : [];
            $after = is_string($section['after'] ?? null) ? $section['after'] : '';
            ?>
            <section class="legal-section" id="legal-section-<?= (int) $index + 1 ?>">
                <div class="legal-section-heading">
                    <span><?= str_pad((string) ((int) $index + 1), 2, '0', STR_PAD_LEFT) ?></span>
                    <h2><?= Security::escape($sectionTitle) ?></h2>
                </div>
                <?php foreach ($paragraphs as $paragraph): ?>
                    <?php if (is_string($paragraph) && $paragraph !== ''): ?>
                        <p><?= Security::escape($paragraph) ?></p>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if ($points !== []): ?>
                    <ul class="legal-points">
                        <?php foreach ($points as $point): ?>
                            <?php if (is_array($point)): ?>
                                <li>
                                    <?php if (is_string($point['label'] ?? null) && $point['label'] !== ''): ?><strong><?= Security::escape($point['label']) ?></strong><?php endif; ?>
                                    <?php if (is_string($point['text'] ?? null) && $point['text'] !== ''): ?><span><?= Security::escape($point['text']) ?></span><?php endif; ?>
                                </li>
                            <?php elseif (is_string($point) && $point !== ''): ?>
                                <li><span><?= Security::escape($point) ?></span></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($after !== ''): ?><p><?= Security::escape($after) ?></p><?php endif; ?>
            </section>
        <?php endforeach; ?>

        <?php if ($legalClosing !== ''): ?>
            <footer class="legal-document-closing"><span aria-hidden="true">✓</span><p><?= Security::escape($legalClosing) ?></p></footer>
        <?php endif; ?>
    </article>
</section>
