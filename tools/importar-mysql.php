<?php
/**
 * Passa os dados de um banco MySQL para um arquivo SQLite novo. No MySQL, só lê, numa transação somente leitura.
 * Confere tudo antes de pôr o arquivo no lugar: contagem, soma por tabela e por mês e o conteúdo de cada linha.
 * Se algo divergir, apaga o que fez e sai com erro.
 *
 * Uso: php tools/importar-mysql.php --mysql=config/.env --destino=/pasta/financeiro.sqlite [--ignorar=tabela,tabela]
 *   --mysql    arquivo .env com DB_HOST, DB_NAME, DB_USER e DB_PASS
 *   --destino  o arquivo novo; se já existir, nada é feito
 *   --ignorar  tabelas do MySQL que ficam de fora. Sem isso, tabela desconhecida é erro.
 *   --ignorar-v1-pendente  aceita perder compras da tabela antiga do cartão que ainda não foram importadas no site
 *
 * Precisa de pdo_mysql e pdo_sqlite no mesmo PHP. Só mostra nomes de tabela, totais e chaves; nunca o conteúdo.
 */
declare(strict_types=1);

use Financeiro\Colacao;
use Financeiro\Site;
use Financeiro\SqliteConexao;

if (PHP_SAPI !== 'cli') {
    exit("Rode pela linha de comando.\n");
}
require __DIR__ . '/../src/autoload.php';

const COLUNA_DE_MES = [
    'lancamentos'        => ['data', ['valor']],
    'previsao'           => ['mes', ['valor']],
    'previsao_log'       => ['mes', ['antes', 'depois']],
    'cartao_compras'     => ['primeira_fatura', ['valor_total']],
    'saldo_conferencias' => ['data', ['saldo_informado']],
];

function falha(string $msg): never
{
    fwrite(STDERR, "\nIMPORTAÇÃO RECUSADA: {$msg}\n");
    global $arquivoNovo;
    if (is_string($arquivoNovo ?? null)) {
        foreach (['', '-wal', '-shm'] as $s) {
            @unlink($arquivoNovo . $s);
        }
    }
    exit(1);
}

