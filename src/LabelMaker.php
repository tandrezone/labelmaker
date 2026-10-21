<?php
/**
 * LabelMaker - small thermal-printer labels with PHP + GD
 *
 *   +-------------------------------------------+
 *   |  [icon]  Propylene Glycol        +------+ |
 *   |          Packed: 21/10/2026      | 3ml  | |
 *   |          Lot: A12                +------+ |
 *   +-------------------------------------------+
 *
 * Default size: 384 x 128 px = 48 x 16 mm on a 384-dot (203 dpi) printer.
 * The name auto-fits on 1-3 lines (or uses the font-size you give it), optional
 * info lines go under it, the volume or weight goes in the black tag on the
 * right, and an optional icon sits on the left. Output is a crisp 1-bit PNG.
 *
 *   $maker = new LabelMaker\LabelMaker();
 *   $maker->save('label.png', ['text' => 'Propylene Glycol', 'qty' => '3ml', 'icon' => 'water_drop']);
 *   $png = $maker->png(['text' => 'Glycerin', 'qty' => '10ml', 'info' => ['Packed: {today}', 'Exp: {today+1y}']]);
 *
 * Options
 *   text        Product name (required). A | forces a line break: "Propylene|Glycol"
 *   qty         Volume or weight: 3ml, 250 g, 1,5 L ... Leave it out for no tag
 *   info        Extra lines under the name: packing date, expiry, lot, storage ...
 *               A list, or one string with the lines split by | or new lines (8 lines max)
 *   info-size   Size of the info lines in px (0 or auto = 18 px on a 128 px label, the default)
 *   info-gap    Space between the name and the info lines in px (auto = 5 px on a 128 px label)
 *   info-spacing Extra space between the info lines in px, kept even when the lines shrink
 *               to fit (auto = 20% of their font size)
 *   date-format How {today} is written, as PHP date() letters (default d/m/Y)
 *               In text, qty and info: {today} = today's date, {today+3d} {today+2w}
 *               {today+6m} {today+1y} = days/weeks/months/years from today (or -)
 *   icon        Built-in icon: drop, flask, pumpkin; or one of ~3,600 icon names (see iconNames()):
 *               food & everything else from Noto Emoji (apple, pear, carrot, garlic, cheese ...)
 *               and Material Icons (eco, science, ac_unit, local_fire_department ...);
 *               or a PNG/JPG/GIF/WebP file: a path, or a name without the extension
 *               from the icon folder (leaf -> leaf.png)
 *   icon-mode   How a picture icon becomes black & white:
 *               alpha = every coloured pixel prints black (icons on a transparent background)
 *               luma  = dark pixels print black (black-on-white pictures, JPGs)
 *               auto  = alpha if the picture has transparency, otherwise luma (default)
 *   width       Label width in px (default 384 = the full print width)
 *   height      Label height in px (default 128, about 16 mm)
 *   font-size   Size of the name in px (0 or auto = as big as fits, the default).
 *               A name that does not fit at this size is still shrunk to fit.
 *   frame       true = rounded border to cut along, false = no border (default true)
 *   mono        true = pure black & white 1-bit PNG, false = smooth greyscale (default true)
 *   dpi         Resolution written into the PNG (default 203)
 *   font        TTF file for the name
 *   font-heavy  TTF file for the tag (default: the font option if given)
 *
 * Untrusted input (e.g. $_GET) must be rendered with $untrusted = true: then
 * only USER_OPTIONS are read and icons can only be built-in or from the icon folder.
 *
 * Fonts: Barlow Semi Condensed (free, OFL licence), looked up in the font folder:
 *   BarlowSemiCondensed-Bold.ttf, BarlowSemiCondensed-ExtraBold.ttf
 * from https://fonts.google.com/specimen/Barlow+Semi+Condensed
 * Without them it falls back to Liberation Sans, DejaVu Sans or Arial if it finds them.
 * Icons: fonts/NotoEmoji-Labels.ttf (OFL; made by tools/build-emoji-font.py) and
 * fonts/MaterialIcons-Regular.ttf (Apache 2.0), each with a .codepoints list of names.
 *
 * Needs PHP 7.4+ with GD and FreeType (e.g. apt install php-gd).
 */

declare(strict_types=1);

namespace LabelMaker;

use InvalidArgumentException;
use RuntimeException;

final class LabelMaker
{
    public const BUILTIN_ICONS = ['drop', 'flask', 'pumpkin'];

    /** The options a web visitor may set; fonts and file paths stay on the server. */
    public const USER_OPTIONS = [
        'text', 'qty', 'info', 'info-size', 'info-gap', 'info-spacing', 'date-format', 'icon', 'icon-mode',
        'width', 'height', 'font-size', 'frame', 'mono', 'dpi',
    ];

