<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The three branded documents, drawn as native vector PDFs through PdfCanvas.
 *
 *  - receipt()  the per-payment receipt a student (or admin) downloads. There is no on-screen
 *               receipt page and no raster step: this method returns the finished PDF bytes.
 *  - revenue()  the monthly revenue statement exported from the admin payment desk.
 *  - report()   the branded period report exported from the admin Reports desk (multi-page).
 *
 * Layout numbers are carried over unchanged from the previous GD renderer (same 827 x 1169
 * top-left space), so the visual design stays equivalent while the output is now crisp,
 * selectable-text vector PDF built with core PHP only.
 */
final class DocumentPdf
{
    private const BRAND = 'Tech SG Academy';
    private const COMPANY = 'Tech SG Studio (SMC-Pvt.) Ltd.';
    private const TAGLINE = 'We Study. We Build. We Deliver.';
    private const PHONE = '+92 306 9524047';
    private const EMAIL = 'info@techsgstudio.com';
    private const WEB = 'techsgstudio.com';
    private const CITY = 'Faisalabad, Punjab, Pakistan';

    /** @return array<string,array<int,int>> named RGB triples */
    private static function palette(): array
    {
        return [
            'white' => [255, 255, 255],
            'page' => [238, 243, 251],
            'shadow' => [214, 224, 238],
            'navy' => [15, 23, 42],
            'navyBand' => [30, 41, 66],
            'brand' => [0, 33, 71],
            'brandLine' => [39, 76, 115],
            'bandText' => [203, 220, 252],
            'blue' => [0, 33, 71],
            'blueDark' => [0, 33, 71],
            'text' => [51, 65, 85],
            'muted' => [100, 116, 139],
            'border' => [226, 232, 240],
            'dash' => [203, 213, 225],
            'pale' => [245, 248, 255],
            'green' => [74, 222, 128],
            'ring' => [220, 252, 231],
            'paid' => [22, 163, 74],
        ];
    }

    /* ------------------------------------------------------------------ documents */

