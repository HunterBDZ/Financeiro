# Avisos de terceiros

O código do Financeiro é distribuído sob a [AGPL-3.0-or-later](LICENSE). Os componentes abaixo continuam sob as próprias licenças.

## Fontes

| Fonte | Onde está | Licença |
|---|---|---|
| Manrope | `public/assets/fonts/` e, recortada para o PDF, `src/Pdf/fontes/` | SIL OFL 1.1 ([`Manrope-OFL.txt`](public/assets/fonts/Manrope-OFL.txt)) |
| Sora | `public/assets/fonts/` e, recortada para o PDF, `src/Pdf/fontes/` | SIL OFL 1.1 ([`Sora-OFL.txt`](public/assets/fonts/Sora-OFL.txt)) |
| JetBrains Mono | `public/assets/fonts/` | SIL OFL 1.1 ([`JetBrainsMono-OFL.txt`](public/assets/fonts/JetBrainsMono-OFL.txt)) |

**Modificações:** as versões web foram recortadas e convertidas para WOFF2. As do PDF são instâncias estáticas em TTF, geradas por `tools/pdf-fontes/gerar.py`. Os avisos de copyright estão nos textos da licença, e uma cópia acompanha `src/Pdf/fontes/`.

## Paletas de temas

Os temas consagrados usam as cores de projetos sob as licenças MIT e Apache-2.0, ajustadas onde o contraste não passava. A lista completa, com links, está em [`tools/temas/CREDITOS.md`](tools/temas/CREDITOS.md).

## Imagens do projeto

Estas imagens foram criadas para o Financeiro e seguem a licença do projeto:

- `public/assets/img/marca.svg`, `icone-*.png` e `linhas-*.svg`;
- `public/assets/img/categorias.svg`, os ícones de categoria (fonte em `tools/icones/icones.json`);
- `database/demo/*.jpg`, desenhos dos itens da demonstração;
- `docs/img/*.webp`, capturas do app com dados fictícios. Elas mostram os ícones e as fontes acima, sob as respectivas licenças.

## Foto da tela de entrar

`public/assets/img/entrar-casas-*.webp`: [Appartment building from Banner Street](https://commons.wikimedia.org/wiki/File:Appartment_building_from_Banner_Street_2024-01-11.jpg), de Tom Page, sob a [CC BY-SA 2.0](https://creativecommons.org/licenses/by-sa/2.0/). **Modificações:** reduzida e convertida para WebP; na tela, recortada e tingida de azul-marinho. O crédito aparece na própria tela, com o link.

## Serviços externos

Nenhum código destes serviços acompanha o app. O que sai do servidor está descrito em [`docs/SEGURANCA.md`](docs/SEGURANCA.md).

## Nos pacotes

Os pacotes e a imagem Docker levam junto o [FrankenPHP](https://github.com/php/frankenphp) (MIT), com o PHP (licença PHP), o Caddy (Apache-2.0) e as bibliotecas embutidas neles, e os certificados raiz da [Mozilla](https://curl.se/docs/caertextract.html) (MPL-2.0). As licenças de cada um vêm com os projetos de origem.

O instalador de Windows também leva as bibliotecas que acompanham o pacote oficial do FrankenPHP para Windows, com as licenças em `licencas/` dentro da pasta do programa, e o runtime do Visual C++ da Microsoft (`vcruntime140.dll` e `vcruntime140_1.dll`), redistribuível pelos termos da Microsoft.
