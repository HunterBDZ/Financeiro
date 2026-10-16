# Créditos dos ícones de categoria

Os ícones em `public/assets/img/icones.webp` são os emojis da Apple (Apple Color Emoji) em
160 px, juntados num sprite por `tools/icones/gerar.py`.

- Desenhos © Apple Inc. Não têm licença livre: aqui servem só ao uso pessoal deste app da casa,
  então não publique o sprite nem o repositório.
- As imagens vêm do repositório iamcal/emoji-data (pasta `img-apple-160`),
  https://github.com/iamcal/emoji-data, e ficam em cache em `tools/icones/.apple/`, fora do git.
- Até 2026-10-15 os ícones eram Twemoji (CC-BY 4.0) reduzidos a pixel art; ver o histórico do git.

Para trocar ou acrescentar ícones: edite `tools/icones/icones.json` (chave, rótulo, código do
emoji, grupo) e rode `python3 tools/icones/gerar.py` (precisa de Pillow com WebP). Ele baixa o
que faltar, regera o sprite e `src/icones_lista.php`. Novas palavras para a sugestão pelo nome
ficam em `Icones::REGRAS` (src/Icones.php); o formulário recebe as regras prontas em JSON.
