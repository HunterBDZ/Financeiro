#!/usr/bin/env python3
"""Gera o sprite dos ícones de categoria (public/assets/img/categorias.svg) e a lista em PHP
(src/icones_lista.php) a partir de tools/icones/icones.json, só com a biblioteca padrão.

Cada ícone vira um <symbol id="cat-<chave>"> de 24×24, em traço de 1,5 com pontas e cantos
redondos, sem preenchimento e na cor do texto (currentColor). O desenho de um ícone pode repetir
o de outro com "=outra-chave". Rodar da raiz do repositório: python3 tools/icones/gerar.py
"""
import json, pathlib, xml.etree.ElementTree as ET

AQUI = pathlib.Path(__file__).resolve().parent
RAIZ = AQUI.parent.parent
ATRIBUTOS = ('viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" '
             'stroke-linecap="round" stroke-linejoin="round"')


def main():
    cfg = json.loads((AQUI / 'icones.json').read_text())
    itens = [(chave, rot, desenho, g) for g, _, lista in cfg['grupos'] for chave, rot, desenho in lista]
    desenhos = {chave: desenho for chave, _, desenho, _ in itens}
    if len(desenhos) != len(itens):
        raise SystemExit('chave repetida em icones.json')
    if cfg['padrao'] not in desenhos:
        raise SystemExit(f"o ícone padrão {cfg['padrao']!r} não está na lista")

    simbolos, php, compartilhados = [], [], 0
    for chave, rot, desenho, g in itens:
        if desenho.startswith('='):
            compartilhados += 1
            desenho = desenhos.get(desenho[1:], '')
            if desenho == '' or desenho.startswith('='):
                raise SystemExit(f'{chave}: aponta para um desenho que não existe')
        # o desenho tem de ser XML válido: um erro aqui quebraria o sprite inteiro no navegador
        ET.fromstring(f'<svg xmlns="http://www.w3.org/2000/svg">{desenho}</svg>')
        if 'fill="currentColor"' in desenho or 'style=' in desenho:
            raise SystemExit(f'{chave}: só traço, sem preenchimento nem estilo')
        simbolos.append(f'<symbol id="cat-{chave}" {ATRIBUTOS}>{desenho}</symbol>')
        php.append(f"    {chave!r} => [{json.dumps(rot, ensure_ascii=False)}, {g!r}],")

    (RAIZ / 'public/assets/img/categorias.svg').write_text(
        '<svg xmlns="http://www.w3.org/2000/svg">\n'
        '<!-- GERADO por tools/icones/gerar.py a partir de tools/icones/icones.json: não editar à mão. -->\n'
        + '\n'.join(simbolos) + '\n</svg>\n')
    grupos = ',\n'.join(f"    {g!r} => {json.dumps(r, ensure_ascii=False)}" for g, r, _ in cfg['grupos'])
    (RAIZ / 'src/icones_lista.php').write_text(
        "<?php\n// GERADO por tools/icones/gerar.py a partir de tools/icones/icones.json: não editar à mão.\n"
        "// Os desenhos ficam em public/assets/img/categorias.svg, um <symbol id=\"cat-<chave>\"> por ícone.\n"
        f"return [\n  'padrao' => {cfg['padrao']!r},\n"
        f"  'grupos' => [\n{grupos},\n  ],\n  'icones' => [\n" + '\n'.join(php) + "\n  ],\n];\n")
    print(len(itens), 'ícones,', compartilhados, 'com o desenho de outro')


if __name__ == '__main__':
    main()
