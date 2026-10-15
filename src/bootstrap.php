<?php
declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

spl_autoload_register(function (string $class): void {
    $prefix = 'Financeiro\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

require __DIR__ . '/helpers.php';

use Financeiro\Config;

Config::load(__DIR__ . '/../config/.env');

// Sessão: cookie sempre httponly + SameSite=Strict; secure ligado automaticamente
// quando a requisição já chega via HTTPS (atrás do vhost :443).
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

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
