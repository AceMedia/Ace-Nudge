#!/usr/bin/env python3
"""Rebuild docs/images/*.svg from docs/images/src/*.svg: acemedia.ninja palette, the Ace mark and the skewed NUDGE wordmark."""
import os, sys
sys.path.insert(0, os.path.dirname(__file__))
from brand import *

root = os.path.join(os.path.dirname(__file__), '..', 'docs', 'images')
PALETTE = {'#2271b1': BLUE, '#1d2327': NAVY, '#f6f7f7': PAPER, '#f0f0f1': PAPER, '#dcdcde': LINE, '#50575e': INK,
           '#8fb0d6': '#c5d7e8', '#c3c4c7': '#c5d7e8', '#e8e8e8': LINE, '#c5d9ed': '#c5d7e8', '#dba617': ORANGE, '#d63638': ORANGE, '#00a32a': BLUE, '#101820': NAVY, '#1d3a5f': BLUE}

def load(name):
    s = open(os.path.join(root, 'src', name)).read()
    for a, b in PALETTE.items():
        s = s.replace(a, b)
    return s

def put(name, s):
    open(os.path.join(root, name), 'w').write(s)

def lockup(x, y, w, ink, word_fill, word_stroke=None):
    """Ace mark with a small skewed NUDGE beside it. w is the mark's width."""
    h = w * 307 / 537
    word, ww = nudge_plain(x + w + 8, y + h * 0.78, h * 0.62, word_fill, word_stroke, h * 0.05 if word_stroke else 0)
    return ace_mark(x, y, w, ink) + word, w + 8 + ww

# hero: big Ace mark and the splat wordmark, small lockup in the bubble
s = load('hero.svg')
s = s.replace('<text x="70" y="150" font-size="64" font-weight="800">Ace NUDGE</text>', '')
splat, sw, sh = nudge_splat(300, 78, 62)
s = s.replace('<text x="70" y="205"', ace_mark(60, 56, 150, '#fff') + splat + '<text x="70" y="205"', 1) if False else s
s = s.replace('</g>\n<g transform="translate(800,60)">', '</g>' + ace_mark(60, 50, 150, '#fff') + splat + '\n<g transform="translate(800,60)">', 1)
mini, _ = lockup(20, 14, 38, ORANGE, ORANGE)
s = s.replace('<text x="20" y="34" font-size="15" fill="#c5d7e8" font-family="system-ui,-apple-system,Segoe UI,Roboto,sans-serif">Ace NUDGE</text>', mini)
s = s.replace('Ace NUDGE</text>', '</text>')
s = s.replace('width="190" height="40" rx="20"','width="220" height="40" rx="20"').replace('x="535" y="311"','x="550" y="311"').replace('fill="#bcd4f0"', 'fill="#c5d7e8"').replace('<rect width="1200" height="420" rx="18" fill="url(#g)"/>', '<rect width="1200" height="420" rx="18" fill="url(#g)"/><circle cx="1090" cy="40" r="130" fill="#f39510" opacity=".10"/>')
put('hero.svg', s)

# how it works: lockup top left
s = load('how-it-works.svg')
lk, _ = lockup(32, 18, 56, NAVY, ORANGE, NAVY)
s = s.replace('<g $F', '<g $F') .replace('</svg>', lk + '</svg>')
put('how-it-works.svg', s)

# panel: lockup in the alerts header
s = load('panel.svg')
lk, _ = lockup(250, 12, 44, '#fff', ORANGE)
s = s.replace('<text x="492" y="36"', lk + '<text x="492" y="36"', 1)
put('panel.svg', s)

# newsletter: lockup in both mail headers
s = load('newsletter.svg')
a, _ = lockup(320, 8, 44, NAVY, ORANGE, NAVY)
b, _ = lockup(320, 8, 44, '#fff', ORANGE)
s = s.replace('<text x="24" y="28" font-size="13" fill="#787c82">From: Your Site</text>', a + '<text x="24" y="28" font-size="13" fill="#787c82">From: Your Site</text>', 1)
s = s.replace('<text x="24" y="28" font-size="13" fill="#c5d7e8">From: Your Site</text>', b + '<text x="24" y="28" font-size="13" fill="#c5d7e8">From: Your Site</text>', 1)
put('newsletter.svg', s)

# admin: page title and menu item
s = load('admin.svg')
t, _ = lockup(230, 20, 64, NAVY, ORANGE, NAVY)
m, _ = lockup(20, 108, 34, '#fff', ORANGE)
s = s.replace('<text x="230" y="52" font-size="26" font-weight="700" fill="#10233a">Ace NUDGE</text>', t)
s = s.replace('<text x="24" y="122" fill="#fff" font-weight="700">Ace NUDGE</text>', m)
s = s.replace('fill="#f39510" font-weight="800"', 'fill="#f39510" font-weight="800"')
put('admin.svg', s)
print('built')
