# financeiro — decisões e operação

Repositório: GitHub (`HunterBDZ/Financeiro`); `~/financeiro` no servidor 192.168.0.154 é a cópia de trabalho
com a staging. Produção: `/var/www/financeiro`, atualizada por versões com `financeiro-atualizar` desde
1/11/2026 (remoto `github`, chave só de leitura). O código que estava
na máquina .164 (publicado em 10/10/2026) é o primeiro commit daqui; trabalhe sempre neste.

## 2026-11-01 — temas

- **Por quê:** o casal quis um lugar para escolher o tema, com os 32 da pesquisa e cartões com a prévia de cada um.
- **Tela `/temas`:** menu lateral e menu do M, além de um atalho em Ajustes. Tocar num cartão mostra o tema no app inteiro, e ele só
  fica ao salvar; sem JS, o toque já salva. O atalho claro/escuro continua e leva aos temas Claro e Escuro, na conta.
- **Onde fica:** `usuarios.tema`, na conta de cada um; NULL é automático. Ele vem no HTML, então não pisca. O `localStorage` guarda só
  a cópia para a tela de login, e quem nunca escolheu segue com o que já tinha no aparelho.
- **Como é feito:** `tools/temas/gerar.py` lê `tools/temas/temas.json`, confere o contraste e gera `public/assets/css/temas.css` e
  `src/temas_lista.php`. Claro e Escuro continuam em `style.css`. As cores fixas do cartão de saldo e dos avisos viraram tokens. Um teste mede
  o contraste de cada tema. Para criar um tema: acrescente no JSON e rode o gerador.
- **Portátil** é mantido a pedido, mas entrada e saída têm a mesma cor: só o sinal + e − separa. Créditos em `tools/temas/CREDITOS.md`.

## 2026-11-01 — aba Wishlist

- **Por quê:** o casal queria um lugar para guardar o que pretende comprar, com link, foto e valor, e saber quando
  fica melhor comprar o 1º da lista.
- **Previsão (`WishlistPrevisao`):** roda o motor uma vez para cada mês de compra, com a opção `extra`, e olha o plano
  inteiro. Cabe quando a compra não deixa nenhum mês abaixo da reserva; mês que já estava abaixo sem ela só não
  pode piorar. O melhor mês é o 1º que cabe sem atrasar o empréstimo; "sem esperar" mostra o 1º que cabe, com os juros a
  mais. Parcelado sai da fatura do mês seguinte, sem juros; se alguma parcela passar do plano, o mês não é avaliado.
  Não grava nada e usa o plano de hoje.
- **Fileira de meses:** cada mês tem símbolo e rótulo, não só cor: cabe, cabe atrasando o empréstimo, abaixo da reserva,
  no vermelho. O motivo de separar os dois primeiros é que essa é a decisão real.
- **Link (`LinkProduto`):** lê JSON-LD de produto, as etiquetas de compartilhamento e a marcação usual de páginas grandes.
  O endereço vem da pessoa, então vale a defesa contra SSRF do OWASP: só http/https nas portas 80 e 443, todo IP
  resolvido tem de ser público, a conexão vai para o IP conferido, cada redirecionamento é reavaliado, e há limite
  de tempo e de tamanho. Loja que bloqueia não quebra nada: o item é preenchido à mão.
- **Foto:** a CSP só permite imagem local, então nada de link externo. Fica em `wishlist_fotos`, no banco: entra nos
  backups e na volta do atualizador, que apagaria arquivos soltos no site. Sem a extensão GD, o servidor não reduz nem
  reescreve a imagem: confere pelo conteúdo (JPEG, PNG ou WebP, até 3 MB), e o navegador reduz a foto escolhida. Sai por
  `/wishlist/{id}/foto`, só com login. SVG é recusado.
