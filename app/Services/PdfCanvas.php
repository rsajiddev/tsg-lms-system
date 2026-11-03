<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Dependency-free vector PDF canvas.
 *
 * Draws text and shapes straight into a PDF content stream using only core PHP: no GD, no JPEG,
 * no third-party library. The output is a real, multi-page, selectable-text vector PDF.
 *
 * Coordinate system mirrors the raster renderer it replaced: an 827 x 1169 page (A4 portrait in
 * "large points") addressed with a top-left origin, so existing layout numbers port 1:1. Every
 * primitive flips Y internally (PDF grows upward).
 *
 * Text uses the core Type1 fonts (Helvetica / Times) with WinAnsiEncoding, which already covers
 * the characters these documents use (letters, digits, PKR, the middle dot, em dash, ellipsis),
 * so no TrueType embedding is required. Advance widths come from a compact metrics table so
 * centring, right-alignment and truncation stay accurate.
 */
final class PdfCanvas
{
    public const PAGE_WIDTH = 827;
    public const PAGE_HEIGHT = 1169;

    /** @var list<string> one content stream buffer per page */
    private array $pages = [];

    /* ------------------------------------------------------------------ core fonts */

    /**
     * Advance widths (per 1000 units) for the printable ASCII range 32..126, indexed in order.
     * Values are the standard Adobe AFM metrics for each core font.
     */
    private const WIDTHS = [
        'helv' => [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,722,667,722,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584],
        'helvB' => [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,556,500,556,556,278,556,556,222,278,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,333,260,333,584],
        'times' => [250,333,408,500,458,864,499,180,333,333,500,541,250,333,250,278,500,500,500,500,500,500,500,500,500,500,272,272,541,564,541,444,921,722,667,667,722,611,556,722,722,333,333,722,611,833,722,722,611,722,722,556,611,722,667,944,667,667,611,333,278,333,469,556,333,444,500,444,500,444,333,500,500,222,333,500,278,778,500,500,500,500,333,389,278,500,500,722,500,500,500,334,260,334,500],
        'timesB' => [250,333,555,500,500,1000,500,278,333,333,500,570,250,333,250,278,500,500,500,500,500,500,500,500,500,500,333,333,570,570,570,500,930,722,667,722,722,667,611,778,778,389,500,778,611,889,722,778,667,778,722,556,611,722,722,944,722,722,611,333,278,333,581,556,333,500,556,444,556,444,333,500,556,278,333,556,278,833,556,556,500,556,333,389,333,556,500,722,500,500,500,390,333,390,570],
    ];

    /** Supplementary widths for the WinAnsi high-byte glyphs the documents use. */
    private const SPECIALS = [
        'helv' => [133 => 1000, 145 => 222, 146 => 222, 147 => 333, 148 => 333, 150 => 556, 151 => 1000, 183 => 278],
        'helvB' => [133 => 1000, 145 => 222, 146 => 222, 147 => 333, 148 => 333, 150 => 556, 151 => 1000, 183 => 278],
        'times' => [133 => 1000, 145 => 222, 146 => 222, 147 => 333, 148 => 333, 150 => 500, 151 => 889, 183 => 250],
        'timesB' => [133 => 1000, 145 => 278, 146 => 278, 147 => 390, 148 => 390, 150 => 500, 151 => 944, 183 => 250],
    ];

    /** @var array<string,array<int,int>> ord => width maps, built once per font key */
    private static array $metricCache = [];

    /* ------------------------------------------------------------------ pages */

    public function newPage(): void
    {
        $this->pages[] = '';
    }

    private function emit(string $chunk): void
    {
        if ($this->pages === []) {
            $this->newPage();
        }
        $last = array_key_last($this->pages);
        $this->pages[$last] .= $chunk;
    }

    /* ------------------------------------------------------------------ shapes */

    public function rect(float $x, float $y, float $w, float $h, array $rgb): void
    {
        $this->emit(sprintf(
            "%s rg\n%s %s %s %s re f\n",
            self::rgb($rgb),
            self::n($x), self::n(self::PAGE_HEIGHT - $y - $h), self::n($w), self::n($h)
        ));
    }

