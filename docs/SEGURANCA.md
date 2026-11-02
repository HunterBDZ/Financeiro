# Segurança

Como o Financeiro protege as contas e os dados.

- Senhas com bcrypt, consultas preparadas e CSRF em todo POST.
- Bloqueio de 10 minutos depois de 5 tentativas erradas de login.
- Sessão com `HttpOnly` e `SameSite=Strict`. "Lembrar este aparelho" dura 60 dias, troca o token a cada uso e pode ser revogado em Ajustes.
- CSP estrita, sem script inline nem CDN, com fontes auto-hospedadas. Ela também proíbe `<base>`, formulário que envie para outro site, moldura (`frame-ancestors`) e plugin.
- Em MySQL, o usuário do banco só tem `SELECT`, `INSERT`, `UPDATE` e `DELETE`. Em SQLite, o arquivo do banco só pode ser lido pelo usuário do programa.
- Só a primeira conta criada no site faz backup, restaura, atualiza e liga a rede. Baixar uma cópia ou restaurar pede a senha de novo.
- Num servidor, o `financeiro-atualizar` só instala uma versão cuja tag tenha a assinatura SSH de quem publica, com a chave pública no próprio script.
- Uma atualização pela tela só é aceita com a assinatura Ed25519 do projeto. A chave pública fica no código, em `Atualizacao::CHAVE_PUBLICA`, e a privada, fora do repositório. O SHA-256 do pacote vem dentro do que foi assinado, e o app não aceita versão igual ou mais velha.
- Ao ler um link colado, o servidor só conecta em endereços públicos, confere cada redirecionamento e valida a foto pelo conteúdo.

Para relatar uma falha, veja o [`SECURITY.md`](../SECURITY.md).

## Acesso pela rede

No computador da pessoa, o programa só ouve em `127.0.0.1` até ela ligar o acesso em **Ajustes → Usar no celular**. Ligado, o acesso é em HTTP, sem criptografia: quem está na mesma rede pode ler a senha e os dados, e a tela avisa disso antes de ligar. Use só em rede de confiança. Por isso, pelo celular não se cria a primeira conta nem se faz backup, restauração, atualização ou troca do acesso: só no próprio computador. O app recusa endereço que não seja local, de rede privada ou do próprio computador, o que barra páginas de fora que tentem se passar por ele.

Em servidor, com `instalar.sh`, o site é em HTTPS com certificado próprio. Com Docker, a porta fica só no computador e o app só aceita o endereço `localhost` até você mudar o `compose.yaml`: para outros aparelhos, ponha um proxy com HTTPS na frente e o nome dele em `HOSTS_PERMITIDOS`.

Em servidor e no Docker, a primeira conta só é criada com o **código de instalação**, que aparece no fim do `instalar.sh` ou no log do contêiner. Sem ele, quem chegasse primeiro à página, ou uma página maliciosa usando DNS rebinding, ficaria com a conta de administrador. O código tem 12 caracteres sorteados, fica num arquivo privado da pasta de dados e é apagado quando a conta nasce.

## O que sai do servidor

Os dados financeiros ficam no seu banco, e o app não tem telemetria nem pede a senha do seu banco. O servidor só faz conexões de saída nestes casos:

| Quando | Para onde | O que é enviado |
|---|---|---|
| A tela usa cotação, no máximo a cada 2 horas | `economia.awesomeapi.com.br` e, se falhar, `api.frankfurter.dev` | A lista de moedas e o IP do servidor. Nenhum valor seu |
| Você cola um link na Wishlist | O site do link | O endereço do link e o IP do servidor |
| Você aperta Procurar versão nova, ou roda `financeiro-atualizar` | O repositório, em `github.com` | O pedido das versões e o IP do servidor |
| Na instalação em servidor | Os repositórios do sistema e o `github.com` | Os pedidos de pacotes e do código |

Sem acesso à internet, o app continua funcionando: a cotação fica na última guardada, e o item da Wishlist é preenchido à mão. O navegador só fala com o seu servidor, porque fontes e imagens são locais.
