<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Server-side JPG documents drawn with GD (no headless browser required).
 *
 * Three exports share one drawing toolkit:
 *  - renderReceipt()  the per-payment receipt a student (or admin) downloads. It mirrors the
 *                     on-screen receipt card so the saved image and the browser view match.
 *  - renderRevenue()  the monthly revenue statement exported from the admin payment desk.
 *  - renderReport()   the branded period report exported from the admin Reports desk.
 *
 * Every document is laid out on an A4 portrait canvas at 300 DPI. The report paginates across as
 * many A4 pages as its rows require (PaymentReceiptPdf wraps those pages into a single PDF). The
 * JPEG is encoded at maximum quality.
 */
final class PaymentReceiptJpg
{
    private const SCALE = 3;
    private const QUALITY = 100;
    private const A4_WIDTH = 2480;
    private const A4_HEIGHT = 3508;

    private const BRAND = 'Tech SG Academy';
    private const COMPANY = 'Tech SG Studio (SMC-Pvt.) Ltd.';
    private const TAGLINE = 'We Study. We Build. We Deliver.';
    private const PHONE = '+92 306 9524047';
    private const EMAIL = 'info@techsgstudio.com';
    private const WEB = 'techsgstudio.com';
    private const CITY = 'Faisalabad, Punjab, Pakistan';

    /** @var array<string,?string> */
    private static array $fontCache = [];

    /* ------------------------------------------------------------------ documents */

