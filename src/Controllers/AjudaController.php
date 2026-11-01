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
}
