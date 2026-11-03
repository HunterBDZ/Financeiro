<?php
/** Os códigos de recuperação, logo depois de ligar a verificação. Aparecem só esta vez. Variáveis: $codigos. */
?>
<div class="grade aj-subtela sis">
    <?php \Financeiro\View::partial('ajustes/_sub_topo', ['titulo' => t('Verificação em duas etapas ligada'), 'volta' => '/ajustes?aberto=duasetapas#duasetapas']); ?>

    <section class="faixa-grade sis-grupo" aria-labelledby="de-rec-t">
        <div class="aj-sub">
        <h2 class="aj-sub-t" id="de-rec-t"><?= e(t('Guarde os códigos de recuperação')) ?></h2>
        <p><?= e(t('Se você perder o celular ou apagar o app, entre com um destes códigos no lugar do código do app. Cada um vale uma vez.')) ?></p>
        <ol class="de-lista num" translate="no">
            <?php foreach ($codigos as $c): ?><li><?= e($c) ?></li><?php endforeach; ?>
        </ol>
        <p class="aj-ajuda atencao"><?= e(t('Eles aparecem só agora. Anote num papel ou guarde num lugar seguro, longe do celular.')) ?></p>
        <a class="btn btn-principal aj-salvar" href="/ajustes?aberto=duasetapas#duasetapas"><?= e(t('Já guardei')) ?></a>
        </div>
    </section>
</div>
