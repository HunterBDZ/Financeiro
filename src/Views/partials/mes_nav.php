<?php
/**
 * Navegação entre meses (P1). Uso:
 *   View::partial('partials/mes_nav', ['mes' => '2026-10-01', 'meses' => MesModel::todos(),
 *                                      'href' => '/mes?mes=', 'sufixo' => '#contas']);
 * Variáveis: $mes (AAAA-MM-01), $meses (linhas {mes, situacao} em ordem), $href (prefixo do link,
 * recebe o mês no fim), $sufixo (opcional, ex. '#contas'), $hoje (opcional, Y-m-d).
 * Saída: <nav class="mes-nav"><a>‹</a><strong>out/26</strong> <span class="estado …">○ previsto</span><a>›</a></nav>
 * Nas pontas a seta vira <span aria-disabled="true">.
 */
$mes    = (string) ($mes ?? date('Y-m-01'));
$meses  = $meses ?? [];
$href   = (string) ($href ?? '?mes=');
$sufixo = (string) ($sufixo ?? '');
$hoje   = $hoje ?? null;

$lista = [];
$situacao = 'PREVISAO';
foreach ($meses as $m) {
    $k = is_array($m) ? (string) ($m['mes'] ?? '') : (string) $m;
    if ($k === '') {
        continue;
    }
    $lista[] = $k;
    if ($k === $mes && is_array($m)) {
        $situacao = (string) ($m['situacao'] ?? 'PREVISAO');
    }
}
$i = array_search($mes, $lista, true);
$anterior = $i !== false && $i > 0 ? $lista[$i - 1] : null;
$proximo  = $i !== false && $i < count($lista) - 1 ? $lista[$i + 1] : null;
$estado   = estadoMes($mes, $situacao, $hoje);
?>
<nav class="mes-nav" aria-label="mês">
    <?php if ($anterior !== null): ?>
        <a class="mes-nav-seta" href="<?= e($href . $anterior . $sufixo) ?>" rel="prev" aria-label="<?= e(monthLabel($anterior)) ?>">‹</a>
    <?php else: ?>
        <span class="mes-nav-seta" aria-disabled="true">‹</span>
    <?php endif; ?>
    <strong><?= e(monthLabel($mes)) ?></strong> <span class="estado estado-<?= e($estado) ?>"><?= e(estadoLabel($estado)) ?></span>
    <?php if ($proximo !== null): ?>
        <a class="mes-nav-seta" href="<?= e($href . $proximo . $sufixo) ?>" rel="next" aria-label="<?= e(monthLabel($proximo)) ?>">›</a>
    <?php else: ?>
        <span class="mes-nav-seta" aria-disabled="true">›</span>
    <?php endif; ?>
</nav>
