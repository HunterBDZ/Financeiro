<?php
namespace Financeiro\Controllers;

use Financeiro\Ajuda;
use Financeiro\Auth;
use Financeiro\View;

final class AjudaController
{
    public function index(): void
    {
        Auth::requireLogin();
        View::render('ajuda/index', ['pageTitle' => t('ajuda · financeiro'), 'grupos' => Ajuda::grupos()], 'ajuda');
    }

    /** O aviso de certificado aparece antes de entrar, então esta resposta abre sem login. */
    public function conexao(): void
    {
        View::renderAuth('ajuda/conexao');
    }
}
