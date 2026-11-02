<?php
/**
 * Os testes do Financeiro, em três partes:
 * 1. a conferência do site (tests/conferencia.php), no banco de config/.env ou, com FIN_DADOS, no dessa pasta: só o que
 *    vale para qualquer casa, em qualquer data e idioma. Não grava nada;
 * 2. as regras (tests/regras.php), num banco de demonstração descartável e com o relógio parado, num dia comum e no
 *    último dia do mês; com FIN_REGRAS_DRIVER=mysql, num banco MySQL criado na hora;
 * 3. backup, restauração e atualização (tests/sistema.php), num banco descartável.
 * As partes 2 e 3 rodam em processo próprio: os dados de quem roda nem são abertos.
 * Uso: php tests/run.php   (o atualizador roda isto no ensaio e depois de aplicar uma versão). Sai com 1 se algo falhar.
 */
declare(strict_types=1);

error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    // @ continua calando, e a linha de comando do FrankenPHP começa com os cabeçalhos enviados
    if (!(error_reporting() & $no) || (headers_sent() && str_contains($str, 'headers already sent'))) {
        return true;
    }
    throw new \ErrorException($str, 0, $no, $file, $line);
});

$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['HTTPS']          = 'on';

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/conferencia.php';

use Financeiro\Atualizacao;

$ok = 0;
$falhas = 0;
$check = static function (string $nome, bool $cond, string $detalhe = '') use (&$ok, &$falhas): void {
    if ($cond) {
        $ok++;
        echo "ok    {$nome}\n";
    } else {
        $falhas++;
        echo "FALHA {$nome}" . ($detalhe !== '' ? " — {$detalhe}" : '') . "\n";
    }
};

// ---- 1. a conferência do site, nos dados de quem roda
conferencia($check);

// ---- 2 e 3. um arquivo de testes em processo próprio: as falhas aparecem aqui, e o resumo dele entra na conta
$emProcesso = static function (string $nome, array $args, array $env = []) use (&$ok, &$falhas): void {
    $env += array_filter(getenv(), 'is_string');
    $proc = proc_open([...Atualizacao::comandoPhp(), ...$args], [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $tubos, Atualizacao::pastaDoProcesso(dirname(__DIR__)), $env);
    if (!is_resource($proc)) {
        $falhas++;
        echo "FALHA {$nome}: o processo não começou\n";
        return;
    }
    $saida = (string) stream_get_contents($tubos[1]);
    fclose($tubos[1]);
    $fim = proc_close($proc);
    $linhas = preg_split('/\R/', trim($saida)) ?: [];
    foreach ($linhas as $l) {
        if (str_starts_with($l, 'FALHA') || str_contains($l, 'Fatal error')) {
            echo $l . "\n";
        }
    }
    if (preg_match('/^(\d+) ok, (\d+) falha\(s\)$/', (string) end($linhas), $m) && ($fim === 0) === ($m[2] === '0')) {
        $ok += (int) $m[1];
        $falhas += (int) $m[2];
        echo "{$nome}: {$m[0]}\n";
        return;
    }
    $falhas++;
    echo "FALHA {$nome}: parou antes do fim (saída {$fim}) — " . implode(' | ', array_slice($linhas, -3)) . "\n";
};

if (!function_exists('proc_open')) {
    echo "regras e sistema: este PHP não abre outros processos; eles rodam no CI\n";
} elseif (!extension_loaded('pdo_sqlite') && getenv('FIN_REGRAS_DRIVER') !== 'mysql') {
    echo "regras e sistema: este PHP não tem pdo_sqlite; eles rodam no CI\n";
} else {
    $emProcesso('regras', [__DIR__ . '/regras.php']);
    $emProcesso('regras no último dia do mês', [__DIR__ . '/regras.php', '--borda']);
    if (extension_loaded('pdo_sqlite')) {
        // backup, restauração, migrações e atualização: em SQLite, numa pasta descartável
        $tmpSis = sys_get_temp_dir() . '/financeiro-sistema-' . bin2hex(random_bytes(6));
        mkdir($tmpSis, 0700);
        try {
            $emProcesso('sistema', [__DIR__ . '/sistema.php'], ['FIN_DADOS' => $tmpSis]);
        } finally {
            Atualizacao::removerPasta($tmpSis);
        }
    }
}

echo "\n{$ok} ok, {$falhas} falha(s)\n";
exit($falhas > 0 ? 1 : 0);
