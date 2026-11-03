<?php
/**
 * Todos os avisos de "Precisa de atenção", quando são mais do que a faixa do Início mostra. Variáveis: $avisos.
 */
$voltar = '<a class="btn btn-contorno" href="/">' . svgIcon('chevron', 16, 'fe-volta-seta') . e(t('Voltar ao Início')) . '</a>';
?>
<div class="grade ini aten-pg">
    <?php \Financeiro\View::partial('partials/topo', ['titulo' => t('Precisa de atenção'), 'acoes' => $voltar]); ?>
    <div class="faixa-grade">
        <?php if ($avisos === []): ?>
            <p class="ini-nada"><?= e(t('Nada precisa de atenção agora.')) ?></p>
        <?php else: ?>
            <?php \Financeiro\View::partial('partials/atencao', ['avisos' => $avisos, 'comTitulo' => false]); ?>
        <?php endif; ?>
    </div>
</div>
