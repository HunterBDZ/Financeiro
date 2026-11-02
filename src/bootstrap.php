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
    Preflight::tela(500, t('O Financeiro não pôde abrir'), [t('Não deu para preparar o banco de dados. Confira se há espaço em disco.')]);
}
Config::load(Paths::config());

if (PHP_SAPI !== 'cli') {
    Rede::exigirHost();
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
// quando a requisição já chega via HTTPS (atrás do vhost :443).
$https = Rede::https();

// linha de comando sem cabeçalhos livres (frankenphp php-cli): não há navegador, nem sessão de verdade
if (PHP_SAPI === 'cli' && headers_sent()) {
    $_SESSION = [];
} else {
    session_set_cookie_params([
        'lifetime' => (int) Config::get('SESSION_LIFETIME', '28800'),
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

// Cookie de sessão rolante: quem está usando renova as 8 h a cada página (o arquivo da
// sessão já é tocado a cada requisição; o gc_maxlifetime vira limite de inatividade).
if (!empty($_SESSION['usuario_id']) && ($_COOKIE[session_name()] ?? null) === session_id() && !headers_sent()) {
    $p = session_get_cookie_params();
    setcookie(session_name(), session_id(), [
        'expires'  => time() + (int) $p['lifetime'],
        'path'     => $p['path'],
        'domain'   => $p['domain'],
        'secure'   => $p['secure'],
        'httponly' => $p['httponly'],
        'samesite' => $p['samesite'] ?: 'Strict',
    ]);
    unset($p);
}

if (Config::get('APP_ENV', 'production') === 'production') {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}
