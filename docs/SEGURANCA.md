# Segurança

Como o Financeiro protege as contas e os dados.

- Senhas com bcrypt, consultas preparadas e CSRF em todo POST.
- Bloqueio de 10 minutos depois de 5 tentativas erradas de login.
- Sessão com `HttpOnly` e `SameSite=Strict`. "Lembrar este aparelho" dura 60 dias, troca o token a cada uso e pode ser revogado em Ajustes.
- CSP estrita, sem script inline nem CDN, com fontes auto-hospedadas.
- O usuário do banco só tem `SELECT`, `INSERT`, `UPDATE` e `DELETE`.
- Ao ler um link colado, o servidor só conecta em endereços públicos, confere cada redirecionamento e valida a foto pelo conteúdo.
