# financeiro — decisões e operação

Repositório canônico: `~/financeiro` no servidor 192.168.0.154 (git, branch `main`).
Produção: `/var/www/financeiro`, publicada a partir deste repositório. O código que estava
na máquina .164 (publicado em 10/10/2026) é o primeiro commit daqui; trabalhe sempre neste.

## 2026-10-16 — ícones de categoria, seletor de meses e ajustes pedidos

- **Ícones de categoria (pixel art):** cada categoria tem um ícone (Farmácia = pílula, Mercado =
  prato de comida, Soldo = saco de dinheiro…), mostrado no Início (últimos lançamentos, para onde
  foi), no Extrato, no Mês, nas Metas e na lista de categorias. São 77 emojis do Twemoji (CC-BY 4.0)
  reduzidos a 20×20 num sprite só (`public/assets/img/icones.png`, ~35 KB), gerado por
  `tools/icones/gerar.py` a partir de `tools/icones/icones.json` (créditos em `tools/icones/CREDITOS.md`).
- **Escolher o ícone:** ao criar uma categoria (Extrato → Categorias) há um catálogo por grupo
  (comida, casa e contas, transporte, saúde, dinheiro, lazer). Enquanto ninguém escolhe, o ícone
  acompanha o nome digitado. Nas categorias existentes, tocar no ícone abre
  `/categorias/{id}/icone` para trocar.
- **Banco:** coluna nova `categorias.icone` (VARCHAR(24), NULL), migração
  `database/migrations/2026_10_18_icone_categoria.sql`. NULL = o app sugere pelo nome e grupo
  (`Icones::sugerir`, regras em `Icones::REGRAS`), por isso as categorias que já existiam não
  precisaram de dado novo e uma categoria criada só em produção também ganha ícone.
- **Seletor de meses:** a fileira de meses do Mês virou um painel que abre no título do mês (no Mês
  e no Extrato): meses por ano, ponto de estado, mês corrente destacado; funciona sem JS
  (`details.menu`) e fecha com Esc ou clicando fora. No celular as setas descem para a linha do estado.
- **Ações destrutivas** ("Tirar deste mês", "Excluir lançamento") viraram caixas vermelhas do
  tamanho de um botão; "Voltar à meta" virou uma caixa neutra ao lado.
- **Saídas em vermelho:** valores de saída nos lançamentos (Início, Extrato), "Saiu" no resumo do
  Início e o total de Saídas no Mês. Os valores por categoria do Mês ficam neutros (o vermelho ali
  continua querendo dizer "passou da meta").
- **Lançar sem atalhos:** saíram as 6 pílulas de categorias mais usadas; fica só o select (que
  continua com as mais usadas no topo de cada grupo). O serviço `LancarForm` perdeu a consulta que
  só servia aos atalhos.

## 2026-10-16 — visual novo ("fintech"), claro e escuro, com o spitz da casa

- **Por quê:** o casal pediu um visual moderno de app de banco/cripto, limpo e intuitivo
  ("menos poluição é melhor", "menos gamificado"). Um redesign anterior ("linha d'água",
  papel e tinta) foi recusado antes de ir ao ar e foi todo substituído.
- **Regras do visual:** uma coisa em destaque por tela; cor só com função (limão = ação
  principal; verde = entrou; vermelho = saiu/negativo; âmbar = atenção, abaixo da reserva,
  perto do limite da meta). Manrope no texto e Sora nos números grandes, auto-hospedadas
  (`public/assets/fonts`, licenças OFL junto). Cinzas e cores com contraste AA (axe: 0
  violações em todas as telas, nos dois temas, no celular e no computador).
- **Tema:** segue o aparelho até alguém escolher; a escolha fica no `localStorage` (`tema`,
  como antes). Seletor Claro/Escuro sempre visível: menu lateral no computador e sol/lua no
  topo no celular (a Natalia usa o claro).
- **Olho do saldo** (`[data-ocultar]`): borra os números da página; vale só para a aba aberta
  (`sessionStorage`), porque o `localStorage` só guarda o tema.
- **Spitz:** a imagem em pixel art (`public/assets/img/spitz.png`) foi enviada pelo usuário; é
  a marca do app, o ícone da aba e espia por cima do cartão do login.
