#!/usr/bin/env python3
"""Gera os temas do app a partir de tools/temas/temas.json, só com a biblioteca padrão:
  public/assets/css/temas.css e src/temas_lista.php.

Cada paleta tem o lado claro e o escuro, e vira três temas: claro, escuro e automático (segue o aparelho).
A paleta "padrao" é a do app (os tokens ficam em style.css; aqui só entra na lista).
Um tema muda só o fundo, a superfície, a lateral, o realce, os fios, as tintas e o azul. Entrada, saída, atenção
e alerta são do app e não mudam, salvo nas paletas de Acessibilidade (alto contraste e daltônico), que existem
justamente para isso. Fontes, forma e layout nunca mudam.

As contas de contraste (WCAG): as tintas e o azul com 4,5:1 sobre o fundo, a superfície, a lateral e o realce, indo
para o preto ou o branco só o necessário; o contorno de campo (--fio-forte) com 3:1; o texto sobre o azul com 4,5:1;
e as cores de entrada, saída, atenção e alerta com 4,5:1 (se não passar, o gerador para e diz onde).
Rodar da raiz do repositório: python3 tools/temas/gerar.py
"""
import json

BASE = 'padrao'          # os tokens da paleta padrão ficam em style.css
# as cores com função, iguais em todos os temas (style.css); só a Acessibilidade troca
FIXAS = {
    'claro': {'entrada': '#127A4C', 'saida': '#C2261D', 'atencao': '#965800', 'alerta': '#B3261E'},
    'escuro': {'entrada': '#5FD39A', 'saida': '#F2877F', 'atencao': '#E7AE57', 'alerta': '#F28B84'},
}
MUDAM = ['fundo', 'superficie', 'lateral', 'realce', 'fio', 'fio-forte', 'tinta', 'tinta-2', 'tinta-3',
         'azul', 'azul-hover', 'azul-fundo', 'sobre-azul']


def lum(h):
    h = h.lstrip('#')[:6]
    r, g, b = [int(h[i:i + 2], 16) / 255 for i in (0, 2, 4)]
    f = lambda c: c / 12.92 if c <= .03928 else ((c + .055) / 1.055) ** 2.4
    return .2126 * f(r) + .7152 * f(g) + .0722 * f(b)


def contraste(a, b):
    x, y = sorted([lum(a), lum(b)], reverse=True)
    return (x + .05) / (y + .05)


def mix(a, b, p):
    a, b = a.lstrip('#'), b.lstrip('#')
    return '#' + ''.join('%02X' % round(int(a[i:i + 2], 16) * (1 - p) + int(b[i:i + 2], 16) * p) for i in (0, 2, 4))


def ajusta(cor, fundos, alvo=4.5, destinos=('#000000', '#FFFFFF')):
    """A cor mais perto da original que passa no contraste com todos os fundos."""
    if all(contraste(cor, f) >= alvo for f in fundos):
        return cor.upper()
    melhor = None
    for destino in destinos:
        for i in range(1, 101):
            c = mix(cor, destino, i / 100)
            if all(contraste(c, f) >= alvo for f in fundos):
                if melhor is None or i < melhor[0]:
                    melhor = (i, c)
                break
    if melhor is None:
        raise SystemExit('sem cor legível: %s sobre %s' % (cor, fundos))
    return melhor[1]


def lado(p, modo):
    """Os tokens de um lado (claro ou escuro) da paleta, já conferidos."""
    t = dict(p[modo])
    nome = '%s (%s)' % (p['id'], modo)
    fundos = [t['fundo'], t['superficie'], t['lateral'], t['realce']]
    for k in ('tinta', 'tinta-2', 'tinta-3', 'azul'):
        t[k] = ajusta(t[k], fundos)
    t['fio-forte'] = ajusta(t.get('fio-forte', t['fio']), [t['fundo'], t['superficie'], t['realce']], 3, (t['tinta'],))
    t['azul-fundo'] = t['azul'] + ('14' if modo == 'claro' else '1F')
    for k in ('azul', 'azul-hover'):
        if contraste(t['sobre-azul'], t[k]) < 4.5:
            raise SystemExit('%s: o texto sobre --%s não passa (%.2f)' % (nome, k, contraste(t['sobre-azul'], t[k])))
    acessibilidade = p['grupo'] == 'Acessibilidade'
    for k, cor in FIXAS[modo].items():
        if k in t and not acessibilidade:
            raise SystemExit('%s: --%s é do app e não muda com o tema' % (nome, k))
        c = t.get(k, cor)
        pior = min(contraste(c, f) for f in fundos)
        if pior < 4.5:
            raise SystemExit('%s: --%s %s não passa sobre o fundo do tema (%.2f)' % (nome, k, c, pior))
        t[k] = c
    return t


