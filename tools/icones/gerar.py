"""Gera o sprite dos ícones de categoria (public/assets/img/icones.png) e a lista em PHP
(src/icones_lista.php) a partir de tools/icones/icones.json.

Cada emoji (Twemoji, CC-BY 4.0) é desenhado em 512 px, reduzido por média de área para uma
grade de 18×18, com a transparência cortada em 50% e a paleta limitada a 10 cores; depois
ganha 1 px de contorno na cor vizinha escurecida (fica 20×20). Rodar fora do servidor:
    python3 tools/icones/gerar.py   (precisa de cairosvg, Pillow e numpy; baixa os SVGs)
"""
import io, json, pathlib, urllib.request
import numpy as np
import cairosvg
from PIL import Image

AQUI = pathlib.Path(__file__).resolve().parent
RAIZ = AQUI.parent.parent
CACHE = AQUI / '.svg'
URL = 'https://cdn.jsdelivr.net/gh/jdecked/twemoji@15.1.0/assets/svg/{}.svg'


def svg(codigo):
    CACHE.mkdir(exist_ok=True)
    arq = CACHE / f'{codigo}.svg'
    if not arq.exists():
        arq.write_bytes(urllib.request.urlopen(URL.format(codigo), timeout=30).read())
    return arq


def pixelar(codigo, n):
    inner = n - 2
    png = cairosvg.svg2png(url=str(svg(codigo)), output_width=512, output_height=512)
    big = np.array(Image.open(io.BytesIO(png)).convert('RGBA')).astype(np.float32) / 255.0
    a = big[:, :, 3:4]
    pre = np.concatenate([big[:, :, :3] * a, a], axis=2)
    small = np.array(Image.fromarray((pre * 255).astype(np.uint8), 'RGBA').resize((inner, inner), Image.BOX)).astype(np.float32) / 255.0
    alpha = small[:, :, 3]
    cheio = alpha > 0.5
    rgb = np.zeros((inner, inner, 3), np.float32)
    rgb[cheio] = small[cheio, :3] / alpha[cheio, None]
    rgb8 = (np.clip(rgb, 0, 1) * 255).astype(np.uint8)
    lista = rgb8[cheio].reshape(-1, 1, 3)
    if len(lista):
        k = min(10, len(lista))
        q = Image.fromarray(lista).quantize(colors=k, method=Image.Quantize.MEDIANCUT)
        pal = np.array(q.getpalette()[:k * 3]).reshape(-1, 3).astype(np.float32)
        # cinzas e pretos escuros viram grafite (senão somem no tema escuro); cor saturada fica como
        # está; o contorno continua mais escuro
        lum = pal @ np.array([0.2126, 0.7152, 0.0722], np.float32)
        croma = pal.max(axis=1) - pal.min(axis=1)
        escuros = (lum < 75) & (croma < 70)
        pal[escuros] = pal[escuros] * 0.4 + np.array([140, 146, 160], np.float32) * 0.6
        rgb8[cheio] = pal.astype(np.uint8)[np.array(q).reshape(-1)]
    out = np.zeros((n, n, 4), np.uint8)
    out[1:-1, 1:-1, :3] = rgb8
    out[1:-1, 1:-1, 3] = cheio * 255
    # contorno seletivo: cada pixel vazio vizinho de um cheio pega a média dos vizinhos, escurecida
    m = out[:, :, 3] > 0
    cor = out[:, :, :3].astype(np.float32)
    soma = np.zeros((n, n, 3), np.float32)
    cont = np.zeros((n, n), np.float32)
    for dy, dx in ((0, 1), (1, 0), (0, -1), (-1, 0)):
        mm = np.roll(np.roll(m, dy, 0), dx, 1)
        cc = np.roll(np.roll(cor, dy, 0), dx, 1)
        soma += cc * mm[:, :, None]
        cont += mm
    borda = (cont > 0) & ~m
    escuro = (soma[borda] / cont[borda, None]) * 0.42
    out[borda, :3] = np.clip(escuro, 0, 255).astype(np.uint8)
    out[borda, 3] = 255
    return out


def main():
    cfg = json.loads((AQUI / 'icones.json').read_text())
    n, cols = cfg['tamanho'], cfg['colunas']
    itens = [(chave, rot, cod, g) for g, _, lista in cfg['grupos'] for chave, rot, cod in lista]
    linhas = (len(itens) + cols - 1) // cols
    folha = np.zeros((linhas * n, cols * n, 4), np.uint8)
    php = []
    for i, (chave, rot, cod, g) in enumerate(itens):
        c, l = i % cols, i // cols
        folha[l * n:(l + 1) * n, c * n:(c + 1) * n] = pixelar(cod, n)
        php.append(f"    {chave!r} => [{json.dumps(rot, ensure_ascii=False)}, {c}, {l}, {g!r}],")
    Image.fromarray(folha, 'RGBA').save(RAIZ / 'public/assets/img/icones.png', optimize=True)
    grupos = ',\n'.join(f"    {g!r} => {json.dumps(r, ensure_ascii=False)}" for g, r, _ in cfg['grupos'])
    (RAIZ / 'src/icones_lista.php').write_text(
        "<?php\n// GERADO por tools/icones/gerar.py a partir de tools/icones/icones.json: não editar à mão.\n"
        f"// Ícones: {cfg['fonte']}\n"
        f"return [\n  'tamanho' => {n},\n  'colunas' => {cols},\n  'linhas' => {linhas},\n"
        f"  'grupos' => [\n{grupos},\n  ],\n  'icones' => [\n" + '\n'.join(php) + "\n  ],\n];\n")
    print(len(itens), 'ícones,', f'{cols}×{linhas}')


if __name__ == '__main__':
    main()
