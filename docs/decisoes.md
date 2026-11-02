# Decisões

As regras do app e o porquê de cada uma. Para instalar e operar, veja o [manual](INSTALACAO.md).

## Princípios

- **Uso geral:** as telas não supõem tipo de empréstimo, profissão, folha de pagamento nem quantas pessoas usam o site. Texto novo fala de "parcela do empréstimo" e "salário", no singular ou de forma impessoal.
- **Sem nome de instituição financeira:** nada disso em telas, código, documentos, prints ou commits. Nas telas, use "a instituição" ou "o extrato". Um teste compara cada palavra do projeto com hashes guardados. O nome dado a um cartão ou digitado num lançamento é escolha de quem usa, e o app não altera.
- **Rótulos antes de cortes:** o maior problema de uma tela costuma ser o rótulo que leva à leitura errada, não o excesso. "Acima da reserva" parece dinheiro livre, mas o plano já destinou esse valor. Por isso a previsão do fim do mês aparece no Início e no Mês, vinda de `fica_na_conta`, e um teste confere que é o mesmo número.

## Banco e migrações

- **SQLite é o destino; o MySQL fica só na transição.** `DB_DRIVER=mysql|sqlite` escolhe o banco, com `mysql` como padrão quando a chave não existe, para o site atual seguir igual ao atualizar. Instalações novas nascem em SQLite. O uso típico é uma família numa rede de casa: um arquivo só simplifica instalar, copiar e atualizar.
- **O dialeto mora em poucos lugares:** `src/Sql.php`, `src/SqliteConexao.php` e `src/SqliteConsulta.php`. Models e Services montam o SQL com os pedaços de `Sql` e não perguntam qual é o banco.
- **Como tirar o MySQL, depois que a produção migrar:** (1) deixar em cada método de `Sql` só o ramo SQLite e retirar `Sql::sqlite()`; (2) em `Database`, ficar só com `conectarSqlite`; (3) apagar `database/schema.sql`, `tools/importar-mysql.php`, `tools/comparar-bancos.php`, `instalar.sh`, `tools/financeiro-atualizar.sh`, `tools/financeiro-backup.sh` e o importador `CartaoModel::importarAntigas`; (4) tirar de `tests/run.php` os ramos de `Sql::sqlite()` e do CI o banco MySQL; (5) `FOR UPDATE`, `Sql::fusoDaConexao` e `Sql::travandoLinhas` somem junto.
- **`database/schema.sqlite.sql` é a fonte de SQLite.** Mudança de banco entra nos dois schemas enquanto os dois existirem. As tabelas são `STRICT`, para recusar tipo errado como o MySQL em modo estrito.
- **Dinheiro no SQLite:** fica em `REAL`, porque o motor já calcula em `float`. Os gatilhos `dec_` arredondam ao gravar como uma coluna `DECIMAL`, meio para longe do zero sobre o número escrito. O `SUM` é substituído por um que soma em unidades inteiras. O resultado é o mesmo `float` que o PHP tiraria do `DECIMAL` do MySQL.
- **Dinheiro continua em `REAL` na 2.0, e não em centavos:** converter mexeria em todas as tabelas e em todo o código, para pouco ganho, porque os gatilhos `dec_` já guardam 2 casas. No lugar disso, somas com `round(…, 2)` na borda e comparações com tolerância de centavos.
- **Datas e horas no SQLite:** texto, no fuso do site na hora de gravar. `CHECK` recusa data inválida. Diferença conhecida: no MySQL, uma coluna `TIMESTAMP` muda de hora quando o fuso do site muda, e no SQLite ela guarda a hora em que foi gravada. Só aparece se o fuso for trocado depois de haver dados.
- **Texto sem acento e sem caixa:** `src/Colacao.php` imita `utf8mb4_0900_ai_ci`, com tabelas tiradas do próprio MySQL, e é usada na colação `AI_CI` e no `LIKE`. O banco só abre pela conexão do app: outros programas leem as tabelas, mas não ordenam nem gravam nessas colunas.
- **Transações no SQLite:** `beginTransaction` usa `BEGIN IMMEDIATE`, e toda leitura vai para a memória ao executar, sem cursor aberto. Assim a espera de 15 s do `busy_timeout` vale sempre, e o erro de banco ocupado não aparece no meio de uma transação. A trava de senha é um arquivo, com `flock`, porque segurar uma transação durante o bcrypt pararia as outras escritas.
- **Empates de ORDER BY têm desempate fixo:** cada banco resolvia um empate de um jeito, e o comparador apontava. Só a lista de sugestões de descrição e a ordem das categorias de mesmo valor podem mudar, sem efeito em nenhum número.
- **`database/schema.sql` é o ponto de partida** de instalações novas. É gerado com `mysqldump --no-data --skip-comments --skip-dump-date --skip-add-drop-table --routines --triggers`, `sed -E 's/ AUTO_INCREMENT=[0-9]+//'`, para contadores não entrarem no diff, e sem `DEFINER`, para qualquer usuário importar a view. Sem `DROP TABLE`, de propósito.
- **Migrações:** a tabela `migracoes` registra o que rodou em cada site, e `instalar.php` marca como aplicadas as que o `schema.sql` já contém. A pasta `database/migrations` pode ficar sem nenhuma migração, mas precisa existir, porque o atualizador procura nela. Linhas de migrações antigas na tabela não atrapalham.
- **Ponto de partida do repositório:** o `schema.sql` atual contém todas as migrações até a versão 1.7.0. Um site anterior à 1.5.0 não sobe direto para uma versão sem essas migrações: o ensaio do atualizador falha, e o site fica como está.
- **Código e banco andam juntos:** para voltar uma versão, restaure também o dump do mesmo momento. O código antigo sobre dados migrados dá uma previsão errada, sem erro aparente.
- **Tabela antiga do cartão:** a `cartao_compras_v1` não nasce mais em instalação nova. Em site antigo, o importador traz as compras dela uma vez, quando o cartão é cadastrado (total = parcela × nº de parcelas; 1ª fatura = mês da 1ª parcela; sem categoria). Apagar a tabela é uma decisão de cada site, depois de conferir a importação.
- **Categorias achadas pela chave:** desde a 2.0, `categorias.chave` (única) marca as especiais: `salario`, `credito_emprestimo`, `amortizacao`, `guardar`, `resgate_poupanca`, `parcela`, `aplicar`, `resgate_investimento` e `fatura`, nas constantes `MotorFinanceiro::CAT_*`. O motor, o Cartão e Investimentos acham por ela, então o nome pode mudar. A migração deu a chave pelo nome de fábrica; a da fatura foi a primeira ativa de gasto do grupo Cartão, a mesma que o Cartão usava. A tradução das de fábrica continua pelo nome (`nomeCategoria()`).
- **Migrações SQLite (`database/migrations-sqlite`):** a `s0001_contas` é a da 2.0. O `.sql` muda a estrutura e o `.php` preenche os dados, só com a conexão que recebe, porque a restauração de um backup migra uma cópia aberta à parte. As tabelas que ganham coluna com `REFERENCES` e padrão diferente de NULL (`lancamentos`) ou que mudam um `CHECK` (`cartao_compras`) são recriadas com as chaves estrangeiras ligadas: nenhuma outra as referencia. O contador de ids continua de onde estava. A ordem das colunas é a do schema, porque a restauração compara as duas, e um teste confere que o banco migrado tem a mesma estrutura de um banco novo, partindo do schema da 1.10 guardado em `tests/dados`.
- **`tools/importar-mysql.php` não acompanha a 2.0:** ele marca as migrações como feitas sobre o schema de agora e não cria a Conta principal nem os vínculos. Sai junto com o MySQL; quem ainda está em MySQL migra com a 1.10 e atualiza depois.
- **Nomes internos:** `consignado_inicio` é uma chave gravada em `ajustes`. Trocar exige migração.

