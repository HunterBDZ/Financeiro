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
        http_response_code(self::gravar((int) Auth::id(), $_POST));
    }

    /** Grava o pedido e diz o código da resposta. À parte porque o PHP de linha de comando não lê o código depois da 1ª saída. */
    public static function gravar(int $id, array $post): int
    {
        if (($post['padrao'] ?? '') === '1') {
            Menu::salvar($id, null);
            return 204;
        }
        $nova = Menu::validar($post['ordem'] ?? null);
        if ($nova === null) {
            return 422;
        }
        Menu::salvar($id, Menu::mesclar(Menu::daConta($id), $nova));
        return 204;
    }
}
