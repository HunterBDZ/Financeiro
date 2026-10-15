<?php
/**
 * Fita com todos os meses cadastrados (rola na horizontal; mes.js centraliza o mês aberto).
 * Uso: View::partial('partials/mes_fita', ['mes' => '2026-10-01', 'meses' => MesModel::todos(), 'hoje' => $hoje]);
 * Cada mês leva o ponto do estado (cheio fechado · tinta aberto · vazado previsto) e o ano em 2 dígitos.
 */
$mes   = (string) ($mes ?? '');
$meses = $meses ?? [];
$hoje  = $hoje ?? null;
?>
<nav class="mes-fita" aria-label="todos os meses" data-mes-fita>
    <ol>
        <?php foreach ($meses as $m):
            $k = (string) $m['mes'];
            $est = estadoMes($k, (string) $m['situacao'], $hoje);
            $atual = $k === $mes; ?>
            <li><a class="mes-fita-m estado-<?= e($est) ?><?= $atual ? ' is-on' : '' ?>" href="/mes?mes=<?= e($k) ?>"<?= $atual ? ' aria-current="page"' : '' ?> title="<?= e(monthLabel($k) . ' ' . estadoLabel($est)) ?>">
                <span><?= e(mesCurto($k)) ?></span>
                <small><i class="ponto-estado" aria-hidden="true"></i><?= e(date('y', (int) strtotime($k))) ?><span class="sr-only">, <?= e(estadoLabel($est)) ?></span></small>
            </a></li>
        <?php endforeach; ?>
    </ol>
</nav>
