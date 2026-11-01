<?php
namespace Financeiro\Controllers;

use Financeiro\Auth;
use Financeiro\Models\UsuarioModel;
use Financeiro\Temas;
use Financeiro\View;

/** Tela Temas: cartões com a prévia de cada tema. O tema escolhido fica na conta de cada um. */
final class TemaController
{
    public function index(): void
    {
        Auth::requireLogin();
        View::render('temas/index', ['pageTitle' => 'temas · financeiro', 'temas' => Temas::todos(), 'atual' => Temas::doUsuario()], 'temas');
    }

    /** POST /temas: `auto` volta a seguir o aparelho. Com silencioso=1 (o atalho claro/escuro) não muda de página. */
    public function salvar(): void
    {
        Auth::requireLogin();
        csrfCheck();
        $pedido = (string) ($_POST['tema'] ?? '');
        if ($pedido !== 'auto' && !Temas::existe($pedido)) {
            flash('error', 'Esse tema não existe.');
            redirecionar('/temas', 303);
        }
        UsuarioModel::salvarTema((int) Auth::id(), $pedido === 'auto' ? null : $pedido);
        if (($_POST['silencioso'] ?? '') === '1') {
            http_response_code(204);
            return;
        }
        flash('success', 'Tema salvo: ' . ($pedido === 'auto' ? 'Automático' : Temas::todos()[$pedido]['nome']));
        redirecionar('/temas', 303);
    }
}
