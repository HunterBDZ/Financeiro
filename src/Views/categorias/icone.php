<?php
/**
 * Trocar o ícone de uma categoria (GET/POST /categorias/{id}/icone).
 * Variáveis: $cat (linha de categorias), $atual (chave em vigor), $volta.
 */
use Financeiro\Icones;
use Financeiro\View;

$nome = nomeCurto($cat);
?>
<div class="ic-pagina">
    <header class="lf-topo">
        <a class="lf-x lf-voltar" href="<?= e($volta) ?>" aria-label="<?= e(t('voltar')) ?>"><?= svgIcon('chevron', 20, 'lf-seta') ?></a>
        <h1 class="lf-titulo"><?= e(t('Ícone de {nome}', ['nome' => $nome])) ?></h1>
    </header>

    <div class="ic-atual">
        <?= Icones::html($atual, 80) ?>
        <p><?= t('Agora: {icone}. Escolha outro e salve.', ['icone' => '<b>' . e(Icones::rotulo($atual)) . '</b>']) ?></p>
    </div>

    <form class="ic-form" method="post" action="/categorias/<?= (int) $cat['id'] ?>/icone">
        <?= csrfField() ?>
        <input type="hidden" name="volta" value="<?= e($volta) ?>">
        <?php View::partial('partials/icone_picker', ['selecionado' => $atual, 'legenda' => t('Ícones')]); ?>
        <button type="submit" class="btn btn-primary btn-block ic-salvar"><?= e(t('Salvar ícone')) ?></button>
    </form>
</div>