    public static function receipt(array $payment): string
    {
        $pdf = new PdfCanvas();
        $pdf->newPage();
        $c = self::palette();

        $width = 827;
        $center = (int) ($width / 2);
        $cardL = 70;
        $cardR = 757;
        $cardT = 180;
        $cardB = 958;
        $innerL = 112;
        $innerR = 715;

        $currency = strtoupper(trim((string) ($payment['currency'] ?? ''))) ?: 'PKR';
        $amountLabel = $currency . ' ' . number_format((float) ($payment['amount'] ?? 0), 2);
        $receiptNumber = (string) ($payment['receipt_number'] ?? '—');
        $course = (string) ($payment['course_title'] ?? 'Course enrollment');
        $studentName = (string) ($payment['student_name'] ?? 'Student');
        $email = (string) ($payment['student_email'] ?? '');
        $reference = (string) ($payment['transaction_reference'] ?? '—');

        // Page background and the centred white receipt card.
        $pdf->rect(0, 0, $width, 1169, $c['page']);
        $pdf->roundRect($cardL, $cardT, $cardR - $cardL, $cardB - $cardT, 24, $c['white'], $c['border']);

        // Header: left-aligned status with a green Paid badge on the right.
        $pdf->text('Payment Successful!', $innerL, 244, $c['navy'], 24, true);
        $pdf->text(self::BRAND . ' · Course enrollment', $innerL, 272, $c['muted'], 13);
        $pdf->roundRect($innerR - 74, 214, 74, 34, 17, $c['paid']);
        $pdf->text('Paid', $innerR - 37, 237, $c['white'], 14, true, 'sans', 'center');

        self::ticketDivider($pdf, $cardL, $cardR, 320, $c);

        // Receipt meta block: bold navy label followed by its value.
        $meta = [
            ['Receipt No:', $pdf->fit($receiptNumber, 13, true, 'sans', 360)],
            ['Issued:', self::issuedLabel($payment['receipt_issued_at'] ?? null)],
            ['Payment Method:', 'Verified bank / wallet transfer'],
        ];
        $metaY = 362;
        foreach ($meta as $line) {
            $pdf->text($line[0], $innerL, $metaY, $c['navy'], 13, true);
            $offset = $pdf->textWidth($line[0], 13, true) + 8;
            $pdf->text($line[1], $innerL + $offset, $metaY, $c['navy'], 13, true);
            $metaY += 30;
        }

        self::ticketDivider($pdf, $cardL, $cardR, 458, $c);

        // Line items.
        $pdf->text('Items', $innerL, 496, $c['navy'], 14, true);
        $pdf->text('Amount', $innerR, 496, $c['navy'], 14, true, 'sans', 'right');
        $pdf->text($pdf->fit('1x ' . $course, 13, false, 'sans', 470), $innerL, 528, $c['text'], 13);
        $pdf->text($amountLabel, $innerR, 528, $c['text'], 13, false, 'sans', 'right');
        $pdf->text('Course enrollment payment · fully verified by the Academy', $innerL, 556, $c['muted'], 11);

        self::ticketDivider($pdf, $cardL, $cardR, 592, $c);

        // Totals panel.
        $pdf->roundRect($innerL, 614, $innerR - $innerL, 96, 14, $c['pale'], $c['border']);
        $pdf->text('Subtotal', $innerL + 22, 646, $c['muted'], 12);
        $pdf->text($amountLabel, $innerR - 22, 646, $c['navy'], 12, true, 'sans', 'right');
        $pdf->text('Total Paid', $innerL + 22, 690, $c['navy'], 20, true);
        $pdf->text($amountLabel, $innerR - 22, 690, $c['navy'], 20, true, 'sans', 'right');

        self::ticketDivider($pdf, $cardL, $cardR, 734, $c);

        // Billed-to and transaction reference columns.
        $pdf->text('BILLED TO', $innerL, 766, $c['muted'], 9, true);
        $pdf->text($pdf->fit($studentName, 14, true, 'sans', 300), $innerL, 792, $c['navy'], 14, true);
        if ($email !== '') {
            $pdf->text($pdf->fit($email, 11, false, 'sans', 300), $innerL, 814, $c['muted'], 11);
        }
        $pdf->text('TRANSACTION REFERENCE', 470, 766, $c['muted'], 9, true);
        $pdf->text($pdf->fit($reference, 13, false, 'sans', 230), 470, 792, $c['navy'], 13);

        // Closing note.
        $pdf->text('Thanks for learning with ' . self::BRAND . '!', $center, 866, $c['navy'], 16, true, 'sans', 'center');
        $pdf->text('Issued by ' . self::COMPANY . ' · ' . self::EMAIL, $center, 898, $c['muted'], 10, false, 'sans', 'center');
        $pdf->text('This receipt confirms payment received for the course listed above.', $center, 918, $c['muted'], 10, false, 'sans', 'center');

        return $pdf->output();
    }

