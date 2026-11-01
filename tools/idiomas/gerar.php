<?php
/**
 * Lê os textos marcados com t(), tn() e T(), lista o que falta traduzir e gera public/assets/js/i18n-<idioma>.js.
 * Uso: php tools/idiomas/gerar.php          gera os .js e mostra o que falta
 *      php tools/idiomas/gerar.php --json   só escreve em stdout as chaves de cada idioma que faltam
 */
$raiz = dirname(__DIR__, 2);

/** @return array{php: list<string>, js: list<string>} */
function chaves(string $raiz): array
{
    // literal PHP/JS em aspas simples ou duplas
    $lit = '(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")';
    $php = $js = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz . '/src', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php' || str_contains($f->getPathname(), '/idiomas/')) {
            continue;
        }
        $s = (string) file_get_contents($f->getPathname());
        preg_match_all('/(?<![\w>:$])t\(\s*' . $lit . '/u', $s, $m);
        array_push($php, ...array_map('literal', $m[1]));
        preg_match_all('/(?<![\w>:$])tn\(\s*[^,]+,\s*' . $lit . '\s*,\s*' . $lit . '/u', $s, $m);
        array_push($php, ...array_map('literal', $m[1]), ...array_map('literal', $m[2]));
    }
    // os nomes dos ícones vêm da lista gerada
    $icones = require $raiz . '/src/icones_lista.php';
    array_push($php, ...array_values($icones['grupos']), ...array_map(static fn($i) => $i[0], array_values($icones['icones'])));
    foreach (glob($raiz . '/public/assets/js/*.js') as $f) {
        if (str_contains($f, '/i18n')) {
            continue;
        }
        preg_match_all('/(?<![\w.$])T\(\s*' . $lit . '/u', (string) file_get_contents($f), $m);
        array_push($js, ...array_map('literal', $m[1]));
    }
    return ['php' => array_values(array_unique($php)), 'js' => array_values(array_unique($js))];
}

function literal(string $l): string
{
    $q = $l[0];
    $c = substr($l, 1, -1);
    return $q === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $c) : stripcslashes($c);
}

$ch = chaves($raiz);
$todas = array_values(array_unique([...$ch['php'], ...$ch['js']]));
$falta = [];
foreach (['en', 'es'] as $id) {
    $dic = require $raiz . '/src/idiomas/' . $id . '.php';
    $falta[$id] = array_values(array_filter($todas, static fn($k) => ($dic[$k] ?? '') === ''));
    if (!in_array('--json', $argv, true)) {
        $js = [];
        foreach ($ch['js'] as $k) {
            if (($dic[$k] ?? '') !== '') {
                $js[$k] = $dic[$k];
            }
        }
        ksort($js);
        file_put_contents($raiz . '/public/assets/js/i18n-' . $id . '.js',
            'window.FIN_DIC = ' . json_encode($js, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . ";\n");
    }
}
if (in_array('--json', $argv, true)) {
    echo json_encode($falta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
} else {
    echo count($todas), " textos; faltam en ", count($falta['en']), ", es ", count($falta['es']), "\n";
}