    private const SS               = 4;     // draw 4x bigger, then shrink: smooth curves and text
    private const LINE_HEIGHT      = 1.0;   // name line spacing, in font sizes
    private const INFO_LINE_HEIGHT = 1.2;   // info line spacing, in font sizes
    private const INFO_MAX_SHARE   = 0.5;   // the info lines take at most this part of the height
    private const MONO_THRESHOLD   = 128;   // 0-255: anything darker than this prints black
    private const ICON_EXTS        = ['png', 'webp', 'gif', 'jpg', 'jpeg'];
    private const GLYPH_FONTS      = ['emoji' => 'NotoEmoji-Labels', 'material' => 'MaterialIcons-Regular'];

    private const DEFAULTS = [
        'text' => '', 'qty' => '', 'info-size' => 'auto', 'info-gap' => 'auto', 'info-spacing' => 'auto', 'date-format' => 'd/m/Y', 'icon' => '', 'icon-mode' => 'auto',
        'width' => '384', 'height' => '128', 'font-size' => 'auto', 'frame' => '1', 'mono' => '1', 'dpi' => '203',
        'font' => '', 'font-heavy' => '',
    ];

    private ?string $iconDir;
    private string $fontDir;
    private array $measureCache = [];

    /**
     * @param string|null $iconDir Folder of picture icons that can be picked by name (icon=leaf -> leaf.png)
     * @param string|null $fontDir Folder with the Barlow fonts (default: the package's fonts/ folder)
     */
    public function __construct(?string $iconDir = null, ?string $fontDir = null)
    {
        if (!function_exists('imagettftext')) {
            throw new RuntimeException('PHP GD with FreeType support is required (e.g. apt install php-gd).');
        }
        $this->iconDir = $iconDir !== null ? rtrim($iconDir, '/\\') : null;
        $this->fontDir = rtrim($fontDir ?? dirname(__DIR__) . '/fonts', '/\\');
    }

    /**
     * Draws the label.
     *
     * @return \GdImage|resource
     */
    public function render(array $options, bool $untrusted = false)
    {
        $o = $this->normalize($options, $untrusted);
        $img = $this->renderLabel($o);
        if (function_exists('imageresolution')) {
            imageresolution($img, $o['dpi'], $o['dpi']);
        }
        return $img;
    }

    /** Draws the label and returns the PNG file contents. */
    public function png(array $options, bool $untrusted = false): string
    {
        $img = $this->render($options, $untrusted);
        ob_start();
        imagepng($img, null, 9);
        return (string)ob_get_clean();
    }

    /** Draws the label and saves it as a PNG file. */
    public function save(string $path, array $options): void
    {
        if (!imagepng($this->render($options), $path, 9)) {
            throw new RuntimeException("Could not write $path");
        }
    }

    /** Every icon name: the built-in ones, then each icon font's. */
    public static function iconNames(): array
    {
        $names = self::BUILTIN_ICONS;
        foreach (self::glyphIcons() as $icons) {
            // array_keys() turns names like "123" into ints
            $names = array_merge($names, array_map('strval', array_keys($icons)));
        }
        return array_values(array_unique($names));
    }

    /**
     * The icon fonts' icons: ['emoji' => [name => codepoint hex], 'material' => [...]],
     * from each font's .codepoints file. A name in both is drawn from the first.
     */
    public static function glyphIcons(): array
    {
        static $sets = null;
        if ($sets === null) {
            $sets = [];
            foreach (self::GLYPH_FONTS as $set => $file) {
                $sets[$set] = [];
                $path = dirname(__DIR__) . "/fonts/$file.codepoints";
                foreach (@file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                    if (preg_match('/^([a-z0-9_]+) ([0-9a-f]{4,6})$/', trim($line), $m)) {
                        $sets[$set][$m[1]] = $m[2];
                    }
                }
            }
        }
        return $sets;
    }

    /** Path of an icon font: 'emoji' or 'material'. */
    public static function glyphFont(string $set): string
    {
        if (!isset(self::GLYPH_FONTS[$set])) {
            throw new InvalidArgumentException("Unknown icon font: $set");
        }
        return dirname(__DIR__) . '/fonts/' . self::GLYPH_FONTS[$set] . '.ttf';
    }

    /** A single icon as a black & white PNG, $size px square (for icon pickers). */
    public function iconPng(string $icon, int $size = 48): string
    {
        $icon = $this->resolveIcon(trim($icon), true);
        $size = max(8, min(512, $size));
        $big = $size * self::SS;
        $img = imagecreatetruecolor($big, $big);
        imagefilledrectangle($img, 0, 0, $big - 1, $big - 1, 0xFFFFFF);
        if ($icon !== '') {
            $this->drawIcon($img, $icon, 'auto', 0, 0, $big);
        }
        $out = imagecreatetruecolor($size, $size);
        imagecopyresampled($out, $img, 0, 0, 0, 0, $size, $size, $big, $big);
        ob_start();
        imagepng(self::toMono($out), null, 9);
        return (string)ob_get_clean();
    }

    // -------------------------------------------------------------- options

