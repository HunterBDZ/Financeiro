# Créditos dos ícones de categoria

Os ícones em `public/assets/img/icones.png` são emojis do **Twemoji** reduzidos a pixel art
(20×20) por `tools/icones/gerar.py`.

- Twemoji 15.1.0 — https://github.com/jdecked/twemoji (originalmente Twitter/X).
- Gráficos licenciados sob **CC-BY 4.0** — https://creativecommons.org/licenses/by/4.0/
- Mudanças: redução de tamanho, paleta limitada, contorno de 1 px e cinzas escuros clareados.

Para trocar ou acrescentar ícones: edite `tools/icones/icones.json` (chave, rótulo, código do
emoji, grupo) e rode `python3 tools/icones/gerar.py` (precisa de cairosvg, Pillow e numpy). Ele
regera o sprite e `src/icones_lista.php`. Novas palavras para a sugestão pelo nome ficam em
`Icones::REGRAS` (src/Icones.php).
