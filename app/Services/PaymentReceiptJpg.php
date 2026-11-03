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
 * Everything is authored in logical pixels and multiplied by SCALE (2x) so the files come out
 * crisp and HD, and the JPEG is encoded at maximum quality.
 */
final class PaymentReceiptJpg
{
    private const SCALE = 2;
    private const QUALITY = 100;

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
        $width = 760;
        $height = 1000;
        $image = self::canvas($width, $height);
        $c = self::palette($image);

        // Light page behind the white card, matching the browser view.
        self::rect($image, 0, 0, $width, $height, $c['page']);
        // Soft shadow, then the card itself.
        self::roundRect($image, 32, 34, 704, 940, 26, $c['shadow']);
        self::roundRect($image, 28, 28, 704, 940, 26, $c['white'], $c['border']);

        $center = (int) ($width / 2);
        $left = 78;
        $right = $width - 78;

        // Hero: green success check inside a pale ring.
        self::circle($image, $center, 112, 112, $c['ring']);
        self::circle($image, $center, 112, 84, $c['green']);
        self::check($image, $center, 112, $c['white']);

        self::text($image, 'Payment Successful!', $center, 214, $c['navy'], 30, true, 'serif', 'center');
        self::text($image, 'OFFICIAL RECEIPT', $center, 246, $c['blue'], 15, true, 'sans', 'center');
        self::text($image, self::BRAND . ' · Course enrollment', $center, 272, $c['muted'], 15, false, 'sans', 'center');

        self::dashedLine($image, $left, $right, 304, $c['dash']);

        $currency = strtoupper(trim((string) ($payment['currency'] ?? ''))) ?: 'PKR';
        $amountLabel = $currency . ' ' . number_format((float) ($payment['amount'] ?? 0), 2);
        $issued = self::issuedLabel($payment['receipt_issued_at'] ?? null);

        self::metaRow($image, $left, 340, 'Receipt No:', (string) ($payment['receipt_number'] ?? '—'), $c);
        self::metaRow($image, $left, 374, 'Issued:', $issued, $c);
        self::metaRow($image, $left, 408, 'Payment Method:', 'Verified bank / wallet transfer', $c);

        self::dashedLine($image, $left, $right, 440, $c['dash']);

        self::text($image, 'Items', $left, 474, $c['navy'], 15, true);
        self::text($image, 'Amount', $right, 474, $c['navy'], 15, true, 'sans', 'right');
        $course = self::fit($image, (string) ($payment['course_title'] ?? 'Course enrollment'), 15, false, 'sans', $right - $left - 190);
        self::text($image, '1x', $left, 508, $c['navy'], 15, true);
        $qtyW = self::textWidth('1x ', 15, true) + 6;
        self::text($image, $course, $left + $qtyW, 508, $c['text'], 15, false);
        self::text($image, $amountLabel, $right, 508, $c['navy'], 15, true, 'sans', 'right');
        self::text($image, 'Course enrollment payment · fully verified by the Academy', $left, 532, $c['muted'], 12, false);

        self::dashedLine($image, $left, $right, 560, $c['dash']);

        self::text($image, 'Subtotal', $left, 592, $c['muted'], 15, false);
        self::text($image, $amountLabel, $right, 592, $c['navy'], 15, true, 'sans', 'right');
        self::text($image, 'Total Paid', $left, 632, $c['navy'], 18, true);
        self::text($image, $amountLabel, $right, 632, $c['navy'], 21, true, 'sans', 'right');

        self::dashedLine($image, $left, $right, 664, $c['dash']);

        $colRight = $center + 24;
        self::text($image, 'BILLED TO', $left, 692, $c['muted'], 11, true);
        self::text($image, self::fit($image, (string) ($payment['student_name'] ?? 'Student'), 15, true, 'sans', $center - $left - 20), $left, 720, $c['navy'], 15, true);
        $email = (string) ($payment['student_email'] ?? '');
        if ($email !== '') self::text($image, self::fit($image, $email, 12, false, 'sans', $center - $left - 20), $left, 744, $c['muted'], 12, false);
        self::text($image, 'TRANSACTION REFERENCE', $colRight, 692, $c['muted'], 11, true);
        self::text($image, self::fit($image, (string) ($payment['transaction_reference'] ?? '—'), 14, false, 'sans', $right - $colRight), $colRight, 720, $c['text'], 14, false);

