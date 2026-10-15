<?php
/**
 * 2026-10-16 · parte de DADOS da mudança "a meta é a previsão padrão de todo mês" (opção B).
 * Rodar DEPOIS de 2026_10_16_meta_padrao.sql e de publicar o código.
 *
 *   sudo -u www-data php database/migrations/2026_10_16_meta_padrao.php            # só mostra
 *   sudo -u www-data php database/migrations/2026_10_16_meta_padrao.php --aplicar  # grava
 *   (na staging: cd ~/financeiro && php database/migrations/2026_10_16_meta_padrao.php …)
 *
 * Grava por PrevisaoLote (origem 'migracao'), em dois lotes — cada um sai com "desfazer
 * última alteração" no ⋯ da aba Mês (7 dias), o 2º primeiro:
 *  1. categorias de gasto COM meta: nos meses em PREVISAO, apaga as linhas herdadas da
 *     planilha (as que nenhum lote do casal gravou), para a meta valer de verdade. Linhas
 *     gravadas pelo casal (manual, propagar, sugestão, fechar) ficam como exceção do mês.
 *  2. "Delivery / comer fora" (arquivada): a previsão passa para "Restaurante/ Delivery" —
 *     a categoria que a substituiu. Se ela tem meta, a meta já cobre o gasto (contar os dois
 *     seria pagar o delivery duas vezes); se não tem, o valor é copiado para ela.
 */
declare(strict_types=1);

$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['HTTPS'] = 'on';
require __DIR__ . '/../../src/bootstrap.php';

use Financeiro\Database;
use Financeiro\Services\MotorFinanceiro;
use Financeiro\Services\PrevisaoLote;

const DELIVERY_VELHA = 'Delivery / comer fora';
const DELIVERY_NOVA  = 'Restaurante/ Delivery';

$aplicar = in_array('--aplicar', $argv, true);
$pdo = Database::connection();
$st = $pdo->query("SHOW COLUMNS FROM previsao_lotes LIKE 'origem'")->fetch();
if (!$st || !str_contains((string) $st['Type'], "'migracao'")) {
    fwrite(STDERR, "rode antes database/migrations/2026_10_16_meta_padrao.sql (falta a origem 'migracao')\n");
    exit(1);
}

$d     = MotorFinanceiro::dados();
$cats  = $d['cats'];
$prevM = array_values(array_map(static fn($m) => (string) $m['mes'], array_filter($d['meses'], static fn($m) => $m['situacao'] === 'PREVISAO')));
$uid   = (int) $pdo->query('SELECT MIN(id) FROM usuarios')->fetchColumn();

