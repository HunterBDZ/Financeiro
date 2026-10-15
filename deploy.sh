#!/usr/bin/env bash
# Publica o código no servidor a partir do diretório atual:
#   1. rsync para /tmp/fin-deploy no servidor (sem config/.env, dados pessoais e .git)
#   2. php -l em todos os .php lá (não há PHP local)
#   3. sudo financeiro-publish: copia para /var/www/financeiro, chown e roda tests/run.php
#   4. smoke HTTP nas abas
# Requisitos: chave SSH instalada em hunter@servidor e /etc/sudoers.d/financeiro-publish.
set -euo pipefail
cd "$(dirname "$0")"
HOST=${FIN_HOST:-hunter@192.168.0.154}
STAGE=/tmp/fin-deploy

if [ -n "$(git status --porcelain 2>/dev/null)" ]; then
    echo "aviso: há alterações não commitadas (publicando o diretório como está)"
fi

echo "→ enviando para $HOST:$STAGE"
rsync -az --delete --exclude .git --exclude config/.env --exclude database/data --exclude backups ./ "$HOST:$STAGE/"

echo "→ php -l"
ssh "$HOST" "cd $STAGE && find . -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v '^No syntax errors' || true; find . -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null 2>&1"

echo "→ publicando e testando"
ssh "$HOST" "sudo /usr/local/bin/financeiro-publish $STAGE"

echo "→ smoke http"
for p in / /lancamentos /mes /futuro /ajustes /login; do
    code=$(ssh "$HOST" "curl -sk -o /dev/null -w '%{http_code}' https://localhost$p")
    echo "   $p → $code"
    case $code in 200|302|303) ;; *) echo "FALHA: $p devolveu $code"; exit 1;; esac
done
echo "✓ publicado $(git rev-parse --short HEAD 2>/dev/null || echo 'sem commit')"
