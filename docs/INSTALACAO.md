# Manual de instalação

## Instalar

Você precisa de um computador com **Ubuntu 24.04 ou mais novo**, que fique ligado e na sua rede. Nele, rode:

```sh
git clone git@github.com:HunterBDZ/Financeiro.git
sudo bash Financeiro/instalar.sh
```

Espere alguns minutos: nenhuma pergunta é feita. No fim, o instalador mostra o endereço do site.

1. Abra esse endereço no navegador, em qualquer aparelho da sua rede.
2. O navegador avisa que a conexão não é segura. Isso é esperado na primeira vez: clique em **Avançado** e depois em **Continuar**.
3. Crie a sua conta.
4. Informe a data de início e quanto você tem hoje. Depois, é só lançar.

Para dar acesso a outra pessoa, use **Ajustes → Pessoas**.

### O que o instalador faz

- Instala Apache, PHP e MySQL, se faltarem. No Ubuntu 24.04, o PHP 8.4 ou mais novo vem do repositório `ppa:ondrej/php`.
- Coloca o site em `/var/www/financeiro`, cria o banco com uma senha aleatória e publica o site em HTTPS.
- Ativa o backup diário em `/var/backups/financeiro`, guardando 30 dias, e instala o comando `financeiro-atualizar`.

Ele não mexe no firewall. Se parar no meio, corrija o que ele apontar e rode o mesmo comando de novo. Rodar de novo num site pronto não muda nada. O registro completo fica em `/var/log/financeiro-instalacao.log`.

> [!CAUTION]
> O app foi feito para a rede de casa. Para acessar de fora, use uma VPN, sem abrir portas no roteador.

### Dica: mantenha o mesmo endereço

Reserve o IP do servidor no roteador. Se o IP mudar, o site continua funcionando no endereço novo, mas o navegador volta a mostrar o aviso de conexão.

## Atualizar para uma versão nova

Um comando faz tudo: baixa a última versão publicada, testa numa cópia do seu banco, faz backup, aplica as mudanças e confere o site. Se falhar antes de mexer no site, nada muda. Se falhar depois, ele volta o código e o banco sozinho.

**Para atualizar:**

```sh
sudo financeiro-atualizar --verificar   # mostra se há versão nova e o que muda
sudo financeiro-atualizar               # atualiza, depois de confirmar
```

✅ A última linha do resultado traz o backup, e **Ajustes** mostra a versão nova no fim da tela.

O próprio comando não se atualiza: se uma versão mudar `tools/financeiro-atualizar.sh`, o `CHANGELOG.md` avisa como instalar o novo.

## Restaurar um backup

Isto substitui os dados atuais pelos do backup:

```sh
sudo sh -c 'gunzip -c /var/backups/financeiro/ARQUIVO.sql.gz | mysql financeiro'
```

## Esqueci a senha

Outra pessoa com conta pode definir uma senha nova em **Ajustes → Pessoas**. Se não houver outra pessoa, gere uma senha nova no servidor, trocando `LOGIN`:

```sh
sudo -u www-data php /var/www/financeiro/tools/redefinir-senha.php LOGIN
```

## Quando algo dá errado

| O que aparece | Causa provável | O que fazer |
|---|---|---|
| `as portas 80 ou 443 já estão em uso` | Outro programa usa as portas do site | Pare esse programa e rode o instalador de novo |
| `não consegui entrar no MySQL` | O MySQL já tinha senha de root | Entre em contato com quem mantém o projeto |
| `Erro interno. Tente novamente` | O app não conectou no banco | Confira `DB_*` em `config/.env` e veja `sudo tail /var/log/apache2/financeiro-error.log` |
| `Class "Pdo\Mysql" not found` | PHP anterior a 8.4 | Atualize o PHP |
| `Call to undefined function mb_…` | Falta a extensão `mbstring` | `sudo apt install php-mbstring` e recarregue o Apache |
| `could not find driver` | Falta a extensão `pdo_mysql` | `sudo apt install php-mysql` e recarregue o Apache |
| Só a página inicial abre; as outras dão 404 | `mod_rewrite` ou `AllowOverride` | Rode o instalador de novo |
| Página padrão do Apache | O site padrão continua ativo | `sudo a2dissite 000-default && sudo systemctl reload apache2` |
| `Arquivo de configuração não encontrado` | Falta o `.env`, ou o dono está errado | Rode o instalador de novo |
| `O banco está sem as tabelas` | O `schema.sql` não foi importado | Rode o instalador de novo |
| `outra atualização está rodando` | Dois comandos ao mesmo tempo | Espere o outro terminar |
| `os testes da versão … falharam no ensaio` | A versão nova não funciona no seu ambiente | O site não mudou. Não insista e avise quem mantém o projeto |
| Bloqueado no login | 5 tentativas erradas | Espere 10 minutos |
| `Unknown collation: utf8mb4_0900_ai_ci` | O banco não é MySQL 8 | Use MySQL 8 |
| Aviso de JIT do PCRE no log | O kernel bloqueia memória JIT | Coloque `pcre.jit=0` no `php.ini` e recarregue o Apache |

## Personalizar

- **Fuso horário:** o app usa o horário de Brasília (`src/bootstrap.php` e `src/Database.php`).
- **Categorias:** crie novas no Extrato. Não renomeie as de empréstimo e poupança, `Salário` e `Fatura do cartão`: o cálculo as encontra pelo nome.
