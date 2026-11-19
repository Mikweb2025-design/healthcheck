#!/usr/bin/env python3
"""Creates img/app.svg (white, navigation), img/app-dark.svg, icon-192/512, maskable, apple-touch-icon.
Motif: three activity rings around a heart-beat line. Run: python3 tests/frontend/tools/make_icons.py"""
import math
from pathlib import Path
from PIL import Image, ImageDraw

OUT = Path(__file__).resolve().parents[3] / "img"
S = 1024
RINGS = [((229, 72, 77), 0.40), ((242, 153, 74), 0.31), ((43, 182, 115), 0.22)]
FILL = [0.82, 0.68, 0.9]


def draw(size, pad):
    img = Image.new("RGBA", (S, S))
    d = ImageDraw.Draw(img)
    for y in range(S):  # vertical gradient background
        t = y / S
        c = (int(18 + 12 * t), int(22 + 10 * t), int(30 + 22 * t), 255)
        d.line([(0, y), (S, y)], fill=c)
    cx = cy = S / 2
    w = int(S * 0.075 * (1 - pad))
    for (col, r), f in zip(RINGS, FILL):
        rr = S * r * (1 - pad)
        box = [cx - rr, cy - rr, cx + rr, cy + rr]
        d.arc(box, 0, 360, fill=tuple(int(c * 0.28 + 24 * 0.72) for c in col) + (255,), width=w)
        d.arc(box, -90, -90 + 360 * f, fill=col + (255,), width=w)
    # pulse line
    k = (1 - pad)
    pts = [(-0.16, 0), (-0.07, 0), (-0.035, -0.09), (0.0, 0.1), (0.035, -0.04), (0.06, 0), (0.16, 0)]
    pts = [(cx + x * S * k, cy + y * S * k) for x, y in pts]
    d.line(pts, fill=(255, 255, 255, 255), width=int(S * 0.028 * k), joint="curve")
    return img.resize((size, size), Image.LANCZOS)


def svg(color):
    parts = ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="32" height="32" fill="none" stroke="%s" stroke-linecap="round" stroke-linejoin="round">' % color]
    for r, sw, f in [(13.5, 2.6, 0.82), (9.6, 2.6, 0.68), (5.8, 2.6, 0.9)]:
        a = -math.pi / 2 + 2 * math.pi * f
        x, y = 16 + r * math.cos(a), 16 + r * math.sin(a)
        large = 1 if f > 0.5 else 0
        parts.append('<path d="M16 %.2f A%.2f %.2f 0 %d 1 %.2f %.2f" stroke-width="%.1f"/>' % (16 - r, r, r, large, x, y, sw))
    parts.append('</svg>')
    return "".join(parts)


if __name__ == "__main__":
    OUT.mkdir(exist_ok=True)
    draw(192, 0).save(OUT / "icon-192.png")
    draw(512, 0).save(OUT / "icon-512.png")
    draw(512, 0.18).save(OUT / "icon-maskable-512.png")
    draw(180, 0).convert("RGB").save(OUT / "apple-touch-icon.png")
    draw(64, 0).save(OUT / "app.png")
    (OUT / "app.svg").write_text(svg("#ffffff"))
    (OUT / "app-dark.svg").write_text(svg("#222831"))
    print("icons written to", OUT)