- **Moedas (`Cotacao`):** o item guarda o valor na moeda escolhida, e a conversão para reais é feita ao mostrar, então muda
  com a cotação. A previsão e o "Comprei" usam os reais de hoje. A cotação é a comercial, guardada na tabela `cotacoes` e
  renovada no máximo a cada 2 horas, com uma fonte reserva; se as duas falharem, vale a última guardada, com a data à vista.
  IOF e taxa do cartão não entram, e a tela avisa. As fontes são de terceiros, sem chave, e o endereço delas está no código.
- **Ordem:** por botões (1º, subir, descer), sem arrastar: funciona igual no celular e no teclado (WCAG 2.5.7).
- **Comprei:** tira o item da lista e abre o "+" preenchido, onde se escolhe conta ou cartão; tem desfazer. Excluir também;
  o item some de vez depois de 30 dias.
- **Onde fica:** menu lateral e menu do M. A barra do celular já tem 5 itens.

## 2026-11-01 — empréstimo sem nome de instituição

- **Regra:** o app e o repositório são genéricos: nada de nome de instituição financeira em telas, código,
  documentos, prints ou commits. Nas telas, usar "a instituição" ou "o extrato". Um teste varre os arquivos.
- **Dados:** a migração `2026_11_01_descricao_emprestimo.sql` deixa o lançamento do crédito só como "Crédito do
  empréstimo". O nome dado a um cartão ou digitado num lançamento é escolha de quem usa, e o app não altera.

## 2026-11-01 — versões e atualização pelo GitHub

- **Por quê:** o projeto vai abrir ao público, e cada dono de site precisa atualizar sem saber as migrações.
- **Versões:** a tag `vX.Y.Z` é o que chega aos sites; o `main` sem tag não chega. `VERSION` precisa
  coincidir com a tag. Processo em [`LANCAR-VERSAO.md`](LANCAR-VERSAO.md).
- **`financeiro-atualizar`:** ensaia migrações e testes numa cópia do banco, com o site intacto; só então faz o
  backup, migra e copia. Se falhar depois disso, volta o código e o banco sozinho. Não há atualização
  automática: é o dono quem decide, porque são dados financeiros.
- **Migrações:** a tabela `migracoes` registra o que rodou. Em site anterior a ela, `.na-base` diz o que já está
  aplicado; o `instalar.php` registra as que o `schema.sql` já contém.
- **Limite conhecido:** se a volta automática restaurar o banco, perde o que foi lançado nos segundos
  entre o backup e a falha; o site não entra em manutenção nesse intervalo.
- **Antes de abrir ao público:** criar um repositório novo, sem o histórico (nomes, valores e o IP da casa);
  trocar `icones.webp` por ícones de licença livre; definir a licença; 
  que traz nomes e senha fixa, e tirar o IP de `deploy.sh` e `tools/backup-pull.sh`; e rever as menções pessoais
  em `docs/decisoes.md`.

## 2026-11-01 — aba Empréstimo: simular um adiantamento

- **Por quê:** a Natalia sentia falta de um lugar para pôr um valor adiantado e ver quanto falta. O
  "E se…?" do Anual não tem esse campo.
- **Compara com "só as parcelas" (`objetivo` 3), não com o plano:** o plano já adianta a sobra de
  cada mês, então um adiantamento manual só troca de lugar com o automático. Na staging, R$ 1.000 em
  nov/26 não mudou nenhum número do plano. A tela diz em uma linha quando o plano do Anual quita.
- **Como calcula:** `MotorFinanceiro::calcular` ganhou a opção `adiantar` (mês → valor, fora de mês
  fechado), que soma à amortização lançada no mês. `EmprestimoSimulacao` compara os dois cenários;
  depois do último mês do plano, segue só com parcela e juros. Valor acima da dívida vira a dívida.
- **Nada é gravado:** a simulação vai na URL (`/emprestimo?valor=…&mes=…`); `parcial=1` devolve só o
  resultado, que `emprestimo.js` troca enquanto se digita (o cálculo fica só no servidor).