    public static function revenue(array $report): string
    {
        // Monthly revenue statement drawn in the same receipt card language as the student receipt
        // (white card, green status badge, perforated dividers, a meta block, an items table and a
        // pale totals panel), but carrying the admin's own revenue figures.
        $pdf = new PdfCanvas();
        $pdf->newPage();
        $c = self::palette();

        $rates = array_slice(is_array($report['rates'] ?? null) ? $report['rates'] : [], 0, 4);
        $width = 827;
        $center = (int) ($width / 2);
        $cardL = 70;
        $cardR = 757;
        $cardT = 180;
        $innerL = 112;
        $innerR = 715;

        $period = (string) ($report['period'] ?? '');
        $generated = (string) ($report['generated_at'] ?? gmdate('Y-m-d H:i:s') . ' UTC');
        $badge = 'APPROVED';

        // Vertical plan, computed up front so the card is sized before its content is drawn.
        $divider1 = 320;
        $metaStart = 362;
        $metaStep = 30;
        $metaEnd = $metaStart + 3 * $metaStep;
        $divider2 = $metaEnd + 28;
        $itemsHeader = $divider2 + 38;
        $itemsRowStart = $itemsHeader + 32;
        $itemsRowStep = 46;
        $itemsEnd = $rates === [] ? $itemsRowStart + 10 : $itemsRowStart + count($rates) * $itemsRowStep - 18;
        $divider3 = $itemsEnd + 24;
        $panelTop = $divider3 + 20;
        $panelRowStep = 30;
        $panelH = $rates === [] ? 60 : 58 + count($rates) * $panelRowStep;
        $panelBottom = $panelTop + $panelH;
        $divider4 = $panelBottom + 24;
        $thanks = $divider4 + 46;
        $issued = $thanks + 30;
        $confirms = $issued + 20;
        $cardB = $confirms + 40;

        $pdf->rect(0, 0, $width, 1169, $c['page']);
        $pdf->roundRect($cardL, $cardT, $cardR - $cardL, $cardB - $cardT, 24, $c['white'], $c['border']);

        // Header: left-aligned title with a green status badge on the right.
        $pdf->text('Monthly Revenue Statement', $innerL, 244, $c['navy'], 24, true);
        $pdf->text(self::BRAND . ' · ' . ($period !== '' ? $period : 'Course payments'), $innerL, 272, $c['muted'], 13);
        $badgeWidth = $pdf->textWidth($badge, 12, true) + 30;
        $badgeX = $innerR - $badgeWidth;
        $pdf->roundRect($badgeX, 214, $badgeWidth, 34, 17, $c['paid']);
        $pdf->text($badge, $badgeX + $badgeWidth / 2, 237, $c['white'], 12, true, 'sans', 'center');

        self::ticketDivider($pdf, $cardL, $cardR, $divider1, $c);

        // Meta block: bold navy label followed by its value.
        $meta = [
            ['Period:', $pdf->fit($period !== '' ? $period : '—', 13, true, 'sans', 360)],
            ['Enrolled Students:', number_format((int) ($report['student_count'] ?? 0))],
            ['Payments Received:', number_format((int) ($report['payment_count'] ?? 0))],
            ['Generated:', $pdf->fit($generated, 13, true, 'sans', 320)],
        ];
        $metaY = $metaStart;
        foreach ($meta as $line) {
            $pdf->text($line[0], $innerL, $metaY, $c['navy'], 13, true);
            $offset = $pdf->textWidth($line[0], 13, true) + 8;
            $pdf->text($line[1], $innerL + $offset, $metaY, $c['navy'], 13, true);
            $metaY += $metaStep;
        }

        self::ticketDivider($pdf, $cardL, $cardR, $divider2, $c);

        // Revenue by currency, styled like the receipt's items table (a row plus a muted note).
        $pdf->text('Revenue by currency', $innerL, $itemsHeader, $c['navy'], 14, true);
        $pdf->text('This month', $innerR, $itemsHeader, $c['navy'], 14, true, 'sans', 'right');
        if ($rates === []) {
            $pdf->text('No approved payments recorded for this month.', $innerL, $itemsRowStart, $c['muted'], 13);
        }
        foreach ($rates as $index => $rate) {
            $currency = strtoupper((string) ($rate['currency'] ?? 'PKR'));
            $rowY = $itemsRowStart + $index * $itemsRowStep;
            $pdf->text($currency . ' approved revenue', $innerL, $rowY, $c['text'], 13);
            $pdf->text(number_format((float) ($rate['monthly_revenue'] ?? 0), 2), $innerR, $rowY, $c['text'], 13, false, 'sans', 'right');
            $note = 'Dev 15%: ' . number_format((float) ($rate['developer_share'] ?? 0), 2)
                . ' · All time: ' . number_format((float) ($rate['lifetime_revenue'] ?? 0), 2)
                . ' · Dev 15% (all): ' . number_format((float) ($rate['lifetime_developer_share'] ?? 0), 2);
            $pdf->text($pdf->fit($note, 11, false, 'sans', $innerR - $innerL), $innerL, $rowY + 22, $c['muted'], 11);
        }

        self::ticketDivider($pdf, $cardL, $cardR, $divider3, $c);

        // Pale totals panel: the developer share the statement owes, one line per currency.
        $pdf->roundRect($innerL, $panelTop, $innerR - $innerL, $panelH, 14, $c['pale'], $c['border']);
        $pdf->text('DEVELOPER SHARE (15%)', $innerL + 22, $panelTop + 30, $c['muted'], 9, true);
        if ($rates === []) {
            $pdf->text('Nothing owed for this month.', $innerL + 22, $panelTop + 52, $c['navy'], 13, true);
        }
        foreach ($rates as $index => $rate) {
            $currency = strtoupper((string) ($rate['currency'] ?? 'PKR'));
            $rowY = $panelTop + 58 + $index * $panelRowStep;
            $pdf->text($currency . ' · this month', $innerL + 22, $rowY, $c['navy'], 13, true);
            $pdf->text(number_format((float) ($rate['developer_share'] ?? 0), 2), $innerR - 22, $rowY, $c['navy'], 13, true, 'sans', 'right');
        }

        self::ticketDivider($pdf, $cardL, $cardR, $divider4, $c);

        // Closing note.
        $pdf->text('Thank you — ' . self::BRAND, $center, $thanks, $c['navy'], 16, true, 'sans', 'center');
        $pdf->text('Issued by ' . self::COMPANY . ' · ' . self::EMAIL, $center, $issued, $c['muted'], 10, false, 'sans', 'center');
        $pdf->text('Approved course payments only · Developer share calculated at 15%.', $center, $confirms, $c['muted'], 10, false, 'sans', 'center');

        return $pdf->output();
    }

