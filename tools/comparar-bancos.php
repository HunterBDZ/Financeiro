<?php
/**
 * Compara dois sites do Financeiro, normalmente o mesmo dado em MySQL e em SQLite: o plano do MotorFinanceiro, mês a mês
 * e no centavo, e o HTML das telas. Cada lado roda em processos próprios, numa transação desfeita no fim: nenhum banco muda.
 *
 * Uso: php tools/comparar-bancos.php --a=/pasta/do/mysql --b=/pasta/do/sqlite [--php="frankenphp php-cli"] [--mostrar]
 *   --a, --b   pastas de dados (FIN_DADOS), cada uma com o seu config.env. A palavra site = o config/.env do repositório.
 *   --php      o PHP a usar nos processos; precisa do driver do banco de cada lado
 *   --mostrar  mostra o trecho que difere. Sem isso, só a tela e a linha: o conteúdo pode ser pessoal.
 *
 * Sai com 1 se houver qualquer diferença.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Rode pela linha de comando.\n");
}

const TELAS_FIXAS = ['/', '/lancar', '/lancamentos', '/meta', '/mes', '/mes/fechar', '/futuro', '/cartao', '/emprestimo', '/wishlist', '/investimentos', '/relatorio', '/relatorio/pdf', '/temas', '/ajustes', '/ajuda', '/comecar', '/ajuda/conexao'];
const CSRF_FIXO = '0000000000000000000000000000000000000000000000000000000000000000';

$op = getopt('', ['a:', 'b:', 'php:', 'mostrar', 'filho:', 'uri:', 'idioma:', 'usuario:', 'saida:']);

if (isset($op['filho'])) {
    filho((string) $op['filho'], $op);
    exit(0);
}
if (!isset($op['a'], $op['b'])) {
    fwrite(STDERR, "Uso: php tools/comparar-bancos.php --a=/pasta/do/mysql --b=/pasta/do/sqlite [--php=\"frankenphp php-cli\"] [--mostrar]\n");
    exit(2);
}
exit(comparar((string) $op['a'], (string) $op['b'], (string) ($op['php'] ?? ''), isset($op['mostrar'])));

// ---- processo pai

/** @return array<string, mixed> o que o filho gravou */
function rodar(string $php, string $dados, array $args): array
{
    $saida = (string) tempnam(sys_get_temp_dir(), 'fincmp');
    $cmd = array_merge(comandoPhp($php), [__FILE__], $args, ['--saida=' . $saida]);
    $env = getenv();
    unset($env['FIN_DADOS']);
    if ($dados !== 'site') {
        $env['FIN_DADOS'] = $dados;
    }
    $proc = proc_open($cmd, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($proc)) {
        throw new RuntimeException('Não consegui iniciar o PHP.');
    }
    $saiu = stream_get_contents($pipes[1]);
    $erro = stream_get_contents($pipes[2]);
    $codigo = proc_close($proc);
    $conteudo = (string) file_get_contents($saida);
    @unlink($saida);
    if ($conteudo === '') {
        throw new RuntimeException("O processo de {$dados} falhou ({$codigo}): " . substr($saiu . $erro, 0, 800));
    }
    return unserialize($conteudo);
}

/** @return list<string> */
function comandoPhp(string $php): array
{
    if ($php !== '') {
        return preg_split('/\s+/', trim($php));
    }
    return stripos(basename(PHP_BINARY), 'frankenphp') !== false ? [PHP_BINARY, 'php-cli'] : [PHP_BINARY];
}

