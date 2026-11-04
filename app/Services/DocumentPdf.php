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
            'danger' => [190, 18, 60],
            'dangerTint' => [254, 226, 226],
            'amber' => [180, 83, 9],
            'amberTint' => [254, 243, 199],
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
        $type = strtolower(trim((string) ($report['type'] ?? 'students')));
        $periodRange = self::safeText((string) ($report['period_range'] ?? ''));
        $generated = self::safeText((string) ($report['generated_at'] ?? date('M j, Y')));
        $reference = self::safeText((string) ($report['reference'] ?? '')) ?: self::reportReference($report);
        $audience = self::reportAudience($report);
        $closing = isset($report['audience']) ? 'Report shared by the Academy' : 'Internal administration report';

        $pdf = new PdfCanvas();
        $c = self::palette();

        $width = 827;
        $height = 1169;
        $right = $width - 56;

        // Horizontal geometry: the table is drawn straight onto the white sheet, like the reference layout.
        $innerLeft = 64;
        $innerRight = $width - 64;
        $tableW = $innerRight - $innerLeft;
        $noW = 34;
        $colCount = max(1, count($columns));
        // Column widths are measured from the data and the unused width is shared out, so a name or an
        // email prints whole instead of being cut with an ellipsis beside a short, roomy column.
        $cols = self::columnGeometry($pdf, $columns, $rows, $colCount, $innerLeft + $noW, $tableW - $noW);

        // Shared vertical geometry.
        $rowH = 28;
        $headH = 34;
        $footRuleY = $height - 78;
        $tableLimit = $footRuleY - 26;

        // Page 1 reserves room for the slim band, the prepared-for block and a caption above the table.
        $bandH = 104;
        $p1Caption = 226;
        $p1HeadTop = 236;
        $p1FirstRow = $p1HeadTop + $headH;

        // Continuation pages use a narrower band and repeat the header row.
        $contBandBottom = 96;
        $contCaption = 122;
        $contHeadTop = 132;
        $contFirstRow = $contHeadTop + $headH;

        // The closing page keeps space for the totals panel, the notes and the signature.
        $panelH = 34 + max(1, count($metrics)) * 26 + 12;
        $finalReserve = 26 + $panelH + 76;

        $p1Capacity = max(1, intdiv($tableLimit - $p1FirstRow, $rowH));
        $contCapacity = max(1, intdiv($tableLimit - $contFirstRow, $rowH));
        $p1FinalCapacity = max(1, intdiv($tableLimit - $finalReserve - $p1FirstRow, $rowH));
        $contFinalCapacity = max(1, intdiv($tableLimit - $finalReserve - $contFirstRow, $rowH));

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
        // The closing block must never hang off the page, so an over-full last chunk is pushed onto a
        // fresh page until what remains fits the reduced capacity.
        while (true) {
            $last = count($pages) - 1;
            $capacity = $pages[$last]['first'] ? $p1FinalCapacity : $contFinalCapacity;
            if (count($pages[$last]['rows']) <= $capacity) {
                break;
            }
            $carried = array_slice($pages[$last]['rows'], $capacity);
            $pages[$last]['rows'] = array_slice($pages[$last]['rows'], 0, $capacity);
            $pages[] = ['rows' => $carried, 'start' => $pages[$last]['start'] + $capacity, 'first' => false];
        }
        $total = count($pages);
        $grandTotal = count($rows);

        foreach ($pages as $pageIndex => $page) {
            $pageRows = $page['rows'];
            $count = max(1, count($pageRows));
            $first = $page['first'];
            $caption = $first ? $p1Caption : $contCaption;
            $headTop = $first ? $p1HeadTop : $contHeadTop;
            $firstRow = $first ? $p1FirstRow : $contFirstRow;
            $tableBottom = $firstRow + $count * $rowH;
            $isLast = $pageIndex === $total - 1;

            $pdf->newPage();
            $pdf->rect(0, 0, $width, $height, $c['page']);
            $pdf->roundRect(24, 24, $width - 48, $height - 48, 20, $c['white']);

            if ($first) {
                // Slim brand band: monogram and wordmark on the left, the document name and its
                // reference on the right, exactly where the reference invoice puts "INVOICE".
                $pdf->roundRect(24, 24, $width - 48, $bandH, 20, $c['brand']);
                $pdf->roundRect(56, 46, 40, 40, 10, $c['white']);
                $pdf->text('TS', 76, 73, $c['brand'], 15, true, 'sans', 'center');
                $pdf->text(self::BRAND, 108, 66, $c['white'], 17, true);
                $pdf->text(self::TAGLINE, 108, 84, $c['bandText'], 10);
                $titleFit = self::fitSize($pdf, strtoupper($title), 20, 14, $right - 300);
                $pdf->text($titleFit[1], $right, 60, $c['white'], $titleFit[0], true, 'sans', 'right');
                $pdf->text('Report No. ' . $reference . ' · Generated ' . $generated, $right, 84, $c['bandText'], 10, false, 'sans', 'right');

                // Prepared-for block on the left, period and record count as right-aligned pairs.
                $pdf->text('PREPARED FOR', $innerLeft, 158, $c['muted'], 9, true);
                $pdf->text($pdf->fit($audience['name'], 15, true, 'sans', 380), $innerLeft, 180, $c['navy'], 15, true);
                $pdf->text($pdf->fit($audience['detail'], 10.5, false, 'sans', 400), $innerLeft, 198, $c['muted'], 10.5);
                self::metaRow($pdf, $innerRight, 162, 'Reporting period', $periodRange, $innerLeft + 430, $c, 11);
                self::metaRow($pdf, $innerRight, 186, 'Records', number_format($grandTotal) . ' in this period', $innerLeft + 430, $c, 11);
                $pdf->text('RECORDS FOR THIS PERIOD', $innerLeft, $caption, $c['navy'], 11, true);
            } else {
                // Narrow band on a continuation page, with the row window printed inside it.
                $pdf->roundRect(24, 24, $width - 48, $contBandBottom - 24, 20, $c['brand']);
                $pdf->roundRect(56, 40, 32, 32, 8, $c['white']);
                $pdf->text('TS', 72, 62, $c['brand'], 12, true, 'sans', 'center');
                $pdf->text(self::BRAND, 98, 60, $c['white'], 14, true);
                $contTitle = self::fitSize($pdf, strtoupper($title), 12, 10, $right - 210);
                $pdf->text($contTitle[1], $right, 58, $c['bandText'], $contTitle[0], true, 'sans', 'right');
                $pdf->text(
                    'Rows ' . number_format($page['start'] + 1) . '-' . number_format($page['start'] + count($pageRows))
                    . ' of ' . number_format($grandTotal) . ' · Report No. ' . $reference,
                    $right,
                    78,
                    $c['bandText'],
                    10,
                    false,
                    'sans',
                    'right'
                );
                $pdf->text('RECORDS FOR THIS PERIOD (CONTINUED)', $innerLeft, $caption, $c['navy'], 11, true);
            }

            // Table header, repeated on every page, aligned the same way as the cells below it.
            $pdf->roundRect($innerLeft, $headTop, $tableW, $headH, 8, $c['brand']);
            $headBase = $headTop + 22;
            $pdf->text('NO.', $innerLeft + 8, $headBase, $c['white'], 10, true);
            foreach ($cols as $ci => $col) {
                $label = $pdf->fit(strtoupper((string) ($columns[$ci] ?? '')), 10, true, 'sans', $col['w'] - 12);
                $x = $col['align'] === 'right' ? $col['x'] + $col['w'] - 6 : $col['x'] + 6;
                $pdf->text($label, $x, $headBase, $c['white'], 10, true, 'sans', $col['align']);
            }

            // Data rows: one tinted band per pair, a hairline between rows, padded cells, chips for statuses.
            if ($pageRows === []) {
                $pdf->rect($innerLeft, $firstRow, $tableW, $rowH, $c['pale']);
                $pdf->text('No records were found for this period.', $innerLeft + 8, $firstRow + 18, $c['muted'], 11);
            }
            foreach ($pageRows as $ri => $row) {
                $abs = $page['start'] + $ri;
                $rowTop = $firstRow + (int) ($ri * $rowH);
                $base = $rowTop + 18;
                if ($abs % 2 === 0) {
                    $pdf->rect($innerLeft, $rowTop, $tableW, $rowH, $c['pale']);
                }
                if ($abs > 0) {
                    $pdf->line($innerLeft, $rowTop, $innerRight, $rowTop, $c['border']);
                }
                $pdf->text((string) ($abs + 1), $innerLeft + 8, $base, $c['muted'], 10);
                foreach (array_slice(is_array($row) ? $row : [], 0, $colCount) as $ci => $value) {
                    $col = $cols[$ci];
                    $value = self::safeText((string) $value);
                    $tone = $col['status'] ? self::chipFor($value) : null;
                    if ($tone !== null) {
                        self::chip($pdf, (float) $col['x'] + 4, (float) $rowTop + 5, self::chipLabel($value), $tone, $c);
                        continue;
                    }
                    $x = $col['align'] === 'right' ? $col['x'] + $col['w'] - 6 : $col['x'] + 6;
                    $pdf->text($pdf->fit($value, 11, false, 'sans', $col['w'] - 12), $x, $base, $c['text'], 11, false, 'sans', $col['align']);
                }
            }

            if ($isLast) {
                // Closing block: totals panel on the right, what the report covers on the left,
                // signature under the panel. Mirrors the SUB TOTAL / Terms / signature foot of the sample.
                $blockTop = $tableBottom + 26;
                $panelW = 300;
                $panelX = $innerRight - $panelW;
                $pdf->roundRect($panelX, $blockTop, $panelW, $panelH, 12, $c['pale'], $c['border']);
                $pdf->text('REPORT TOTALS', $panelX + 18, $blockTop + 24, $c['muted'], 9, true);
                if ($metrics === []) {
                    $pdf->text('No headline figures for this period.', $panelX + 18, $blockTop + 52, $c['muted'], 11);
                }
                $rowY = $blockTop + 34;
                foreach ($metrics as $mi => $metric) {
                    if ($mi > 0) {
                        $pdf->line($panelX + 14, $rowY, $innerRight - 14, $rowY, $c['border']);
                    }
                    $label = self::safeText(ucfirst(strtolower((string) ($metric['label'] ?? ''))));
                    $pdf->text($pdf->fit($label, 10.5, false, 'sans', $panelW - 130), $panelX + 18, $rowY + 18, $c['muted'], 10.5);
                    $pdf->text($pdf->fit((string) ($metric['value'] ?? '—'), 12, true, 'sans', 110), $innerRight - 18, $rowY + 18, $c['navy'], 12, true, 'sans', 'right');
                    $rowY += 26;
                }

                $notesW = $panelX - $innerLeft - 34;
                $pdf->text('WHAT THIS REPORT COVERS', $innerLeft, $blockTop + 24, $c['muted'], 9, true);
                $lineY = $blockTop + 46;
                foreach (self::wrapText($pdf, self::reportNotes($type), 11, $notesW) as $noteLine) {
                    $pdf->text($noteLine, $innerLeft, $lineY, $c['text'], 11);
                    $lineY += 16;
                }

                $sigY = $blockTop + $panelH + 40;
                $pdf->line($innerRight - 200, $sigY, $innerRight, $sigY, $c['dash']);
                $pdf->text('Authorised signature', $innerRight, $sigY + 18, $c['muted'], 10, false, 'sans', 'right');
            }

            // Quiet footer rule instead of a heavy band: purpose left, site centred, page marker right.
            $pdf->line($innerLeft, $footRuleY, $innerRight, $footRuleY, $c['border']);
            $pdf->text('Thank you — ' . self::BRAND . ' · ' . $closing, $innerLeft, $footRuleY + 22, $c['muted'], 10);
            $pdf->text(self::WEB, (int) ($width / 2), $footRuleY + 22, $c['muted'], 10, false, 'sans', 'center');
            $pdf->text('Page ' . ($pageIndex + 1) . ' of ' . $total, $innerRight, $footRuleY + 22, $c['navy'], 10, true, 'sans', 'right');
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

    /**
     * Measure the table and hand out the width its columns did not ask for.
     *
     * Every column is sized from its own widest real value (headers included), then the leftover width
     * is shared out in proportion to that need. Short columns stop hogging space and long names, emails
     * and titles are printed whole instead of being cut with an ellipsis.
     *
     * @return array<int,array{x:float,w:float,align:string}>
     */
    private static function columnGeometry(PdfCanvas $pdf, array $columns, array $rows, int $colCount, float $xStart, float $available): array
    {
        $need = [];
        $statusCol = [];
        for ($i = 0; $i < $colCount; $i++) {
            $need[$i] = $pdf->textWidth(strtoupper((string) ($columns[$i] ?? '')), 10, true);
            // Only a column that is named like a status may wear a pill. Elsewhere the very same word is
            // just a label, and turning it into a badge would claim something the data never said.
            $statusCol[$i] = preg_match('/\b(status|state|result)\b/i', (string) ($columns[$i] ?? '')) === 1;
        }
        $numeric = array_fill(0, $colCount, true);
        $seen = array_fill(0, $colCount, 0);
        foreach ($rows as $row) {
            foreach (array_slice(is_array($row) ? $row : [], 0, $colCount) as $i => $value) {
                $text = self::safeText((string) $value);
                if ($text === '') {
                    continue;
                }
                $seen[$i]++;
                if (!self::isNumericValue($text)) {
                    $numeric[$i] = false;
                }
                // A status word is drawn as a pill, so the column has to fit the pill rather than the word.
                $width = ($statusCol[$i] && self::chipFor($text) !== null)
                    ? $pdf->textWidth(self::chipLabel($text), 9.5, true) + 20
                    : $pdf->textWidth($text, 11, false);
                $need[$i] = max($need[$i], $width);
            }
        }

        $pad = 14;
        $widths = [];
        foreach ($need as $i => $w) {
            $widths[$i] = max(58, (int) ceil($w) + $pad);
        }
        $sum = array_sum($widths);
        $last = count($widths) - 1;
        if ($sum <= $available) {
            $slack = $available - $sum;
            $used = 0;
            foreach ($widths as $i => $w) {
                // The final column soaks up the rounding remainder so the table always ends on the rule.
                $extra = $i === $last ? $slack - $used : (int) floor($slack * $w / max(1, $sum));
                $used += $extra;
                $widths[$i] = $w + $extra;
            }
        } else {
            // Wider than the sheet: shrink in proportion but never below a readable floor.
            $used = 0;
            foreach ($widths as $i => $w) {
                $shrunk = $i === $last
                    ? (int) ($available - $used)
                    : max(48, (int) floor($w * $available / $sum));
                $used += $shrunk;
                $widths[$i] = $shrunk;
            }
        }

        $cols = [];
        $x = $xStart;
        foreach ($widths as $i => $w) {
            $cols[] = [
                'x' => $x,
                'w' => (float) $w,
                'align' => ($numeric[$i] && $seen[$i] > 0) ? 'right' : 'left',
                'status' => $statusCol[$i],
            ];
            $x += $w;
        }
        return $cols;
    }

    /** Counts, rates and scores are right-aligned so their digits line up down the column. */
    private static function isNumericValue(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1 || preg_match('/^\d{1,2}:\d{2}/', $value) === 1) {
            return false;
        }
        // "—/100" is an empty score inside a numeric column, so it belongs with the digits above it.
        return preg_match('/^(?:—|\d)[\d.,\/%\s]*$/u', $value) === 1;
    }

    /** Only known status words become pills; anything else stays plain text so the table never invents one. */
    private static function chipFor(string $value): ?string
    {
        return match (strtolower(trim($value))) {
            'present', 'pass', 'reviewed', 'active', 'paid', 'approved', 'on time' => 'ok',
            'absent', 'fail', 'rejected', 'suspended', 'banned' => 'bad',
            'pending', 'submitted', 'in progress', 'late', 'unpaid', 'resubmit requested', 'auto submitted', 'timed out' => 'warn',
            default => null,
        };
    }

    /** A status pill: tinted rounded background, darker label, sized to the word inside it. */
    private static function chip(PdfCanvas $pdf, float $x, float $top, string $label, string $tone, array $c): void
    {
        [$fg, $bg] = match ($tone) {
            'bad' => [$c['danger'], $c['dangerTint']],
            'warn' => [$c['amber'], $c['amberTint']],
            default => [$c['paid'], $c['ring']],
        };
        $width = (float) ($pdf->textWidth($label, 9.5, true) + 20);
        $pdf->roundRect($x, $top, $width, 18, 9, $bg);
        $pdf->text($label, $x + 10, $top + 13, $fg, 9.5, true);
    }

    /** The stored word is lower case; the printed pill reads better title-cased, and never rewrites data. */
    private static function chipLabel(string $value): string
    {
        $value = trim($value);
        return preg_match('/^[a-z][a-z ]*$/', $value) === 1 ? ucwords($value) : $value;
    }

    /** A right-aligned "Label: value" pair, measured so both parts finish exactly on the right edge. */
    private static function metaRow(PdfCanvas $pdf, float $right, float $baseline, string $label, string $value, float $minLeft, array $c, float $size): void
    {
        $caption = $label . ':';
        $gap = 11;
        $captionW = (float) $pdf->textWidth($caption, $size, true);
        $value = $pdf->fit($value, $size, false, 'sans', max(40.0, $right - $minLeft - $captionW - $gap));
        $start = $right - ($captionW + $gap + $pdf->textWidth($value, $size, false));
        $pdf->text($caption, $start, $baseline, $c['navy'], $size, true);
        $pdf->text($value, $start + $captionW + $gap, $baseline, $c['text'], $size);
    }

    /** A heading is shrunk before it is cut, so a long report name never prints as "ATTEND…". */
    private static function fitSize(PdfCanvas $pdf, string $value, float $maxSize, float $minSize, float $available): array
    {
        for ($size = $maxSize; $size >= $minSize; $size -= 1) {
            if ($pdf->textWidth($value, $size, true) <= $available) {
                return [$size, $value];
            }
        }
        return [$minSize, $pdf->fit($value, $minSize, true, 'sans', $available)];
    }

    /** Break a sentence into lines that fit the column, so the notes block never runs into the panel. */
    private static function wrapText(PdfCanvas $pdf, string $text, float $size, float $maxWidth): array
    {
        $lines = [];
        $current = '';
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if ($current !== '' && $pdf->textWidth($candidate, $size, false) > $maxWidth) {
                $lines[] = $current;
                $current = $word;
                continue;
            }
            $current = $candidate;
        }
        if ($current !== '') {
            $lines[] = $current;
        }
        return array_slice($lines, 0, 4);
    }

    /** Who the document is for. Shared student copies carry their own audience; desk exports do not. */
    private static function reportAudience(array $report): array
    {
        $given = is_array($report['audience'] ?? null) ? $report['audience'] : [];
        return [
            'name' => self::safeText((string) ($given['name'] ?? '')) ?: 'Academy administration',
            'detail' => self::safeText((string) ($given['detail'] ?? '')) ?: 'Reports desk · ' . self::BRAND,
        ];
    }

    /** A printable reference so an admin and a student can name the very same document. */
    private static function reportReference(array $report): string
    {
        $slug = strtoupper(substr(preg_replace('/[^a-z]/', '', strtolower((string) ($report['type'] ?? ''))) ?? '', 0, 3));
        $weekly = strtolower((string) ($report['period'] ?? '')) === 'weekly';
        // The escaped W prints the literal letter, the plain W after it is the ISO week number.
        $stamp = date($weekly ? 'o-\WW' : 'Y-m');
        $parts = preg_split('/\s[\x{2013}-]\s/u', (string) ($report['period_range'] ?? '')) ?: [];
        $end = trim((string) ($parts[1] ?? ''));
        if ($end !== '' && ($ts = strtotime($end)) !== false) {
            $stamp = date($weekly ? 'o-\WW' : 'Y-m', $ts);
        }
        return 'TSG-REP-' . ($slug !== '' ? $slug : 'ALL') . '-' . ($weekly ? 'W' : 'M') . '-' . $stamp;
    }

    /** One plain-language note per desk, printed under the table instead of a filler paragraph. */
    private static function reportNotes(string $type): string
    {
        return match ($type) {
            'attendance' => 'One line per marked day. A day counts as present once the session is confirmed, and closes as absent when no confirmation arrives inside the window.',
            'quizzes' => 'Only submitted attempts appear here. Each score is taken from the quiz total and the result applies the pass mark stored with that quiz.',
            'assignments' => 'Submissions are listed with the marks an instructor awarded after review. An unreviewed submission stays pending and carries no score yet.',
            'students' => 'Every enrolled student is listed with the days marked for the period and the regularity rate that comes out of those days.',
            'all' => 'One consolidated pack. Every headline figure is taken from its own desk, and the rows below cover student, attendance, quiz and assignment activity for the period.',
            default => 'Rows are taken from the academy records that fall inside the reporting period printed above.',
        };
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
