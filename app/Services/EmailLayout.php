<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Renders every automated email in one branded layout so a recipient sees the same
 * structure regardless of which feature triggered the message.
 *
 * A message is described as a plain "view" array and rendered twice: as HTML and as
 * the matching plain-text fallback. Callers never build markup themselves.
 *
 * Recognised view keys:
 *   greeting   string  opening line, e.g. "Dear Raza Ullah,"
 *   preheader  string  inbox preview text shown above the message body
 *   paragraphs array   narrative blocks, rendered in order
 *   code       string  optional one-time code rendered as a large centred block
 *   heading    string  optional section title shown above "details" or "table"
 *   details    array   [['label' => ..., 'value' => ...], ...] key/value panel
 *   table      array   ['columns' => [...], 'rows' => [[...], ...], 'footer' => ...]
 *   notice     string  optional highlighted warning line
 *   cta        array   ['label' => ..., 'url' => ...] single action button
 *   closing    array   narrative blocks rendered after the panel/table
 *   legal      bool    append the company privacy / terms / refund links
 *   signature  self::SIGN_* selects the closing signature block
 */
final class EmailLayout
{
    public const SIGN_HR = 'hr';
    public const SIGN_SYSTEM = 'system';
    public const SIGN_ACADEMY = 'academy';

    private const NAVY = '#0f172a';
    private const BLUE = '#2563eb';
    private const TEXT = '#334155';
    private const MUTED = '#64748b';
    private const BORDER = '#e2e8f0';
    private const PAGE = '#f8fafc';
    private const GREEN = '#059669';

    /** Company identity printed in the footer; overridable from the environment. */
    public static function company(): array
    {
        $site = rtrim((string) (getenv('COMPANY_SITE_URL') ?: 'https://techsgstudio.com'), '/');
        return [
            'name' => trim((string) (getenv('COMPANY_LEGAL_NAME') ?: 'Tech SG Studio (SMC-Private) Limited')),
            'short' => trim((string) (getenv('COMPANY_NAME') ?: 'Tech SG Studio')),
            'brand' => trim((string) (getenv('MAIL_FROM_NAME') ?: 'Tech SG Studio')),
            'site' => $site,
            'contact' => trim((string) (getenv('MAIL_REPLY_TO_ADDRESS') ?: getenv('MAIL_FROM_ADDRESS') ?: '')),
            'privacy' => $site . '/privacy-policy',
            'terms' => $site . '/terms',
            'refund' => $site . '/refund-policy',
        ];
    }

