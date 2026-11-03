<?php
namespace Financeiro\Controllers;

use Financeiro\Auth;
use Financeiro\DuasEtapas;
use Financeiro\Models\UsuarioModel;
use Financeiro\QrCode;
use Financeiro\View;

/**
 * Ligar e desligar a verificação em duas etapas, a partir de Ajustes. Ligar: o QR e a chave (o segredo novo fica na
 * sessão até a pessoa confirmar um código), depois os códigos de recuperação, uma vez só. Desligar: senha e código.
 */
final class DuasEtapasController
{
    private const NOVO = 'duas_etapas_novo';
    private const CODIGOS = 'duas_etapas_codigos';
    private const VOLTA = '/ajustes?aberto=duasetapas#duasetapas';

    public function index(): void
    {
        Auth::requireLogin();
        $id = (int) Auth::id();
        if (!DuasEtapas::disponivel() || DuasEtapas::ligada($id)) {
            redirecionar(self::VOLTA, 302);
        }
        $segredo = self::segredoNovo();
        $login = (string) (UsuarioModel::find($id)['login'] ?? '');
        $erro = $_SESSION['duas_etapas_erro'] ?? null;
        unset($_SESSION['duas_etapas_erro']);
        View::render('ajustes/duas_etapas', [
            'pageTitle' => t('verificação em duas etapas · MyMonetto'),
            'qr'        => QrCode::svg(DuasEtapas::enderecoQr($segredo, $login), t('Código QR para o app autenticador')),
            'chave'     => DuasEtapas::chaveParaDigitar($segredo),
            'erro'      => is_string($erro) ? $erro : null,
        ], 'ajustes');
    }

    public function ligar(): void
    {
        Auth::requireLogin();
        csrfCheck();
        $id = (int) Auth::id();
        if (!DuasEtapas::disponivel() || DuasEtapas::ligada($id)) {
            redirecionar(self::VOLTA, 303);
        }
        $segredo = self::segredoNovo();
        $codigo = DuasEtapas::limpar(is_string($_POST['codigo'] ?? null) ? $_POST['codigo'] : '');
        $passo = DuasEtapas::conferirCodigo($segredo, $codigo, DuasEtapas::passo(), null);
        if ($passo === null) {
            $_SESSION['duas_etapas_erro'] = t('O código não confere. Confira se o app mostra o MyMonetto e digite o código que está na tela agora.');
            redirecionar('/ajustes/duas-etapas', 303);
        }
        try {
            $codigos = DuasEtapas::ligar($id, $segredo, $passo);
        } catch (\RuntimeException $e) {
            error_log('duas etapas: ' . $e->getMessage());
            $_SESSION['duas_etapas_erro'] = t('Não deu para guardar a configuração neste computador. Nada mudou: avise quem instalou o app.');
            redirecionar('/ajustes/duas-etapas', 303);
        }
        unset($_SESSION[self::NOVO]);
        // os outros aparelhos entraram só com a senha: saem e, na volta, pedem o código. Este continua lembrado.
        $lembrado = isset($_COOKIE[Auth::COOKIE_LEMBRAR]);
        Auth::sairDeTodos($id);
        if ($lembrado) {
            Auth::lembrar($id);
        }
        $_SESSION[self::CODIGOS] = $codigos;
        redirecionar('/ajustes/duas-etapas/codigos', 303);
    }

    /** Os códigos de recuperação aparecem uma vez só, logo depois de ligar. */
    public function codigos(): void
    {
        Auth::requireLogin();
        $codigos = $_SESSION[self::CODIGOS] ?? null;
        unset($_SESSION[self::CODIGOS]);
        if (!is_array($codigos) || $codigos === []) {
            redirecionar(self::VOLTA, 302);
        }
        header('Cache-Control: no-store');
        View::render('ajustes/duas_etapas_codigos', [
            'pageTitle' => t('códigos de recuperação · MyMonetto'),
            'codigos'   => array_map('strval', $codigos),
        ], 'ajustes');
    }

    public function desligar(): void
    {
        Auth::requireLogin();
        csrfCheck();
        $id = (int) Auth::id();
        if (!DuasEtapas::ligada($id)) {
            redirecionar(self::VOLTA, 303);
        }
        $senha = is_string($_POST['senha'] ?? null) ? $_POST['senha'] : '';
        $codigo = is_string($_POST['codigo'] ?? null) ? $_POST['codigo'] : '';
        // os erros contam à parte do login: 5 bloqueiam só esta ação e a troca de senha, por alguns minutos
        $conferida = Auth::conferirSenhaECodigo($id, $senha, $codigo);
        if ($conferida !== 'ok') {
            $msg = $conferida === 'bloqueado' ? AjustesController::muitasTentativas() : t('A senha ou o código não conferem.');
            $_SESSION['aj_erro'] = ['grupo' => 'duasetapas', 'msg' => $msg];
            redirecionar(self::VOLTA, 303);
        }
        DuasEtapas::desligar($id);
        flash('success', t('Verificação em duas etapas desligada.'));
        redirecionar(self::VOLTA, 303);
    }

    /** O segredo que está sendo ligado: o mesmo até a pessoa confirmar, mesmo se a página recarregar. */
    private static function segredoNovo(): string
    {
        $guardado = $_SESSION[self::NOVO] ?? null;
        $segredo = is_string($guardado) ? DuasEtapas::deBase32($guardado) : null;
        if ($segredo === null || strlen($segredo) !== 20) {
            $segredo = DuasEtapas::novoSegredo();
            $_SESSION[self::NOVO] = DuasEtapas::base32($segredo);
        }
        return $segredo;
    }
}
