<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

// Cabeçalhos de segurança básicos (fica valendo mesmo sem mod_headers configurado no Apache)
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; font-src 'self'; script-src 'self'; img-src 'self' data:");

use Financeiro\Router;
use Financeiro\Controllers\AuthController;
use Financeiro\Controllers\DashboardController;
use Financeiro\Controllers\LancamentosController;
use Financeiro\Controllers\MesController;
use Financeiro\Controllers\MetaController;
use Financeiro\Controllers\CategoriaController;
use Financeiro\Controllers\FuturoController;
use Financeiro\Controllers\AjustesController;

$router = new Router();

// ---- sessão
$router->get('/login', [AuthController::class, 'form']);
$router->post('/login', [AuthController::class, 'login']);
$router->post('/logout', [AuthController::class, 'logout']);

// ---- início
$router->get('/', [DashboardController::class, 'index']);
$router->post('/conferir', [DashboardController::class, 'conferir']);

// ---- lançar e extrato
$router->get('/lancar', [LancamentosController::class, 'lancarForm']);
$router->get('/lancamentos', [LancamentosController::class, 'index']);
$router->post('/lancamentos', [LancamentosController::class, 'store']);
$router->post('/lancamentos/desfazer', [LancamentosController::class, 'desfazer']);
$router->get('/lancamentos/{id}/editar', [LancamentosController::class, 'editForm']);
$router->post('/lancamentos/{id}/excluir', [LancamentosController::class, 'destroy']);
$router->post('/lancamentos/{id}', [LancamentosController::class, 'update']);
$router->post('/categorias', [CategoriaController::class, 'criar']);
$router->post('/categorias/{id}/remover', [CategoriaController::class, 'remover']);
$router->get('/categorias/{id}/icone', [CategoriaController::class, 'iconeForm']);
$router->post('/categorias/{id}/icone', [CategoriaController::class, 'salvarIcone']);

// ---- meta (metas de gasto por categoria)
$router->get('/meta', [MetaController::class, 'index']);
$router->post('/meta/salvar', [MetaController::class, 'salvar']);

// ---- mês e fechar mês
$router->get('/mes', [MesController::class, 'index']);
$router->post('/mes/previsao', [MesController::class, 'salvarPrevisao']);
$router->post('/mes/desfazer', [MesController::class, 'desfazerLote']);
$router->get('/mes/fechar', [MesController::class, 'fecharForm']);
$router->post('/mes/fechar', [MesController::class, 'fechar']);
$router->post('/mes/reabrir', [MesController::class, 'reabrir']);

// ---- futuro
$router->get('/futuro', [FuturoController::class, 'index']);

// ---- ajustes (menu do avatar)
$router->get('/ajustes', [AjustesController::class, 'index']);
$router->post('/ajustes/salvar', [AjustesController::class, 'salvar']);
$router->post('/ajustes/senha', [AjustesController::class, 'trocarSenha']);
$router->post('/ajustes/contas', [AjustesController::class, 'salvarConta']);
$router->post('/ajustes/contas/{id}/remover', [AjustesController::class, 'removerConta']);
$router->post('/ajustes/essenciais', [AjustesController::class, 'salvarEssenciais']);
$router->post('/ajustes/aparelhos/sair', [AjustesController::class, 'sairAparelhos']);

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