    private function normalize(array $options, bool $untrusted): array
    {
        if ($untrusted) {
            $options = array_intersect_key($options, array_flip(self::USER_OPTIONS));
        }
        $info = $options['info'] ?? [];
        unset($options['info']);
        $o = self::DEFAULTS;
        foreach ($options as $key => $value) {
            if (is_array($value)) {
                $value = end($value);
            }
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            if (array_key_exists($key, $o) && is_scalar($value)) {
                $o[$key] = (string)$value;
            }
        }

        $dateFormat = $o['date-format'];
        if ($dateFormat === '' || strlen($dateFormat) > 32) {
            throw new InvalidArgumentException('date-format must be 1 to 32 characters, e.g. d/m/Y');
        }
        $clean = function (string $value) use ($dateFormat): string {
            return self::expandDates(trim((string)preg_replace('/\s+/u', ' ', $value)), $dateFormat);
        };

        $text = $clean($o['text']);
        if (trim(str_replace('|', '', $text)) === '') {
            throw new InvalidArgumentException('Missing text (the product name), e.g. --text="Propylene Glycol"');
        }
        if (strlen($text) > 120) {
            throw new InvalidArgumentException('The text is too long (120 characters max).');
        }
        $qty = $clean($o['qty']);
        if (strlen($qty) > 24) {
            throw new InvalidArgumentException('The quantity is too long (24 characters max).');
        }
        $infoLines = [];
        foreach (is_array($info) ? $info : [$info] as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            foreach (preg_split('/\r\n|\r|\n|\|/', (string)$item) ?: [] as $line) {
                $line = $clean($line);
                if (strlen($line) > 80) {
                    throw new InvalidArgumentException('An info line is too long (80 characters max).');
                }
                if ($line !== '') {
                    $infoLines[] = $line;
                }
            }
        }
        if (count($infoLines) > 8) {
            throw new InvalidArgumentException('Too many info lines (8 max).');
        }
        $infoSize = strtolower(trim($o['info-size']));
        $infoSize = in_array($infoSize, ['', 'auto'], true) ? 0 : self::wholeNumber($infoSize, 0, 400, 'info-size');
        $autoOrPx = function (string $name) use ($o): int {    // -1 = auto
            $value = strtolower(trim($o[$name]));
            return in_array($value, ['', 'auto'], true) ? -1 : self::wholeNumber($value, 0, 400, $name);
        };
        $mode = strtolower(trim($o['icon-mode']));
        if (!in_array($mode, ['auto', 'alpha', 'luma'], true)) {
            throw new InvalidArgumentException('icon-mode must be auto, alpha or luma.');
        }
        $fontSize = strtolower(trim($o['font-size']));
        $fontSize = in_array($fontSize, ['', 'auto'], true) ? 0 : self::wholeNumber($fontSize, 0, 1000, 'font-size');

        $ownFont = !$untrusted && $o['font'] !== '';
        $font = $ownFont
            ? self::existingFile($o['font'], 'Font')
            : self::firstFile(array_merge([$this->fontDir . '/BarlowSemiCondensed-Bold.ttf'], self::systemFonts()));
        if ($font === null) {
            throw new RuntimeException('No font found. Put BarlowSemiCondensed-Bold.ttf and -ExtraBold.ttf '
                . "in {$this->fontDir}, or pass the font option (--font=/path/to/font.ttf)");
        }
        if (!$untrusted && $o['font-heavy'] !== '') {
            $heavy = self::existingFile($o['font-heavy'], 'Font');
        } elseif ($ownFont) {
            $heavy = $font;
        } else {
            $heavy = self::firstFile([$this->fontDir . '/BarlowSemiCondensed-ExtraBold.ttf']) ?? $font;
        }

        return [
            'text'       => $text,
            'qty'        => $qty,
            'info'       => $infoLines,
            'info-size'  => $infoSize,
            'info-gap'   => $autoOrPx('info-gap'),
            'info-spacing' => $autoOrPx('info-spacing'),
            'icon'       => $this->resolveIcon(trim($o['icon']), $untrusted),
            'icon-mode'  => $mode,
            'width'      => self::wholeNumber($o['width'], 64, 2400, 'width'),
            'height'     => self::wholeNumber($o['height'], 32, 2400, 'height'),
            'font-size'  => $fontSize,
            'frame'      => self::isOn($o['frame']),
            'mono'       => self::isOn($o['mono']),
            'dpi'        => self::wholeNumber($o['dpi'], 72, 1200, 'dpi'),
            'font'       => $font,
            'font-heavy' => $heavy,
        ];
    }

