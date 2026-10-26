<?php
/**
 * Code 128 (subset B: printable ASCII 32-126) as a list of bar and space widths in modules.
 *
 *   Code128::widths('LOT-A12') -> [2, 1, 1, 2, 1, 4, ...]   (bar, space, bar, space ... ends with a bar)
 */

declare(strict_types=1);

namespace LabelMaker;

use InvalidArgumentException;

final class Code128
{
    public const MAX_LENGTH = 32;

    private const START_B = 104;
    private const STOP    = 106;

    /** Bar/space widths of symbols 0-106 (the stop symbol has 7 elements, the rest 6). */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    /** @return int[] widths in modules, starting with a bar and ending with the stop bar */
    public static function widths(string $text): array
    {
        $length = strlen($text);
        if ($length === 0 || $length > self::MAX_LENGTH) {
            throw new InvalidArgumentException('The barcode must be 1 to ' . self::MAX_LENGTH . ' characters.');
        }
        $symbols = [self::START_B];
        $sum = self::START_B;
        for ($i = 0; $i < $length; $i++) {
            $code = ord($text[$i]);
            if ($code < 32 || $code > 126) {
                throw new InvalidArgumentException('The barcode can only use letters, digits and punctuation (no accents).');
            }
            $symbols[] = $code - 32;
            $sum += ($code - 32) * ($i + 1);
        }
        $symbols[] = $sum % 103;
        $symbols[] = self::STOP;

        $widths = [];
        foreach ($symbols as $symbol) {
            foreach (str_split(self::PATTERNS[$symbol]) as $width) {
                $widths[] = (int)$width;
            }
        }
        return $widths;
    }
}
