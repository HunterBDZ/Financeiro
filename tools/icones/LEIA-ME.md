# Ícones de categoria

Os ícones de categoria são desenhos de traço feitos para o app e seguem a licença do projeto. Ficam num sprite só, `public/assets/img/categorias.svg`, com um `<symbol id="cat-<chave>">` por ícone. `Icones::html` (em `src/Icones.php`) desenha cada um com `<svg><use href="/assets/img/categorias.svg?v=…#cat-<chave>">`.

A fonte é `icones.json`: os grupos do seletor e, em cada grupo, a chave, o rótulo e o desenho de cada ícone. `gerar.py` lê esse arquivo e escreve o sprite e `src/icones_lista.php` (rótulo e grupo de cada chave). Os dois são gerados: não edite à mão.

## O estilo

- Quadro de 24×24, traço de 1,5, pontas e cantos redondos (`stroke-linecap` e `stroke-linejoin` `round`).
- Sem preenchimento: a cor é a do texto (`currentColor`), e o gerador recusa `fill` e `style`.
- Desenho simples, que se reconheça a 18–20 px. Um ponto é um traço de comprimento quase zero (`M12 17h.01`).
- Os ícones de interface (`svgIcon`, em `src/helpers.php`) seguem o mesmo estilo.

## Acrescentar ou trocar um ícone

1. Em `icones.json`, acrescente `["chave", "Rótulo", "<path d=\"…\"/>"]` no grupo certo. Para repetir o desenho de outro ícone, use `"=outra-chave"` no lugar do desenho.
2. **Não troque nem apague chaves existentes**: elas ficam gravadas em `categorias.icone`. Uma chave que sai da lista passa a usar a sugestão pelo nome.
3. Rode `python3 tools/icones/gerar.py` na raiz do repositório (só a biblioteca padrão do Python).
4. Traduza o rótulo novo em `src/idiomas/en.php` e `src/idiomas/es.php` e rode `php tools/idiomas/gerar.php`.
5. Se o ícone deve ser sugerido pelo nome da categoria, acrescente as palavras em `Icones::REGRAS`, em `src/Icones.php`.

`tests/regras.php` e `tests/sistema.php` conferem que toda chave da lista tem o seu símbolo no sprite.