- **Consolidar:** o cartão "Consolidar adiantamento" (`POST /emprestimo/adiantamento`) grava o que foi
  pago como lançamento da Amortização extra: entra no Extrato, sai da conta e abate a dívida, com
  desfazer. A data não pode ser futura, porque o motor só conta adiantamento lançado em mês aberto ou
  fechado. Igual a outro no mesmo dia pede confirmação. Valor acima da dívida estimada é aceito com
  aviso (o pagamento aconteceu), mas o motor só abate até a dívida e a conta prevista fica diferente
  do Extrato até o saldo ser conferido. "Adiantamentos feitos" lista os lançados, com edição.
- **Validação própria:** `parseMoedaBr` descarta letras ("12a" vira 12), então a tela confere o campo
  antes de chamá-la.
- **Onde fica:** menu lateral, menu do M e link no resumo do Anual. A barra do celular já tem 5 itens.
- **Dados do empréstimo:** juros, parcela, número de parcelas, 1ª parcela e crédito recebido saíram de
  Ajustes e são editados só na aba (`POST /emprestimo/dados`), para cada dado ter um lugar só. Ajustes
  ignora essas chaves (`AjusteModel::DO_EMPRESTIMO`), e os dois usam a mesma validação
  (`AjustesController::lerValores`). Parcela zero passou a ser recusada, porque zeraria a dívida.
- **Saldo devedor informado:** o saldo era só o valor presente da parcela, então baixar os juros aumentava
  a dívida (1,97% → 1,91% levou nov/26 de 14.000,90 a 14.268,95). Agora os ajustes `saldo_devedor` e
  `saldo_devedor_mes` (AAAAMM; criados na 1ª gravação, sem migração) dizem quanto o extrato cobra antes
  da parcela do mês: no início desse mês a dívida passa a esse valor (`MotorFinanceiro::saldoInformado`), e
  vale em todas as telas. Adiantamentos de meses anteriores já estão no extrato; os lançados no próprio mês
  ou depois abatem do saldo. Antes do mês informado o motor não supõe adiantamento automático. Os meses
  anteriores continuam com a dívida estimada, então o Anual pode mostrar um salto nesse mês. Em
  branco, tudo volta ao cálculo antigo. O mês tem de ser do plano e não pode ser futuro.
- **Em aberto até informar o saldo:** sem ele, o saldo inicial é o valor presente das 96 parcelas, mas
  ainda soma juros de set e out antes da 1ª parcela (nov/26). Com isso o cálculo chega a nov/35, e o
  contrato acabaria em out/34. A tela avisa enquanto as datas diferirem, e o "juros a menos" e o ganho
  do Anual dependem disso. A taxa guarda 2 casas em % (`ajustes.valor` é DECIMAL(14,4)).

## 2026-10-16 — aba Cartão: compras à vista e parceladas

- **Por quê:** o casal queria um lugar para lançar as compras no cartão e as parcelas; a previsão
  da "Fatura do cartão" era digitada à mão, mês a mês. Decidido com o casal em 16/10: um cartão;
  a previsão da fatura é só a soma das parcelas lançadas (sem estimar o à vista que
  ainda vai ser gasto); Cartão na barra do celular no lugar de Metas.
- **Regra (regime de caixa):** "fatura de M" é a que vence no mês M. É o dinheiro que sai da conta,
  e é nesse mês que ela conta no plano, como a categoria "Fatura do cartão" (grupo Cartao). A compra
  no cartão **não** conta de novo na categoria dela nem na meta: no plano só a fatura conta.
- **Em que fatura cai:** compra no dia do fechamento ou depois vai para a fatura seguinte; se o
  vencimento é no dia do fechamento ou antes, a fatura vence no mês depois do fechamento
  (`CartaoFatura::faturaDe`; `cartao.js` repete a conta na prévia). Parcela = total ÷ n arredondado
  para baixo no centavo, a sobra na 1ª. Compra antiga: a "parcela atual" k cai na fatura aberta,
  então a 1ª foi k − 1 faturas antes. As parcelas não têm tabela: saem de `valor_total`,
  `parcelas` e `primeira_fatura` (que a edição da compra deixa trocar).
