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
