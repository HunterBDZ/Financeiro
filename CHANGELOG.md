# Versões

Cada versão publicada é uma tag `vX.Y.Z` deste repositório. Sites instalados atualizam com `sudo financeiro-atualizar`.

## 1.3.0

- Aba **Investimentos**: poupança e outros investimentos, com o saldo calculado do valor de partida e dos lançamentos do Extrato. Aplicar e resgatar não contam como renda nem gasto. Rendimentos não entram.
- **Idiomas:** português, inglês e espanhol, escolhidos em Ajustes ou no login e salvos na conta. Sem escolha, segue o navegador. Valores continuam em reais e no formato brasileiro.
- Textos gerais: as telas falam em "parcela do empréstimo" e "salário", sem supor um tipo de empréstimo ou de profissão.
- Migrações novas: `usuarios.idioma`, o grupo `Investimento` com duas categorias e o valor de partida `investimentos_ini`, aplicadas pelo atualizador.

## 1.2.1

Textos e telas mais claros, sem mudança nos cálculos nem no banco.

- Início e Mês mostram a previsão para o fim do mês, a mesma do Anual.
- Mês: "Entradas previstas", com o desconto em folha à vista. Categorias zeradas e sem meta ficam recolhidas em "Sem movimento".
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
