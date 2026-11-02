<?php
/**
 * Editar: ‹ volta · editar · o mesmo partial preenchido · SALVAR ·
 * excluir (POST sem confirm; o aviso traz DESFAZER) · lançado por Ana · 24/09 22:10.
 * Variáveis: $lancamento, $volta, $form.
 */
use Financeiro\View;

$l = $lancamento;
?>
<div class="lf-pagina">
    <?php View::partial('lancamentos/_form', $form); ?>

    <div class="lf-rodape">
        <form method="post" action="/lancamentos/<?= (int) $l['id'] ?>/excluir" class="lf-excluir">
            <?= csrfField() ?>
            <input type="hidden" name="volta" value="<?= e($volta) ?>">
            <button type="submit" class="btn-link-danger"><?= e(t('Excluir lançamento')) ?></button>
        </form>
        <p class="lf-meta"><?= e(t('Lançado por {nome} em {data}', ['data' => diaMesHora((string) $l['criado_em']), 'nome' => (string) $l['usuario_nome']])) ?></p>
    </div>
</div>