## Versões e atualização

- **A tag `vX.Y.Z` é o que chega aos sites.** O `main` sem tag não chega, e `VERSION` precisa coincidir com a tag. Processo em [`LANCAR-VERSAO.md`](LANCAR-VERSAO.md).
- **`financeiro-atualizar`:** ensaia migrações e testes numa cópia do banco, com o site intacto. Só então faz o backup, migra e copia. Se falhar depois disso, volta o código e o banco. Não há atualização automática, porque são dados financeiros.
- **Limite conhecido:** se a volta automática restaurar o banco, perde-se o que foi lançado nos segundos entre o backup e a falha. O site não entra em manutenção nesse intervalo.
- **`instalar.sh`:** não faz perguntas e só cuida do app, sem firewall. Os scripts instalados saem da mesma versão do site. O Apache não fixa IP, para o site sobreviver a uma troca de IP.
- **Primeira conta:** nasce em `/criar-conta`, que só existe enquanto o banco não tem conta. No modo servidor, quem chegar primeiro na rede cria: o risco é aceito porque o site é de rede local e essa janela é curta. No modo instalador, só o próprio computador cria (`Primeira::contaPermitida`), porque um notebook pode estar numa rede pública.
- **Dois modos:** com `config/.env`, é o modo servidor, o de sempre. Sem ele, ou com `FIN_DADOS`, é o modo instalador: `Primeira` cria sozinha a pasta de dados, a configuração e o banco, sem perguntar nada. A pasta fica fora de Documentos, para serviços de nuvem não mexerem no banco aberto. O comportamento do modo servidor não muda, salvo por `X-Forwarded-Proto`.
- **Acesso pela rede:** desligado por padrão e, quando ligado, em HTTP. No modo instalador, o `Host` precisa ser local; com `ACESSO_REDE=1`, também vale IP privado e o nome da máquina. Isso barra DNS rebinding. No modo servidor, só há conferência com `HOSTS_PERMITIDOS`.
- **`X-Forwarded-Proto`:** só vale com `CONFIAR_PROXY=1`, porque qualquer cliente pode mandar esse cabeçalho. Quem usa um proxy na frente do site precisa ligar essa chave ao atualizar. O Apache do `instalar.sh` não depende disso.
- **Windows e Mac:** `packaging/gerente.php` faz o papel do script de Linux, em PHP, para ser testado em qualquer sistema. Fica dentro de `app`, para acompanhar as atualizações; o primeiro arquivo de cada processo fica fora dela (`programa/gerente.php`, `atualizacao/lancar.php`), porque o Windows não troca uma pasta com arquivo aberto. Por isso também `Paths::renomear()` espera e tenta de novo.
- **Windows sem administrador:** instalador NSIS gerado em Linux e início por `HKCU\...\Run`. Atalho e início automático chamam `financeiro.exe` (`packaging/windows/lancador.c`), que inicia o `php.exe` com um console invisível. O `php-win.exe` não serve: sem console, cada programa que ele inicia abre uma janela preta. O `php.ini` e as extensões são relativos à pasta `bin`, a pasta atual de todo processo: com acento no caminho, o FrankenPHP não acha a extensão. O nome da pessoa ter acento é comum.
- **Mac:** o programa em uso mora na pasta de dados, e só o binário fica no `.app`. Assim, a atualização pela tela não quebra a assinatura do aplicativo. Um `.app` só substitui o programa se for mais novo.
- **`php -S` só para desenvolver:** atende uma página por vez. Use `tools/servir-dev.php`, que não serve arquivos com ponto.
- **Testes dos sites:** rodam no ensaio e depois de aplicar, sobre os dados da pessoa, e por isso têm duas partes. A conferência só confere o que vale para qualquer casa, em qualquer data e idioma, e não grava nada. As regras, que precisam de dados conhecidos, rodam num banco de demonstração descartável, com o relógio parado num dia comum e no último dia do mês. Testes que supunham o formato dos dados ou a data barraram cinco versões seguidas.
- **Relógio parado só na linha de comando:** `FIN_RELOGIO` vale nos testes e no `php -S`, nunca nas páginas servidas pelo Apache ou pelo FrankenPHP, para uma variável esquecida não pôr o site de alguém noutro dia. Backups, cookies, travas e a manutenção ficam na hora da máquina, porque contam tempo de verdade.

