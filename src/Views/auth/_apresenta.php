<?php
/** A coluna da esquerda das telas de entrar: a marca, o nome e a frase do que o app faz. */
use Financeiro\SiteIcone;

$sinalGrande = SiteIcone::personalizado() !== null
    ? '<span class="marca-sinal is-imagem pub-sinal"><img src="' . e(SiteIcone::url()) . '" alt="" width="40" height="40"></span>'
    : '<span class="marca-sinal pub-sinal">' . svgIcon('marca', 24) . '</span>';
?>
<div class="pub-apresenta">
    <?= $sinalGrande ?>
    <h1 class="pub-nome" translate="no">MyMonetto</h1>
    <p class="pub-frase"><?= e(t('Quanto dá para gastar até o fim do mês, sem passar da reserva.')) ?></p>
</div>
