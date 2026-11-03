<?php
namespace Financeiro;

use Financeiro\Models\SessaoLembradaModel;
use Financeiro\Models\UsuarioModel;

/**
 * Login, sessão e "lembrar este aparelho".
 *
 * Cookie `financeiro_lembrar` = "seletor:validador" (12 + 32 bytes aleatórios, em hex).
 * No banco fica só sha256(validador), comparado com hash_equals. Cada uso rotaciona o
 * validador; o anterior ainda vale por 60 s (duas abas restaurando juntas), sem nova
 * rotação e sem Set-Cookie (o navegador já recebeu o atual na outra resposta). Qualquer
 * outro validador para um seletor conhecido = cookie roubado e reusado: apaga todos os
 * aparelhos do usuário e derruba as sessões vivas dele.
 *
 * Sessões vivas: todo login grava $_SESSION['sessao_versao']; requireLogin() compara com
 * usuarios.sessao_versao (1 SELECT por página). sairDeTodos() incrementa a versão.
 *
 * Verificação em duas etapas (DuasEtapas): depois da senha, primeiroPasso() deixa a pessoa esperando o código, e
 * segundoPasso() entra. O aparelho lembrado entra sem o código, porque o cookie nasceu de um login completo.
 */
final class Auth
{
    private const MAX_TENTATIVAS = 5;
    private const BLOQUEIO_MINUTOS = 10;
    /** Quem já está logado e erra a senha ou o código (trocar senha, desligar as duas etapas): um bloqueio mais curto. */
    private const ACAO_BLOQUEIO_MINUTOS = 5;

    public const COOKIE_LEMBRAR = 'financeiro_lembrar';

    /** Na sessão: quem acertou a senha e ainda deve o código da verificação em duas etapas. */
    private const PENDENTE = 'duas_etapas_pendente';
    private const PENDENTE_SEGUNDOS = 300;

    /** Resultado da conferência de versão nesta requisição (null = ainda não conferiu). */
    private static ?bool $versaoOk = null;

    /** Esta requisição entrou pelo cookie `financeiro_lembrar` (sessão nova, token CSRF novo). */
    private static bool $restaurado = false;

    /** Entra só com a senha. Com a verificação em duas etapas ligada, não entra: veja primeiroPasso(). */
    public static function attempt(string $login, string $senha): bool
    {
        return self::primeiroPasso($login, $senha, false) === 'entrou';
    }

    /**
     * A senha, primeira parte do login. Chamar com a trava (travarSenha) e depois de conferir o bloqueio.
     * 'errada' | 'entrou' | 'codigo': a senha confere, e a conta pede o código do app autenticador. Nesse caso, a
     * sessão guarda quem está entrando por 5 minutos, e as tentativas erradas não zeram: elas só zeram com o código
     * certo, senão quem tem a senha ganharia 5 chutes a cada vez que a digitasse de novo.
     */
    public static function primeiroPasso(string $login, string $senha, bool $lembrar): string
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, nome, senha_hash, sessao_versao FROM usuarios WHERE login = :login LIMIT 1');
        $stmt->execute(['login' => $login]);
        $usuario = $stmt->fetch();

        if (!$usuario || !password_verify($senha, $usuario['senha_hash'])) {
            // mesma mensagem/tempo pra login inexistente ou senha errada, evita enumeração de usuário
            usleep(random_int(150000, 300000));
            self::registrarFalha($login);
            return 'errada';
        }