## Plano e previsão

- **Meta é a previsão padrão:** a meta de uma categoria de gasto vale em todo mês ainda em `PREVISAO`. Um valor gravado num mês é a exceção daquele mês, e "voltar à meta" apaga a linha. A definição única é a view `previsao_efetiva`. Quem lê previsão lê da view; quem grava usa só `PrevisaoLote`, com log e desfazer. Categoria arquivada não herda meta.
- **Mês fechado não muda:** ao fechar, `PrevisaoLote::congelar()` grava como linhas do mês a previsão que vinha da meta, num lote fora do "desfazer última alteração". Reabrir desfaz esse lote, e o mês volta a seguir as metas de hoje.
- **Metas e Mês continuam separados:** uma muda todos os meses, o outro muda um. Juntar arrisca alterar o plano inteiro sem querer.
- **Veio menos que o previsto:** no mês aberto, conta ou entrada com lançado abaixo do previsto (além de centavos) mostra "Veio X de Y previstos" e pergunta: "Foi o valor final" grava a previsão do mês igual ao que veio, só nesse mês, com desfazer; "Ainda falta pagar" (ou receber) não grava nada e diz quanto o plano continua contando. Só a pessoa sabe se é parcial ou mais barato, então o app não decide sozinho (antes, abaixo de 90% virava "pago em parte"). O salário com a parcela descontada compara sem a parcela. O dia a dia fica de fora: gastar menos que a meta durante o mês é o normal.
- **Tela Mês:** salvar, tirar deste mês ou voltar à meta, só no mês ou também nos seguintes. `+ incluir conta` põe uma conta sem previsão. O aviso mostra o efeito no fim do mês, o primeiro mês que fura a reserva ou fica no vermelho, e tem desfazer. Salvar uma meta é atômico.
- **Meses sem fim:** `Horizonte` mantém sempre 24 meses depois do mês de hoje. Mês novo copia a previsão do anterior; contas pontuais, a do mesmo mês do ano passado; categoria com meta continua seguindo a meta; a fatura vem do Cartão. Nos meses que já existem, o plano não muda.
- **Início numa data de verdade:** `ajustes.data_inicio` (AAAAMMDD), uma só para conta, poupança e investimentos. O saldo é o do início desse dia. O início não pode ficar depois de um lançamento ou de um mês fechado, nem no futuro. No 1º mês, a meta conta só pelos dias que faltam; na primeira configuração, salário e contas cujo dia já passou ficam fora da previsão daquele mês, porque já estão no saldo.
- **Meta com data própria:** `ajustes.meta_mes`, opcional. A meta final é conta + poupança, sem investimentos.
- **Primeira configuração no site:** `/comecar`. A instalação pelo terminal só cria categorias. Só o 1º passo é obrigatório. O que a pessoa usa vem antes das contas fixas, e a lista de contas inclui a fatura só para quem marcou cartão.
- **Recursos opcionais:** `usa_emprestimo` e `usa_cartao` escondem a aba; sem empréstimo, o plano ignora a dívida. Sem cartão, a fatura sai das contas fixas, sem apagar nada. "Tenho carro" desmarcado deixa as categorias de carro sem uso com `ativo = 0`, e elas voltam ao marcar, porque o nome é único mesmo em categoria inativa.
- **Desempenho:** o plano é calculado desde o 1º mês a cada página. Um teste exige menos de 1 segundo com mais de 10 anos de meses.

## Sobra, frase do saldo e centavos

