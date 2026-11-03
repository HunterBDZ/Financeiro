# Privacidade e aviso

O mesmo texto aparece no app, em `/privacidade`, com link na tela de entrar, no fim da Ajuda e em Ajustes. A página abre sem login.

## Seus dados

Os dados ficam no computador onde o app foi instalado. O app não tem telemetria nem anúncios e não pede a senha do seu banco. Só quem tem conta no app vê os números.

## O que sai do computador

| Quando | Para onde | O que vai |
|---|---|---|
| A tela usa cotação, no máximo a cada 2 horas | `economia.awesomeapi.com.br` e, se falhar, `api.frankfurter.dev` | A lista de moedas |
| Você cola um link na Wishlist | O site do link | O endereço do link |
| Você aperta Procurar versão nova | `github.com` | O pedido das versões |

Nos três casos, o site vê o endereço IP do computador, mas nenhum valor seu. Sem internet, o app continua funcionando. Os detalhes técnicos, inclusive a instalação em servidor, estão em [SEGURANCA.md](SEGURANCA.md#o-que-sai-do-servidor).

## Cookies

O app usa só cookies necessários, então não mostra aviso pedindo consentimento:

| Cookie | Para quê | Duração |
|---|---|---|
| `financeiro_sid` | A sessão de quem entrou | Até fechar o navegador ou sair |
| `financeiro_lembrar` | "Lembrar este aparelho" | 60 dias |
| `financeiro_idioma` | O idioma escolhido antes de entrar | 1 ano |

O tema escolhido fica no armazenamento do navegador (`localStorage`, chave `tema`). Não há cookies de propaganda nem de medição.

## Aviso

O Financeiro é software livre, sob a licença [AGPL-3.0-or-later](../LICENSE), e vem sem garantia. Ele ajuda a organizar as contas, mas não é aconselhamento financeiro: confira os números antes de decidir. Os dados são de quem usa, e os backups ficam com quem instalou o app (Ajustes, Backup e atualização).

## Contato

- Dúvidas, sugestões e problemas: as Issues do repositório.
- Falha de segurança: siga o [SECURITY.md](../SECURITY.md), sem abrir Issue pública.