        self::text($image, 'Thanks for learning with ' . self::BRAND . '!', $center, 792, $c['navy'], 17, true, 'sans', 'center');
        self::text($image, 'Issued by ' . self::COMPANY, $center, 822, $c['muted'], 13, false, 'sans', 'center');
        self::text($image, 'This receipt confirms payment received for the course listed above.', $center, 846, $c['muted'], 12, false, 'sans', 'center');
        self::text($image, self::PHONE . '  ·  ' . self::EMAIL . '  ·  ' . self::WEB, $center, 882, $c['blue'], 13, true, 'sans', 'center');

        return self::jpeg($image);
    }

    public static function renderRevenue(array $report): string
    {
        $rates = is_array($report['rates'] ?? null) ? $report['rates'] : [];
        $width = 820;
        $height = 560 + (count($rates) * 46) + 150;
        $image = self::canvas($width, $height);
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
        $y = $headerY + 40;
        foreach ($rates as $index => $rate) {
            if ($index % 2 === 0) self::rect($image, $left, $y - 22, $right - $left, 40, $c['pale']);
            foreach ($cols as $col) {
                if ($col['key'] === 'currency') {
                    self::text($image, strtoupper((string) ($rate['currency'] ?? '')), $col['x'], $y, $c['navy'], 13, true);
                    continue;
                }
                $colour = $col['bold'] ? $c['green'] : $c['text'];
                self::text($image, number_format((float) ($rate[$col['key']] ?? 0), 2), $col['x'], $y, $colour, 13, $col['bold'], 'sans', $col['align']);
            }
            $y += 40;
        }
        if ($rates === []) self::text($image, 'No approved payments recorded for this month.', $left, $y, $c['muted'], 14, false);

        self::footer($image, $left, $right, $height - 24, $c, 'Generated ' . (string) ($report['generated_at'] ?? gmdate('Y-m-d H:i:s') . ' UTC'));

        return self::jpeg($image);
    }

    public static function renderReport(array $report): string
    {
        $rows = array_slice(is_array($report['rows'] ?? null) ? $report['rows'] : [], 0, 60);
        $metrics = array_slice(is_array($report['metrics'] ?? null) ? $report['metrics'] : [], 0, 4);
        $chart = array_slice(is_array($report['chart'] ?? null) ? $report['chart'] : [], 0, 4);
        $columns = array_values(is_array($report['columns'] ?? null) ? $report['columns'] : []);

        $width = 820;
        $left = 56;
        $right = $width - 56;
        $center = (int) ($width / 2);

        // Vertical rhythm is computed up front so the canvas is exactly tall enough and the white
        // table card always overlaps the header band by a fixed amount.
        $bandTop = 24;
        $bandH = 214;
        $cardLeft = 40;
        $cardRight = $width - 40;
        $cardTop = $bandTop + $bandH - 30;
        $padX = 26;
        $innerLeft = $cardLeft + $padX;
        $innerRight = $cardRight - $padX;
        $headTop = $cardTop + 40;
        $headH = 42;
        $rowH = 34;
        $rowCount = max(1, count($rows));
        $tableBottom = $headTop + $headH + $rowCount * $rowH;
        $cardBottom = $tableBottom + 22;
        $secTop = $cardBottom + 30;
        $secH = 176;
        $secBottom = $secTop + $secH;
        $footTop = $secBottom + 34;
        $footH = 60;
        $height = $footTop + $footH + 24;

        $image = self::canvas($width, (int) $height);
        $c = self::palette($image);

        self::rect($image, 0, 0, $width, (int) $height, $c['page']);
        self::roundRect($image, 24, 24, $width - 48, (int) $height - 48, 22, $c['white']);

        // Header band in the requested brand navy (#0B1B3A).
        self::roundRect($image, 24, $bandTop, $width - 48, $bandH, 22, $c['brand']);

        // Logo mark: a 3x3 dot grid, then the brand name and tagline.
        $dot = 7;
        $dotGap = 5;
        $gridX = $left;
        $gridY = $bandTop + 34;
        for ($r = 0; $r < 3; $r++) {
            for ($col = 0; $col < 3; $col++) {
                self::rect($image, (int) ($gridX + $col * ($dot + $dotGap)), (int) ($gridY + $r * ($dot + $dotGap)), $dot, $dot, $c['bandText']);
            }
        }
        $logoW = 3 * $dot + 2 * $dotGap;
        self::text($image, self::BRAND, (int) ($left + $logoW + 16), $bandTop + 52, $c['white'], 24, true, 'sans');
        self::text($image, self::TAGLINE, (int) ($left + $logoW + 16), $bandTop + 74, $c['bandText'], 12, false, 'sans');

        // Contact block, right aligned.
        self::text($image, 'CONTACT', $right, $bandTop + 44, $c['white'], 12, true, 'sans', 'right');
        self::text($image, self::CITY, $right, $bandTop + 64, $c['bandText'], 11, false, 'sans', 'right');
        self::text($image, self::PHONE, $right, $bandTop + 82, $c['bandText'], 11, false, 'sans', 'right');
        self::text($image, self::EMAIL, $right, $bandTop + 100, $c['bandText'], 11, false, 'sans', 'right');

        // Lower band row: prepared-for / period / generated. Kept clear of the card overlap below.
        self::line($image, $left, $bandTop + $bandH - 96, $right, $bandTop + $bandH - 96, $c['brandLine'], 1);
        $metaLabelY = $bandTop + $bandH - 78;
        $metaValueY = $bandTop + $bandH - 56;
        self::text($image, 'PREPARED FOR', $left, $metaLabelY, $c['bandText'], 10, true, 'sans');
        self::text($image, 'Academy administration', $left, $metaValueY, $c['white'], 13, true, 'sans');
        self::text($image, 'REPORTING PERIOD', 300, $metaLabelY, $c['bandText'], 10, true, 'sans');
        self::text($image, self::fit($image, (string) ($report['period_range'] ?? ''), 13, true, 'sans', 250), 300, $metaValueY, $c['white'], 13, true, 'sans');
        self::text($image, 'GENERATED', $right, $metaLabelY, $c['bandText'], 10, true, 'sans', 'right');
        self::text($image, (string) ($report['generated_at'] ?? date('M j, Y')), $right, $metaValueY, $c['white'], 12, false, 'sans', 'right');

        // Table card (white, overlapping the band bottom).
        self::roundRect($image, $cardLeft, $cardTop, $cardRight - $cardLeft, $cardBottom - $cardTop, 16, $c['white'], $c['border']);
        self::text($image, self::fit($image, (string) ($report['title'] ?? 'Academy report'), 18, true, 'sans', $innerRight - $innerLeft), $innerLeft, $cardTop + 24, $c['navy'], 18, true, 'sans');

        // Header row.
        self::roundRect($image, $innerLeft, $headTop, $innerRight - $innerLeft, $headH, 8, $c['brand']);
        $noW = 52;
        $dataW = ($innerRight - $innerLeft) - $noW;
        $colCount = max(1, count($columns));
        $colW = (int) ($dataW / $colCount);
        $headBase = $headTop + (int) ($headH / 2) + 5;
        self::text($image, 'NO.', $innerLeft + 14, $headBase, $c['white'], 11, true, 'sans');
        foreach ($columns as $index => $column) {
            $cx = $innerLeft + $noW + (int) ($index * $colW);
            self::text($image, self::fit($image, strtoupper((string) $column), 11, true, 'sans', $colW - 16), $cx + 8, $headBase, $c['white'], 11, true, 'sans');
        }

        // Data rows with alternating tint and a running number.
        for ($ri = 0; $ri < $rowCount; $ri++) {
            $rowTop = $headTop + $headH + (int) ($ri * $rowH);
            $base = $rowTop + (int) ($rowH / 2) + 5;
            if ($ri % 2 === 0) self::rect($image, $innerLeft, $rowTop, $innerRight - $innerLeft, $rowH, $c['pale']);
            $row = is_array($rows[$ri] ?? null) ? $rows[$ri] : null;
            if ($row === null) {
                self::text($image, 'No records were found for this period.', $innerLeft + 14, $base, $c['muted'], 12, false, 'sans');
                continue;
            }
            self::text($image, (string) ($ri + 1), $innerLeft + 14, $base, $c['muted'], 12, false, 'sans');
            foreach (array_slice($row, 0, $colCount) as $ci => $value) {
                $cx = $innerLeft + $noW + (int) ($ci * $colW);
                self::text($image, self::fit($image, (string) $value, 12, false, 'sans', $colW - 16), $cx + 8, $base, $c['text'], 12, false, 'sans');
            }
        }

        // Summary box (left).
        $boxW = 336;
        self::roundRect($image, $cardLeft, $secTop, $boxW, $secH, 14, $c['white'], $c['border']);
        self::rect($image, $cardLeft, $secTop, 6, $secH, $c['brand']);
        self::text($image, 'REPORT SUMMARY', $cardLeft + 22, $secTop + 30, $c['brand'], 12, true, 'sans');
        if ($metrics === []) {
            self::text($image, 'No headline figures for this period.', $cardLeft + 22, $secTop + 60, $c['muted'], 12, false, 'sans');
        }
        $my = $secTop + 58;
        foreach ($metrics as $metric) {
            self::text($image, self::fit($image, (string) ($metric['label'] ?? ''), 12, false, 'sans', $boxW - 130), $cardLeft + 22, $my, $c['muted'], 12, false, 'sans');
            self::text($image, self::fit($image, (string) ($metric['value'] ?? '—'), 13, true, 'sans', $boxW - 160), $cardLeft + $boxW - 22, $my, $c['navy'], 13, true, 'sans', 'right');
            self::line($image, $cardLeft + 22, $my + 12, $cardLeft + $boxW - 22, $my + 12, $c['border'], 1);
            $my += 30;
        }

        // Period snapshot bars (right).
        $rx = $cardLeft + $boxW + 24;
        $rw = $cardRight - $rx;
        self::text($image, 'PERIOD SNAPSHOT', $rx, $secTop + 30, $c['brand'], 12, true, 'sans');
        if ($chart !== []) {
            $values = array_map(static fn(array $item): float => max(0, (float) ($item['value'] ?? 0)), $chart);
            $maxValue = $values === [] ? 1.0 : max(1.0, ...$values);
            $barLabelW = 150;
            $barTrackW = $rw - $barLabelW - 74;
            $by = $secTop + 58;
            foreach ($chart as $index => $metric) {
                self::text($image, self::fit($image, (string) ($metric['label'] ?? ''), 11, false, 'sans', $barLabelW - 8), $rx, $by, $c['muted'], 11, false, 'sans');
                self::roundRect($image, (int) ($rx + $barLabelW), $by - 10, (int) $barTrackW, 12, 6, $c['pale']);
                $barW = (int) round($barTrackW * (($values[$index] ?? 0) / $maxValue));
                if ($barW > 4) self::roundRect($image, (int) ($rx + $barLabelW), $by - 10, $barW, 12, 6, $c['brand']);
                self::text($image, self::fit($image, (string) ($metric['value'] ?? ''), 11, true, 'sans', 62), $rx + $rw, $by, $c['navy'], 11, true, 'sans', 'right');
                $by += 30;
            }
        } else {
            self::text($image, 'No distribution snapshot for this period.', $rx, $secTop + 58, $c['muted'], 12, false, 'sans');
        }

        // Total records pill.
        $pillW = 210;
        $pillH = 42;
        $pillX = $cardRight - $pillW;
        $pillY = $secBottom - $pillH;
        self::roundRect($image, $pillX, $pillY, $pillW, $pillH, 10, $c['brand']);
        self::text($image, 'Total records', $pillX + 16, $pillY + 26, $c['bandText'], 12, false, 'sans');
        self::text($image, (string) count($rows), $pillX + $pillW - 16, $pillY + 27, $c['white'], 16, true, 'sans', 'right');

        // Authorised signature line.
        self::line($image, $rx, $secBottom - 4, $pillX - 24, $secBottom - 4, $c['border'], 1);
        self::text($image, 'Authorised signature', $rx, $secBottom + 14, $c['muted'], 10, false, 'sans');

        // Bottom band.
        self::roundRect($image, $cardLeft, $footTop, $cardRight - $cardLeft, $footH, 14, $c['brand']);
        self::text($image, self::BRAND . ' · Internal administration report · ' . self::WEB, $center, $footTop + (int) ($footH / 2) + 5, $c['white'], 12, false, 'sans', 'center');

        return self::jpeg($image);
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

    private static function canvas(int $logicalWidth, int $logicalHeight): \GdImage
    {
        if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            throw new \RuntimeException('The GD extension is required to generate JPG documents.');
        }
        $image = imagecreatetruecolor($logicalWidth * self::SCALE, $logicalHeight * self::SCALE);
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
