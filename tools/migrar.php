<?php
/** Aplica as mudanças de banco que faltam no SQLite. Usado pelo atualizador; pode rodar de novo sem problema. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Rode pela linha de comando.\n");
}
require __DIR__ . '/../src/bootstrap.php';

use Financeiro\Database;
use Financeiro\Migrador;
use Financeiro\Sql;

if (!Sql::sqlite()) {
    fwrite(STDERR, "Este site está em MySQL: quem migra é o financeiro-atualizar.\n");
    exit(1);
}
foreach (Migrador::aplicar(Database::connection(), Migrador::pasta()) as $a) {
    echo "migração {$a}\n";
}
