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
        View::render('temas/index', ['pageTitle' => t('temas · financeiro'), 'temas' => Temas::todos(), 'atual' => Temas::doUsuario()], 'temas');
    }

    /** POST /temas: `auto` volta a seguir o aparelho. Com silencioso=1 (o atalho claro/escuro) não muda de página. */
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
        // Só os nomes descritivos têm tradução; os próprios (Nord, Dracula, Gruvbox...) passam como estão.
        // i18n: t('Gruvbox escuro') t('Gruvbox claro') t('Solarized escuro') t('Solarized claro') t('Everforest escuro') t('Everforest claro') t('Automático') t('Claro') t('Escuro') t('Noite') t('Terminal') t('Âmbar') t('Portátil') t('Janela 95') t('Papel') t('Caramelo') t('Calçadão') t('Azulejo') t('Tropical') t('Cordel') t('Festa Junina') t('Alto contraste escuro') t('Alto contraste claro') t('Daltônico')
        flash('success', t('Tema salvo: {nome}', ['nome' => $pedido === 'auto' ? t('Automático') : t(Temas::todos()[$pedido]['nome'])]));
        redirecionar('/temas', 303);
    }
}
