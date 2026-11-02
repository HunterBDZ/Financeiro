#!/bin/sh
# No contêiner, o pedido chega de fora dele: é modo servidor, e quem protege a porta é o compose ou o seu proxy.
set -eu
if [ ! -f "$FIN_DADOS/config.env" ]; then
    umask 077
    cat > "$FIN_DADOS/config.env" <<CONF
APP_ENV=production
DB_DRIVER=sqlite
DB_FILE=financeiro.sqlite
FIN_MODO=servidor
SESSION_LIFETIME=28800
MOTOR_V2=1
# 1 = atrás de um proxy seu com HTTPS: confia em X-Forwarded-Proto
CONFIAR_PROXY=${CONFIAR_PROXY:-0}
# nomes aceitos no endereço, além de localhost, separados por vírgula; vazio aceita qualquer um (inseguro)
HOSTS_PERMITIDOS=${HOSTS_PERMITIDOS-localhost}
CONF
fi
exec "$@"
