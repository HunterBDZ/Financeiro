<?php
/**
 * A Ajuda, como um documento: o índice à esquerda (os grupos e as perguntas) e as respostas em texto corrido, cada
 * uma com o id que o "?" das telas usa (/ajuda#gastar). A resposta aberta pelo "?" fica marcada (:target). A busca
 * esconde o que não tem as palavras (app.js, data-ajuda-*). Subtela de Ajustes. Variável: $grupos.
 */
$idGrupo = static fn(int $i): string => 'ajuda-g' . $i;
?>
<div class="grade aj-subtela ajuda-doc">
    <?php \Financeiro\View::partial('ajustes/_sub_topo', ['titulo' => t('Ajuda')]); ?>

    <div class="faixa-grade ajuda-corpo">
        <nav class="ajuda-indice" aria-labelledby="ajuda-indice-t">
            <h2 class="ajuda-indice-t" id="ajuda-indice-t"><?= e(t('Nesta página')) ?></h2>
            <?php foreach ($grupos as $i => $g): ?>
                <p class="ajuda-indice-g"><a href="#<?= e($idGrupo($i)) ?>"><?= e($g['titulo']) ?></a></p>
                <ul>
                    <?php foreach ($g['itens'] as $item): ?>
                        <li><a href="#<?= e($item['id']) ?>"><?= e($item['pergunta']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        </nav>

        <div class="ajuda-texto">
            <label class="campo ajuda-busca"><span class="sr-only"><?= e(t('Buscar na ajuda')) ?></span><input type="search" placeholder="<?= e(t('Buscar na ajuda')) ?>" autocomplete="off" data-ajuda-busca></label>
            <p class="ajuda-vazio" data-ajuda-vazio hidden><?= e(t('Nada encontrado. Tente outra palavra.')) ?></p>
            <?php foreach ($grupos as $i => $g): ?>
                <section class="ajuda-grupo" id="<?= e($idGrupo($i)) ?>" aria-labelledby="<?= e($idGrupo($i)) ?>-t" data-ajuda-grupo>
                    <h2 class="ajuda-grupo-t" id="<?= e($idGrupo($i)) ?>-t"><?= e($g['titulo']) ?></h2>
                    <?php foreach ($g['itens'] as $item): ?>
                        <article class="ajuda-item" id="<?= e($item['id']) ?>" aria-labelledby="<?= e($item['id']) ?>-q" data-ajuda-item>
                            <h3 id="<?= e($item['id']) ?>-q"><?= e($item['pergunta']) ?></h3>
                            <p><?= e($item['resposta']) ?></p>
                            <?php if (!empty($item['link'])): ?><p><a class="link" href="<?= e($item['link'][0]) ?>"><?= e($item['link'][1]) ?></a></p><?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </section>
            <?php endforeach; ?>
            <p class="ajuda-rodape"><a class="link" href="/privacidade"><?= e(t('Privacidade, aviso e contato')) ?></a></p>
        </div>
    </div>
</div>