    public static function renderReceipt(array $payment): string
    {
        $width = 827;
        $height = 1169;
        $image = self::canvas($width, $height, true);
        $c = self::palette($image);

        self::rect($image, 0, 0, $width, $height, $c['page']);
        self::roundRect($image, 24, 28, 779, 1112, 20, $c['white'], $c['border']);
        self::roundRect($image, 24, 28, 779, 250, 20, $c['brand']);
        self::rect($image, 24, 50, 8, 205, $c['blue']);

        $left = 58;
        $right = 769;
        $currency = strtoupper(trim((string) ($payment['currency'] ?? ''))) ?: 'PKR';
        $amountLabel = $currency . ' ' . number_format((float) ($payment['amount'] ?? 0), 2);
        $receiptNumber = (string) ($payment['receipt_number'] ?? '—');
        $course = (string) ($payment['course_title'] ?? 'Course enrollment');
        $center = (int) ($width / 2);

        for ($row = 0; $row < 3; $row++) {
            for ($col = 0; $col < 3; $col++) {
                self::circle($image, $left + $col * 13, 61 + $row * 13, 7, $c['bandText']);
            }
        }
        self::text($image, self::BRAND, 112, 68, $c['white'], 24, true);
        self::text($image, self::COMPANY, 112, 91, $c['bandText'], 11);
        self::text($image, 'CONTACT', $right, 57, $c['white'], 11, true, 'sans', 'right');
        self::text($image, self::CITY, $right, 76, $c['bandText'], 10, false, 'sans', 'right');
        self::text($image, self::PHONE, $right, 94, $c['bandText'], 10, false, 'sans', 'right');
        self::text($image, self::EMAIL, $right, 112, $c['bandText'], 10, false, 'sans', 'right');
        self::line($image, $left, 133, $right, 133, $c['brandLine']);
        self::text($image, 'OFFICIAL PAYMENT RECEIPT', $left, 174, $c['white'], 26, true);
        self::text($image, 'VERIFIED COURSE ENROLLMENT', $left, 198, $c['bandText'], 11, true);
        self::text($image, 'RECEIPT NUMBER', $left, 224, $c['bandText'], 9, true);
        self::text($image, self::fit($image, $receiptNumber, 13, true, 'sans', 300), $left, 247, $c['white'], 13, true);
        self::text($image, 'ISSUED', $right, 224, $c['bandText'], 9, true, 'sans', 'right');
        self::text($image, self::issuedLabel($payment['receipt_issued_at'] ?? null), $right, 247, $c['white'], 12, true, 'sans', 'right');

        self::roundRect($image, 48, 302, 354, 124, 12, $c['pale'], $c['border']);
        self::roundRect($image, 425, 302, 354, 124, 12, $c['pale'], $c['border']);
        self::text($image, 'BILLED TO', 68, 329, $c['blueDark'], 10, true);
        self::text($image, self::fit($image, (string) ($payment['student_name'] ?? 'Student'), 17, true, 'sans', 310), 68, 359, $c['navy'], 17, true);
        $email = (string) ($payment['student_email'] ?? '');
        self::text($image, self::fit($image, $email !== '' ? $email : 'Student account', 11, false, 'sans', 310), 68, 385, $c['muted'], 11);
        self::text($image, 'PAYMENT DETAILS', 445, 329, $c['blueDark'], 10, true);
        self::text($image, 'Verified bank / wallet transfer', 445, 359, $c['navy'], 13, true);
        self::text($image, 'TRANSACTION REFERENCE', 445, 385, $c['muted'], 9, true);
        self::text($image, self::fit($image, (string) ($payment['transaction_reference'] ?? '—'), 11, false, 'sans', 310), 445, 407, $c['text'], 11);

        self::text($image, 'PAYMENT SUMMARY', $left, 474, $c['navy'], 15, true);
        self::roundRect($image, $left, 495, $right - $left, 38, 8, $c['navyBand']);
        self::text($image, 'DESCRIPTION', $left + 14, 520, $c['white'], 10, true);
        self::text($image, 'QTY.', 645, 520, $c['white'], 10, true, 'sans', 'center');
        self::text($image, 'AMOUNT', $right - 14, 520, $c['white'], 10, true, 'sans', 'right');
        self::text($image, self::fit($image, $course, 14, true, 'sans', 490), $left + 14, 563, $c['navy'], 14, true);
        self::text($image, 'Course enrollment · fully verified', $left + 14, 584, $c['muted'], 10);
        self::text($image, '1', 645, 558, $c['text'], 13, false, 'sans', 'center');
        self::text($image, $amountLabel, $right - 14, 558, $c['navy'], 14, true, 'sans', 'right');
        self::line($image, $left, 600, $right, 600, $c['border']);

        self::roundRect($image, 48, 624, 354, 174, 12, $c['pale'], $c['border']);
        self::text($image, 'PAYMENT STATUS', 68, 653, $c['blueDark'], 10, true);
        self::circle($image, 83, 691, 24, $c['ring']);
        self::check($image, 83, 691, $c['green']);
        self::text($image, 'PAID IN FULL', 105, 697, $c['navy'], 14, true);
        self::text($image, 'This receipt confirms payment received', 68, 735, $c['muted'], 11);
        self::text($image, 'for the course listed above.', 68, 754, $c['muted'], 11);
        self::text($image, 'Issued by ' . self::COMPANY, 68, 779, $c['muted'], 9);

        self::roundRect($image, 425, 624, 354, 174, 12, $c['white'], $c['border']);
        self::text($image, 'Subtotal', 447, 657, $c['muted'], 12);
        self::text($image, $amountLabel, 757, 657, $c['text'], 12, true, 'sans', 'right');
        self::line($image, 447, 674, 757, 674, $c['border']);
        self::roundRect($image, 445, 692, 314, 78, 12, $c['brand']);
        self::text($image, 'TOTAL PAID', 465, 722, $c['bandText'], 10, true);
        self::text($image, $amountLabel, 739, 749, $c['white'], 20, true, 'sans', 'right');

        self::text($image, 'Thank you for learning with ' . self::BRAND . '!', $center, 869, $c['navy'], 18, true, 'sans', 'center');
        self::text($image, 'We appreciate your trust in the Academy.', $center, 895, $c['muted'], 12, false, 'sans', 'center');
        self::line($image, 505, 978, 755, 978, $c['border']);
        self::text($image, 'AUTHORISED SIGNATURE', 755, 998, $c['muted'], 9, true, 'sans', 'right');

        self::roundRect($image, 48, 1040, 731, 78, 12, $c['navyBand']);
        self::text($image, self::BRAND . '  ·  ' . self::WEB, 68, 1073, $c['white'], 13, true);
        self::text($image, self::PHONE . '  ·  ' . self::EMAIL, 68, 1097, $c['bandText'], 10);
        self::text($image, 'Keep this receipt for your records.', 757, 1085, $c['bandText'], 10, false, 'sans', 'right');

        return self::jpeg($image);
    }