function comparar(string $a, string $b, string $php, bool $mostrar): int
{
    $diferencas = 0;
    $relatar = static function (string $msg) use (&$diferencas): void {
        $diferencas++;
        if ($diferencas <= 60) {
            echo "DIFERE  {$msg}\n";
        }
    };

    // ---- plano
    $pa = rodar($php, $a, ['--filho=plano']);
    $pb = rodar($php, $b, ['--filho=plano']);
    echo 'Bancos: ', $pa['driver'], ' x ', $pb['driver'], "\n";
    $maior = 0.0;
    $campos = 0;
    $meses = [];
    foreach (['plano', 'objetivo1', 'objetivo2', 'objetivo3', 'gastos', 'dados'] as $parte) {
        $fa = achatar($pa[$parte]);
        $fb = achatar($pb[$parte]);
        foreach ($fa + $fb as $k => $_) {
            $va = $fa[$k] ?? null;
            $vb = $fb[$k] ?? null;
            $campos++;
            if ($parte === 'plano') {
                $meses[explode('.', $k)[0]] = true;
            }
            if (is_numeric($va) && is_numeric($vb)) {
                $maior = max($maior, abs((float) $va - (float) $vb));
                if (round((float) $va * 100) !== round((float) $vb * 100)) {
                    $relatar("{$parte}.{$k}: diferença de centavos" . ($mostrar ? " ({$va} x {$vb})" : ''));
                }
            } elseif ($va !== $vb) {
                $relatar("{$parte}.{$k}: valor diferente" . ($mostrar ? ' (' . json_encode($va) . ' x ' . json_encode($vb) . ')' : ''));
            }
        }
    }
    printf("Plano: %d meses, %d campos conferidos, maior diferença bruta %.10f\n", count($meses), $campos, $maior);

    // ---- ordem do texto
    if ($pa['colacao'] !== $pb['colacao']) {
        $relatar('colação: os grupos de texto não saem na mesma ordem' . ($mostrar ? ' ' . json_encode(primeiraDiferenca($pa['colacao'], $pb['colacao']), JSON_UNESCAPED_UNICODE) : ''));
    }
    printf("Colação: %d textos em %d grupos\n", count($pa['colacao'], COUNT_RECURSIVE) - count($pa['colacao']), count($pa['colacao']));

    // ---- telas
    if ($pa['telas'] !== $pb['telas']) {
        $relatar('as listas de telas não são iguais: ' . count($pa['telas']) . ' x ' . count($pb['telas']));
    }
    $n = 0;
    $difTelas = 0;
    foreach (array_intersect($pa['telas'], $pb['telas']) as $posicao => $uri) {
        // as telas fixas em cada idioma e conta; as de cada mês ou item, uma vez
        $fixa = $posicao < $pa['telasFixas'];
        foreach ($fixa ? $pa['idiomas'] : ['pt'] as $idioma) {
            foreach ($fixa ? $pa['usuarios'] : [$pa['usuarios'][0]] as $usuario) {
                $args = ['--filho=pagina', '--uri=' . $uri, '--idioma=' . $idioma, '--usuario=' . $usuario];
                $ta = rodar($php, $a, $args);
                $tb = rodar($php, $b, $args);
                $n++;
                $linha = primeiraLinhaDiferente($ta['corpo'], $tb['corpo']);
                if ($ta['status'] !== $tb['status'] || $linha !== null) {
                    $difTelas++;
                    $relatar("{$uri} [{$idioma}, conta {$usuario}]: status {$ta['status']} x {$tb['status']}" . ($linha !== null ? ", linha {$linha[0]}" : '')
                        . ($mostrar && $linha !== null ? "\n  a: {$linha[1]}\n  b: {$linha[2]}" : ''));
                }
            }
        }
    }
    printf("Telas: %d renderizações, %d com diferença\n", $n, $difTelas);

    if ($diferencas > 0) {
        echo "\n{$diferencas} diferença(s).\n";
        return 1;
    }
    echo "\n0 diferenças.\n";
    return 0;
}

/** @return array<string, scalar|null> caminho => valor */
function achatar(mixed $v, string $prefixo = ''): array
{
    if (!is_array($v)) {
        return [rtrim($prefixo, '.') => $v];
    }
    $out = [];
    foreach ($v as $k => $x) {
        $out += achatar($x, $prefixo . $k . '.');
    }
    return $out;
}

function primeiraDiferenca(array $a, array $b): array
{
    foreach ($a as $i => $g) {
        if (($b[$i] ?? null) !== $g) {
            return [$g, $b[$i] ?? null];
        }
    }
    return [];
}

