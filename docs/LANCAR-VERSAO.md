# Como lançar uma versão

Para quem desenvolve. Quem só usa o site segue a [atualização do manual](INSTALACAO.md#atualizar-para-uma-versão-nova).

## Numeração

`MAIOR.MENOR.CORREÇÃO`:

| Mudou o quê | Exemplo | Sobe |
|---|---|---|
| Correção sem novidade | conserta um cálculo | `1.0.0` → `1.0.1` |
| Algo novo, sem exigir nada de quem atualiza | uma aba nova | `1.0.1` → `1.1.0` |
| Exige ação manual ou muda um comportamento importante | novo requisito de PHP | `1.1.0` → `2.0.0` |

## Passo a passo

1. Desenvolva e teste num banco de demonstração ([como preparar](../CONTRIBUTING.md#rodar-localmente)): `php tests/run.php`.
2. Se o banco mudou:
   - crie `database/migrations/AAAA_MM_DD_descricao.sql` (estrutura) e, se precisar mudar dados, um `.php` com o mesmo prefixo;
   - faça a migração funcionar se rodar de novo, porque ela só é registrada depois de terminar;
   - no SQLite, crie também `database/migrations-sqlite/sNNNN_descricao.sql` (ou `.php`, que devolve uma função que recebe a conexão), com número maior que o último;
   - atualize `database/schema.sql` e `database/schema.sqlite.sql`, porque instalações novas começam por eles e marcam as migrações como feitas.
3. Atualize `VERSION`, o selo de versão no topo do `README.md` (ele aparece duas vezes na mesma linha) e acrescente a seção da versão no topo de `CHANGELOG.md`. Os testes falham se o selo e o `VERSION` não baterem.
4. Publique:
   ```sh
   git add -A && git commit -m "Versão 1.1.0: aba Exemplo"
   git tag -s v1.1.0 -m "Versão 1.1.0"
   git push origin main --follow-tags
   ```
   A tag é **assinada** com a chave SSH de quem publica (veja abaixo): os servidores recusam versão sem essa assinatura.

Numa tag, `.github/workflows/release.yml` testa, monta os pacotes com `packaging/empacotar.sh`, assina o `atualizacao.json` com o segredo `FIN_CHAVE_ATUALIZACAO`, publica a versão e a imagem Docker. Sem o segredo, a publicação para: os sites só aceitam o que essa chave assinou.

O app é um pacote só para todo sistema: `financeiro-app-X.Y.Z.tar.gz`, com o `atualizacao.json` assinado. Quem instalou em qualquer sistema baixa o mesmo arquivo. O FrankenPHP não muda numa atualização pela tela: para mudar, a pessoa instala o pacote novo.

### Windows e Mac

O instalador de Windows sai em Linux, com `nsis`, `p7zip-full` e `mingw-w64`. O `.dmg` só sai num Mac: o `release.yml` tem um job para isso. Em **Actions → Windows e Mac**, um botão monta, instala e testa nos dois sistemas de verdade. Rode antes de lançar: esse job só roda à mão, porque gasta mais da cota do GitHub.

O FrankenPHP de cada sistema é conferido pelo SHA-256: em `packaging/empacotar.sh`, `packaging/windows/montar.sh` e `packaging/macos/montar.sh`. O projeto às vezes refaz os arquivos sem mudar o número da versão, e aí a montagem para com "não confere". Baixe o arquivo novo e veja o que mudou. Troque o SHA numa branch, rode **Windows e Mac** nela e só então junte na `main`.

Assinar é opcional. Sem estes segredos, os pacotes saem sem assinatura, e o sistema avisa ao abrir:

| Segredo | Para quê |
|---|---|
| `FIN_WIN_PFX`, `FIN_WIN_SENHA` | Certificado de código do Windows, em base64, e a senha. Assina os `.exe`, as `.dll`, o instalador e o desinstalador |
| `FIN_MAC_P12`, `FIN_MAC_P12_SENHA`, `FIN_MAC_ID` | Certificado Developer ID em base64, a senha e o nome da identidade |
| `FIN_MAC_APPLE_ID`, `FIN_MAC_SENHA`, `FIN_MAC_TIME` | Apple ID, senha de app e time, para notarizar |

### Microsoft Store (opcional)

A assinatura da loja é gratuita e evita o aviso do Windows, mas isso nunca foi tentado. Reserve o nome no Partner Center e, numa versão já montada:

```sh
packaging/windows/montar.sh --app PASTA_DO_APP --versao X.Y.Z --saida PASTA --so-pasta
packaging/windows/loja/montar.sh --programa PASTA/programa --versao X.Y.Z --saida PASTA/loja --nome NOME --publisher "CN=..." --publicado-por "Nome"
```

Num Windows, `makeappx pack /d PASTA\loja /p financeiro-X.Y.Z.msix /nv` gera o pacote para enviar. Nessa instalação, a tela diz que as versões novas vêm da loja. Falta conferir se o MSIX aceita o servidor local e o início automático.

### Chave da atualização

Para gerar um par novo, `php tools/assinar-atualizacao.php --gerar=ARQUIVO`: o privado vai para o arquivo, que nunca entra no repositório, e o público sai na tela e vai para `Atualizacao::CHAVE_PUBLICA`. Quem trocar a chave precisa publicar a chave nova numa versão assinada pela antiga.

### Chave das versões (servidores)

O `financeiro-atualizar` só instala uma tag assinada com a chave SSH cuja parte pública está em `ASSINANTES`, no próprio script. A chave privada fica com quem publica, fora do repositório e fora do GitHub. Para assinar sempre, uma vez no clone:

```sh
git config gpg.format ssh
git config user.signingkey /caminho/da/chave-privada
git config tag.gpgSign true
```

Para trocar a chave, publique, assinada pela antiga, uma versão que já traga a nova em `ASSINANTES`. Ao terminar uma atualização, o `financeiro-atualizar` instala a versão nova dele mesmo e do backup diário, que vieram dentro da tag conferida. Para conferir uma tag à mão:

```sh
f=$(mktemp); sed -n "s/^ASSINANTES='\(.*\)'$/\1/p" tools/financeiro-atualizar.sh > "$f"
git -c gpg.format=ssh -c gpg.ssh.allowedSignersFile="$f" verify-tag v1.1.0; rm -f "$f"
```

Os sites só recebem o que tem tag: um commit sem tag no `main` não chega a ninguém. Não mova nem apague uma tag publicada: se houver erro, lance outra versão.

## Migrações

- A tabela `migracoes` registra o que já rodou em cada site.
- `database/instalar.php` marca como aplicadas as que já vieram no `schema.sql`.
- A pasta `database/migrations` começa vazia, só com `.gitkeep`: o `schema.sql` já contém tudo. Mantenha a pasta, porque o atualizador procura as migrações nela.
- `database/migrations-sqlite` vale para o SQLite: cada arquivo roda na sua transação, ao atualizar ou ao abrir um pacote novo.
- O `.sql` roda como root do MySQL, e o `.php` como o usuário do PHP, que só tem `SELECT`, `INSERT`, `UPDATE` e `DELETE`.
