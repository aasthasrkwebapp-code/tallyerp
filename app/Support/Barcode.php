<?php

namespace App\Support;

/**
 * Code 128 (set B) barcodes as inline SVG, for printing item labels.
 */
class Barcode
{
    /** Bar/space widths for symbol values 0–106 (each string is 6 or 7 module widths). */
    protected const PATTERNS = [
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

    protected const START_B = 104;

    protected const STOP = 106;

    /**
     * True when the text can be encoded (printable ASCII).
     */
    public static function encodable(string $text): bool
    {
        return $text !== '' && preg_match('/^[\x20-\x7E]+$/', $text) === 1;
    }

    /**
     * @return array<int, int> module widths, alternating bar and space, starting with a bar
     */
    public static function modules(string $text): array
    {
        $codes = [self::START_B];
        foreach (str_split($text) as $char) {
            $codes[] = ord($char) - 32;
        }

        // Checksum: start value + sum of (position × value), mod 103.
        $sum = self::START_B;
        foreach (array_slice($codes, 1) as $i => $value) {
            $sum += ($i + 1) * $value;
        }
        $codes[] = $sum % 103;
        $codes[] = self::STOP;

        $widths = [];
        foreach ($codes as $code) {
            foreach (str_split(self::PATTERNS[$code]) as $w) {
                $widths[] = (int) $w;
            }
        }

        return $widths;
    }

    public static function svg(string $text, float $moduleWidth = 1.5, int $height = 50): string
    {
        $x = 10 * $moduleWidth; // quiet zone
        $bars = '';
        foreach (self::modules($text) as $i => $width) {
            $w = $width * $moduleWidth;
            if ($i % 2 === 0) {
                $bars .= sprintf('<rect x="%.2f" y="0" width="%.2f" height="%d"/>', $x, $w, $height);
            }
            $x += $w;
        }
        $total = $x + 10 * $moduleWidth;

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %.2f %d" width="%.2f" height="%d" role="img" aria-label="Barcode %s"><rect width="100%%" height="100%%" fill="#fff"/><g fill="#000">%s</g></svg>',
            $total, $height, $total, $height, e($text), $bars,
        );
    }
}
