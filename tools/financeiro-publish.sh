#!/usr/bin/env bash
# Instalado no servidor como /usr/local/bin/financeiro-publish (root, 755) e liberado em
# /etc/sudoers.d/financeiro-publish. Copia a staging (/tmp/fin-deploy) para /var/www/financeiro
# preservando config/.env, acerta o dono e roda os testes como www-data.
set -euo pipefail
SRC=${1:-/tmp/fin-deploy}
DEST=/var/www/financeiro

[ -d "$SRC/src" ] && [ -d "$SRC/public" ] || { echo "origem inválida: $SRC"; exit 1; }

rsync -a --delete "$SRC/src/" "$DEST/src/"
rsync -a --delete "$SRC/public/assets/" "$DEST/public/assets/"
install -m 644 "$SRC/public/index.php" "$DEST/public/index.php"
[ -f "$SRC/public/.htaccess" ] && install -m 644 "$SRC/public/.htaccess" "$DEST/public/.htaccess"
install -m 640 "$SRC/database/schema.sql" "$DEST/database/schema.sql"
mkdir -p "$DEST/tests"
rsync -a --delete "$SRC/tests/" "$DEST/tests/"
chown -R www-data:www-data "$DEST/src" "$DEST/public" "$DEST/tests" "$DEST/database/schema.sql"

sudo -u www-data bash -c "cd $DEST && php tests/run.php"