    public static function renderRevenue(array $report): string
    {
        $rates = is_array($report['rates'] ?? null) ? $report['rates'] : [];
        $width = 827;
        $height = 1169;
        $image = self::canvas($width, $height, true);
        $c = self::palette($image);

        self::rect($image, 0, 0, $width, $height, $c['page']);
        self::roundRect($image, 24, 24, $width - 48, $height - 48, 22, $c['white'], $c['border']);

        $left = 56;
        $right = $width - 56;
        $center = (int) ($width / 2);
        self::headerBand($image, 24, $width, $c, 'MONTHLY REVENUE STATEMENT');

        self::text($image, (string) ($report['period'] ?? ''), $left, 250, $c['navy'], 26, true, 'serif');
        self::text($image, 'Approved course payments · Developer share calculated at 15%', $left, 278, $c['muted'], 13, false);

        // Two summary tiles.
        $tileW = (int) (($right - $left - 16) / 2);
        self::summaryTile($image, $left, 306, $tileW, 92, 'ENROLLED STUDENTS', number_format((int) ($report['student_count'] ?? 0)), $c);
        self::summaryTile($image, $left + $tileW + 16, 306, $tileW, 92, 'PAYMENTS RECEIVED', number_format((int) ($report['payment_count'] ?? 0)), $c);

        // Currency table. Columns are laid out inside the card width so the last figure never
        // runs past the right border, and the header band lines up with the data columns.
        self::text($image, 'CURRENCY TOTALS', $left, 440, $c['navy'], 15, true);
        $cols = [
            ['x' => $left + 16, 'head' => 'Currency', 'key' => 'currency', 'align' => 'left', 'money' => false, 'bold' => true],
            ['x' => $left + 150, 'head' => 'This month', 'key' => 'monthly_revenue', 'align' => 'left', 'money' => true, 'bold' => false],
            ['x' => $left + 315, 'head' => 'Dev 15%', 'key' => 'developer_share', 'align' => 'left', 'money' => true, 'bold' => true],
            ['x' => $left + 465, 'head' => 'All time', 'key' => 'lifetime_revenue', 'align' => 'left', 'money' => true, 'bold' => false],
            ['x' => $right - 16, 'head' => 'Dev 15% (all)', 'key' => 'lifetime_developer_share', 'align' => 'right', 'money' => true, 'bold' => true],
        ];
        $headerY = 470;
        self::rect($image, $left, $headerY - 20, $right - $left, 34, $c['navyBand']);
        foreach ($cols as $col) self::text($image, strtoupper($col['head']), $col['x'], $headerY, $c['white'], 11, true, 'sans', $col['align']);
        $rowHeight = max(10, min(36, intdiv(510, max(1, count($rates)))));
        $rowFontSize = max(9, min(13, (int) floor($rowHeight * 0.36)));
        $y = $headerY + 40;
        foreach ($rates as $index => $rate) {
            if ($index % 2 === 0) self::rect($image, $left, $y - (int) floor($rowHeight * 0.55), $right - $left, $rowHeight, $c['pale']);
            foreach ($cols as $col) {
                if ($col['key'] === 'currency') {
                    self::text($image, strtoupper((string) ($rate['currency'] ?? '')), $col['x'], $y, $c['navy'], $rowFontSize, true);
                    continue;
                }
                $colour = $col['bold'] ? $c['green'] : $c['text'];
                self::text($image, number_format((float) ($rate[$col['key']] ?? 0), 2), $col['x'], $y, $colour, $rowFontSize, $col['bold'], 'sans', $col['align']);
            }
            $y += $rowHeight;
        }
        if ($rates === []) self::text($image, 'No approved payments recorded for this month.', $left, $y, $c['muted'], 14, false);

        self::footer($image, $left, $right, $height - 24, $c, 'Generated ' . (string) ($report['generated_at'] ?? gmdate('Y-m-d H:i:s') . ' UTC'));

        return self::jpeg($image);
    }

