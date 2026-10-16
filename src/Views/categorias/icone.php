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
        <a class="lf-x lf-voltar" href="<?= e($volta) ?>" aria-label="voltar"><?= svgIcon('chevron', 20, 'lf-seta') ?></a>
        <h1 class="lf-titulo">Ícone de <?= e($nome) ?></h1>
    </header>

    <div class="ic-atual">
        <?= Icones::html($atual, 80) ?>
        <p>Agora: <b><?= e(Icones::rotulo($atual)) ?></b>. Escolha outro e salve.</p>
    </div>

    <form class="ic-form" method="post" action="/categorias/<?= (int) $cat['id'] ?>/icone">
        <?= csrfField() ?>
        <input type="hidden" name="volta" value="<?= e($volta) ?>">
        <?php View::partial('partials/icone_picker', ['selecionado' => $atual, 'legenda' => 'Ícones']); ?>
        <button type="submit" class="btn btn-primary btn-block ic-salvar">Salvar ícone</button>
    </form>
</div>