- **Uma sobra, três nomes pela base:** a sobra é o balanço da conta, tudo o que entrou menos tudo o que saiu (`Services\Sobra`, sobre `MotorFinanceiro::balancoTotal`). Foi pedido do dono: "balanço é balanço" (ver Início). Crédito e resgates entram; adiantamento, poupança, investimentos e a parcela paga à parte saem. "Sobra até hoje" usa só o que foi lançado (o motor com o mês tratado como fechado); "Sobra prevista" é a linha do plano; num mês fechado, as duas coincidem e o nome é "Sobra do mês". Início, Mês, Fechar e Relatório usam a mesma classe. No Início, "Entrou" e "Saiu" são o balanço inteiro, e o detalhe mostra a linha "Empréstimo e poupança". No Mês, "Entradas" e "Saídas" batem com as listas, que são renda e gasto, e a linha "Empréstimo e poupança" mostra o resto do caminho até a sobra. A parcela descontada do salário não passa pela conta: aparece só na ponte "8.000 − 289,90 de parcela".
- **Ponte da renda bruta:** fora de mês fechado, o salário previsto é bruto. Quando há parcela (descontada ou paga à parte), a tela mostra a renda usada e a conta numa linha discreta, "8.000,00 − 289,90 de parcela".
- **Mês aberto na base do plano:** cada linha do dia a dia mostra o maior entre o lançado e o previsto (a regra do motor), e a barra mostra quanto já foi gasto. Assim as linhas somam Entradas e Saídas do resumo, e a sobra prevista bate com a previsão do fim do mês. Antes, o dia a dia contava só o gasto, e a "Diferença" do Mês não era nem até hoje nem prevista.
- **"Sobra" só para a sobra:** em Metas, o que fica do dinheiro do mês virou "Resta para usar"; no Fechar, o nível sugerido do mês seguinte virou "Para os não essenciais".
- **Frase do saldo no Início (`DashboardController::resposta`):** S = previsão do fim do mês sem adiantar o empréstimo (o plano com `objetivo` 3; sem empréstimo, a própria previsão), P = previsão com o plano, R = reserva. S < 0: "fecha no vermelho: faltam −S para zerar". S < R: "fecha abaixo da reserva: faltam R − S". Senão, "Dá para gastar S − R até o dia N", e, se o plano adianta o empréstimo (S − P > 0), a frase diz que esse valor vai para o empréstimo, que é por que a previsão fica igual à reserva. Nenhum número novo: S, P e R aparecem em "Ver detalhes". Olha só o mês da previsão; os meses seguintes ficam no Mês e no Simular.
- **Centavos:** dinheiro aparece com centavos em todas as telas, inclusive nos totais e nas frases, para o mesmo valor ter a mesma cara. Exceções deliberadas: o número grande do saldo (centavos menores), as marcas redondas dos eixos de gráfico, os valores "cerca de" (estimativas arredondadas de propósito) e o PDF, que tem desenho próprio para caber na folha. Campo de digitar não é exibição: segue `numInput()`.

## Contas e transferências

- **Uma conta é só onde o dinheiro está:** banco, banco digital, dinheiro vivo (`contas`). O plano continua sobre as categorias; cada lançamento diz de que conta saiu ou em que conta entrou (`lancamentos.conta_id`). A migração criou a "Conta principal" com o saldo inicial de antes, e tudo o que já havia ficou nela. O nome dela no banco fica em português, e a tela mostra no idioma de quem vê (`nomeConta()`).
- **Quem tem uma conta não vê nada novo:** o lançar, o Início, o Extrato e o Fechar só mostram contas com mais de uma ativa (`ContaModel::varias()`). Com uma conta e um cartão, as telas são as de antes; a diferença é Contas em Ajustes, "Novo cartão" no menu do Cartão, "Pagar fatura" na fatura fechada e o estorno na compra.
- **Saldo de uma conta:** saldo inicial + lançamentos com o sinal da categoria + transferências recebidas − enviadas. A soma das contas é o saldo do motor, porque as transferências se anulam e `ajustes.saldo_ini` é mantido igual à soma dos saldos iniciais sempre que uma conta é salva. O motor não mudou.
- **A soma inclui as contas arquivadas:** arquivar só vale com a conta zerada (contando os lançamentos agendados) e com outra ativa. Os lançamentos dela continuam no motor, então o saldo inicial dela tem de continuar na soma; senão o total do Início ficaria diferente da soma das contas pelo saldo inicial da arquivada. Com uma conta só, o "Saldo da conta no início" de Ajustes e da primeira configuração é o saldo inicial dela; com mais de uma, cada conta tem o seu, em Ajustes → Contas.
- **Transferência fora das categorias (`transferencias`):** não é renda nem gasto, não entra no plano nem nos totais do Extrato, e um teste confere que o plano não muda em nenhum mês. No Extrato, aparece com ↔ no dia dela, e com a conta filtrada leva o sinal de quem recebeu ou mandou.
- **Lançar com várias contas:** "Pagou com" (num gasto) ou "Recebeu em" (numa entrada), em botões: as contas e, num gasto, os cartões (`conta:id`, `cartao:id`). O padrão é o da última vez da pessoa. O modo Transferir pede de, para, valor e data. Sem JS, o CSS mostra os campos de cada modo.
- **Conferir com várias contas:** um campo por conta ativa no Início e no Fechar, com o saldo do app na data e a diferença enquanto se digita (`saldo_conferencias.conta_id`). A conferência do total (NULL) é a de quem tem uma conta, e a de todas as anteriores.

## Lançar e Extrato

