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
$W = 760; $H = 230; $ml = 58; $mr = 14; $mt = 22; $mb = 36;
$pw = $W - $ml - $mr;
$ph = $H - $mt - $mb;
$x = static fn(int $i): float => $ml + ($i + 0.5) * $pw / max(1, $n);
$y = static fn(float $v): float => $mt + ($topo - $v) / max(1e-9, $topo - $piso) * $ph;
$f = static fn(float $v): string => number_format($v, 1, '.', '');

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
$resumo = [];
if ($quita !== null) {
    $resumo[] = 'quita o empréstimo em ' . monthLabel($quita);
}
if ($bate !== null) {
    $resumo[] = 'bate a meta de ' . num($meta, 0) . ' em ' . monthLabel($bate);
}
$refs = [[0.0, 'fu-base', '0', ''], [$reserva, 'fu-reserva', num($reserva, 0), 'reserva'], [$meta, 'fu-meta-l', num($meta, 0), 'meta']];
?>
<div class="fu">

    <!-- saldo projetado -->
    <section class="card fu-graf" aria-labelledby="fu-graf-t">
        <div class="card-head">
            <h2 class="titulo" id="fu-graf-t">conta mês a mês</h2>
            <?php if ($resumo !== []): ?><span class="card-acao num fu-resumo"><?= e(implode(' · ', $resumo)) ?></span><?php endif; ?>
        </div>
        <figure class="fu-fig">
            <svg class="fu-svg" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="saldo em conta ao fim de cada mês, <?= e(monthLabel($linhas[0]['mes'])) ?> a <?= e(monthLabel($ultimo)) ?>">
                <?php foreach ($refs as [$v, $cls, $txt, $nome]): if ($v < $piso || $v > $topo || ($v <= 0.004 && $nome !== '')) { continue; } ?>
                    <line class="<?= $cls ?>" x1="<?= $ml ?>" x2="<?= $W - $mr ?>" y1="<?= $f($y($v)) ?>" y2="<?= $f($y($v)) ?>"/>
                    <text class="fu-tick" x="<?= $ml - 6 ?>" y="<?= $f($y($v) + 4) ?>" text-anchor="end"><?= e($txt) ?></text>
                    <?php if ($nome !== ''): ?><text class="fu-nota" x="<?= $W - $mr ?>" y="<?= $f($y($v) - 4) ?>" text-anchor="end"><?= e($nome) ?></text><?php endif; ?>
                <?php endforeach; ?>
                <?php if ($ultReal >= 0 && $ultReal < $n - 1): $xc = $x($ultReal) + $pw / max(1, $n) / 2; ?>
                    <line class="fu-grid" x1="<?= $f($xc) ?>" x2="<?= $f($xc) ?>" y1="<?= $mt - 10 ?>" y2="<?= $H - $mb ?>"/>
                    <text class="fu-ano" x="<?= $f($xc - 4) ?>" y="<?= $mt - 2 ?>" text-anchor="end">real</text>
                    <text class="fu-ano" x="<?= $f($xc + 4) ?>" y="<?= $mt - 2 ?>">projeção</text>
                <?php endif; ?>
                <?php if ($ultReal >= 1): ?><path class="fu-linha" d="<?= $caminho(0, $ultReal) ?>"/><?php endif; ?>
                <path class="fu-linha fu-linha-prev" d="<?= $caminho(max(0, $ultReal), $n - 1) ?>"/>
                <?php foreach ($linhas as $i => $l): $cls = $l['alerta'] === 'neg' ? 'fu-pt-neg' : ($l['alerta'] === 'warn' ? 'fu-pt-warn' : 'fu-pt-ok'); ?>
                    <circle class="fu-pt <?= $cls ?>" cx="<?= $f($x($i)) ?>" cy="<?= $f($y((float) $l['fica'])) ?>" r="3.5"><title><?= e(monthLabel($l['mes']) . ': ' . num((float) $l['fica'], 0)) ?></title></circle>
                <?php endforeach; ?>
                <?php foreach (array_unique([$iMin, $n - 1]) as $i): $v = (float) $linhas[$i]['fica']; $acima = $i !== $iMin || $v >= $reserva; ?>
                    <text class="fu-rot" x="<?= $f($x($i)) ?>" y="<?= $f($y($v) + ($acima ? -9 : 16)) ?>" text-anchor="<?= $i === $n - 1 ? 'end' : 'middle' ?>"><?= e(num($v, 0)) ?></text>
                <?php endforeach; ?>
                <?php foreach ($linhas as $i => $l): ?>
                    <text class="fu-tick" x="<?= $f($x($i)) ?>" y="<?= $H - $mb + 15 ?>" text-anchor="middle"><?= e(mesCurto($l['mes'])) ?></text>
                    <?php if ($i === 0 || substr($l['mes'], 5, 2) === '01'): ?>
                        <text class="fu-ano" x="<?= $f($x($i)) ?>" y="<?= $H - $mb + 29 ?>" text-anchor="middle"><?= e(substr($l['mes'], 0, 4)) ?></text>
                    <?php endif; ?>
                <?php endforeach; ?>
            </svg>
        </figure>
    </section>

    <!-- mês a mês -->
    <section class="card fu-meses" id="meses" aria-labelledby="fu-meses-t">
        <div class="card-head"><h2 class="titulo" id="fu-meses-t">mês a mês</h2></div>
        <p class="mes-base">mês fechado: só o lançado · mês aberto: lançado até hoje e o previsto do que falta · meses seguintes: previsão</p>
        <div class="fu-lista">
            <div class="fu-mes-head num">
                <span></span>
                <span class="fu-lbl">entradas</span>
                <span class="fu-lbl">saídas</span>
                <span class="fu-lbl">balanço</span>
                <span></span>
            </div>
            <?php foreach ($linhas as $l):
                $glifo = mb_substr(estadoLabel($l['estado']), 0, 1);
                $balCls = $l['bal'] < -0.004 ? 'neg' : ($l['bal'] > 0.004 ? 'pos' : '');
                ?>
                <details class="fu-mes<?= $l['mes'] === $ref ? ' is-ref' : '' ?>">
                    <summary class="num">
                        <span class="fu-m"><?= e(monthLabel($l['mes'])) ?> <span class="estado-<?= e($l['estado']) ?>"><?= e($glifo) ?></span></span>
                        <span class="fu-v"><?= e(num($l['entra'], 0)) ?></span>
                        <span class="fu-v"><?= e(num($l['sai'], 0)) ?></span>
                        <span class="fu-v fu-bal <?= e($balCls) ?>"><?= e(num($l['bal'], 0)) ?></span>
                    </summary>
                    <div class="fu-det num">
                        <?php if ($l['consignado'] > 0.004): ?>
                            <p>soldo bruto <?= e(num($l['entra_bruto'])) ?></p>
                            <p>consignado −<?= e(num($l['consignado'])) ?></p>
                        <?php endif; ?>
                        <?php if (abs($l['cred']) > 0.004): ?><p>⇄<?= NBSP ?>crédito <?= e(($l['cred'] > 0 ? '+' : '') . num($l['cred'])) ?></p><?php endif; ?>
                        <?php if (abs($l['poup']) > 0.004): ?><p>⇄<?= NBSP ?>poupança <?= e(($l['poup'] > 0 ? '+' : '') . num($l['poup'])) ?></p><?php endif; ?>
                        <?php if ($l['amort'] > 0.004): ?><p>amortização extra <?= e(num($l['amort'])) ?></p><?php endif; ?>
                        <p class="<?= e($l['alerta']) ?>"><?= $l['estado'] === 'real' ? 'conta no fim do mês' : 'conta no fim do mês (projeção)' ?> <?= e(num($l['fica'])) ?></p>
                        <p>dívida <?= e(num($l['divida'])) ?></p>
                        <a class="btn-link fu-abrir" href="/mes?mes=<?= e($l['mes']) ?>"><?= $l['estado'] === 'real' ? 'ver' : 'ver e ajustar' ?> <?= e(monthLabel($l['mes'])) ?> ›</a>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    </section>
</div>