    /** Returns '' (no icon), 'builtin:<name>', 'glyph:<font>:<codepoint>' or the path of an image file. */
    private function resolveIcon(string $icon, bool $untrusted): string
    {
        if ($icon === '') {
            return '';
        }
        if (in_array(strtolower($icon), self::BUILTIN_ICONS, true)) {
            return 'builtin:' . strtolower($icon);
        }
        if (!$untrusted && is_file($icon)) {
            return $icon;
        }
        // A name from the icon folder (the only kind of file allowed from untrusted input)
        if ($this->iconDir !== null && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $icon)) {
            foreach (self::ICON_EXTS as $ext) {
                $path = "{$this->iconDir}/$icon.$ext";
                if (is_file($path)) {
                    return $path;
                }
            }
        }
        $name = str_replace(['-', ' '], '_', strtolower($icon));
        foreach (self::glyphIcons() as $set => $icons) {
            if (isset($icons[$name])) {
                return "glyph:$set:" . $icons[$name];
            }
        }
        throw new InvalidArgumentException("Icon not found: \"$icon\". Use " . implode(', ', self::BUILTIN_ICONS)
            . ', an icon name (e.g. apple, carrot, eco, science; list them with labelmaker --icons)'
            . ($untrusted ? ' or the name of a file in the icon folder.' : ' or the path of a PNG/JPG/GIF/WebP file.'));
    }

    /** Replaces {today}, {today+3d}, {today-1w}, {today+6m}, {today+1y} with dates. */
    private static function expandDates(string $value, string $format): string
    {
        return (string)preg_replace_callback('/\{today(?:([+-])(\d{1,4})([dwmy]))?\}/i', function (array $m) use ($format): string {
            $date = new \DateTimeImmutable('today');
            if (!empty($m[1])) {
                $units = ['d' => 'days', 'w' => 'weeks', 'm' => 'months', 'y' => 'years'];
                $date = $date->modify($m[1] . $m[2] . ' ' . $units[strtolower($m[3])]);
            }
            return $date->format($format);
        }, $value);
    }

    private static function wholeNumber(string $value, int $min, int $max, string $name): int
    {
        if (!preg_match('/^\s*\d+\s*$/', $value)) {
            throw new InvalidArgumentException("$name must be a whole number.");
        }
        return max($min, min($max, (int)$value));
    }

    private static function isOn(string $value): bool
    {
        return !in_array(strtolower(trim($value)), ['0', 'no', 'false', 'off'], true);
    }

    private static function firstFile(array $paths): ?string
    {
        foreach ($paths as $path) {
            if (@is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    private static function existingFile(string $path, string $what): string
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException("$what not found: $path");
        }
        return $path;
    }

    private static function systemFonts(): array
    {
        return [
            '/usr/share/fonts/truetype/liberation/LiberationSansNarrow-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/dejavu-sans-fonts/DejaVuSans-Bold.ttf',
            'C:/Windows/Fonts/arialnb.ttf',
            'C:/Windows/Fonts/arialbd.ttf',
            '/System/Library/Fonts/Supplemental/Arial Narrow Bold.ttf',
            '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
            '/Library/Fonts/Arial Bold.ttf',
        ];
    }

    // -------------------------------------------------------------- drawing
    // Everything is drawn SS times bigger ("big pixels") and shrunk at the end.
    // $k = big pixels per pixel of the 384 x 128 reference design.

    private function renderLabel(array $o)
    {
        $W = $o['width'];
        $H = $o['height'];
        $k = min($H / 128, $W / 384) * self::SS;

        $img = imagecreatetruecolor($W * self::SS, $H * self::SS);
        imagefilledrectangle($img, 0, 0, $W * self::SS - 1, $H * self::SS - 1, 0xFFFFFF);

        // Frame: a black rounded rectangle with a white one inside it
        $pad = 6 * $k;
        $x0 = $pad;
        $y0 = $pad;
        $x1 = $W * self::SS - $pad;
        $y1 = $H * self::SS - $pad;
        if ($o['frame']) {
            $border = max(2 * self::SS, round(3 * $k));
            self::fillRoundRect($img, $x0, $y0, $x1, $y1, 12 * $k, 0x000000);
            $x0 += $border;
            $y0 += $border;
            $x1 -= $border;
            $y1 -= $border;
            self::fillRoundRect($img, $x0, $y0, $x1, $y1, max(0, 12 * $k - $border), 0xFFFFFF);
        }

        $midY   = ($y0 + $y1) / 2;
        $left   = $x0 + ($o['icon'] !== '' ? 14 : 18) * $k;
        $right  = $x1 - 12 * $k;
        $top    = $y0 + 8 * $k;
        $bottom = $y1 - 8 * $k;

        if ($o['qty'] !== '') {
            $tagWidth = $this->drawTag($img, $o['font-heavy'], $o['qty'], $right, $midY, 0.45 * ($x1 - $x0), $k);
            $right -= $tagWidth + 12 * $k;
        }
        if ($o['icon'] !== '') {
            $size = min(72 * $k, $bottom - $top);
            $this->drawIcon($img, $o['icon'], $o['icon-mode'], $left, $midY - $size / 2, $size);
            $left += $size + 10 * $k;
        }
        if ($right - $left < 16 * $k) {
            throw new InvalidArgumentException('No room left for the text: make the label wider or the quantity shorter.');
        }

        // The name and the info lines under it, centred together
        $w = $right - $left;
        $h = $bottom - $top;
        $info = null;
        $gap = 0.0;
        if ($o['info']) {
            $infoPx = $o['info-size'] > 0 ? $o['info-size'] * self::SS : 18 * $k;
            $spacing = $o['info-spacing'] >= 0 ? $o['info-spacing'] * self::SS : null;
            $info = $this->fitInfo($o['font'], $o['info'], $w, self::INFO_MAX_SHARE * $h, $infoPx, $spacing);
            $gap = $o['info-gap'] >= 0 ? $o['info-gap'] * self::SS : 5 * $k;
            $h -= $info['height'] + $gap;
        }
        $maxPx = $o['font-size'] > 0 ? $o['font-size'] * self::SS : 0.33 * $H * self::SS;
        $name = $this->fitName($o['font'], $o['text'], $w, $h, $maxPx);
        $y = $top + ($h - $name['height']) / 2;
        $this->drawBlock($img, $o['font'], $name, $left, $y);
        if ($info) {
            $this->drawBlock($img, $o['font'], $info, $left, $y + $name['height'] + $gap);
        }

        // Shrink to the real size
        $out = imagecreatetruecolor($W, $H);
        imagecopyresampled($out, $img, 0, 0, 0, 0, $W, $H, $W * self::SS, $H * self::SS);
        unset($img);

        return $o['mono'] ? self::toMono($out) : $out;
    }

    /** The black quantity tag, right-aligned at $right and centred on $midY. Returns its width. */
    private function drawTag($img, string $font, string $qty, float $right, float $midY, float $maxWidth, float $k): float
    {
        // "3ml" -> big "3" + smaller "ml";  "1,5 L" -> "1,5" + "L"
        if (preg_match('/^(\d+(?:[.,]\d+)?)\s*(.*)$/u', $qty, $m)) {
            [$num, $unit] = [$m[1], trim($m[2])];
        } else {
            [$num, $unit] = [$qty, ''];
        }
        $padX = 12 * $k;
        $padY = 14 * $k;
        $gap = 2 * $k;
        $numPt = self::px2pt(56 * $k);
        $unitPt = self::px2pt(32 * $k);

        $nm = $this->measure($font, $numPt, $num);
        $um = $unit !== '' ? $this->measure($font, $unitPt, $unit) : null;
        $content = $nm['w'] + ($um ? $gap + $um['w'] : 0);
        if ($content + 2 * $padX > $maxWidth) {
            // A long quantity: shrink its type until the tag fits
            $f = ($maxWidth - 2 * $padX) / $content;
            $numPt *= $f;
            $unitPt *= $f;
            $gap *= $f;
            $nm = $this->measure($font, $numPt, $num);
            $um = $um ? $this->measure($font, $unitPt, $unit) : null;
            $content = $nm['w'] + ($um ? $gap + $um['w'] : 0);
        }

        $width = $content + 2 * $padX;
        $height = $nm['asc'] + 2 * $padY;
        $tx = $right - $width;
        $ty = $midY - $height / 2;
        self::fillRoundRect($img, $tx, $ty, $right, $ty + $height, 8 * $k, 0x000000);

        $baseline = (int)round($ty + $padY + $nm['asc']);
        imagettftext($img, $numPt, 0, (int)round($tx + $padX - $nm['x']), $baseline, 0xFFFFFF, $font, $num);
        if ($um) {
            $ux = $tx + $padX + $nm['w'] + $gap - $um['x'];
            imagettftext($img, $unitPt, 0, (int)round($ux), $baseline, 0xFFFFFF, $font, $unit);
        }
        return $width;
    }

    /**
     * Lays out the product name on 1-3 lines, at $maxPx (em size in big pixels) or smaller
     * if it does not fit in $w x $h. Uses the fewest lines that reach the biggest size.
     */
    private function fitName(string $font, string $text, float $w, float $h, float $maxPx): array
    {
        $ref = 100.0;              // measure at 100 pt, then scale
        $refPx = $ref / 0.75;      // GD renders at 96 dpi: 1 pt = 4/3 px

        if (strpos($text, '|') !== false) {
            $choices = [array_values(array_filter(array_map('trim', explode('|', $text)), 'strlen'))];
        } else {
            $words = explode(' ', $text);
            $choices = [];
            for ($n = 1; $n <= min(3, count($words)); $n++) {
                $choices[] = $this->bestSplit($font, $ref, $words, $n);
            }
        }

        $best = [];
        $bestScale = 0.0;
        foreach ($choices as $lines) {
            $n = count($lines);
            $widest = 1.0;
            foreach ($lines as $line) {
                $widest = max($widest, $this->measure($font, $ref, $line)['w']);
            }
            $needed = $this->measure($font, $ref, $lines[0])['asc']
                + ($n - 1) * $refPx * self::LINE_HEIGHT
                + $this->measure($font, $ref, $lines[$n - 1])['desc'];
            $scale = min($w / $widest, $h / max($needed, 1.0), $maxPx / $refPx);
            if ($scale > $bestScale * 1.02) {      // more lines only if clearly bigger
                $best = $lines;
                $bestScale = $scale;
            }
        }

        $pt = $ref * $bestScale * 0.99;
        return $this->block($font, [$best], $pt, $pt / 0.75 * self::LINE_HEIGHT);
    }

    /**
     * Lays out the info lines at $px (em size in big pixels), smaller if they do not fit in
     * $w x $h. Three or more lines go in two columns when that makes them clearly bigger.
     * $spacing = extra space between the lines in big pixels, or null for the default.
     */
    private function fitInfo(string $font, array $lines, float $w, float $h, float $px, ?float $spacing): array
    {
        $ref = 100.0;
        $refPx = $ref / 0.75;
        $colGap = 0.6;   // space between columns, in font sizes
        // Baseline to baseline = em * $lineHeight + $extra: the default grows with the type,
        // a chosen spacing stays the same when the type shrinks to fit
        $lineHeight = $spacing === null ? self::INFO_LINE_HEIGHT : 1.0;
        $extra = $spacing ?? 0.0;
        $n = count($lines);
        $best = null;
        foreach ($n >= 3 ? [1, 2] : [1] as $count) {
            $columns = array_chunk($lines, (int)ceil($n / $count));
            $room = $h - (count($columns[0]) - 1) * $extra;
            $b = $this->block($font, $columns, $ref, $refPx * $lineHeight, $colGap * $refPx);
            $scale = min($px / $refPx, $w / max($b['width'], 1.0), max($room, 1.0) / max($b['height'], 1.0));
            if ($best === null || $scale > $best[0] * 1.15) {
                $best = [$scale, $columns];
            }
        }
        $pt = $ref * $best[0] * 0.99;
        return $this->block($font, $best[1], $pt, $pt / 0.75 * $lineHeight + $extra, $colGap * $pt / 0.75);
    }

    /**
     * Columns of text lines at $pt, $lineGap big pixels from baseline to baseline: where each
     * column starts, the ink height above the first baseline, and the total ink width and height.
     */
    private function block(string $font, array $columns, float $pt, float $lineGap, float $colGap = 0.0): array
    {
        $gap = $lineGap;
        $rows = max(array_map('count', $columns));
        $asc = 0.0;
        $desc = 0.0;
        $x = 0.0;
        $placed = [];
        foreach ($columns as $lines) {
            $asc = max($asc, $this->measure($font, $pt, $lines[0])['asc']);
            if (count($lines) === $rows) {
                $desc = max($desc, $this->measure($font, $pt, $lines[$rows - 1])['desc']);
            }
            $placed[] = ['x' => $x, 'lines' => $lines];
            $widest = 0.0;
            foreach ($lines as $line) {
                $widest = max($widest, $this->measure($font, $pt, $line)['w']);
            }
            $x += $widest + $colGap;
        }
        return [
            'columns' => $placed,
            'pt'      => $pt,
            'gap'     => $gap,
            'asc'     => $asc,
            'width'   => $x - $colGap,
            'height'  => $asc + ($rows - 1) * $gap + $desc,
        ];
    }

    /** Draws a block from fitName() / fitInfo() with its top-left ink corner at $x, $y. */
    private function drawBlock($img, string $font, array $block, float $x, float $y): void
    {
        foreach ($block['columns'] as $column) {
            foreach ($column['lines'] as $i => $line) {
                $m = $this->measure($font, $block['pt'], $line);
                $left = $x + $column['x'] - $m['x'];
                $baseline = $y + $block['asc'] + $i * $block['gap'];
                imagettftext($img, $block['pt'], 0, (int)round($left), (int)round($baseline), 0x000000, $font, $line);
            }
        }
    }

    /** Splits the words into $n lines so the longest line is as short as possible. */
    private function bestSplit(string $font, float $pt, array $words, int $n): array
    {
        $count = count($words);
        if ($n <= 1) {
            return [implode(' ', $words)];
        }
        $best = [];
        $bestWidth = INF;
        foreach (self::combinations(range(1, $count - 1), $n - 1) as $breaks) {
            $lines = [];
            $start = 0;
            foreach (array_merge($breaks, [$count]) as $end) {
                $lines[] = implode(' ', array_slice($words, $start, $end - $start));
                $start = $end;
            }
            $width = 0.0;
            foreach ($lines as $line) {
                $width = max($width, $this->measure($font, $pt, $line)['w']);
            }
            if ($width < $bestWidth) {
                $bestWidth = $width;
                $best = $lines;
            }
        }
        return $best;
    }

    private static function combinations(array $items, int $k): array
    {
        if ($k === 0) {
            return [[]];
        }
        $out = [];
        for ($i = 0; $i <= count($items) - $k; $i++) {
            foreach (self::combinations(array_slice($items, $i + 1), $k - 1) as $rest) {
                $out[] = array_merge([$items[$i]], $rest);
            }
        }
        return $out;
    }

    /** Ink box of a string: width, height above/below the baseline, left offset. */
    private function measure(string $font, float $pt, string $text): array
    {
        $key = "$font|$pt|$text";
        if (!isset($this->measureCache[$key])) {
            $b = imagettfbbox($pt, 0, $font, $text);
            if ($b === false) {
                throw new RuntimeException("Could not use the font $font");
            }
            $this->measureCache[$key] = [
                'w'    => (float)(max($b[2], $b[4]) - min($b[0], $b[6])),
                'asc'  => (float)-min($b[5], $b[7]),
                'desc' => (float)max(0, $b[1], $b[3]),
                'x'    => (float)min($b[0], $b[6]),
            ];
        }
        return $this->measureCache[$key];
    }

    private static function px2pt(float $px): float
    {
        return $px * 0.75;
    }

    // -------------------------------------------------------------- icons

    private function drawIcon($img, string $icon, string $mode, float $x, float $y, float $size): void
    {
        if (strpos($icon, 'builtin:') === 0) {
            self::drawBuiltinIcon($img, substr($icon, 8), $x, $y, $size);
        } elseif (strpos($icon, 'glyph:') === 0) {
            [, $set, $codepoint] = explode(':', $icon, 3);
            $this->drawGlyphIcon($img, self::glyphFont($set), $codepoint, $x, $y, $size);
        } else {
            self::drawImageIcon($img, $icon, $mode, $x, $y, $size);
        }
    }

    /** One character of an icon font, scaled so its ink fills the icon box. */
    private function drawGlyphIcon($img, string $font, string $codepoint, float $x, float $y, float $s): void
    {
        if (!is_file($font)) {
            throw new RuntimeException("Icon font not found: $font");
        }
        $char = html_entity_decode("&#x$codepoint;", ENT_NOQUOTES, 'UTF-8');
        $ref = 100.0;
        $m = $this->measure($font, $ref, $char);
        $f = $s / max($m['w'], $m['asc'] + $m['desc'], 1.0);
        $left = $x + ($s - $m['w'] * $f) / 2 - $m['x'] * $f;
        $baseline = $y + ($s - ($m['asc'] + $m['desc']) * $f) / 2 + $m['asc'] * $f;
        imagettftext($img, $ref * $f, 0, (int)round($left), (int)round($baseline), 0x000000, $font, $char);
    }

    private static function drawBuiltinIcon($img, string $name, float $x, float $y, float $s): void
    {
        if ($name === 'drop') {
            // Teardrop curve with the tip at the top
            $cx = $x + $s / 2;
            $cy = $y + $s / 2;
            $r = 0.46 * $s;
            $points = [];
            for ($i = 0; $i < 180; $i++) {
                $t = 2 * M_PI * $i / 180;
                $points[] = [$cx + 0.95 * $r * sin($t) * sin($t / 2), $cy - $r * cos($t)];
            }
            self::fillPolygon($img, $points, 0x000000);
            // White highlight on the lower left
            $dot = (int)round(0.14 * $r);
            for ($a = 115; $a <= 175; $a += 3) {
                $rad = deg2rad($a);
                $hx = (int)round($cx + 0.42 * $r * cos($rad));
                $hy = (int)round($cy + 0.32 * $r + 0.42 * $r * sin($rad));
                imagefilledellipse($img, $hx, $hy, $dot, $dot, 0xFFFFFF);
            }
            return;
        }

        if ($name === 'pumpkin') {
            // Overlapping lobes, each inner one with a white outline for the ribs, and a stem
            $cx = $x + $s / 2;
            $cy = $y + 0.6 * $s;
            $rib = 0.035 * $s;
            $lobes = [   // [x offset, width, height], drawn back to front
                [-0.31, 0.38, 0.56], [0.31, 0.38, 0.56],
                [-0.17, 0.44, 0.66], [0.17, 0.44, 0.66],
                [0.0, 0.42, 0.72],
            ];
            foreach ($lobes as $i => [$dx, $w, $h]) {
                $lx = (int)round($cx + $dx * $s);
                if ($i >= 2) {
                    imagefilledellipse($img, $lx, (int)round($cy), (int)round($w * $s + 2 * $rib), (int)round($h * $s + 2 * $rib), 0xFFFFFF);
                }
                imagefilledellipse($img, $lx, (int)round($cy), (int)round($w * $s), (int)round($h * $s), 0x000000);
            }
            $p = function (float $fx, float $fy) use ($x, $y, $s): array {
                return [$x + $fx * $s, $y + $fy * $s];
            };
            self::fillPolygon($img, [$p(0.45, 0.30), $p(0.56, 0.30), $p(0.60, 0.12), $p(0.66, 0.05), $p(0.60, 0.02), $p(0.51, 0.11)], 0x000000);
            return;
        }

        // flask (Erlenmeyer) with a white liquid line
        $p = function (float $fx, float $fy) use ($x, $y, $s): array {
            return [$x + $fx * $s, $y + $fy * $s];
        };
        self::fillRoundRect($img, $x + 0.30 * $s, $y + 0.05 * $s, $x + 0.70 * $s, $y + 0.15 * $s, 0.03 * $s, 0x000000);
        self::fillPolygon($img, [
            $p(0.38, 0.12), $p(0.62, 0.12), $p(0.62, 0.40), $p(0.89, 0.84), $p(0.87, 0.90),
            $p(0.83, 0.92), $p(0.17, 0.92), $p(0.13, 0.90), $p(0.11, 0.84), $p(0.38, 0.40),
        ], 0x000000);
        self::fillRoundRect($img, $x + 0.31 * $s, $y + 0.60 * $s, $x + 0.69 * $s, $y + 0.645 * $s, 0.02 * $s, 0xFFFFFF);
    }

    /** Draws a picture file, scaled into the icon box and turned into black ink. */
    private static function drawImageIcon($img, string $path, string $mode, float $x, float $y, float $s): void
    {
        $data = @file_get_contents($path);
        $src = $data === false ? false : @imagecreatefromstring($data);
        if (!$src) {
            throw new InvalidArgumentException('Could not read the icon. Use PNG, JPG, GIF or WebP (convert SVG to PNG first).');
        }
        if (!imageistruecolor($src)) {
            imagepalettetotruecolor($src);
        }
        $sw = imagesx($src);
        $sh = imagesy($src);
        $f = min($s / $sw, $s / $sh);
        $tw = max(1, (int)round($sw * $f));
        $th = max(1, (int)round($sh * $f));

        $icon = imagecreatetruecolor($tw, $th);
        imagealphablending($icon, false);
        imagesavealpha($icon, true);
        imagefilledrectangle($icon, 0, 0, $tw - 1, $th - 1, imagecolorallocatealpha($icon, 255, 255, 255, 127));
        imagecopyresampled($icon, $src, 0, 0, 0, 0, $tw, $th, $sw, $sh);
        unset($src);

        if ($mode === 'auto') {
            $mode = self::hasTransparency($icon) ? 'alpha' : 'luma';
        }
        $ox = (int)round($x + ($s - $tw) / 2);
        $oy = (int)round($y + ($s - $th) / 2);
        for ($py = 0; $py < $th; $py++) {
            for ($px = 0; $px < $tw; $px++) {
                $c = imagecolorat($icon, $px, $py);
                $opacity = 1 - (($c >> 24) & 0x7F) / 127;
                $r = ($c >> 16) & 0xFF;
                $g = ($c >> 8) & 0xFF;
                $b = $c & 0xFF;
                if ($mode === 'alpha') {
                    // Any coloured pixel is ink; near-white details stay white
                    $ink = $opacity * (1 - max(0, min(1, (min($r, $g, $b) - 200) / 55)));
                } else {
                    $ink = $opacity * (1 - (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255);
                }
                if ($ink > 0.01) {
                    $v = (int)round(255 * (1 - $ink));
                    imagesetpixel($img, $ox + $px, $oy + $py, ($v << 16) | ($v << 8) | $v);
                }
            }
        }
    }

    private static function hasTransparency($im): bool
    {
        $w = imagesx($im);
        $h = imagesy($im);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if (((imagecolorat($im, $x, $y) >> 24) & 0x7F) > 16) {
                    return true;
                }
            }
        }
        return false;
    }

    // -------------------------------------------------------------- helpers

    private static function fillRoundRect($img, float $x0, float $y0, float $x1, float $y1, float $r, int $color): void
    {
        $x0 = (int)round($x0);
        $y0 = (int)round($y0);
        $x1 = (int)round($x1) - 1;
        $y1 = (int)round($y1) - 1;
        $r = (int)round(max(0, min($r, ($x1 - $x0) / 2, ($y1 - $y0) / 2)));
        if ($r < 1) {
            imagefilledrectangle($img, $x0, $y0, $x1, $y1, $color);
            return;
        }
        imagefilledrectangle($img, $x0 + $r, $y0, $x1 - $r, $y1, $color);
        imagefilledrectangle($img, $x0, $y0 + $r, $x1, $y1 - $r, $color);
        foreach ([[$x0 + $r, $y0 + $r], [$x1 - $r, $y0 + $r], [$x0 + $r, $y1 - $r], [$x1 - $r, $y1 - $r]] as [$cx, $cy]) {
            imagefilledellipse($img, $cx, $cy, 2 * $r, 2 * $r, $color);
        }
    }

    private static function fillPolygon($img, array $points, int $color): void
    {
        $flat = [];
        foreach ($points as [$px, $py]) {
            $flat[] = (int)round($px);
            $flat[] = (int)round($py);
        }
        if (PHP_VERSION_ID >= 80000) {
            imagefilledpolygon($img, $flat, $color);
        } else {
            imagefilledpolygon($img, $flat, count($points), $color);
        }
    }

    /** Pure black & white: a 2-colour palette image, saved as a 1-bit PNG. */
    private static function toMono($src)
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $dst = imagecreate($w, $h);
        imagecolorallocate($dst, 255, 255, 255);   // first colour = background
        $black = imagecolorallocate($dst, 0, 0, 0);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($src, $x, $y);
                $lum = 0.2126 * (($c >> 16) & 0xFF) + 0.7152 * (($c >> 8) & 0xFF) + 0.0722 * ($c & 0xFF);
                if ($lum < self::MONO_THRESHOLD) {
                    imagesetpixel($dst, $x, $y, $black);
                }
            }
        }
        return $dst;
    }
}
