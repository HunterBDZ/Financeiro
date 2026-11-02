/*
 * financeiro.exe: o que o atalho e o início automático chamam, sem abrir janela preta. O php.exe é um programa de
 * console: iniciado daqui, com CREATE_NO_WINDOW, ele e tudo que ele inicia dividem um console que nunca aparece.
 * O php-win.exe não serve: ele não tem console, e cada programa que inicia abriria o próprio.
 * COMANDO é o que vale sem argumento: abrir, ou servir no pacote da Microsoft Store.
 */
#define WIN32_LEAN_AND_MEAN
#include <windows.h>
#include <stdio.h>
#include <wchar.h>

#ifndef COMANDO
#define COMANDO L"abrir"
#endif

int WINAPI wWinMain(HINSTANCE i, HINSTANCE a, PWSTR args, int mostrar)
{
    static wchar_t pasta[32768], bin[32768], linha[32768];
    STARTUPINFOW si = {sizeof si};
    PROCESS_INFORMATION pi;
    wchar_t *barra;
    (void) i; (void) a; (void) mostrar;
    if (GetModuleFileNameW(NULL, pasta, 32768) == 0 || (barra = wcsrchr(pasta, L'\\')) == NULL) {
        return 1;
    }
    *barra = 0;
    swprintf(bin, 32768, L"%ls\\bin", pasta);
    swprintf(linha, 32768, L"\"%ls\\php.exe\" -n \"%ls\\gerente.php\" %ls", bin, pasta, args != NULL && *args != 0 ? args : COMANDO);
    if (!CreateProcessW(NULL, linha, NULL, NULL, FALSE, CREATE_NO_WINDOW, NULL, bin, &si, &pi)) {
        return 1;
    }
    CloseHandle(pi.hThread);
    CloseHandle(pi.hProcess);
    return 0;
}
