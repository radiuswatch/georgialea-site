"""Build ClipNow logo SVGs with text converted to outlines (no font needed to view them).

Run: python3 scripts/build-clipnow-logo.py clipper/brand   (needs: pip install fonttools)
"""
import pathlib, sys
from fontTools.ttLib import TTFont
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.boundsPen import BoundsPen
from fontTools.pens.transformPen import TransformPen

OUT = pathlib.Path(sys.argv[1]); OUT.mkdir(parents=True, exist_ok=True)
MONO = TTFont('/usr/share/fonts/truetype/dejavu/DejaVuSansMono-Bold.ttf')
SANS = TTFont('/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf')

GREEN, INK, LIGHT, DARK = '#31C971', '#06230F', '#E9EDE9', '#0B0D0B'

def text_path(font, text, size, x=0.0, y=0.0, tracking=0.0):
    """SVG path data for text with its baseline at y, plus (width, top, bottom) of the ink."""
    upm = font['head'].unitsPerEm; s = size / upm
    cmap = font.getBestCmap(); gs = font.getGlyphSet(); hmtx = font['hmtx']
    d, pen_x = [], 0.0
    bounds = BoundsPen(gs)
    for ch in text:
        g = cmap[ord(ch)]
        # flip y (font units go up, SVG goes down) and place glyph
        t = (s, 0, 0, -s, x + pen_x, y)
        sp = SVGPathPen(gs); gs[g].draw(TransformPen(sp, t)); d.append(sp.getCommands())
        gs[g].draw(TransformPen(bounds, t))
        pen_x += hmtx[g][0] * s + tracking
    xmin, ymin, xmax, ymax = bounds.bounds
    return ' '.join(d), (xmin, ymin, xmax, ymax), pen_x - tracking

def mark(size=120, ox=0, oy=0):
    r = size * 8 / 30
    # "CN" at the site's ratio (13px text in a 30px tile), centred on its actual ink
    d, (x0, y0, x1, y1), _ = text_path(MONO, 'CN', size * 13 / 30 * 1.12)
    dx = ox + size / 2 - (x0 + x1) / 2; dy = oy + size / 2 - (y0 + y1) / 2
    d, _, _ = text_path(MONO, 'CN', size * 13 / 30 * 1.12, dx, dy)
    return (f'<rect x="{ox}" y="{oy}" width="{size}" height="{size}" rx="{r:.2f}" fill="{GREEN}"/>'
            f'<path d="{d}" fill="{INK}"/>')

def svg(w, h, body, title):
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {w:.0f} {h:.0f}" width="{w:.0f}" height="{h:.0f}" role="img" aria-label="{title}">'
            f'<title>{title}</title>{body}</svg>\n')

# 1) The mark on its own (app icon / profile picture)
(OUT / 'clipnow-mark.svg').write_text(svg(120, 120, mark(120), 'ClipNow'))

# 2) Horizontal logo: mark + wordmark, at the site's proportions (30px mark, 17px text, 10px gap) scaled up,
#    with the wordmark slightly larger so it reads well at logo sizes.
M = 120; GAP = 40; TS = 72
probe, (wx0, wy0, wx1, wy1), adv = text_path(SANS, 'ClipNow', TS, tracking=-0.6)
cap_mid = (wy0 + wy1) / 2  # ink centre relative to baseline (negative)
base = M / 2 - cap_mid
pad = 6
W = M + GAP + (wx1 - wx0) + pad
for name, color in [('on-dark', LIGHT), ('on-light', DARK)]:
    d, _, _ = text_path(SANS, 'ClipNow', TS, M + GAP - wx0, base, tracking=-0.6)
    body = mark(M) + f'<path d="{d}" fill="{color}"/>'
    (OUT / f'clipnow-logo-{name}.svg').write_text(svg(W, M, body, 'ClipNow'))
print('logo width', round(W), 'x', M)
for f in sorted(OUT.glob('*.svg')): print(f.name, f.stat().st_size, 'bytes')
