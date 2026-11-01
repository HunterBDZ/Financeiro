# Manual de instalação

Ao final, o Financeiro estará rodando em HTTPS na sua rede, com o banco criado, o primeiro usuário e backup diário. Reserve cerca de 30 minutos.

**Quer só conhecer o app?** Faça [o caminho rápido](#caminho-rápido-para-experimentar), que usa dados fictícios e leva uns 10 minutos.

## Antes de começar

| Você precisa de | Detalhe |
|---|---|
| Um servidor Linux na sua rede | Testado em Ubuntu 26.04. Os comandos usam `apt` |
| PHP **8.4 ou mais novo** | Testado em 8.5, com as extensões `pdo_mysql` e `mbstring`. O Ubuntu 24.04 traz o PHP 8.3, que não serve |
| MySQL **8** | Testado em 8.4. O banco usa a collation `utf8mb4_0900_ai_ci`; MariaDB não foi testado |
| Acesso ao repositório | Ele é privado: use uma conta com acesso e uma [chave SSH no GitHub](https://docs.github.com/pt/authentication/connecting-to-github-with-ssh) |
| Um IP fixo para o servidor | Reserve no roteador. Os exemplos usam `192.168.0.50`: troque pelo seu |

> [!CAUTION]
> Use só na rede de casa. O app guarda dados financeiros e não foi preparado para ficar exposto na internet. Para acessar de fora, use uma VPN, sem abrir portas no roteador.

## Instalação completa

### 1. Instale os pacotes

```sh
sudo apt update
sudo apt install apache2 mysql-server php libapache2-mod-php php-mysql php-mbstring git rsync openssl
php -v
```

✅ O `php -v` deve mostrar 8.4 ou mais. Se mostrar menos, pare aqui e atualize o sistema ou o PHP.

### 2. Baixe o código

```sh
git clone git@github.com:HunterBDZ/Financeiro.git ~/financeiro
sudo mkdir -p /var/www/financeiro
sudo rsync -a --exclude .git ~/financeiro/ /var/www/financeiro/
```

### 3. Crie o banco e o usuário do app

Escolha uma senha forte para o banco e troque `TROQUE_ESTA_SENHA` nos dois comandos:

```sh
sudo mysql -e "CREATE DATABASE financeiro CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
  CREATE USER 'financeiro_app'@'localhost' IDENTIFIED BY 'TROQUE_ESTA_SENHA';
  GRANT SELECT, INSERT, UPDATE, DELETE ON financeiro.* TO 'financeiro_app'@'localhost';"
sudo mysql financeiro < /var/www/financeiro/database/schema.sql
```

✅ `sudo mysql financeiro -e "SHOW TABLES;"` deve listar as tabelas, como `lancamentos` e `usuarios`.

### 4. Configure o app

```sh
cd /var/www/financeiro
sudo cp config/.env.example config/.env
sudo nano config/.env
```

Troque só o `DB_PASS` pela senha do passo 3 e salve. Depois, proteja o arquivo e ajuste o dono:

```sh
sudo chown -R www-data:www-data /var/www/financeiro
sudo chmod 640 config/.env
```

| Variável | O que é | Padrão |
|---|---|---|
| `DB_HOST`, `DB_NAME`, `DB_USER` | Onde está o banco | `localhost`, `financeiro`, `financeiro_app` |
| `DB_PASS` | Senha do banco | **precisa trocar** |
| `APP_ENV` | `production` esconde erros da tela | `production` |
| `SESSION_LIFETIME` | Segundos até a sessão expirar sem uso | `28800` (8 horas) |
| `MOTOR_V2` | `1`: a sobra só adianta o empréstimo se os meses seguintes continuarem acima da reserva | `1` |

### 5. Crie os usuários

```sh
sudo -u www-data php database/instalar.php
```

O instalador pergunta nome, login e senha (mínimo de 8 caracteres), e oferece um segundo usuário para quem divide as finanças com você. Ele também cria as categorias e os 16 meses do plano, a partir do mês atual.

✅ A última linha deve ser `Pronto. 23 categorias, 16 meses e …`.

### 6. Confira com os testes

```sh
sudo -u www-data php tests/run.php | tail -1
```

✅ Deve terminar com `0 falha(s)`. Os testes rodam dentro de uma transação desfeita no fim, então não alteram seus dados.

### 7. Publique com HTTPS

Gere o certificado, trocando o IP nas duas posições:

```sh
sudo openssl req -x509 -newkey rsa:2048 -nodes -days 825 \
  -keyout /etc/ssl/private/financeiro.key -out /etc/ssl/certs/financeiro.crt \
  -subj "/CN=192.168.0.50" -addext "subjectAltName=IP:192.168.0.50" \
  -addext "basicConstraints=critical,CA:FALSE"
sudo chmod 600 /etc/ssl/private/financeiro.key
```

Crie o site com `sudo nano /etc/apache2/sites-available/financeiro.conf`, trocando o IP nas duas linhas `ServerName` e no `Redirect`:

```apache
<VirtualHost *:80>
    ServerName 192.168.0.50
    Redirect permanent / https://192.168.0.50/
</VirtualHost>

<VirtualHost *:443>
    ServerName 192.168.0.50
    DocumentRoot /var/www/financeiro/public

    SSLEngine on
    SSLCertificateFile /etc/ssl/certs/financeiro.crt
    SSLCertificateKeyFile /etc/ssl/private/financeiro.key

    <Directory /var/www/financeiro/public>
        Options -Indexes -MultiViews
        AllowOverride All
        Require all granted
    </Directory>

    Header always set Strict-Transport-Security "max-age=31536000"

    ErrorLog ${APACHE_LOG_DIR}/financeiro-error.log
    CustomLog ${APACHE_LOG_DIR}/financeiro-access.log combined
</VirtualHost>
```

Ative:

```sh
sudo a2enmod rewrite ssl headers
sudo a2dissite 000-default
sudo a2ensite financeiro
sudo apache2ctl configtest
sudo systemctl reload apache2
```

✅ O `configtest` deve responder `Syntax OK`.

### 8. Libere só o necessário no firewall

```sh
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

### 9. Ative o backup diário

```sh
sudo install -m 755 ~/financeiro/tools/financeiro-backup.sh /usr/local/bin/financeiro-backup.sh
echo '30 3 * * * root /usr/local/bin/financeiro-backup.sh' | sudo tee /etc/cron.d/financeiro-backup
sudo /usr/local/bin/financeiro-backup.sh
sudo ls -l /var/backups/financeiro
```

✅ Deve aparecer um arquivo `financeiro-….sql.gz`. O backup fica no mesmo disco: copie para outro lugar de vez em quando.

### 10. Entre no app

Abra `https://192.168.0.50` no computador e no celular. O navegador avisa sobre o certificado na primeira vez, porque ele foi gerado por você: aceite o aviso só nos seus aparelhos.

Depois, nesta ordem:

1. **Ajustes:** informe o saldo e a poupança de partida, a reserva mínima, a meta e as contas recorrentes.
2. **Empréstimo → Dados do empréstimo:** se tiver um, informe parcela, juros, número de parcelas e o saldo devedor do extrato.
3. **Mês:** informe o salário e as contas previstas.
4. **Metas:** defina quanto quer gastar por categoria.
5. **Cartão:** cadastre o cartão, lance as compras parceladas e, se as faturas baterem, ligue-o ao plano.

Pronto: use o **+** para lançar.

## Caminho rápido para experimentar

Sobe uma cópia descartável, com dados fictícios, só neste computador. Use um banco separado do real.

```sh
sudo apt install mysql-server php php-mysql php-mbstring git
git clone git@github.com:HunterBDZ/Financeiro.git ~/financeiro-demo
cd ~/financeiro-demo

sudo mysql -e "CREATE DATABASE financeiro_demo CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
  CREATE USER 'financeiro_demo'@'localhost' IDENTIFIED BY 'demo1234';
  GRANT SELECT, INSERT, UPDATE, DELETE ON financeiro_demo.* TO 'financeiro_demo'@'localhost';"
sudo mysql financeiro_demo < database/schema.sql

cp config/.env.example config/.env
sed -i 's/^DB_NAME=.*/DB_NAME=financeiro_demo/; s/^DB_USER=.*/DB_USER=financeiro_demo/; s/^DB_PASS=.*/DB_PASS=demo1234/; s/^APP_ENV=.*/APP_ENV=development/' config/.env

php database/instalar.php --demo
php -S 127.0.0.1:8099 -t public
```

Abra http://127.0.0.1:8099 e entre com `ana` / `demo1234`. O servidor embutido do PHP serve só para testar: para uso real, faça a instalação completa.

## Atualizar para uma versão nova

Um comando faz tudo: baixa a última versão publicada, testa numa cópia do seu banco, faz backup, aplica as mudanças e confere o site. Se falhar antes de mexer no site, nada muda. Se falhar depois, ele volta o código e o banco sozinho.

**Só na primeira vez**, instale o comando, trocando `SEU_USUARIO`:

```sh
sudo install -m 755 ~/financeiro/tools/financeiro-atualizar.sh /usr/local/bin/financeiro-atualizar
echo 'REPO=/home/SEU_USUARIO/financeiro' | sudo tee /etc/financeiro-atualizar.conf
```

**Para atualizar:**

```sh
sudo financeiro-atualizar --verificar   # mostra se há versão nova e o que muda
sudo financeiro-atualizar               # atualiza, depois de confirmar
```

✅ A última linha do resultado traz o backup, e **Ajustes** mostra a versão nova no fim da tela.

O próprio comando não se atualiza: se uma versão mudar `tools/financeiro-atualizar.sh`, o `CHANGELOG.md` avisa para repetir o `sudo install` depois de `git -C ~/financeiro pull`.

## Restaurar um backup

Isto substitui os dados atuais pelos do backup:

```sh
sudo sh -c 'gunzip -c /var/backups/financeiro/ARQUIVO.sql.gz | mysql financeiro'
```

## Quando algo dá errado

| O que aparece | Causa provável | O que fazer |
|---|---|---|
| `Erro interno. Tente novamente` | O app não conectou no banco | Confira `DB_*` em `config/.env` e veja `sudo tail /var/log/apache2/financeiro-error.log` |
| `Class "Pdo\Mysql" not found` | PHP anterior a 8.4 | Atualize o PHP |
| `Call to undefined function mb_…` | Falta a extensão `mbstring` | `sudo apt install php-mbstring` e recarregue o Apache |
| `could not find driver` | Falta a extensão `pdo_mysql` | `sudo apt install php-mysql` e recarregue o Apache |
| Só a página inicial abre; as outras dão 404 | `mod_rewrite` ou `AllowOverride` | Refaça o passo 7 |
| Página padrão do Apache | O site padrão continua ativo | `sudo a2dissite 000-default && sudo systemctl reload apache2` |
| `Arquivo de configuração não encontrado` | Falta o `.env`, ou o dono está errado | Refaça o passo 4 |
| `O banco está sem as tabelas` | O `schema.sql` não foi importado | Refaça o final do passo 3 |
| `outra atualização está rodando` | Dois comandos ao mesmo tempo | Espere o outro terminar |
| `os testes da versão … falharam no ensaio` | A versão nova não funciona no seu ambiente | O site não mudou. Não insista e avise quem mantém o projeto |
| `Este banco já tem usuário` | O instalador já rodou | Nada a fazer: ele nunca altera um banco em uso |
| Bloqueado no login | 5 tentativas erradas | Espere 10 minutos |
| `Unknown collation: utf8mb4_0900_ai_ci` | O banco não é MySQL 8 | Use MySQL 8 |
| Aviso de JIT do PCRE no log | O kernel bloqueia memória JIT | Coloque `pcre.jit=0` no `php.ini` e recarregue o Apache |

## Personalizar

- **Fuso horário:** o app usa o horário de Brasília (`src/bootstrap.php` e `src/Database.php`).
- **Banco do empréstimo:** os textos da aba citam a POUPEX (`src/Views/emprestimo/`).
- **Categorias:** crie novas no Extrato. Não renomeie as de empréstimo e poupança, `Salário` e `Fatura do cartão`: o cálculo as encontra pelo nome.
- **Mais meses no plano:** o instalador cria 16. Não há tela para acrescentar outros.
