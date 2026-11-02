#!/bin/sh
# Backup diário do banco "financeiro": em MySQL, mantém 30 dias em /var/backups/financeiro.
# Instale como /usr/local/bin/financeiro-backup.sh (root, 755); roda pelo cron como root.
set -eu
SITE=${FIN_SITE:-/var/www/financeiro}
# em SQLite, quem faz e guarda as cópias é o próprio app, na pasta de dados
if grep -q '^DB_DRIVER=sqlite' "$SITE/config/.env" 2>/dev/null; then
    F=$(sudo -u www-data php "$SITE/tools/backup.php")
    logger -t financeiro-backup "ok $F"
    exit 0
fi
DEST=/var/backups/financeiro
KEEP_DAYS=30
mkdir -p "$DEST"
chmod 700 "$DEST"
F="$DEST/financeiro-$(date +%Y%m%d-%H%M%S).sql.gz"
mysqldump --single-transaction --routines --triggers --events financeiro | gzip -9 > "$F.tmp"
gzip -t "$F.tmp"
zcat "$F.tmp" | grep -q 'CREATE TABLE `lancamentos`'
mv "$F.tmp" "$F"
chmod 600 "$F"
find "$DEST" -name 'financeiro-*.sql.gz' -mtime +"$KEEP_DAYS" -delete
logger -t financeiro-backup "ok $F ($(du -h "$F" | cut -f1))"