def corpo(t, modo):
    chaves = MUDAM + [k for k in FIXAS[modo] if k in t and t[k] != FIXAS[modo][k]]
    return ' '.join('--%s: %s;' % (k, t[k]) for k in chaves) + ' color-scheme: %s;' % ('light' if modo == 'claro' else 'dark')


def amostra(t):
    return {'fundo': t['fundo'], 'superficie': t['superficie'], 'tinta': t['tinta'], 'tinta2': t['tinta-2'], 'fio': t['fio'],
            'azul': t['azul'], 'sobre': t['sobre-azul'], 'entrada': t['entrada'], 'saida': t['saida']}


def main():
    paletas = json.load(open('tools/temas/temas.json', encoding='utf-8'))
    css = ['/* Gerado por tools/temas/gerar.py a partir de tools/temas/temas.json. Não edite à mão. */',
           '/* Cada paleta: o lado claro, o escuro e o automático, que segue o aparelho. */',
           ":root[data-tema]:not([data-tema=\"claro\"]):not([data-tema=\"escuro\"]) .ct-plastico:not([data-visual]) { background-image: none; }"]
    php = ['<?php', '// Gerado por tools/temas/gerar.py a partir de tools/temas/temas.json. Não edite à mão.',
           '// id => o tema: a paleta, o modo (light, dark ou auto), o fundo para a barra do navegador e as amostras dos dois lados.',
           'return [']
    esc = lambda s: "'" + str(s).replace('\\', '\\\\').replace("'", "\\'") + "'"

    def arr(d):
        return '[' + ', '.join('%s => %s' % (esc(k), esc(v)) for k, v in d.items()) + ']'

    vistos = set()
    for p in paletas:
        claro, escuro = lado(p, 'claro'), lado(p, 'escuro')
        ids = p['ids']
        for i in ids.values():
            if i is not None:
                if i in vistos or not i.isalnum() or i != i.lower():
                    raise SystemExit('id de tema repetido ou fora do padrão [a-z0-9]: %s' % i)
                vistos.add(i)
        if p['id'] != BASE:
            claros = ', '.join(':root[data-tema="%s"]' % i for i in (ids['claro'], ids['auto']))
            css.append('%s { %s }' % (claros, corpo(claro, 'claro')))
            css.append(':root[data-tema="%s"] { %s }' % (ids['escuro'], corpo(escuro, 'escuro')))
            css.append('@media (prefers-color-scheme: dark) { :root[data-tema="%s"] { %s } }' % (ids['auto'], corpo(escuro, 'escuro')))
        for modo, i in (('light', ids['claro']), ('dark', ids['escuro']), ('auto', ids['auto'])):
            if i is None:
                continue
            campos = [('nome', esc(p['nome'])), ('paleta', esc(p['id'])), ('grupo', esc(p['grupo'])), ('modo', esc(modo)),
                      ('bg', esc(escuro['fundo'] if modo == 'dark' else claro['fundo']))]
            if modo == 'auto':
                campos.append(('bgEscuro', esc(escuro['fundo'])))
            campos += [('claro', arr(amostra(claro))), ('escuro', arr(amostra(escuro)))]
            php.append('    %s => [%s],' % (esc(i), ', '.join('%s => %s' % (esc(k), v) for k, v in campos)))
    php.append('];')
    open('public/assets/css/temas.css', 'w', encoding='utf-8').write('\n'.join(css) + '\n')
    open('src/temas_lista.php', 'w', encoding='utf-8').write('\n'.join(php) + '\n')
    print(len(paletas), 'paletas,', len(vistos), 'temas')


main()