// linhas cruas de previsao nos meses PREVISAO
$cruas = [];
foreach ($pdo->query("SELECT p.categoria_id, p.mes, p.valor FROM previsao p JOIN meses m ON m.mes = p.mes WHERE m.situacao = 'PREVISAO'") as $r) {
    $cruas[(int) $r['categoria_id']][(string) $r['mes']] = (float) $r['valor'];
}
// o último valor que um lote não desfeito gravou em cada célula
$doCasal = [];
foreach ($pdo->query('SELECT g.categoria_id, g.mes, g.depois FROM previsao_log g JOIN previsao_lotes l ON l.lote = g.lote
                      WHERE l.desfeito_em IS NULL ORDER BY g.id') as $r) {
    $doCasal[(int) $r['categoria_id']][(string) $r['mes']] = $r['depois'] === null ? null : (float) $r['depois'];
}

// ---- lote 1: categorias com meta seguem a meta (sai o que veio da planilha)
$lote1 = [];
$resumo1 = [];
foreach ($d['metas'] as $cat => $meta) {
    foreach ($cruas[$cat] ?? [] as $mes => $valor) {
        $gravado = array_key_exists($mes, $doCasal[$cat] ?? []) ? $doCasal[$cat][$mes] : false;
        if ($gravado !== false && $gravado !== null && abs($gravado - $valor) < 0.005) {
            continue;                                        // o casal gravou este valor: é exceção do mês
        }
        $lote1[] = [$cat, $mes, null];
        $resumo1[$cat][] = [$mes, $valor];
    }
}

// ---- lote 2: a previsão da Delivery arquivada vai para Restaurante/ Delivery
$porNome = [];
foreach ($cats as $c) {
    $porNome[$c['nome']] = $c;
}
$lote2 = [];
$resumo2 = '';
$velha = $porNome[DELIVERY_VELHA] ?? null;
$nova  = $porNome[DELIVERY_NOVA] ?? null;
if ($velha !== null && $nova !== null && $velha['ativo'] === 0) {
    $linhas = array_filter($cruas[$velha['id']] ?? [], static fn($v) => $v > 0.004);
    if ($linhas !== []) {
        $metaNova = $d['metas'][$nova['id']] ?? null;
        foreach ($linhas as $mes => $valor) {
            $lote2[] = [$velha['id'], $mes, null];
            if ($metaNova === null && !isset($cruas[$nova['id']][$mes])) {
                $lote2[] = [$nova['id'], $mes, $valor];
            }
        }
        $vals = array_unique(array_map(static fn($v) => num($v, 0), $linhas));
        $resumo2 = sprintf('%s (arquivada): %d meses (%s → %s, %s/mês) passam para %s%s',
            DELIVERY_VELHA, count($linhas), monthLabel((string) array_key_first($linhas)), monthLabel((string) array_key_last($linhas)),
            implode(' / ', $vals), DELIVERY_NOVA,
            $metaNova !== null ? ', que já prevê a meta de ' . num($metaNova, 0) . '/mês (sem contar o delivery duas vezes)' : ' (copiado)');
    }
}

// ---- relatório
$plano = static function () {
    MotorFinanceiro::limparCache();
    return MotorFinanceiro::calcularPlano();
};
$fmtPlano = static function (array $p, float $reserva): string {
    $ult = (string) array_key_last($p);
    $abaixo = array_keys(array_filter($p, static fn($l) => $l['estado'] !== 'real' && $l['fica_na_conta'] < $reserva - 0.004));
    return sprintf('fim de %s %s · dívida em %s %s · quita %s · abaixo da reserva: %s',
        monthLabel($ult), num((float) $p[$ult]['fica_na_conta'], 0), monthLabel($ult), num((float) $p[$ult]['falta_pagar'], 0),
        ($q = MotorFinanceiro::quitaEm($p)) !== null ? monthLabel($q) : 'não quita até ' . monthLabel($ult),
        $abaixo === [] ? 'nenhum mês' : implode(' ', array_map('mesCurto', $abaixo)));
};
$reserva = (float) ($d['aj']['reserva_minima'] ?? 0.0);

echo ($aplicar ? "APLICANDO" : "SIMULAÇÃO (nada é gravado; use --aplicar)") . " — banco " . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n\n";
echo "1) categorias com meta passam a seguir a meta nos meses previstos:\n";
if ($resumo1 === []) {
    echo "   (nada: nenhuma linha herdada da planilha em categoria com meta)\n";
}
foreach ($resumo1 as $cat => $itens) {
    $vals = array_unique(array_map(static fn($i) => num($i[1], 0), $itens));
    printf("   %-28s meta %7s · sai a previsão antiga (%s) em %d meses: %s → %s\n", nomeCurto($cats[$cat]), num($d['metas'][$cat], 0),
        implode(' / ', $vals), count($itens), monthLabel($itens[0][0]), monthLabel(end($itens)[0]));
}
echo "\n2) " . ($resumo2 !== '' ? $resumo2 : 'Delivery: nada a fazer') . "\n\n";

$antes = $plano();
echo "antes:  " . $fmtPlano($antes, $reserva) . "\n";

if (!$aplicar) {
    $pdo->beginTransaction();
}
$l1 = $lote1 !== [] ? PrevisaoLote::gravar('migracao', $lote1, $uid) : null;
$l2 = $lote2 !== [] ? PrevisaoLote::gravar('migracao', $lote2, $uid) : null;
$depois = $plano();
echo "depois: " . $fmtPlano($depois, $reserva) . "\n";
if (!$aplicar) {
    $pdo->rollBack();
    MotorFinanceiro::limparCache();
    echo "\n(simulação desfeita)\n";
} else {
    echo "\nlotes: " . ($l1 ?? '—') . " (metas) · " . ($l2 ?? '—') . " (delivery)\n";
}
