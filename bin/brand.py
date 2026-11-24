"""Brand pieces for the README illustrations: the acemedia.ninja "Ace" mark and a skewed, chunky NUDGE wordmark.
Needs fontTools. Used by bin/build-images.py. Palette is taken from acemedia.ninja and acemedia.host."""
import re
from fontTools.ttLib import TTFont
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen

ORANGE, BLUE, NAVY, DEEP, PAPER, LINE, INK = '#f39510', '#336699', '#10233a', '#10294b', '#f4f7fb', '#dfe7f0', '#46566a'
FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'
LOGO = '/var/www/html/AceMedia/logo.svg'

def ace_mark(x, y, width, fill):
    """The acemedia.ninja logo (gothic Ace). Native size 537 by 307."""
    d = re.findall(r' d="([^"]+)"', open(LOGO).read())[0]
    k = width / 537.0
    return f'<path transform="translate({x},{y}) scale({k:.4f})" d="{d}" fill="{fill}"/>'

_font = TTFont(FONT)
def word_path(text, size):
    """Outline of text as one SVG path, baseline at y=0. Returns (d, width)."""
    gs, cmap = _font.getGlyphSet(), _font.getBestCmap()
    k, x, parts = size / _font['head'].unitsPerEm, 0, []
    for ch in text:
        name = cmap[ord(ch)]
        pen = SVGPathPen(gs)
        gs[name].draw(TransformPen(pen, (k, 0, 0, -k, x, 0)))
        parts.append(pen.getCommands())
        x += gs[name].width * k
    return ' '.join(parts), x

def nudge_plain(x, y, size, fill, stroke=None, sw=0, text='NUDGE'):
    """A skewed NUDGE, leaning right as if shoved."""
    d, w = word_path(text, size)
    st = f' stroke="{stroke}" stroke-width="{sw}" stroke-linejoin="round" paint-order="stroke"' if stroke else ''
    return f'<g transform="translate({x},{y}) skewX(-14)"><path d="{d}" fill="{fill}"{st}/></g>', w

def nudge_splat(x, y, size):
    """The big Nickelodeon style mark: white word, navy outline, orange splat behind, speed lines and a shove arrow."""
    d, w = word_path('NUDGE', size)
    pad, h = size * 0.42, size
    bw, bh = w + pad * 2, h * 1.75
    blob = (f'M{pad*0.3:.1f},{bh*0.45:.1f} C{pad*0.1:.1f},{bh*0.12:.1f} {bw*0.12:.1f},{-bh*0.02:.1f} {bw*0.30:.1f},{bh*0.08:.1f} '
            f'C{bw*0.42:.1f},{-bh*0.08:.1f} {bw*0.60:.1f},{bh*0.02:.1f} {bw*0.70:.1f},{bh*0.07:.1f} '
            f'C{bw*0.86:.1f},{-bh*0.04:.1f} {bw*1.00:.1f},{bh*0.14:.1f} {bw*0.97:.1f},{bh*0.42:.1f} '
            f'C{bw*1.04:.1f},{bh*0.62:.1f} {bw*0.95:.1f},{bh*0.90:.1f} {bw*0.80:.1f},{bh*0.88:.1f} '
            f'C{bw*0.68:.1f},{bh*1.02:.1f} {bw*0.50:.1f},{bh*0.94:.1f} {bw*0.38:.1f},{bh*0.99:.1f} '
            f'C{bw*0.22:.1f},{bh*1.00:.1f} {bw*0.12:.1f},{bh*0.90:.1f} {bw*0.10:.1f},{bh*0.78:.1f} C{-bw*0.02:.1f},{bh*0.70:.1f} {pad*0.0:.1f},{bh*0.55:.1f} {pad*0.3:.1f},{bh*0.45:.1f}Z')
    lines = ''.join(f'<rect x="{-size*0.9:.1f}" y="{bh*(0.30+i*0.17):.1f}" width="{size*(0.8-i*0.15):.1f}" height="{size*0.09:.1f}" rx="{size*0.045:.1f}" fill="{ORANGE}"/>' for i in range(3))
    arrow = f'<path d="M{bw*0.97:.1f},{bh*0.50:.1f} l{size*0.35:.1f},{-size*0.22:.1f} l0,{size*0.14:.1f} l{size*0.3:.1f},0 l0,{size*0.16:.1f} l{-size*0.3:.1f},0 l0,{size*0.14:.1f}Z" fill="{ORANGE}" stroke="{NAVY}" stroke-width="{size*0.05:.1f}" stroke-linejoin="round"/>'
    word = (f'<g transform="translate({pad:.1f},{bh*0.70:.1f}) skewX(-14)">'
            f'<path d="{d}" transform="translate({size*0.05:.1f},{size*0.07:.1f})" fill="{NAVY}" stroke="{NAVY}" stroke-width="{size*0.2:.1f}" stroke-linejoin="round"/>'
            f'<path d="{d}" fill="#fff" stroke="{NAVY}" stroke-width="{size*0.2:.1f}" stroke-linejoin="round" paint-order="stroke"/></g>')
    g = (f'<g transform="translate({x},{y}) rotate(-3)"><path d="{blob}" transform="translate({size*0.06:.1f},{size*0.08:.1f})" fill="{NAVY}"/>'
         f'<path d="{blob}" fill="{ORANGE}" stroke="{NAVY}" stroke-width="{size*0.06:.1f}" stroke-linejoin="round"/>{lines}{arrow}{word}</g>')
    return g, bw + size * 0.65, bh
