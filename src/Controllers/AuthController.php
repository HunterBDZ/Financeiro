<?php
namespace Financeiro\Controllers;

use Financeiro\Auth;
use Financeiro\Models\UsuarioModel;
use Financeiro\Primeira;
use Financeiro\View;

final class AuthController
{
    public function form(): void
    {
        if (UsuarioModel::nenhum()) {
            redirecionar('/criar-conta', 302);
        }
        if (Auth::logado() || Auth::tentarLembrado()) {
            redirecionar(self::destino());
        }
        View::renderAuth('auth/login', [
            'erro'    => null,
            'expirou' => isset($_GET['expirou']),
            'login'   => '',
            'lembrar' => false,
        ]);
    }

    /** A primeira conta do site. Existe só enquanto não há nenhuma; depois, novas contas nascem em Ajustes. */
    public function criarContaForm(): void
    {
        if (!UsuarioModel::nenhum()) {
            redirecionar('/login', 302);
        }
        self::exigirContaPermitida();
        if (Primeira::codigoExigido()) {
            Primeira::codigo();     // cria e escreve no log, se ainda não existe
        }
        View::renderAuth('auth/criar', ['erro' => null, 'nome' => '', 'login' => '', 'pedeCodigo' => Primeira::codigoExigido()]);
    }

    public function criarConta(): void
    {
        self::exigirContaPermitida();
        csrfCheck();

        $nome = is_string($_POST['nome'] ?? null) ? mb_substr(trim($_POST['nome']), 0, 100) : '';
        $login = is_string($_POST['login'] ?? null) ? mb_substr(trim($_POST['login']), 0, 100) : '';
        $senha = is_string($_POST['senha'] ?? null) ? $_POST['senha'] : '';
        $codigo = is_string($_POST['codigo'] ?? null) ? mb_substr($_POST['codigo'], 0, 40) : '';
        $pedeCodigo = Primeira::codigoExigido();

        // a trava impede duas primeiras contas ao mesmo tempo
        if (!Auth::travarSenha()) {
            renderErro(429, t('Muitas tentativas'), t('Tente de novo em instantes.'));
        }
        try {
            if (!UsuarioModel::nenhum()) {
                redirecionar('/login', 303);
            }
            $erro = $pedeCodigo && !Primeira::codigoConfere($codigo)
                ? t('O código de instalação não confere. Ele aparece no fim da instalação ou no log do servidor.')
                : UsuarioModel::erroNovaConta($nome, $login, $senha);
            if ($erro === null) {
                UsuarioModel::criar($nome, $login, $senha);
                if ($pedeCodigo) {
                    Primeira::esquecerCodigo();
                }
            }
        } finally {
            Auth::soltarSenha();
        }
        if ($erro !== null) {
            http_response_code(422);
            View::renderAuth('auth/criar', ['erro' => $erro, 'nome' => $nome, 'login' => $login, 'pedeCodigo' => $pedeCodigo]);
            return;
        }
        Auth::attempt($login, $senha);
        redirecionar('/comecar', 303);
    }

    private static function exigirContaPermitida(): void
    {
        if (!Primeira::contaPermitida()) {
            renderErro(403, t('Crie a conta no próprio computador'), t('Abra o Financeiro no computador onde ele está instalado para criar a primeira conta. Depois, os outros aparelhos podem entrar'));
        }
    }

    public function login(): void
    {
        csrfCheck();

        $login   = is_string($_POST['login'] ?? null) ? trim($_POST['login']) : '';
        $senha   = is_string($_POST['senha'] ?? null) ? $_POST['senha'] : '';
        $lembrar = ($_POST['lembrar'] ?? '') === '1';
        $login   = mb_substr($login, 0, 100);

        $tela = ['erro' => null, 'expirou' => false, 'login' => $login, 'lembrar' => $lembrar];

        if ($login === '' || $senha === '') {
            http_response_code(401);
            $tela['erro'] = t('Login ou senha incorretos.');
            View::renderAuth('auth/login', $tela);
            return;
        }

        // conferir bloqueio → testar senha → registrar falha, um login de cada vez: sem a
        // trava, uma rajada em paralelo passava toda pela conferência antes da 1ª falha gravar
        if (!Auth::travarSenha()) {
            $bloqueado = 60;
            $ok = false;
        } else {
            try {
                $bloqueado = Auth::segundosBloqueado($login);
                $ok = $bloqueado === 0 && Auth::attempt($login, $senha);
            } finally {
                Auth::soltarSenha();
            }
        }
        if ($bloqueado > 0) {
            http_response_code(429);
            $tela['erro'] = t('Muitas tentativas. Tente de novo em {min} min.', ['min' => (int) ceil($bloqueado / 60)]);
            View::renderAuth('auth/login', $tela);
            return;
        }
        if (!$ok) {
            http_response_code(401);
            $tela['erro'] = t('Login ou senha incorretos.');
            View::renderAuth('auth/login', $tela);
            return;
        }

        if ($lembrar) {
            Auth::lembrar((int) Auth::id());
        }
        redirecionar(self::destino());
    }

    /**
     * "sair" SEMPRE sai e esquece este aparelho — sem exigir o token CSRF. Com a sessão de 8 h já
     * vencida não há token na sessão, e barrar aqui (403 "sessão expirada") deixava o cookie de
     * lembrar de 60 dias valendo: o próximo a abrir o app entrava logado. Os dois cookies são
     * SameSite=Strict, então um POST forjado de outro site chega sem eles e não derruba ninguém;
     * o pior que um POST válido faz é o que o botão promete.
     */
    public function logout(): void
    {
        csrfCheck();
        Auth::logout();
        redirecionar('/login');
    }

    /** Para onde ir depois de entrar: a página pedida antes do login (só caminho local). */
    private static function destino(): string
    {
        $next = $_SESSION['next'] ?? '/';
        unset($_SESSION['next']);
        $next = voltaSegura(is_string($next) ? $next : null, '/');
        return str_starts_with($next, '/login') || str_starts_with($next, '/logout') ? '/' : $next;
    }
}
