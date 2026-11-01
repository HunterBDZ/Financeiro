<div align="center">

<img src="public/assets/img/marca.svg" alt="" width="96">

# Financeiro

**Controle o dinheiro da sua casa no seu próprio servidor: lance gastos em segundos, veja como o mês vai fechar e planeje compras, dívidas e investimentos.**

Sem assinatura, sem nuvem e sem pedir a senha do seu banco.

[![Versão 1.7.0](https://img.shields.io/badge/vers%C3%A3o-1.7.0-blue)](CHANGELOG.md) ![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777BB4?logo=php&logoColor=white) ![MySQL 8](https://img.shields.io/badge/MySQL-8-4479A1?logo=mysql&logoColor=white) ![Sem dependências](https://img.shields.io/badge/depend%C3%AAncias-nenhuma-success)

[Diferenciais](#diferenciais) · [Telas](#telas) · [Como funciona](#como-funciona) · [Instalar](#instalar) · [Atualizar](#atualizar) · [Documentação](#documentação)

<br>

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/img/pc-inicio-escuro.jpg">
  <img src="docs/img/pc-inicio-claro.jpg" alt="Tela inicial: saldo em conta, resumo do mês, últimos lançamentos e para onde foi o dinheiro" width="860">
</picture>

<sub>Todos os prints usam dados fictícios do modo de demonstração.</sub>

</div>

## Diferenciais

### Veja o futuro da sua conta

Mês a mês, veja quanto terá na conta e teste "e se" sem salvar nada.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/img/pc-futuro-escuro.jpg">
  <img src="docs/img/pc-futuro-claro.jpg" alt="Gráfico da conta nos próximos meses, com a reserva e a meta" width="860">
</picture>

### Saiba o melhor mês para comprar

Cole o link do que quer comprar, em qualquer moeda, e o app indica quando a compra cabe no seu plano.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/img/pc-wishlist-escuro.jpg">
  <img src="docs/img/pc-wishlist-claro.jpg" alt="Wishlist com o primeiro item e o melhor mês para comprar" width="860">
</picture>

### Descubra se vale adiantar o empréstimo

Digite um valor e veja quanto falta, quando termina e quanto de juros você deixa de pagar.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/img/pc-emprestimo-escuro.jpg">
  <img src="docs/img/pc-emprestimo-claro.jpg" alt="Simulação de adiantamento do empréstimo" width="860">
</picture>

### Saiba quanto vem nas próximas faturas

Lance compras parceladas e veja o valor de cada fatura antes de ela chegar.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/img/pc-cartao-escuro.jpg">
  <img src="docs/img/pc-cartao-claro.jpg" alt="Fatura do cartão, próximas faturas e parcelamentos" width="860">
</picture>

### Receba um relatório que explica seu mês

Baixe em PDF, por mês ou por ano, com gráficos, o que foi bom, o que foi ruim e o que pode melhorar.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/img/pc-relatorio-escuro.jpg">
  <img src="docs/img/pc-relatorio-claro.jpg" alt="Relatório do mês com a escada do saldo e o botão de baixar o PDF" width="860">
</picture>

### Escolha a cara do seu app

São 32 temas com prévia, incluindo opções de alto contraste e para daltonismo.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/img/pc-temas-escuro.jpg">
  <img src="docs/img/pc-temas-claro.jpg" alt="Tela de temas com cards de prévia" width="860">
</picture>

## Telas

Funciona no computador e no celular.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/img/pc-mes-escuro.jpg">
  <img src="docs/img/pc-mes-claro.jpg" alt="Tela do mês no computador, com entradas, contas e metas" width="860">
</picture>

<table>
  <tr>
    <td align="center"><img src="docs/img/celular-inicio-claro.jpg" alt="Tela inicial no celular" width="240"><br><b>Início</b></td>
    <td align="center"><img src="docs/img/celular-lancar-claro.jpg" alt="Lançar um gasto no celular" width="240"><br><b>Lançar</b></td>
    <td align="center"><img src="docs/img/celular-mes-claro.jpg" alt="Tela do mês no celular" width="240"><br><b>Mês</b></td>
  </tr>
</table>

## Como funciona

1. **Comece.** Crie a sua conta e informe a data de início e quanto você tem hoje.
2. **Lance.** Aperte **Lançar**, digite o valor e escolha a categoria.
3. **Acompanhe.** Abra **Mês** para ver como ele deve terminar.
4. **Feche.** No fim do mês, confira o saldo com o banco e feche o mês.

## Instalar

Em um computador com Ubuntu 24.04 ou mais novo, rode:

```sh
git clone git@github.com:HunterBDZ/Financeiro.git
sudo bash Financeiro/instalar.sh
```

Espere alguns minutos: nenhuma pergunta é feita. No fim, abra o endereço que aparecer e crie a sua conta no navegador.

Dúvidas? Veja o [manual de instalação](docs/INSTALACAO.md).

## Atualizar

Os sites não se atualizam sozinhos: o dono decide quando. Um comando baixa a última versão publicada, testa numa cópia do banco, faz backup e aplica. Se algo falhar, o site volta para a versão anterior.

```sh
sudo financeiro-atualizar --verificar   # há versão nova? o que muda?
sudo financeiro-atualizar               # atualiza
```

```mermaid
flowchart LR
    A["Versão publicada<br>(tag no GitHub)"] --> B["Baixa"]
    B --> C["Ensaia numa<br>cópia do banco"]
    C -->|"Falhou"| X["Site intacto"]
    C -->|"Passou"| D["Backup"]
    D --> E["Migrações e código"]
    E --> F["Confere o site"]
    F -->|"Falhou"| Y["Volta sozinho"]
    F -->|"Passou"| G["Site atualizado"]
```

As mudanças de cada versão ficam no [`CHANGELOG.md`](CHANGELOG.md).

## Documentação

- **[Manual de instalação](docs/INSTALACAO.md):** instalar, atualizar, restaurar e resolver problemas.
- **[Por dentro](docs/POR-DENTRO.md):** arquitetura, pastas, testes e acessibilidade.
- **[Segurança](docs/SEGURANCA.md):** como o app protege contas e dados.
- **[Como lançar uma versão](docs/LANCAR-VERSAO.md):** para quem desenvolve.
- **[Decisões e operação](docs/decisoes.md):** o porquê de cada regra.

## Status e licença

**Status:** em desenvolvimento ativo. As mudanças de cada versão estão no [`CHANGELOG.md`](CHANGELOG.md).

**Licença:** © 2026 [HunterBDZ](https://github.com/HunterBDZ). Todos os direitos reservados.

**Componentes de terceiros:** fontes Manrope e Sora sob licença OFL. Veja os créditos dos [temas](tools/temas/CREDITOS.md) e dos [ícones](tools/icones/CREDITOS.md).
