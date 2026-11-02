# Manual de instalação

## No seu computador, com Linux

Funciona em qualquer distribuição de 64 bits, sem `sudo` e sem instalar banco de dados.

1. Baixe o pacote da sua máquina em [Versões](https://github.com/HunterBDZ/Financeiro/releases/latest): `x86_64` na maioria dos computadores, `aarch64` em Raspberry Pi de 64 bits. Se quiser, confira com o `SHA256SUMS` que está junto.
2. Num terminal, na pasta do download:
   ```sh
   tar xzf financeiro-*-linux-*.tar.gz
   cd financeiro-*/
   ./instalar
   ```
3. Abra `http://localhost:8484` e crie a sua conta. Se a porta estiver ocupada, o instalador mostra outra.
4. Informe a data de início e quanto você tem hoje. Depois, é só lançar.

O programa fica em `~/.local/lib/financeiro`, e os dados, em `~/.local/share/financeiro`. Ele inicia sozinho com o computador. Para dar acesso a outra pessoa, use **Ajustes → Pessoas**.

| Comando | O que faz |
|---|---|
| `financeiro` | Abre no navegador |
| `financeiro status` | Diz se está no ar |
| `financeiro parar` e `financeiro iniciar` | Desliga e liga |
| `financeiro senha LOGIN` | Define uma senha nova para essa conta |
| `financeiro desinstalar` | Tira o programa e deixa os dados. Com `--apagar-dados`, apaga também |

### Usar no celular

Abra **Ajustes → Usar no celular**, ligue o acesso pela rede e leia o código com a câmera do celular. O celular precisa estar na mesma rede, e o computador, ligado.

> [!WARNING]
> Na rede, a conexão não é criptografada: quem estiver nela pode ver a senha e os dados. Ligue só em rede de confiança.

Se o celular não abrir, em geral é o firewall do computador. O Financeiro não mexe nele. A tela mostra o comando, que é um destes:

```sh
sudo ufw allow 8484/tcp
sudo firewall-cmd --permanent --add-port=8484/tcp && sudo firewall-cmd --reload
```

### Backup e atualização

Tudo fica em **Ajustes → Backup e atualização**, com a primeira conta criada, no próprio computador:

- **Backup:** o app faz uma cópia por dia, na primeira vez que abre, e guarda as últimas 30. Baixe de vez em quando uma cópia para outro lugar.
- **Restaurar:** escolha da lista ou envie o arquivo. O que havia antes fica guardado em uma cópia.
- **Atualizar:** aperte **Procurar versão nova** e **Atualizar agora**. Se falhar, o site volta sozinho.

Instalar um pacote mais novo por cima também funciona: os dados ficam, e o banco acompanha ao abrir.

## Com Docker

Para quem já usa Docker, em servidor, NAS ou Raspberry Pi. Na pasta do repositório:

```sh
docker compose up -d
```

Abra `http://localhost:8484` e crie a sua conta. Os dados ficam no volume `financeiro-dados`.

- **Atualizar:** `docker compose pull && docker compose up -d`. O banco acompanha ao abrir, depois de uma cópia.
- **Outros aparelhos:** a porta fica só neste computador. Para abrir, ponha um proxy com HTTPS na frente e defina `CONFIAR_PROXY: "1"` e `HOSTS_PERMITIDOS` no `compose.yaml` antes da primeira execução. Depois, esses valores ficam em `/data/config.env`.
- **Quem chega primeiro cria a conta:** não deixe a porta aberta antes de criar a sua.

## Num servidor com Ubuntu ou Debian

Instala Apache, PHP e banco de dados, com HTTPS, para um computador que fica sempre ligado.

Você precisa de um computador com **Ubuntu 24.04 ou mais novo, Debian 13 ou um derivado deles** (Linux Mint, LMDE), que fique ligado e na sua rede. Nele, rode:

```sh
git clone https://github.com/HunterBDZ/Financeiro.git
sudo bash Financeiro/instalar.sh
```

Espere alguns minutos: nenhuma pergunta é feita. No fim, o instalador mostra o endereço do site.

1. Abra esse endereço no navegador, em qualquer aparelho da sua rede.
2. O navegador avisa que a conexão não é segura. Isso é esperado na primeira vez: clique em **Avançado** e depois em **Continuar**.
3. Crie a sua conta.
4. Informe a data de início e quanto você tem hoje. Depois, é só lançar.

Para dar acesso a outra pessoa, use **Ajustes → Pessoas**.

### O que o instalador faz

- Instala Apache, PHP e MySQL, se faltarem. Onde o sistema não oferece MySQL, como no Debian 13, usa MariaDB 11.4.5 ou mais novo. No Ubuntu 24.04 e no Mint, o PHP 8.4 ou mais novo vem do repositório `ppa:ondrej/php`.
- Coloca o site em `/var/www/financeiro`, cria o banco com uma senha aleatória e publica o site em HTTPS.
- Ativa o backup diário em `/var/backups/financeiro`, guardando 30 dias, e instala o comando `financeiro-atualizar`.

Ele não mexe no firewall. Se parar no meio, corrija o que ele apontar e rode o mesmo comando de novo. Rodar de novo num site pronto não muda nada. O registro completo fica em `/var/log/financeiro-instalacao.log`.

> [!CAUTION]
> O app foi feito para a rede de casa. Para acessar de fora, use uma VPN, sem abrir portas no roteador.

### Dica: mantenha o mesmo endereço

Reserve o IP do servidor no roteador. Se o IP mudar, o site continua funcionando no endereço novo, mas o navegador volta a mostrar o aviso de conexão.

## Atualizar um servidor

Um comando faz tudo: baixa a última versão publicada, testa numa cópia do seu banco, faz backup, aplica as mudanças e confere o site. Se falhar antes de mexer no site, nada muda. Se falhar depois, ele volta o código e o banco sozinho.

**Para atualizar:**

```sh
sudo financeiro-atualizar --verificar   # mostra se há versão nova e o que muda
sudo financeiro-atualizar               # atualiza, depois de confirmar
```

✅ A última linha do resultado traz o backup, e **Ajustes** mostra a versão nova no fim da tela.

O próprio comando não se atualiza: se uma versão mudar `tools/financeiro-atualizar.sh`, o `CHANGELOG.md` avisa como instalar o novo.

## Restaurar um backup no servidor

Em SQLite, use a tela: **Ajustes → Backup e atualização**. Em MySQL, isto substitui os dados atuais pelos do backup:

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
| `não consegui entrar no banco` | O MySQL ou o MariaDB já tinha senha de root | [Abra uma issue](https://github.com/HunterBDZ/Financeiro/issues) |
| `Erro interno. Tente novamente` | O app não conectou no banco | Confira `DB_*` em `config/.env` e veja `sudo tail /var/log/apache2/financeiro-error.log` |
| `Class "Pdo\Mysql" not found` | PHP anterior a 8.4 | Atualize o PHP |
| `Call to undefined function mb_…` | Falta a extensão `mbstring` | `sudo apt install php-mbstring` e recarregue o Apache |
| `could not find driver` | Falta a extensão `pdo_mysql` | `sudo apt install php-mysql` e recarregue o Apache |
| Só a página inicial abre; as outras dão 404 | `mod_rewrite` ou `AllowOverride` | Rode o instalador de novo |
| Página padrão do Apache | O site padrão continua ativo | `sudo a2dissite 000-default && sudo systemctl reload apache2` |
| `Arquivo de configuração não encontrado` | Falta o `.env`, ou o dono está errado | Rode o instalador de novo |
| `O banco está sem as tabelas` | O `schema.sql` não foi importado | Rode o instalador de novo |
| `A assinatura da atualização não confere` | O arquivo baixado não veio do projeto, ou veio alterado | O site não mudou. Não insista e [abra uma issue](https://github.com/HunterBDZ/Financeiro/issues) |
| `Faça isso no computador onde o Financeiro está instalado` | Backup, restauração e atualização não passam pelo celular | Abra no próprio computador |
| `Endereço não reconhecido` | O acesso pela rede está desligado, ou o endereço não é da rede de casa | Abra pelo endereço mostrado no computador. Atrás de outro nome, use `HOSTS_PERMITIDOS` em `config.env` |
| `outra atualização está rodando` | Dois comandos ao mesmo tempo | Espere o outro terminar |
| `os testes da versão … falharam no ensaio` | A versão nova não funciona no seu ambiente | O site não mudou. Não insista e [abra uma issue](https://github.com/HunterBDZ/Financeiro/issues) |
| Bloqueado no login | 5 tentativas erradas | Espere 10 minutos |
| `Unknown collation: utf8mb4_0900_ai_ci` | O banco é anterior ao MySQL 8 ou ao MariaDB 11.4.5 | Use MySQL 8 ou MariaDB 11.4.5 ou mais novo |
| Aviso de JIT do PCRE no log | O kernel bloqueia memória JIT | Coloque `pcre.jit=0` no `php.ini` e recarregue o Apache |

## Personalizar

- **Moeda e fuso horário:** ficam em Ajustes e na primeira configuração, no site. Trocar a moeda muda só o símbolo: nada é convertido. O fuso não depende das tabelas de fuso do banco.
- **Categorias:** crie novas no Extrato. Não renomeie as de empréstimo e poupança, `Salário` e `Fatura do cartão`: o cálculo as encontra pelo nome.
