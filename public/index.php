<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

// Cabeçalhos de segurança básicos (fica valendo mesmo sem mod_headers configurado no Apache)
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; font-src 'self'; script-src 'self'; img-src 'self' data:");

use Financeiro\Services\FotoPlano;
use Financeiro\Services\Horizonte;

// os meses do plano não têm fim: se o mês virou, cria os que faltam
try {
    \Financeiro\Database::connection();   // antes de qualquer data: é ao conectar que o fuso do site passa a valer
    Horizonte::garantir();
    FotoPlano::garantir();
} catch (\Throwable $e) {
    error_log('horizonte: ' . $e->getMessage());
}

$router = require __DIR__ . '/../src/rotas.php';

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