    /**
     * Branded period report as one or more A4 pages. Rows that no longer fit flow onto the next
     * page with a repeated table header, and every page carries a footer band with a "Page x of y"
     * marker. All pages live in a single PdfCanvas, so output() returns one multi-page PDF.
     */
    public static function report(array $report): string
    {
        $rows = array_slice(is_array($report['rows'] ?? null) ? $report['rows'] : [], 0, 60);
        $metrics = array_slice(is_array($report['metrics'] ?? null) ? $report['metrics'] : [], 0, 4);
        $columns = array_values(is_array($report['columns'] ?? null) ? $report['columns'] : []);
        $title = self::safeText((string) ($report['title'] ?? 'Academy report'));
        $periodRange = (string) ($report['period_range'] ?? '');
        $generated = (string) ($report['generated_at'] ?? date('M j, Y'));

        $width = 827;
        $height = 1169;
        $left = 56;
        $right = $width - 56;

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

        // Slice the rows across pages: page 1 takes p1Capacity, the rest take contCapacity each.
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

        $pdf = new PdfCanvas();
        $c = self::palette();

        foreach ($pages as $pageIndex => $page) {
            $pageRows = $page['rows'];
            $count = max(1, count($pageRows));
            $first = $page['first'];
            $headTop = $first ? $p1HeadTop : $contHeadTop;
            $firstRow = $first ? $p1FirstRow : $contFirstRow;
            $tableBottom = $firstRow + $count * $rowH;
            $thisCardTop = $first ? $cardTop : $contCardTop;
            $cardBottom = max($tableBottom, $first ? $sumBottom : $thisCardTop) + 16;

            $pdf->newPage();
            $pdf->rect(0, 0, $width, $height, $c['page']);
            $pdf->roundRect(24, 24, $width - 48, $height - 48, 20, $c['white']);

            if ($first) {
                // Full brand header band with logo, contact, and prepared-for/period/generated row.
                $pdf->roundRect(24, $bandTop, $width - 48, $bandH, 18, $c['brand']);
                $pdf->rect(24, $bandTop + 20, 8, $bandH - 40, $c['blue']);
                $dot = 7;
                $dotGap = 5;
                $gridX = $left;
                $gridY = $bandTop + 30;
                for ($r = 0; $r < 3; $r++) {
                    for ($col = 0; $col < 3; $col++) {
                        $pdf->rect((int) ($gridX + $col * ($dot + $dotGap)), (int) ($gridY + $r * ($dot + $dotGap)), $dot, $dot, $c['bandText']);
                    }
                }
                $logoW = 3 * $dot + 2 * $dotGap;
                $pdf->text(self::BRAND, (int) ($left + $logoW + 16), $bandTop + 48, $c['white'], 22, true);
                $pdf->text(self::TAGLINE, (int) ($left + $logoW + 16), $bandTop + 70, $c['bandText'], 11);
                $pdf->text('CONTACT', $right, $bandTop + 40, $c['white'], 11, true, 'sans', 'right');
                $pdf->text(self::CITY, $right, $bandTop + 60, $c['bandText'], 10, false, 'sans', 'right');
                $pdf->text(self::PHONE, $right, $bandTop + 78, $c['bandText'], 10, false, 'sans', 'right');
                $pdf->text(self::EMAIL, $right, $bandTop + 96, $c['bandText'], 10, false, 'sans', 'right');
                $pdf->line($left, $bandTop + $bandH - 78, $right, $bandTop + $bandH - 78, $c['brandLine']);
                $metaLabelY = $bandTop + $bandH - 58;
                $metaValueY = $bandTop + $bandH - 38;
                $pdf->text('PREPARED FOR', $left, $metaLabelY, $c['bandText'], 10, true);
                $pdf->text('Academy administration', $left, $metaValueY, $c['white'], 13, true);
                $pdf->text('REPORTING PERIOD', 300, $metaLabelY, $c['bandText'], 10, true);
                $pdf->text($pdf->fit($periodRange, 12, true, 'sans', 240), 300, $metaValueY, $c['white'], 12, true);
                $pdf->text('GENERATED', $right, $metaLabelY, $c['bandText'], 10, true, 'sans', 'right');
                $pdf->text($pdf->fit($generated, 11, false, 'sans', 220), $right, $metaValueY, $c['white'], 11, false, 'sans', 'right');

                // White table card (overlaps the band) with title + "at a glance" summary strip.
                $pdf->roundRect($cardLeft, $cardTop, $cardRight - $cardLeft, $cardBottom - $cardTop, 14, $c['white'], $c['border']);
                $pdf->text($pdf->fit($title, 18, true, 'sans', ($innerRight - $innerLeft) - 130), $innerLeft, $cardTop + 30, $c['navy'], 18, true);
                $pdf->text(number_format($grandTotal) . ' records', $innerRight, $cardTop + 30, $c['muted'], 11, false, 'sans', 'right');
                self::reportSummary($pdf, $innerLeft, $sumTop, $innerRight - $innerLeft, $sumH, $metrics, $c);
            } else {
                // Slim brand band for continuation pages, then the table card continues.
                $pdf->roundRect(24, 24, $width - 48, $contBandBottom - 24, 16, $c['brand']);
                $pdf->rect(24, 44, 8, $contBandBottom - 64, $c['blue']);
                $pdf->text(self::BRAND, $left + 20, 68, $c['white'], 16, true);
                $pdf->text($pdf->fit($title, 11, false, 'sans', 360), $right, 68, $c['bandText'], 11, false, 'sans', 'right');
                $contLabel = 'Rows ' . number_format($page['start'] + 1) . '-' . number_format($page['start'] + count($pageRows)) . ' of ' . number_format($grandTotal);
                $pdf->roundRect($cardLeft, $contCardTop, $cardRight - $cardLeft, $cardBottom - $contCardTop, 14, $c['white'], $c['border']);
                $pdf->text($title . ' (continued)', $innerLeft, $contCardTop + 32, $c['navy'], 14, true);
                $pdf->text($contLabel, $innerRight, $contCardTop + 32, $c['muted'], 11, false, 'sans', 'right');
            }

            // Table header row (repeated on every page).
            $pdf->roundRect($innerLeft, $headTop, $innerRight - $innerLeft, $headH, 8, $c['brand']);
            $headBase = $headTop + (int) ($headH / 2) + 5;
            $pdf->text('NO.', $innerLeft + 14, $headBase, $c['white'], 10, true);
            foreach ($columns as $ci => $column) {
                $cx = $innerLeft + $noW + (int) ($ci * $colW);
                $pdf->text($pdf->fit(strtoupper((string) $column), 10, true, 'sans', $colW - 20), $cx + 10, $headBase, $c['white'], 10, true);
            }

            // Data rows: fixed height, alternating tint, a hairline separator, and padded cells.
            if ($pageRows === []) {
                $rowTop = $firstRow;
                if ($rowTop % 2 === 0) {
                    $pdf->rect($innerLeft, $rowTop, $innerRight - $innerLeft, $rowH, $c['pale']);
                }
                $pdf->text('No records were found for this period.', $innerLeft + 14, $rowTop + 20, $c['muted'], 12);
            }
            foreach ($pageRows as $ri => $row) {
                $abs = $page['start'] + $ri;
                $rowTop = $firstRow + (int) ($ri * $rowH);
                $base = $rowTop + 20;
                if ($abs % 2 === 0) {
                    $pdf->rect($innerLeft, $rowTop, $innerRight - $innerLeft, $rowH, $c['pale']);
                }
                if ($abs > 0) {
                    $pdf->line($innerLeft, $rowTop, $innerRight, $rowTop, $c['border']);
                }
                $pdf->text((string) ($abs + 1), $innerLeft + 14, $base, $c['muted'], 11);
                foreach (array_slice(is_array($row) ? $row : [], 0, $colCount) as $ci => $value) {
                    $cx = $innerLeft + $noW + (int) ($ci * $colW);
                    $pdf->text($pdf->fit((string) $value, 11, false, 'sans', $colW - 20), $cx + 10, $base, $c['text'], 11);
                }
            }

            // Authorised signature (only on the final page, just under the table).
            if ($pageIndex === $total - 1) {
                $sigY = $tableBottom + 6;
                $pdf->line($right - 220, $sigY + 14, $right, $sigY + 14, $c['border']);
                $pdf->text('Authorised signature', $right, $sigY + 30, $c['muted'], 10, false, 'sans', 'right');
            }

            // Footer band on every page with the brand line and page marker.
            $pdf->roundRect($cardLeft, $footBandTop, $cardRight - $cardLeft, $footBandH, 12, $c['brand']);
            $pdf->text('Thank you — ' . self::BRAND . ' · Internal administration report · ' . self::WEB, $innerLeft + 8, $footBandTop + (int) ($footBandH / 2) + 4, $c['white'], 12);
            $pdf->text('Page ' . ($pageIndex + 1) . ' of ' . $total, $innerRight - 8, $footBandTop + (int) ($footBandH / 2) + 4, $c['bandText'], 12, true, 'sans', 'right');
        }

        return $pdf->output();
    }

