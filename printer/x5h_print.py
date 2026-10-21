#!/usr/bin/env python3
"""
Prints a picture on an X5h / X6h Bluetooth thermal printer (the printers of the TinyPrint app).

    py x5h_print.py label.png                       # the first X5h/X6h printer it finds
    py x5h_print.py label.png --printer X5h-782D    # by name or Bluetooth address
    py x5h_print.py label.png --density darkest --feed 120 --copies 3
    py x5h_print.py --scan                          # list the printers nearby

Needs: py -m pip install bleak pillow
The printer must be on and not connected to the phone (close TinyPrint first).
No pairing in Windows is needed.

Protocol (the "cat printer" family): Bluetooth LE service AE30, write to AE01,
notifications on AE02. Every command is
    51 78 <cmd> 00 <length lo> <length hi> <payload> <crc8 of payload> FF
and every printed row is 48 bytes = 384 dots, bit (x % 8) of byte (x // 8) = dot x, 1 = black.
"""

import argparse
import asyncio
import sys

from bleak import BleakClient, BleakScanner
from PIL import Image

WIDTH = 384                      # dots across the print head (48 mm at 203 dpi)
SERVICE = "0000ae30-0000-1000-8000-00805f9b34fb"
WRITE_CHARS = ["0000ae01-0000-1000-8000-00805f9b34fb", "0000ae03-0000-1000-8000-00805f9b34fb"]
NOTIFY_CHAR = "0000ae02-0000-1000-8000-00805f9b34fb"
NAME_PREFIXES = ("X5", "X6")

# (heating energy, print quality, seconds between rows, print speed, extra stroke px) per density.
# More energy = darker, but 0xFFFF is the most the printer takes; past "darkest" the print
# speed command slows the paper down (more heat per row; None = the printer's own speed)
# and the black parts get thicker (white parts, like the text in the tag, get thinner).
DENSITY = {
    "light": (0x4000, 0x25, 0.015, None, 0),
    "normal": (0x8000, 0x35, 0.02, None, 0),
    "dark": (0xC000, 0x45, 0.025, None, 0),
    "darkest": (0xFFFF, 0x55, 0.03, None, 0),
    "super-darkest": (0xFFFF, 0x55, 0.04, 45, 0),
    "even-darker": (0xFFFF, 0x55, 0.04, 45, 1),
    "impossible": (0xFFFF, 0x55, 0.05, 60, 2),
}

LATTICE_START = bytes([0xAA, 0x55, 0x17, 0x38, 0x44, 0x5F, 0x5F, 0x5F, 0x44, 0x38, 0x2C])
LATTICE_END = bytes([0xAA, 0x55, 0x17, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x17])


def crc8(data: bytes) -> int:
    crc = 0
    for b in data:
        crc ^= b
        for _ in range(8):
            crc = ((crc << 1) ^ 0x07) & 0xFF if crc & 0x80 else (crc << 1) & 0xFF
    return crc


def packet(cmd: int, payload: bytes = b"") -> bytes:
    n = len(payload)
    return bytes([0x51, 0x78, cmd, 0x00, n & 0xFF, n >> 8]) + payload + bytes([crc8(payload), 0xFF])


def u16(value: int) -> bytes:
    return bytes([value & 0xFF, (value >> 8) & 0xFF])