/** @return ?array{int, string, string} */
function primeiraLinhaDiferente(string $a, string $b): ?array
{
    if ($a === $b) {
        return null;
    }
    $la = explode("\n", $a);
    $lb = explode("\n", $b);
    foreach ($la as $i => $l) {
        if (($lb[$i] ?? null) !== $l) {
            return [$i + 1, trecho($l, (string) ($lb[$i] ?? '')), trecho((string) ($lb[$i] ?? ''), $l)];
        }
    }
    return [count($la) + 1, '', trecho((string) $lb[count($la)], '')];
}

function trecho(string $s, string $outro): string
{
    $i = 0;
    $fim = min(strlen($s), strlen($outro));
    while ($i < $fim && $s[$i] === $outro[$i]) {
        $i++;
    }
    return substr($s, max(0, $i - 60), 180);
}

// ---- processos filhos: rodam com FIN_DADOS do lado que estão medindo

function filho(string $modo, array $op): void
{
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    require __DIR__ . '/../src/bootstrap.php';

    $pdo = \Financeiro\Database::connection();
    $pdo->beginTransaction();
    // nada do que acontece aqui fica: as garantias de toda página e o relógio das cotações, para não buscar na internet
    \Financeiro\Services\Horizonte::garantir();
    \Financeiro\Services\FotoPlano::garantir();
    $pdo->exec("REPLACE INTO cotacoes (moeda, valor, data, buscado_em) VALUES ('BRL', 1, " . \Financeiro\Sql::hoje() . ', ' . \Financeiro\Sql::agora() . ')');

    $saida = (string) $op['saida'];
    if ($modo === 'plano') {
        file_put_contents($saida, serialize(filhoPlano($pdo)));
    } else {
        filhoPagina($pdo, (string) $op['uri'], (string) $op['idioma'], (int) $op['usuario'], $saida);
    }
    $pdo->rollBack();
}