    public static function html(string $subject, array $view): string
    {
        $company = self::company();
        $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $rows = '';

        if (($view['greeting'] ?? '') !== '') {
            $rows .= self::block('<p style="margin:0 0 16px;font-size:15px;line-height:26px;color:' . self::TEXT . ';">' . $e((string) $view['greeting']) . '</p>');
        }
        $rows .= self::paragraphs($view['paragraphs'] ?? [], $e);

        $panel = '';
        if (($view['code'] ?? '') !== '') {
            $panel .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;margin:0 0 14px;"><tr><td align="center" style="background:#ffffff;border:1px dashed ' . self::BLUE . ';border-radius:12px;padding:18px;">'
                . '<span style="font-size:32px;font-weight:800;letter-spacing:10px;color:' . self::NAVY . ';font-family:Menlo,Consolas,monospace;">' . $e((string) $view['code']) . '</span></td></tr></table>';
        }
        if (($view['heading'] ?? '') !== '') {
            $panel .= '<p style="margin:0 0 10px;font-size:13px;letter-spacing:1.2px;text-transform:uppercase;color:' . self::MUTED . ';">' . $e((string) $view['heading']) . '</p>';
        }
        $panel .= self::detailsTable($view['details'] ?? [], $e);
        $panel .= self::dataTable($view['table'] ?? [], $e);
        if ($panel !== '') {
            $rows .= self::block('<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;border:1px solid ' . self::BORDER . ';border-radius:12px;background:' . self::PAGE . ';margin:0 0 20px;"><tr><td style="padding:18px 20px;">' . $panel . '</td></tr></table>');
        }

        if (($view['notice'] ?? '') !== '') {
            $rows .= self::block('<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;border-left:4px solid ' . self::BLUE . ';background:#eff6ff;margin:0 0 20px;"><tr><td style="padding:12px 16px;font-size:14px;line-height:23px;color:#1e3a8a;">' . $e((string) $view['notice']) . '</td></tr></table>');
        }
        $rows .= self::paragraphs($view['closing'] ?? [], $e);

        if (isset($view['cta']['label'], $view['cta']['url'])) {
            $rows .= self::block('<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:separate;margin:0 0 22px;"><tr><td style="background:' . self::BLUE . ';border-radius:9px;"><a href="' . $e((string) $view['cta']['url']) . '" style="display:inline-block;padding:12px 22px;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;">' . $e((string) $view['cta']['label']) . '</a></td></tr></table>');
        }

        if (!empty($view['legal'])) {
            $rows .= self::block(
                '<p style="margin:0 0 8px;font-size:13px;font-weight:700;color:' . self::NAVY . ';">Before you continue, please read our legal information:</p>'
                . self::legalLink($company['privacy'], 'Privacy Policy', $e)
                . self::legalLink($company['terms'], 'Terms &amp; Conditions', $e)
                . self::legalLink($company['refund'], 'Refund Policy', $e)
            );
        }

        $signature = self::signature((string) ($view['signature'] ?? self::SIGN_HR), $company, $e);

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $e($subject) . '</title></head>'
            . '<body style="margin:0;padding:0;background:' . self::PAGE . ';">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:' . self::PAGE . ';padding:24px 12px;">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="border-collapse:collapse;max-width:640px;width:100%;background:#ffffff;border:1px solid ' . self::BORDER . ';border-radius:16px;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">'
            . '<tr><td style="background:' . self::NAVY . ';padding:22px 28px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
            . '<td style="font-size:16px;font-weight:800;letter-spacing:2px;color:#ffffff;">' . $e($company['brand']) . '</td>'
            . '<td align="right" style="font-size:12px;letter-spacing:1.4px;text-transform:uppercase;color:#93c5fd;">' . $e(self::audienceLabel((string) ($view['audience'] ?? ''))) . '</td>'
            . '</tr></table></td></tr>'
            . '<tr><td style="padding:28px;">' . $rows . '</td></tr>'
            . '<tr><td style="border-top:1px solid ' . self::BORDER . ';padding:20px 28px;background:#ffffff;">'
            . $signature
            . '<p style="margin:14px 0 0;font-size:11px;line-height:18px;color:' . self::MUTED . ';">This message was generated automatically by the ' . $e($company['name']) . ' learning platform. Please do not reply to this inbox; use ' . ($company['contact'] !== '' ? '<a href="mailto:' . $e($company['contact']) . '" style="color:' . self::MUTED . ';text-decoration:underline;">' . $e($company['contact']) . '</a>' : 'the contact address on our website') . ' for support.</p>'
            . '</td></tr></table></td></tr></table></body></html>';
    }