    /**
     * Build the branded period report as one or more A4 pages (300 DPI). Rows that no longer fit
     * on a page flow onto the next A4 page with a repeated table header, and every page carries a
     * footer band with a "Page x of y" marker. The returned JPEG pages are wrapped into a PDF by
     * PaymentReceiptPdf, so the download itself is always a PDF document.
     *
     * @return list<string> one JPEG byte string per A4 page
     */
    public static function renderReport(array $report): array
    {
        $rows = array_slice(is_array($report['rows'] ?? null) ? $report['rows'] : [], 0, 60);
        $metrics = array_slice(is_array($report['metrics'] ?? null) ? $report['metrics'] : [], 0, 4);
        $columns = array_values(is_array($report['columns'] ?? null) ? $report['columns'] : []);
        $title = self::safeText((string) ($report['title'] ?? 'Academy report'));
        $periodRange = (string) ($report['period_range'] ?? '');
        $generated = (string) ($report['generated_at'] ?? date('M j, Y'));

        $width = 827;
        $height = self::A4_HEIGHT / self::SCALE;
        $height = (int) round($height);
        $left = 56;
        $right = $width - 56;
        $center = (int) ($width / 2);

        // Shared horizontal table geometry.
        $cardLeft = 40;
        $cardRight = $width - 40;
        $padX = 24;
        $innerLeft = $cardLeft + $padX;
        $innerRight = $cardRight - $padX;
        $noW = 46;
        $colCount = max(1, count($columns));
        $colW = (int) ((($innerRight - $innerLeft) - $noW) / $colCount);

        // Shared vertical geometry.
        $rowH = 30;
        $headH = 40;
        $footBandH = 46;
        $footBandTop = $height - 24 - $footBandH;
        $bottomLimit = $footBandTop - 18;

        // Page 1 reserves room for the full header band plus an "at a glance" summary strip.
        $bandTop = 24;
        $bandH = 196;
        $cardTop = $bandTop + $bandH - 30;
        $sumTop = $cardTop + 50;
        $sumH = 92;
        $sumBottom = $sumTop + $sumH;
        $p1HeadTop = $sumBottom + 18;
        $p1FirstRow = $p1HeadTop + $headH;
        $p1Capacity = max(1, intdiv($bottomLimit - $p1FirstRow, $rowH));

        // Continuation pages use a slim band and start the table higher up.
        $contBandBottom = 104;
        $contCardTop = $contBandBottom - 24;
        $contHeadTop = $contCardTop + 52;
        $contFirstRow = $contHeadTop + $headH;
        $contCapacity = max(1, intdiv($bottomLimit - $contFirstRow, $rowH));

        // Slice the rows across A4 pages: page 1 takes p1Capacity, the rest take contCapacity each.
        $pages = [];
        if ($rows === []) {
            $pages[] = ['rows' => [], 'start' => 0, 'first' => true];
        } else {
            $offset = 0;
            $firstChunk = array_slice($rows, 0, $p1Capacity);
            $pages[] = ['rows' => $firstChunk, 'start' => $offset, 'first' => true];
            $offset += count($firstChunk);
            while ($offset < count($rows)) {
                $chunk = array_slice($rows, $offset, $contCapacity);
                $pages[] = ['rows' => $chunk, 'start' => $offset, 'first' => false];
                $offset += count($chunk);
            }
        }
        $total = count($pages);
        $grandTotal = count($rows);

        $images = [];
        foreach ($pages as $pageIndex => $page) {
            $pageRows = $page['rows'];
            $count = max(1, count($pageRows));
            $first = $page['first'];
            $headTop = $first ? $p1HeadTop : $contHeadTop;
            $firstRow = $first ? $p1FirstRow : $contFirstRow;
            $tableBottom = $firstRow + $count * $rowH;
            $thisCardTop = $first ? $cardTop : $contCardTop;
            $cardBottom = max($tableBottom, $first ? $sumBottom : $thisCardTop) + 16;

            $image = self::canvas($width, $height, true);
            $c = self::palette($image);
            self::rect($image, 0, 0, $width, $height, $c['page']);
            self::roundRect($image, 24, 24, $width - 48, $height - 48, 20, $c['white']);

            if ($first) {
                // Full brand header band with logo, contact, and prepared-for/period/generated row.
                self::roundRect($image, 24, $bandTop, $width - 48, $bandH, 18, $c['brand']);
                self::rect($image, 24, $bandTop + 20, 8, $bandH - 40, $c['blue']);
                $dot = 7;
                $dotGap = 5;
                $gridX = $left;
                $gridY = $bandTop + 30;
                for ($r = 0; $r < 3; $r++) {
                    for ($col = 0; $col < 3; $col++) {
                        self::rect($image, (int) ($gridX + $col * ($dot + $dotGap)), (int) ($gridY + $r * ($dot + $dotGap)), $dot, $dot, $c['bandText']);
                    }
                }
                $logoW = 3 * $dot + 2 * $dotGap;
                self::text($image, self::BRAND, (int) ($left + $logoW + 16), $bandTop + 48, $c['white'], 22, true);
                self::text($image, self::TAGLINE, (int) ($left + $logoW + 16), $bandTop + 70, $c['bandText'], 11);
                self::text($image, 'CONTACT', $right, $bandTop + 40, $c['white'], 11, true, 'sans', 'right');
                self::text($image, self::CITY, $right, $bandTop + 60, $c['bandText'], 10, false, 'sans', 'right');
                self::text($image, self::PHONE, $right, $bandTop + 78, $c['bandText'], 10, false, 'sans', 'right');
                self::text($image, self::EMAIL, $right, $bandTop + 96, $c['bandText'], 10, false, 'sans', 'right');
                self::line($image, $left, $bandTop + $bandH - 78, $right, $bandTop + $bandH - 78, $c['brandLine']);
                $metaLabelY = $bandTop + $bandH - 58;
                $metaValueY = $bandTop + $bandH - 38;
                self::text($image, 'PREPARED FOR', $left, $metaLabelY, $c['bandText'], 10, true, 'sans');
                self::text($image, 'Academy administration', $left, $metaValueY, $c['white'], 13, true, 'sans');
                self::text($image, 'REPORTING PERIOD', 300, $metaLabelY, $c['bandText'], 10, true, 'sans');
                self::text($image, self::fit($image, $periodRange, 12, true, 'sans', 240), 300, $metaValueY, $c['white'], 12, true, 'sans');
                self::text($image, 'GENERATED', $right, $metaLabelY, $c['bandText'], 10, true, 'sans', 'right');
                self::text($image, self::fit($image, $generated, 11, false, 'sans', 220), $right, $metaValueY, $c['white'], 11, false, 'sans', 'right');

                // White table card (overlaps the band) with title + "at a glance" summary strip.
                self::roundRect($image, $cardLeft, $cardTop, $cardRight - $cardLeft, $cardBottom - $cardTop, 14, $c['white'], $c['border']);
                self::text($image, self::fit($image, $title, 18, true, 'sans', ($innerRight - $innerLeft) - 130), $innerLeft, $cardTop + 30, $c['navy'], 18, true);
                self::text($image, number_format($grandTotal) . ' records', $innerRight, $cardTop + 30, $c['muted'], 11, false, 'sans', 'right');
                self::reportSummary($image, $innerLeft, $sumTop, $innerRight - $innerLeft, $sumH, $metrics, $c);
            } else {
                // Slim brand band for continuation pages, then the table card continues.
                self::roundRect($image, 24, 24, $width - 48, $contBandBottom - 24, 16, $c['brand']);
                self::rect($image, 24, 44, 8, $contBandBottom - 64, $c['blue']);
                self::text($image, self::BRAND, $left + 20, 68, $c['white'], 16, true);
                self::text($image, self::fit($image, $title, 11, false, 'sans', 360), $right, 68, $c['bandText'], 11, false, 'sans', 'right');
                $contLabel = 'Rows ' . number_format($page['start'] + 1) . '-' . number_format($page['start'] + count($pageRows)) . ' of ' . number_format($grandTotal);
                self::roundRect($image, $cardLeft, $contCardTop, $cardRight - $cardLeft, $cardBottom - $contCardTop, 14, $c['white'], $c['border']);
                self::text($image, $title . ' (continued)', $innerLeft, $contCardTop + 32, $c['navy'], 14, true);
                self::text($image, $contLabel, $innerRight, $contCardTop + 32, $c['muted'], 11, false, 'sans', 'right');
            }

            // Table header row (repeated on every page).
            self::roundRect($image, $innerLeft, $headTop, $innerRight - $innerLeft, $headH, 8, $c['brand']);
            $headBase = $headTop + (int) ($headH / 2) + 5;
            self::text($image, 'NO.', $innerLeft + 14, $headBase, $c['white'], 10, true);
            foreach ($columns as $ci => $column) {
                $cx = $innerLeft + $noW + (int) ($ci * $colW);
                self::text($image, self::fit($image, strtoupper((string) $column), 10, true, 'sans', $colW - 20), $cx + 10, $headBase, $c['white'], 10, true, 'sans');
            }

            // Data rows: fixed height, alternating tint, a hairline separator, and padded cells.
            if ($pageRows === []) {
                $rowTop = $firstRow;
                if ($rowTop % 2 === 0) self::rect($image, $innerLeft, $rowTop, $innerRight - $innerLeft, $rowH, $c['pale']);
                self::text($image, 'No records were found for this period.', $innerLeft + 14, $rowTop + 20, $c['muted'], 12);
            }
            foreach ($pageRows as $ri => $row) {
                $abs = $page['start'] + $ri;
                $rowTop = $firstRow + (int) ($ri * $rowH);
                $base = $rowTop + 20;
                if ($abs % 2 === 0) self::rect($image, $innerLeft, $rowTop, $innerRight - $innerLeft, $rowH, $c['pale']);
                if ($abs > 0) self::line($image, $innerLeft, $rowTop, $innerRight, $rowTop, $c['border']);
                self::text($image, (string) ($abs + 1), $innerLeft + 14, $base, $c['muted'], 11, false);
                foreach (array_slice(is_array($row) ? $row : [], 0, $colCount) as $ci => $value) {
                    $cx = $innerLeft + $noW + (int) ($ci * $colW);
                    self::text($image, self::fit($image, (string) $value, 11, false, 'sans', $colW - 20), $cx + 10, $base, $c['text'], 11);
                }
            }

            // Authorised signature (only on the final page, just under the table).
            if ($pageIndex === $total - 1) {
                $sigY = $tableBottom + 6;
                self::line($image, $right - 220, $sigY + 14, $right, $sigY + 14, $c['border']);
                self::text($image, 'Authorised signature', $right, $sigY + 30, $c['muted'], 10, false, 'sans', 'right');
            }

            // Footer band on every page with the brand line and page marker.
            self::roundRect($image, $cardLeft, $footBandTop, $cardRight - $cardLeft, $footBandH, 12, $c['brand']);
            self::text($image, 'Thank you — ' . self::BRAND . ' · Internal administration report · ' . self::WEB, $innerLeft + 8, $footBandTop + (int) ($footBandH / 2) + 4, $c['white'], 12, false, 'sans');
            self::text($image, 'Page ' . ($pageIndex + 1) . ' of ' . $total, $innerRight - 8, $footBandTop + (int) ($footBandH / 2) + 4, $c['bandText'], 12, true, 'sans', 'right');

            $images[] = self::jpeg($image);
        }

        return $images;
    }

