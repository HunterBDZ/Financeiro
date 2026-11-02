# Versões

Cada versão publicada é uma tag `vX.Y.Z` deste repositório. Pacotes atualizam em Ajustes, em Backup e atualização; servidores, com `sudo financeiro-atualizar`.

## 1.11.2

- **Mês:** as contas fixas mostram só "Pago" em verde ou "Não pago" em vermelho, com o vencimento ao lado. Os gastos que variam mostram, em cores, se estão "Abaixo da meta", "Na meta" (de 90% a 100%) ou "Acima da meta". A comparação usa o valor do próprio mês, se ele tiver um. As barras com "x de y" saíram.
- **Criar categoria:** ao criar um gasto, escolha se o valor é fixo todo mês (uma conta, com valor e dia de vencimento) ou se varia, com uma meta opcional.
- **Cartão:** a fatura pode ser ordenada por maior ou menor valor, de A a Z ou por mais ou menos parcelas. Os parcelamentos, por quando acabam, parcelas que faltam, valor da parcela ou de A a Z.

## 1.11.1

- **Mês:** quando um mês tem valor próprio para uma categoria, a barra compara o que foi gasto com esse valor, e não com a meta de todo mês. Exemplo: "0,00 de 800,00" em novembro, mesmo com a meta de 1.000,00. Com valor zero e nada gasto, a barra some.

## 1.11.0

- **Verificação em duas etapas, opcional:** em Ajustes, cada pessoa pode ligar a verificação com um app autenticador. Depois da senha, o login pede o código de 6 dígitos ou um dos 10 códigos de recuperação. Aparelho lembrado entra sem o código.
- **Pacotes de Windows, Mac e Linux:** a tela Backup e atualização mostra a versão do programa que roda o Financeiro e avisa quando há um instalador novo para pôr por cima. Os dados ficam.

## 1.10.0

- **Início:** o cartão do saldo responde numa frase, por exemplo "Dá para gastar R$ 2.283,81 até o dia 30 sem passar da reserva". Os outros números ficam em "Ver detalhes".
- **A mesma sobra em todas as telas:** "Sobra até hoje", "Sobra prevista" ou "Sobra do mês", com o mesmo número no Início, no Mês, no Fechar e no Relatório. A sobra é o balanço da conta, e no Mês a linha "Empréstimo e poupança" mostra o caminho das listas até ela. Valores sempre com centavos.
- **Lançar:** as categorias mais usadas viram botões com ícone, com os gastos primeiro, e "Mais…" traz a lista completa com busca.
- **Extrato:** busca em todos os meses, num ano ou entre datas, com valor mínimo e máximo e o total no topo. O botão "Exportar planilha (CSV)" baixa um arquivo que o Excel e o LibreOffice abrem direto.
- **Cartão:** o pagamento vai para a fatura certa, mesmo quando é feito antes do mês do vencimento, e o plano não conta essa fatura de novo.
- **Mês:** uma conta ou entrada que veio menor que o previsto pergunta se aquele foi o valor final. Com um toque, a previsão do mês fica exata. O falso "pago em parte" deixou de aparecer.
- Os testes da atualização separam as regras, que rodam num banco de demonstração com data fixa, da conferência dos seus dados. Dia do mês, idioma ou dados diferentes não barram mais uma atualização.
- **Servidor e Docker:** a primeira conta pede o código de instalação que aparece no fim da instalação, ou no log do Docker. Assim, ninguém da rede cria a conta antes de você.
- **Servidor:** o `instalar.sh` usa SQLite e não instala mais MySQL. O `financeiro-atualizar` só instala versões assinadas por quem publica e, ao terminar, atualiza a si mesmo e o backup diário.
- **Docker:** aceita só o endereço `localhost` até você mudar o `compose.yaml`.
- **Ajustes:** link para o código-fonte, ao lado da versão.
- Regras de segurança do navegador mais restritas.
- Com um tema escolhido, o olho que esconde os valores e o botão de fechar avisos voltam a funcionar.
- Leitores de tela ouvem no seletor de mês e nas faturas do Cartão o mesmo texto que aparece na tela.

## 1.9.0

- **Windows:** instalador que não pede administrador nem usa terminal. O app inicia com a sessão, e o ícone abre o navegador. Ao desinstalar, os dados só saem se você escolher.
- **Mac com chip Apple:** arraste para Aplicativos e abra. Mac Intel não é atendido, porque o FrankenPHP não tem versão oficial para ele.
- O de Windows já foi instalado num computador de verdade; o de Mac ainda não passou por nenhum Mac. Use com cuidado e guarde um backup.
- **Usar no celular:** instruções de firewall mais completas para Windows e Mac.
- Os testes não barram mais a atualização no primeiro mês de uma conta nova, quando esse mês é parcial.
- Pastas com espaço no caminho funcionam também no pacote de Linux.

## 1.8.2

- Os testes não barram mais a atualização de um site com arquivos próprios em `database/data`, pasta que fica fora do repositório.

## 1.8.1

