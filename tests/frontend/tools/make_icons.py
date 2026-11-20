#!/usr/bin/env python3
"""Creates img/app.svg (white, navigation), img/app-dark.svg, icon-192/512, maskable, apple-touch-icon.
Motif: three activity rings (sleep, recovery, movement) around a heart with a pulse line cut out.
Run: python3 tests/frontend/tools/make_icons.py"""
import math
from pathlib import Path
from PIL import Image, ImageDraw, ImageFilter

OUT = Path(__file__).resolve().parents[3] / "img"
S = 2048  # drawn large, scaled down for smooth edges
RINGS = [((255, 92, 98), 0.405), ((255, 168, 76), 0.315), ((52, 211, 140), 0.225)]
FILL = [0.82, 0.68, 0.90]
HEART = [(16, 21.6), ((11.3, 18.2), (10, 15.8), (10, 14.1)), ((10, 12.1), (11.5, 10.8), (13.1, 10.8)), ((14.4, 10.8), (15.4, 11.5), (16, 12.7)),
         ((16.6, 11.5), (17.6, 10.8), (18.9, 10.8)), ((20.5, 10.8), (22, 12.1), (22, 14.1)), ((22, 15.8), (20.7, 18.2), (16, 21.6))]


def heart_points(cx, cy, scale, n=24):
    """polygon of the heart (cubic beziers of HEART in 32x32 units, centred on cx, cy)"""
    pts, p0 = [], HEART[0]
    for c1, c2, p3 in HEART[1:]:
        for i in range(1, n + 1):
            t = i / n
            x = (1 - t) ** 3 * p0[0] + 3 * (1 - t) ** 2 * t * c1[0] + 3 * (1 - t) * t ** 2 * c2[0] + t ** 3 * p3[0]
            y = (1 - t) ** 3 * p0[1] + 3 * (1 - t) ** 2 * t * c1[1] + 3 * (1 - t) * t ** 2 * c2[1] + t ** 3 * p3[1]
            pts.append((cx + (x - 16) * scale, cy + (y - 16.2) * scale))
        p0 = p3
    return pts


def cap_arc(d, box, start, end, color, width):
    d.arc(box, start, end, fill=color, width=width)
    cx, cy = (box[0] + box[2]) / 2, (box[1] + box[3]) / 2
    r = (box[2] - box[0]) / 2 - width / 2
    for a in (start, end):
        x, y = cx + r * math.cos(math.radians(a)), cy + r * math.sin(math.radians(a))
        d.ellipse([x - width / 2, y - width / 2, x + width / 2, y + width / 2], fill=color)


def draw(size, pad):
    k = 1 - pad
    img = Image.new("RGBA", (S, S))
    d = ImageDraw.Draw(img)
    for y in range(S):  # diagonal-ish gradient: deep navy to blue
        t = y / S
        d.line([(0, y), (S, y)], fill=(int(14 + 14 * t), int(24 + 22 * t), int(42 + 38 * t), 255))
    glow = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    gd = ImageDraw.Draw(glow)
    gd.ellipse([S * 0.18, S * 0.12, S * 0.82, S * 0.76], fill=(60, 140, 255, 70))
    img = Image.alpha_composite(img, glow.filter(ImageFilter.GaussianBlur(S * 0.09)))
    d = ImageDraw.Draw(img)
    cx = cy = S / 2
    w = int(S * 0.082 * k)
    for (col, r), f in zip(RINGS, FILL):
        rr = S * r * k
        box = [cx - rr, cy - rr, cx + rr, cy + rr]
        track = tuple(int(c * 0.22 + 26 * 0.78) for c in col) + (255,)
        d.ellipse(box, outline=track, width=w)
        cap_arc(d, box, -90, -90 + 360 * f, col + (255,), w)
    sc = S * k * 0.0165 * 1.0  # heart: 12 units wide in the 32 grid
    hp = heart_points(cx, cy, S * k * 0.0185)
    heart = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    hd = ImageDraw.Draw(heart)
    hd.polygon(hp, fill=(255, 255, 255, 255))
    pk = [(-0.115, 0.012), (-0.05, 0.012), (-0.026, -0.052), (0.004, 0.07), (0.03, -0.03), (0.05, 0.012), (0.115, 0.012)]
    pk = [(cx + x * S * k, cy + y * S * k) for x, y in pk]
    hd.line(pk, fill=(0, 0, 0, 0), width=int(S * 0.022 * k), joint="curve")  # pulse cut out of the heart
    for x, y in (pk[0], pk[-1]):
        pass
    img = Image.alpha_composite(img, heart)
    return img.resize((size, size), Image.LANCZOS)


def svg(color):
    """navigation icon: two rings and the heart with the pulse cut out (mask)"""
    parts = ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="32" height="32" fill="none" stroke="%s" stroke-linecap="round" stroke-linejoin="round">' % color,
             '<mask id="m"><rect width="32" height="32" fill="#fff" stroke="none"/><path d="M11.4 16.3H13.7L14.7 13.6L16.3 18.6L17.6 15.2L18.4 16.3H20.8" stroke="#000" stroke-width="1.5"/></mask>']
    for r, sw, f in [(13.6, 2.8, 0.82), (9.8, 2.8, 0.7)]:
        a = -math.pi / 2 + 2 * math.pi * f
        x, y = 16 + r * math.cos(a), 16 + r * math.sin(a)
        large = 1 if f > 0.5 else 0
        parts.append('<path d="M16 %.2f A%.2f %.2f 0 %d 1 %.2f %.2f" stroke-width="%.1f"/>' % (16 - r, r, r, large, x, y, sw))
    d = "M16 21.6C11.3 18.2 10 15.8 10 14.1C10 12.1 11.5 10.8 13.1 10.8C14.4 10.8 15.4 11.5 16 12.7C16.6 11.5 17.6 10.8 18.9 10.8C20.5 10.8 22 12.1 22 14.1C22 15.8 20.7 18.2 16 21.6Z"
    parts.append('<path d="%s" fill="%s" stroke="none" mask="url(#m)"/>' % (d, color))
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
