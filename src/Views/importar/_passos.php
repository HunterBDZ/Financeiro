<?php
/**
 * Os passos do Importar, à vista no topo de cada tela: o arquivo, as colunas (só CSV) e a revisão. $atual (1 a 3),
 * $comColunas (bool: o passo das colunas existe para este arquivo; antes de escolher o arquivo, aparece como "se for CSV").
 * Também o erro que voltou do servidor: errosImportar() tira o aviso de erro da sessão, para a tela mostrá-lo junto
 * do campo (o layout só mostra o aviso depois do conteúdo, então ele não aparece duas vezes).
 */
$nomes = [1 => t('Arquivo'), 2 => ($comColunas ?? true) ? t('Colunas') : t('Colunas, se for CSV'), 3 => t('Revisar')];
?>
<ol class="imp-passos" aria-label="<?= e(t('Passos')) ?>">
    <?php foreach ($nomes as $n => $nome): ?>
        <li class="<?= $n < $atual ? 'is-feito' : ($n === $atual ? 'is-agora' : '') ?>"<?= $n === $atual ? ' aria-current="step"' : '' ?>><span class="imp-passo-n num"><?= $n ?></span><?= e($nome) ?></li>
    <?php endforeach; ?>
</ol>
