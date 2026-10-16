"""Gera o sprite dos ícones de categoria (public/assets/img/icones.webp) e a lista em PHP
(src/icones_lista.php) a partir de tools/icones/icones.json.

Os desenhos são os emojis da Apple (Apple Color Emoji) em 160 px, baixados do repositório
iamcal/emoji-data (pasta img-apple-160) para tools/icones/.apple/, que fica fora do git.
Cada emoji ocupa uma célula de 160 px com 8 px transparentes à direita e embaixo: vários
encostam na borda, e sem esse respiro o vizinho vaza quando o navegador reduz a imagem.
Rodar fora do servidor:
    python3 tools/icones/gerar.py   (precisa de Pillow com WebP)
"""
import json, pathlib, urllib.error, urllib.request
from PIL import Image

AQUI = pathlib.Path(__file__).resolve().parent
RAIZ = AQUI.parent.parent
CACHE = AQUI / '.apple'
URL = 'https://raw.githubusercontent.com/iamcal/emoji-data/master/img-apple-160/{}.png'
RESPIRO = 8


def imagem(codigo, n):
    """O emoji em n×n; alguns só existem com o seletor de variação (-fe0f) no nome do arquivo."""
    CACHE.mkdir(exist_ok=True)
    arq = CACHE / f'{codigo}.png'
    if not arq.exists():
        for nome in (codigo, codigo + '-fe0f'):
            try:
                arq.write_bytes(urllib.request.urlopen(URL.format(nome), timeout=30).read())
                break
            except urllib.error.HTTPError as erro:
                if erro.code != 404:
                    raise
        else:
            raise SystemExit(f'emoji {codigo} não encontrado em {URL}')
    im = Image.open(arq).convert('RGBA')
    return im if im.size == (n, n) else im.resize((n, n), Image.LANCZOS)


def main():
    cfg = json.loads((AQUI / 'icones.json').read_text())
    n, cols = cfg['tamanho'], cfg['colunas']
    passo = n + RESPIRO
    itens = [(chave, rot, cod, g) for g, _, lista in cfg['grupos'] for chave, rot, cod in lista]
    linhas = (len(itens) + cols - 1) // cols
    folha = Image.new('RGBA', (cols * passo, linhas * passo), (0, 0, 0, 0))
    php = []
    for i, (chave, rot, cod, g) in enumerate(itens):
        c, l = i % cols, i // cols
        folha.paste(imagem(cod, n), (c * passo, l * passo))
        php.append(f"    {chave!r} => [{json.dumps(rot, ensure_ascii=False)}, {c}, {l}, {g!r}],")
    # WebP com perda só na cor (a transparência fica exata): ~5× menor que o PNG, sem diferença à vista
    folha.save(RAIZ / 'public/assets/img/icones.webp', quality=90, method=6)
    grupos = ',\n'.join(f"    {g!r} => {json.dumps(r, ensure_ascii=False)}" for g, r, _ in cfg['grupos'])
    (RAIZ / 'src/icones_lista.php').write_text(
        "<?php\n// GERADO por tools/icones/gerar.py a partir de tools/icones/icones.json: não editar à mão.\n"
        f"// Ícones: {cfg['fonte']}\n"
        f"return [\n  'tamanho' => {n},\n  'passo' => {passo},\n  'colunas' => {cols},\n  'linhas' => {linhas},\n"
        f"  'grupos' => [\n{grupos},\n  ],\n  'icones' => [\n" + '\n'.join(php) + "\n  ],\n];\n")
    print(len(itens), 'ícones,', f'{cols}×{linhas},', folha.size)


if __name__ == '__main__':
    main()
