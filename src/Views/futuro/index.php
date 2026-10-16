<?php
/**
 * Anual — a conta mês a mês pelo plano do motor: a linha do saldo projetado ao fim de cada mês
 * (contra a reserva e a meta) e a tabela entradas / saídas / balanço com detalhe por mês; o
 * detalhe de cada mês leva à aba Mês daquele mês (onde se ajusta a previsão).
 * Variáveis: $hoje, $ref, $ultimo, $reserva, $meta, $linhas, $quita, $bate.
 */
$n    = count($linhas);
$vals = array_map(static fn($l) => (float) $l['fica'], $linhas);
// a meta só entra na escala se estiver perto da linha (senão a linha fica achatada; a meta vai no texto)
$comMeta = $meta > 0.004 && $meta <= max($vals) * 1.25;
$topo = max(max($vals), $reserva, $comMeta ? $meta : 0.0, 1.0);
$piso = min(0.0, min($vals));
$amp  = max(1e-9, $topo - $piso);
$topoV = $topo + $amp * 0.12;
$pisoV = $piso - $amp * 0.04;
// O SVG estica na largura (preserveAspectRatio none) e só desenha traços (sem escala no traço);
// rótulos e pontos são HTML posicionados em %, do mesmo tamanho no celular e no computador.
$VW = 1000; $VH = 300;
$x  = static fn(int $i): float => ($i + 0.5) / max(1, $n) * $VW;
$y  = static fn(float $v): float => ($topoV - $v) / ($topoV - $pisoV) * $VH;
$f  = static fn(float $v): string => number_format($v, 1, '.', '');
$pc = static fn(float $u, int $total): string => number_format($u / $total * 100, 3, '.', '') . '%';

// curva suave que nunca passa do valor real entre dois meses (cúbica monótona, Fritsch–Carlson)
$suave = static function (array $p) use ($f): string {
    $k = count($p);
    if ($k === 0) {
        return '';
    }
    if ($k === 1) {
        return 'M' . $f($p[0][0]) . ',' . $f($p[0][1]);
    }
    $d = [];
    for ($i = 0; $i < $k - 1; $i++) {
        $dx = $p[$i + 1][0] - $p[$i][0];
        $d[$i] = $dx != 0.0 ? ($p[$i + 1][1] - $p[$i][1]) / $dx : 0.0;
    }
    $m = [$d[0]];
    for ($i = 1; $i < $k - 1; $i++) {
        $m[$i] = $d[$i - 1] * $d[$i] <= 0 ? 0.0 : ($d[$i - 1] + $d[$i]) / 2;
    }
    $m[$k - 1] = $d[$k - 2];
    for ($i = 0; $i < $k - 1; $i++) {
        if ($d[$i] == 0.0) {
            $m[$i] = 0.0; $m[$i + 1] = 0.0;
            continue;
        }
        $a = $m[$i] / $d[$i]; $b = $m[$i + 1] / $d[$i]; $h = $a * $a + $b * $b;
        if ($h > 9) {
            $t = 3 / sqrt($h); $m[$i] = $t * $a * $d[$i]; $m[$i + 1] = $t * $b * $d[$i];
        }
    }
    $s = 'M' . $f($p[0][0]) . ',' . $f($p[0][1]);
    for ($i = 0; $i < $k - 1; $i++) {
        $hx = ($p[$i + 1][0] - $p[$i][0]) / 3;
        $s .= ' C' . $f($p[$i][0] + $hx) . ',' . $f($p[$i][1] + $m[$i] * $hx) . ' ' . $f($p[$i + 1][0] - $hx) . ','
            . $f($p[$i + 1][1] - $m[$i + 1] * $hx) . ' ' . $f($p[$i + 1][0]) . ',' . $f($p[$i + 1][1]);
    }
    return $s;
};
$pts   = array_map(static fn(int $i): array => [$x($i), $y($vals[$i])], array_keys($vals));
$linha = $suave($pts);
$area  = $linha . ' L' . $f($pts[$n - 1][0]) . ',' . $VH . ' L' . $f($pts[0][0]) . ',' . $VH . ' Z';

