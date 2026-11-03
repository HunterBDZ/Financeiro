#!/usr/bin/env python3
"""Gera os temas do app a partir de tools/temas/temas.json, só com a biblioteca padrão:
  public/assets/css/temas.css e src/temas_lista.php.
Cada cor de texto é conferida contra os fundos em que aparece (WCAG AA, 4,5:1) e, se não passar, vai para
o preto ou o branco só o necessário. Rodar da raiz do repositório: python3 tools/temas/gerar.py
"""
import json

BASE = {'claro', 'escuro'}   # os tokens destes dois ficam em style.css


def lum(h):
    h = h.lstrip('#')
    r, g, b = [int(h[i:i + 2], 16) / 255 for i in (0, 2, 4)]
    f = lambda c: c / 12.92 if c <= .03928 else ((c + .055) / 1.055) ** 2.4
    return .2126 * f(r) + .7152 * f(g) + .0722 * f(b)


def contraste(a, b):
    x, y = sorted([lum(a), lum(b)], reverse=True)
    return (x + .05) / (y + .05)


def mix(a, b, p):
    a, b = a.lstrip('#'), b.lstrip('#')
    return '#' + ''.join('%02x' % round(int(a[i:i + 2], 16) * (1 - p) + int(b[i:i + 2], 16) * p) for i in (0, 2, 4))


def rgba(h, a):
    h = h.lstrip('#')
    return 'rgba(%d, %d, %d, %s)' % (int(h[:2], 16), int(h[2:4], 16), int(h[4:], 16), a)


def ajusta(cor, fundos, alvo=4.5):
    if all(contraste(cor, f) >= alvo for f in fundos):
        return cor
    melhor = None
    for destino in ('#000000', '#ffffff'):
        for i in range(1, 101):
            c = mix(cor, destino, i / 100)
            if all(contraste(c, f) >= alvo for f in fundos):
                if melhor is None or i < melhor[0]:
                    melhor = (i, c)
                break
    if melhor is None:
        raise SystemExit('sem cor legível: %s sobre %s' % (cor, fundos))
    return melhor[1]


def tokens(t):
    fundos = [t['bg'], t['card'], t['c2']]
    escuro = t['modo'] == 'escuro'
    text = ajusta(t['text'], fundos + [t['c3']])
    t2 = ajusta(t['t2'], fundos + [t['c3']])
    cores = {}
    for k in ('pos', 'neg', 'warn'):
        # folga acima de 4,5: o selo de estado pinta um tom da própria cor por baixo do texto
        cores[k] = ajusta(t[k], fundos + [mix(t['card'], t[k], .14), mix(t['bg'], t[k], .14)], 4.9)
    ct = ajusta(t['ct'], [t['cofre']])
    ct2 = ajusta(t['ct2'], [t['cofre'], mix(t['cofre'], '#ffffff', .08)])
    no_cofre = lambda c: ajusta(c, [t['cofre'], mix(t['cofre'], c, .16)])
    v = {
        'bg': t['bg'], 'card': t['card'], 'card-2': t['c2'], 'card-3': t['c3'],
        'line': rgba('#ffffff', '.09') if escuro else rgba(text, '.1'),
        'line-2': rgba('#ffffff', '.16') if escuro else rgba(text, '.18'),
        'text': text, 'text-2': t2, 'text-3': t2,
        'acc': t['acc'], 'acc-hover': mix(t['acc'], t['on'], .12), 'on-acc': t['on'],
        'pos': cores['pos'], 'neg': cores['neg'], 'warn': cores['warn'],
        'pos-bg': rgba(cores['pos'], '.14'), 'neg-bg': rgba(cores['neg'], '.14'), 'warn-bg': rgba(cores['warn'], '.14'),
        'barra': text, 'foco': ajusta(t['acc'], [t['bg'], t['card']], 3) if escuro else text,
        'sel': rgba('#ffffff', '.05') if escuro else rgba(text, '.04'),
        'sombra': 'none' if escuro else '0 1px 2px rgba(16, 24, 40, .04), 0 8px 24px -14px rgba(16, 24, 40, .16)',
        'sombra-flutua': '0 18px 50px -18px rgba(0, 0, 0, %s)' % ('.7' if escuro else '.35'),
        'cofre': t['cofre'], 'cofre-txt': ct, 'cofre-txt-2': ct2, 'cofre-mut': ct2,
        'cofre-ok': no_cofre(t['acc']), 'cofre-ok-bg': rgba(no_cofre(t['acc']), '.16'),
        'cofre-warn': no_cofre(t['warn']), 'cofre-warn-bg': rgba(no_cofre(t['warn']), '.16'),
        'cofre-neg': no_cofre(t['neg']), 'cofre-neg-bg': rgba(no_cofre(t['neg']), '.16'),
        'cofre-pos': no_cofre(t['pos']), 'cofre-saida': no_cofre(t['neg']),
        'toast': t['cofre'], 'toast-txt': ct, 'toast-txt-2': ct2,
        'ic-halo': 'drop-shadow(0 0 1px rgba(255, 255, 255, .45))' if escuro else 'none',
    }
    if t['id'] == 'portatil':       # sem vermelho nas 4 cores do tema
        v['perigo'] = text
    if t.get('fam'):
        v['f'] = v['fn'] = "'JetBrains Mono', ui-monospace, Menlo, Consolas, monospace"
    return v