- **Rótulos corrigidos junto:** no Mês, "Em conta hoje" só no mês atual (nos outros, "Conta no
  fim do mês", que é o valor mostrado); no Anual, a comparação é com o fim do mês atual
  ("desde out/26"), não com "hoje".
- Nenhuma mudança de banco nem de regra de cálculo; os 57 testes seguem iguais (só o marcador
  da tela inicial mudou de `agua-valor` para `saldo-v`).
- **Tela grande:** o conteúdo fica centralizado ao lado do menu. A partir de 1600 px ele cresce até
  1560 px e as telas ganham colunas:
  - Início em 3 colunas;
  - Mês com essenciais e não essenciais lado a lado;
  - Extrato em tabela;
  - Anual com "Conta no fim" e "Dívida" na tabela, que no celular ficam no detalhe do mês;
  - Metas com a renda à esquerda e os grupos à direita.
  A partir de 1280 px, Fechar mês e Ajustes ficam em 2 colunas.

## 2026-10-15/17 — aba Mês editável; a meta é a previsão padrão (opção B)

- **Regra:** a meta de uma categoria de gasto vale como previsão de **todo mês ainda em
  PREVISAO**. Um valor gravado num mês é a **exceção** daquele mês ("ajustado"); "voltar à meta"
  apaga a linha. Definição única: view `previsao_efetiva` (migrações `2026_10_16_meta_padrao.sql`
  e `2026_10_17_congelar_metas.sql`). Quem **lê** previsão lê da view; quem **grava** usa só
  `PrevisaoLote` (log + desfazer). Categoria arquivada não herda meta.
- **Mês fechado não muda:** ao fechar, `PrevisaoLote::congelar()` grava como linhas do mês a
  previsão que vinha da meta (lote `congelar`, fora do "desfazer última alteração"); reabrir
  desfaz esse lote (`descongelar()`), e o mês volta a seguir as metas de hoje.
- **Aba Mês (mês aberto ou futuro):** tocar numa linha abre a edição — salvar · tirar deste
  mês · voltar à meta, só no mês ou "também nos meses seguintes". `+ incluir conta` põe no
  mês uma conta sem previsão. O aviso mostra o efeito no fim do mês, o primeiro mês que furar
  a reserva ou ficar no vermelho, e tem DESFAZER. Fita com todos os meses no topo; a aba
  anual leva a cada mês. Mês fechado continua só leitura.
- **Meta:** salvar é atômico e mostra o efeito no fim do último mês.
- **Fechar mês:** "aplicar" segue marcado; troca as metas do dia a dia só no mês seguinte.
- **Dados (lotes `migracao`, 15/10):**
  1. Barbeiro, Mercado e Lanches passaram a seguir a meta nos meses previstos (saíram os
     valores originais da planilha). Correção em 17/10: Barbeiro out/26 = 140 tinha sido
     gravado na tela antiga de Previsão (sem log) e foi devolvido;
  2. a previsão da "Delivery / comer fora" (arquivada, 400/mês) passou para
     "Restaurante/ Delivery", que já prevê a meta de 450/mês (não contar o delivery 2×).

## Staging e publicação

- Banco `financeiro_stage` (cópia da produção) + usuário MySQL `financeiro_stage`;
  `~/financeiro/config/.env` (fora do git) aponta para ele. Testes: `cd ~/financeiro && php tests/run.php`.
- Servidor de teste: `cd ~/financeiro && php -S 127.0.0.1:8099 -t public` e, da sua
  máquina, `ssh -N -L 8099:127.0.0.1:8099 hunter@192.168.0.154` → http://127.0.0.1:8099.
  (Ao parar, use `pkill -f "[p]hp -S 127.0.0.1:8099"`: sem o colchete o padrão casa com o
  próprio comando do ssh e derruba a sessão.)
- Recarregar a staging com os dados de produção (como root):
  `mysqldump --single-transaction --routines --triggers financeiro | mysql financeiro_stage`.
- Publicar: testes na staging → backup do banco e do código → migrações SQL como root
  (`sudo mysql financeiro < database/migrations/….sql`) → `sudo financeiro-publish ~/financeiro`
  (copia src/public/tests/schema.sql e roda `tests/run.php` em produção, como www-data; não
  copia `database/migrations`) → migrações de dados copiadas para
  `/var/www/financeiro/database/migrations` e rodadas com `sudo -u www-data php …`.
- **Voltar uma versão:** código e banco andam juntos. Voltar só o código para antes de
  `ed55308` com a migração de dados aplicada deixa as categorias com meta sem previsão (a
  projeção fica otimista demais). Restaure também o dump do banco do mesmo momento
  (`~/financeiro-antes-*.sql.gz`, `/var/backups/financeiro/antes-*.sql.gz`) — ou desfaça antes
  os lotes `migracao`. Código: `git archive <commit> | tar -x -C /tmp/fin-volta && sudo financeiro-publish /tmp/fin-volta`.
- `database/schema.sql` é gerado da staging com
  `mysqldump --no-data --skip-comments --skip-dump-date --skip-add-drop-table --routines --triggers`
  (sem `DROP TABLE`, de propósito).