$op = getopt('', ['mysql:', 'destino:', 'ignorar::', 'ignorar-v1-pendente']);
if (empty($op['mysql']) || empty($op['destino'])) {
    fwrite(STDERR, "Uso: php tools/importar-mysql.php --mysql=config/.env --destino=/pasta/financeiro.sqlite [--ignorar=tabela,tabela]\n");
    exit(2);
}
$destino = (string) $op['destino'];
if (file_exists($destino)) {
    falha("o arquivo de destino já existe: {$destino}");
}
if (!extension_loaded('pdo_mysql') || !extension_loaded('pdo_sqlite')) {
    falha('este PHP precisa de pdo_mysql e pdo_sqlite.');
}
if (!is_readable((string) $op['mysql'])) {
    falha('não consegui ler o arquivo indicado em --mysql.');
}
$env = [];
foreach (file((string) $op['mysql'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
    if (!str_starts_with(trim($l), '#') && str_contains($l, '=')) {
        [$k, $v] = explode('=', $l, 2);
        $env[trim($k)] = trim($v);
    }
}
$ignorar = array_values(array_filter(array_map('trim', explode(',', (string) ($op['ignorar'] ?? '')))));

// ---- MySQL: uma fotografia só de todas as tabelas, sem poder gravar
$my = new PDO('mysql:host=' . ($env['DB_HOST'] ?? 'localhost') . ';dbname=' . ($env['DB_NAME'] ?? '') . ';charset=utf8mb4', $env['DB_USER'] ?? '', $env['DB_PASS'] ?? '', [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);
$my->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$my->exec('SET SESSION TRANSACTION READ ONLY');

$numeroFuso = $my->query("SELECT valor FROM ajustes WHERE chave = 'fuso'")->fetchColumn();
$fuso = Site::fusoDoNumero($numeroFuso === false ? null : (int) round((float) $numeroFuso));
date_default_timezone_set($fuso);
// TIMESTAMP volta no fuso da sessão: o texto gravado no SQLite é o mesmo que o site mostra hoje
$my->exec("SET time_zone = '" . Site::deslocamento($fuso) . "'");
$my->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

$tabelasMy = [];
foreach ($my->query("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'") as $r) {
    $tabelasMy[] = (string) $r['t'];
}

// ---- SQLite: arquivo novo, feito ao lado do destino
$arquivoNovo = $destino . '.novo';
@unlink($arquivoNovo);
$sq = new SqliteConexao($arquivoNovo);
$sq->exec((string) file_get_contents(__DIR__ . '/../database/schema.sqlite.sql'));
$tabelasSq = array_map('strval', $sq->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN));

$sobra = array_values(array_diff($tabelasMy, $tabelasSq, $ignorar));
if ($sobra !== []) {
    falha('tabelas do MySQL que o SQLite não tem: ' . implode(', ', $sobra) . '. Atualize o site ou use --ignorar, sabendo o que fica de fora.');
}
$falta = array_values(array_diff($tabelasSq, $tabelasMy));
if ($falta !== []) {
    falha('tabelas que o MySQL ainda não tem: ' . implode(', ', $falta) . '. Atualize o site antes de importar.');
}
if (in_array('cartao_compras_v1', $tabelasMy, true) && !isset($op['ignorar-v1-pendente'])) {
    $pendentes = (int) $my->query('SELECT COUNT(*) FROM cartao_compras_v1 v WHERE NOT EXISTS (SELECT 1 FROM cartao_compras n WHERE n.descricao = v.descricao AND n.criado_em = v.criado_em)')->fetchColumn();
    if ($pendentes > 0) {
        falha("a tabela antiga do cartão tem {$pendentes} compra(s) ainda não importada(s). Cadastre o cartão no site para importá-las, ou use --ignorar-v1-pendente.");
    }
}

/** @return array<string, array{tipo:string, casas:?int, gerada:bool}> */
function colunasMysql(PDO $my, string $tabela): array
{
    $st = $my->prepare('SELECT column_name AS c, data_type AS t, numeric_scale AS e, extra AS x FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position');
    $st->execute([$tabela]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(string) $r['c']] = ['tipo' => strtolower((string) $r['t']), 'casas' => $r['e'] === null ? null : (int) $r['e'], 'gerada' => (bool) preg_match('/(STORED|VIRTUAL) GENERATED/i', (string) $r['x'])];
    }
    return $out;
}

function ehBlob(array $col): bool
{
    return str_contains($col['tipo'], 'blob') || str_contains($col['tipo'], 'binary');
}

function chavePrimaria(PDO $sq, string $tabela): array
{
    $cols = [];
    foreach ($sq->query('PRAGMA table_info(' . $tabela . ')') as $r) {
        if ((int) $r['pk'] > 0) {
            $cols[(int) $r['pk']] = (string) $r['name'];
        }
    }
    ksort($cols);
    return array_values($cols);
}

/** Valor em texto, igual nos dois bancos: DECIMAL com as suas casas, o resto como veio. */
function canonico(mixed $v, array $col): string
{
    if ($v === null) {
        return "\0NULL";
    }
    if ($col['tipo'] === 'decimal') {
        return centavos($v, (int) $col['casas']);
    }
    return is_string($v) ? $v : (string) $v;
}

/** O número sem ponto, em unidades da última casa: comparação exata, sem float. */
function centavos(mixed $v, int $casas): string
{
    if (is_string($v) && preg_match('/^(-?)(\d+)(?:\.(\d*))?$/', $v, $m)) {
        $f = str_pad($m[3] ?? '', $casas, '0');
        if (strlen($f) > $casas) {
            $f = substr($f, 0, $casas);
        }
        $n = ltrim($m[2] . $f, '0');
        return ($n === '' ? '0' : $m[1] . $n);
    }
    $s = sprintf('%.' . $casas . 'F', (float) $v);
    return centavos($s, $casas);
}

$sq->exec('PRAGMA foreign_keys = OFF');
$sq->beginTransaction();
$colunas = [];
foreach ($tabelasSq as $tabela) {
    $cols = colunasMysql($my, $tabela);
    $colsSq = [];
    foreach ($sq->query('PRAGMA table_xinfo(' . $tabela . ')') as $r) {
        $colsSq[(string) $r['name']] = (int) $r['hidden'] !== 0;
    }
    $difer = array_merge(array_diff(array_keys($cols), array_keys($colsSq)), array_diff(array_keys($colsSq), array_keys($cols)));
    if ($difer !== []) {
        falha("as colunas de {$tabela} não são as mesmas nos dois bancos: " . implode(', ', $difer));
    }
    $colunas[$tabela] = $cols;
    $grava = array_keys(array_filter($cols, static fn(array $c): bool => !$c['gerada']));
    $ins = $sq->prepare('INSERT INTO ' . $tabela . ' (' . implode(', ', $grava) . ') VALUES (' . implode(', ', array_fill(0, count($grava), '?')) . ')');
    $sel = $my->query('SELECT ' . implode(', ', array_map(static fn(string $c): string => '`' . $c . '`', $grava)) . ' FROM `' . $tabela . '`');
    while (($linha = $sel->fetch()) !== false) {
        foreach ($grava as $i => $c) {
            $v = $linha[$c];
            $tipo = $v === null ? PDO::PARAM_NULL : (ehBlob($cols[$c]) ? PDO::PARAM_LOB : (is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR));
            $ins->bindValue($i + 1, $v, $tipo);
        }
        $ins->execute();
    }
    // o próximo id não volta para trás, mesmo com linhas apagadas
    $criar = (string) $my->query('SHOW CREATE TABLE `' . $tabela . '`')->fetch(PDO::FETCH_NUM)[1];
    if (preg_match('/AUTO_INCREMENT=(\d+)/', $criar, $m)) {
        $sq->prepare('DELETE FROM sqlite_sequence WHERE name = ?')->execute([$tabela]);
        $sq->prepare('INSERT INTO sqlite_sequence (name, seq) VALUES (?, ?)')->execute([$tabela, (int) $m[1] - 1]);
    }
}
$sq->commit();

// ---- conferência
$sq->exec('PRAGMA foreign_keys = ON');
$fk = $sq->query('PRAGMA foreign_key_check')->fetchAll();
if ($fk !== []) {
    falha(count($fk) . ' linha(s) com chave estrangeira quebrada, por exemplo em ' . $fk[0]['table'] . '.');
}
$integridade = (string) $sq->query('PRAGMA integrity_check')->fetchColumn();
if ($integridade !== 'ok') {
    falha('o arquivo novo não passou em integrity_check.');
}

$divergencias = [];
printf("%-22s %8s  %s\n", 'tabela', 'linhas', 'somas');
foreach ($tabelasSq as $tabela) {
    $cols = $colunas[$tabela];
    $nomes = array_keys($cols);
    $pk = chavePrimaria($sq, $tabela);
    $lista = implode(', ', array_map(static fn(string $c): string => '`' . $c . '`', $nomes));
    $resumos = [];
    foreach (['mysql' => $my, 'sqlite' => $sq] as $qual => $banco) {
        $linhas = [];
        $somas = [];
        foreach ($banco->query('SELECT ' . $lista . ' FROM ' . ($qual === 'mysql' ? '`' . $tabela . '`' : $tabela)) as $r) {
            $partes = [];
            foreach ($nomes as $c) {
                $partes[$c] = sha1(canonico($r[$c], $cols[$c]));
                if ($cols[$c]['tipo'] === 'decimal' && $r[$c] !== null) {
                    $somas[$c] = ($somas[$c] ?? 0) + (int) centavos($r[$c], (int) $cols[$c]['casas']);
                }
            }
            $chave = implode("\x1F", array_map(static fn(string $c): string => (string) $r[$c], $pk));
            $linhas[$chave] = $partes;
        }
        ksort($linhas, SORT_STRING);
        ksort($somas);
        $resumos[$qual] = ['linhas' => $linhas, 'somas' => $somas];
    }
    $a = $resumos['mysql'];
    $b = $resumos['sqlite'];
    if (count($a['linhas']) !== count($b['linhas'])) {
        $divergencias[] = "{$tabela}: " . count($a['linhas']) . ' linhas no MySQL e ' . count($b['linhas']) . ' no SQLite';
    }
    if ($a['somas'] !== $b['somas']) {
        $divergencias[] = "{$tabela}: as somas não batem";
    }
    foreach ($a['linhas'] as $chave => $h) {
        if (($b['linhas'][$chave] ?? null) !== $h) {
            $quais = array_keys(array_diff_assoc($h, $b['linhas'][$chave] ?? []));
            $divergencias[] = "{$tabela}: linha diferente, chave " . strtr((string) $chave, "\x1F", '|') . ', colunas ' . implode(', ', $quais);
            break;
        }
    }
    $textoSomas = [];
    foreach ($a['somas'] as $c => $v) {
        $textoSomas[] = $c . '=' . number_format($v / 10 ** (int) $cols[$c]['casas'], (int) $cols[$c]['casas'], ',', '.');
    }
    printf("%-22s %8d  %s\n", $tabela, count($a['linhas']), implode(' ', $textoSomas));
}

// por mês: em cada banco, com a função de mês dele, e comparado em unidades inteiras
$mesesConferidos = 0;
foreach (COLUNA_DE_MES as $tabela => [$colMes, $valores]) {
    foreach ($valores as $valor) {
        $casas = (int) $colunas[$tabela][$valor]['casas'];
        $doMy = $doSq = [];
        foreach ($my->query("SELECT DATE_FORMAT(`{$colMes}`, '%Y-%m') AS m, COUNT(*) AS n, COALESCE(SUM(`{$valor}`), 0) AS s FROM `{$tabela}` GROUP BY m") as $r) {
            $doMy[(string) $r['m']] = [(int) $r['n'], centavos($r['s'], $casas)];
        }
        foreach ($sq->query("SELECT strftime('%Y-%m', {$colMes}) AS m, COUNT(*) AS n, COALESCE(SUM({$valor}), 0) AS s FROM {$tabela} GROUP BY m") as $r) {
            $doSq[(string) $r['m']] = [(int) $r['n'], centavos($r['s'], $casas)];
        }
        ksort($doMy);
        ksort($doSq);
        $mesesConferidos += count($doMy);
        foreach ($doMy + $doSq as $m => $_) {
            if (($doMy[$m] ?? null) !== ($doSq[$m] ?? null)) {
                $divergencias[] = "{$tabela}.{$valor}: mês {$m} não bate";
            }
        }
    }
}
if ($divergencias !== []) {
    falha(count($divergencias) . " divergência(s):\n- " . implode("\n- ", array_slice($divergencias, 0, 30)));
}
$my->rollBack();

// tudo no arquivo principal, sem diário ao lado; o site volta ao WAL quando abrir
$contagens = [];
foreach ($tabelasSq as $tabela) {
    $contagens[$tabela] = (int) $sq->query('SELECT COUNT(*) FROM ' . $tabela)->fetchColumn();
}
if ((string) $sq->query('PRAGMA journal_mode = DELETE')->fetchColumn() !== 'delete') {
    falha('não consegui fechar o diário do arquivo novo.');
}
$sq = $banco = $ins = $sel = null;
@chmod($arquivoNovo, 0600);
if (!rename($arquivoNovo, $destino)) {
    falha('não consegui pôr o arquivo no lugar.');
}
$arquivoNovo = $destino;

// a última conferência já é no arquivo final, numa conexão nova
$final = new SqliteConexao($destino);
foreach ($contagens as $tabela => $n) {
    if ((int) $final->query('SELECT COUNT(*) FROM ' . $tabela)->fetchColumn() !== $n) {
        falha("{$tabela}: o arquivo final não tem as mesmas linhas.");
    }
}
$final = null;
echo "\nOK: ", count($tabelasSq), " tabelas conferidas linha a linha e {$mesesConferidos} somas mensais iguais. Arquivo: {$destino}\n";
