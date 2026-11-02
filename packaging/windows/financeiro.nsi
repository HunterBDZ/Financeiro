; Instalador do Financeiro para Windows: por usuário, sem administrador e sem perguntas.
; makensis -INPUTCHARSET UTF8 -DVERSAO=1.2.3 -DORIGEM=pasta -DSAIDA=arquivo.exe -DICONE=financeiro.ico [-DASSINAR="comando %1"] financeiro.nsi
Unicode true
Target amd64-unicode
SetCompressor /SOLID lzma
!include "MUI2.nsh"
!include "WordFunc.nsh"
!include "FileFunc.nsh"
!include "LogicLib.nsh"

!define CHAVE_APP "Software\Microsoft\Windows\CurrentVersion\Uninstall\Financeiro"
!define CHAVE_INICIO "Software\Microsoft\Windows\CurrentVersion\Run"

!ifdef ASSINAR
!finalize '${ASSINAR}' = 0
!uninstfinalize '${ASSINAR}' = 0
!endif

Name "Financeiro"
OutFile "${SAIDA}"
InstallDir "$LOCALAPPDATA\Programs\Financeiro"
RequestExecutionLevel user
ShowInstDetails nevershow
ShowUninstDetails nevershow
BrandingText "Financeiro ${VERSAO}"
VIProductVersion "${VERSAO}.0"
VIAddVersionKey /LANG=0 "ProductName" "Financeiro"
VIAddVersionKey /LANG=0 "ProductVersion" "${VERSAO}"
VIAddVersionKey /LANG=0 "FileVersion" "${VERSAO}"
VIAddVersionKey /LANG=0 "FileDescription" "Financeiro"
VIAddVersionKey /LANG=0 "LegalCopyright" "AGPL-3.0-or-later"

!define MUI_ICON "${ICONE}"
!define MUI_UNICON "${ICONE}"
!define MUI_ABORTWARNING
!define MUI_FINISHPAGE_RUN
!define MUI_FINISHPAGE_RUN_FUNCTION AbrirAgora
!define MUI_FINISHPAGE_RUN_TEXT "$(TXT_ABRIR)"
!insertmacro MUI_PAGE_INSTFILES
!insertmacro MUI_PAGE_FINISH
!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES

; sem português nem espanhol no Windows da pessoa, a primeira da lista vale
!insertmacro MUI_LANGUAGE "English"
!insertmacro MUI_LANGUAGE "PortugueseBR"
!insertmacro MUI_LANGUAGE "Spanish"

LangString TXT_ABRIR ${LANG_ENGLISH} "Open Financeiro"
LangString TXT_ABRIR ${LANG_PORTUGUESEBR} "Abrir o Financeiro"
LangString TXT_ABRIR ${LANG_SPANISH} "Abrir Financeiro"
LangString TXT_MAIS_NOVA ${LANG_ENGLISH} "A newer version of Financeiro is already installed: $R0. Nothing was changed."
LangString TXT_MAIS_NOVA ${LANG_PORTUGUESEBR} "Já está instalada uma versão mais nova do Financeiro: $R0. Nada foi alterado."
LangString TXT_MAIS_NOVA ${LANG_SPANISH} "Ya está instalada una versión más nueva de Financeiro: $R0. No se cambió nada."
LangString TXT_APAGAR ${LANG_ENGLISH} "Also delete your data (entries, accounts and backups)?$\r$\n$\r$\nThis cannot be undone. If you choose No, they stay in $LOCALAPPDATA\Financeiro."
LangString TXT_APAGAR ${LANG_PORTUGUESEBR} "Apagar também os seus dados (lançamentos, contas e backups)?$\r$\n$\r$\nIsso não pode ser desfeito. Se escolher Não, eles ficam em $LOCALAPPDATA\Financeiro."
LangString TXT_APAGAR ${LANG_SPANISH} "¿Borrar también sus datos (movimientos, cuentas y copias de seguridad)?$\r$\n$\r$\nEsto no se puede deshacer. Si elige No, se quedan en $LOCALAPPDATA\Financeiro."

Function AbrirAgora
    Exec '"$INSTDIR\financeiro.exe"'
FunctionEnd

