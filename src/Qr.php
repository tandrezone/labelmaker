<?php
/**
 * QR code (byte mode, error correction level M, versions 1-10) as a matrix of modules.
 *
 *   Qr::matrix('https://example.com') -> list of rows, each a list of bool (true = dark)
 */

declare(strict_types=1);

namespace LabelMaker;

use InvalidArgumentException;

final class Qr
{
    public const MAX_LENGTH = 213;   // bytes, version 10-M

    /** Level M per version 1-10: [EC codewords per block, blocks, data codewords per block, blocks, data codewords per block]. */
    private const BLOCKS = [
        1  => [10, 1, 16, 0, 0],
        2  => [16, 1, 28, 0, 0],
        3  => [26, 1, 44, 0, 0],
        4  => [18, 2, 32, 0, 0],
        5  => [24, 2, 43, 0, 0],
        6  => [16, 4, 27, 0, 0],
        7  => [18, 4, 31, 0, 0],
        8  => [22, 2, 38, 2, 39],
        9  => [22, 3, 36, 2, 37],
        10 => [26, 4, 43, 1, 44],
    ];

    private const ALIGNMENT = [
        2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34],
        7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    /** @return bool[][] */
    public static function matrix(string $text): array
    {
        $bytes = array_values(unpack('C*', $text) ?: []);
        $n = count($bytes);
        if ($n === 0) {
            throw new InvalidArgumentException('The QR code is empty.');
        }
        $version = 0;
        foreach (self::BLOCKS as $v => [, $b1, $d1, $b2, $d2]) {
            if ($n + 2 + ($v >= 10 ? 1 : 0) <= $b1 * $d1 + $b2 * $d2) {
                $version = $v;
                break;
            }
        }
        if ($version === 0) {
            throw new InvalidArgumentException('The QR code text is too long (' . self::MAX_LENGTH . ' bytes max).');
        }
        $codewords = self::interleave($version, self::dataCodewords($version, $bytes));
        $size = 17 + 4 * $version;

        // Function patterns first, then the data in a zigzag, then the best of the 8 masks
        [$base, $isFunction] = self::functionPatterns($version, $size);
        $bits = [];
        foreach ($codewords as $c) {
            for ($i = 7; $i >= 0; $i--) {
                $bits[] = ($c >> $i) & 1;
            }
        }
        $k = 0;
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $y = ((($right + 1) & 2) === 0) ? $size - 1 - $vert : $vert;
                    if (!$isFunction[$y][$x]) {
                        $base[$y][$x] = ($bits[$k++] ?? 0) === 1;
                    }
                }
            }
        }
        $best = null;
        $bestScore = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $m = $base;
            for ($y = 0; $y < $size; $y++) {
                for ($x = 0; $x < $size; $x++) {
                    if (!$isFunction[$y][$x] && self::masked($mask, $x, $y)) {
                        $m[$y][$x] = !$m[$y][$x];
                    }
                }
            }
            self::drawFormat($m, $size, $mask);
            $score = self::penalty($m, $size);
            if ($score < $bestScore) {
                [$best, $bestScore] = [$m, $score];
            }
        }
        return $best;
    }

    private static function masked(int $mask, int $x, int $y): bool
    {
        switch ($mask) {
            case 0: return ($x + $y) % 2 === 0;
            case 1: return $y % 2 === 0;
            case 2: return $x % 3 === 0;
            case 3: return ($x + $y) % 3 === 0;
            case 4: return (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0;
            case 5: return $x * $y % 2 + $x * $y % 3 === 0;
            case 6: return ($x * $y % 2 + $x * $y % 3) % 2 === 0;
            default: return (($x + $y) % 2 + $x * $y % 3) % 2 === 0;
        }
    }

    private static function dataCodewords(int $version, array $bytes): array
    {
        [, $b1, $d1, $b2, $d2] = self::BLOCKS[$version];
        $capacity = $b1 * $d1 + $b2 * $d2;
        $bits = [];
        $put = function (int $value, int $len) use (&$bits): void {
            for ($i = $len - 1; $i >= 0; $i--) {
                $bits[] = ($value >> $i) & 1;
            }
        };
        $put(0b0100, 4);
        $put(count($bytes), $version >= 10 ? 16 : 8);
        foreach ($bytes as $b) {
            $put($b, 8);
        }
        $put(0, min(4, $capacity * 8 - count($bits)));
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }
        $out = [];
        foreach (array_chunk($bits, 8) as $byte) {
            $out[] = bindec(implode('', $byte));
        }
        for ($pad = 0xEC; count($out) < $capacity; $pad ^= 0xEC ^ 0x11) {
            $out[] = $pad;
        }
        return $out;
    }

    private static function interleave(int $version, array $data): array
    {
        [$ecLen, $b1, $d1, $b2, $d2] = self::BLOCKS[$version];
        $gen = self::generator($ecLen);
        $blocks = [];
        $pos = 0;
        foreach ([[$b1, $d1], [$b2, $d2]] as [$count, $len]) {
            for ($i = 0; $i < $count; $i++) {
                $chunk = array_slice($data, $pos, $len);
                $pos += $len;
                $blocks[] = ['data' => $chunk, 'ec' => self::remainder($chunk, $gen)];
            }
        }
        $out = [];
        for ($i = 0; $i < max($d1, $d2); $i++) {
            foreach ($blocks as $b) {
                if (isset($b['data'][$i])) {
                    $out[] = $b['data'][$i];
                }
            }
        }
        for ($i = 0; $i < $ecLen; $i++) {
            foreach ($blocks as $b) {
                $out[] = $b['ec'][$i];
            }
        }
        return $out;
    }

    private static function gfMul(int $a, int $b): int
    {
        $r = 0;
        for ($i = 7; $i >= 0; $i--) {
            $r = ($r << 1) ^ (($r >> 7) * 0x11D);
            $r ^= (($b >> $i) & 1) * $a;
        }
        return $r;
    }

    /** Coefficients (without the leading 1) of the Reed-Solomon generator polynomial of the given degree. */
    private static function generator(int $degree): array
    {
        $g = array_fill(0, $degree, 0);
        $g[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $g[$j] = self::gfMul($g[$j], $root);
                if ($j + 1 < $degree) {
                    $g[$j] ^= $g[$j + 1];
                }
            }
            $root = self::gfMul($root, 2);
        }
        return $g;
    }

    private static function remainder(array $data, array $gen): array
    {
        $r = array_fill(0, count($gen), 0);
        foreach ($data as $b) {
            $f = $b ^ array_shift($r);
            $r[] = 0;
            foreach ($gen as $i => $c) {
                $r[$i] ^= self::gfMul($c, $f);
            }
        }
        return $r;
    }

    /** @return array{0: bool[][], 1: bool[][]} the modules drawn so far and which of them are fixed */
    private static function functionPatterns(int $version, int $size): array
    {
        $m = array_fill(0, $size, array_fill(0, $size, false));
        $f = $m;
        $set = function (int $x, int $y, bool $dark) use (&$m, &$f, $size): void {
            if ($x >= 0 && $y >= 0 && $x < $size && $y < $size) {
                $m[$y][$x] = $dark;
                $f[$y][$x] = true;
            }
        };
        for ($i = 0; $i < $size; $i++) {
            $set(6, $i, $i % 2 === 0);
            $set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {   // finders with their separators
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $d = max(abs($dx), abs($dy));
                    $set($cx + $dx, $cy + $dy, $d !== 2 && $d !== 4);
                }
            }
        }
        $pos = self::ALIGNMENT[$version] ?? [];
        $last = count($pos) - 1;
        foreach ($pos as $i => $cy) {
            foreach ($pos as $j => $cx) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $set($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        // Format areas are reserved now and filled per mask
        self::drawFormat($m, $size, 0, $f);
        if ($version >= 7) {
            $rem = $version;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            }
            $bits = $version << 12 | $rem;
            for ($i = 0; $i < 18; $i++) {
                $dark = (($bits >> $i) & 1) === 1;
                $a = $size - 11 + $i % 3;
                $b = intdiv($i, 3);
                $set($a, $b, $dark);
                $set($b, $a, $dark);
            }
        }
        return [$m, $f];
    }

    /** Writes the format bits (level M + mask) into $m; with $fixed, only marks those modules as fixed. */
    private static function drawFormat(array &$m, int $size, int $mask, ?array &$fixed = null): void
    {
        $rem = $mask;   // level M is 00
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = ($mask << 10 | $rem) ^ 0x5412;
        $set = function (int $x, int $y, bool $dark) use (&$m, &$fixed): void {
            $m[$y][$x] = $dark;
            if ($fixed !== null) {
                $fixed[$y][$x] = true;
            }
        };
        $bit = function (int $i) use ($bits): bool {
            return (($bits >> $i) & 1) === 1;
        };
        for ($i = 0; $i <= 5; $i++) {
            $set(8, $i, $bit($i));
        }
        $set(8, 7, $bit(6));
        $set(8, 8, $bit(7));
        $set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $set(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $set($size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $set(8, $size - 15 + $i, $bit($i));
        }
        $set(8, $size - 8, true);
    }

    private static function penalty(array $m, int $size): int
    {
        $score = 0;
        $dark = 0;
        foreach ([false, true] as $transpose) {
            for ($a = 0; $a < $size; $a++) {
                $run = 1;
                $hist = '';
                for ($b = 0; $b < $size; $b++) {
                    $cur = $transpose ? $m[$b][$a] : $m[$a][$b];
                    $hist .= $cur ? '1' : '0';
                    if ($b > 0) {
                        $prev = $transpose ? $m[$b - 1][$a] : $m[$a][$b - 1];
                        if ($cur === $prev) {
                            $run++;
                            if ($run === 5) {
                                $score += 3;
                            } elseif ($run > 5) {
                                $score++;
                            }
                        } else {
                            $run = 1;
                        }
                    }
                    if (!$transpose && $cur) {
                        $dark++;
                    }
                }
                $score += 40 * (preg_match_all('/(?=10111010000|00001011101)/', $hist));
            }
        }
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                if ($m[$y][$x] === $m[$y][$x + 1] && $m[$y][$x] === $m[$y + 1][$x] && $m[$y][$x] === $m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }
        $total = $size * $size;
        return $score + 10 * (int)(abs($dark * 20 - $total * 10) / $total / 1);
    }
}
