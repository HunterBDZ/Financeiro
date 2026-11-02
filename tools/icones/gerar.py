"""Gera o sprite dos ícones de categoria (public/assets/img/icones.webp) e a lista em PHP
(src/icones_lista.php) a partir de tools/icones/icones.json.

Os desenhos são o Noto Emoji (Apache-2.0), em PNG de 512 px, de um commit fixo do repositório
oficial. Cada arquivo baixado é conferido com o SHA-256 guardado em tools/icones/noto.sha256,
e nada baixado fica no repositório: o cache é uma pasta temporária (troque com --cache).
Cada emoji ocupa uma célula de 160 px com 8 px transparentes à direita e embaixo: vários
encostam na borda, e sem esse respiro o vizinho vaza quando o navegador reduz a imagem.

Rodar fora do servidor:
    python3 tools/icones/gerar.py              (precisa de Pillow com WebP)
    python3 tools/icones/gerar.py --atualizar-hashes    (só depois de acrescentar um emoji
                                               ou trocar o commit; revise o diff do noto.sha256)
"""
import argparse, hashlib, json, pathlib, tempfile, urllib.request
from PIL import Image

AQUI = pathlib.Path(__file__).resolve().parent
RAIZ = AQUI.parent.parent
# Último commit em que o LICENSE da raiz do projeto é o Apache-2.0: ver CREDITOS.md antes de trocar.
COMMIT = '41e31b110b4eb929dffb410264694a06205b7ad7'
URL = f'https://raw.githubusercontent.com/googlefonts/noto-emoji/{COMMIT}/png/512/emoji_u{{}}.png'
HASHES = AQUI / 'noto.sha256'
RESPIRO = 8


def ler_hashes():
    if not HASHES.exists():
        return {}
    return {nome: h for h, nome in (linha.split() for linha in HASHES.read_text().splitlines() if linha.strip())}


def imagem(codigo, n, cache, hashes, atualizar):
    """O emoji centralizado em n×n, conferido antes de abrir: o arquivo vem de fora."""
    nome = f'emoji_u{codigo}.png'
    arq = cache / nome
    dados = arq.read_bytes() if arq.exists() else urllib.request.urlopen(URL.format(codigo), timeout=60).read()
    resumo = hashlib.sha256(dados).hexdigest()
    if atualizar:
        hashes[nome] = resumo
    elif hashes.get(nome) != resumo:
        raise SystemExit(f'{nome}: SHA-256 diferente do esperado em {HASHES.name} ({resumo})')
    arq.write_bytes(dados)
    im = Image.open(arq).convert('RGBA')
    im.thumbnail((n, n), Image.LANCZOS)
    celula = Image.new('RGBA', (n, n), (0, 0, 0, 0))
    celula.paste(im, ((n - im.width) // 2, (n - im.height) // 2))
    return celula


def main():
    args = argparse.ArgumentParser()
    args.add_argument('--cache', type=pathlib.Path, default=pathlib.Path(tempfile.gettempdir()) / f'financeiro-noto-{COMMIT[:12]}')
    args.add_argument('--atualizar-hashes', action='store_true')
    opcoes = args.parse_args()
    opcoes.cache.mkdir(parents=True, exist_ok=True)
    hashes = ler_hashes()

    cfg = json.loads((AQUI / 'icones.json').read_text())
    n, cols = cfg['tamanho'], cfg['colunas']
    passo = n + RESPIRO
    itens = [(chave, rot, cod, g) for g, _, lista in cfg['grupos'] for chave, rot, cod in lista]
    linhas = (len(itens) + cols - 1) // cols
    folha = Image.new('RGBA', (cols * passo, linhas * passo), (0, 0, 0, 0))
    php = []
    for i, (chave, rot, cod, g) in enumerate(itens):
        c, l = i % cols, i // cols
        folha.paste(imagem(cod, n, opcoes.cache, hashes, opcoes.atualizar_hashes), (c * passo, l * passo))
        php.append(f"    {chave!r} => [{json.dumps(rot, ensure_ascii=False)}, {c}, {l}, {g!r}],")
    if opcoes.atualizar_hashes:
        HASHES.write_text(''.join(f'{h}  {nome}\n' for nome, h in sorted(hashes.items())))
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