    /** Draws the "at a glance" summary strip (headline metric tiles) shown at the top of page 1. */
    private static function reportSummary(\GdImage $image, int $x, int $y, int $w, int $h, array $metrics, array $c): void
    {
        self::roundRect($image, $x, $y, $w, $h, 12, $c['pale'], $c['border']);
        if ($metrics === []) {
            self::text($image, 'No headline figures for this period.', $x + 20, $y + (int) ($h / 2) + 4, $c['muted'], 12);
            return;
        }
        $cellW = (int) ($w / max(1, count($metrics)));
        foreach ($metrics as $index => $metric) {
            $cx = $x + (int) ($index * $cellW);
            if ($index > 0) self::line($image, $cx, $y + 16, $cx, $y + $h - 16, $c['border']);
            self::text($image, self::fit($image, strtoupper((string) ($metric['label'] ?? '')), 9, true, 'sans', $cellW - 34), $cx + 20, $y + 34, $c['muted'], 9, true);
            self::text($image, self::fit($image, (string) ($metric['value'] ?? '—'), 18, true, 'sans', $cellW - 34), $cx + 20, $y + 66, $c['navy'], 18, true);
        }
    }

    /* ------------------------------------------------------------------ shared blocks */

    private static function headerBand(\GdImage $image, int $top, int $width, array $c, string $kicker): void
    {
        $bandH = 168;
        self::rect($image, 24, $top, $width - 48, $bandH, $c['navyBand']);
        self::rect($image, 24, $top, 10, $bandH, $c['blue']);
        $center = (int) ($width / 2);
        // Crest.
        self::circle($image, $center, $top + 52, 52, $c['blue']);
        self::text($image, 'T', $center, $top + 62, $c['white'], 24, true, 'serif', 'center');
        self::text($image, self::BRAND, $center, $top + 118, $c['white'], 26, true, 'serif', 'center');
        self::text($image, $kicker, $center, $top + 146, $c['bandText'], 12, true, 'sans', 'center');
        self::text($image, self::TAGLINE . '   ·   ' . self::WEB, $center, $top + 164, $c['bandText'], 10, false, 'sans', 'center');
    }

