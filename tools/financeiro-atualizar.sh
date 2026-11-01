#!/usr/bin/env bash
# Atualiza um site Financeiro para a última versão publicada (a maior tag vX.Y.Z do repositório).
# Instalado como /usr/local/bin/financeiro-atualizar; configuração em /etc/financeiro-atualizar.conf.
#
#   sudo financeiro-atualizar --verificar     diz se há versão nova; não muda nada
#   sudo financeiro-atualizar                 atualiza, depois de confirmar
#   sudo financeiro-atualizar --sim           atualiza sem perguntar (para o cron não é recomendado)
#   sudo financeiro-atualizar --versao v1.2.0 vai para uma versão específica, mais nova que a atual
#
# Ordem: baixa a versão -> ensaia migrações e testes numa cópia do banco, com o site intacto ->
# backup -> migrações -> código -> testes no site. Falhou no ensaio: nada mudou. Falhou depois do
# backup: volta o código e o banco sozinho. config/.env nunca é tocado.
set -euo pipefail

REPO=""                         # clone do repositório (obrigatório)
DEST=/var/www/financeiro
BANCO=financeiro
SERVICO=www-data                # usuário que roda o PHP
BACKUPS=/var/backups/financeiro
REMOTO=origin
TRAVA=/run/lock/financeiro-atualizar.lock
TEMP_BASE=/var/tmp
MYSQL_OPCOES=()
PERMITIR_SEM_ROOT=0             # só para ensaiar este script fora de produção

CONF=${FIN_CONF:-/etc/financeiro-atualizar.conf}
RE_VERSAO='^v[0-9]+\.[0-9]+\.[0-9]+$'
BASE=.na-base                   # migrações que o schema.sql da 1ª versão já contém
TMP=""
ENSAIO=""
USUARIO_ENSAIO=""
DUMP=""

morre() { echo "ERRO: $*" >&2; exit 1; }
passo() { echo "→ $*"; }

if [ -r "$CONF" ]; then
    if [ "$(id -u)" = 0 ] && [ "$(stat -c %U "$CONF")" != root ]; then
        morre "$CONF precisa pertencer ao root."
    fi
    # shellcheck disable=SC1090
    . "$CONF"
fi

SO_VER=0
CONFIRMADO=0
PEDIDA=""
while [ $# -gt 0 ]; do
    case "$1" in
        --verificar) SO_VER=1 ;;
        --sim) CONFIRMADO=1 ;;
        --versao) shift; PEDIDA=${1:-} ;;
        -h|--help) sed -n '2,11p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) morre "opção desconhecida: $1" ;;
    esac
    shift
done

[ "$(id -u)" = 0 ] || [ "$PERMITIR_SEM_ROOT" = 1 ] || morre "rode com sudo."
[ -n "$REPO" ] || morre "defina REPO em $CONF."
[ -d "$REPO/.git" ] || morre "$REPO não é um clone do repositório."
[ -f "$DEST/config/.env" ] || morre "$DEST/config/.env não existe: instale o site antes."
for c in git mysql mysqldump php rsync gzip tar flock openssl; do
    command -v "$c" >/dev/null || morre "falta o programa $c."
done

como_dono() {
    if [ "$(id -u)" = 0 ]; then sudo -H -u "$(stat -c %U "$REPO")" git -C "$REPO" "$@"; else git -C "$REPO" "$@"; fi
}
como_servico() {
    if [ "$(id -u)" = 0 ]; then sudo -u "$SERVICO" "$@"; else "$@"; fi
}
sql() { mysql "${MYSQL_OPCOES[@]}" "$@"; }
valor_env() { sed -n "s/^$1=//p" "$DEST/config/.env" | head -1; }

limpar() {
    if [ -n "$ENSAIO" ]; then sql -e "DROP DATABASE IF EXISTS \`$ENSAIO\`" 2>/dev/null || true; fi
    if [ -n "$USUARIO_ENSAIO" ]; then
        sql -e "DROP USER IF EXISTS '$USUARIO_ENSAIO'@'localhost', '$USUARIO_ENSAIO'@'127.0.0.1'" 2>/dev/null || true
    fi
    if [ -n "$TMP" ]; then rm -rf "$TMP"; fi
}
trap limpar EXIT

