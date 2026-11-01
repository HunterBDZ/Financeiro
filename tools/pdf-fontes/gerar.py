#!/usr/bin/env python3
"""Gera as fontes do PDF a partir das variáveis do app: TTF estático e um .php com cmap e larguras.
O servidor não tem nada para ler fonte, então o PHP só lê o que sai daqui. Rode no desenvolvimento:
    python3 tools/pdf-fontes/gerar.py
Precisa de fontTools e brotli. As fontes têm licença OFL, com os textos em public/assets/fonts."""
import io
import os
import sys
from fontTools.ttLib import TTFont
from fontTools.varLib.instancer import instantiateVariableFont

RAIZ = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
ORIGEM = os.path.join(RAIZ, 'public', 'assets', 'fonts')
DESTINO = os.path.join(RAIZ, 'src', 'Pdf', 'fontes')
FONTES = [
    ('manrope-500', 'manrope-var-latin.woff2', 500, 'Manrope-Medium'),
    ('manrope-700', 'manrope-var-latin.woff2', 700, 'Manrope-Bold'),
    ('sora-600', 'sora-var-latin.woff2', 600, 'Sora-SemiBold'),
]

def php_str(s):
    return "'" + s.replace('\\', '\\\\').replace("'", "\\'") + "'"

def gerar(chave, arquivo, peso, nome):
    f = TTFont(os.path.join(ORIGEM, arquivo))
    f = instantiateVariableFont(f, {'wght': peso}, inplace=False)
    f.flavor = None
    for t in ('GSUB', 'GPOS', 'GDEF', 'STAT', 'DSIG', 'meta', 'gasp'):
        if t in f:
            del f[t]
    buf = io.BytesIO()
    f.save(buf)
    f = TTFont(io.BytesIO(buf.getvalue()))
    with open(os.path.join(DESTINO, chave + '.ttf'), 'wb') as s:
        s.write(buf.getvalue())
    escala = 1000.0 / f['head'].unitsPerEm
    ordem = f.getGlyphOrder()
    gid = {g: i for i, g in enumerate(ordem)}
    larg = {g: int(round(f['hmtx'][g][0] * escala)) for g in ordem}
    cmap = f.getBestCmap()
    os2 = f['OS/2']
    cap = getattr(os2, 'sCapHeight', 0) or 700
    head = f['head']
    linhas = ['<?php', '// GERADO por tools/pdf-fontes/gerar.py: não editar à mão.', 'return [',
        f"    'nome' => {php_str(nome)},",
        f"    'ascent' => {int(round(f['hhea'].ascent * escala))}, 'descent' => {int(round(f['hhea'].descent * escala))},",
        f"    'cap' => {int(round(cap * escala))},",
        f"    'bbox' => [{int(head.xMin * escala)}, {int(head.yMin * escala)}, {int(head.xMax * escala)}, {int(head.yMax * escala)}],",
        "    'glifos' => ["]
    for cp in sorted(cmap):
        g = cmap[cp]
        linhas.append(f"        {cp} => [{gid[g]}, {larg[g]}],")
    linhas += ['    ],', '];', '']
    with open(os.path.join(DESTINO, chave + '.php'), 'w', encoding='utf-8') as s:
        s.write('\n'.join(linhas))
    print(chave, len(cmap), 'códigos', os.path.getsize(os.path.join(DESTINO, chave + '.ttf')), 'bytes')

os.makedirs(DESTINO, exist_ok=True)
for item in FONTES:
    gerar(*item)