    private static function footer(\GdImage $image, int $left, int $right, int $bottom, array $c, string $note): void
    {
        $lineY = $bottom - 96;
        self::line($image, $left, $lineY, $right, $lineY, $c['border'], 1);
        self::text($image, self::COMPANY, $left, $lineY + 26, $c['navy'], 13, true);
        self::text($image, self::CITY, $left, $lineY + 46, $c['muted'], 11, false);
        self::text($image, self::PHONE . '  ·  ' . self::EMAIL . '  ·  ' . self::WEB, $left, $lineY + 66, $c['blue'], 12, true);
        self::text($image, $note, $right, $lineY + 26, $c['muted'], 11, false, 'sans', 'right');
        self::text($image, 'Authorised signature', $right, $lineY + 66, $c['muted'], 10, false, 'sans', 'right');
        self::line($image, $right - 200, $lineY + 52, $right, $lineY + 52, $c['border'], 1);
    }

    private static function summaryTile(\GdImage $image, int $x, int $y, int $w, int $h, string $label, string $value, array $c): void
    {
        self::roundRect($image, $x, $y, $w, $h, 14, $c['pale'], $c['border']);
        self::text($image, $label, $x + 18, $y + 30, $c['muted'], 11, true);
        // Long figures (revenue strings, names) shrink to fit rather than being cut off, so the
        // whole value stays readable inside the tile.
        $available = $w - 36;
        $size = 22;
        foreach ([22, 20, 18, 16, 14, 13] as $try) {
            if (self::textWidth($value, $try, true) <= $available) { $size = $try; break; }
            $size = $try;
        }
        $value = self::fit($image, $value, $size, true, 'sans', $available);
        self::text($image, $value, $x + 18, $y + 78, $c['blueDark'], $size, true);
    }

    private static function metaRow(\GdImage $image, int $left, int $y, string $label, string $value, array $c): void
    {
        self::text($image, $label, $left, $y, $c['navy'], 14, true);
        $offset = self::textWidth($label, 14, true) + 10;
        self::text($image, $value, $left + $offset, $y, $c['text'], 14, false);
    }

