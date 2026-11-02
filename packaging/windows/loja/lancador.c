/* Só para a Microsoft Store, onde o início automático e o menu pedem um .exe do próprio pacote. COMANDO é abrir ou servir. */
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
    (void) i; (void) a; (void) args; (void) mostrar;
    if (GetModuleFileNameW(NULL, pasta, 32768) == 0 || (barra = wcsrchr(pasta, L'\\')) == NULL) {
        return 1;
    }
    *barra = 0;
    swprintf(bin, 32768, L"%ls\\bin", pasta);
    swprintf(linha, 32768, L"\"%ls\\php-win.exe\" -n \"%ls\\gerente.php\" %ls", bin, pasta, COMANDO);
    if (!CreateProcessW(NULL, linha, NULL, NULL, FALSE, CREATE_NO_WINDOW, NULL, bin, &si, &pi)) {
        return 1;
    }
    CloseHandle(pi.hThread);
    WaitForSingleObject(pi.hProcess, INFINITE);
    CloseHandle(pi.hProcess);
    return 0;
}
