#!/usr/bin/env bash
# Monta o .app e o .dmg do Financeiro para Mac com chip Apple. O FrankenPHP não tem binário oficial para Mac Intel.
#
#   packaging/macos/montar.sh --app-tar financeiro-app-X.Y.Z.tar.gz --saida PASTA [--binario frankenphp-mac-arm64] [--sem-dmg]
#
# O .dmg só sai num Mac, com hdiutil. --sem-dmg monta só o .app, em qualquer sistema, para conferir.
#
# Assinar é opcional. FIN_MAC_ID é a identidade "Developer ID Application: ...", já no chaveiro. Com FIN_MAC_APPLE_ID,
# FIN_MAC_SENHA e FIN_MAC_TIME, também notariza. Sem eles, sai sem Developer ID, e o Mac avisa ao abrir (docs/INSTALACAO.md).
set -euo pipefail

FRANKENPHP=1.13.4
SOMA=ca6dfe20ec16a695a2e1509b26a1aa79537b0dab5b1ae4db1cc6b35304653f17
APP_TAR=""; SAIDA=""; BINARIO=""; SEM_DMG=0
while [ $# -gt 0 ]; do
    case "$1" in
        --app-tar) APP_TAR=$2; shift ;;
        --saida) SAIDA=$2; shift ;;
        --binario) BINARIO=$2; shift ;;
        --sem-dmg) SEM_DMG=1 ;;
        *) echo "opção desconhecida: $1" >&2; exit 2 ;;
    esac
    shift
done
[ -n "$APP_TAR" ] && [ -n "$SAIDA" ] || { echo "faltou --app-tar ou --saida" >&2; exit 2; }

AQUI=$(cd "$(dirname "$0")" && pwd)
mkdir -p "$SAIDA"
SAIDA=$(cd "$SAIDA" && pwd)
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
soma() { if command -v sha256sum >/dev/null; then sha256sum "$1"; else shasum -a 256 "$1"; fi | cut -d' ' -f1; }

if [ -z "$BINARIO" ]; then
    BINARIO=$TMP/frankenphp-mac-arm64
    curl -fsSL -o "$BINARIO" "https://github.com/php/frankenphp/releases/download/v$FRANKENPHP/frankenphp-mac-arm64"
fi
[ "$(soma "$BINARIO")" = "$SOMA" ] || { echo "o FrankenPHP de Mac não confere" >&2; exit 1; }

PALCO=$TMP/palco
APP=$PALCO/Financeiro.app
RES=$APP/Contents/Resources
PROG=$RES/programa
mkdir -p "$APP/Contents/MacOS" "$PROG/conf"
tar -xzf "$APP_TAR" -C "$PROG"
VERSAO=$(tr -d '[:space:]' < "$PROG/app/VERSION")
case "$VERSAO" in *[!0-9.]*|"") echo "versão inválida: $VERSAO" >&2; exit 2 ;; esac

sed "s/@VERSAO@/$VERSAO/g" "$AQUI/Info.plist.in" > "$APP/Contents/Info.plist"
cp "$BINARIO" "$APP/Contents/MacOS/frankenphp"
chmod 755 "$APP/Contents/MacOS/frankenphp"
cp "$AQUI/financeiro" "$RES/financeiro"
chmod 755 "$RES/financeiro"
cp "$AQUI/../icones/financeiro.icns" "$RES/financeiro.icns"
cp "$PROG/app/packaging/php.ini" "$PROG/conf/php.ini"
cp "$PROG/app/packaging/gerente-inicio.php" "$PROG/gerente.php"
echo "macos-arm64" > "$PROG/PACOTE"
if [ "$(uname -s)" = Darwin ]; then
    cc -arch arm64 -mmacosx-version-min=12.0 -O2 -o "$APP/Contents/MacOS/Financeiro" "$AQUI/lancador.c"
else
    # só para conferir fora do Mac: um script faz o mesmo que o lancador.c
    printf '#!/bin/sh\nexec /bin/sh "$(dirname "$0")/../Resources/financeiro"\n' > "$APP/Contents/MacOS/Financeiro"
    chmod 755 "$APP/Contents/MacOS/Financeiro"
fi

cp "$AQUI/LEIA-ME.txt" "$PALCO/LEIA-ME.txt"
cp "$AQUI/desinstalar.command" "$PALCO/Desinstalar o Financeiro.command"
chmod 755 "$PALCO/Desinstalar o Financeiro.command"

if [ "$SEM_DMG" = 1 ]; then
    tar -C "$PALCO" -czf "$SAIDA/financeiro-$VERSAO-macos-arm64-conferir.tar.gz" .
    ls -la "$SAIDA/financeiro-$VERSAO-macos-arm64-conferir.tar.gz"
    exit 0
fi
command -v hdiutil >/dev/null || { echo "o .dmg só sai num Mac; use --sem-dmg para conferir o .app" >&2; exit 1; }

# um Mac com chip Apple só roda o que tem assinatura; sem Developer ID, vale a assinatura local
if [ -n "${FIN_MAC_ID:-}" ]; then
    codesign --force --timestamp --options runtime --entitlements "$AQUI/entitlements.plist" --sign "$FIN_MAC_ID" "$APP/Contents/MacOS/frankenphp"
    codesign --force --timestamp --options runtime --sign "$FIN_MAC_ID" "$APP/Contents/MacOS/Financeiro"
    codesign --force --timestamp --options runtime --sign "$FIN_MAC_ID" "$APP"
else
    codesign --force --sign - "$APP/Contents/MacOS/frankenphp"
    codesign --force --sign - "$APP/Contents/MacOS/Financeiro"
    codesign --force --sign - "$APP"
fi
codesign --verify --strict "$APP"

ln -s /Applications "$PALCO/Applications"
DMG=$SAIDA/financeiro-$VERSAO-macos-arm64.dmg
rm -f "$DMG"
hdiutil create -quiet -volname Financeiro -srcfolder "$PALCO" -fs HFS+ -format UDZO "$DMG"

if [ -n "${FIN_MAC_ID:-}" ]; then
    codesign --force --timestamp --sign "$FIN_MAC_ID" "$DMG"
    if [ -n "${FIN_MAC_APPLE_ID:-}" ]; then
        xcrun notarytool submit "$DMG" --apple-id "$FIN_MAC_APPLE_ID" --password "${FIN_MAC_SENHA:?}" --team-id "${FIN_MAC_TIME:?}" --wait
        xcrun stapler staple "$DMG"
    fi
fi
ls -la "$DMG"
