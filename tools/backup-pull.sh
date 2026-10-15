#!/usr/bin/env bash
# Copia o dump mais recente do servidor (/var/backups/financeiro, feito pelo cron às 03:30 UTC)
# para ./backups/ nesta máquina. Rode de vez em quando (ou num cron local):
#   */path/financeiro/tools/backup-pull.sh
# Requer a chave SSH em hunter@servidor e /usr/local/bin/financeiro-backup-last liberado no sudoers.
set -euo pipefail
cd "$(dirname "$0")/.."
HOST=${FIN_HOST:-hunter@192.168.0.154}
mkdir -p backups
nome=$(ssh "$HOST" 'sudo -n /usr/local/bin/financeiro-backup-last')
scp -q "$HOST:/tmp/$nome" "backups/$nome"
gzip -t "backups/$nome"
echo "✓ backups/$nome ($(du -h "backups/$nome" | cut -f1))"
# mantém os 30 mais recentes
ls -t backups/financeiro-*.sql.gz 2>/dev/null | tail -n +31 | xargs -r rm --