Section "Financeiro"
    ; uma versão já instalada: não volta para uma mais velha, e para antes de trocar os arquivos
    ${If} ${FileExists} "$INSTDIR\app\VERSION"
        FileOpen $R1 "$INSTDIR\app\VERSION" r
        FileRead $R1 $R0
        FileClose $R1
        ${WordReplace} "$R0" "$\r" "" "+" $R0
        ${WordReplace} "$R0" "$\n" "" "+" $R0
        ${VersionCompare} "$R0" "${VERSAO}" $R2
        ${If} $R2 == 1
            MessageBox MB_OK|MB_ICONINFORMATION "$(TXT_MAIS_NOVA)" /SD IDOK
            SetErrorLevel 2
            Quit
        ${EndIf}
        nsExec::Exec '"$INSTDIR\bin\php.exe" -n "$INSTDIR\gerente.php" parar'
        Pop $R1
    ${EndIf}

    RMDir /r "$INSTDIR\app"
    RMDir /r "$INSTDIR\app.antes"
    RMDir /r "$INSTDIR\app.novo"
    RMDir /r "$INSTDIR\bin"
    SetOutPath "$INSTDIR"
    File /r "${ORIGEM}\*"
    WriteUninstaller "$INSTDIR\desinstalar.exe"

    ; no início da sessão, em segundo plano e sem janela
    WriteRegStr HKCU "${CHAVE_INICIO}" "Financeiro" '"$INSTDIR\financeiro.exe" servir'
    WriteRegStr HKCU "${CHAVE_APP}" "DisplayName" "Financeiro"
    WriteRegStr HKCU "${CHAVE_APP}" "DisplayVersion" "${VERSAO}"
    WriteRegStr HKCU "${CHAVE_APP}" "Publisher" "Hunter"
    WriteRegStr HKCU "${CHAVE_APP}" "DisplayIcon" "$INSTDIR\financeiro.exe"
    WriteRegStr HKCU "${CHAVE_APP}" "InstallLocation" "$INSTDIR"
    WriteRegStr HKCU "${CHAVE_APP}" "UninstallString" '"$INSTDIR\desinstalar.exe"'
    WriteRegStr HKCU "${CHAVE_APP}" "QuietUninstallString" '"$INSTDIR\desinstalar.exe" /S'
    WriteRegDWORD HKCU "${CHAVE_APP}" "NoModify" 1
    WriteRegDWORD HKCU "${CHAVE_APP}" "NoRepair" 1
    ${GetSize} "$INSTDIR" "/S=0K" $R0 $R1 $R2
    IntFmt $R0 "0x%08X" $R0
    WriteRegDWORD HKCU "${CHAVE_APP}" "EstimatedSize" $R0

    CreateShortcut "$SMPROGRAMS\Financeiro.lnk" "$INSTDIR\financeiro.exe"
    CreateShortcut "$DESKTOP\Financeiro.lnk" "$INSTDIR\financeiro.exe"

    Exec '"$INSTDIR\financeiro.exe" iniciar'
SectionEnd


Section "Uninstall"
    nsExec::Exec '"$INSTDIR\bin\php.exe" -n "$INSTDIR\gerente.php" parar'
    Pop $R1
    DeleteRegValue HKCU "${CHAVE_INICIO}" "Financeiro"
    DeleteRegKey HKCU "${CHAVE_APP}"
    Delete "$SMPROGRAMS\Financeiro.lnk"
    Delete "$DESKTOP\Financeiro.lnk"
    RMDir /r "$INSTDIR"
    ; sem janela (/S), os dados sempre ficam; só /APAGAR_DADOS apaga
    ${GetParameters} $R0
    ClearErrors
    ${GetOptions} "$R0" "/APAGAR_DADOS" $R1
    ${IfNot} ${Errors}
        RMDir /r "$LOCALAPPDATA\Financeiro"
    ${ElseIfNot} ${Silent}
        MessageBox MB_YESNO|MB_ICONQUESTION|MB_DEFBUTTON2 "$(TXT_APAGAR)" IDNO fim
        RMDir /r "$LOCALAPPDATA\Financeiro"
        fim:
    ${EndIf}
SectionEnd