    /**
     * Rounded rectangle. Radius is clamped to half the shorter side so degenerate requests still
     * draw a clean shape instead of folding the corner beziers past each other.
     */
    public function roundRect(float $x, float $y, float $w, float $h, float $r, ?array $fill, ?array $stroke = null): void
    {
        $r = max(0.0, min($r, $w / 2, $h / 2));
        $k = 0.5523 * $r;
        $segs = [
            ['m', $x + $r, $y],
            ['l', $x + $w - $r, $y],
            ['c', $x + $w - $r + $k, $y, $x + $w, $y + $r - $k, $x + $w, $y + $r],
            ['l', $x + $w, $y + $h - $r],
            ['c', $x + $w, $y + $h - $r + $k, $x + $w - $r + $k, $y + $h, $x + $w - $r, $y + $h],
            ['l', $x + $r, $y + $h],
            ['c', $x + $r - $k, $y + $h, $x, $y + $h - $r + $k, $x, $y + $h - $r],
            ['l', $x, $y + $r],
            ['c', $x, $y + $r - $k, $x + $r - $k, $y, $x + $r, $y],
            ['h'],
        ];
        $this->path($segs, $fill, $stroke);
    }

    public function circle(float $cx, float $cy, float $diameter, ?array $fill, ?array $stroke = null): void
    {
        $rr = $diameter / 2;
        $k = 0.5523 * $rr;
        $segs = [
            ['m', $cx - $rr, $cy],
            ['c', $cx - $rr, $cy - $k, $cx - $k, $cy + $rr, $cx, $cy + $rr],
            ['c', $cx + $k, $cy + $rr, $cx + $rr, $cy - $k, $cx + $rr, $cy],
            ['c', $cx + $rr, $cy + $k, $cx + $k, $cy - $rr, $cx, $cy - $rr],
            ['c', $cx - $k, $cy - $rr, $cx - $rr, $cy + $k, $cx - $rr, $cy],
            ['h'],
        ];
        $this->path($segs, $fill, $stroke);
    }

    public function line(float $x1, float $y1, float $x2, float $y2, array $rgb, float $thickness = 1): void
    {
        $this->emit(sprintf(
            "%s RG\n%s w\n%s %s m %s %s l S\n",
            self::rgb($rgb), self::n(max(0.5, $thickness)),
            self::n($x1), self::n(self::PAGE_HEIGHT - $y1),
            self::n($x2), self::n(self::PAGE_HEIGHT - $y2)
        ));
    }

    public function dashedLine(float $x1, float $x2, float $y, array $rgb, float $dash = 8, float $gap = 6, float $thickness = 2): void
    {
        $this->emit(sprintf(
            "%s RG\n%s w\n[%s %s] 0 d\n%s %s m %s %s l S\n[] 0 d\n",
            self::rgb($rgb), self::n(max(0.5, $thickness)), self::n($dash), self::n($gap),
            self::n($x1), self::n(self::PAGE_HEIGHT - $y),
            self::n($x2), self::n(self::PAGE_HEIGHT - $y)
        ));
    }

    /**
     * Assemble a path from move/line/curve/close segments and paint it. Coordinates are supplied in
     * the top-left space; each pair is flipped here so every shape helper stays origin-consistent.
     */
    private function path(array $segs, ?array $fill, ?array $stroke): void
    {
        $op = ($fill !== null && $stroke !== null) ? 'B' : ($stroke !== null ? 'S' : 'f');
        $out = '';
        if ($fill !== null) {
            $out .= self::rgb($fill) . " rg\n";
        }
        if ($stroke !== null) {
            $out .= self::rgb($stroke) . " RG\n1 w\n";
        }
        foreach ($segs as $seg) {
            $cmd = $seg[0];
            if ($cmd === 'h') {
                $out .= "h\n";
                continue;
            }
            $coords = [];
            $points = array_slice($seg, 1);
            for ($i = 0, $n = count($points); $i < $n; $i += 2) {
                $coords[] = self::n($points[$i]);
                $coords[] = self::n(self::PAGE_HEIGHT - $points[$i + 1]);
            }
            $out .= implode(' ', $coords) . ' ' . $cmd . "\n";
        }
        $this->emit($out . $op . "\n");
    }

    /* ------------------------------------------------------------------ text */

    public function text(string $value, float $x, float $baselineY, array $rgb, float $size, bool $bold = false, string $family = 'sans', string $align = 'left'): void
    {
        $bytes = self::toWinAnsi($value);
        if ($bytes === '') {
            return;
        }
        $width = self::measure($bytes, $size, $family, $bold);
        $drawX = $x;
        if ($align === 'center') {
            $drawX = $x - $width / 2;
        } elseif ($align === 'right') {
            $drawX = $x - $width;
        }
        $font = ['helv' => '/F1', 'helvB' => '/F2', 'times' => '/F3', 'timesB' => '/F4'][self::fontKey($family, $bold)];
        $this->emit(sprintf(
            "%s rg\nBT %s %s Tf %s %s Td (%s) Tj ET\n",
            self::rgb($rgb), $font, self::n($size),
            self::n($drawX), self::n(self::PAGE_HEIGHT - $baselineY),
            self::escape($bytes)
        ));
    }

