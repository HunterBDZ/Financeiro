# Para quem já usa Docker, em servidor, NAS ou Raspberry Pi. Os dados ficam no volume /data; a porta é 8484.
FROM dunglas/frankenphp:1.13.4-php8.4

RUN install-php-extensions pdo_sqlite mbstring curl dom sodium opcache

COPY . /app
RUN rm -rf /app/.git /app/.github /app/docs/img /app/instalar.sh \
    && touch /app/DOCKER \
    && install -m 755 /app/packaging/docker/entrada.sh /usr/local/bin/financeiro-entrada \
    && cp /app/packaging/php.ini "$PHP_INI_DIR/conf.d/zz-financeiro.ini" \
    && useradd --system --uid 1000 --home-dir /data financeiro \
    && mkdir -p /data /config/caddy /data/caddy \
    && chown -R financeiro:financeiro /data /config/caddy

USER financeiro
ENV FIN_DADOS=/data FIN_APP=/app FIN_PORTA=8484 FIN_BIND=0.0.0.0 XDG_CONFIG_HOME=/config XDG_DATA_HOME=/data/caddy
VOLUME /data
EXPOSE 8484
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s CMD ["frankenphp", "php-cli", "-r", "exit(@fsockopen('127.0.0.1', 8484, $e, $t, 2) ? 0 : 1);"]
ENTRYPOINT ["financeiro-entrada"]
CMD ["frankenphp", "run", "--config", "/app/packaging/Caddyfile"]
