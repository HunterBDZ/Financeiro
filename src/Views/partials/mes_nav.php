<?php
/**
 * Navegação entre meses (P1). Uso:
 *   View::partial('partials/mes_nav', ['mes' => '2026-10-01', 'meses' => MesModel::todos(),
 *                                      'href' => '/mes?mes=', 'sufixo' => '#contas']);
 * Variáveis: $mes (AAAA-MM-01), $meses (linhas {mes, situacao} em ordem), $href (prefixo do link,
 * recebe o mês no fim), $sufixo (opcional, ex. '#contas'), $hoje (opcional, Y-m-d).
 * O mês por extenso é o botão de um seletor (details.menu, funciona sem JS): abre um painel com
 * todos os meses por ano, cada um com o ponto do estado. Setas redondas à direita.
 * O h1 da tela fica escondido para leitor de tela (o botão mostra o mesmo texto).
 */
$mes    = (string) ($mes ?? date('Y-m-01'));
$meses  = $meses ?? [];
$href   = (string) ($href ?? '?mes=');
$sufixo = (string) ($sufixo ?? '');
$hoje   = $hoje ?? null;

$lista = [];
$situacoes = [];
foreach ($meses as $m) {
    $k = is_array($m) ? (string) ($m['mes'] ?? '') : (string) $m;
    if ($k === '') {
        continue;
    }
    $lista[] = $k;
    $situacoes[$k] = is_array($m) ? (string) ($m['situacao'] ?? 'PREVISAO') : 'PREVISAO';
}
$i = array_search($mes, $lista, true);
$anterior = $i !== false && $i > 0 ? $lista[$i - 1] : null;
$proximo  = $i !== false && $i < count($lista) - 1 ? $lista[$i + 1] : null;
$estado   = estadoMes($mes, $situacoes[$mes] ?? 'PREVISAO', $hoje);
$extenso  = mb_convert_case(mesExtenso($mes), MB_CASE_TITLE);
$ano      = substr($mes, 0, 4);

$porAno = [];
foreach ($lista as $k) {
    $porAno[substr($k, 0, 4)][] = $k;
}
?>
<nav class="mes-nav" aria-label="mês">
    <?php if ($anterior !== null): ?>
        <a class="mes-nav-seta" href="<?= e($href . $anterior . $sufixo) ?>" rel="prev" aria-label="<?= e(monthLabel($anterior)) ?>">‹</a>
    <?php else: ?>
        <span class="mes-nav-seta is-off" aria-hidden="true">‹</span>
    <?php endif; ?>
    <h1 class="sr-only"><?= e($extenso . ' de ' . $ano) ?></h1>
    <details class="menu mes-picker" data-mes-picker>
        <summary aria-label="<?= e($extenso . ' de ' . $ano . ', trocar o mês') ?>">
            <span class="mes-nav-t" aria-hidden="true"><?= e($extenso) ?> <span class="mes-nav-ano num"><?= e($ano) ?></span></span>
            <?= svgIcon('chevron', 18, 'mes-picker-seta') ?>
        </summary>
        <div class="menu-painel mes-picker-painel">
            <?php foreach ($porAno as $a => $ks): ?>
                <p class="mes-picker-ano num"><?= e($a) ?></p>
                <ol class="mes-picker-grade">
                    <?php foreach ($ks as $k):
                        $est = estadoMes($k, $situacoes[$k], $hoje);
                        $atual = $k === $mes; ?>
                        <li><a class="mes-picker-m estado-<?= e($est) ?><?= $atual ? ' is-on' : '' ?>" href="<?= e($href . $k . $sufixo) ?>"<?= $atual ? ' aria-current="page"' : '' ?>>
                            <span><?= e(mesCurto($k)) ?></span><i class="ponto-estado" aria-hidden="true"></i><span class="sr-only"> de <?= e($a) ?>, <?= e(mb_strtolower(estadoLabel($est))) ?></span>
                        </a></li>
                    <?php endforeach; ?>
                </ol>
            <?php endforeach; ?>
            <p class="mes-picker-leg" aria-hidden="true">
                <span class="leg-real"><i class="ponto-estado"></i>Fechado</span>
                <span class="leg-aberto"><i class="ponto-estado"></i>Aberto</span>
                <span class="leg-previsto"><i class="ponto-estado"></i>Previsto</span>
            </p>
        </div>
    </details>
    <span class="estado estado-<?= e($estado) ?>"><?= e(estadoLabel($estado)) ?></span>
    <?php if ($proximo !== null): ?>
        <a class="mes-nav-seta" href="<?= e($href . $proximo . $sufixo) ?>" rel="next" aria-label="<?= e(monthLabel($proximo)) ?>">›</a>
    <?php else: ?>
        <span class="mes-nav-seta is-off" aria-hidden="true">›</span>
    <?php endif; ?>
</nav>
