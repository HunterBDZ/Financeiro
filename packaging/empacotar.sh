#!/usr/bin/env bash
# Monta os arquivos de uma versão, a partir do commit atual: os pacotes de Linux, o pacote só do app (que o site baixa
# para se atualizar), o atualizacao.json assinado e o SHA256SUMS.
#
#   packaging/empacotar.sh --saida PASTA [--arq x86_64,aarch64] [--binario ARQUIVO] [--ca ARQUIVO] [--chave ARQUIVO] [--php COMANDO]
#
#   --binario   FrankenPHP já baixado; o SHA-256 tem de bater com o daqui. Sem ele, baixa do projeto.
#   --ca        certificados raiz; sem ele, baixa de curl.se
#   --chave     chave privada da atualização, ou FIN_CHAVE_ATUALIZACAO no ambiente. Sem nenhuma, não assina.
set -euo pipefail

FRANKENPHP=1.13.4
declare -A SOMA=(
    [x86_64]=3bbd2f11929550e3b35ddb407c7981ef14bc5337e705c910068f85a6ccbf91f3
    [aarch64]=35eebf7462790c897ebf8c464c24f7a93ffcbc7012376ed0f1f66defa935585d
)
SAIDA=""; ARQS=x86_64,aarch64; BINARIO=""; CA=""; CHAVE=""; PHP=php
while [ $# -gt 0 ]; do
    case "$1" in
        --saida) SAIDA=$2; shift ;;
        --arq) ARQS=$2; shift ;;
        --binario) BINARIO=$2; shift ;;
        --ca) CA=$2; shift ;;
        --chave) CHAVE=$2; shift ;;
        --php) PHP=$2; shift ;;
        *) echo "opção desconhecida: $1" >&2; exit 2 ;;
    esac
    shift
done
[ -n "$SAIDA" ] || { echo "faltou --saida" >&2; exit 2; }

RAIZ=$(git rev-parse --show-toplevel)
cd "$RAIZ"
VERSAO=$(git show HEAD:VERSION | tr -d '[:space:]')
mkdir -p "$SAIDA"
SAIDA=$(cd "$SAIDA" && pwd)
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

if [ -z "$CA" ]; then
    curl -fsSL -o "$TMP/cacert.pem" https://curl.se/ca/cacert.pem
    CA=$TMP/cacert.pem
fi

# ---- o app: igual em todo sistema; o que é só do repositório fica de fora
mkdir "$TMP/montar"
git archive --format=tar --prefix=app/ HEAD | tar -x -C "$TMP/montar"
rm -rf "$TMP/montar/app/.github" "$TMP/montar/app/docs/img" "$TMP/montar/app/instalar.sh"
cp "$CA" "$TMP/montar/app/packaging/cacert.pem"
EMPACOTE=(tar --format=ustar --owner=0 --group=0 --numeric-owner --sort=name)
APP=financeiro-app-$VERSAO.tar.gz
"${EMPACOTE[@]}" -C "$TMP/montar" -cf - app | gzip -9 > "$SAIDA/$APP"

# ---- os pacotes de cada arquitetura
IFS=, read -ra LISTA <<< "$ARQS"
for arq in "${LISTA[@]}"; do
    [ -n "${SOMA[$arq]:-}" ] || { echo "arquitetura desconhecida: $arq" >&2; exit 2; }
    bin=${BINARIO:-}
    if [ -z "$bin" ]; then
        bin=$TMP/frankenphp-$arq
        curl -fsSL -o "$bin" "https://github.com/php/frankenphp/releases/download/v$FRANKENPHP/frankenphp-linux-$arq"
    fi
    echo "${SOMA[$arq]}  $bin" | sha256sum -c --quiet - || { echo "o FrankenPHP de $arq não confere" >&2; exit 1; }

    nome=financeiro-$VERSAO-linux-$arq
    pac=$TMP/$nome
    mkdir -p "$pac/programa/bin" "$pac/programa/conf"
    cp -R "$TMP/montar/app" "$pac/programa/app"
    install -m 755 "$bin" "$pac/programa/bin/frankenphp"
    install -m 755 packaging/linux/financeiro "$pac/programa/financeiro"
    install -m 755 packaging/linux/instalar "$pac/instalar"
    install -m 644 packaging/linux/LEIA-ME.txt "$pac/LEIA-ME.txt"
    install -m 644 packaging/Caddyfile "$pac/programa/Caddyfile"
    install -m 644 packaging/php.ini "$pac/programa/conf/php.ini"
    echo "linux-$arq" > "$pac/programa/PACOTE"
    "${EMPACOTE[@]}" -C "$TMP" -cf - "$nome" | gzip -6 > "$SAIDA/$nome.tar.gz"
    rm -rf "$pac"
done

# ---- assinatura: o site só aceita o que esta chave assinou
if [ -n "$CHAVE" ] || [ -n "${FIN_CHAVE_ATUALIZACAO:-}" ]; then
    # shellcheck disable=SC2086
    $PHP tools/assinar-atualizacao.php ${CHAVE:+--chave="$CHAVE"} --pacote="$SAIDA/$APP" --versao="$VERSAO" --notas=CHANGELOG.md
else
    echo "Sem chave: atualizacao.json não foi gerado." >&2
fi
( cd "$SAIDA" && sha256sum -- *.tar.gz > SHA256SUMS )
ls -la "$SAIDA"
