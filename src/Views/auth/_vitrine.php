<?php
/**
 * A vitrine das telas de entrar (entrar, criar conta, o código): o MyMonetto mostrado como ele é, numa janela que
 * flutua sobre o azul-marinho, com um cartão por cima. Os valores são um exemplo fixo (a página é pública: nunca dado
 * de verdade). A marca e a frase são lidas; a cena é decorativa (aria-hidden). A barra se preenche e o carimbo bate
 * uma vez, só com CSS; com o mouse, a cena se inclina de leve (entrar.js, [data-inclina]). No fundo, as faixas: a
 * barra "para onde vai o dinheiro" repetida em diagonal (_vitrine_faixas.svg), nas cores do tema. Os números saem no
 * formato do idioma ($numV), como no resto do app.
 */
use Financeiro\Icones;
use Financeiro\SiteIcone;

$sinalVitrine = SiteIcone::personalizado() !== null
    ? '<span class="marca-sinal is-imagem"><img src="' . e(SiteIcone::url()) . '" alt="" width="32" height="32"></span>'
    : '<span class="marca-sinal">' . svgIcon('marca', 20) . '</span>';
// o número com os centavos menores, no formato do idioma: 2.398,66 em português, 2,398.66 em inglês
$numV = static function (float $v): string {
    $texto = _numero(abs($v), 2);
    $corte = (int) strrpos($texto, sepDecimal());
    return ($v < 0 ? MENOS : '') . e(substr($texto, 0, $corte)) . '<span class="cent">' . e(substr($texto, $corte)) . '</span>';
};
$segVitrine = [
    ['s-saiu', 9],
    ['s-contas', 40],
    ['s-metas', 19],
    ['s-gastar', 20],
    ['s-reserva', 12],
];
?>
<?php readfile(__DIR__ . '/_vitrine_faixas.svg'); ?>
<div class="vit-trilhos"><span></span><span></span><span></span></div>
<p class="vit-marca"><?= $sinalVitrine ?><b translate="no">MyMonetto</b></p>

<p class="vit-frase"><?= e(t('Quanto dá para gastar até o fim do mês, sem passar da reserva.')) ?></p>

<div class="vit-cena" aria-hidden="true" data-inclina>
    <div class="vit-janela">
        <p class="vit-rot"><?= e(t('Dá para gastar até dia 30')) ?></p>
        <p class="vit-num num"><span class="vit-moeda"><?= e(moedaSimbolo()) ?></span><?= $numV(2398.66) ?></p>
        <div class="vit-barra">
            <?php foreach ($segVitrine as $i => [$classe, $peso]): ?>
                <span class="<?= e($classe) ?>" style="flex:<?= (int) $peso ?>;--i:<?= (int) $i ?>"></span>
            <?php endforeach; ?>
        </div>
        <ul class="vit-contas">
            <li><?= Icones::html('casa', 18) ?><span><?= e(t('Aluguel')) ?></span><b class="num v-saida"><?= $numV(-2350) ?></b><?= carimbo('2026-11-05') ?></li>
            <li><?= Icones::html('lampada', 18) ?><span><?= e(t('Luz')) ?></span><b class="num v-previsto"><?= $numV(238.40) ?></b><em><?= e(t('vence dia {dia}', ['dia' => 10])) ?></em></li>
            <li><?= Icones::html('wifi', 18) ?><span><?= e(t('Internet')) ?></span><b class="num v-previsto"><?= $numV(119.90) ?></b><em><?= e(t('vence dia {dia}', ['dia' => 12])) ?></em></li>
        </ul>
    </div>

    <div class="vit-cartao">
        <span class="vit-cartao-n"><?= e(t('Cartão')) ?></span>
        <span class="vit-cartao-f"><?= e(t('Fatura de dezembro')) ?></span>
        <b class="vit-cartao-v num"><?= $numV(1646.70) ?></b>
    </div>
</div>