- Dois testes dependiam da data e barravam a atualização: um no começo do mês, sem lançamentos, e outro a partir do último dia do mês. Conferidos agora em várias datas.

## 1.8.0

- **SQLite:** o app funciona sem servidor de banco. Sites em MySQL continuam como estão até migrar.
- **Pacote para Linux:** instala sem `sudo` e sem banco de dados, em qualquer distribuição. Há também imagem Docker.
- **Backup na tela:** uma cópia por dia, na primeira vez que o app abre, além de fazer backup, baixar uma cópia e restaurar em Ajustes.
- **Atualização na tela:** procura versão nova só quando você pede, confere a assinatura, ensaia numa cópia dos dados e volta sozinha se falhar.
- **Usar no celular:** desligado por padrão. Ao ligar, a tela mostra um código para ler com a câmera e o que fazer se o firewall bloquear.
- **Administração:** só a primeira conta criada faz backup, restaura, atualiza e liga a rede. No computador da pessoa, só nele, e não pelo celular.
- **Mês:** sem escolher o mês, a tela abre no mês do calendário, mesmo que ele tenha sido fechado antes do fim.
- **Reabrir um mês** agora desfaz também o ajuste que o fechamento aplicou no mês seguinte, menos nos valores mudados depois.
- **Ícones novos:** os ícones de categoria agora são do Noto Emoji, de licença livre, e têm outra aparência.
- **Moeda e fuso:** cada site escolhe os seus em Ajustes, e as categorias de fábrica aparecem no idioma da conta.
- **Navegação:** botão Menu, Simular no menu, categorias em Ajustes e Fechar mais fácil de achar. As mensagens de erro agora são frases completas.
- **Licença:** o projeto agora é distribuído sob a AGPL-3.0-or-later, com avisos de terceiros, política de segurança e guia de contribuição.
- **Antes de atualizar:** sites abaixo da 1.5.0 precisam passar antes pela 1.7.0, porque as migrações antigas saíram do repositório.
- O importador do MySQL explica o erro quando o banco não tem as tabelas do Financeiro.

## 1.7.0

- **Valor como se fala:** em Lançar, `45` agora é R$ 45,00, e não R$ 0,45. Centavos vão depois da vírgula: `45,90`.
- **Número ambíguo não é adivinhado:** `1,500` é recusado em português e em espanhol, em vez de virar R$ 1,50. Em inglês, ele é mil e quinhentos, e `12.50` é aceito.
- **O que fazer com a sobra:** agora é uma escolha, em Ajustes e na primeira configuração: deixar na conta ou adiantar o empréstimo. Instalações novas começam deixando na conta; quem já usa o site continua como está. Quem adianta vê também como a conta ficaria sem isso.
- **Números explicados:** o que era "Em conta" em Metas virou "Sobra depois dos gastos", e Fechar mostra a conta entre a sobra e o balanço.
- **Aba Ajuda:** perguntas em 3 idiomas, com busca, e um "?" ao lado dos termos principais.
- **Primeiros passos:** uma lista na tela inicial para quem está começando.
- **Esqueci a senha:** outra pessoa com conta redefine em Ajustes → Pessoas; sem outra pessoa, há um comando no manual.
- **Fechar o mês** agora pede confirmação.
- Os testes não falham mais em contas em inglês ou espanhol, nem com o mês atual já fechado, o que bloqueava a atualização desses sites.

## 1.6.0

- **Instalação com um comando:** `sudo bash instalar.sh` instala tudo sem fazer perguntas. Se parar no meio, é só rodar de novo.
- **Primeira conta no navegador:** o site pede a conta na primeira visita, sem usar o terminal.
- **Ajustes → Pessoas:** adicione outras pessoas pelo site.
- Os testes não falham mais em sites sem cartão ou sem empréstimo, o que bloqueava a atualização deles.

## 1.5.1

- O atalho claro/escuro não desfaz mais o tema escolhido: troca entre as duas versões do mesmo tema. Tema de um modo só mostra o nome dele, que leva à tela Temas.

## 1.5.0

- **Aba Relatório no lugar da Anual:** escolha mensal ou anual, veja o resumo na tela e baixe o PDF, com gráficos, o que foi bom, o que foi ruim, o que pode melhorar e previsões.
- **Escada do saldo:** do valor inicial ao final do período, fechando no centavo.
- **Placar do planejamento:** o app guarda o que o plano dizia no começo de cada mês e compara com o que aconteceu. Começa no mês em que esta versão for instalada, se for na primeira semana; senão, no mês seguinte.
- **Previsões em três camadas:** parcelas já contratadas, estimado e cenários.
- Mês em andamento não recebe veredito, e regra sem histórico suficiente não aparece.
- **Simular:** o gráfico do plano e o "E se…?" ficam em `/futuro`, sem item de menu. Abrem pelo Relatório, pelo Mês, pela Wishlist e pelo Empréstimo.
- O PDF é gerado pelo próprio app, sem dependências novas no servidor.
- Migração nova: tabela `plano_fotos`, aplicada pelo atualizador.

