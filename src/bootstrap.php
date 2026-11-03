<?php
declare(strict_types=1);

// antes de tudo: um erro aqui no início não pode mostrar caminhos na página
ini_set('display_errors', '0');

require __DIR__ . '/autoload.php';
require __DIR__ . '/helpers.php';

// até o banco conectar; ao conectar, vale o fuso escolhido no site (Financeiro\Site)
date_default_timezone_set(Financeiro\Site::FUSO_PADRAO);

use Financeiro\Config;
use Financeiro\Paths;
use Financeiro\Preflight;
use Financeiro\Primeira;
use Financeiro\Rede;

if (Paths::dados() !== null || !Paths::portatil()) {
    if (is_readable(Paths::config())) {
        Config::load(Paths::config());
    }
}
Preflight::exigir();
try {
    Primeira::garantir();
} catch (\Throwable $e) {
    error_log('primeira execução: ' . $e->getMessage());
    Preflight::tela(500, t('O MyMonetto não pôde abrir'), [t('Não deu para preparar o banco de dados. Confira se há espaço em disco.')]);
}
Config::load(Paths::config());

if (PHP_SAPI !== 'cli') {
    Rede::exigirHost();
    Financeiro\Manutencao::exigir();
    // com pasta de dados, a sessão e o log moram nela e nada depende do php.ini da máquina
    if (Paths::dados() !== null) {
        ini_set('session.save_path', Paths::sessoes());
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        ini_set('log_errors', '1');
        ini_set('error_log', Paths::log());
    }
}

// Sessão: cookie sempre httponly + SameSite=Strict; secure ligado automaticamente
// quando a requisição já chega via HTTPS (atrás do vhost :443). O cookie é da sessão do navegador
// (sem data): fechar o navegador sai. Quem quer continuar entrado marca "lembrar este aparelho".
$https = Rede::https();

// linha de comando sem cabeçalhos livres (frankenphp php-cli): não há navegador, nem sessão de verdade
if (PHP_SAPI === 'cli' && headers_sent()) {
    $_SESSION = [];
} else {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string) (int) Config::get('SESSION_LIFETIME', '28800'));
    session_name('financeiro_sid');
    session_start();
}

// O limite de 8 h sem uso fica no servidor: a cada página, a sessão parada há mais tempo que isso acaba (o
// gc_maxlifetime só apaga o arquivo de vez em quando), e a tela de entrar diz que ela expirou.
if (session_status() === PHP_SESSION_ACTIVE) {
    if (!empty($_SESSION['usuario_id']) && isset($_SESSION['ultimo_uso'])
        && time() - (int) $_SESSION['ultimo_uso'] > (int) Config::get('SESSION_LIFETIME', '28800')) {
        $_SESSION = ['sessao_expirou' => true];
        session_regenerate_id(true);
    }
    $_SESSION['ultimo_uso'] = time();
}

if (Config::get('APP_ENV', 'production') === 'production') {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}
