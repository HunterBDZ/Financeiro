<?php
/** Sem nenhum mês no plano (a primeira configuração não terminou): diz o que falta e leva até lá. */
?>
<div class="grade ini">
    <?php \Financeiro\View::partial('partials/topo', ['titulo' => t('Nenhum mês cadastrado')]); ?>
    <section class="faixa-grade ini-vazio" aria-label="<?= e(t('Nenhum mês cadastrado')) ?>">
        <p><?= e(t('Ainda não há meses aqui. Termine a primeira configuração para começar.')) ?></p>
        <a class="btn btn-principal" href="/comecar"><?= e(t('Continuar a configuração')) ?></a>
    </section>
</div>
