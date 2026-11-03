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
        View::render('ajuda/index', ['pageTitle' => t('ajuda · MyMonetto'), 'grupos' => Ajuda::grupos()], 'ajuda');
    }

    /** O navegador pede /favicon.ico por conta própria: leva ao ícone escolhido em Ajustes (ou ao padrão). */
    public function favicon(): void
    {
        header('Cache-Control: public, max-age=86400');
        header('Location: ' . \Financeiro\SiteIcone::url(), true, 302);
    }

    /** Privacidade, aviso e contato: abre sem login, para valer também na tela de entrar. */
    public function privacidade(): void
    {
        View::renderAuth('ajuda/privacidade', ['repositorio' => 'https://github.com/' . \Financeiro\Atualizacao::repositorio()]);
    }

    /** O aviso de certificado aparece antes de entrar, então esta resposta abre sem login. */
    public function conexao(): void
    {
        View::renderAuth('ajuda/conexao');
    }
}
