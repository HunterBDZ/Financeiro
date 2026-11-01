<?php
/** A aba Ajuda: perguntas agrupadas, com busca. O "?" das telas abre direto a resposta. Variável: $grupos. */
?>
<div class="ajuda">
    <h1 class="aj-titulo"><?= e(t('Ajuda')) ?></h1>
    <label class="campo ajuda-busca"><span class="sr-only"><?= e(t('Buscar na ajuda')) ?></span><input type="search" placeholder="<?= e(t('Buscar na ajuda')) ?>" autocomplete="off" data-ajuda-busca></label>
    <p class="muted" data-ajuda-vazio hidden><?= e(t('Nada encontrado. Tente outra palavra.')) ?></p>
    <?php foreach ($grupos as $g): ?>
        <section class="card ajuda-grupo" data-ajuda-grupo>
            <h2 class="titulo"><?= e($g['titulo']) ?></h2>
            <?php foreach ($g['itens'] as $i): ?>
                <details class="ajuda-item" id="<?= e($i['id']) ?>" data-ajuda-item>
                    <summary><?= e($i['pergunta']) ?></summary>
                    <p><?= e($i['resposta']) ?></p>
                </details>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
</div>
