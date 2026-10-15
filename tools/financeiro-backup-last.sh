#!/usr/bin/env bash
# Instalado no servidor como /usr/local/bin/financeiro-backup-last (root, 755) e liberado em
# /etc/sudoers.d/financeiro-publish. Copia o dump mais recente para /tmp com leitura liberada
# e imprime o nome do arquivo (tools/backup-pull.sh busca de lá).
set -euo pipefail
ULT=$(ls -t /var/backups/financeiro/financeiro-*.sql.gz | head -1)
[ -n "$ULT" ] || { echo "nenhum dump em /var/backups/financeiro" >&2; exit 1; }
install -m 644 "$ULT" "/tmp/$(basename "$ULT")"
basename "$ULT"
