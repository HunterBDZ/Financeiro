#!/bin/sh
# Dois cliques tiram o Financeiro deste Mac. Os dados só saem se a pessoa escolher.
ROTULO=io.github.hunterbdz.financeiro
DADOS="$HOME/Library/Application Support/Financeiro"
case "$(defaults read -g AppleLocale 2>/dev/null)" in
    pt*) Q='Desinstalar o Financeiro deste Mac?'; D='Apagar também os seus dados (lançamentos, contas e backups)? Isso não pode ser desfeito.'; S='Sim'; N='Não'; P='O Financeiro foi desinstalado.'; F="Os seus dados ficaram em $DADOS" ;;
    es*) Q='¿Desinstalar Financeiro de este Mac?'; D='¿Borrar también sus datos (movimientos, cuentas y copias de seguridad)? Esto no se puede deshacer.'; S='Sí'; N='No'; P='Financeiro se desinstaló.'; F="Sus datos se quedaron en $DADOS" ;;
    *) Q='Uninstall Financeiro from this Mac?'; D='Also delete your data (entries, accounts and backups)? This cannot be undone.'; S='Yes'; N='No'; P='Financeiro was uninstalled.'; F="Your data stayed in $DADOS" ;;
esac
perguntar() { osascript -e "button returned of (display dialog \"$1\" buttons {\"$N\", \"$S\"} default button \"$N\" with title \"Financeiro\")" 2>/dev/null; }

[ "$(perguntar "$Q")" = "$S" ] || exit 0
launchctl bootout "gui/$(id -u)/$ROTULO" 2>/dev/null
rm -f "$HOME/Library/LaunchAgents/$ROTULO.plist"
rm -rf /Applications/Financeiro.app "$HOME/Applications/Financeiro.app" "$DADOS/programa"
if [ "$(perguntar "$D")" = "$S" ]; then
    rm -rf "$DADOS"
    echo "$P"
else
    echo "$P $F"
fi