- **Categoria em um toque:** as 8 mais usadas (frequência da pessoa nos últimos 90 dias, depois a da casa; sem uso, o dia a dia) viram botões com o ícone, gastos antes de entradas, e "Mais…" abre a lista completa, também com gastos antes de entradas. Tudo é um grupo de rádios só, então funciona sem JS e manda um valor só. O JS mostra a busca da lista, passa para o botão a escolha feita na lista e escreve o nome no "Mais…". O formulário volta ao `<select>` se alguém o usar: `lancar.js` aceita os dois.
- **Busca no Extrato:** o período é o mês da tela (padrão), o ano dele, todos os meses ou entre datas, com valor mínimo e máximo e quem lançou em "Mais filtros". Os filtros de antes continuam. Fora do mês, ou com filtro, o topo mostra quantos lançamentos e o total de gastos e de entradas. Os totais saem numa linha só de SQL, em qualquer período; a lista segue paginada.
- **Planilha (CSV):** o mesmo resultado, em `GET /lancamentos/planilha` (`Services\Planilha`), UTF-8 com BOM, `;` e vírgula decimal (em inglês, `,` e ponto), data AAAA-MM-DD, valor com sinal e sem milhar, texto que começa com `=`, `+`, `-` ou `@` com apóstrofo na frente (injeção de fórmula). Lida em lotes de 1.000 pela chave data + id, sem OFFSET, e até 20.000 linhas: acima disso, a tela pede um período menor. O SQL serve aos dois bancos sem pedaço de `Sql`: só comparações de data em texto e de valor.

## Simular

- **"E se…?" em `/futuro`:** simula economizar ou gastar todo mês, um gasto ou uma entrada num mês, e ligar ou desligar o adiantamento com a sobra. Nada é gravado: a simulação vai na URL, então dá para voltar, recarregar ou mandar o link.
- **Como calcula:** `MotorFinanceiro::calcular` recebe a opção `extra` (mês → valor, fora de mês fechado). Sem `adiantar`, usa `objetivo` 3. `FuturoController::simulacao` valida a URL: valor inválido é avisado e ignorado, e os meses precisam ser do plano, do atual em diante.
- **O que compara:** conta e dívida no fim, mês mais apertado, meses abaixo da reserva, quando o empréstimo acaba e juros até quitar. A dívida no fim entrou porque, sem ela, "sem adiantar" parece melhor do que é.
- **Gráfico:** o título diz a conclusão. Linha reta, um ponto por mês (fechado, aberto, previsto), previsão tracejada, faixas abaixo da reserva e de zero. No celular só ficam os pontos de hoje, do mês mais apertado e do fim, porque os outros embolam.
- **Ganho de adiantar:** `AnualResumo::ganho` compara com o mesmo plano sem adiantamento (`objetivo` 3) e continua só com as parcelas até zerar.
- **Tabela:** os números não se esticam em tela larga, a cor só aparece nas exceções, e a dívida quitada vira "—". O "mudou em relação ao mês anterior" é calculado a cada página, da previsão e dos lançamentos. As referências para gráfico e tabela foram IBCS, Stephen Few, Datawrapper, NN/g e GOV.UK.

## Empréstimo

- **Compara com "só as parcelas" (`objetivo` 3), não com o plano:** o plano pode adiantar a sobra de cada mês, e um adiantamento manual só trocaria de lugar com o automático.
- **Como calcula:** a opção `adiantar` (mês → valor, fora de mês fechado) soma à amortização lançada no mês. `EmprestimoSimulacao` compara os dois cenários e, depois do último mês do plano, segue só com parcela e juros. Valor acima da dívida vira a dívida.
- **Nada é gravado ao simular:** vai na URL, e `parcial=1` devolve só o resultado, que `emprestimo.js` troca enquanto se digita. O cálculo fica só no servidor.
- **Consolidar (`POST /emprestimo/adiantamento`):** grava o que foi pago como lançamento da Amortização extra, com desfazer. A data não pode ser futura. Igual a outro no mesmo dia pede confirmação. Simular não preenche "Valor pago"; só o "Já paguei" do resultado leva o valor.
- **Valor acima da dívida estimada:** é aceito com aviso, porque o pagamento aconteceu, mas o motor só abate até a dívida, e a conta prevista fica diferente do Extrato até o saldo ser conferido.
- **Um lugar só para cada dado:** juros, parcela, número de parcelas, 1ª parcela e crédito recebido são editados só na aba (`POST /emprestimo/dados`). Ajustes ignora essas chaves (`AjusteModel::DO_EMPRESTIMO`), e os dois usam a mesma validação. Parcela zero é recusada, porque zeraria a dívida.
- **Saldo devedor informado:** sem ele, o saldo é o valor presente da parcela, e baixar os juros aumentaria a dívida. `saldo_devedor` e `saldo_devedor_mes` (AAAAMM) dizem quanto o extrato cobra antes da parcela daquele mês, e a dívida passa a esse valor (`MotorFinanceiro::saldoInformado`). Adiantamentos de meses anteriores já estão no extrato; os do próprio mês ou depois abatem do saldo. Em branco, vale o cálculo. O mês tem de ser do plano e não pode ser futuro.
- **Parcela:** `parcela_modo` 1 = descontada do salário, 2 = paga à parte. Nos dois casos, a conta e a dívida dão o mesmo resultado. À parte, o salário não diminui, e no mês fechado vale a categoria "Parcela do empréstimo".
- **Validação própria:** `parseMoedaBr` descarta letras, então a tela confere o campo antes de chamá-la.