exec 9>"$TRAVA"
flock -n 9 || morre "outra atualização está rodando."

# ---- que versão?
como_dono fetch --quiet --tags "$REMOTO" || morre "não consegui falar com o repositório ($REMOTO)."
ATUAL=""
[ -f "$DEST/VERSION" ] && ATUAL=$(tr -d '[:space:]' < "$DEST/VERSION")
ULTIMA=$(como_dono tag --list 'v[0-9]*' --sort=-v:refname | grep -E "$RE_VERSAO" | head -1 || true)
[ -n "$ULTIMA" ] || morre "o repositório não tem versão publicada (tag vX.Y.Z)."
ALVO=${PEDIDA:-$ULTIMA}
[[ "$ALVO" =~ $RE_VERSAO ]] || morre "versão inválida: $ALVO (use vX.Y.Z)."
como_dono rev-parse --quiet --verify "refs/tags/$ALVO" >/dev/null || morre "a versão $ALVO não existe."
NOVA=${ALVO#v}
[ "$(como_dono show "$ALVO:VERSION" 2>/dev/null | tr -d '[:space:]')" = "$NOVA" ] || morre "o arquivo VERSION da tag $ALVO não diz $NOVA."

echo "Instalada: ${ATUAL:-anterior à 1.0.0}"
echo "Disponível: $NOVA"
if [ "$ATUAL" = "$NOVA" ]; then
    echo "Já está na versão $NOVA."
    exit 0
fi
if [ -n "$ATUAL" ] && [ "$(printf '%s\n%s\n' "$ATUAL" "$NOVA" | sort -V | tail -1)" = "$ATUAL" ]; then
    if [ "$SO_VER" = 1 ]; then exit 0; fi
    morre "a instalada ($ATUAL) é mais nova que $NOVA. Para voltar, restaure um backup (docs/INSTALACAO.md)."
fi
NOTAS=$(como_dono show "$ALVO:CHANGELOG.md" 2>/dev/null | awk -v v="## $NOVA" 'index($0, v) == 1 { d = 1; next } /^## / { d = 0 } d' || true)
if [ -n "$NOTAS" ]; then printf 'O que muda:\n%s\n' "$NOTAS"; fi
if [ "$SO_VER" = 1 ]; then exit 0; fi

if [ "$CONFIRMADO" != 1 ]; then
    [ -t 0 ] || morre "use --sim quando rodar sem terminal."
    read -r -p "Atualizar para $NOVA? [s/N] " resp
    case "$resp" in s|S|sim|Sim|SIM) ;; *) echo "Nada foi alterado."; exit 0 ;; esac
fi

# ---- a versão nova, fora do site
TMP=$(mktemp -d "$TEMP_BASE/financeiro-atualizar.XXXXXX")
chmod 755 "$TMP"
mkdir "$TMP/novo" "$TMP/antes"
como_dono archive --format=tar "$ALVO" | tar -x -C "$TMP/novo"
if [ "$(id -u)" = 0 ]; then chown -R "$SERVICO":"$SERVICO" "$TMP/novo"; fi

