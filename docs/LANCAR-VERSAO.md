# Como lançar uma versão

Para quem desenvolve. Quem só usa o site segue a [atualização do manual](INSTALACAO.md#atualizar-para-uma-versão-nova).

## Numeração

`MAIOR.MENOR.CORREÇÃO`:

| Mudou o quê | Exemplo | Sobe |
|---|---|---|
| Correção sem novidade | conserta um cálculo | `1.0.0` → `1.0.1` |
| Algo novo, sem exigir nada de quem atualiza | uma aba nova | `1.0.1` → `1.1.0` |
| Exige ação manual ou muda um comportamento importante | novo requisito de PHP | `1.1.0` → `2.0.0` |

## Passo a passo

1. Desenvolva e teste num banco de demonstração ([como preparar](../CONTRIBUTING.md#rodar-localmente)): `php tests/run.php`.
2. Se o banco mudou:
   - crie `database/migrations/AAAA_MM_DD_descricao.sql` (estrutura) e, se precisar mudar dados, um `.php` com o mesmo prefixo;
   - faça a migração funcionar se rodar de novo, porque ela só é registrada depois de terminar;
   - no SQLite, crie também `database/migrations-sqlite/sNNNN_descricao.sql` (ou `.php`, que devolve uma função que recebe a conexão), com número maior que o último;
   - atualize `database/schema.sql` e `database/schema.sqlite.sql`, porque instalações novas começam por eles e marcam as migrações como feitas.
3. Atualize `VERSION`, o selo de versão no topo do `README.md` (ele aparece duas vezes na mesma linha) e acrescente a seção da versão no topo de `CHANGELOG.md`. Os testes falham se o selo e o `VERSION` não baterem.
4. Publique:
   ```sh
   git add -A && git commit -m "Versão 1.1.0: aba Exemplo"
   git tag -a v1.1.0 -m "Versão 1.1.0"
   git push origin main --follow-tags
   ```

Numa tag, `.github/workflows/release.yml` testa, monta os pacotes com `packaging/empacotar.sh`, assina o `atualizacao.json` com o segredo `FIN_CHAVE_ATUALIZACAO`, publica a versão e a imagem Docker. Sem o segredo, a publicação para: os sites só aceitam o que essa chave assinou.

Para gerar um par novo, `php tools/assinar-atualizacao.php --gerar=ARQUIVO`: o privado vai para o arquivo, que nunca entra no repositório, e o público sai na tela e vai para `Atualizacao::CHAVE_PUBLICA`. Quem trocar a chave precisa publicar a chave nova numa versão assinada pela antiga.

Os sites só recebem o que tem tag: um commit sem tag no `main` não chega a ninguém. Não mova nem apague uma tag publicada: se houver erro, lance outra versão.

## Migrações

- A tabela `migracoes` registra o que já rodou em cada site.
- `database/instalar.php` marca como aplicadas as que já vieram no `schema.sql`.
- A pasta `database/migrations` começa vazia, só com `.gitkeep`: o `schema.sql` já contém tudo. Mantenha a pasta, porque o atualizador procura as migrações nela.
- `database/migrations-sqlite` vale para o SQLite: cada arquivo roda na sua transação, ao atualizar ou ao abrir um pacote novo.
- O `.sql` roda como root do MySQL, e o `.php` como o usuário do PHP, que só tem `SELECT`, `INSERT`, `UPDATE` e `DELETE`.
