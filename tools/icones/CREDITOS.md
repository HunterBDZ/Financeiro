# Créditos dos ícones de categoria

Os ícones em `public/assets/img/icones.webp` vêm do [Noto Emoji](https://github.com/googlefonts/noto-emoji), © Google LLC, sob a licença Apache-2.0 (cópia em [`LICENSE-Noto-Emoji.txt`](LICENSE-Noto-Emoji.txt)).

**O que foi modificado:** os PNGs de 512 px foram reduzidos para 160 px e reunidos em um único sprite WebP por `tools/icones/gerar.py`. O desenho não foi alterado.

**Por que um commit fixo:** o gerador usa o commit `41e31b110b4eb929dffb410264694a06205b7ad7`. Depois dele, o `LICENSE` da raiz do projeto Noto Emoji passou a ser o da OFL, enquanto o README continua dizendo que as imagens são Apache-2.0. Nesse commit, o `LICENSE` e o README dizem a mesma coisa. Reavalie isso antes de trocar o commit.

**Integridade:** `noto.sha256` guarda o SHA-256 de cada PNG, e o gerador para se algum for diferente. Nenhum arquivo baixado fica no repositório: o cache é uma pasta temporária.

## Trocar ou acrescentar um ícone

1. Edite `tools/icones/icones.json` (chave, rótulo, código do emoji e grupo). Não troque chaves existentes: elas ficam gravadas em `categorias.icone`.
2. Rode `python3 tools/icones/gerar.py --atualizar-hashes` (precisa de Pillow com WebP) e revise o diff de `noto.sha256`.

O gerador refaz o sprite e `src/icones_lista.php`. As palavras que sugerem um ícone pelo nome da categoria ficam em `Icones::REGRAS`, em `src/Icones.php`.