    public static function text(string $subject, array $view): string
    {
        $company = self::company();
        $lines = [self::textHeader($subject, $company)];
        if (($view['greeting'] ?? '') !== '') {
            $lines[] = (string) $view['greeting'];
            $lines[] = '';
        }
        foreach (self::textParagraphs($view['paragraphs'] ?? []) as $line) $lines[] = $line;
        if (($view['code'] ?? '') !== '') {
            $lines[] = str_repeat('=', 30);
            $lines[] = 'CODE:  ' . strtoupper((string) $view['code']);
            $lines[] = str_repeat('=', 30);
            $lines[] = '';
        }
        if (($view['heading'] ?? '') !== '') {
            $lines[] = strtoupper((string) $view['heading']);
            $lines[] = '';
        }
        foreach (($view['details'] ?? []) as $detail) {
            if (!is_array($detail)) continue;
            $lines[] = trim((string) ($detail['label'] ?? '')) . ': ' . trim((string) ($detail['value'] ?? ''));
        }
        if (($view['details'] ?? []) !== []) $lines[] = '';
        $table = $view['table'] ?? [];
        if (is_array($table) && ($table['rows'] ?? []) !== []) {
            foreach ((array) ($table['columns'] ?? []) as $index => $column) {
                $lines[] = '- ' . trim((string) $column) . ' #' . (int) $index;
            }
            foreach ((array) $table['rows'] as $row) {
                $lines[] = '';
                foreach ((array) ($table['columns'] ?? []) as $index => $column) {
                    $value = $row[$index] ?? '';
                    $lines[] = trim((string) $column) . ': ' . (is_scalar($value) ? (string) $value : '');
                }
            }
            $lines[] = '';
            if (($table['footer'] ?? '') !== '') $lines[] = (string) $table['footer'];
            $lines[] = '';
        }
        if (($view['notice'] ?? '') !== '') {
            $lines[] = 'PLEASE NOTE: ' . (string) $view['notice'];
            $lines[] = '';
        }
        foreach (self::textParagraphs($view['closing'] ?? []) as $line) $lines[] = $line;
        if (isset($view['cta']['label'], $view['cta']['url'])) {
            $lines[] = (string) $view['cta']['label'] . ': ' . $view['cta']['url'];
            $lines[] = '';
        }
        if (!empty($view['legal'])) {
            $lines[] = 'Before you continue, please read our legal information:';
            $lines[] = 'Privacy Policy: ' . $company['privacy'];
            $lines[] = 'Terms & Conditions: ' . $company['terms'];
            $lines[] = 'Refund Policy: ' . $company['refund'];
            $lines[] = '';
        }
        foreach (self::signatureLines((string) ($view['signature'] ?? self::SIGN_HR), $company) as $line) $lines[] = $line;
        $lines[] = '';
        $lines[] = '---';
        $lines[] = 'Automated message from ' . $company['name'] . '. Do not reply to this inbox.';

        return implode("\r\n", preg_replace('/[ \t]+$/m', '', $lines) ?? $lines);
    }

    private static function textHeader(string $subject, array $company): string
    {
        return $company['name'] . ' | ' . $subject;
    }

    private static function paragraphs(array $items, callable $e): string
    {
        $html = '';
        foreach ($items as $paragraph) {
            if (!is_scalar($paragraph) || trim((string) $paragraph) === '') continue;
            $html .= self::block('<p style="margin:0 0 16px;font-size:15px;line-height:26px;color:' . self::TEXT . ';">' . $e(trim((string) $paragraph)) . '</p>');
        }
        return $html;
    }

    private static function textParagraphs(array $items): array
    {
        $lines = [];
        foreach ($items as $paragraph) {
            if (!is_scalar($paragraph) || trim((string) $paragraph) === '') continue;
            $lines[] = wordwrap(trim((string) $paragraph), 88, "\r\n", true);
            $lines[] = '';
        }
        return $lines;
    }

    private static function detailsTable(array $details, callable $e): string
    {
        $html = '';
        foreach ($details as $detail) {
            if (!is_array($detail) || trim((string) ($detail['value'] ?? '')) === '') continue;
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">'
                . '<tr><td width="42%" style="padding:7px 12px 7px 0;border-bottom:1px solid ' . self::BORDER . ';font-size:13px;color:' . self::MUTED . ';">' . $e(trim((string) ($detail['label'] ?? ''))) . '</td>'
                . '<td style="padding:7px 0;border-bottom:1px solid ' . self::BORDER . ';font-size:14px;font-weight:600;color:' . self::NAVY . ';">' . $e(trim((string) $detail['value'])) . '</td></tr></table>';
        }
        return $html;
    }

