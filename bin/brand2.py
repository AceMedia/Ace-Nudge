"""Ace mark + leaning NUDGE lockup: one solid colour, NUDGE the same height as the mark."""
import re, math
from fontTools.ttLib import TTFont
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.pens.boundsPen import BoundsPen

LOGO = '/var/www/html/AceMedia/logo.svg'
FONTS = {'dejavu': '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
         'cantarell': '/usr/share/fonts/opentype/cantarell/Cantarell-Bold.otf',
         'nimbus': '/usr/share/fonts/opentype/urw-base35/NimbusSans-Bold.otf',
         'quicksand': '/usr/share/fonts/truetype/quicksand/Quicksand-Bold.ttf'}

def mark(x, y, h, fill):
    d = re.findall(r' d="([^"]+)"', open(LOGO).read())[0]
    k = h / 307.0
    return f'<path transform="translate({x:.1f},{y:.1f}) scale({k:.4f})" d="{d}" fill="{fill}"/>', 537 * k

def nudge(x, y, h, fill, font='nimbus', lean=9, push=2.5, track=0.06, weight=0.015, xs=0.86):
    """NUDGE, cap height h, top at y. Every letter leans, and each leans a little further and sits closer than the one before, as if shoved along."""
    f = TTFont(FONTS[font]); gs, cm = f.getGlyphSet(), f.getBestCmap()
    bp = BoundsPen(gs); gs[cm[ord('H')]].draw(bp); cap = bp.bounds[3]
    k = h * 0.95 / cap; cx = 0; out = []; y = y + h * 0.015
    for i, ch in enumerate(' NUDGE'.strip()):
        n = cm[ord(ch)]; pen = SVGPathPen(gs)
        sk = math.tan(math.radians(lean + push * i))
        # baseline at y+h; skew about the baseline so the letter leans right
        gs[n].draw(TransformPen(pen, (k * xs, 0, sk * k, -k, cx, y + h * 0.95)))
        out.append(pen.getCommands())
        cx += gs[n].width * k * xs * (1 + track) * (1 - 0.018 * i)
    sw = h * weight
    p = f'<path d="{" ".join(out)}" fill="{fill}" stroke="{fill}" stroke-width="{sw:.1f}" stroke-linejoin="round"/>'
    return f'<g transform="translate({x:.1f},0)">{p}</g>', cx + h * 0.15

def lockup(x, y, h, fill, gap=0.16, **kw):
    m, mw = mark(x, y, h, fill)
    n, nw = nudge(x + mw + h * gap, y, h, fill, **kw)
    return m + n, mw + h * gap + nw
