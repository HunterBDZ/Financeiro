<?php
/**
 * A vitrine das telas de entrar (entrar, criar conta, o código): o MyMonetto mostrado como ele é, numa janela que
 * flutua sobre o azul-marinho, com um cartão por cima. Os valores são um exemplo fixo (a página é pública: nunca dado
 * de verdade). A marca e a frase são lidas; a cena é decorativa (aria-hidden). A barra se preenche e o carimbo bate
 * uma vez, só com CSS; com o mouse, a cena se inclina de leve (entrar.js, [data-inclina]). No fundo, uma foto de
 * casas à noite (Wikimedia Commons, CC BY-SA 2.0), tingida de azul-marinho; o crédito fica no canto, com o link.
 */
use Financeiro\Icones;
use Financeiro\SiteIcone;

$sinalVitrine = SiteIcone::personalizado() !== null
    ? '<span class="marca-sinal is-imagem"><img src="' . e(SiteIcone::url()) . '" alt="" width="32" height="32"></span>'
    : '<span class="marca-sinal">' . svgIcon('marca', 20) . '</span>';
$segVitrine = [
    ['s-saiu', 9],
    ['s-contas', 40],
    ['s-metas', 19],
    ['s-gastar', 20],
    ['s-reserva', 12],
];
?>
<img class="vit-foto" src="<?= e(asset('/assets/img/entrar-casas-1600.webp')) ?>"
     srcset="<?= e(asset('/assets/img/entrar-casas-800.webp')) ?> 800w, <?= e(asset('/assets/img/entrar-casas-1600.webp')) ?> 1600w"
     sizes="(min-width: 1024px) 60vw, 100vw" width="1600" height="1068" alt="" fetchpriority="high">
<div class="vit-trilhos"><span></span><span></span><span></span></div>
<p class="vit-marca"><?= $sinalVitrine ?><b translate="no">MyMonetto</b></p>

<p class="vit-frase"><?= e(t('Quanto dá para gastar até o fim do mês, sem passar da reserva.')) ?></p>

<div class="vit-cena" aria-hidden="true" data-inclina>
    <div class="vit-janela">
        <p class="vit-rot"><?= e(t('Dá para gastar até dia 30')) ?></p>
        <p class="vit-num num"><span class="vit-moeda">R$</span>2.398<span class="cent">,66</span></p>
        <div class="vit-barra">
            <?php foreach ($segVitrine as $i => [$classe, $peso]): ?>
                <span class="<?= e($classe) ?>" style="flex:<?= (int) $peso ?>;--i:<?= (int) $i ?>"></span>
            <?php endforeach; ?>
        </div>
        <ul class="vit-contas">
            <li><?= Icones::html('casa', 18) ?><span><?= e(t('Aluguel')) ?></span><b class="num v-saida">−2.350<span class="cent">,00</span></b><?= carimbo('2026-11-05') ?></li>
            <li><?= Icones::html('lampada', 18) ?><span><?= e(t('Luz')) ?></span><b class="num v-previsto">238<span class="cent">,40</span></b><em><?= e(t('vence dia {dia}', ['dia' => 10])) ?></em></li>
            <li><?= Icones::html('wifi', 18) ?><span><?= e(t('Internet')) ?></span><b class="num v-previsto">119<span class="cent">,90</span></b><em><?= e(t('vence dia {dia}', ['dia' => 12])) ?></em></li>
        </ul>
    </div>

    <div class="vit-cartao">
        <span class="vit-cartao-n"><?= e(t('Cartão')) ?></span>
        <span class="vit-cartao-f"><?= e(t('Fatura de dezembro')) ?></span>
        <b class="vit-cartao-v num">1.646<span class="cent">,70</span></b>
    </div>
</div>

<p class="vit-credito"><a href="https://commons.wikimedia.org/wiki/File:Appartment_building_from_Banner_Street_2024-01-11.jpg" rel="noopener" target="_blank"><?= e(t('Foto: {autor}, {licenca}', ['autor' => 'Tom Page', 'licenca' => 'CC BY-SA 2.0'])) ?></a></p>
