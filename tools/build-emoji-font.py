#!/usr/bin/env python3
"""
Builds fonts/NotoEmoji-Labels.ttf + .codepoints: the black & white Noto Emoji as an icon font.

GD cannot draw characters above U+FFFF, where the emoji live, so every single-character emoji
is copied to the private use area (U+E000...) of a bold, non-variable copy of the font.
The .codepoints file lists "name hex" like the Material Icons one. Emoji whose name is
taken by Material Icons get an _emoji suffix (egg_emoji), except the ALIASES below.

    py -m pip install fonttools
    py tools/build-emoji-font.py NotoEmoji[wght].ttf emoji-test.txt

NotoEmoji[wght].ttf: https://github.com/google/fonts/tree/main/ofl/notoemoji (OFL)
emoji-test.txt:      https://unicode.org/Public/emoji/latest/emoji-test.txt
"""

import re
import sys
import unicodedata
from pathlib import Path

from fontTools.subset import Options, Subsetter
from fontTools.ttLib import TTFont
from fontTools.varLib.instancer import instantiateVariableFont

WEIGHT = 600          # bolder lines print better on a thermal printer
PUA_START = 0xE000
FONTS = Path(__file__).resolve().parent.parent / "fonts"

# Short names for common label icons: alias -> emoji name
ALIASES = {
    "apple": "red_apple", "orange": "tangerine", "kiwi": "kiwi_fruit", "corn": "ear_of_corn",
    "chili": "hot_pepper", "pepper": "bell_pepper", "sweet_potato": "roasted_sweet_potato",
    "honey": "honey_pot", "nuts": "peanuts", "berries": "blueberries", "herb": "herb",
    "milk": "glass_of_milk", "bread": "bread", "cheese": "cheese_wedge", "salt": "salt",
    "mushroom": "mushroom", "olive": "olive", "ginger": "ginger_root", "peas": "pea_pod",
    "beans": "beans", "jalapeno": "hot_pepper", "lime": "lemon",
}


def slug(name: str) -> str:
    name = unicodedata.normalize("NFKD", name).encode("ascii", "ignore").decode()
    return re.sub(r"[^a-z0-9]+", "_", name.lower()).strip("_")


def main(font_path: str, test_path: str) -> None:
    material = set()
    for line in (FONTS / "MaterialIcons-Regular.codepoints").read_text().splitlines():
        if line.strip():
            material.add(line.split()[0])

    font = TTFont(font_path)
    if "fvar" in font:
        font = instantiateVariableFont(font, {"wght": WEIGHT})
    cmap = font.getBestCmap()

    # name -> glyph for every emoji that is one character (plus an optional FE0F)
    emoji = {}
    for line in Path(test_path).read_text(encoding="utf-8").splitlines():
        m = re.match(r"^([0-9A-F ]+?)\s*;\s*fully-qualified\s*#\s*\S+\s+E[\d.]+\s+(.+)$", line)
        if not m:
            continue
        cps = [int(c, 16) for c in m.group(1).split() if c != "FE0F"]
        name = slug(m.group(2))
        if len(cps) != 1 or cps[0] not in cmap or not name:
            continue
        if name in material:
            name += "_emoji"        # Material Icons keeps the plain name
        emoji.setdefault(name, cmap[cps[0]])
    # Aliases win over Material Icons names (Material "apple" is the company logo)
    for alias, target in ALIASES.items():
        if alias not in emoji and target in emoji:
            emoji[alias] = emoji[target]

    # One private-use character per distinct glyph
    code_of_glyph, names = {}, []
    for name, glyph in emoji.items():
        if glyph not in code_of_glyph:
            code_of_glyph[glyph] = PUA_START + len(code_of_glyph)
        names.append((name, code_of_glyph[glyph]))

    for table in font["cmap"].tables:
        if table.isUnicode():
            table.cmap = {code: glyph for glyph, code in code_of_glyph.items()} if table.format in (4, 12) else {}
    font["cmap"].tables = [t for t in font["cmap"].tables if t.isUnicode() and t.format in (4, 12)]

    options = Options()
    options.layout_features = []
    options.notdef_outline = True
    options.name_IDs = ["*"]
    subsetter = Subsetter(options)
    subsetter.populate(unicodes=list(code_of_glyph.values()))
    subsetter.subset(font)
    font.save(FONTS / "NotoEmoji-Labels.ttf")

    with open(FONTS / "NotoEmoji-Labels.codepoints", "w", newline="\n") as f:
        for name, code in sorted(names):
            f.write(f"{name} {code:x}\n")
    print(f"{len(names)} names, {len(code_of_glyph)} glyphs -> {FONTS / 'NotoEmoji-Labels.ttf'}")


if __name__ == "__main__":
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    main(sys.argv[1], sys.argv[2])
