<?php
/**
 * Todos os avisos de "Precisa de atenção", quando são mais do que a faixa do Início mostra. Variáveis: $avisos.
 */
?>
<div class="dash aten-pg">
    <header class="lf-topo aten-topo">
        <a class="lf-x lf-voltar" href="/" aria-label="<?= e(t('voltar')) ?>"><?= svgIcon('chevron', 20, 'lf-seta') ?></a>
        <h1 class="lf-titulo"><?= e(t('Precisa de atenção')) ?></h1>
    </header>
    <?php if ($avisos === []): ?>
        <p class="card dash-vazio"><?= e(t('Nada precisa de atenção agora.')) ?></p>
    <?php else: ?>
        <?php \Financeiro\View::partial('partials/atencao', ['avisos' => $avisos, 'comTitulo' => false]); ?>
    <?php endif; ?>
</div>