# ---- migrações: a tabela `migracoes` diz o que já rodou
#  $1 = banco; $2 = pasta da versão nova
preparar_registro() {
    local tem
    tem=$(sql -N "$1" -e "SHOW TABLES LIKE 'migracoes'") || return 1
    sql "$1" -e "CREATE TABLE IF NOT EXISTS migracoes (
        arquivo VARCHAR(190) NOT NULL PRIMARY KEY,
        aplicada_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" || return 1
    # site anterior ao registro: tudo o que a 1ª versão com registro já trazia está aplicado
    if [ -z "$tem" ] && [ -f "$2/database/migrations/$BASE" ]; then
        local a
        while read -r a; do
            [ -n "$a" ] || continue
            sql "$1" -e "INSERT IGNORE INTO migracoes (arquivo) VALUES ('$a')" || return 1
        done < "$2/database/migrations/$BASE"
    fi
}
pendentes() {
    local a feitas
    feitas=$(sql -N "$1" -e 'SELECT arquivo FROM migracoes') || return 1
    # mesmo prefixo: o .sql (estrutura) antes do .php (dados)
    find "$2/database/migrations" -maxdepth 1 -type f \( -name '*.sql' -o -name '*.php' \) -printf '%f\n' \
        | LC_ALL=C sort -t. -k1,1 -k2,2r \
        | while read -r a; do
            [[ "$a" =~ ^[A-Za-z0-9_.-]+$ ]] || { echo "nome de migração inválido: $a" >&2; exit 1; }
            grep -qxF "$a" <<< "$feitas" || echo "$a"
        done
}
aplicar_migracoes() {
    local a lista
    preparar_registro "$1" "$2" || return 1
    lista=$(pendentes "$1" "$2") || return 1
    for a in $lista; do
        echo "   migração $a"
        case "$a" in
            *.sql) sql "$1" < "$2/database/migrations/$a" || return 1 ;;
            *.php) ( cd "$2" && como_servico php "database/migrations/$a" ) || return 1 ;;
        esac
        sql "$1" -e "INSERT INTO migracoes (arquivo) VALUES ('$a')" || return 1
    done
}