    private static function check(\GdImage $image, int $cx, int $cy, int $color): void
    {
        $s = self::SCALE;
        imagesetthickness($image, 7 * $s);
        imageline($image, (int) (($cx - 17) * $s), (int) (($cy + 1) * $s), (int) (($cx - 4) * $s), (int) (($cy + 14) * $s), $color);
        imageline($image, (int) (($cx - 4) * $s), (int) (($cy + 14) * $s), (int) (($cx + 18) * $s), (int) (($cy - 13) * $s), $color);
    }

    /* ------------------------------------------------------------------ primitives */

    private static function canvas(int $logicalWidth, int $logicalHeight, bool $a4 = false): \GdImage
    {
        if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            throw new \RuntimeException('The GD extension is required to generate JPG documents.');
        }
        $image = imagecreatetruecolor(
            $a4 ? self::A4_WIDTH : $logicalWidth * self::SCALE,
            $a4 ? self::A4_HEIGHT : $logicalHeight * self::SCALE
        );
        imageresolution($image, 300, 300);
        imageantialias($image, true);
        imagealphablending($image, true);
        imagefill($image, 0, 0, self::color($image, 255, 255, 255));
        return $image;
    }

    private static function palette(\GdImage $image): array
    {
        return [
            'white' => self::color($image, 255, 255, 255),
            'page' => self::color($image, 238, 243, 251),
            'shadow' => self::color($image, 214, 224, 238),
            'navy' => self::color($image, 15, 23, 42),
            'navyBand' => self::color($image, 30, 41, 66),
            'brand' => self::color($image, 11, 27, 58),
            'brandLine' => self::color($image, 46, 64, 96),
            'bandText' => self::color($image, 203, 220, 252),
            'blue' => self::color($image, 37, 99, 235),
            'blueDark' => self::color($image, 29, 78, 216),
            'text' => self::color($image, 51, 65, 85),
            'muted' => self::color($image, 100, 116, 139),
            'border' => self::color($image, 226, 232, 240),
            'dash' => self::color($image, 203, 213, 225),
            'pale' => self::color($image, 245, 248, 255),
            'green' => self::color($image, 74, 222, 128),
            'ring' => self::color($image, 220, 252, 231),
        ];
    }

    private static function color(\GdImage $image, int $red, int $green, int $blue): int
    {
        return imagecolorallocate($image, $red, $green, $blue);
    }

    private static function rect(\GdImage $image, int $x, int $y, int $w, int $h, int $color): void
    {
        $s = self::SCALE;
        imagefilledrectangle($image, $x * $s, $y * $s, ($x + $w) * $s, ($y + $h) * $s, $color);
    }

    private static function line(\GdImage $image, int $x1, int $y1, int $x2, int $y2, int $color, int $thickness = 1): void
    {
        $s = self::SCALE;
        imagesetthickness($image, max(1, $thickness * $s));
        imageline($image, $x1 * $s, $y1 * $s, $x2 * $s, $y2 * $s, $color);
    }

    private static function dashedLine(\GdImage $image, int $x1, int $x2, int $y, int $color, int $dash = 8, int $gap = 6): void
    {
        $s = self::SCALE;
        imagesetthickness($image, 2 * $s);
        $x = $x1 * $s;
        $end = $x2 * $s;
        $yy = $y * $s;
        $d = $dash * $s;
        $g = $gap * $s;
        while ($x < $end) {
            $seg = min($x + $d, $end);
            imageline($image, (int) $x, (int) $yy, (int) $seg, (int) $yy, $color);
            $x += $d + $g;
        }
    }

    private static function circle(\GdImage $image, int $cx, int $cy, int $diameter, int $color): void
    {
        $s = self::SCALE;
        imagefilledellipse($image, $cx * $s, $cy * $s, $diameter * $s, $diameter * $s, $color);
    }

    private static function roundRect(\GdImage $image, int $x, int $y, int $w, int $h, int $r, ?int $fill, ?int $border = null): void
    {
        $s = self::SCALE;
        $x *= $s;
        $y *= $s;
        $w *= $s;
        $h *= $s;
        $r *= $s;
        if ($fill !== null) {
            imagefilledrectangle($image, $x + $r, $y, $x + $w - $r, $y + $h, $fill);
            imagefilledrectangle($image, $x, $y + $r, $x + $r, $y + $h - $r, $fill);
            imagefilledrectangle($image, $x + $w - $r, $y + $r, $x + $w, $y + $h - $r, $fill);
            imagefilledarc($image, $x + $r, $y + $r, $r * 2, $r * 2, 180, 270, $fill, IMG_ARC_PIE);
            imagefilledarc($image, $x + $w - $r, $y + $r, $r * 2, $r * 2, 270, 360, $fill, IMG_ARC_PIE);
            imagefilledarc($image, $x + $r, $y + $h - $r, $r * 2, $r * 2, 90, 180, $fill, IMG_ARC_PIE);
            imagefilledarc($image, $x + $w - $r, $y + $h - $r, $r * 2, $r * 2, 0, 90, $fill, IMG_ARC_PIE);
        }
        if ($border !== null) {
            imagesetthickness($image, max(1, $s));
            imageline($image, $x + $r, $y, $x + $w - $r, $y, $border);
            imageline($image, $x + $r, $y + $h, $x + $w - $r, $y + $h, $border);
            imageline($image, $x, $y + $r, $x, $y + $h - $r, $border);
            imageline($image, $x + $w, $y + $r, $x + $w, $y + $h - $r, $border);
            imagearc($image, $x + $r, $y + $r, $r * 2, $r * 2, 180, 270, $border);
            imagearc($image, $x + $w - $r, $y + $r, $r * 2, $r * 2, 270, 360, $border);
            imagearc($image, $x + $r, $y + $h - $r, $r * 2, $r * 2, 90, 180, $border);
            imagearc($image, $x + $w - $r, $y + $h - $r, $r * 2, $r * 2, 0, 90, $border);
        }
    }

    /* ------------------------------------------------------------------ text */

    private static function text(\GdImage $image, string $value, int $x, int $y, int $color, int $size = 16, bool $bold = false, string $family = 'sans', string $align = 'left'): void
    {
        $font = self::font($family, $bold);
        $value = self::safeText($value);
        if ($value === '') return;
        $px = $size * self::SCALE;
        if ($font === null) {
            imagestring($image, 5, (int) ($x * self::SCALE), (int) (($y - 12) * self::SCALE), $value, $color);
            return;
        }
        $box = imagettfbbox($px, 0, $font, $value);
        $dx = $x * self::SCALE;
        if (is_array($box)) {
            $w = $box[2] - $box[0];
            if ($align === 'center') $dx -= $w / 2;
            elseif ($align === 'right') $dx -= $w;
        }
        imagettftext($image, $px, 0, (int) round($dx), (int) round($y * self::SCALE), $color, $font, $value);
    }

    private static function textWidth(string $value, int $size, bool $bold, string $family = 'sans'): int
    {
        $font = self::font($family, $bold);
        $value = self::safeText($value);
        if ($value === '') return 0;
        $px = $size * self::SCALE;
        if ($font === null) return (int) round(strlen($value) * 6 * self::SCALE / self::SCALE);
        $box = imagettfbbox($px, 0, $font, $value);
        return is_array($box) ? (int) ceil(($box[2] - $box[0]) / self::SCALE) : 0;
    }

    private static function fit(\GdImage $image, string $value, int $size, bool $bold, string $family, int $maxWidth): string
    {
        $value = self::safeText($value);
        if ($value === '' || self::textWidth($value, $size, $bold, $family) <= $maxWidth) return $value;
        while ($value !== '' && self::textWidth($value . '…', $size, $bold, $family) > $maxWidth) {
            $value = mb_substr($value, 0, mb_strlen($value) - 1);
        }
        return $value . '…';
    }

    private static function font(string $family, bool $bold): ?string
    {
        $key = $family . '|' . ($bold ? 'b' : 'r');
        if (array_key_exists($key, self::$fontCache)) return self::$fontCache[$key];
        $candidates = match ($family) {
            'serif' => $bold
                ? ['/System/Library/Fonts/Supplemental/Georgia Bold.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSerif-Bold.ttf']
                : ['/System/Library/Fonts/Supplemental/Georgia.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSerif.ttf'],
            default => $bold
                ? ['/System/Library/Fonts/Supplemental/Arial Bold.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf']
                : ['/System/Library/Fonts/Supplemental/Arial.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'],
        };
        foreach ($candidates as $path) {
            if (is_file($path)) return self::$fontCache[$key] = $path;
        }
        return self::$fontCache[$key] = null;
    }

    private static function safeText(string $value): string
    {
        $value = trim(preg_replace('/[\r\n\x00-\x1F]+/u', ' ', $value) ?? '');
        return function_exists('mb_convert_encoding') ? mb_convert_encoding($value, 'UTF-8', 'UTF-8') : $value;
    }

    private static function issuedLabel(mixed $value): string
    {
        if (!is_string($value) || $value === '') return gmdate('M j, Y');
        $timestamp = strtotime($value . ' UTC');
        return $timestamp === false ? '—' : date('g:i A · M j, Y', $timestamp);
    }

    private static function jpeg(\GdImage $image): string
    {
        ob_start();
        imagejpeg($image, null, self::QUALITY);
        $jpg = (string) ob_get_clean();
        imagedestroy($image);
        return $jpg;
    }
}