## 1.4.0

- **O plano não acaba mais:** o app mantém sempre 24 meses à frente, sozinho. Antes, os lançamentos eram recusados quando os meses criados na instalação acabavam.
- **Início numa data de verdade:** um campo em Ajustes, igual para conta, poupança e investimentos. A meta passa a ter data própria e opcional.
- **Primeira configuração no site:** data de início, saldos, salário, contas fixas e o que você usa. A instalação pelo terminal só cria categorias e usuários.
- **Início no meio do mês:** o que venceu antes da data não entra na previsão daquele mês, e a meta conta só pelos dias que faltam.
- **Empréstimo e cartão opcionais:** a aba de quem não usa fica escondida. A parcela do empréstimo pode vir descontada do salário ou ser paga à parte.
- O Anual mostra de um ano atrás em diante; o histórico completo fica no Mês.
- Sem reserva definida, as telas não falam mais em "acima da reserva"; sem meta, não dizem que ela foi batida.
- Sites já instalados continuam como estão: o início é o 1º mês que já existe, a meta fica no último mês de hoje, e a parcela continua descontada do salário.
- Migração nova: ajustes e a categoria "Parcela do empréstimo", aplicados pelo atualizador.

## 1.3.1

- **Ícone do site:** em Ajustes, dá para enviar a própria imagem. Sem nenhuma, vale uma imagem neutra. Vale para todas as contas do site.
- Cartão, no computador: a fatura não fica mais separada por um espaço quando a lista de parcelamentos é longa.
- Migração nova: tabela `site_icone`, aplicada pelo atualizador.

## 1.3.0

- Aba **Investimentos**: poupança e outros investimentos, com o saldo calculado do valor de partida e dos lançamentos do Extrato. Aplicar e resgatar não contam como renda nem gasto. Rendimentos não entram.
- **Idiomas:** português, inglês e espanhol, escolhidos em Ajustes ou no login e salvos na conta. Sem escolha, segue o navegador. Valores continuam em reais e no formato brasileiro.
- Textos gerais: as telas falam em "parcela do empréstimo" e "salário", sem supor um tipo de empréstimo ou de profissão.
- Migrações novas: `usuarios.idioma`, o grupo `Investimento` com duas categorias e o valor de partida `investimentos_ini`, aplicadas pelo atualizador.

## 1.2.1

Textos e telas mais claros, sem mudança nos cálculos nem no banco.

- Início e Mês mostram a previsão para o fim do mês, a mesma do Anual.
- Mês: "Entradas previstas", com o desconto no salário à vista. Categorias zeradas e sem meta ficam recolhidas em "Sem movimento".
- Metas: "Renda do mês" virou "Disponível no mês", que é o que o número sempre foi: entradas + sobra anterior.
- Anual: a frase da reserva não diz mais "acima" quando o mês fica exatamente na reserva. A tela passa a se chamar Anual em todo lugar.
- Cartão: "6 a pagar, contando a aberta" no lugar de "faltam 6".
- Menos repetição: saem o selo "Acima da reserva" do Início, Entradas/Saídas do card do Mês, subtítulos iguais ao nome e o aviso antigo em Ajustes. "Últimos lançamentos" mostra 3.

## 1.2.0

- Tela **Temas**, com 32 temas: consagrados, retrô, brasileiros e de acessibilidade. Cada card mostra a prévia, e tocar nele mostra o tema no app inteiro antes de salvar.
- O tema fica salvo por conta e acompanha a pessoa em qualquer aparelho. A opção Automático segue o claro ou escuro do aparelho.
- O botão rápido de claro e escuro continua e, ao ser usado, troca o tema escolhido por Claro ou Escuro.
- Migração nova: coluna `usuarios.tema`, aplicada pelo atualizador.

## 1.1.0

- Aba **Wishlist**: lista em ordem, com link, foto e valor. O link preenche nome, valor e foto quando a loja permite.
- Melhor mês para comprar o 1º item, à vista ou parcelado, com o custo de comprar antes.
- Valores em 13 moedas, convertidos para reais pela cotação do dia.
- **Requisito novo:** as extensões `php-curl` e `php-xml`, para ler os links e buscar a cotação. Sem elas, o resto continua funcionando e o item é preenchido à mão.
- O servidor passa a acessar a internet para ler os links colados e buscar a cotação.

## 1.0.1

- Telas e textos do empréstimo genéricos, sem nome de instituição.
- Correção no atualizador: a checagem do backup acusava falha em bancos maiores. **Quem já instalou o comando** precisa repetir o `sudo install` depois de `git pull`.
- Melhorias no README e no manual.

## 1.0.0

Primeira versão numerada.

- Telas Início, Extrato, Mês, Cartão, Anual, Empréstimo, Metas e Ajustes.
- Simulação e registro de adiantamentos do empréstimo, com saldo devedor informado.
- Instalador (`database/instalar.php`) com modo de demonstração.
- Atualizador (`financeiro-atualizar`), com ensaio numa cópia do banco e volta automática.
