/* O executável do .app: chama o script. O Mac notariza melhor um binário aqui do que um script. */
#include <libgen.h>
#include <limits.h>
#include <mach-o/dyld.h>
#include <stdio.h>
#include <stdlib.h>
#include <unistd.h>

int main(void)
{
    char caminho[PATH_MAX], real[PATH_MAX], script[PATH_MAX];
    uint32_t tamanho = sizeof caminho;
    if (_NSGetExecutablePath(caminho, &tamanho) != 0 || realpath(caminho, real) == NULL) {
        return 1;
    }
    snprintf(script, sizeof script, "%s/../Resources/financeiro", dirname(real));
    execl("/bin/sh", "sh", script, (char *) NULL);
    return 1;
}
