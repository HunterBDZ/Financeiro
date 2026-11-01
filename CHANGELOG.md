# Versões

Cada versão publicada é uma tag `vX.Y.Z` deste repositório. Sites instalados atualizam com `sudo financeiro-atualizar`.

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
