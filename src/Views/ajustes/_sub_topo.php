<?php
/**
 * O topo das subtelas de Ajustes (Temas, Categorias, Backup e atualização, Usar no celular, Duas etapas): um jeito só
 * de voltar (o "‹ Ajustes" acima do título) e o título no tamanho do topo das outras telas. É a primeira faixa da grade:
 *   <div class="grade aj-subtela"><?php View::partial('ajustes/_sub_topo', ['titulo' => ..., 'volta' => ...]); ?> ...
 * Variáveis: $titulo, $apoio (opcional), $volta (padrão /ajustes), $voltaRotulo (padrão "Ajustes"), $acoes (HTML à direita).
 */
$volta = (string) ($volta ?? '/ajustes');
$apoio = (string) ($apoio ?? '');
$acoes = (string) ($acoes ?? '');
?>
<header class="topo faixa-grade aj-sub-topo">
    <div class="topo-txt">
        <a class="aj-volta" href="<?= e($volta) ?>"><?= svgIcon('chevron', 16, 'ic-esq') ?><span><?= e((string) ($voltaRotulo ?? t('Ajustes'))) ?></span></a>
        <h1><?= e((string) $titulo) ?></h1>
        <?php if ($apoio !== ''): ?><p class="topo-sub"><?= e($apoio) ?></p><?php endif; ?>
    </div>
    <?php if ($acoes !== ''): ?><div class="topo-dir"><?= $acoes ?></div><?php endif; ?>
</header>