## Cartão

- **Regime de caixa:** "fatura de M" é a que vence no mês M. É o dinheiro que sai da conta, e é nesse mês que ela conta no plano, como a categoria "Fatura do cartão". A compra no cartão não conta de novo na categoria dela nem na meta.
- **Em que fatura cai:** compra no dia do fechamento ou depois vai para a fatura seguinte. Se o vencimento é no dia do fechamento ou antes, a fatura vence no mês depois do fechamento (`CartaoFatura::faturaDe`; `cartao.js` repete a conta na prévia).
- **Parcelas:** total ÷ n arredondado para baixo no centavo, com a sobra na 1ª. Não têm tabela: saem de `valor_total`, `parcelas` e `primeira_fatura`. Em compra antiga, a "parcela atual" k cai na fatura aberta, então a 1ª foi k − 1 faturas antes.
- **Ligar ao plano (`alimenta_plano`):** começa desligado, e antes de ligar a aba mostra a conferência de 6 faturas. Ligado, toda mudança no cartão reescreve a previsão da Fatura de cada mês em `PREVISAO`, da aberta em diante (`CartaoFatura::sincronizarPlano`), num lote fora do "desfazer" do Mês. Meses antes da aberta não mudam, porque a fatura já fechou. No Mês, a linha fica só para ler. Desligar devolve a edição ao Mês, e os valores ficam.
- **Lançar:** a categoria tem de ser um gasto operacional, e não a própria Fatura.
- **De que fatura é cada pagamento (`CartaoFatura::atribuir`):** um lançamento na categoria da Fatura vai para a fatura mais recente que já tinha fechado na data dele; se ela já está toda paga (com tolerância de centavos), para a seguinte, a aberta: é pagar adiantado. Parciais somam na mesma fatura, em ordem de data. Assim, pagar no dia 31 a fatura que vence no dia 2 conta para ela, e não para a do mês da data. O valor da fatura é o maior entre a soma das parcelas e a previsão dela; sem nenhum dos dois, basta um pagamento. Sem cartão cadastrado ou sem a aba, o pagamento fica no mês da data, como antes.
- **O vínculo fica gravado (2.0):** cada pagamento de fatura guarda o cartão e a fatura (`lancamentos.cartao_id` e `fatura`). "Pagar fatura", na fatura fechada ou vencida, grava os dois na conta escolhida (a do cartão por padrão). Pago pelo lançar, sem dizer a fatura, `atribuir()` preenche um pagamento por vez; com vários cartões, vai para o primeiro com uma fatura fechada ainda por pagar. A migração preencheu os antigos com a regra da 1.10, então nenhum número mudou. Editar um pagamento mantém o vínculo, salvo se a data mudar (a fatura é calculada de novo) ou se ele sair da categoria da fatura (o vínculo sai). Antes, a atribuição era refeita a cada página e podia mudar com a previsão; agora não muda.
- **Vários cartões:** a aba tem um seletor quando há mais de um, e "Novo cartão" fica no menu. A Fatura do cartão continua uma categoria só, que recebe os pagamentos de todos os cartões; ligados ao plano, a previsão dela é a soma das faturas deles, a partir da fatura aberta mais antiga entre eles. Com vários, o valor de cada fatura para saber se está paga é só a soma das parcelas, porque a previsão é a de todos juntos. Arquivar um cartão o tira da aba e do plano; as compras e os pagamentos ficam.
- **Estorno:** uma compra negativa, sempre em 1 parcela, na fatura da data dele (`CHECK (valor_total <> 0)` e estorno só à vista).
- **Onde isso vale:** no Cartão, paga = já fechou e o pago cobre a soma das parcelas; a parcial diz quanto falta. No plano (`MotorFinanceiro::previsaoDoMes`), a previsão da Fatura desconta o que já foi pago da fatura do mês, e no mês aberto ela vira o lançado mais o que falta, porque o lançado pode ser de outra fatura. Pagamento com data em mês futuro não desconta. No Mês, a Fatura usa o vencimento do cartão e o pago da fatura, e a paga antes do mês aparece como "Pago em 31/10". O Relatório mostra o pago da fatura do mês.

## Wishlist

