<?php
/** Os idiomas como botões, cada um com o nome no próprio idioma. */
use Financeiro\Idioma;

$idiomaAtual = Idioma::atual();
?>
<form class="idi" method="post" action="/idioma" aria-label="<?= e(t('Idioma')) ?>">
    <?= csrfField() ?>
    <?php foreach (Idioma::LISTA as $id => $dados): ?>
        <button type="submit" name="idioma" value="<?= e($id) ?>" lang="<?= e($dados['html']) ?>" class="idi-b<?= $id === $idiomaAtual ? ' is-on' : '' ?>"<?= $id === $idiomaAtual ? ' aria-current="true"' : '' ?>><?= e($dados['nome']) ?></button>
    <?php endforeach; ?>
</form>