# ---- ensaio numa cópia do banco, com o código novo; o site continua igual
passo "ensaiando a versão $NOVA numa cópia do banco"
SENHA_ENSAIO=$(openssl rand -hex 16)
ENSAIO="${BANCO}_ensaio_$$"
USUARIO_ENSAIO="fin_ensaio_$$"
sql -e "CREATE DATABASE \`$ENSAIO\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
    CREATE USER '$USUARIO_ENSAIO'@'localhost' IDENTIFIED BY '$SENHA_ENSAIO';
    CREATE USER '$USUARIO_ENSAIO'@'127.0.0.1' IDENTIFIED BY '$SENHA_ENSAIO';
    GRANT SELECT, INSERT, UPDATE, DELETE ON \`$ENSAIO\`.* TO '$USUARIO_ENSAIO'@'localhost';
    GRANT SELECT, INSERT, UPDATE, DELETE ON \`$ENSAIO\`.* TO '$USUARIO_ENSAIO'@'127.0.0.1';" \
    || morre "não consegui criar o banco de ensaio. O site não mudou."
mysqldump "${MYSQL_OPCOES[@]}" --single-transaction --routines --triggers --events "$BANCO" > "$TMP/copia.sql" \
    || morre "não consegui copiar o banco. O site não mudou."
sql "$ENSAIO" < "$TMP/copia.sql" || morre "não consegui montar a cópia do banco. O site não mudou."

mkdir -p "$TMP/novo/config"
sed -e "s/^DB_NAME=.*/DB_NAME=$ENSAIO/" -e "s/^DB_USER=.*/DB_USER=$USUARIO_ENSAIO/" -e "s/^DB_PASS=.*/DB_PASS=$SENHA_ENSAIO/" \
    "$DEST/config/.env" > "$TMP/novo/config/.env"
chmod 640 "$TMP/novo/config/.env"
if [ "$(id -u)" = 0 ]; then chown "$SERVICO":"$SERVICO" "$TMP/novo/config/.env"; fi

while IFS= read -r -d '' f; do
    php -l "$f" >/dev/null || morre "erro de sintaxe em ${f#"$TMP"/novo/}. O site não mudou."
done < <(find "$TMP/novo/src" "$TMP/novo/public" "$TMP/novo/database" "$TMP/novo/tests" -name '*.php' -print0)
aplicar_migracoes "$ENSAIO" "$TMP/novo" || morre "uma migração falhou no ensaio. O site não mudou."
( cd "$TMP/novo" && como_servico php tests/run.php ) > "$TMP/ensaio.log" 2>&1 || {
    grep -E 'FALHA|Fatal' "$TMP/ensaio.log" | head -10 >&2 || true
    morre "os testes da versão $NOVA falharam no ensaio. O site não mudou."
}
tail -1 "$TMP/ensaio.log" | sed 's/^/   testes: /'
rm -f "$TMP/novo/config/.env" "$TMP/copia.sql"

# ---- backup
passo "fazendo backup"
mkdir -p "$BACKUPS"
chmod 700 "$BACKUPS"
CARIMBO=$(date -u +%Y%m%d-%H%M%S)
DUMP="$BACKUPS/antes-atualizacao-${ATUAL:-0}-para-$NOVA-$CARIMBO.sql.gz"
CODIGO="$BACKUPS/codigo-antes-atualizacao-${ATUAL:-0}-para-$NOVA-$CARIMBO.tar.gz"
mysqldump "${MYSQL_OPCOES[@]}" --single-transaction --routines --triggers --events "$BANCO" | gzip -9 > "$DUMP" \
    || morre "o backup do banco falhou. O site não mudou."
# grep -c lê o arquivo inteiro: com -q, zcat morreria de SIGPIPE e o pipefail acusaria backup ruim
gzip -t "$DUMP" && zcat "$DUMP" | grep -c 'CREATE TABLE `lancamentos`' >/dev/null || morre "o backup do banco está ruim. O site não mudou."
chmod 600 "$DUMP"
tar -czf "$CODIGO" -C "$DEST" --exclude=config/.env . || morre "o backup do código falhou. O site não mudou."
chmod 600 "$CODIGO"
rsync -a --exclude config/.env "$DEST/" "$TMP/antes/"
find "$BACKUPS" \( -name 'antes-atualizacao-*' -o -name 'codigo-antes-atualizacao-*' \) -mtime +90 -delete

recarregar() {
    if [ "$(id -u)" = 0 ] && command -v systemctl >/dev/null; then systemctl reload apache2 2>/dev/null || true; fi
}
aplicar() {
    passo "aplicando migrações"
    # as migrações .php rodam no código novo, agora apontando para o banco de verdade
    cp -p "$DEST/config/.env" "$TMP/novo/config/.env" || return 1
    aplicar_migracoes "$BANCO" "$TMP/novo" || return 1
    passo "copiando o código"
    rsync -a --delete --exclude .git --exclude config/.env --exclude database/data "$TMP/novo/" "$DEST/" || return 1
    if [ "$(id -u)" = 0 ]; then
        chown -R "$SERVICO":"$SERVICO" "$DEST"
        chmod 750 "$DEST" "$DEST/config" "$DEST/database"   # só o public/ precisa ficar aberto
        chmod 640 "$DEST/config/.env"
    fi
    recarregar
    passo "conferindo o site"
    ( cd "$DEST" && como_servico php tests/run.php ) > "$TMP/site.log" 2>&1 || {
        grep -E 'FALHA|Fatal' "$TMP/site.log" | head -10 >&2 || true
        return 1
    }
    tail -1 "$TMP/site.log" | sed 's/^/   testes: /'
}
desfazer() {
    echo "A atualização falhou. Voltando para a versão anterior." >&2
    rsync -a --delete --exclude config/.env "$TMP/antes/" "$DEST/" || return 1
    if [ "$(id -u)" = 0 ]; then chown -R "$SERVICO":"$SERVICO" "$DEST"; chmod 640 "$DEST/config/.env"; fi
    sql -e "DROP DATABASE \`$BANCO\`; CREATE DATABASE \`$BANCO\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;" || return 1
    zcat "$DUMP" | sql "$BANCO" || return 1
    recarregar
}

if ! aplicar; then
    if desfazer; then
        morre "o site voltou para ${ATUAL:-a versão anterior}. Backup: $DUMP"
    fi
    morre "NÃO consegui voltar sozinho. Restaure o banco de $DUMP e o código de $CODIGO (docs/INSTALACAO.md)."
fi

echo "Pronto: site na versão $NOVA."
echo "Backup desta atualização: $DUMP"