    private static function dataTable(array $table, callable $e): string
    {
        if (!is_array($table) || ($table['rows'] ?? []) === []) return '';
        $columns = (array) ($table['columns'] ?? []);
        $head = '';
        foreach ($columns as $column) {
            $head .= '<th align="left" style="padding:8px 10px;background:' . self::NAVY . ';font-size:12px;text-transform:uppercase;letter-spacing:.6px;color:#ffffff;">' . $e(trim((string) $column)) . '</th>';
        }
        $body = '';
        foreach ((array) $table['rows'] as $row) {
            $cells = '';
            foreach ($columns as $index => $column) {
                $value = $row[$index] ?? '';
                $cells .= '<td style="padding:9px 10px;border-bottom:1px solid ' . self::BORDER . ';font-size:13px;color:' . self::TEXT . ';">' . $e(is_scalar($value) ? trim((string) $value) : '') . '</td>';
            }
            $body .= '<tr>' . $cells . '</tr>';
        }
        $foot = ($table['footer'] ?? '') !== ''
            ? '<tr><td colspan="' . count($columns) . '" style="padding:10px;font-size:13px;font-weight:700;color:' . self::GREEN . ';">' . $e((string) $table['footer']) . '</td></tr>'
            : '';
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:6px 0 4px;border:1px solid ' . self::BORDER . ';"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . $foot . '</tbody></table>';
    }

    private static function legalLink(string $url, string $label, callable $e): string
    {
        return '<p style="margin:0 0 6px;font-size:14px;line-height:22px;color:' . self::TEXT . ';">'
            . $label . ': <a href="' . $e($url) . '" style="color:' . self::BLUE . ';text-decoration:underline;">' . $e($url) . '</a></p>';
    }

    private static function block(string $html): string
    {
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;"><tr><td>' . $html . '</td></tr></table>';
    }

    private static function audienceLabel(string $audience): string
    {
        return match (strtolower($audience)) {
            'student' => 'Student message',
            'hr' => 'Internal · HR',
            'admin' => 'Internal · Admin',
            'developer' => 'Internal · Developer',
            default => 'Notification',
        };
    }

    private static function signature(string $kind, array $company, callable $e): string
    {
        $department = $kind === self::SIGN_SYSTEM
            ? 'LMS Automated System'
            : ($kind === self::SIGN_ACADEMY ? 'Tech SG Academy Team' : 'Human Resources Department');
        $contact = $kind === self::SIGN_HR && $company['contact'] !== ''
            ? '<p style="margin:0;font-size:13px;line-height:22px;color:' . self::MUTED . ';">' . $e($company['contact']) . '</p>'
            : '';
        return '<p style="margin:0 0 4px;font-size:14px;color:' . self::TEXT . ';">Best regards,</p>'
            . '<p style="margin:0;font-size:14px;font-weight:700;color:' . self::NAVY . ';">' . $e($department) . '</p>'
            . '<p style="margin:0;font-size:13px;color:' . self::MUTED . ';">' . $e($company['name']) . '</p>'
            . $contact
            . '<p style="margin:0;font-size:13px;"><a href="' . $e($company['site']) . '" style="color:' . self::BLUE . ';text-decoration:underline;">' . $e(str_replace('https://', '', $company['site'])) . '</a></p>';
    }

    private static function signatureLines(string $kind, array $company): array
    {
        $department = $kind === self::SIGN_SYSTEM
            ? 'LMS Automated System'
            : ($kind === self::SIGN_ACADEMY ? 'Tech SG Academy Team' : 'Human Resources Department');
        $lines = ['', 'Best regards,', ' ' . $department, ' ' . $company['name']];
        if ($kind === self::SIGN_HR && $company['contact'] !== '') $lines[] = ' ' . $company['contact'];
        $lines[] = ' ' . str_replace('https://', '', $company['site']);
        return $lines;
    }
}
