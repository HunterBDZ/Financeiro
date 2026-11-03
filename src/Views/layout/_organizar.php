<?php
/**
 * O modo organizar de uma lista de abas (menu.js). $parte = 'barra' vai antes da lista: o aviso para leitor de tela,
 * a barra com Pronto e Voltar à ordem padrão e as setas que cada aba ganha no modo. $parte = 'abrir' vai depois:
 * o botão que entra no modo (segurar uma aba também entra).
 */
$parte = (string) ($parte ?? 'barra');
?>
<?php if ($parte === 'abrir'): ?>
<button type="button" class="org-abrir" data-org-abrir><?= svgIcon('pen', 15) ?><span><?= e(t('Organizar menu')) ?></span></button>
<?php else: ?>
<p class="sr-only" aria-live="polite" data-org-aviso></p>
<div class="org-barra" data-org-barra hidden>
    <p class="org-dica"><?= e(t('Arraste as abas ou use as setas para mudar a ordem.')) ?></p>
    <div class="org-acoes">
        <button type="button" class="org-pronto" data-org-pronto><?= svgIcon('check', 16) ?><?= e(t('Pronto')) ?></button>
        <button type="button" class="org-padrao" data-org-voltar><?= e(t('Voltar à ordem padrão')) ?></button>
    </div>
</div>
<template data-org-setas>
    <span class="org-setas">
        <button type="button" class="org-seta" data-org-subir><?= svgIcon('arrow-up', 16) ?></button>
        <button type="button" class="org-seta" data-org-descer><?= svgIcon('arrow-down', 16) ?></button>
    </span>
</template>
<?php endif; ?>
