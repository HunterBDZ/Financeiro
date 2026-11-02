#!/usr/bin/env bash
# Monta o instalador para Windows a partir do app já preparado, em Linux, sem nada do Windows.
#
#   packaging/windows/montar.sh --app PASTA --versao X.Y.Z --saida PASTA [--zip FRANKENPHP.zip] [--vcredist VC_redist.x64.exe] [--so-pasta]
#
# --so-pasta deixa o programa em SAIDA/programa, sem gerar o instalador: é o que a Microsoft Store usa (loja/montar.sh).
#
# Precisa de makensis, 7z, python3, mingw-w64 e, para assinar, osslsigncode. Assinar é opcional: FIN_WIN_PFX é o certificado em
# base64 e FIN_WIN_SENHA, a senha dele. Sem eles, sai sem assinar, e o Windows avisa ao abrir (docs/INSTALACAO.md).
set -euo pipefail

FRANKENPHP=1.13.4
ZIP_SHA=66b9b7322fe39a0027113cec9fd1c21a1d9d3d51ae4500ade1c9a2960555587e
# o endereço versionado traz o próprio SHA-256; o curto da Microsoft muda a cada versão
VCREDIST_SHA=cc0ff0eb1dc3f5188ae6300faef32bf5beeba4bdd6e8e445a9184072096b713b
VCREDIST_URL=https://download.visualstudio.microsoft.com/download/pr/990c720a-46ce-4dc7-87b9-c2eb615d34b9/CC0FF0EB1DC3F5188AE6300FAEF32BF5BEEBA4BDD6E8E445A9184072096B713B/VC_redist.x64.exe

APP=""; VERSAO=""; SAIDA=""; ZIP=""; VCREDIST=""; SO_PASTA=0
while [ $# -gt 0 ]; do
    case "$1" in
        --app) APP=$2; shift ;;
        --versao) VERSAO=$2; shift ;;
        --saida) SAIDA=$2; shift ;;
        --zip) ZIP=$2; shift ;;
        --vcredist) VCREDIST=$2; shift ;;
        --so-pasta) SO_PASTA=1 ;;
        *) echo "opção desconhecida: $1" >&2; exit 2 ;;
    esac
    shift
done
[ -n "$APP" ] && [ -n "$VERSAO" ] && [ -n "$SAIDA" ] || { echo "faltou --app, --versao ou --saida" >&2; exit 2; }
[[ "$VERSAO" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "versão inválida: $VERSAO" >&2; exit 2; }

AQUI=$(cd "$(dirname "$0")" && pwd)
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$SAIDA"
SAIDA=$(cd "$SAIDA" && pwd)

if [ -z "$ZIP" ]; then
    ZIP=$TMP/frankenphp.zip
    curl -fsSL -o "$ZIP" "https://github.com/php/frankenphp/releases/download/v$FRANKENPHP/frankenphp-windows-x86_64.zip"
fi
echo "$ZIP_SHA  $ZIP" | sha256sum -c --quiet - || { echo "o FrankenPHP de Windows não confere" >&2; exit 1; }
if [ -z "$VCREDIST" ]; then
    VCREDIST=$TMP/VC_redist.x64.exe
    curl -fsSL -o "$VCREDIST" "$VCREDIST_URL"
fi
echo "$VCREDIST_SHA  $VCREDIST" | sha256sum -c --quiet - || { echo "o VC_redist não confere" >&2; exit 1; }

PROG=$TMP/programa
mkdir -p "$PROG/bin/ext" "$PROG/conf" "$PROG/licencas"
unzip -q "$ZIP" -d "$TMP/fp"
# só o que o app usa: o zip completo tem 164 MB, com ICU, banco de outros sistemas e extensões que nada chama
for f in frankenphp.exe php.exe php8ts.dll brotlicommon.dll brotlidec.dll brotlienc.dll libwatcher-c.dll \
    pthreadVC3.dll libsqlite3.dll libsodium.dll libcrypto-3-x64.dll libssl-3-x64.dll libssh2.dll nghttp2.dll; do
    install -m 644 "$TMP/fp/$f" "$PROG/bin/$f"
done
for f in php_pdo_sqlite php_mbstring php_curl php_sodium; do
    install -m 644 "$TMP/fp/ext/$f.dll" "$PROG/bin/ext/$f.dll"
done
python3 "$AQUI/vcruntime.py" "$VCREDIST" "$PROG/bin"
cp "$TMP/fp/license.txt" "$PROG/licencas/php-e-frankenphp.txt"
cp "$TMP/fp/readme-redist-bins.txt" "$PROG/licencas/bibliotecas.txt"

cp -R "$APP" "$PROG/app"
cat "$AQUI/../php.ini" "$AQUI/php-windows.ini" > "$PROG/conf/php.ini"
cp "$AQUI/../gerente-inicio.php" "$PROG/gerente.php"
cp "$AQUI/financeiro.cmd" "$PROG/financeiro.cmd"
cp "$AQUI/../icones/financeiro.ico" "$TMP/financeiro.ico"
cp "$AQUI/lancador.rc" "$TMP/lancador.rc"
x86_64-w64-mingw32-windres -I "$TMP" "$TMP/lancador.rc" -O coff -o "$TMP/lancador.res"
x86_64-w64-mingw32-gcc -municode -mwindows -O2 -s -o "$PROG/financeiro.exe" "$AQUI/lancador.c" "$TMP/lancador.res"
echo "windows-x86_64" > "$PROG/PACOTE"

if [ "$SO_PASTA" = 1 ]; then
    rm -rf "$SAIDA/programa"
    cp -R "$PROG" "$SAIDA/programa"
    exit 0
fi

ASSINAR=()
if [ -n "${FIN_WIN_PFX:-}" ]; then
    base64 -d <<< "$FIN_WIN_PFX" > "$TMP/cert.pfx"
    assinar() {
        osslsigncode sign -pkcs12 "$TMP/cert.pfx" -pass "${FIN_WIN_SENHA:-}" -n Financeiro -t http://timestamp.digicert.com -h sha256 -in "$1" -out "$1.assinado"
        mv "$1.assinado" "$1"
    }
    while IFS= read -r -d '' f; do assinar "$f"; done < <(find "$PROG" -type f \( -name '*.exe' -o -name '*.dll' \) -print0)
    # o makensis assina o instalador e o desinstalador com este comando; %1 é o arquivo
    printf '#!/bin/sh\nosslsigncode sign -pkcs12 "%s" -pass "$FIN_WIN_SENHA" -n Financeiro -t http://timestamp.digicert.com -h sha256 -in "$1" -out "$1.assinado" && mv "$1.assinado" "$1"\n' "$TMP/cert.pfx" > "$TMP/assinar.sh"
    chmod +x "$TMP/assinar.sh"
    ASSINAR=("-DASSINAR=$TMP/assinar.sh \"%1\"")
fi

NOME=financeiro-$VERSAO-windows-x86_64.exe
makensis -V2 -INPUTCHARSET UTF8 -DVERSAO="$VERSAO" -DORIGEM="$PROG" -DSAIDA="$SAIDA/$NOME" -DICONE="$AQUI/../icones/financeiro.ico" -DLADO="$AQUI/lado.bmp" ${ASSINAR[@]+"${ASSINAR[@]}"} "$AQUI/financeiro.nsi"
ls -la "$SAIDA/$NOME"
