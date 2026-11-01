<?php
/** Editar um item da Wishlist. Variáveis: $item, $form, $taxas. */
use Financeiro\View;

$id = (int) $item['id'];
$csrf = csrfField();
?>
<div class="wl wl-editar">
    <header class="wl-topo"><h1>Editar item</h1><a class="btn-link" href="/wishlist">Voltar para a lista</a></header>
    <section class="card"><?php View::partial('wishlist/_form', ['item' => $item, 'form' => $form, 'taxas' => $taxas]); ?></section>
    <section class="card wl-editar-acoes" aria-label="Outras ações">
        <form method="post" action="/wishlist/<?= $id ?>/mover"><?= $csrf ?><input type="hidden" name="para" value="primeiro"><button class="btn btn-ghost" type="submit">Colocar em 1º</button></form>
        <form method="post" action="/wishlist/<?= $id ?>/comprei"><?= $csrf ?><button class="btn btn-ghost" type="submit">Comprei</button></form>
        <form method="post" action="/wishlist/<?= $id ?>/excluir"><?= $csrf ?><button class="btn-link-danger" type="submit">Excluir</button></form>
    </section>
</div>
