<?php
/**
 * "Precisa de atenção": a lista de avisos (Services\Atencao), cada um com o que aconteceu, um detalhe curto e uma ação.
 * Variáveis: $avisos; $max (null = todos; no Início, Atencao::MAX, e o resto vira "e mais N", que leva a /atencao);
 * $comTitulo (false na página da lista, que já tem o título).
 */
$max ??= null;
$comTitulo ??= true;
$mostrar = $max !== null ? array_slice($avisos, 0, $max) : $avisos;
$resto = count($avisos) - count($mostrar);
?>
<section class="card aten" <?= $comTitulo ? 'aria-labelledby="aten-t"' : 'aria-label="' . e(t('Precisa de atenção')) . '"' ?> data-atencao>
    <?php if ($comTitulo): ?><h2 class="titulo aten-t" id="aten-t"><?= e(t('Precisa de atenção')) ?></h2><?php endif; ?>
    <ul class="aten-lista">
        <?php foreach ($mostrar as $a): ?>
            <li class="aten-i<?= $a['nivel'] === 0 ? ' is-atrasado' : '' ?>" data-aviso="<?= e($a['tipo']) ?>">
                <span class="aten-ponto" aria-hidden="true"></span>
                <span class="aten-txt"><b><?= e($a['titulo']) ?></b><small><?= e($a['detalhe']) ?></small></span>
                <a class="aten-acao" href="<?= e($a['href']) ?>" aria-label="<?= e($a['acao'] . ': ' . $a['titulo']) ?>"><?= e($a['acao']) ?><?= svgIcon('chevron', 15) ?></a>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if ($resto > 0): ?>
        <a class="aten-mais" href="/atencao"><?= e(tn($resto, 'e mais 1', 'e mais {n}')) ?><?= svgIcon('chevron', 15) ?></a>
    <?php endif; ?>
</section>