- **Previsão (`WishlistPrevisao`):** roda o motor uma vez para cada mês de compra, com a opção `extra`, e olha o plano inteiro. Cabe quando a compra não deixa nenhum mês abaixo da reserva; mês que já estava abaixo só não pode piorar. O melhor mês é o 1º que cabe sem atrasar o empréstimo; "sem esperar" mostra o 1º que cabe, com os juros a mais. Parcelado sai da fatura do mês seguinte, sem juros; se alguma parcela passar do plano, o mês não é avaliado.
- **Fileira de meses:** cada mês tem símbolo e rótulo, não só cor. Separar "cabe" de "cabe atrasando o empréstimo" é porque essa é a decisão real.
- **Link (`LinkProduto`):** lê JSON-LD de produto, as etiquetas de compartilhamento e a marcação usual de páginas grandes. O endereço vem da pessoa, então vale a defesa contra SSRF do OWASP: só http/https nas portas 80 e 443, todo IP resolvido tem de ser público, a conexão vai para o IP conferido, cada redirecionamento é reavaliado, e há limite de tempo e de tamanho. Loja que bloqueia não quebra nada: o item é preenchido à mão.
- **Foto:** a CSP só permite imagem local. A foto fica em `wishlist_fotos`, no banco, para entrar nos backups e na volta do atualizador. Sem a extensão GD, o servidor confere pelo conteúdo (JPEG, PNG ou WebP, até 3 MB), e o navegador reduz a foto escolhida. SVG é recusado. Sai por `/wishlist/{id}/foto`, só com login.
- **Moedas (`Cotacao`):** o item guarda o valor na moeda escolhida, e a conversão é feita ao mostrar. A previsão e o "Comprei" usam o valor de hoje. A cotação é a comercial, guardada em `cotacoes` e renovada no máximo a cada 2 horas, com uma fonte reserva; se as duas falharem, vale a última guardada, com a data à vista. IOF e taxa do cartão não entram, e a tela avisa.
- **Ordem:** por botões, sem arrastar: funciona igual no celular e no teclado (WCAG 2.5.7).
- **Comprei e excluir:** têm desfazer. O item some de vez depois de 30 dias.

## Investimentos

- Aplicar e resgatar são movimentações: saem da conta e entram no balanço, mas nunca contam como renda ou gasto. Por isso o grupo `Investimento` tem `operacional = 0`.
- No plano, aplicar reduz o dinheiro disponível, e o adiantamento automático daquele mês diminui junto.
- Rendimento não entra: a aba mostra o que foi aplicado, não o valor de mercado. O nome de cada aplicação vai na descrição do lançamento.

## Relatório

- **PDF próprio, em `src/Pdf`:** o servidor pode não ter GD, Imagick nem navegador, e o projeto não usa Composer. Manrope e Sora saem como TTF estático de `tools/pdf-fontes/gerar.py`. Caractere fora da fonte vira um ponto médio. Sem PDF marcado (PDF/UA), porque mal feito é pior que nada; título, idioma e ToUnicode estão lá.
- **Juízo só em mês encerrado:** o plano do mês em andamento usa o maior entre lançado e planejado, então não dá para dizer que algo ficou abaixo.
- **Regras (`RelatorioDados`):** diferença relevante = ao menos 2% da renda e 10% do planejado; no máximo 2 itens por lista; entrada abaixo em 5%; 25% acima da média com 3 meses encerrados; conta pontual só se julga no anual. Regra sem base se cala. Nada de nota, 50/30/20 ou comparação com a média do país.
- **"Pode melhorar" é aritmética:** roda o motor com 10% a menos numa categoria não essencial e só mostra se muda algo.
- **Foto do plano (`plano_fotos`):** tirada na primeira requisição de cada mês, sem depender de fechar, porque fechar tarde já conhece o resultado. Foto depois do dia 7 não entra no placar. Meses passados não têm como ser refeitos.
- **Previsão em três camadas:** já contratado, estimado e cenários, sem leque de incerteza por falta de base. Os cenários usam "o que tem menos o que deve": na conta sozinha, gastar mais parecia melhor, porque o plano adianta menos o empréstimo.
- **Fatura aberta por categoria só para ler:** o que não tem compra registrada aparece como "Fatura sem detalhe".
- **Sem nome de usuário no PDF:** ele pode sair do app.

## Fechar o mês

- Mês terminado mostra um botão à vista; mês em andamento, o link "Fechar este mês".
- "Aplicar" vem desligado, a confirmação cita os dias que faltam e a mensagem diz o que mudou no mês seguinte.
- Reabrir também desfaz o ajuste que o fechamento aplicou no mês seguinte, menos nos valores que a pessoa mudou depois.
- **Início com o mês fechado antes do fim:** o resumo fica no mês do calendário, e só a previsão olha o próximo mês aberto.

## Telas e visual

- **Visual:** uma coisa em destaque por tela e cor só com função: verde = entrou; vermelho = saiu ou negativo; âmbar = atenção. Manrope no texto e Sora nos números grandes, auto-hospedadas.
- **Saídas em vermelho:** nos lançamentos e nos totais. Os valores por categoria do Mês ficam neutros, porque o vermelho ali quer dizer "passou da meta".
- **Olho do saldo (`[data-ocultar]`):** borra os números e vale só para a aba aberta (`sessionStorage`).
- **Menus:** a barra do celular tem 5 itens (Início, Extrato, +, Mês, Cartão), com o "+" no meio. O resto fica no botão Menu e no menu lateral. Simular tem item próprio.
- **Telas grandes:** a partir de 1600 px, o conteúdo cresce até 1560 px e as telas ganham colunas; a partir de 1280 px, Fechar e Ajustes ficam em 2 colunas.
- **Ajustes:** só Dinheiro abre sozinho. Os links antigos `?aberto=` continuam valendo. Categorias ficam em Ajustes, com o atalho "Criar categoria" no Lançar.
- **Aviso de conexão:** a explicação fica em `/ajuda/conexao`, que abre sem login, porque quem vê o aviso ainda não entrou.
- **Acessibilidade:** 44 px nos botões redondos e nos links curtos, e nenhum texto abaixo de 12 px (um teste varre o CSS).

## Temas

