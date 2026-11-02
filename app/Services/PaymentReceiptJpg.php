<?php

declare(strict_types=1);

namespace App\Services;

final class PaymentReceiptJpg
{
    private const WIDTH = 1200;
    private static ?string $font = null;
    private static ?string $boldFont = null;

    public static function renderReceipt(array $payment): string
    {
        $image = self::canvas(1120);
        $navy = self::color($image, 15, 23, 42);
        $muted = self::color($image, 91, 107, 129);
        $blue = self::color($image, 37, 99, 235);
        $green = self::color($image, 4, 120, 87);
        $line = self::color($image, 226, 232, 240);
        $pale = self::color($image, 247, 249, 252);
        self::text($image, 'TECH SG ACADEMY', 72, 86, $navy, 31, true);
        self::text($image, 'PAYMENT RECEIPT', 72, 132, $blue, 16, true);
        self::pill($image, 'PAID', 942, 62, 102, 42, $green);
        imageline($image, 72, 164, 1048, 164, $line);

        self::text($image, 'RECEIPT NUMBER', 72, 210, $muted, 13, true);
        self::text($image, (string) ($payment['receipt_number'] ?? '—'), 72, 246, $navy, 23, true);
        self::text($image, 'ISSUED', 680, 210, $muted, 13, true);
        self::text($image, self::dateLabel($payment['receipt_issued_at'] ?? null), 680, 246, $navy, 18, true);

        self::box($image, 72, 282, 976, 160, $pale, $line);
        self::text($image, 'BILLED TO', 96, 322, $muted, 13, true);
        self::text($image, (string) ($payment['student_name'] ?? 'Student'), 96, 362, $navy, 22, true);
        self::text($image, (string) ($payment['student_email'] ?? ''), 96, 397, $muted, 16);
        self::text($image, 'COURSE', 680, 322, $muted, 13, true);
        self::wrapped($image, (string) ($payment['course_title'] ?? 'Course'), 680, 360, 340, $navy, 18, true, 2);

        self::text($image, 'PAYMENT DETAILS', 72, 504, $navy, 20, true);
        self::text($image, 'Description', 96, 555, $muted, 14, true);
        self::text($image, 'Amount', 824, 555, $muted, 14, true);
        imageline($image, 72, 578, 1048, 578, $line);
        self::wrapped($image, (string) ($payment['course_title'] ?? 'Course enrollment'), 96, 620, 600, $navy, 18, true, 2);
        $amount = strtoupper((string) ($payment['currency'] ?? '')) . ' ' . number_format((float) ($payment['amount'] ?? 0), 2);
        self::text($image, $amount, 824, 620, $navy, 20, true);
        self::text($image, 'Course enrollment payment', 96, 658, $muted, 14);

        imageline($image, 72, 712, 1048, 712, $line);
        self::text($image, 'TOTAL PAID', 680, 760, $muted, 15, true);
        self::text($image, $amount, 824, 806, $blue, 31, true);
        self::text($image, 'Transaction reference', 96, 760, $muted, 13, true);
        self::text($image, (string) ($payment['transaction_reference'] ?? '—'), 96, 797, $navy, 17, true);

        imageline($image, 72, 870, 1048, 870, $line);
        self::text($image, 'Issued by Tech SG Studio (SMC-Pvt.) Ltd.', 72, 918, $navy, 16, true);
        self::text($image, 'info@techsgstudio.com', 72, 952, $muted, 15);
        self::text($image, 'This receipt confirms payment received for the course listed above.', 72, 1010, $muted, 14);
        return self::jpeg($image);
    }