def main():
    temas = json.load(open('tools/temas/temas.json', encoding='utf-8'))
    css = ['/* Gerado por tools/temas/gerar.py a partir de tools/temas/temas.json. Não edite à mão. */',
           "@font-face { font-family: 'JetBrains Mono'; font-style: normal; font-weight: 100 800; font-display: swap; src: url('/assets/fonts/jetbrains-mono-var-latin.woff2') format('woff2'); }",
           '/* o desenho de linhas do cartão de saldo pega a cor do tema */',
           ":root[data-tema]:not([data-tema=\"claro\"]):not([data-tema=\"escuro\"]) .saldo::before { background: var(--cofre-ok); -webkit-mask: url('/assets/img/linhas-cartao.svg') right -40px top -10px / 120% auto no-repeat; mask: url('/assets/img/linhas-cartao.svg') right -40px top -10px / 120% auto no-repeat; opacity: .5; }",
           ":root[data-tema]:not([data-tema=\"claro\"]):not([data-tema=\"escuro\"]) .ct-plastico:not([data-visual]) { background-image: none; }"]
    php = ['<?php', '// Gerado por tools/temas/gerar.py a partir de tools/temas/temas.json. Não edite à mão.', 'return [']
    esc = lambda s: "'" + str(s).replace('\\', '\\\\').replace("'", "\\'") + "'"
    for t in temas:
        v = tokens(t)
        if t['id'] not in BASE:
            corpo = ' '.join('--%s: %s;' % kv for kv in v.items())
            css.append(':root[data-tema="%s"] { %s color-scheme: %s; }' % (t['id'], corpo, 'dark' if t['modo'] == 'escuro' else 'light'))
            if t.get('raio') is not None:
                css.append(':root[data-tema="%s"] * { border-radius: %s !important; }' % (t['id'], '0' if t['raio'] == '0' else t['raio']))
        campos = {'nome': t['nome'], 'grupo': t['grupo'], 'modo': 'dark' if t['modo'] == 'escuro' else 'light', 'bg': t['bg'], 'card': v['card'], 'text': v['text'], 'text2': v['text-2'],
                  'acc': t['acc'], 'on': t['on'], 'pos': v['pos'], 'neg': v['neg'], 'warn': v['warn'], 'cofre': t['cofre'], 'ct': v['cofre-txt'], 'mono': bool(t.get('fam')), 'reto': t.get('raio') == '0'}
        php.append('    %s => [%s],' % (esc(t['id']), ', '.join('%s => %s' % (esc(k), ('true' if x is True else 'false' if x is False else esc(x))) for k, x in campos.items())))
    php.append('];')
    open('public/assets/css/temas.css', 'w', encoding='utf-8').write('\n'.join(css) + '\n')
    open('src/temas_lista.php', 'w', encoding='utf-8').write('\n'.join(php) + '\n')
    print(len(temas), 'temas')


main()
