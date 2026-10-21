# labelmaker

Small thermal-printer labels with PHP + GD: product name, quantity tag and an optional icon,
as a crisp 1-bit black & white PNG.

```
+-------------------------------------------+
|  [icon]  Propylene               +------+ |
|          Glycol                  | 3ml  | |
|                                  +------+ |
+-------------------------------------------+
```

Default size: 384 x 128 px = 48 x 16 mm on a 384-dot (203 dpi) printer.

## Install

```
composer require tiagoandre/labelmaker
```

Needs PHP 7.4+ with GD and FreeType (e.g. `apt install php-gd`).

## Use

```php
use LabelMaker\LabelMaker;

$maker = new LabelMaker();                 // or new LabelMaker('/path/to/icons')
$maker->save('label.png', ['text' => 'Propylene Glycol', 'qty' => '3ml', 'icon' => 'drop']);
$png = $maker->png(['text' => 'Glycerin', 'qty' => '10ml', 'font-size' => 40]);
$img = $maker->render([...]);              // GD image
```

From a web request, pass `true` so only safe options are read and no file paths are accepted:

```php
echo (new LabelMaker(__DIR__ . '/icons'))->png($_GET, true);
```

See [examples/label.php](examples/label.php).

Command line:

```
vendor/bin/labelmaker --text="Propylene Glycol" --qty=3ml
vendor/bin/labelmaker --text="Ácido Cítrico" --qty="250 g" --icon=flask --font-size=36 --out=citric.png
```

## Print on an X5h / X6h Bluetooth printer

The small thermal printers that use the TinyPrint app (X5h, X6h, ...) can print straight from
a laptop over Bluetooth. No pairing is needed, but the printer must be on and **not connected
to the phone** (close TinyPrint first).

### Command line

Needs Python 3 with two packages:

```
py -m pip install -r printer/requirements.txt
```

```
vendor/bin/labelmaker --text="Glycerin" --qty=10ml --print                   # first printer found
vendor/bin/labelmaker --text="Glycerin" --qty=10ml --print=X5h-782D --copies=3
vendor/bin/labelmaker --text="Glycerin" --qty=10ml --print --density=dark --feed=120
```

Or print any picture directly:

```
py printer/x5h_print.py label.png --printer X5h-782D
py printer/x5h_print.py --scan            # list the printers nearby
```

| Print option | Meaning |
|--------------|---------|
| `--print[=NAME]` | Print after saving; NAME is the printer's Bluetooth name or address (default: the first X5h/X6h found) |
| `--density`  | `light`, `normal`, `dark` (default), `darkest` (full heat), `super-darkest` (full heat, slower), `even-darker` (+ lines 1 dot thicker) or `impossible` (slowest, + 2 dots thicker; small text may fill in) |
| `--feed`     | Paper to feed after printing, in dots (8 dots = 1 mm; default 80) |
| `--copies`   | How many labels (default 1) |

Python is run as `py -3` on Windows and `python3` elsewhere; set `LABELMAKER_PYTHON` to change it.

### Browser

[examples/index.html](examples/index.html) is a page to type a label, see it, and print it
from Chrome or Edge (Web Bluetooth; not Firefox or Safari):

```
php -S localhost:8000 -t examples
```

then open <http://localhost:8000>, click **Connect printer**, pick the printer and click **Print**.

## Options

| Option       | Meaning |
|--------------|---------|
| `text`       | Product name (required). A `\|` forces a line break: `Propylene\|Glycol` |
| `qty`        | Volume or weight: `3ml`, `250 g`, `1,5 L` ... Leave it out for no tag |
| `info`       | Extra lines under the name (up to 8): packing date, expiry, lot, storage ... A list, or one string split by `\|`. On the command line repeat `--info` |
| `info-size`  | Size of the info lines in px; `auto` (default) = 18 px on a 128 px label. 3+ lines may go in two columns |
| `info-gap`   | Space between the name and the info lines in px; `auto` (default) = 5 px on a 128 px label |
| `info-spacing` | Extra space between the info lines in px; `auto` (default) = 20% of their size. A set value stays fixed when the lines shrink to fit |
| `date-format`| How dates are written, in PHP `date()` letters (default `d/m/Y`) |
| `icon`       | An icon name (see [Icons](#icons): `apple`, `pumpkin`, `carrot`, `eco` ...); a PNG/JPG/GIF/WebP path; or a file name (no extension) from the icon folder |
| `icon-mode`  | `alpha`, `luma` or `auto` (default): how a picture icon becomes black & white |
| `width`      | Label width in px (default 384) |
| `height`     | Label height in px (default 128) |
| `font-size`  | Name size in px; `auto` (default) = as big as fits. Still shrinks if the name doesn't fit |
| `frame`      | Rounded border to cut along (default on) |
| `mono`       | Pure black & white 1-bit PNG (default on); off = smooth greyscale |
| `dpi`        | Resolution written into the PNG (default 203) |
| `font`       | TTF file for the name (not from untrusted input) |
| `font-heavy` | TTF file for the tag (not from untrusted input) |

### Dates

In `text`, `qty` and `info`, `{today}` becomes today's date, and `{today+3d}`, `{today+2w}`,
`{today+6m}`, `{today+1y}` (or `-`) a date that many days, weeks, months or years away:

```
vendor/bin/labelmaker --text="Lavender Oil" --qty=10ml --icon=spa \
    --info="Packed: {today}" --info="Exp: {today+2y}" --info="Lot: A12"
```

For 3 or more info lines, a taller label keeps them readable: `--height=176` (22 mm).

### Icons

About 3,600 icons, by name:
- **Built in:** `drop`, `flask`, `pumpkin`.
- **Noto Emoji** (~1,400): fruit and vegetables (`apple`, `pear`, `orange`, `lemon`, `banana`,
  `grapes`, `strawberry`, `cherries`, `carrot`, `corn`, `garlic`, `onion`, `potato`, `mushroom`,
  `chili` ...), food (`bread`, `cheese`, `honey`, `milk`, `salt` ...), `jack_o_lantern`,
  lab and care (`test_tube`, `lotion_bottle`, `soap` ...) and everything else in emoji.
- **Material Icons** (~2,200): `eco`, `science`, `ac_unit`, `local_fire_department`, `warning` ...
  (<https://fonts.google.com/icons>, "Material Icons" style).

The browser page has them in groups (Fruit, Vegetables, Food & drink, Lab & care, Storage &
warnings) plus a search. On the command line:

```
vendor/bin/labelmaker --icons --search=apple
```

`fonts/NotoEmoji-Labels.ttf` is made from Noto Emoji by `tools/build-emoji-font.py`
(see the instructions at the top of that script).

## Fonts

Bundled in `fonts/`:
- Barlow Semi Condensed Bold / ExtraBold for the text (SIL Open Font License, `fonts/OFL.txt`),
  from <https://fonts.google.com/specimen/Barlow+Semi+Condensed>.
- Material Icons for the icons (Apache 2.0, `fonts/MaterialIcons-LICENSE.txt`),
  from <https://github.com/google/material-design-icons>.
- Noto Emoji for the emoji icons (SIL Open Font License, `fonts/NotoEmoji-OFL.txt`),
  from <https://fonts.google.com/noto/specimen/Noto+Emoji>.

Pass a different folder for the text fonts as the second constructor argument:
`new LabelMaker($iconDir, $fontDir)`.