    public static function renderRevenue(array $report): string
    {
        $rates = is_array($report['rates'] ?? null) ? $report['rates'] : [];
        $height = max(940, 590 + (count($rates) * 120));
        $image = self::canvas($height);
        $navy = self::color($image, 15, 23, 42);
        $muted = self::color($image, 91, 107, 129);
        $blue = self::color($image, 37, 99, 235);
        $green = self::color($image, 4, 120, 87);
        $line = self::color($image, 226, 232, 240);
        $pale = self::color($image, 247, 249, 252);
        self::text($image, 'TECH SG ACADEMY', 72, 86, $navy, 31, true);
        self::text($image, 'MONTHLY REVENUE STATEMENT', 72, 132, $blue, 16, true);
        self::pill($image, 'ADMIN REPORT', 850, 62, 198, 42, $blue);
        self::text($image, (string) ($report['period'] ?? ''), 72, 214, $navy, 27, true);
        self::text($image, 'Approved course payments · Developer share calculated at 15%', 72, 252, $muted, 16);
        self::box($image, 72, 292, 976, 98, $pale, $line);
        self::text($image, 'ENROLLED STUDENTS', 96, 330, $muted, 12, true);
        self::text($image, number_format((int) ($report['student_count'] ?? 0)), 96, 370, $navy, 24, true);
        self::text($image, 'PAYMENTS RECEIVED', 430, 330, $muted, 12, true);
        self::text($image, number_format((int) ($report['payment_count'] ?? 0)), 430, 370, $navy, 24, true);
        self::text($image, 'CURRENCY TOTALS', 72, 438, $navy, 19, true);
        self::text($image, 'Currency', 88, 482, $muted, 12, true);
        self::text($image, 'This month', 245, 482, $muted, 12, true);
        self::text($image, 'Dev 15%', 455, 482, $muted, 12, true);
        self::text($image, 'All time', 650, 482, $muted, 12, true);
        self::text($image, 'Dev 15%', 855, 482, $muted, 12, true);
        imageline($image, 72, 504, 1048, 504, $line);
        $y = 548;
        foreach ($rates as $rate) {
            self::text($image, strtoupper((string) ($rate['currency'] ?? '')), 88, $y, $navy, 15, true);
            self::text($image, number_format((float) ($rate['monthly_revenue'] ?? 0), 2), 245, $y, $navy, 15, true);
            self::text($image, number_format((float) ($rate['developer_share'] ?? 0), 2), 455, $y, $green, 15, true);
            self::text($image, number_format((float) ($rate['lifetime_revenue'] ?? 0), 2), 650, $y, $navy, 15, true);
            self::text($image, number_format((float) ($rate['lifetime_developer_share'] ?? 0), 2), 855, $y, $green, 15, true);
            $y += 94;
            imageline($image, 72, $y - 26, 1048, $y - 26, $line);
        }
        if ($rates === []) self::text($image, 'No approved payments recorded for this month.', 96, $y, $muted, 16);
        self::text($image, 'Generated ' . (string) ($report['generated_at'] ?? gmdate('Y-m-d H:i:s') . ' UTC'), 72, $height - 76, $muted, 13);
        self::text($image, 'Tech SG Studio (SMC-Pvt.) Ltd. · info@techsgstudio.com', 72, $height - 42, $navy, 14, true);
        return self::jpeg($image);
    }

