<?php /** Enquanto o programa reinicia para ligar ou desligar a rede. Variável: $ligar. O cabeçalho Refresh volta sozinho. */ ?>
<div class="aj sis">
    <h1 class="aj-titulo"><?= e(t('Usar no celular')) ?></h1>
    <p class="aviso" role="status"><?= e($ligar ? t('Ligando o acesso pela rede…') : t('Desligando o acesso pela rede…')) ?> <?= e(t('Esta página abre sozinha em alguns segundos.')) ?></p>
</div>
