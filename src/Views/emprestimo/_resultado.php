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
    <p class="aj-erro emp-res-erro" role="alert"><?= e($erro) ?></p>
<?php return; endif;
if ($sim === null): ?>
    <section class="emp-convite"><p><?= e(t('Digite um valor para ver quanto falta depois, quando o empréstimo acaba e quanto de juros deixa de pagar.')) ?></p></section>
<?php return; endif;

$rot = mesAno($sim['mes']);
$quitaAgora = $sim['falta'] <= 0.01;
$antes = $sim['mesesAntes'];
$antesTxt = $antes === null || $antes < 1 ? '' : ($antes >= 24 && $antes % 12 === 0 ? t('{n} anos antes', ['n' => $antes / 12]) : tn($antes, '1 mês antes', '{n} meses antes'));
$anuncio = $sim['quita'] !== null
    ? t('Com {valor} em {mes}, falta {falta} e termina em {fim}.', ['valor' => money((float) $sim['aplicado']), 'mes' => $rot, 'falta' => money((float) $sim['falta']), 'fim' => mesAno($sim['quita'])])
    : t('Com {valor} em {mes}, falta {falta}.', ['valor' => money((float) $sim['aplicado']), 'mes' => $rot, 'falta' => money((float) $sim['falta'])]);

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
<section class="emp-res" aria-labelledby="emp-res-t" data-anuncio="<?= e($anuncio) ?>">
    <h2 class="emp-res-t" id="emp-res-t"><?= e(t('Adiantando {valor} em {mes}', ['valor' => money((float) $sim['aplicado']), 'mes' => $rot])) ?></h2>
    <?php if ($sim['ajustado']): ?>
        <p class="aj-ajuda"><?= e(t('O valor passa da dívida de {mes}: considerei {valor}, que quita tudo.', ['mes' => $rot, 'valor' => money((float) $sim['aplicado'])])) ?></p>
    <?php endif; ?>

    <p class="emp-res-v"><small><?= e(t('Falta depois')) ?></small><b><?= $quitaAgora ? e(t('Nada: quitado')) : valor((float) $sim['falta'], 'neutro', ['moeda' => true]) ?></b></p>
    <dl class="emp-dl num">
        <div><dt><?= e(t('Termina em')) ?></dt><dd><?= $sim['quita'] !== null ? e(monthLabel($sim['quita'])) : e(t('não dá para dizer')) ?><?php if ($antesTxt !== ''): ?><small><?= e($antesTxt) ?></small><?php endif; ?></dd></div>
        <div><dt><?= e(t('Juros a menos')) ?></dt><dd><?= $sim['jurosMenos'] !== null ? moedaSimbolo() . NBSP . e(num((float) $sim['jurosMenos'])) : '—' ?></dd></div>
        <div><dt><?= e(t('Conta no fim de {mes}', ['mes' => $rot])) ?></dt><dd<?= $sim['contaDepois'] < -0.004 ? ' class="neg"' : '' ?>><?= e(num((float) $sim['contaDepois'])) ?><small><?= e(t('sem adiantar, {valor}', ['valor' => num((float) $sim['contaAntes'])])) ?></small></dd></div>
    </dl>

    <?php if ($sim['alerta'] !== null): ?>
        <p class="emp-aviso <?= $sim['alerta'] === 'neg' ? 'alerta' : 'atencao' ?>"><?= e($sim['alerta'] === 'neg' ? t('A conta fica no vermelho: {valor} em {mes}.', ['valor' => money((float) $sim['pior']['fica']), 'mes' => monthLabel($sim['pior']['mes'])]) : t('A conta fica abaixo da reserva: {valor} em {mes}.', ['valor' => money((float) $sim['pior']['fica']), 'mes' => monthLabel($sim['pior']['mes'])])) ?></p>
    <?php endif; ?>

    <?php if ($nC > 1): ?>
        <figure class="emp-graf">
            <figcaption class="emp-leg">
                <span><i class="emp-l-antes" aria-hidden="true"></i><?= e(t('Só com as parcelas')) ?></span>
                <span><i class="emp-l-com" aria-hidden="true"></i><?= e(t('Com o adiantamento')) ?></span>
            </figcaption>
            <svg viewBox="0 0 <?= $VW ?> <?= $VH ?>" preserveAspectRatio="none" role="img" focusable="false" aria-label="<?= e(t('Dívida no fim de cada mês, de {de} a {ate}. {anuncio}', ['de' => monthLabel($curva[0]['mes']), 'ate' => monthLabel($curva[$nC - 1]['mes']), 'anuncio' => $anuncio])) ?>">
                <line class="emp-base" x1="0" x2="<?= $VW ?>" y1="<?= $VH ?>" y2="<?= $VH ?>"/>
                <polyline class="emp-antes" points="<?= e($pts('antes')) ?>"/>
                <polyline class="emp-com" points="<?= e($pts('com')) ?>"/>
            </svg>
            <div class="emp-eixo num"><span><?= e(monthLabel($curva[0]['mes'])) ?></span><span><?= e(monthLabel($curva[$nC - 1]['mes'])) ?></span></div>
        </figure>
        <details class="dobra emp-anos">
            <summary><?= e(t('Ver ano a ano')) ?></summary>
            <div class="emp-tab-rola">
                <table class="num">
                    <thead><tr><th scope="col"><?= e(t('Fim de')) ?></th><th scope="col"><?= e(t('Só parcelas')) ?></th><th scope="col"><?= e(t('Com adiantamento')) ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($anos as $c): ?>
                            <tr><th scope="row"><?= e(monthLabel($c['mes'])) ?></th><td><?= e(num((float) $c['antes'])) ?></td><td><?= (float) $c['com'] > 0.01 ? e(num((float) $c['com'])) : e(t('quitado')) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
    <?php endif; ?>

    <?php if ($sim['menorParcela'] !== null): ?>
        <details class="dobra emp-anos">
            <summary><?= e(t('Se a instituição baixar a parcela em vez do prazo')) ?></summary>
            <p class="emp-parc"><?= t('A parcela vai a cerca de {parcela}, termina em {fim} e os juros caem cerca de {juros}. Encurtar o prazo economiza mais; aliviar a parcela sobra mais por mês.', ['parcela' => '<b>' . e(money((float) $sim['menorParcela']['parcela'])) . '</b>', 'fim' => e(monthLabel($sim['menorParcela']['quita'])), 'juros' => moedaSimbolo() . NBSP . e(num((float) $sim['menorParcela']['jurosMenos'], 0))]) ?></p>
        </details>
    <?php endif; ?>

    <?php if ($catAmort !== null): ?>
        <a class="btn-link emp-ja-paguei" href="/emprestimo?<?= e(http_build_query(['valor' => numInput((float) $sim['aplicado']), 'mes' => $sim['mes'], 'pago' => numInput((float) $sim['aplicado'])])) ?>#emp-consolidar"><?= e(t('Já paguei: consolidar este adiantamento')) ?></a>
    <?php endif; ?>
</section>
