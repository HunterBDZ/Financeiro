# Versões

Cada versão publicada é uma tag `vX.Y.Z` deste repositório. Sites instalados atualizam com `sudo financeiro-atualizar`.

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
