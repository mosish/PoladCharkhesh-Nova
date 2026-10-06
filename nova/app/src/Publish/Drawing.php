<?php

declare(strict_types=1);

namespace Nova\Publish;

/**
 * Generated section drawings (05 §26: code-generated, no user markup, inline):
 * a PHP port of the frozen Phase 2 workspace drawing (ws.js), drawn from one
 * designation's boundary dimensions. Never mirrored (`.dwg-svg` is LTR).
 * Returns null when the needed dimensions are not all present.
 */
final class Drawing
{
    /** @param array<string, float> $dims symbol => value (mm) */
    public static function svg(string $family, array $dims, string $label, bool $big = false, int $fontSize = 0): ?string
    {
        $W = 440;
        $H = $big ? 330 : 260;
        $fs = $fontSize > 0 ? $fontSize : ($big ? 13 : 12);
        $aria = self::e($label);

        if ($family === 'unit') {
            if (!isset($dims['d'])) {
                return null;
            }
            $s = $big ? 5 : 4;
            $ox = $W / 2 - 32 * $s;
            $oy = 14;
            $cx = $ox + 32 * $s;
            $cy = $oy + 25 * $s;
            $r = 5 * $s;

            return '<svg class="dwg-svg" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . $aria . '">'
                . '<g style="color:var(--line)"><use href="#g-unit" x="' . self::n($ox) . '" y="' . $oy . '" width="' . 64 * $s . '" height="' . 48 * $s . '"/></g>'
                . '<path class="ln-ctr" d="M' . self::n($cx - $r - 30) . ' ' . self::n($cy) . 'H' . self::n($cx + $r + 30) . 'M' . self::n($cx) . ' ' . self::n($cy - $r - 30) . 'V' . self::n($cy + $r + 30) . '"/>'
                . '<path class="ln-dim" d="M' . self::n($cx - $r) . ' ' . self::n($cy - $r - 16) . 'H' . self::n($cx + $r) . '" marker-start="url(#ar)" marker-end="url(#ar)"/>'
                . '<path class="ln-dim" d="M' . self::n($cx - $r) . ' ' . self::n($cy) . 'V' . self::n($cy - $r - 20) . 'M' . self::n($cx + $r) . ' ' . self::n($cy) . 'V' . self::n($cy - $r - 20) . '"/>'
                . '<text class="dim-t" x="' . self::n($cx) . '" y="' . self::n($cy - $r - 22) . '" text-anchor="middle" font-size="' . $fs . '">Ø' . self::fmt($dims['d']) . '</text></svg>';
        }

        $D = $dims['D'] ?? null;
        $d0 = $dims['d'] ?? $dims['Fw'] ?? null;
        $B = $dims['B'] ?? $dims['T'] ?? $dims['C'] ?? $dims['H'] ?? $dims['b'] ?? null;
        if ($D === null || $d0 === null || $B === null || $D <= $d0 || $B <= 0) {
            return null;
        }
        $s = min(($H - 92) / $D, ($big ? 170 : 140) / $B);
        $cx = $W / 2 - 6;
        $cy = ($H - 44) / 2 + 4;
        $y = static fn(float $r): float => $cy - $r * $s;
        $m = static fn(float $v): float => 2 * $cy - $v;
        $w = $B * $s;
        $x0 = $cx - $w / 2;
        $x1 = $cx + $w / 2;
        $t = ($D - $d0) / 2;
        $rO = $D / 2;
        $rI = $d0 / 2;
        $rect = static fn(float $xa, float $xb, float $ra, float $rb): array => [[$xa, $y($ra)], [$xb, $y($ra)], [$xb, $y($rb)], [$xa, $y($rb)]];

        $g = '';
        switch ($family) {
            case 'ball':
                $g .= self::both($cy, $rect($x0, $x1, $rO, $rO - .32 * $t), 'fill-sec') . self::both($cy, $rect($x0, $x1, $rI + .32 * $t, $rI), 'fill-sec');
                $rb = min(.24 * $t, .42 * $B) * $s;
                $rp = ($rO + $rI) / 2;
                $g .= '<circle class="fill-paper" cx="' . self::n($cx) . '" cy="' . self::n($y($rp)) . '" r="' . self::n($rb) . '"/><circle class="fill-paper" cx="' . self::n($cx) . '" cy="' . self::n($m($y($rp))) . '" r="' . self::n($rb) . '"/>';
                break;
            case 'cylindrical':
                $k = .13 * $w;
                $g .= self::both($cy, [[$x0, $y($rO)], [$x1, $y($rO)], [$x1, $y($rO - .42 * $t)], [$x1 - $k, $y($rO - .42 * $t)], [$x1 - $k, $y($rO - .3 * $t)], [$x0 + $k, $y($rO - .3 * $t)], [$x0 + $k, $y($rO - .42 * $t)], [$x0, $y($rO - .42 * $t)]], 'fill-sec');
                $g .= self::both($cy, $rect($x0 + $k + 2, $x1 - $k - 2, $rO - .3 * $t, $rI + .3 * $t), 'fill-paper') . self::both($cy, $rect($x0, $x1, $rI + .3 * $t, $rI), 'fill-sec');
                break;
            case 'tapered':
                $cupY = static fn(float $x): float => $y($rO - .38 * $t) + ($y($rO - .22 * $t) - $y($rO - .38 * $t)) * ($x - $x0) / $w;
                $coneY = static fn(float $x): float => $y($rI + .26 * $t) + ($y($rI + .42 * $t) - $y($rI + .26 * $t)) * ($x - $x0) / $w;
                $xa = $x0 + .12 * $w;
                $xb = $x1 - .12 * $w;
                $g .= self::both($cy, [[$x0, $y($rO)], [$x1, $y($rO)], [$x1, $y($rO - .22 * $t)], [$x0, $y($rO - .38 * $t)]], 'fill-sec');
                $g .= self::both($cy, [[$x0, $y($rI)], [$x1, $y($rI)], [$x1, $y($rI + .42 * $t)], [$x0, $y($rI + .26 * $t)]], 'fill-sec');
                $g .= self::both($cy, [[$xa, $cupY($xa) + 1.5], [$xb, $cupY($xb) + 1.5], [$xb, $coneY($xb) - 1.5], [$xa, $coneY($xa) - 1.5]], 'fill-paper');
                break;
            case 'spherical':
                $top = 'M' . self::n($x0) . ' ' . self::n($y($rO)) . 'H' . self::n($x1) . 'V' . self::n($y($rO - .3 * $t)) . 'Q' . self::n($cx) . ' ' . self::n($y($rO - .12 * $t)) . ' ' . self::n($x0) . ' ' . self::n($y($rO - .3 * $t)) . 'Z';
                $bot = 'M' . self::n($x0) . ' ' . self::n($m($y($rO))) . 'H' . self::n($x1) . 'V' . self::n($m($y($rO - .3 * $t))) . 'Q' . self::n($cx) . ' ' . self::n($m($y($rO - .12 * $t))) . ' ' . self::n($x0) . ' ' . self::n($m($y($rO - .3 * $t))) . 'Z';
                $g .= '<path class="fill-sec" d="' . $top . '"/><path class="fill-sec" d="' . $bot . '"/>';
                $g .= self::both($cy, [[$x0, $y($rI)], [$x1, $y($rI)], [$x1, $y($rI + .26 * $t)], [$cx + .06 * $w, $y($rI + .26 * $t)], [$cx + .06 * $w, $y($rI + .4 * $t)], [$cx - .06 * $w, $y($rI + .4 * $t)], [$cx - .06 * $w, $y($rI + .26 * $t)], [$x0, $y($rI + .26 * $t)]], 'fill-sec');
                $rp = $rI + .58 * $t;
                $rx = .2 * $w;
                $ry = .15 * $t * $s;
                foreach ([[-1, -12], [1, 12]] as [$sx, $a]) {
                    $ex = $cx + $sx * .23 * $w;
                    $g .= '<ellipse class="fill-paper" cx="' . self::n($ex) . '" cy="' . self::n($y($rp)) . '" rx="' . self::n($rx) . '" ry="' . self::n($ry) . '" transform="rotate(' . $a . ' ' . self::n($ex) . ' ' . self::n($y($rp)) . ')"/>';
                    $g .= '<ellipse class="fill-paper" cx="' . self::n($ex) . '" cy="' . self::n($m($y($rp))) . '" rx="' . self::n($rx) . '" ry="' . self::n($ry) . '" transform="rotate(' . -$a . ' ' . self::n($ex) . ' ' . self::n($m($y($rp))) . ')"/>';
                }
                break;
            case 'needle':
                $k = .07 * $w;
                $g .= self::both($cy, [[$x0, $y($rO)], [$x1, $y($rO)], [$x1, $y($rO - .55 * $t)], [$x1 - $k, $y($rO - .55 * $t)], [$x1 - $k, $y($rO - .2 * $t)], [$x0 + $k, $y($rO - .2 * $t)], [$x0 + $k, $y($rO - .55 * $t)], [$x0, $y($rO - .55 * $t)]], 'fill-sec');
                $g .= self::both($cy, $rect($x0 + $k + 3, $x1 - $k - 3, $rO - .2 * $t, $rI), 'fill-paper');
                $g .= '<path class="ln-thin" d="M' . self::n($x0 - 34) . ' ' . self::n($y($rI)) . 'H' . self::n($x1 + 34) . 'M' . self::n($x0 - 34) . ' ' . self::n($m($y($rI))) . 'H' . self::n($x1 + 34) . '"/>';
                break;
            case 'thrust':
                $k = .32 * $w;
                $g .= self::both($cy, $rect($x0, $x0 + $k, $rO - .12 * $t, $rI), 'fill-sec') . self::both($cy, $rect($x1 - $k, $x1, $rO, $rI + .12 * $t), 'fill-sec');
                $rb = min(.2 * $w / $s, .3 * $t) * $s;
                $rp = ($rO + $rI) / 2;
                $g .= '<circle class="fill-paper" cx="' . self::n($cx) . '" cy="' . self::n($y($rp)) . '" r="' . self::n($rb) . '"/><circle class="fill-paper" cx="' . self::n($cx) . '" cy="' . self::n($m($y($rp))) . '" r="' . self::n($rb) . '"/>';
                break;
            case 'seal':
                $g .= self::both($cy, [[$x0, $y($rO)], [$x1, $y($rO)], [$x1, $y($rO - .16 * $t)], [$x0 + .2 * $w, $y($rO - .16 * $t)], [$x0 + .2 * $w, $y($rI + .42 * $t)], [$x0, $y($rI + .42 * $t)]], 'fill-sec');
                $g .= self::both($cy, [[$x0 + .38 * $w, $y($rO - .16 * $t)], [$x1 - .02 * $w, $y($rO - .16 * $t)], [$x1 - .1 * $w, $y($rI)], [$x0 + .5 * $w, $y($rI + .14 * $t)]], 'fill-tint');
                $sx = $x1 - .2 * $w;
                $sr = max(2.5, .07 * $t * $s);
                $g .= '<circle class="ln-obj" cx="' . self::n($sx) . '" cy="' . self::n($y($rI + .3 * $t)) . '" r="' . self::n($sr) . '"/><circle class="ln-obj" cx="' . self::n($sx) . '" cy="' . self::n($m($y($rI + .3 * $t))) . '" r="' . self::n($sr) . '"/>';
                $g .= '<path class="ln-thin" d="M' . self::n($x0 - 34) . ' ' . self::n($y($rI)) . 'H' . self::n($x1 + 34) . 'M' . self::n($x0 - 34) . ' ' . self::n($m($y($rI))) . 'H' . self::n($x1 + 34) . '"/>';
                break;
            default:
                return null;
        }

        $xD = $x1 + 44;
        $xd = $x0 - 40;
        $yB = $m($y($rO)) + 26;
        $dimsSvg = '<path class="ln-dim" d="M' . self::n($x1 + 4) . ' ' . self::n($y($rO)) . 'H' . self::n($xD + 6) . 'M' . self::n($x1 + 4) . ' ' . self::n($m($y($rO))) . 'H' . self::n($xD + 6)
            . 'M' . self::n($x0 - 4) . ' ' . self::n($y($rI)) . 'H' . self::n($xd - 6) . 'M' . self::n($x0 - 4) . ' ' . self::n($m($y($rI))) . 'H' . self::n($xd - 6)
            . 'M' . self::n($x0) . ' ' . self::n($m($y($rO)) + 4) . 'V' . self::n($yB + 6) . 'M' . self::n($x1) . ' ' . self::n($m($y($rO)) + 4) . 'V' . self::n($yB + 6) . '"/>'
            . '<path class="ln-dim" d="M' . self::n($xD) . ' ' . self::n($y($rO)) . 'V' . self::n($m($y($rO))) . 'M' . self::n($xd) . ' ' . self::n($y($rI)) . 'V' . self::n($m($y($rI))) . 'M' . self::n($x0) . ' ' . self::n($yB) . 'H' . self::n($x1) . '" marker-start="url(#ar)" marker-end="url(#ar)"/>'
            . '<text class="dim-t" font-size="' . $fs . '" x="' . self::n($xD + 8) . '" y="' . self::n($cy + $fs * .35) . '">Ø' . self::fmt($D) . '</text>'
            . '<text class="dim-t" font-size="' . $fs . '" x="' . self::n($xd - 8) . '" y="' . self::n($cy + $fs * .35) . '" text-anchor="end">Ø' . self::fmt($d0) . '</text>'
            . '<text class="dim-t" font-size="' . $fs . '" x="' . self::n($cx) . '" y="' . self::n($yB + $fs + 6) . '" text-anchor="middle">' . self::fmt($B) . '</text>';

        return '<svg class="dwg-svg" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . $aria . '">'
            . '<path class="ln-ctr" d="M' . self::n($x0 - 70) . ' ' . self::n($cy) . 'H' . self::n($x1 + 70) . '"/>' . $g . $dimsSvg . '</svg>';
    }

    /** @param list<array{float, float}> $pts the upper half; the lower half is mirrored */
    private static function both(float $cy, array $pts, string $cls): string
    {
        return '<path class="' . $cls . '" d="M' . self::path($pts) . 'Z"/><path class="' . $cls . '" d="M' . self::path(self::mirror($pts, $cy)) . 'Z"/>';
    }

    /** @param list<array{float, float}> $pts */
    private static function path(array $pts): string
    {
        return implode(' L', array_map(static fn(array $q): string => self::n($q[0]) . ' ' . self::n($q[1]), $pts));
    }

    /**
     * The same outline mirrored about the centre line (the lower half of the section).
     *
     * @param list<array{float, float}> $pts
     *
     * @return list<array{float, float}>
     */
    private static function mirror(array $pts, float $cy): array
    {
        return array_map(static fn(array $q): array => [$q[0], 2 * $cy - $q[1]], $pts);
    }

    /** A value as written on the drawing (as the prototype's fmt()). */
    public static function fmt(float $v): string
    {
        return rtrim(rtrim(\sprintf('%.2f', round($v, 2)), '0'), '.');
    }

    private static function n(float $v): string
    {
        return rtrim(rtrim(\sprintf('%.2f', $v), '0'), '.');
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