    /* ------------------------------------------------------------------ shared blocks */

    /** Perforated ticket divider: a page-coloured notch is cut into both card edges and a dashed rule joins them. */
    private static function ticketDivider(PdfCanvas $pdf, float $cardLeft, float $cardRight, float $y, array $c): void
    {
        $pdf->circle($cardLeft, $y, 26, $c['page']);
        $pdf->circle($cardRight, $y, 26, $c['page']);
        $pdf->dashedLine($cardLeft + 34, $cardRight - 34, $y, $c['dash']);
    }

    /** Draws the "at a glance" summary strip (headline metric tiles) shown at the top of page 1. */
    private static function reportSummary(PdfCanvas $pdf, float $x, float $y, float $w, float $h, array $metrics, array $c): void
    {
        $pdf->roundRect($x, $y, $w, $h, 12, $c['pale'], $c['border']);
        if ($metrics === []) {
            $pdf->text('No headline figures for this period.', $x + 20, $y + (int) ($h / 2) + 4, $c['muted'], 12);
            return;
        }
        $cellW = (int) ($w / max(1, count($metrics)));
        foreach ($metrics as $index => $metric) {
            $cx = $x + (int) ($index * $cellW);
            if ($index > 0) {
                $pdf->line($cx, $y + 16, $cx, $y + $h - 16, $c['border']);
            }
            $pdf->text($pdf->fit(strtoupper((string) ($metric['label'] ?? '')), 9, true, 'sans', $cellW - 34), $cx + 20, $y + 34, $c['muted'], 9, true);
            $pdf->text($pdf->fit((string) ($metric['value'] ?? '—'), 18, true, 'sans', $cellW - 34), $cx + 20, $y + 66, $c['navy'], 18, true);
        }
    }

    /* ------------------------------------------------------------------ text helpers */

    private static function safeText(string $value): string
    {
        return trim(preg_replace('/[\r\n\x00-\x1F]+/u', ' ', $value) ?? '');
    }

    private static function issuedLabel(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return gmdate('M j, Y');
        }
        $timestamp = strtotime($value . ' UTC');
        return $timestamp === false ? '—' : date('g:i A · M j, Y', $timestamp);
    }
}