- **Banco:** migração `2026_10_19_cartao.sql`: tabelas `cartoes` (fechamento, vencimento, limite,
  `alimenta_plano`) e `cartao_compras`, e a origem de lote `cartao`. A `cartao_compras` da versão
  antiga do app (sem uso desde a reescrita) virou `cartao_compras_v1`; as compras dela entram uma
  vez, quando o cartão é cadastrado (`CartaoModel::importarAntigas`: total = parcela × nº de
  parcelas, 1ª fatura = mês da 1ª parcela, sem categoria).
- **Aba Cartão:**
  - a fatura aberta em forma de cartão: total, fecha e vence, limite livre (limite menos as
    parcelas da aberta em diante);
  - "Próximas faturas" em degraus, que escolhem a fatura mostrada (link `?fatura=`; funciona sem JS);
  - a fatura com Parcelas (k/N) e Compras à vista: cada linha abre a edição; excluir tem desfazer;
  - Parcelamentos, com quanto falta;
  - os dados do cartão e o menu ⋯ (Configurar, Ligar ao plano ou Tirar do plano).
- **Lançar:** no "+", "Pagou com: Conta | Cartão". No cartão:
  - vezes 1 a 6, 10 ou 12, ou outro número até 48;
  - prévia ao vivo ("Cai na fatura de novembro, que vence em 5/11. 10 × 320,00, de nov/26 a
    ago/27") e a escada das faturas com o que a compra soma em destaque;
  - "Outras vezes ou compra antiga" para informar a parcela atual;
  - a categoria tem de ser um gasto operacional, e não a própria Fatura do cartão;
  - o aviso de compra lançada tem desfazer.
- **Ligar ao plano (`alimenta_plano`):** começa desligado. Antes de ligar, a aba mostra a
  conferência: 6 faturas, previsão de hoje × lançado no cartão, com a diferença. Ligado:
  - toda mudança no cartão reescreve a previsão da Fatura do cartão de cada mês em PREVISAO, da
    fatura aberta em diante (`CartaoFatura::sincronizarPlano`, lote `cartao`, com log). Esse lote
    fica fora do "desfazer última alteração" do Mês;
  - os meses antes da aberta não mudam: a fatura já fechou e vale o valor do banco;
  - no Mês, a linha fica só para ler ("Vem do Cartão · ver a fatura") e salvar nela é recusado.
  Desligar devolve a edição ao Mês, e os valores ficam como estavam.
- **Barra do celular:** Início, Extrato, +, Mês, Cartão. Metas foi para o menu do M, com Anual e
  Ajustes.
- **Testes:** 101. A seção 15 cobre a fatura da compra, fechamento e vencimento, centavos, compra
  antiga, situação das faturas, limite e ordem dos parcelamentos. Numa transação: o plano ligado,
  o Mês, a aba, a importação única, o "+" com Pagou com e a barra. Esse teste desativa o cartão de
  verdade dentro da transação; sem isso ele falha quando já há cartão cadastrado e trava a
  publicação (os testes rodam na produção). E2E e axe passaram sem falhas nas telas novas.

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
  Metas (duas de cada lado). O Anual abre por "Anual", no fim do seletor de meses do Mês, e
  pelo menu do avatar (ao lado de Ajustes), opção escolhida pelo casal; no Anual a aba Mês fica
  acesa. No computador nada muda: o menu lateral continua com todas as telas.

## 2026-10-16 — ícones de categoria, seletor de meses e ajustes pedidos

- **Ícones de categoria (pixel art):** cada categoria tem um ícone (Farmácia = pílula, Mercado =
  prato de comida, Salário = saco de dinheiro…), mostrado no Início (últimos lançamentos, para onde
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
- Publicar agora: lançar a versão ([`LANCAR-VERSAO.md`](LANCAR-VERSAO.md)) e rodar `sudo financeiro-atualizar`. O
  processo abaixo, com `financeiro-publish`, é o antigo, e fica só para consulta.
- Publicar (antigo): testes na staging → backup do banco e do código → migrações SQL como root
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
  (sem `DROP TABLE`, de propósito), `sed -E 's/ AUTO_INCREMENT=[0-9]+//'`, para os contadores da
  staging não entrarem no diff, e `sed 's/DEFINER=`root`@`localhost` //'`, para qualquer usuário
  conseguir importar a view.
- Instalar do zero: [`INSTALACAO.md`](INSTALACAO.md) e `database/instalar.php`.

## 2026-11-01 — auditoria: rótulos antes de cortes

Auditoria das telas e dos acessos de 17/10 a 01/11. O uso pesado é no computador, e a tela mais aberta é o Mês. O maior problema eram rótulos que levavam a uma leitura errada, não o excesso.

- A previsão do fim do mês aparece no Início e no Mês porque "acima da reserva" parece dinheiro livre, e o plano já destinou esse valor. Vem de `fica_na_conta`, o mesmo número do Anual, e um teste confere.
- O selo só aparece quando a conta está abaixo da reserva ou no vermelho.
- Metas continua separada do Mês: uma muda todos os meses, a outra muda um. Juntar as duas arrisca alterar o plano inteiro sem querer.
- O número "Livre até o fim do mês" não entrou: falta decidir se o adiantamento do empréstimo conta.

## 2026-11-01 — idiomas

Português, inglês e espanhol, escolhidos em Ajustes e no login.

- O texto em português é a própria chave (`t('Salvar')`), e o que não tem tradução aparece em português. Assim o português não muda e um esquecimento não quebra a tela. Um teste falha se faltar tradução.
- A escolha fica na conta, como o tema, e num cookie, para valer também na tela de login. Sem escolha, segue o navegador.
- **Não se traduz** o que a pessoa digitou: nomes de categoria, descrições e itens da wishlist.
- **Valores continuam em reais e no formato brasileiro** (1.234,56 e dd/mm) em todos os idiomas, porque a moeda é o real e os campos de valor leem esse formato. Trocar isso é outro trabalho.

Para acrescentar um idioma: incluir em `Idioma::LISTA`, `MESES` e `SEMANA`, criar `src/idiomas/<id>.php`, acrescentar o id em `tools/idiomas/gerar.php` e rodar o gerador, que aponta o que falta e cria o `i18n-<id>.js`. Texto novo: sempre com `t()`, `tn()` ou `T()`, com literal na primeira posição.

## 2026-11-01 — uso geral: nada individualizado na tela

O app é para qualquer pessoa, então as telas não podem supor a situação de quem o criou: tipo de empréstimo, profissão, folha de pagamento ou um casal. Texto novo fala de "parcela do empréstimo" e "salário", no singular ou de forma impessoal.

Ainda supõem um caso específico, e precisam de decisão antes do lançamento público:

- **Nomes internos:** `consignado_inicio`, `parcela_consignado` e afins não aparecem na tela. Renomear exige migração.
- **Repositório:** os exemplos em `docs/decisoes.md` e `tests/run.php` citam nomes e o endereço da casa.

## 2026-11-01 — aba Investimentos

Poupança e outros investimentos, com o saldo = valor de partida (Ajustes) + lançamentos do Extrato até hoje.

- Aplicar e resgatar são movimentações, como a poupança: saem da conta e entram no balanço, mas nunca contam como renda ou gasto. Por isso o grupo novo `Investimento` tem `operacional = 0`.
- **No plano, aplicar reduz o dinheiro disponível**, e o adiantamento automático do empréstimo daquele mês diminui junto.
- **Rendimento não entra:** a aba mostra o que foi aplicado, não o valor de mercado.
- A meta final continua sendo conta + poupança, sem os investimentos.
- As categorias ficam fixas por enquanto. O nome de cada aplicação vai na descrição do lançamento.

## 2026-11-01 — ícone do site

O ícone fica no banco, em `site_icone`, e vale para o site inteiro, porque a tela de login aparece antes de saber quem entrou. Sem ícone enviado, vale `public/assets/img/marca.svg`. Nenhuma imagem pessoal fica no código. O efeito de espiar por cima do cartão, no login, só aparece com ícone enviado.

## 2026-11-01 — qualquer pessoa, em qualquer dia

Antes, os meses eram criados uma vez, na instalação, e o app recusava lançamentos quando eles acabavam. O início era sempre o mês da instalação, e a data dele ficava escrita no texto do rótulo.

- **Meses sem fim:** `Horizonte` mantém sempre 24 meses depois do mês de hoje. Mês novo copia a previsão do anterior; contas pontuais, a do mesmo mês do ano passado; categoria com meta continua seguindo a meta; a fatura vem do Cartão. Nos meses que já existiam, o plano não muda.
- **Início numa data de verdade:** `ajustes.data_inicio` (AAAAMMDD), uma só para conta, poupança e investimentos. O saldo é o do início desse dia, e lançamentos valem a partir dele. Não dá para pôr o início depois de um lançamento ou de um mês fechado, nem no futuro.
- **Início no meio do mês:** nesse 1º mês, a meta conta só pelos dias que faltam. Na primeira configuração, salário e contas cujo dia já passou ficam fora da previsão daquele mês, porque já estão no saldo.
- **Meta com data própria:** `ajustes.meta_mes`, opcional, em vez de valer "até o último mês".
- **Primeira configuração no site:** `/comecar`. A instalação pelo terminal só cria categorias e usuários. Só o 1º passo é obrigatório.
- **Empréstimo e cartão opcionais:** `usa_emprestimo` e `usa_cartao` escondem a aba; sem empréstimo, o plano ignora a dívida.
- **Parcela:** `parcela_modo` 1 = descontada do salário, 2 = paga à parte. Nos dois casos a conta e a dívida dão o mesmo resultado; à parte, o salário não diminui, e no mês fechado vale a categoria "Parcela do empréstimo".
- O Anual mostra de um ano atrás em diante; o histórico inteiro fica no Mês.
- O plano continua sendo calculado desde o 1º mês a cada página. Um teste exige menos de 1 segundo com mais de 10 anos de meses.

## 2026-11-01 — aba Relatório no lugar da Anual

Passou por conselho (5 conselheiros, revisão anônima, síntese). A aba Anual saiu. O simulador "E se…?" e o gráfico do plano continuam em `/futuro`, agora chamado Simular, sem item de menu: abre pelo Relatório, pelo Mês, pela Wishlist e pelo Empréstimo. Um PDF não simula nada.

- **PDF próprio, em `src/Pdf`:** o servidor não tem GD, Imagick nem navegador, e o projeto não usa Composer. Manrope e Sora saem como TTF estático de `tools/pdf-fontes/gerar.py` e ficam commitadas. Caractere fora da fonte vira um ponto médio. Sem PDF marcado (PDF/UA): mal feito é pior que nada. Título, idioma e ToUnicode estão lá.
- **Juízo só em mês encerrado.** O plano do mês em andamento usa o maior entre lançado e planejado, então não dá para dizer que algo ficou abaixo.
- **Regras** em `RelatorioDados`: diferença relevante = ao menos 2% da renda e 10% do planejado; no máximo 2 itens por lista; entrada abaixo em 5%; 25% acima da média com 3 meses encerrados; conta pontual só se julga no anual. Regra sem base se cala. Nada de nota, 50/30/20 ou comparação com a média do país.
- **"Pode melhorar" é aritmética:** roda o motor com 10% a menos numa categoria não essencial e só mostra se muda algo.
- **Foto do plano (`plano_fotos`):** na primeira requisição de cada mês, sem depender de fechar, porque fechar tarde já conhece o resultado. Foto depois do dia 7 não entra no placar. Não dá para refazer meses passados.
- **Previsão em três camadas:** já contratado, estimado e cenários, sem leque de incerteza por falta de base. Os cenários usam "o que tem menos o que deve": na conta sozinha, gastar mais parecia melhor, porque o plano adianta menos o empréstimo.
- **Fatura aberta por categoria só para ler.** O plano continua contando a fatura, e o que não tem compra registrada aparece como "Fatura sem detalhe".
- **Nada de nome de usuário no PDF:** ele pode sair do app.

