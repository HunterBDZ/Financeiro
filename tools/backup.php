<?php
/**
 * Backups do SQLite pela linha de comando.
 *   php tools/backup.php                       cópia do dia (o que o cron e o agendador chamam)
 *   php tools/backup.php --motivo=manual       cópia avulsa; imprime o caminho
 *   php tools/backup.php --copiar=/novo.sqlite cópia num arquivo que ainda não existe
 *   php tools/backup.php --voltar=/copia.sqlite  põe a cópia no lugar do banco: só com o site parado ou em manutenção
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Rode pela linha de comando.\n");
}
require __DIR__ . '/../src/bootstrap.php';

use Financeiro\Backup;

if (!Backup::disponivel()) {
    fwrite(STDERR, "Este site não usa SQLite com pasta de dados.\n");
    exit(1);
}
$op = getopt('', ['motivo:', 'copiar:', 'voltar:']);
try {
    if (isset($op['copiar'])) {
        Backup::copiarPara((string) $op['copiar']);
    } elseif (isset($op['voltar'])) {
        Backup::voltarArquivo((string) $op['voltar']);
    } elseif (isset($op['motivo'])) {
        echo Backup::criar((string) $op['motivo']), "\n";
    } else {
        echo Backup::criar('diario'), "\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
