<?php
/**
 * Prepara um banco recém-criado: categorias, ajustes e, na demonstração, os usuários e dados fictícios.
 * Em MySQL, importe antes database/schema.sql. Em SQLite, cria o arquivo do banco se ele ainda não existe (no modo servidor,
 * é o instalar.sh quem chama). Não mexe num banco já preparado.
 *
 * Uso: php database/instalar.php          sem conta: a primeira é criada no site
 *      php database/instalar.php --demo   dados fictícios para conhecer o app, com logins ana e bruno
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Rode pela linha de comando.\n");
}

const FIN_SEM_DADOS_INICIAIS = true;   // a primeira execução só cria as tabelas: quem prepara é este comando
require __DIR__ . '/../src/bootstrap.php';

use Financeiro\Database;
use Financeiro\Preparo;
use Financeiro\Primeira;
use Financeiro\Sql;

Primeira::criarBancoSeFaltar();
$pdo = Database::connection();
$demo = in_array('--demo', $argv, true);

if (!Sql::tabelaExiste($pdo, 'usuarios')) {
    exit("O banco está sem as tabelas: importe database/schema.sql antes.\n");
}
if ((int) $pdo->query('SELECT COUNT(*) FROM categorias')->fetchColumn() > 0) {
    exit("Este banco já foi preparado: nada foi alterado.\n");
}

$quantas = Preparo::preparar($pdo, $demo);

echo $demo
    ? "Pronto, com dados fictícios. Entre com ana / demo1234 ou bruno / demo1234. Não use a demonstração para dados reais.\n"
    : 'Pronto. ' . $quantas . " categorias. Abra o site para criar a sua conta.\n";
