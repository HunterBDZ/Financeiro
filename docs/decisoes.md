# financeiro — decisões e operação

Repositório canônico: `~/financeiro` no servidor 192.168.0.154 (git, branch `main`).
Produção: `/var/www/financeiro`, publicada a partir deste repositório. O código que estava
na máquina .164 (publicado em 10/10/2026) é o primeiro commit daqui; trabalhe sempre neste.

## 2026-10-16 — "e se" no Anual

- **O que faz:** no cartão "E se…?" do Anual (fechado por padrão) dá para simular economizar ou
  gastar a mais todo mês a partir de um mês, um gasto ou uma entrada num mês, e ligar ou desligar o
  adiantamento do empréstimo com a sobra. **Nada é gravado:** a simulação vai na URL (GET,
  `?se=1&mensal=…&mensal_tipo=economizar|gastar&mensal_de=AAAA-MM-01&uma=…&uma_tipo=gasto|entrada&uma_mes=…&adiantar=1`),
  então dá para voltar, recarregar ou mandar o link.
- **Como calcula:** `MotorFinanceiro::calcular` ganhou a opção `extra` (mês → valor, só fora de mês
  fechado: + entra, − sai, como uma categoria operacional a mais); sem `adiantar`, usa `objetivo` 3.
  `FuturoController::simulacao` lê e valida a URL (valor inválido é avisado e ignorado; teto de
  1 milhão; os meses precisam ser do plano, do atual em diante).
- **O que mostra:** com a simulação ativa a página inteira é o simulado (resumo, gráfico, tabela),
  com um aviso no topo ("Simulação: … Nada foi salvo." e "Voltar ao plano"). O cartão compara
  plano × simulação: conta e dívida no fim, mês mais apertado, meses abaixo da reserva, quando o
  empréstimo acaba e os juros até quitar (`AnualResumo::numeros`/`quitacao`). A dívida no fim
  entrou porque, sem ela, "sem adiantar" parecia melhor do que é. O gráfico mostra o plano em
  cinza por trás; os meses afetados ganham o chip "E se: ±valor" e a conta do mês diz quanto
  do "e se" entrou. No celular a comparação vira um bloco por indicador.

## 2026-10-16 — aba Anual repensada

- **Por quê:** o casal achou ruim a divisão do espaço (no computador as 5 colunas se espalhavam
  pela largura toda) e as informações "mais ou menos". Antes de mexer foram feitas duas pesquisas:
  boas práticas de gráfico e tabela (IBCS, Stephen Few, Datawrapper, NN/g, GOV.UK) e 26 apps de
  finanças e de previsão de caixa (PocketSmith, Simplifi, YNAB, Undebt.it, Float, Mobills,
  Organizze, Meu Dinheiro Web…). As notas ficaram fora do repositório, em `~/financeiro/research_notes/`
  na máquina de trabalho.
- **Resumo** (coluna à direita a partir de 1280 px, parada enquanto a tabela rola; no topo no
  celular): como o plano termina e os marcos em ordem, cada um levando ao mês. Os trechos abaixo
  da reserva ou no vermelho, a volta à reserva, a sobra adiantando o empréstimo e a quitação vêm de
  `AnualResumo::marcos`. **O ganho de adiantar** vem de `AnualResumo::ganho`, que compara com o mesmo
  plano sem adiantamento (`objetivo` 3) e continua só com as parcelas até zerar: em 16/10, quitar
  em out/27 em vez de nov/35, com cerca de 17,6 mil de juros a menos.
- **Gráfico:** o título diz a conclusão ("A conta fica no vermelho em nov/26 e volta à reserva em
  dez/26"). A curva suave deu lugar à linha reta, com um ponto por mês (● fechado, ◐ aberto,
  ○ previsto); no celular só ficam os pontos de hoje, do mês mais apertado e do fim, porque os 16
  embolavam. A previsão é tracejada, há uma faixa âmbar abaixo da reserva e uma vermelha abaixo
  de zero, a marca "hoje" e a dívida em cinza (enquanto a conta fica parada na reserva, é ela que
  cai). A quitação vai no eixo, embaixo do mês.
- **Tabela:** separada por ano, com o total do ano (entradas, saídas e balanço somados; conta e
  dívida de dezembro). Os números não se esticam (no máximo 820 px até 1600 px de tela), a cor só
  aparece nas exceções e a dívida quitada vira "—". No celular ficam Mês | Balanço | Conta no fim.
  Em tela larga (≥ 1600 px), cada linha mostra o que mudou em relação ao mês anterior
  (`AnualResumo::mudancas`, mudanças a partir de R$ 150; a seta diz se subiu ou caiu e a cor, se
  foi bom ou ruim). A linha aberta mostra a conta do mês: começou com + entradas − gastos −
  adiantado = conta no fim. O casal desconfiou que "o que mudou" fosse fixo: não é, sai da previsão
  e dos lançamentos a cada vez que a página abre (teste "acompanha a previsão gravada"; na staging,
  Mercado de mar/27 1.000 → 1.500 fez aparecer "↑ Mercado 500" em mar/27 e "↓ Mercado 500" em
  abr/27). O rótulo passou a dizer a comparação: "Mudou em relação ao mês anterior".
- **Ficou para depois:** um "e se" (sem adiantar, +R$ 500/mês) e o previsto × realizado dos meses
  fechados, as duas ideias que sobraram da pesquisa.

## 2026-10-16 — emojis da Apple e barra do celular com o "+" no meio

- **Emojis da Apple no lugar da pixel art:** o casal achou a pixel art feia e pediu "os emojis do
  iPhone". O sprite agora é `public/assets/img/icones.webp` (~400 KB, 1680×1344): os mesmos 77
  ícones, desenhos da Apple em 160 px (`img-apple-160` do iamcal/emoji-data), cada célula com 8 px
  transparentes de respiro (vários emojis encostam na borda e vazavam para o vizinho quando o
  navegador reduzia a imagem). `Icones::html` desenha o emoji a 66% dentro de um círculo
  (`--card-2`); no tema escuro um contorno claro de 1 px (`--ic-halo`) impede que emojis escuros
  (nota musical, estetoscópio, controle) sumam no fundo. Na grade de escolha o emoji vai sem
  círculo e maior. As imagens são © Apple: servem ao uso pessoal deste app, não publicar
  (`tools/icones/CREDITOS.md`); o cache das imagens fica fora do git.
- **Barra do celular:** com 5 abas o "+" ficava fora do meio. Agora são Início, Extrato, +, Mês,
  Metas (duas de cada lado). O Anual abre por "Visão anual", no fim do seletor de meses do Mês, e
  pelo menu do avatar (ao lado de Ajustes), opção escolhida pelo casal; no Anual a aba Mês fica
  acesa. No computador nada muda: o menu lateral continua com todas as telas.

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
