<?php
namespace Financeiro\Controllers;

use Financeiro\Auth;
use Financeiro\Relogio;
use Financeiro\Services\Investimentos;
use Financeiro\View;

/** Aba Investimentos: poupança e outros investimentos, somados a partir dos lançamentos do Extrato. */
final class InvestimentosController
{
    public function index(): void
    {
        Auth::requireLogin();
        View::render('investimentos/index', [
            'pageTitle' => t('investimentos · financeiro'),
            'resumo' => Investimentos::resumo(Relogio::hoje()),
        ], 'investimentos');
    }
}
