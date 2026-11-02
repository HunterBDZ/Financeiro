# Como contribuir

Abra uma issue antes de uma mudança grande. Falhas de segurança seguem o [`SECURITY.md`](SECURITY.md).

Ao enviar uma contribuição, você concorda em distribuí-la sob a licença do projeto, a [AGPL-3.0-or-later](LICENSE). Assine cada commit com `git commit -s` ([DCO](https://developercertificate.org/)).

## Rodar localmente

Você precisa de PHP 8.4 ou mais novo, com `pdo_sqlite`, `mbstring` e `curl`. Sem instalar banco nenhum:

```sh
export FIN_DADOS=/tmp/financeiro-dev
php database/instalar.php --demo                            # dados fictícios; entre com ana / demo1234
php -S 127.0.0.1:8099 -t public tools/servir-dev.php        # o site
php tests/run.php                                           # os testes
```

`tests/run.php` confere o banco dessa pasta e roda as regras num banco próprio, com a data parada ([Por dentro](docs/POR-DENTRO.md#cálculo-e-testes)). Regra nova que precisa de dados ou da data vai em `tests/regras.php`; em `tests/conferencia.php`, só o que vale para qualquer casa, em qualquer dia.

Para ver o site ou a demonstração num dia escolhido, pare o relógio com `FIN_RELOGIO`, no fuso do site. Só vale na linha de comando e no `php -S`:

```sh
FIN_RELOGIO='2026-11-30 23:50:00' php database/instalar.php --demo
FIN_RELOGIO='2026-11-30 23:50:00' php -S 127.0.0.1:8099 -t public tools/servir-dev.php
```

Enquanto o site antigo não migra, o código também precisa funcionar em MySQL 8, com `pdo_mysql`, e os testes têm de passar nos dois. Use um banco só para isso: sem `FIN_DADOS`, o teste roda contra o banco de `config/.env`.

```sh
mysql -uroot -e "CREATE DATABASE financeiro_demo CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
  CREATE USER 'financeiro_demo'@'127.0.0.1' IDENTIFIED BY 'demo1234';
  GRANT SELECT, INSERT, UPDATE, DELETE ON financeiro_demo.* TO 'financeiro_demo'@'127.0.0.1';"
mysql -uroot financeiro_demo < database/schema.sql
cp config/.env.example config/.env   # e aponte DB_HOST=127.0.0.1, DB_NAME, DB_USER e DB_PASS para o banco acima
php database/instalar.php --demo     # dados fictícios; entre com ana / demo1234
php -S 127.0.0.1:8099 -t public tools/servir-dev.php   # o site
php tests/run.php                    # os testes
```

Assim, a conferência roda no MySQL, e as regras, em SQLite. Para as regras rodarem também no MySQL, como no CI, passe um usuário que crie e apague bancos: elas usam um banco `financeiro_regras_…` só delas.

```sh
FIN_REGRAS_DRIVER=mysql FIN_REGRAS_DB_HOST=127.0.0.1 FIN_REGRAS_DB_USER=root FIN_REGRAS_DB_PASS=... php tests/run.php
```

## Antes de enviar

- `php tests/run.php` termina com 0 falhas.
- Mudou o banco: siga [Como lançar uma versão](docs/LANCAR-VERSAO.md).
- Texto novo passa por `t()`, com tradução em `src/idiomas/`. Rode `php tools/idiomas/gerar.php`, que aponta o que falta.
- Não edite arquivos gerados à mão: temas, ícones e fontes do PDF têm um gerador em `tools/`.
- O projeto não usa dependências no servidor, nem CDN.

## Regras de texto

- As telas servem a qualquer pessoa: não suponha tipo de empréstimo, profissão ou quantas pessoas usam o site.
- Sem nome de instituição financeira em telas, código, documentos, prints ou commits. Use "a instituição" ou "o extrato".
- Sem dados reais em exemplos, testes ou prints. Use a demonstração.

O porquê das regras está em [`docs/decisoes.md`](docs/decisoes.md).
