<?php
/**
 * Editar (P4, SPEC §2.4): ‹ volta · editar · o mesmo partial preenchido · SALVAR ·
 * excluir (POST sem confirm; o aviso traz DESFAZER) · lançado por Matheus · 24/09 22:10.
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
            <button type="submit" class="btn-link-danger">excluir</button>
        </form>
        <p class="lf-meta">lançado por <?= e((string) $l['usuario_nome']) ?> · <?= e(diaMesHora((string) $l['criado_em'])) ?></p>
    </div>
</div>