def image_rows(path: str, threshold: int = 128) -> list:
    """The picture as printer rows. Wider than 384 dots is scaled down, narrower is centred."""
    img = Image.open(path).convert("RGBA")
    page = Image.new("RGBA", img.size, "white")   # transparent = white paper
    page.alpha_composite(img)
    img = page.convert("L")
    if img.width > WIDTH:
        img = img.resize((WIDTH, round(img.height * WIDTH / img.width)), Image.Resampling.LANCZOS)
    left = (WIDTH - img.width) // 2
    px = img.load()
    rows = []
    for y in range(img.height):
        row = bytearray(WIDTH // 8)
        for x in range(img.width):
            if px[x, y] < threshold:
                dot = left + x
                row[dot >> 3] |= 1 << (dot & 7)
        rows.append(bytes(row))
    return rows


def bolden(rows: list, px: int) -> list:
    """Makes black lines and text px dots thicker: grows them right and down, then left and up."""
    if px <= 0 or not rows:
        return rows
    mask = (1 << WIDTH) - 1
    vals = [int.from_bytes(row, "little") for row in rows]   # bit x = dot x
    for k in range(px):
        if k % 2 == 0:
            vals = [(v | v << 1) & mask for v in vals]
            vals = [v | (vals[i - 1] if i else 0) for i, v in enumerate(vals)]
        else:
            vals = [v | v >> 1 for v in vals]
            vals = [v | (vals[i + 1] if i + 1 < len(vals) else 0) for i, v in enumerate(vals)]
    return [v.to_bytes(WIDTH // 8, "little") for v in vals]


def print_job(rows: list, density: str, feed: int, copies: int) -> list:
    energy, quality, _, speed, bold = DENSITY[density]
    rows = bolden(rows, bold)
    job = [
        packet(0xA3, b"\x00"),            # get state
        packet(0xA4, bytes([quality])),   # print quality
        packet(0xA6, LATTICE_START),
        packet(0xAF, u16(energy)),        # heating energy
        packet(0xBE, b"\x00"),            # image mode (not text)
    ]
    if speed is not None:
        job.append(packet(0xBD, bytes([speed])))   # print speed: higher = slower
    for _ in range(copies):
        job += [packet(0xA2, row) for row in rows]
    job += [
        packet(0xA1, u16(feed)),          # feed the paper out to tear it off
        packet(0xA6, LATTICE_END),
        packet(0xA3, b"\x00"),
    ]
    return job


def is_printer(name: str, adv) -> bool:
    return name.upper().startswith(NAME_PREFIXES) or SERVICE in [u.lower() for u in adv.service_uuids]


async def find_printer(wanted: str, timeout: float):
    def match(dev, adv):
        name = adv.local_name or dev.name or ""
        if wanted:
            return wanted.lower() in (name.lower(), dev.address.lower())
        return is_printer(name, adv)

    return await BleakScanner.find_device_by_filter(match, timeout=timeout)


async def scan(timeout: float) -> int:
    found = await BleakScanner.discover(timeout=timeout, return_adv=True)
    printers = [(adv.local_name or dev.name or "", dev.address, adv.rssi)
                for dev, adv in found.values() if is_printer(adv.local_name or dev.name or "", adv)]
    if not printers:
        print("No printers found. Is it on, close by, and not connected to the phone?")
        return 1
    for name, address, rssi in sorted(printers):
        print(f"{name:20} {address}  signal {rssi} dBm")
    return 0


async def send(device, job: list, delay: float) -> None:
    resume = asyncio.Event()
    resume.set()

    def on_notify(_, data: bytearray):
        # 51 78 AE ... 10 = the printer's buffer is full, wait; 00 = carry on
        if len(data) >= 7 and data[0] == 0x51 and data[1] == 0x78 and data[2] == 0xAE:
            if data[6] == 0x10:
                resume.clear()
            else:
                resume.set()

    async with BleakClient(device) as client:
        char = None
        for uuid in WRITE_CHARS:
            char = client.services.get_characteristic(uuid)
            if char:
                break
        if char is None:
            raise RuntimeError("This printer has no AE01/AE03 characteristic: it may use another protocol.")
        try:
            await client.start_notify(NOTIFY_CHAR, on_notify)
        except Exception:
            pass   # no flow control; the delay keeps the pace slow enough
        response = "write-without-response" not in char.properties

        for i, data in enumerate(job, 1):
            try:
                await asyncio.wait_for(resume.wait(), 15)
            except asyncio.TimeoutError:
                raise RuntimeError("The printer stopped answering (out of paper or cover open?)")
            await client.write_gatt_char(char, data, response=response)
            await asyncio.sleep(delay)
            if i % 32 == 0 or i == len(job):
                print(f"\rSending {i}/{len(job)}", end="", flush=True)
        print()
        await asyncio.sleep(1.5)   # let it finish printing and feeding before hanging up


async def main() -> int:
    ap = argparse.ArgumentParser(description="Print a picture on an X5h/X6h Bluetooth thermal printer.")
    ap.add_argument("image", nargs="?", help="PNG/JPG/... to print (384 dots wide fits exactly)")
    ap.add_argument("--printer", default="", help="name (e.g. X5h-782D) or address; default: the first one found")
    ap.add_argument("--density", choices=DENSITY, default="dark", help="how dark to print (default dark)")
    ap.add_argument("--feed", type=int, default=80, help="dots of paper to feed after printing (8 dots = 1 mm)")
    ap.add_argument("--copies", type=int, default=1)
    ap.add_argument("--delay", type=float, default=None,
                    help="seconds between rows (default: set by the density; raise it if prints get garbled)")
    ap.add_argument("--timeout", type=float, default=10, help="seconds to look for the printer")
    ap.add_argument("--scan", action="store_true", help="list the printers nearby and exit")
    args = ap.parse_args()

    if args.scan:
        return await scan(args.timeout)
    if not args.image:
        ap.error("give the image to print, or --scan")

    rows = image_rows(args.image)
    job = print_job(rows, args.density, max(0, min(args.feed, 0xFFFF)), max(1, args.copies))

    device = await find_printer(args.printer, args.timeout)
    if device is None:
        who = args.printer or "an X5h/X6h printer"
        print(f"Could not find {who}. Is it on, close by, and not connected to the phone?", file=sys.stderr)
        return 1
    print(f"Printing {len(rows)} rows x {args.copies} on {device.name or device.address}")
    await send(device, job, args.delay if args.delay is not None else DENSITY[args.density][2])
    print("Done")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(asyncio.run(main()))
    except KeyboardInterrupt:
        sys.exit(130)
    except Exception as e:
        print(f"Error: {e}", file=sys.stderr)
        sys.exit(1)
