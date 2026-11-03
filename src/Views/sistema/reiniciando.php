<?php /** Enquanto o programa reinicia para ligar ou desligar a rede. Variável: $ligar. O cabeçalho Refresh volta sozinho. */ ?>
<div class="grade aj-subtela sis">
    <?php \Financeiro\View::partial('ajustes/_sub_topo', ['titulo' => t('Usar no celular')]); ?>
    <p class="faixa-grade aj-sub-aviso" role="status"><?= e($ligar ? t('Ligando o acesso pela rede…') : t('Desligando o acesso pela rede…')) ?> <?= e(t('Esta página abre sozinha em alguns segundos.')) ?></p>
</div>
