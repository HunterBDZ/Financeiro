<?php
/**
 * Os idiomas como botões, cada um com o nome no próprio idioma. $idiomaDiscreto = true: a seleção discreta das páginas
 * de fora do app (texto pequeno, o atual em tinta cheia). $voltaIdioma: para onde voltar depois de trocar.
 */
use Financeiro\Idioma;

$idiomaAtual = Idioma::atual();
$voltaIdioma = (string) ($voltaIdioma ?? '');
?>
<form class="idi<?= !empty($idiomaDiscreto) ? ' idi-discreto' : '' ?>" method="post" action="/idioma" aria-label="<?= e(t('Idioma')) ?>">
    <?= csrfField() ?>
    <?php if ($voltaIdioma !== ''): ?><input type="hidden" name="volta" value="<?= e($voltaIdioma) ?>"><?php endif; ?>
    <?php foreach (Idioma::LISTA as $id => $dados): ?>
        <button type="submit" name="idioma" value="<?= e($id) ?>" lang="<?= e($dados['html']) ?>" class="idi-b<?= $id === $idiomaAtual ? ' is-on' : '' ?>"<?= $id === $idiomaAtual ? ' aria-current="true"' : '' ?>><?= e($dados['nome']) ?></button>
    <?php endforeach; ?>
</form>
