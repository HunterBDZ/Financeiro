# Financeiro

App web de finanças da casa: substitui a planilha do casal. Os dois lançam gastos pelo celular ou
pelo computador, acompanham o mês e veem como a conta e o empréstimo ficam até o fim do plano.
Roda num servidor doméstico, só na rede local.

> Repositório privado: traz nomes, valores e imagens de uso pessoal (ver [Imagens e licenças](#imagens-e-licenças)).

## Telas

| Tela | Rota | Para quê |
|---|---|---|
| Início | `/` | Quanto ainda dá pra gastar, o que vence, últimos lançamentos, conferência com o banco |
| Extrato | `/lancamentos` | Lançamentos do mês, busca, edição, categorias |
| + Lançar | `/lancar` | Registrar um gasto ou entrada, na conta ou no cartão |
| Mês | `/mes` | Previsão editável, contas do mês, fechar e reabrir |
| Cartão | `/cartao` | Fatura aberta, próximas faturas, parcelamentos |
| Anual | `/futuro` | Projeção mês a mês, marcos, simulação "E se…?" |
| Metas | `/meta` | Meta por categoria, usada como previsão padrão |
| Ajustes | `/ajustes` | Parâmetros, contas recorrentes, aparelhos, senha |

## Regras principais

- **Previsão × real:** cada mês está em `PREVISAO` ou `REAL` (fechado). Mês fechado usa a soma dos
  lançamentos e não muda.
- **Meta:** vale como previsão de todo mês em aberto. Um valor gravado no mês é a exceção. A view
  `previsao_efetiva` é a definição única; só `PrevisaoLote` e `PrevisaoModel` gravam na tabela.
- **Empréstimo e poupança** nunca contam como renda ou gasto (`categorias.operacional`).
- **Balanço** nas telas é o fluxo de caixa completo do mês (`MotorFinanceiro::balancoTotal()`), por
  decisão do casal. Não trocar por um balanço só operacional.
- **Cartão:** no plano só conta a fatura, no mês em que vence. A compra não entra na categoria nem
  na meta.

O porquê de cada decisão está em [`docs/decisoes.md`](docs/decisoes.md). Leia antes de mudar uma regra.

## Stack

PHP 8.5 (mod_php) e MySQL 8.4 com Apache, sem framework, Composer, npm ou etapa de build. A CSP é
estrita: nada de `<script>` inline nem CDN, e as fontes são auto-hospedadas.

```
public/      raiz do site: index.php (rotas) e assets
src/         Controllers, Models, Services (MotorFinanceiro, CartaoFatura, PrevisaoLote…), Views
database/    schema.sql, migrations/, seed.php
tests/       run.php
tools/       publicação, backup, geração do sprite de ícones
docs/        decisoes.md
config/      .env (fora do git) e .env.example
```

## Rodar localmente

Requer PHP com `mbstring` e `pdo_mysql`, e um MySQL.

```sh
cp config/.env.example config/.env     # preencha o acesso ao banco
mysql financeiro < database/schema.sql
php -S 127.0.0.1:8099 -t public
```

`database/seed.php` importa a planilha original de `database/data/`, que fica fora do git, e cria os
usuários com uma senha inicial fraca: troque-a logo em Ajustes.

## Testes

```sh
php tests/run.php
```

Sem dependências. Rodam dentro de uma transação desfeita no fim, e a publicação os executa também
em produção.

## Onde fica o quê

- **Repositório oficial:** `~/financeiro` no servidor, com a staging (banco `financeiro_stage`).
- **GitHub:** cópia de segurança. Publicar em produção não depende dele.
- **Produção:** `/var/www/financeiro`.

## Publicar

Testes na staging, backup do banco e do código, migrações SQL como root e então:

```sh
sudo financeiro-publish ~/financeiro
```

O comando não copia `database/migrations`. O passo a passo, inclusive como voltar uma versão, está
em [`docs/decisoes.md`](docs/decisoes.md#staging-e-publicação).

## Imagens e licenças

- `public/assets/img/icones.webp`: emojis © Apple, só para uso pessoal. **Não publicar**; este é o
  motivo principal de o repositório ser privado. Créditos em `tools/icones/CREDITOS.md`.
- Fontes Manrope e Sora: licença OFL, junto dos arquivos em `public/assets/fonts`.