        if (DuasEtapas::ligada((int) $usuario['id'])) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            $_SESSION[self::PENDENTE] = ['id' => (int) $usuario['id'], 'login' => $login, 'lembrar' => $lembrar, 'ate' => time() + self::PENDENTE_SEGUNDOS];
            return 'codigo';
        }

        self::limparTentativas($login);
        self::entrar($usuario);
        return 'entrou';
    }

    /** Quem passou da senha e falta o código; null se ninguém, ou se passou de 5 minutos. */
    public static function esperandoCodigo(): ?array
    {
        $p = $_SESSION[self::PENDENTE] ?? null;
        if (!is_array($p) || !isset($p['id'], $p['login'], $p['ate']) || (int) $p['ate'] < time()) {
            unset($_SESSION[self::PENDENTE]);
            return null;
        }
        return $p;
    }

    public static function esquecerCodigo(): void
    {
        unset($_SESSION[self::PENDENTE]);
    }

    /**
     * O código do app autenticador, ou um de recuperação, segunda parte do login. Conta no mesmo limite da senha.
     * 'entrou' | 'errado' | 'bloqueado' | 'expirou'.
     */
    public static function segundoPasso(string $codigo, ?int $instante = null): string
    {
        $p = self::esperandoCodigo();
        if ($p === null) {
            return 'expirou';
        }
        $login = (string) $p['login'];
        if (!self::travarSenha()) {
            return 'bloqueado';
        }
        try {
            if (self::segundosBloqueado($login) > 0) {
                self::esquecerCodigo();
                return 'bloqueado';
            }
            if (!DuasEtapas::conferirDaConta((int) $p['id'], $codigo, $instante)) {
                usleep(random_int(150000, 300000));
                self::registrarFalha($login);
                if (self::segundosBloqueado($login) > 0) {
                    self::esquecerCodigo();
                    return 'bloqueado';
                }
                return 'errado';
            }
            $stmt = Database::connection()->prepare('SELECT id, nome, sessao_versao FROM usuarios WHERE id = ?');
            $stmt->execute([(int) $p['id']]);
            $usuario = $stmt->fetch();
            self::esquecerCodigo();
            if (!$usuario) {
                return 'expirou';
            }
            self::limparTentativas($login);
            self::entrar($usuario);
            return 'entrou';
        } finally {
            self::soltarSenha();
        }
    }

    /**
     * Senha e código de quem já está logado (desligar a verificação), contados juntos no limite das ações de quem está
     * logado (chaveDaAcao): só zera se os dois conferem. O código só é testado com a senha certa, para não gastar um
     * de recuperação à toa. 'ok' | 'errada' | 'bloqueado'.
     */
    public static function conferirSenhaECodigo(int $usuarioId, string $senha, string $codigo, ?int $instante = null): string
    {
        return self::conferirLogado($usuarioId, static fn(string $hash): bool => password_verify($senha, $hash) && DuasEtapas::conferirDaConta($usuarioId, $codigo, $instante));
    }

    /**
     * A conferência de quem já está logado, com o limite próprio: os erros contam à parte do login (chaveDaAcao), e
     * o bloqueio, de 5 minutos, vale só para trocar a senha e desligar as duas etapas. Assim, quem chuta a senha na
     * tela de entrar não derruba a sessão do dono, nem o impede de mudar a própria conta; e quem achou a sessão aberta
     * também não chuta a senha à vontade. 'ok' | 'errada' | 'bloqueado'.
     * @param callable(string): bool $confere recebe o hash da senha guardada
     */
    private static function conferirLogado(int $usuarioId, callable $confere): string
    {
        $hash = UsuarioModel::senhaHash($usuarioId);
        if (UsuarioModel::find($usuarioId) === null || $hash === null) {
            return 'errada';
        }
        $chave = self::chaveDaAcao($usuarioId);
        if (!self::travarSenha()) {
            return 'bloqueado';
        }
        try {
            if (self::segundosBloqueado($chave) > 0) {
                return 'bloqueado';
            }
            if ($confere($hash)) {
                self::limparTentativas($chave);
                return 'ok';
            }
            usleep(random_int(150000, 300000));
            self::registrarFalha($chave, self::ACAO_BLOQUEIO_MINUTOS);
            return self::segundosBloqueado($chave) > 0 ? 'bloqueado' : 'errada';
        } finally {
            self::soltarSenha();
        }
    }

    /**
     * Onde contam os erros de quem já está logado, em login_tentativas: começa com um caractere de controle, que a
     * tela de entrar tira do login digitado (AuthController::login). Ninguém de fora chega a esse contador.
     */
    public static function chaveDaAcao(int $usuarioId): string
    {
        return "\x1F" . 'logado:' . $usuarioId;
    }

    /** Minutos que faltam no bloqueio das ações de quem está logado (0 = livre). */
    public static function minutosBloqueadoLogado(int $usuarioId): int
    {
        return (int) ceil(self::segundosBloqueado(self::chaveDaAcao($usuarioId)) / 60);
    }

    /**
     * Serializa conferências de senha (login e troca de senha) com uma trava nomeada: conferir o
     * bloqueio, testar a senha e registrar a falha viram uma coisa só, e rajadas em
     * paralelo não passam das 5 tentativas. Um nome só (app de 2 usuários; também cobre
     * variações de caixa/acento do mesmo login). false = não conseguiu a vez (tratar como
     * bloqueado). A trava cai sozinha quando a conexão fecha.
     */
    public static function travarSenha(): bool
    {
        return Sql::travar(Database::connection(), 'financeiro_senha', 10);
    }

    public static function soltarSenha(): void
    {
        Sql::soltar(Database::connection(), 'financeiro_senha');
    }

    /**
     * Senha atual de quem já está logado (troca de senha, a senha de outra pessoa), com o limite das ações de quem
     * está logado (conferirLogado). 'ok' | 'errada' | 'bloqueado'.
     */
    public static function conferirSenhaAtual(int $usuarioId, string $senha): string
    {
        return self::conferirLogado($usuarioId, static fn(string $hash): bool => password_verify($senha, $hash));
    }

    /** Segundos restantes de bloqueio por tentativas erradas (0 = não bloqueado). */
    public static function segundosBloqueado(string $login): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT ' . Sql::maior('0', Sql::segundosAte('bloqueado_ate')) . ' FROM login_tentativas
             WHERE login = :login AND bloqueado_ate IS NOT NULL'
        );
        $stmt->execute(['login' => $login]);
        return max(0, (int) $stmt->fetchColumn());
    }

    private static function registrarFalha(string $login, int $minutos = self::BLOQUEIO_MINUTOS): void
    {
        // bloqueado_ate vem ANTES de tentativas: assim a conta usa o valor antigo em todo modo de
        // atribuição (MySQL da esquerda pra direita; MariaDB SIMULTANEOUS_ASSIGNMENT; SQLite, valores
        // antigos) e o bloqueio cai exatamente na 5ª falha.
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO login_tentativas (login, tentativas, bloqueado_ate) VALUES (:login, 1, NULL)
             ' . Sql::aoRepetir('login', [
                'bloqueado_ate' => Sql::se('tentativas + 1 >= :max', Sql::agoraMais($minutos, 'MINUTE'), 'bloqueado_ate'),
                'tentativas'    => Sql::menor('tentativas + 1', '250'),
            ])
        )->execute(['login' => $login, 'max' => self::MAX_TENTATIVAS]);
    }

    private static function limparTentativas(string $login): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM login_tentativas WHERE login = :login')->execute(['login' => $login]);
    }

    /** Login de verdade (senha ou cookie): id de sessão novo, usuário e versão na sessão. */
    private static function entrar(array $usuario): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        unset($_SESSION['sessao_expirou']);
        $_SESSION['usuario_id']    = (int) $usuario['id'];
        $_SESSION['usuario_nome']  = (string) $usuario['nome'];
        $_SESSION['sessao_versao'] = (int) $usuario['sessao_versao'];
        self::$versaoOk = true;
        UsuarioModel::marcarLogin((int) $usuario['id']);
    }

    /* -------------------------------------------------------------- lembrar este aparelho */

    /**
     * Entra pelo cookie `financeiro_lembrar`, se houver um válido. Chamado por requireLogin()
     * (sem sessão) e por AuthController::form(). Nunca lança: cookie ruim = false.
     */
    public static function tentarLembrado(): bool
    {
        $bruto = $_COOKIE[self::COOKIE_LEMBRAR] ?? null;
        if (!is_string($bruto) || $bruto === '') {
            return false;
        }
        if (!preg_match('/^([0-9a-f]{24}):([0-9a-f]{64})$/D', $bruto, $m)) {
            self::apagarCookie();
            return false;
        }
        [, $seletor, $validador] = $m;

        $linha = SessaoLembradaModel::buscar($seletor);
        if ($linha === null) {                          // revogado, expirado e limpo, ou inventado
            self::apagarCookie();
            return false;
        }
        if ((int) $linha['resta_seg'] <= 0) {
            SessaoLembradaModel::apagar($seletor);
            self::apagarCookie();
            return false;
        }

        $hash = hash('sha256', $validador);

        if (hash_equals((string) $linha['validador_hash'], $hash)) {
            $novo = bin2hex(random_bytes(32));
            if (SessaoLembradaModel::rotacionar($seletor, $hash, hash('sha256', $novo))) {
                self::gravarCookie($seletor . ':' . $novo, time() + (int) $linha['resta_seg']);
                self::entrar($linha);
                self::$restaurado = true;
                return true;
            }
            // outra requisição rotacionou entre o SELECT e o UPDATE: relê; este validador
            // agora é o anterior e cai na janela logo abaixo
            $linha = SessaoLembradaModel::buscar($seletor);
            if ($linha === null) {
                self::apagarCookie();
                return false;
            }
        }

        $anterior = $linha['validador_ant_hash'];
        if (is_string($anterior) && (int) $linha['na_janela'] === 1 && hash_equals($anterior, $hash)) {
            // duas abas ao mesmo tempo: aceito sem rotacionar de novo e sem Set-Cookie
            SessaoLembradaModel::marcarUso($seletor);
            self::entrar($linha);
            self::$restaurado = true;
            return true;
        }

        // seletor conhecido com validador velho fora da janela: o cookie foi copiado e usado
        // em outro lugar. Apaga todos os aparelhos do usuário e derruba as sessões dele.
        self::sairDeTodos((int) $linha['usuario_id']);
        self::apagarCookie();
        return false;
    }

    /** Cria o seletor:validador deste aparelho (cookie de 60 dias). */
    public static function lembrar(int $usuarioId): void
    {
        self::esquecerEsteAparelho();                   // um navegador = uma linha
        SessaoLembradaModel::limparExpiradas();

        $seletor   = bin2hex(random_bytes(12));
        $validador = bin2hex(random_bytes(32));
        SessaoLembradaModel::criar($seletor, hash('sha256', $validador), $usuarioId);
        self::gravarCookie($seletor . ':' . $validador, time() + SessaoLembradaModel::DIAS * 86400);
    }

    /** Apaga todos os aparelhos lembrados do usuário (o cookie deles deixa de valer). */
    public static function revogarLembrados(int $usuarioId): void
    {
        SessaoLembradaModel::apagarDoUsuario($usuarioId);
    }

    /**
     * "Sair de todos os aparelhos": revoga os lembrados e incrementa usuarios.sessao_versao,
     * o que derruba as sessões PHP vivas nos outros aparelhos na próxima página. A sessão
     * atual (se for do mesmo usuário) recebe a versão nova e continua.
     */
    public static function sairDeTodos(int $usuarioId): void
    {
        self::revogarLembrados($usuarioId);
        $versao = UsuarioModel::incrementarSessaoVersao($usuarioId);
        if (self::id() === $usuarioId) {
            $_SESSION['sessao_versao'] = $versao;
            self::$versaoOk = true;
            self::apagarCookie();                        // a linha deste aparelho também saiu
        }
    }

    /** Apaga a linha do aparelho atual (pelo seletor do cookie) e o cookie. */
    private static function esquecerEsteAparelho(): void
    {
        $bruto = $_COOKIE[self::COOKIE_LEMBRAR] ?? null;
        if (is_string($bruto) && preg_match('/^([0-9a-f]{24}):/', $bruto, $m)) {
            SessaoLembradaModel::apagar($m[1]);
        }
        if ($bruto !== null) {
            self::apagarCookie();
        }
    }

    private static function gravarCookie(string $valor, int $expira): void
    {
        setcookie(self::COOKIE_LEMBRAR, $valor, [
            'expires'  => $expira,
            'path'     => '/',
            'secure'   => requisicaoHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        $_COOKIE[self::COOKIE_LEMBRAR] = $valor;
    }

    private static function apagarCookie(): void
    {
        setcookie(self::COOKIE_LEMBRAR, '', [
            'expires'  => 1,
            'path'     => '/',
            'secure'   => requisicaoHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        unset($_COOKIE[self::COOKIE_LEMBRAR]);
    }

    /* -------------------------------------------------------------- sessão */

    public static function logout(): void
    {
        self::esquecerEsteAparelho();
        $_SESSION = [];
        self::$versaoOk = null;
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => 1,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?: 'Strict',
            ]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function check(): bool
    {
        return isset($_SESSION['usuario_id']);
    }

    /** Logado E com a versão de sessão em dia (1 SELECT por requisição, depois cache). */
    public static function logado(): bool
    {
        if (!self::check()) {
            return false;
        }
        if (self::$versaoOk === null) {
            $atual = UsuarioModel::sessaoVersao((int) $_SESSION['usuario_id']);
            self::$versaoOk = $atual !== null && $atual === (int) ($_SESSION['sessao_versao'] ?? 0);
        }
        if (!self::$versaoOk) {
            // "sair de todos os aparelhos" (ou usuário apagado): esta sessão morreu
            $_SESSION = ['sessao_expirou' => true];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            self::$versaoOk = null;
            return false;
        }
        return true;
    }

    /** A sessão desta requisição acabou de ser recriada pelo cookie de lembrar (ver csrfCheck). */
    public static function restaurado(): bool
    {
        return self::$restaurado;
    }

    public static function id(): ?int
    {
        return isset($_SESSION['usuario_id']) ? (int) $_SESSION['usuario_id'] : null;
    }

    public static function nome(): ?string
    {
        return $_SESSION['usuario_nome'] ?? null;
    }

    public static function administrador(): bool
    {
        return self::id() !== null && self::id() === Models\UsuarioModel::administradorId();
    }

    /**
     * Quem pode mexer no site inteiro: a conta administradora e, no computador da pessoa, só nele. No celular a
     * conexão é HTTP: uma cópia de todos os dados ou um arquivo que os substitui não passam por ela.
     */
    public static function podeAdministrar(): bool
    {
        return self::administrador() && (Primeira::modo() !== 'instalador' || Rede::requisicaoLocal());
    }

    /** Antes da primeira configuração, toda tela leva a ela. */
    private static function exigirConfiguracao(): void
    {
        if (PHP_SAPI === 'cli' || Models\AjusteModel::configurado()) {
            return;
        }
        $caminho = (string) strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
        if ($caminho !== '/comecar') {
            header('Location: /comecar', true, 302);
            exit;
        }
    }

    public static function requireLogin(): void
    {
        if (self::logado() || self::tentarLembrado()) {
            self::exigirConfiguracao();
            return;
        }
        // "a sessão expirou" só para quem tinha uma: a que passou de 8 h, a derrubada de outro aparelho ou a que o
        // servidor já não conhece. Depois de sair, o /login deixa uma sessão vazia, e ela não conta.
        $cookie = $_COOKIE[session_name()] ?? null;
        $expirou = !empty($_SESSION['sessao_expirou']) || (is_string($cookie) && $cookie !== session_id());
        unset($_SESSION['sessao_expirou']);
        if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
            $_SESSION['next'] = $_SERVER['REQUEST_URI'] ?? '/';
        } elseif (strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?') === '/lancamentos') {
            // o Lançar enviado com a sessão vencida: nada é gravado, e depois de entrar o formulário volta preenchido
            \Financeiro\Controllers\LancamentosController::guardarSemSessao();
        }
        header('Location: /login' . ($expirou ? '?expirou=1' : ''), true, 302);
        exit;
    }
}