    public static function renderReport(array $report): string
    {
        $rows = array_slice(is_array($report['rows'] ?? null) ? $report['rows'] : [], 0, 60);
        $metrics = array_slice(is_array($report['metrics'] ?? null) ? $report['metrics'] : [], 0, 3);
        $chart = array_slice(is_array($report['chart'] ?? null) ? $report['chart'] : [], 0, 4);
        $columns = is_array($report['columns'] ?? null) ? $report['columns'] : [];
        $height = 540 + (count($metrics) ? 150 : 0) + (count($rows) + 1) * 42 + 110;
        $image = self::canvas(max(900, $height));
        $white = self::color($image, 255, 255, 255);
        $navy = self::color($image, 15, 23, 42);
        $muted = self::color($image, 91, 107, 129);
        $blue = self::color($image, 37, 99, 235);
        $blueDark = self::color($image, 30, 64, 175);
        $pale = self::color($image, 245, 248, 255);
        $line = self::color($image, 226, 232, 240);
        imagefilledrectangle($image, 2, 2, self::WIDTH - 3, 188, $blueDark);
        imagefilledrectangle($image, 2, 2, 18, 188, $blue);
        self::text($image, 'TechSG Studio Academy', 64, 64, $white, 29, true);
        self::text($image, strtoupper((string)($report['period'] ?? 'Monthly')) . ' REPORT · ' . strtoupper((string)($report['type'] ?? '')), 64, 108, self::color($image, 219, 234, 254), 15, true);
        self::wrapped($image, (string)($report['title'] ?? 'Academy report'), 64, 154, 950, $white, 23, true, 1);
        self::text($image, (string)($report['period_range'] ?? ''), 64, 230, $navy, 19, true);
        self::text($image, 'Generated ' . (string)($report['generated_at'] ?? date('M j, Y · g:i A T')), 64, 262, $muted, 13);

        $metricTop = 296;
        if ($metrics !== []) {
            $gap = 14; $metricWidth = (976 - ($gap * (count($metrics) - 1))) / count($metrics);
            foreach ($metrics as $index => $metric) {
                $x = 72 + (int)($index * ($metricWidth + $gap));
                self::box($image, $x, $metricTop, (int)$metricWidth, 112, $pale, $line);
                self::text($image, strtoupper((string)($metric['label'] ?? '')), $x + 18, $metricTop + 32, $muted, 11, true);
                self::wrapped($image, (string)($metric['value'] ?? '—'), $x + 18, $metricTop + 76, (int)$metricWidth - 36, $blueDark, 20, true, 2);
            }
            $chartTop = 440;
            self::text($image, 'PERIOD SNAPSHOT', 72, $chartTop, $navy, 13, true);
            $values = array_map(static fn(array $item): float => max(0, (float)($item['value'] ?? 0)), $chart);
            $maxValue = max(1.0, ...$values);
            foreach ($chart as $index => $metric) {
                $rowY = $chartTop + 30 + ($index * 27);
                self::text($image, self::fitText((string)($metric['label'] ?? ''), 28), 72, $rowY, $muted, 11, true);
                imagefilledrectangle($image, 320, $rowY - 13, 900, $rowY - 2, $pale);
                $barWidth = max(2, (int)(580 * (($values[$index] ?? 0) / $maxValue)));
                imagefilledrectangle($image, 320, $rowY - 13, 320 + $barWidth, $rowY - 2, $blue);
                self::text($image, self::fitText((string)($metric['value'] ?? ''), 16), 920, $rowY, $navy, 11, true);
            }
            $tableTop = 588;
        } else $tableTop = 310;

        self::text($image, 'REPORT DETAILS', 72, $tableTop, $navy, 16, true);
        $headerY = $tableTop + 24;
        imagefilledrectangle($image, 72, $headerY, 1048, $headerY + 38, $blue);
        $count = max(1, count($columns));
        $columnWidth = (int)(976 / $count);
        foreach ($columns as $index => $column) self::text($image, self::fitText((string)$column, 20), 84 + ($index * $columnWidth), $headerY + 25, $white, 11, true);
        $y = $headerY + 66;
        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex % 2 === 0) imagefilledrectangle($image, 72, $y - 25, 1048, $y + 10, $pale);
            foreach (array_slice(is_array($row) ? $row : [], 0, $count) as $columnIndex => $value) {
                self::text($image, self::fitText((string)$value, max(11, (int)floor($columnWidth / 8))), 84 + ($columnIndex * $columnWidth), $y, $navy, 11);
            }
            $y += 42;
        }
        if ($rows === []) self::text($image, 'No records were found for this period.', 88, $y + 10, $muted, 15);
        imageline($image, 72, $height - 80, 1048, $height - 80, $line);
        self::text($image, 'TechSG Studio Academy · Internal administration report', 72, $height - 45, $muted, 12);
        self::text($image, 'JPG', 1000, $height - 45, $blue, 12, true);
        return self::jpeg($image);
    }

    private static function fitText(string $value, int $characters): string
    {
        $value = self::safeText($value);
        if (function_exists('mb_strwidth') && mb_strwidth($value, 'UTF-8') > $characters) return mb_strimwidth($value, 0, max(4, $characters - 1), '…', 'UTF-8');
        if (strlen($value) > $characters) return substr($value, 0, max(3, $characters - 1)) . '…';
        return $value;
    }

    private static function canvas(int $height): \GdImage
    {
        if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            throw new \RuntimeException('The GD extension is required to generate JPG payment documents.');
        }
        $image = imagecreatetruecolor(self::WIDTH, $height);
        imageantialias($image, true);
        imagefill($image, 0, 0, self::color($image, 255, 255, 255));
        imagerectangle($image, 1, 1, self::WIDTH - 2, $height - 2, self::color($image, 226, 232, 240));
        return $image;
    }

    private static function color(\GdImage $image, int $red, int $green, int $blue): int
    {
        return imagecolorallocate($image, $red, $green, $blue);
    }

    private static function box(\GdImage $image, int $x, int $y, int $width, int $height, int $fill, int $border): void
    {
        imagefilledrectangle($image, $x, $y, $x + $width, $y + $height, $fill);
        imagerectangle($image, $x, $y, $x + $width, $y + $height, $border);
    }

    private static function pill(\GdImage $image, string $label, int $x, int $y, int $width, int $height, int $color): void
    {
        imagefilledrectangle($image, $x + 18, $y, $x + $width - 18, $y + $height, $color);
        imagefilledellipse($image, $x + 18, $y + (int) ($height / 2), $height, $height, $color);
        imagefilledellipse($image, $x + $width - 18, $y + (int) ($height / 2), $height, $height, $color);
        self::text($image, $label, $x + 25, $y + 27, self::color($image, 255, 255, 255), 13, true);
    }

    private static function text(\GdImage $image, string $value, int $x, int $baseline, int $color, int $size = 16, bool $bold = false): void
    {
        $font = self::font($bold);
        $value = self::safeText($value);
        if ($font !== null) imagettftext($image, $size, 0, $x, $baseline, $color, $font, $value);
        else imagestring($image, 5, $x, $baseline - 18, $value, $color);
    }

    private static function wrapped(\GdImage $image, string $value, int $x, int $baseline, int $maxWidth, int $color, int $size, bool $bold, int $maxLines): void
    {
        $font = self::font($bold);
        $words = preg_split('/\s+/', self::safeText($value)) ?: [];
        $line = '';
        $lines = [];
        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            $box = $font ? imagettfbbox($size, 0, $font, $candidate) : false;
            $width = is_array($box) ? abs($box[2] - $box[0]) : strlen($candidate) * 10;
            if ($width > $maxWidth && $line !== '') {
                $lines[] = $line;
                $line = $word;
            } else $line = $candidate;
        }
        if ($line !== '') $lines[] = $line;
        foreach (array_slice($lines, 0, $maxLines) as $index => $part) self::text($image, $part, $x, $baseline + ($index * ($size + 8)), $color, $size, $bold);
    }

    private static function font(bool $bold): ?string
    {
        $path = $bold ? '/System/Library/Fonts/Supplemental/Arial Bold.ttf' : '/System/Library/Fonts/Supplemental/Arial.ttf';
        if (is_file($path)) return $path;
        $fallback = $bold ? '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf' : '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
        return is_file($fallback) ? $fallback : null;
    }

    private static function safeText(string $value): string
    {
        $value = trim(preg_replace('/[\r\n\x00-\x1F]+/u', ' ', $value) ?? '');
        return function_exists('mb_convert_encoding') ? mb_convert_encoding($value, 'UTF-8', 'UTF-8') : $value;
    }

    private static function dateLabel(mixed $value): string
    {
        if (!is_string($value) || $value === '') return gmdate('M j, Y');
        $timestamp = strtotime($value);
        return $timestamp === false ? '—' : gmdate('M j, Y', $timestamp) . ' UTC';
    }

    private static function jpeg(\GdImage $image): string
    {
        ob_start();
        imagejpeg($image, null, 92);
        $jpg = (string) ob_get_clean();
        imagedestroy($image);
        return $jpg;
    }
}
