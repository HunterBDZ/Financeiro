# Segurança

Como o Financeiro protege as contas e os dados.

- Senhas com bcrypt, consultas preparadas e CSRF em todo POST.
- Bloqueio de 10 minutos depois de 5 tentativas erradas de login.
- Sessão com `HttpOnly` e `SameSite=Strict`. "Lembrar este aparelho" dura 60 dias, troca o token a cada uso e pode ser revogado em Ajustes.
- CSP estrita, sem script inline nem CDN, com fontes auto-hospedadas.
- O usuário do banco só tem `SELECT`, `INSERT`, `UPDATE` e `DELETE`.
- Ao ler um link colado, o servidor só conecta em endereços públicos, confere cada redirecionamento e valida a foto pelo conteúdo.

Para relatar uma falha, veja o [`SECURITY.md`](../SECURITY.md).

## O que sai do servidor

Os dados financeiros ficam no seu banco, e o app não tem telemetria nem pede a senha do seu banco. O servidor só faz conexões de saída nestes casos:

| Quando | Para onde | O que é enviado |
|---|---|---|
| A tela usa cotação, no máximo a cada 2 horas | `economia.awesomeapi.com.br` e, se falhar, `api.frankfurter.dev` | A lista de moedas e o IP do servidor. Nenhum valor seu |
| Você cola um link na Wishlist | O site do link | O endereço do link e o IP do servidor |
| Você roda `financeiro-atualizar` | O repositório, em `github.com` | O pedido das versões e o IP do servidor |
| Na instalação | Os repositórios do sistema e o `github.com` | Os pedidos de pacotes e do código |

Sem acesso à internet, o app continua funcionando: a cotação fica na última guardada, e o item da Wishlist é preenchido à mão. O navegador só fala com o seu servidor, porque fontes e imagens são locais.
