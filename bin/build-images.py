#!/usr/bin/env python3
"""Rebuild docs/images/*.svg from docs/images/src/*.svg in the acemedia.ninja and acemedia.host palette.
The Ace mark and NUDGE lockup (brand2.py) appears on the banner and the overview only. Needs fontTools."""
import os, sys
sys.path.insert(0, os.path.dirname(__file__))
from brand2 import lockup, nudge

ORANGE, BLUE, NAVY, PAPER, LINE, INK = '#f39510', '#336699', '#10233a', '#f4f7fb', '#dfe7f0', '#46566a'
root = os.path.join(os.path.dirname(__file__), '..', 'docs', 'images')
PALETTE = {'#2271b1': BLUE, '#1d2327': NAVY, '#f6f7f7': PAPER, '#f0f0f1': PAPER, '#dcdcde': LINE, '#50575e': INK,
           '#8fb0d6': '#c5d7e8', '#c3c4c7': '#c5d7e8', '#e8e8e8': LINE, '#c5d9ed': '#c5d7e8', '#dba617': ORANGE,
           '#d63638': ORANGE, '#00a32a': BLUE, '#101820': NAVY, '#1d3a5f': BLUE}
FF = 'font-family="system-ui,-apple-system,Segoe UI,Roboto,sans-serif"'

def load(name):
    s = open(os.path.join(root, 'src', name)).read()
    for a, b in PALETTE.items():
        s = s.replace(a, b)
    return s

def put(name, s):
    open(os.path.join(root, name), 'w').write(s)

def word(x, y, h, fill):
    """Just the leaning NUDGE, for headings, h = cap height, top at y."""
    return nudge(x, y, h, fill)[0]

# banner
s = load('hero.svg')
lk, _ = lockup(60, 52, 96, '#fff')
s = s.replace('<text x="70" y="150" font-size="64" font-weight="800">Ace NUDGE</text>', lk)
s = s.replace('<text x="20" y="34" font-size="15" fill="#c5d7e8" font-family="system-ui,-apple-system,Segoe UI,Roboto,sans-serif">Ace NUDGE</text>',
              f'<text x="20" y="34" font-size="15" font-weight="700" fill="#c5d7e8" {FF}>Ace</text>' + word(56, 21, 15, ORANGE))
s = s.replace('width="190" height="40" rx="20"', 'width="220" height="40" rx="20"').replace('x="535" y="311"', 'x="550" y="311"')
s = s.replace('fill="#bcd4f0"', 'fill="#c5d7e8"')
put('hero.svg', s)

# overview: dark, with the same lockup
s = load('how-it-works.svg')
s = s.replace('fill="#fff" stroke="#dfe7f0"', 'fill="#183456" stroke="#2a4a73"')
s = s.replace('fill="#10233a"', 'fill="#fff"').replace('fill="#46566a"', 'fill="#c5d7e8"')
s = s.replace('<rect width="1200" height="360" rx="18" fill="#f4f7fb"/>', f'<rect width="1200" height="360" rx="18" fill="{NAVY}"/>')
lk, lw = lockup(0, 16, 56, '#fff')
s = s.replace('<text x="600" y="48" font-size="24" font-weight="800" fill="#fff">From first follow to the inbox, with consent at every step</text>', '')
s = s.replace('</svg>', '<g transform="translate(' + str(round((1200 - lw) / 2)) + ',0)">' + lk + '</g></svg>')
put('how-it-works.svg', s)

# panel and newsletter: no logo
put('panel.svg', load('panel.svg'))
put('newsletter.svg', load('newsletter.svg'))

# admin: the screen's own heading, with the word leaning
s = load('admin.svg')
s = s.replace('<text x="230" y="52" font-size="26" font-weight="700" fill="#10233a">Ace NUDGE</text>',
              f'<text x="230" y="52" font-size="26" font-weight="700" fill="{NAVY}" {FF}>Ace</text>' + word(292, 33, 20, NAVY))
s = s.replace('<text x="24" y="122" fill="#fff" font-weight="700">Ace NUDGE</text>',
              f'<text x="24" y="122" fill="#fff" font-weight="700" {FF} font-size="15">Ace</text>' + word(62, 111, 12, '#fff'))
put('admin.svg', s)
print('built')
