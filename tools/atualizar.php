<?php
/** O trabalho da atualização pela tela, em processo próprio: php tools/atualizar.php --versao=1.2.3 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Rode pela linha de comando.\n");
}
require __DIR__ . '/../src/bootstrap.php';

// a pasta do programa vai ser trocada no meio: o que este processo usa já tem de estar carregado
foreach (['Atualizacao', 'Backup', 'Migrador', 'Manutencao', 'Tar', 'Database', 'SqliteConexao', 'SqliteConsulta', 'Colacao', 'Sql', 'Site', 'Services\\LinkProduto', 'Models\\UsuarioModel', 'Models\\AjusteModel'] as $c) {
    class_exists('Financeiro\\' . $c);
}
$op = getopt('', ['versao:']);
exit(Financeiro\Atualizacao::executar((string) ($op['versao'] ?? '')) ? 0 : 1);