    public function textWidth(string $value, float $size, bool $bold, string $family = 'sans'): int
    {
        return (int) ceil(self::measure(self::toWinAnsi($value), $size, $family, $bold));
    }

    /** Trim a string with an ellipsis until it fits the given width, mirroring the raster renderer. */
    public function fit(string $value, float $size, bool $bold, string $family, float $maxWidth): string
    {
        $value = trim(preg_replace('/[\r\n\x00-\x1F]+/u', ' ', $value) ?? '');
        if ($value === '' || $this->textWidth($value, $size, $bold, $family) <= $maxWidth) {
            return $value;
        }
        while ($value !== '' && $this->textWidth($value . '…', $size, $bold, $family) > $maxWidth) {
            $value = mb_substr($value, 0, mb_strlen($value) - 1);
        }
        return $value . '…';
    }

    /* ------------------------------------------------------------------ metrics helpers */

    private static function fontKey(string $family, bool $bold): string
    {
        return match ($family) {
            'serif' => $bold ? 'timesB' : 'times',
            default => $bold ? 'helvB' : 'helv',
        };
    }

    private static function measure(string $bytes, float $size, string $family, bool $bold): float
    {
        $table = self::metrics(self::fontKey($family, $bold));
        $total = 0;
        for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
            $total += $table[ord($bytes[$i])] ?? 500;
        }
        return $total / 1000 * $size;
    }

    /** @return array<int,int> ord => advance width for a font key */
    private static function metrics(string $key): array
    {
        if (isset(self::$metricCache[$key])) {
            return self::$metricCache[$key];
        }
        $ascii = array_combine(range(32, 126), self::WIDTHS[$key]);
        self::$metricCache[$key] = $ascii + self::SPECIALS[$key];
        return self::$metricCache[$key];
    }

    /** Normalise control characters and convert UTF-8 to the WinAnsi (cp1252) bytes PDF expects. */
    private static function toWinAnsi(string $value): string
    {
        $value = trim(preg_replace('/[\r\n\x00-\x1F]+/u', ' ', $value) ?? '');
        if ($value === '') {
            return '';
        }
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $value);
            if (is_string($converted)) {
                return $converted;
            }
        }
        return $value;
    }

    private static function escape(string $bytes): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $bytes);
    }

    /* ------------------------------------------------------------------ output */

    private static function rgb(array $rgb): string
    {
        return sprintf('%.3F %.3F %.3F', $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255);
    }

    /** Compact number formatting: at most two decimals, no trailing zeros, no thousands sep. */
    private static function n(float $value): string
    {
        $s = number_format($value, 2, '.', '');
        $s = rtrim(rtrim($s, '0'), '.');
        return ($s === '' || $s === '-') ? '0' : $s;
    }

    public function output(): string
    {
        $count = max(1, count($this->pages));
        if ($this->pages === []) {
            $this->newPage();
        }

        // Object ids: 1 catalog, 2 pages tree, 3-6 fonts, then a (page, content) pair per page.
        $pageIds = $contentIds = [];
        for ($i = 0; $i < $count; $i++) {
            $pageIds[$i] = 7 + $i * 2;
            $contentIds[$i] = 8 + $i * 2;
        }

        $fontResource = '<< /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R /F4 6 0 R >> >>';
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = implode(' ', array_map(static fn(int $id): string => $id . ' 0 R', $pageIds));
        $objects[2] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . $count . ' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Times-Roman /Encoding /WinAnsiEncoding >>';
        $objects[6] = '<< /Type /Font /Subtype /Type1 /BaseFont /Times-Bold /Encoding /WinAnsiEncoding >>';

        for ($i = 0; $i < $count; $i++) {
            $stream = $this->pages[$i];
            $objects[$pageIds[$i]] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::PAGE_WIDTH . ' ' . self::PAGE_HEIGHT . '] /Resources ' . $fontResource . ' /Contents ' . $contentIds[$i] . ' 0 R >>';
            $objects[$contentIds[$i]] = '<< /Length ' . strlen($stream) . ">>\nstream\n" . $stream . 'endstream';
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
        $pdf .= 'xref' . "\n0 " . ($maxId + 1) . "\n0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        return $pdf . 'trailer << /Size ' . ($maxId + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    }
}
