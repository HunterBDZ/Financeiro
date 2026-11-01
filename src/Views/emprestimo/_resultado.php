<?php
/**
 * O resultado da simulação (também é a resposta de ?parcial=1): quanto falta, quando acaba, os juros
 * a menos, o que acontece com a conta e o gráfico da dívida. Sem simulação, só o convite.
 * Variáveis: $sit, $sim, $erro, $catAmort.
 */
if ($sit['quitado']) {
    return;
}
if ($erro !== null): ?>
    <p class="aviso aviso-error" role="alert"><?= e($erro) ?></p>
<?php return; endif;
if ($sim === null): ?>
    <section class="card emp-convite"><p>Digite um valor para ver quanto falta depois, quando o empréstimo acaba e quanto de juros vocês deixam de pagar.</p></section>
<?php return; endif;

$rot = monthLabel($sim['mes']);
$quitaAgora = $sim['falta'] <= 0.01;
$antes = $sim['mesesAntes'];
$antesTxt = $antes === null || $antes < 1 ? '' : ($antes === 1 ? '1 mês antes' : ($antes >= 24 && $antes % 12 === 0 ? ($antes / 12) . ' anos antes' : $antes . ' meses antes'));
$anuncio = 'Com ' . money((float) $sim['aplicado']) . ' em ' . $rot . ', falta ' . money((float) $sim['falta'])
    . ($sim['quita'] !== null ? ' e termina em ' . monthLabel($sim['quita']) : '') . '.';

// gráfico: dívida de hoje até acabar, com e sem o adiantamento (linhas de estilos diferentes)
$curva = $sim['curva'];
$nC = count($curva);
$VW = 600;
$VH = 170;
$topo = 1.0;
foreach ($curva as $c) {
    $topo = max($topo, (float) $c['antes'], (float) $c['com']);
}
$pts = static function (string $k) use ($curva, $nC, $VW, $VH, $topo): string {
    $out = [];
    foreach ($curva as $i => $c) {
        $x = $nC > 1 ? $i * $VW / ($nC - 1) : 0;
        $out[] = round($x, 1) . ',' . round($VH - (float) $c[$k] / $topo * ($VH - 6), 1);
    }
    return implode(' ', $out);
};
$anos = [];
foreach ($curva as $i => $c) {
    if (substr($c['mes'], 5, 2) === '12' || $i === $nC - 1) {
        $anos[] = $c;
    }
}
?>
<section class="card emp-res" aria-labelledby="emp-res-t" data-anuncio="<?= e($anuncio) ?>">
    <h2 class="emp-res-t" id="emp-res-t">Adiantando <?= e(money((float) $sim['aplicado'])) ?> em <?= e($rot) ?></h2>
    <?php if ($sim['ajustado']): ?>
        <p class="aviso">O valor passa da dívida de <?= e($rot) ?>: considerei <?= e(money((float) $sim['aplicado'])) ?>, que quita tudo.</p>
    <?php endif; ?>

    <p class="emp-res-v num"><small>Falta depois</small><b><?= $quitaAgora ? 'Nada: quitado' : e(money((float) $sim['falta'])) ?></b></p>
    <dl class="emp-dl num">
        <div><dt>Termina em</dt><dd><?= $sim['quita'] !== null ? e(monthLabel($sim['quita'])) : 'não dá para dizer' ?><?php if ($antesTxt !== ''): ?><small class="pos"><?= e($antesTxt) ?></small><?php endif; ?></dd></div>
        <div><dt>Juros a menos</dt><dd<?= $sim['jurosMenos'] !== null ? ' class="pos"' : '' ?>><?= $sim['jurosMenos'] !== null ? 'R$' . NBSP . e(num((float) $sim['jurosMenos'], 0)) : '—' ?></dd></div>
        <div><dt>Conta no fim de <?= e($rot) ?></dt><dd<?= $sim['contaDepois'] < -0.004 ? ' class="neg"' : '' ?>><?= e(num((float) $sim['contaDepois'], 0)) ?><small>sem adiantar, <?= e(num((float) $sim['contaAntes'], 0)) ?></small></dd></div>
    </dl>

    <?php if ($sim['alerta'] !== null): ?>
        <p class="aviso <?= $sim['alerta'] === 'neg' ? 'aviso-error' : 'aviso-warn' ?>"><?= $sim['alerta'] === 'neg' ? 'A conta fica no vermelho: ' : 'A conta fica abaixo da reserva: ' ?><?= e(money((float) $sim['pior']['fica'])) ?> em <?= e(monthLabel($sim['pior']['mes'])) ?>.</p>
    <?php endif; ?>

    <?php if ($nC > 1): ?>
        <figure class="emp-graf">
            <figcaption class="emp-leg">
                <span><i class="emp-l-antes" aria-hidden="true"></i>Só com as parcelas</span>
                <span><i class="emp-l-com" aria-hidden="true"></i>Com o adiantamento</span>
            </figcaption>
            <svg viewBox="0 0 <?= $VW ?> <?= $VH ?>" preserveAspectRatio="none" role="img" focusable="false" aria-label="<?= e('Dívida no fim de cada mês, de ' . monthLabel($curva[0]['mes']) . ' a ' . monthLabel($curva[$nC - 1]['mes']) . '. ' . $anuncio) ?>">
                <line class="emp-base" x1="0" x2="<?= $VW ?>" y1="<?= $VH ?>" y2="<?= $VH ?>"/>
                <polyline class="emp-antes" points="<?= e($pts('antes')) ?>"/>
                <polyline class="emp-com" points="<?= e($pts('com')) ?>"/>
            </svg>
            <div class="emp-eixo num"><span><?= e(monthLabel($curva[0]['mes'])) ?></span><span><?= e(monthLabel($curva[$nC - 1]['mes'])) ?></span></div>
        </figure>
        <details class="dobra emp-anos">
            <summary>Ver ano a ano</summary>
            <div class="emp-tab-rola">
                <table class="num">
                    <thead><tr><th scope="col">Fim de</th><th scope="col">Só parcelas</th><th scope="col">Com adiantamento</th></tr></thead>
                    <tbody>
                        <?php foreach ($anos as $c): ?>
                            <tr><th scope="row"><?= e(monthLabel($c['mes'])) ?></th><td><?= e(num((float) $c['antes'], 0)) ?></td><td><?= (float) $c['com'] > 0.01 ? e(num((float) $c['com'], 0)) : 'quitado' ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
    <?php endif; ?>

    <?php if ($sim['menorParcela'] !== null): ?>
        <details class="dobra emp-anos">
            <summary>Se a instituição baixar a parcela em vez do prazo</summary>
            <p class="emp-parc">A parcela vai a cerca de <b><?= e(money((float) $sim['menorParcela']['parcela'])) ?></b>, termina em <?= e(monthLabel($sim['menorParcela']['quita'])) ?> e os juros caem cerca de <?= 'R$' . NBSP . e(num((float) $sim['menorParcela']['jurosMenos'], 0)) ?>. Encurtar o prazo economiza mais; aliviar a parcela sobra mais por mês.</p>
        </details>
    <?php endif; ?>

    <?php if ($catAmort !== null): ?>
        <a class="btn btn-ghost btn-block" href="#emp-consolidar">Já paguei: consolidar este adiantamento</a>
    <?php endif; ?>
</section>