// o mês de hoje (referência) e o fim do plano
$iRef = 0;
foreach ($linhas as $i => $l) {
    if ($l['mes'] === $ref) {
        $iRef = $i;
    }
}
$fimFica  = $vals[$n - 1];
$delta    = $fimFica - $vals[$iRef];
$partes = [];
if ($quita !== null) {
    $partes[] = 'quita o empréstimo em ' . monthLabel($quita);
}
if ($bate !== null) {
    $partes[] = 'bate a meta de ' . num($meta, 0) . ' em ' . monthLabel($bate);
} elseif ($meta > 0.004) {
    $partes[] = 'a meta é ' . num($meta, 0);
}
$resumo = $partes === [] ? '' : ucfirst(implode(' e ', $partes)) . '.';
$denso = $n > 8 ? ' is-denso' : '';
// eixo apertado (celular): meses alternados, na paridade do 1º janeiro (os janeiros ficam visíveis)
$par = 0;
foreach ($linhas as $i => $l) {
    if (substr($l['mes'], 5, 2) === '01') {
        $par = $i % 2;
        break;
    }
}
?>
<div class="fu">
    <h1 class="fu-titulo pg-titulo">Os próximos meses</h1>

    <!-- saldo projetado -->
    <section class="card fu-graf" aria-labelledby="fu-graf-t">
        <div class="fu-graf-head">
            <h2 class="fu-graf-t" id="fu-graf-t">Conta no fim de <?= e(monthLabel($ultimo)) ?></h2>
            <p class="fu-graf-v"><b class="num">R$ <?= e(num($fimFica, 0)) ?></b><span class="num <?= $delta >= 0 ? 'pos' : 'neg' ?>"><?= e(($delta >= 0 ? '+' : MENOS) . num(abs($delta), 0)) ?> desde <?= e(monthLabel($linhas[$iRef]['mes'])) ?></span></p>
            <?php if ($resumo !== ''): ?><p class="fu-resumo num"><?= e($resumo) ?></p><?php endif; ?>
        </div>
        <figure class="fu-fig<?= $denso ?>" role="img" aria-label="Conta no fim de cada mês, de <?= e(monthLabel($linhas[0]['mes'])) ?> a <?= e(monthLabel($ultimo)) ?>. Os valores estão na lista mês a mês.">
            <div class="fu-plot" aria-hidden="true">
                <svg class="fu-svg" viewBox="0 0 <?= $VW ?> <?= $VH ?>" preserveAspectRatio="none" focusable="false">
                    <path class="fu-area" d="<?= $area ?>"/>
                    <?php if ($reserva > $pisoV && $reserva < $topoV): ?>
                        <line class="fu-reserva" x1="0" x2="<?= $VW ?>" y1="<?= $f($y($reserva)) ?>" y2="<?= $f($y($reserva)) ?>"/>
                    <?php endif; ?>
                    <?php if ($comMeta): ?>
                        <line class="fu-meta-l" x1="0" x2="<?= $VW ?>" y1="<?= $f($y($meta)) ?>" y2="<?= $f($y($meta)) ?>"/>
                    <?php endif; ?>
                    <path class="fu-linha" d="<?= $linha ?>"/>
                </svg>
                <?php if ($reserva > $pisoV && $reserva < $topoV): ?>
                    <span class="fu-ref fu-reserva-rot num" style="top:<?= $pc($y($reserva), $VH) ?>">Reserva <?= e(num($reserva, 0)) ?></span>
                <?php endif; ?>
                <?php if ($comMeta): ?>
                    <span class="fu-ref fu-meta-rot num" style="top:<?= $pc($y($meta), $VH) ?>">Meta <?= e(num($meta, 0)) ?></span>
                <?php endif; ?>
                <i class="fu-pt fu-pt-hoje" style="left:<?= $pc($x($iRef), $VW) ?>;top:<?= $pc($y($vals[$iRef]), $VH) ?>"></i>
                <span class="fu-hoje" style="left:<?= $pc($x($iRef), $VW) ?>;top:<?= $pc($y($vals[$iRef]), $VH) ?>"><?= e(monthLabel($linhas[$iRef]['mes'])) ?></span>
                <i class="fu-pt fu-pt-fim" style="left:<?= $pc($x($n - 1), $VW) ?>;top:<?= $pc($y($fimFica), $VH) ?>"></i>
            </div>
            <div class="fu-eixo" aria-hidden="true">
                <?php foreach ($linhas as $i => $l): ?>
                    <span class="fu-mx<?= $i % 2 !== $par ? ' is-salta' : '' ?><?= $l['mes'] === $ref ? ' is-ref' : '' ?>" style="left:<?= $pc($x($i), $VW) ?>"><?= e(mesCurto($l['mes'])) ?></span>
                    <?php if ($i === 0 || substr($l['mes'], 5, 2) === '01'): ?><span class="fu-ano" style="left:<?= $pc($x($i), $VW) ?>"><?= e(substr($l['mes'], 0, 4)) ?></span><?php endif; ?>
                <?php endforeach; ?>
            </div>
        </figure>
    </section>

    <!-- mês a mês -->
    <section class="card fu-meses" id="meses" aria-labelledby="fu-meses-t">
        <div class="card-head"><h2 class="titulo" id="fu-meses-t">Mês a mês</h2></div>
        <p class="mes-base">Mês fechado mostra só o que foi lançado. O mês aberto soma o lançado até hoje e o previsto do que falta. Os seguintes são previsão.</p>
        <div class="fu-lista">
            <div class="fu-mes-head num">
                <span></span>
                <span class="fu-lbl">Entradas</span>
                <span class="fu-lbl">Saídas</span>
                <span class="fu-lbl">Balanço</span>
                <span class="fu-lbl fu-largo">Conta no fim</span>
                <span class="fu-lbl fu-largo">Dívida</span>
                <span></span>
            </div>
            <?php foreach ($linhas as $l):
                $balCls = $l['bal'] < -0.004 ? 'neg' : ($l['bal'] > 0.004 ? 'pos' : '');
                ?>
                <details class="fu-mes<?= $l['mes'] === $ref ? ' is-ref' : '' ?>">
                    <summary class="num">
                        <span class="fu-m"><span class="estado estado-<?= e($l['estado']) ?>"><span class="sr-only"><?= e(estadoLabel($l['estado'])) ?></span></span><?= e(monthLabel($l['mes'])) ?></span>
                        <span class="fu-v"><span class="sr-only">entradas </span><?= e(num($l['entra'], 0)) ?></span>
                        <span class="fu-v"><span class="sr-only">saídas </span><?= e(num($l['sai'], 0)) ?></span>
                        <span class="fu-v fu-bal <?= e($balCls) ?>"><span class="sr-only">balanço </span><?= e(($l['bal'] > 0.004 ? '+' : '') . num($l['bal'], 0)) ?></span>
                        <span class="fu-v fu-largo fu-fica <?= e($l['alerta']) ?>"><span class="sr-only">conta no fim </span><?= e(num((float) $l['fica'], 0)) ?></span>
                        <span class="fu-v fu-largo"><span class="sr-only">dívida </span><?= e(num((float) $l['divida'], 0)) ?></span>
                    </summary>
                    <div class="fu-det num">
                        <?php if ($l['consignado'] > 0.004): ?>
                            <p><span>Soldo bruto</span> <?= e(num($l['entra_bruto'])) ?></p>
                            <p><span>Consignado</span> −<?= e(num($l['consignado'])) ?></p>
                        <?php endif; ?>
                        <?php if (abs($l['cred']) > 0.004): ?><p><span>Crédito do empréstimo</span> <?= e(($l['cred'] > 0 ? '+' : '') . num($l['cred'])) ?></p><?php endif; ?>
                        <?php if (abs($l['poup']) > 0.004): ?><p><span>Poupança</span> <?= e(($l['poup'] > 0 ? '+' : '') . num($l['poup'])) ?></p><?php endif; ?>
                        <?php if ($l['amort'] > 0.004): ?><p><span>Amortização extra</span> <?= e(num($l['amort'])) ?></p><?php endif; ?>
                        <p class="<?= e($l['alerta']) ?>"><span><?= $l['estado'] === 'real' ? 'Conta no fim do mês' : 'Conta no fim do mês, previsão' ?></span> <?= e(num($l['fica'])) ?></p>
                        <p><span>Dívida</span> <?= e(num($l['divida'])) ?></p>
                        <a class="btn-link fu-abrir" href="/mes?mes=<?= e($l['mes']) ?>"><?= $l['estado'] === 'real' ? 'Ver' : 'Ver e ajustar' ?> <?= e(monthLabel($l['mes'])) ?></a>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    </section>
</div>
