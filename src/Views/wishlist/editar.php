<?php
/** Editar um item da Wishlist, na grade: o título é o nome do item, o formulário numa coluna e as outras ações embaixo. Variáveis: $item, $form, $taxas. */
use Financeiro\View;

$id = (int) $item['id'];
$csrf = csrfField();
?>
<div class="grade wl wl-editar">
    <?php View::partial('ajustes/_sub_topo', ['titulo' => (string) $item['nome'], 'apoio' => t('Editar item'), 'volta' => '/wishlist', 'voltaRotulo' => t('Wishlist')]); ?>
    <section class="faixa-grade"><div class="wl-editar-form"><?php View::partial('wishlist/_form', ['item' => $item, 'form' => $form, 'taxas' => $taxas]); ?></div></section>
    <section class="faixa-grade wl-editar-acoes" aria-label="<?= e(t('Outras ações')) ?>">
        <form method="post" action="/wishlist/<?= $id ?>/mover"><?= $csrf ?><input type="hidden" name="para" value="primeiro"><button class="btn btn-contorno" type="submit"><?= e(t('Colocar em 1º')) ?></button></form>
        <form method="post" action="/wishlist/<?= $id ?>/comprei"><?= $csrf ?><button class="btn btn-contorno" type="submit"><?= e(t('Comprei')) ?></button></form>
        <form method="post" action="/wishlist/<?= $id ?>/excluir"><?= $csrf ?><button class="btn-link wl-excluir" type="submit"><?= e(t('Excluir')) ?></button></form>
    </section>
</div>
