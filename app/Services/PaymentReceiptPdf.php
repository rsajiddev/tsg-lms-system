<?php

declare(strict_types=1);

namespace App\Services;

final class PaymentReceiptPdf
{
    /** Build a compact, self-contained PDF receipt using only core PHP. */
    public static function render(array $payment): string
    {
        $lines = [
            'TECH SG STUDIO',
            '(SMC-PVT.) LTD.',
            'PAYMENT RECEIPT',
            str_repeat('-', 36),
            'Receipt: ' . ($payment['receipt_number'] ?? ''),
            'Issued: ' . self::formatDate($payment['receipt_issued_at'] ?? null),
            str_repeat('-', 36),
            'STUDENT',
            (string) ($payment['student_name'] ?? ''),
            (string) ($payment['student_email'] ?? ''),
            '',
            'COURSE',
            (string) ($payment['course_title'] ?? ''),
            '',
            'PAYMENT',
            'Amount: ' . ($payment['currency'] ?? 'PKR') . ' ' . number_format((float) ($payment['amount'] ?? 0), 2),
            'Payment: manually verified',
            'Reference: ' . (string) ($payment['transaction_reference'] ?? ''),
            'Status: PAID - VERIFIED',
            str_repeat('-', 36),
            'Thank you for learning with us.',
            'info@techsgstudio.com',
            '+92 306 9524047',
            'techsgstudio.com',
        ];

        $wrapped = [];
        foreach ($lines as $line) {
            foreach (explode("\n", wordwrap($line, 36, "\n", true)) as $part) $wrapped[] = $part;
        }
        $stream = "BT\n/F1 10 Tf\n36 620 Td\n14 TL\n";
        foreach ($wrapped as $index => $line) {
            if ($index > 0) $stream .= "T*\n";
            $stream .= '(' . self::escape($line) . ") Tj\n";
        }
        $stream .= "ET\n";

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 660] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length ' . strlen($stream) . ">>\nstream\n" . $stream . "endstream",
        ];
        $pdf = "%PDF-1.4\n%TSG\n";
        $offsets = [0];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) $pdf .= sprintf("%010d 00000 n \n", $offset);
        return $pdf . 'trailer << /Size ' . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    }

    /**
     * Wrap a single rendered JPEG page into a one-page PDF. Kept for convenience; delegates to
     * fromJpeags().
     */
    public static function fromJpeg(string $jpeg): string
    {
        return self::fromJpeags([$jpeg]);
    }

    /**
     * Wrap an ordered list of already-rendered A4 JPEG pages (from PaymentReceiptJpg) into a single
     * multi-page PDF. Each JPEG is embedded directly as a DCTDecode image XObject, so the branded
     * report design is preserved exactly while the download format is a real PDF. Core PHP only.
     *
     * @param list<string> $jpegs
     */
    public static function fromJpeags(array $jpegs): string
    {
        $jpegs = array_values($jpegs);
        if ($jpegs === []) return self::fromJpeg('');
        $count = count($jpegs);

        // Object ids: 1 catalog, 2 pages tree, then a (page, image, content) triple per page.
        $pageIds = $imgIds = $contentIds = [];
        for ($i = 0; $i < $count; $i++) {
            $pageIds[$i] = 3 + $i * 3;
            $imgIds[$i] = 4 + $i * 3;
            $contentIds[$i] = 5 + $i * 3;
        }

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = implode(' ', array_map(static fn(int $id): string => $id . ' 0 R', $pageIds));
        $objects[2] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . $count . ' >>';

        foreach ($jpegs as $i => $jpeg) {
            $width = 0;
            $height = 0;
            if (!self::jpegSize($jpeg, $width, $height)) {
                $width = 2480; // A4 at 300 DPI fallback so the page still renders
                $height = 3508;
            }
            $alias = 'Im' . $i;
            $content = "q\n{$width} 0 0 {$height} 0 0 cm\n/{$alias} Do\nQ\n";
            $objects[$pageIds[$i]] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $width . ' ' . $height . '] /Resources << /XObject << /' . $alias . ' ' . $imgIds[$i] . ' 0 R >> /ProcSet [/PDF /ImageC] >> /Contents ' . $contentIds[$i] . ' 0 R >>';
            $objects[$imgIds[$i]] = '<< /Type /XObject /Subtype /Image /Width ' . $width . ' /Height ' . $height . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($jpeg) . ">>\nstream\n" . $jpeg . "\nendstream";
            $objects[$contentIds[$i]] = '<< /Length ' . strlen($content) . ">>\nstream\n" . $content . 'endstream';
        }

        ksort($objects, SORT_NUMERIC);
        $maxId = count($objects);
        $pdf = "%PDF-1.4\n%TSG\n";
        $offsets = [];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . ($maxId + 1) . "\n0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        return $pdf . 'trailer << /Size ' . ($maxId + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    }

    /** Read the pixel dimensions from the JPEG's start-of-frame marker. */
    private static function jpegSize(string $jpeg, int &$width, int &$height): bool
    {
        $length = strlen($jpeg);
        if ($length < 4 || substr($jpeg, 0, 2) !== "\xFF\xD8") return false;
        $i = 2;
        while ($i < $length - 9) {
            if ($jpeg[$i] !== "\xFF") { $i++; continue; }
            $marker = ord($jpeg[$i + 1]);
            if ($marker === 0xD8 || $marker === 0x01 || ($marker >= 0xF0 && $marker <= 0xF7) || $marker === 0xFF) { $i += 2; continue; }
            if ($marker === 0xD9) break;
            if (($marker >= 0xC0 && $marker <= 0xCF) && !in_array($marker, [0xC4, 0xC8, 0xCC], true)) {
                $height = (ord($jpeg[$i + 5]) << 8) | ord($jpeg[$i + 6]);
                $width = (ord($jpeg[$i + 7]) << 8) | ord($jpeg[$i + 8]);
                return $width > 0 && $height > 0;
            }
            $i += 2 + ((ord($jpeg[$i + 2]) << 8) | ord($jpeg[$i + 3]));
        }
        return false;
    }

    private static function escape(string $value): string
    {
        $ascii = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : $value;
        $ascii = is_string($ascii) ? $ascii : '';
        $ascii = preg_replace('/[^\x20-\x7E]/', '', $ascii) ?? '';
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ascii);
    }

    private static function formatDate(mixed $value): string
    {
        if (!is_string($value) || $value === '') return '';
        $timestamp = strtotime($value . ' UTC');
        return $timestamp === false ? '' : gmdate('M j, Y H:i', $timestamp) . ' UTC';
    }
}
