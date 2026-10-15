#!/usr/bin/env python3
"""Build the WordPress.org catalogue assets into assets-wporg/.

WordPress.org reads these files from the plugin's SVN `assets/` directory (the
directory is *not* part of the plugin ZIP):

    banner-772x250.png, banner-1544x500.png
    icon-128x128.png,  icon-256x256.png
    screenshot-1.png … screenshot-N.png   (lowercase, numbered — the readme
                                          Screenshots list matches these numbers)

Screenshots are renamed copies of docs/screenshots/*.png; the banner and icon
artwork is drawn here so the set is reproducible instead of hand-made.

Usage:  python tools/make-wporg-assets.py
"""
import os
import shutil

from PIL import Image, ImageDraw, ImageFont

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'assets-wporg')
SHOTS = os.path.join(ROOT, 'docs', 'screenshots')

# Brand gradient from the admin design system (.c-logo): indigo → violet → fuchsia.
GRADIENT = ((99, 102, 241), (139, 92, 246), (217, 70, 239))
BACKGROUND = (9, 9, 11)

# readme.txt → == Screenshots == numbering.
SCREENSHOTS = [
    'dashboard-light.png',
    'dashboard-dark.png',
    'registry-dark.png',
    'settings-light.png',
    'integrations-light.png',
    'integrations-dark.png',
    'mobile-dashboard-light.png',
    'mobile-dashboard.png',
    'mobile-integrations.png',
]

FONT_BOLD = 'C:/Windows/Fonts/segoeuib.ttf'
FONT_REGULAR = 'C:/Windows/Fonts/segoeui.ttf'


def font(path, size):
    try:
        return ImageFont.truetype(path, size)
    except OSError:
        return ImageFont.load_default()


def gradient_image(width, height):
    """Diagonal brand gradient."""
    base = Image.new('RGB', (width, height))
    px = base.load()
    (r0, g0, b0), (r1, g1, b1), (r2, g2, b2) = GRADIENT
    for y in range(height):
        for x in range(width):
            t = (x / max(width - 1, 1) * 0.65) + (y / max(height - 1, 1) * 0.35)
            if t < 0.5:
                u = t * 2
                c = (r0 + (r1 - r0) * u, g0 + (g1 - g0) * u, b0 + (b1 - b0) * u)
            else:
                u = (t - 0.5) * 2
                c = (r1 + (r2 - r1) * u, g1 + (g2 - g1) * u, b1 + (b2 - b1) * u)
            px[x, y] = (int(c[0]), int(c[1]), int(c[2]))
    return base


def make_banner(width, height, name):
    img = gradient_image(width, height).convert('RGBA')
    draw = ImageDraw.Draw(img)

    scale = width / 1544
    title = font(FONT_BOLD, max(20, int(96 * scale)))
    subtitle = font(FONT_BOLD, max(12, int(46 * scale)))
    tagline = font(FONT_REGULAR, max(10, int(30 * scale)))

    pad = int(90 * scale)
    draw.text((pad, int(height * 0.20)), 'Spam Checker Pro', font=title, fill=(255, 255, 255, 255))
    draw.text((pad, int(height * 0.48)), 'for Contact Form 7', font=subtitle, fill=(255, 255, 255, 235))
    draw.text(
        (pad, int(height * 0.72)),
        'Honeypot · Timing · Rate limit · SQLi/XSS · AbuseIPDB · AI',
        font=tagline,
        fill=(255, 255, 255, 205),
    )

    img.convert('RGB').save(os.path.join(OUT, name), optimize=True)
    print(f'  ok  {name} ({width}x{height})')


def make_icon(size, name):
    img = gradient_image(size, size).convert('RGBA')

    # Rounded corners, like an app icon.
    radius = int(size * 0.22)
    mask = Image.new('L', (size, size), 0)
    ImageDraw.Draw(mask).rounded_rectangle([0, 0, size - 1, size - 1], radius=radius, fill=255)
    img.putalpha(mask)

    draw = ImageDraw.Draw(img)
    # Shield with a check mark — protection, drawn geometrically.
    w, h = size, size
    cx = w / 2
    top = h * 0.20
    bottom = h * 0.82
    half = w * 0.28
    shield = [
        (cx, top),
        (cx + half, top + h * 0.10),
        (cx + half, top + h * 0.34),
        (cx, bottom),
        (cx - half, top + h * 0.34),
        (cx - half, top + h * 0.10),
    ]
    draw.polygon(shield, fill=(255, 255, 255, 240))

    stroke = max(2, int(size * 0.075))
    check = [
        (cx - w * 0.13, top + h * 0.30),
        (cx - w * 0.03, top + h * 0.40),
        (cx + w * 0.15, top + h * 0.20),
    ]
    draw.line(check, fill=(99, 102, 241, 255), width=stroke, joint='curve')

    img.save(os.path.join(OUT, name), optimize=True)
    print(f'  ok  {name} ({size}x{size})')


def main():
    os.makedirs(OUT, exist_ok=True)

    print('Banners:')
    make_banner(1544, 500, 'banner-1544x500.png')
    make_banner(772, 250, 'banner-772x250.png')

    print('Icons:')
    make_icon(256, 'icon-256x256.png')
    make_icon(128, 'icon-128x128.png')

    print('Screenshots:')
    for index, source in enumerate(SCREENSHOTS, start=1):
        src = os.path.join(SHOTS, source)
        if not os.path.isfile(src):
            raise SystemExit(f'missing screenshot source: {src}')
        dst = os.path.join(OUT, f'screenshot-{index}.png')
        shutil.copyfile(src, dst)
        print(f'  ok  screenshot-{index}.png  <- docs/screenshots/{source}')

    print(f'\n{OUT}')


if __name__ == '__main__':
    main()
