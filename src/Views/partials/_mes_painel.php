<?php
/**
 * O painel do seletor de meses (dentro de um details.menu.mes-picker): todos os meses por ano, cada um com o
 * estado (fechado, aberto, previsto) e, com $anual, o Relatório e o simulador no fim. Usado por partials/mes_nav
 * e partials/seletor_mes. Variáveis: $mes (AAAA-MM-01, o atual), $lista (meses em ordem), $situacoes (mês => situação),
 * $hoje (Y-m-d ou null), $href (prefixo do link), $sufixo, $anual (bool).
 */
$porAno = [];
foreach ($lista as $k) {
    $porAno[substr($k, 0, 4)][] = $k;
}
?>
<div class="menu-painel mes-picker-painel">
    <?php foreach ($porAno as $a => $ks): ?>
        <p class="mes-picker-ano num"><?= e($a) ?></p>
        <ol class="mes-picker-grade">
            <?php foreach ($ks as $k):
                $est = estadoMes($k, $situacoes[$k], $hoje);
                $atual = $k === $mes; ?>
                <li><a class="mes-picker-m estado-<?= e($est) ?><?= $atual ? ' is-on' : '' ?>" href="<?= e($href . $k . $sufixo) ?>"<?= $atual ? ' aria-current="page"' : '' ?>>
                    <span><?= e(mesCurto($k)) ?></span><i class="ponto-estado" aria-hidden="true"></i><span class="sr-only"> <?= e(t('de {ano}, {estado}', ['ano' => $a, 'estado' => mb_strtolower(estadoLabel($est))])) ?></span>
                </a></li>
            <?php endforeach; ?>
        </ol>
    <?php endforeach; ?>
    <p class="mes-picker-leg" aria-hidden="true">
        <span class="leg-real"><i class="ponto-estado"></i><?= e(t('Fechado')) ?></span>
        <span class="leg-aberto"><i class="ponto-estado"></i><?= e(t('Aberto')) ?></span>
        <span class="leg-previsto"><i class="ponto-estado"></i><?= e(t('Previsto')) ?></span>
    </p>
    <?php if ($anual): ?>
        <a class="mes-picker-anual" href="/relatorio"><?= svgIcon('relatorio', 18) ?><span><?= e(t('Relatório')) ?></span><?= svgIcon('chevron', 16) ?></a>
        <a class="mes-picker-anual" href="/futuro"><?= svgIcon('trend', 18) ?><span><?= e(t('Simular uma mudança')) ?></span><?= svgIcon('chevron', 16) ?></a>
    <?php endif; ?>
</div>
