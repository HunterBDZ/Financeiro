#!/usr/bin/env bash
# Passo opcional, para a Microsoft Store: prepara a pasta que o makeappx do Windows transforma em .msix, sem assinar:
# quem assina é a loja. Parte do programa já montado por packaging/windows/montar.sh (--so-pasta).
#
#   packaging/windows/loja/montar.sh --programa PASTA --versao X.Y.Z --saida PASTA --nome NOME --publisher CN=... --publicado-por TEXTO
#
# NOME, CN e TEXTO vêm da reserva do nome no Partner Center. Precisa de x86_64-w64-mingw32-gcc e python3 com Pillow.
set -euo pipefail

PROG=""; VERSAO=""; SAIDA=""; NOME=""; PUBLISHER=""; POR=""
while [ $# -gt 0 ]; do
    case "$1" in
        --programa) PROG=$2; shift ;;
        --versao) VERSAO=$2; shift ;;
        --saida) SAIDA=$2; shift ;;
        --nome) NOME=$2; shift ;;
        --publisher) PUBLISHER=$2; shift ;;
        --publicado-por) POR=$2; shift ;;
        *) echo "opção desconhecida: $1" >&2; exit 2 ;;
    esac
    shift
done
[ -n "$PROG" ] && [ -n "$VERSAO" ] && [ -n "$SAIDA" ] && [ -n "$NOME" ] && [ -n "$PUBLISHER" ] && [ -n "$POR" ] || { echo "faltam opções" >&2; exit 2; }

AQUI=$(cd "$(dirname "$0")" && pwd)
mkdir -p "$SAIDA"
cp -R "$PROG/." "$SAIDA/"
rm -f "$SAIDA/desinstalar.exe"
echo "windows-loja" > "$SAIDA/PACOTE"
x86_64-w64-mingw32-gcc -municode -mwindows -O2 -s -o "$SAIDA/financeiro-abrir.exe" "$AQUI/lancador.c"
x86_64-w64-mingw32-gcc -municode -mwindows -O2 -s '-DCOMANDO=L"servir"' -o "$SAIDA/financeiro-servir.exe" "$AQUI/lancador.c"
mkdir -p "$SAIDA/recursos"
python3 - "$AQUI/../../icones/financeiro.ico" "$SAIDA/recursos" <<'PY'
import sys
from PIL import Image
ico = Image.open(sys.argv[1])
ico.size = (256, 256)
ico.load()
for t in (44, 50, 150):
    ico.resize((t, t), Image.LANCZOS).save(f"{sys.argv[2]}/icone-{t}.png")
PY
sed -e "s|@VERSAO@|$VERSAO|g" -e "s|@NOME@|$NOME|g" -e "s|@PUBLISHER@|$PUBLISHER|g" -e "s|@PUBLICADO_POR@|$POR|g" "$AQUI/AppxManifest.xml.in" > "$SAIDA/AppxManifest.xml"
echo "Pronto em $SAIDA. No Windows: makeappx pack /d PASTA /p financeiro-$VERSAO.msix /nv"
