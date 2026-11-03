<?php
namespace Financeiro\Controllers;

use Financeiro\Auth;
use Financeiro\Menu;

/** A ordem das abas do menu, gravada na conta pelo modo organizar (menu.js). */
final class MenuController
{
    /**
     * POST /menu/ordem: `ordem[]` com as chaves à vista, na ordem nova; as outras ficam onde estavam.
     * `padrao=1` volta à ordem padrão. Responde 204; lista inválida, 422, e nada muda.
     */
    public function salvarOrdem(): void
    {
        Auth::requireLogin();
        csrfCheck();
        $id = (int) Auth::id();
        if (($_POST['padrao'] ?? '') === '1') {
            Menu::salvar($id, null);
            http_response_code(204);
            return;
        }
        $nova = Menu::validar($_POST['ordem'] ?? null);
        if ($nova === null) {
            http_response_code(422);
            return;
        }
        Menu::salvar($id, Menu::mesclar(Menu::daConta($id), $nova));
        http_response_code(204);
    }
}
