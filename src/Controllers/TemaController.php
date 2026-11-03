<?php
namespace Financeiro\Controllers;

use Financeiro\Auth;
use Financeiro\Models\UsuarioModel;
use Financeiro\Temas;
use Financeiro\View;

/** Tela Temas: as paletas, cada uma em claro, escuro ou automático. O tema escolhido fica na conta de cada um. */
final class TemaController
{
    public function index(): void
    {
        Auth::requireLogin();
        View::render('temas/index', ['pageTitle' => t('temas · MyMonetto'), 'paletas' => Temas::paletas(), 'atual' => Temas::doUsuario()], 'temas');
    }

    /** POST /temas: `auto` volta à paleta padrão, seguindo o aparelho. Com silencioso=1 responde 204, sem mudar de página. */
    public function salvar(): void
    {
        Auth::requireLogin();
        csrfCheck();
        $pedido = (string) ($_POST['tema'] ?? '');
        if ($pedido !== 'auto' && !Temas::existe($pedido)) {
            flash('error', t('Esse tema não existe.'));
            redirecionar('/temas', 303);
        }
        UsuarioModel::salvarTema((int) Auth::id(), $pedido === 'auto' ? null : $pedido);
        if (($_POST['silencioso'] ?? '') === '1') {
            http_response_code(204);
            return;
        }
        flash('success', t('Tema salvo: {nome}', ['nome' => Temas::nome($pedido === 'auto' ? null : $pedido)]));
        redirecionar('/temas', 303);
    }
}
