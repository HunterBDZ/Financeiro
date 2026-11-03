<?php
namespace Financeiro\Controllers;

use Financeiro\Auth;
use Financeiro\Idioma;
use Financeiro\Models\UsuarioModel;

/** Troca o idioma: na conta de quem entrou e num cookie, que vale também na tela de login. */
final class IdiomaController
{
    public function salvar(): void
    {
        csrfCheck();
        $pedido = (string) ($_POST['idioma'] ?? '');
        $id = Auth::id();
        $volta = $id !== null ? '/ajustes?aberto=idioma#idioma' : '/login';
        if (!Idioma::existe($pedido)) {
            redirecionar($volta, 303);
        }
        if ($id !== null) {
            UsuarioModel::salvarIdioma($id, $pedido);
        }
        setcookie(Idioma::COOKIE, $pedido, [
            'expires' => time() + 31536000, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => true, 'samesite' => 'Lax',
        ]);
        Idioma::usar($pedido);
        flash('success', t('Idioma salvo.'));
        redirecionar($volta, 303);
    }
}