function filhoPlano(PDO $pdo): array
{
    $motor = \Financeiro\Services\MotorFinanceiro::class;
    $dados = $motor::dados();
    $gastos = \Financeiro\Services\GastosMensais::class;
    $usuarios = array_map('intval', $pdo->query('SELECT id FROM usuarios ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    return [
        'driver'    => \Financeiro\Sql::sqlite() ? 'sqlite' : 'mysql',
        'plano'     => $motor::calcularPlano(),
        'objetivo1' => $motor::calcular($dados, ['objetivo' => 1]),
        'objetivo2' => $motor::calcular($dados, ['objetivo' => 2]),
        'objetivo3' => $motor::calcular($dados, ['objetivo' => 3]),
        'gastos'    => $gastos::matriz(),
        'dados'     => $dados,
        'colacao'   => gruposDeTexto($pdo),
        'telas'     => telas($pdo),
        'telasFixas' => count(TELAS_FIXAS),
        'idiomas'   => ['pt', 'en', 'es'],
        'usuarios'  => $usuarios === [] ? [0] : array_values(array_unique([$usuarios[0], $usuarios[count($usuarios) - 1]])),
    ];
}

/** Textos do banco e uma lista difícil, agrupados e ordenados pelos pesos de cada banco. */
function gruposDeTexto(PDO $pdo): array
{
    $textos = ['a', 'A', 'á', 'Á', 'à', 'ã', 'â', 'ä', 'ae', 'æ', 'b', 'c', 'ç', 'Ç', 'd', 'é', 'ê', 'e', 'i', 'í', 'o', 'ó', 'õ', 'ô', 'ø', 'oe', 'œ', 'ss', 'ß', 'u', 'ú', 'ü', 'n', 'ñ', 'z',
        'água', 'Agua', 'AGUA', 'coração', 'Coracao', 'maçã', 'maca', 'pão', 'pao', '1', '10', '2', '01', '-1', '_a', ' a', 'a ', 'a  b', 'a-b', 'a b', 'ab', "a'b", 'a.b', 'a,b', 'a/b', 'a&b',
        '#1', '@x', '%', '$1', 'R$ 1', '€1', '(x)', '[x]', '{x}', '!x', '?x', '¿x', '¡x', '«x»', '“x”', 'º', 'ª', '°', '–', '—', '…', '🚗', '🛒', 'carro 🚗', 'Ελληνικά', 'Русский', '日本語'];
    foreach (['SELECT nome FROM categorias', 'SELECT DISTINCT descricao FROM lancamentos', 'SELECT nome FROM usuarios', 'SELECT nome FROM wishlist_itens'] as $sql) {
        array_push($textos, ...array_map('strval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN)));
    }
    $textos = array_values(array_unique($textos));
    $grupos = [];
    if (\Financeiro\Sql::sqlite()) {
        foreach ($textos as $t) {
            $grupos[\Financeiro\Colacao::chave($t)][] = $t;
        }
        ksort($grupos, SORT_STRING);
    } else {
        $st = $pdo->prepare('SELECT HEX(WEIGHT_STRING(CONVERT(? USING utf8mb4) COLLATE utf8mb4_0900_ai_ci))');
        foreach ($textos as $t) {
            $st->execute([$t]);
            $grupos[(string) $st->fetchColumn()][] = $t;
        }
        ksort($grupos, SORT_STRING);
    }
    $out = [];
    foreach ($grupos as $g) {
        sort($g, SORT_STRING);
        $out[] = $g;
    }
    return $out;
}

/** @return list<string> as telas: as fixas e as que dependem dos dados, em ordem */
function telas(PDO $pdo): array
{
    $out = TELAS_FIXAS;
    $meses = array_map('strval', $pdo->query('SELECT mes FROM meses ORDER BY mes')->fetchAll(PDO::FETCH_COLUMN));
    $hoje = date('Y-m-01');
    foreach ($meses as $m) {
        if ($m <= date('Y-m-01', strtotime($hoje . ' +1 month'))) {
            $out[] = '/mes?mes=' . $m;
            $out[] = '/lancamentos?mes=' . substr($m, 0, 7);
        }
    }
    $out[] = '/lancamentos?q=cafe';
    $out[] = '/relatorio?ano=' . date('Y');
    $out[] = '/relatorio?ano=' . (date('Y') - 1);
    foreach ([['lancamentos', '/lancamentos/%d/editar'], ['cartao_compras', '/cartao/compras/%d/editar'], ['wishlist_itens', '/wishlist/%d/editar'], ['categorias', '/categorias/%d/icone']] as [$tabela, $modelo]) {
        foreach ($pdo->query("SELECT id FROM {$tabela} ORDER BY id LIMIT 25")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $out[] = sprintf($modelo, (int) $id);
        }
    }
    foreach ($pdo->query('SELECT id FROM wishlist_itens ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $out[] = '/wishlist/' . (int) $id . '/foto';
    }
    return $out;
}

function filhoPagina(PDO $pdo, string $uri, string $idioma, int $usuario, string $saida): void
{
    $_SERVER['REQUEST_URI'] = $uri;
    $_GET = [];
    parse_str((string) parse_url($uri, PHP_URL_QUERY), $_GET);
    if ($usuario > 0) {
        $_SESSION['usuario_id'] = $usuario;
        $_SESSION['usuario_nome'] = (string) \Financeiro\Models\UsuarioModel::find($usuario)['nome'];
        $_SESSION['sessao_versao'] = (int) \Financeiro\Models\UsuarioModel::sessaoVersao($usuario);
    }
    $_SESSION['csrf_token'] = CSRF_FIXO;
    \Financeiro\Idioma::usar($idioma);

    // redirecionar() e renderErro() terminam com exit: o resultado sai no encerramento
    register_shutdown_function(static function () use ($saida): void {
        $corpo = '';
        while (ob_get_level() > 0) {
            $corpo = ob_get_clean() . $corpo;
        }
        $corpo = preg_replace('/(name="csrf_token" value=")[^"]*/', '$1CSRF', (string) $corpo);
        // a hora em que o PDF foi gerado, e o /ID, que é calculado com ela
        $corpo = preg_replace(['/\/CreationDate <[0-9A-F]+>/', '/\/ID \[<[0-9a-f]+> <[0-9a-f]+>\]/'], '', (string) $corpo);
        file_put_contents($saida, serialize(['status' => (int) http_response_code(), 'cabecalhos' => headers_list(), 'corpo' => (string) $corpo]));
    });
    ob_start();
    $router = require __DIR__ . '/../src/rotas.php';
    $router->dispatch('GET', $uri);
}
