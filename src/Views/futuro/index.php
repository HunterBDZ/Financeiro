<?php
/**
 * Anual — a conta mês a mês pelo plano do motor: a linha do saldo projetado ao fim de cada mês
 * (contra a reserva e a meta) e a tabela entradas / saídas / balanço com detalhe por mês; o
 * detalhe de cada mês leva à aba Mês daquele mês (onde se ajusta a previsão).
 * Variáveis: $hoje, $ref, $ultimo, $reserva, $meta, $linhas, $quita, $bate.
 */
$n    = count($linhas);
$vals = array_map(static fn($l) => (float) $l['fica'], $linhas);
$topo = max(max($vals), $meta, $reserva, 1.0);
$piso = min(0.0, min($vals));
$amp  = max(1e-9, $topo - $piso);
$topoV = $topo + $amp * 0.18;                   // folga em cima para o rótulo do valor
$pisoV = $piso - $amp * 0.05;
// O SVG estica na largura (preserveAspectRatio none) e só desenha traços; texto e pontos são HTML
// posicionados em %, então ficam do mesmo tamanho no celular e no computador.
$VW = 1000; $VH = 300;
$x  = static fn(int $i): float => ($i + 0.5) / max(1, $n) * $VW;
$y  = static fn(float $v): float => ($topoV - $v) / ($topoV - $pisoV) * $VH;
$f  = static fn(float $v): string => number_format($v, 1, '.', '');
$pc = static fn(float $u, int $total): string => number_format($u / $total * 100, 3, '.', '') . '%';

$ultReal = -1;
foreach ($linhas as $i => $l) {
    if ($l['estado'] === 'real') {
        $ultReal = $i;
    }
}
$caminho = static function (int $de, int $ate) use ($linhas, $x, $y, $f): string {
    $d = '';
    for ($i = $de; $i <= $ate; $i++) {
        $d .= ($i === $de ? 'M' : 'L') . $f($x($i)) . ',' . $f($y((float) $linhas[$i]['fica']));
    }
    return $d;
};
$iMin = 0;
foreach ($vals as $i => $v) {
    if ($v < $vals[$iMin]) {
        $iMin = $i;
    }
}
$partes = [];
if ($quita !== null) {
    $partes[] = 'quita o empréstimo em ' . monthLabel($quita);
}
if ($bate !== null) {
    $partes[] = 'bate a meta de ' . num($meta, 0) . ' em ' . monthLabel($bate);
}
$resumo = $partes === [] ? '' : ucfirst(implode(' e ', $partes)) . '.';