- **Tela `/temas`:** tocar num cartão mostra o tema no app inteiro, e ele só fica ao salvar; sem JS, o toque já salva.
- **Onde fica:** `usuarios.tema`, por conta; NULL é automático. Ele vem no HTML, então não pisca. O `localStorage` guarda só a cópia para a tela de login.
- **Como é feito:** `tools/temas/gerar.py` lê `temas.json`, confere o contraste e gera `temas.css` e `src/temas_lista.php`. Claro e Escuro ficam em `style.css`. Um teste mede o contraste de cada tema.
- **Atalho claro/escuro:** `Temas::lados()` diz os dois lados de cada tema, e o atalho alterna entre eles, para um toque não desfazer a escolha. Tema de um modo só mostra o nome, que leva à tela Temas.
- **Portátil:** entrada e saída têm a mesma cor, e só o sinal + e − separa. Créditos em `tools/temas/CREDITOS.md`.

## Ícones

- **Categorias:** `categorias.icone` guarda a chave do catálogo. NULL, ou chave fora do catálogo, vale a sugestão pelo nome e grupo (`Icones::sugerir`). Assim, uma categoria nova sempre tem ícone, sem dado novo.
- **Sprite:** `public/assets/img/icones.webp`, gerado por `tools/icones/gerar.py`, com células de 160 px e 8 px transparentes de respiro, porque vários emojis encostam na borda e vazariam para o vizinho ao reduzir. `Icones::html` desenha o emoji a 66% dentro de um círculo; no tema escuro, um contorno claro (`--ic-halo`) impede que emojis escuros sumam no fundo.
- **Licença:** Noto Emoji, Apache-2.0, de um commit fixo (`tools/icones/CREDITOS.md`).
- **Ícone do site:** fica em `site_icone`, no banco, e vale para o site inteiro, porque a tela de login aparece antes de saber quem entrou. Sem ícone enviado, vale `marca.svg`.

## Idiomas

- O texto em português é a própria chave (`t('Salvar')`), e o que não tem tradução aparece em português. Assim, um esquecimento não quebra a tela. Um teste falha se faltar tradução.
- A escolha fica na conta, como o tema, e num cookie, para valer na tela de login. Sem escolha, segue o navegador.
- **Não se traduz** o que a pessoa digitou. Categorias de fábrica são traduzidas só ao mostrar (`nomeCategoria()`), sem migração: o banco e as comparações do motor continuam em português. A busca do Extrato acha também pelo nome traduzido.
- **Itens brasileiros viram equivalentes:** IPVA é "Vehicle tax" ou "Impuesto del vehículo".
- **Para acrescentar um idioma:** incluir em `Idioma::LISTA`, `MESES` e `SEMANA`, criar `src/idiomas/<id>.php`, acrescentar o id em `tools/idiomas/gerar.php` e rodar o gerador. Texto novo: sempre com `t()`, `tn()` ou `T()`, com literal na primeira posição.
- **Revisão pendente:** falta um falante nativo revisar os termos em inglês e espanhol.

## Moeda e fuso

- **Moeda por site, sem conversão:** trocar a moeda muda o símbolo; 100 continua 100. Converter todo o histórico por uma cotação do dia inventaria valores. O formato do número segue o idioma, e tudo passa por `money()`, `num()`, `numInput()` e, no navegador, por `FIN`.
- **Guardados como número em `ajustes`,** que só tem coluna numérica: moeda pelo código da ISO 4217 e fuso pelo crc32 do nome. Um teste confere que o crc32 não repete.
- **Uma leitura só:** `Database::connection()` chama `Site::carregar()`, e um `SET time_zone` ajusta a conexão. O deslocamento vem do PHP a cada requisição, porque as tabelas de fuso do MySQL podem estar vazias. Efeito conhecido: um horário antigo em `TIMESTAMP` pode aparecer 1 hora diferente em outra estação.
- **Moedas sem centavos ficam com duas casas:** o app e o banco supõem duas.
- **Limite dos campos de valor:** 1 milhão em BRL, USD, EUR e GBP; 1 bilhão nas outras (`valorMaximo()`), porque um salário em algumas moedas passa de 1 milhão.
- **Cotação da Wishlist:** a fonte dá tudo em reais, e as outras moedas se cruzam pelo real. Sem cotação, a tela avisa.

## Backup, atualização e rede

- **Administrador:** a conta mais antiga, sem coluna nova no banco. Quem precisa de mais de um administrador ainda não apareceu.
- **Pelo celular não:** no computador da pessoa, baixar cópia, restaurar, atualizar e ligar a rede só valem em `127.0.0.1`. No celular a conexão é HTTP, e por ela não passa nem a cópia de tudo nem o arquivo que substitui tudo.
- **Restaurar troca linhas, não o arquivo:** numa transação, com o banco aberto por outras páginas. Ninguém vê metade.
- **Volta de atualização troca o arquivo:** o schema pode já ter mudado, e o site está em manutenção.
- **Assinatura, além do SHA-256:** um checksum no mesmo lugar do pacote só pega arquivo corrompido. A chave privada fica fora do repositório.
- **Consulta só quando a pessoa pede:** "sem nuvem" não combina com o app procurando versão por conta própria.
- **Rede com reinício:** ouvir em `127.0.0.1` até a pessoa ligar evita aviso de firewall em quem só usa no computador.
- **QR code próprio:** um gerador externo receberia o endereço da casa.
