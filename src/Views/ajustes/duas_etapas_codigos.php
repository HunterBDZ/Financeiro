<?php
/** Os códigos de recuperação, logo depois de ligar a verificação. Aparecem só esta vez. Variáveis: $codigos. */
?>
<div class="aj sis">
    <h1 class="aj-titulo"><?= e(t('Verificação em duas etapas ligada')) ?></h1>

    <section class="card sis-grupo" aria-labelledby="de-rec-t">
        <h2 class="titulo" id="de-rec-t"><?= e(t('Guarde os códigos de recuperação')) ?></h2>
        <p><?= e(t('Se você perder o celular ou apagar o app, entre com um destes códigos no lugar do código do app. Cada um vale uma vez.')) ?></p>
        <ol class="de-lista num" translate="no">
            <?php foreach ($codigos as $c): ?><li><?= e($c) ?></li><?php endforeach; ?>
        </ol>
        <p class="aviso aviso-warn"><?= e(t('Eles aparecem só agora. Anote num papel ou guarde num lugar seguro, longe do celular.')) ?></p>
        <a class="btn btn-primary aj-salvar" href="/ajustes?aberto=duasetapas#duasetapas"><?= e(t('Já guardei')) ?></a>
    </section>
</div>