// linhas de referência: [valor, classe, rótulo]; o 0 só ganha rótulo se não encostar na reserva
$refs = [];
foreach ([[$reserva, 'fu-reserva', 'Reserva'], [$meta, 'fu-meta-l', 'Meta']] as [$v, $cls, $nome]) {
    if ($v > 0.004 && $v >= $piso && $v <= $topo) {
        $refs[] = [$v, $cls, $nome . ' ' . num($v, 0)];
    }
}
$zeroRot = $y(0.0) - $y($reserva) > $VH * 0.14 ? '0' : '';
$denso = $n > 8 ? ' is-denso' : '';
?>
<div class="fu">
    <h1 class="fu-titulo">Os próximos meses</h1>

    <!-- saldo projetado -->
    <section class="card fu-graf" aria-labelledby="fu-graf-t">
        <div class="card-head fu-graf-head">
            <h2 class="titulo" id="fu-graf-t">Conta no fim de cada mês</h2>
            <?php if ($resumo !== ''): ?><p class="fu-resumo num"><?= e($resumo) ?></p><?php endif; ?>
        </div>
        <figure class="fu-fig<?= $denso ?>" role="img" aria-label="Saldo em conta no fim de cada mês, de <?= e(monthLabel($linhas[0]['mes'])) ?> a <?= e(monthLabel($ultimo)) ?>. Os valores estão na lista mês a mês.">
            <?php if ($ultReal >= 0 && $ultReal < $n - 1): $xc = $x($ultReal) + $VW / max(1, $n) / 2; ?>
                <div class="fu-fases" aria-hidden="true">
                    <span class="fu-fase fu-fase-real" style="left:<?= $pc($xc, $VW) ?>">fechados</span>
                    <span class="fu-fase fu-fase-prev" style="left:<?= $pc($xc, $VW) ?>">previsão</span>
                </div>
            <?php endif; ?>
            <div class="fu-plot" aria-hidden="true">
                <svg class="fu-svg" viewBox="0 0 <?= $VW ?> <?= $VH ?>" preserveAspectRatio="none" focusable="false">
                    <?php if ($reserva > $pisoV && $reserva < $topoV): $yr = $y($reserva); ?>
                        <rect class="fu-zona" x="0" y="<?= $f($yr) ?>" width="<?= $VW ?>" height="<?= $f($VH - $yr) ?>"/>
                    <?php endif; ?>
                    <line class="fu-base" x1="0" x2="<?= $VW ?>" y1="<?= $f($y(0.0)) ?>" y2="<?= $f($y(0.0)) ?>"/>
                    <?php foreach ($refs as [$v, $cls]): ?>
                        <line class="<?= $cls ?>" x1="0" x2="<?= $VW ?>" y1="<?= $f($y($v)) ?>" y2="<?= $f($y($v)) ?>"/>
                    <?php endforeach; ?>
                    <?php if (isset($xc)): ?><line class="fu-div" x1="<?= $f($xc) ?>" x2="<?= $f($xc) ?>" y1="0" y2="<?= $VH ?>"/><?php endif; ?>
                    <?php if ($ultReal >= 1): ?><path class="fu-linha" d="<?= $caminho(0, $ultReal) ?>"/><?php endif; ?>
                    <path class="fu-linha fu-linha-prev" d="<?= $caminho(max(0, $ultReal), $n - 1) ?>"/>
                </svg>
                <?php foreach ($refs as [$v, $cls, $rot]): ?>
                    <span class="fu-ref <?= $cls ?>-rot" style="top:<?= $pc($y($v), $VH) ?>"><?= e($rot) ?></span>
                <?php endforeach; ?>
                <?php if ($zeroRot !== ''): ?><span class="fu-ref fu-base-rot" style="top:<?= $pc($y(0.0), $VH) ?>">0</span><?php endif; ?>
                <?php foreach ($linhas as $i => $l): $cls = $l['alerta'] === 'neg' ? 'fu-pt-neg' : ($l['alerta'] === 'warn' ? 'fu-pt-warn' : 'fu-pt-ok'); ?>
                    <i class="fu-pt <?= $cls ?><?= $l['mes'] === $ref ? ' is-ref' : '' ?>" style="left:<?= $pc($x($i), $VW) ?>;top:<?= $pc($y((float) $l['fica']), $VH) ?>"></i>
                <?php endforeach; ?>
                <?php foreach (array_unique([$iMin, $n - 1]) as $i):
                    $v = (float) $linhas[$i]['fica'];
                    $acima = $i !== $iMin || $v >= $reserva || $y($v) > $VH * 0.86;   // embaixo só se não encostar no eixo
                    $ponta = $i === $n - 1 ? ' is-fim' : ($i === 0 ? ' is-ini' : ''); ?>
                    <span class="fu-val <?= $acima ? 'is-acima' : 'is-abaixo' ?><?= $ponta ?>" style="left:<?= $pc($x($i), $VW) ?>;top:<?= $pc($y($v), $VH) ?>"><?= e(num($v, 0)) ?></span>
                <?php endforeach; ?>
            </div>
            <div class="fu-eixo" aria-hidden="true">
                <?php foreach ($linhas as $i => $l): $jan = substr($l['mes'], 5, 2) === '01'; ?>
                    <span class="fu-mx<?= $i % 2 === 1 ? ' is-impar' : '' ?><?= $l['mes'] === $ref ? ' is-ref' : '' ?>" style="left:<?= $pc($x($i), $VW) ?>"><?= e(mesCurto($l['mes'])) ?><?php if ($i === 0 || $jan): ?><small><?= e(substr($l['mes'], 0, 4)) ?></small><?php endif; ?></span>
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
                        <span class="fu-v fu-bal <?= e($balCls) ?>"><span class="sr-only">balanço </span><?= e(num($l['bal'], 0)) ?></span>
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
