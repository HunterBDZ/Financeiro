<?php
/** Ligar a verificação em duas etapas. Variáveis: $qr (SVG pronto), $chave (o segredo em Base32, em grupos), $erro. */
?>
<div class="grade aj-subtela sis">
    <?php \Financeiro\View::partial('ajustes/_sub_topo', ['titulo' => t('Ligar a verificação em duas etapas'), 'volta' => '/ajustes?aberto=duasetapas#duasetapas']); ?>

    <section class="faixa-grade sis-grupo" aria-labelledby="de-app-t">
        <div class="aj-sub">
        <h2 class="aj-sub-t" id="de-app-t"><span class="de-passo num" aria-hidden="true">1</span><?= e(t('Leia o código com o app autenticador')) ?></h2>
        <p><?= e(t('No celular, abra o app autenticador, escolha adicionar uma conta e aponte a câmera para o código.')) ?></p>
        <div class="sis-qr"><?= $qr ?></div>
        <details class="dobra sis-acao">
            <summary><?= e(t('Não dá para usar a câmera?')) ?></summary>
            <p class="aj-ajuda"><?= e(t('No app, escolha digitar a chave e copie esta, com o nome MyMonetto:')) ?></p>
            <p class="de-chave num" translate="no"><?= e($chave) ?></p>
        </details>
        </div>
    </section>

    <section class="faixa-grade sis-grupo" aria-labelledby="de-conf-t">
        <div class="aj-sub">
        <h2 class="aj-sub-t" id="de-conf-t"><span class="de-passo num" aria-hidden="true">2</span><?= e(t('Confirme com um código')) ?></h2>
        <form class="de-form" method="post" action="/ajustes/duas-etapas/ligar">
            <?= csrfField() ?>
            <div class="campo"><label for="de-cod"><?= e(t('Código de 6 dígitos que o app mostra')) ?></label>
                <span class="de-linha">
                    <input id="de-cod" type="text" name="codigo" class="de-codigo num" inputmode="numeric" pattern="[0-9 ]*" maxlength="12" autocomplete="one-time-code" required autofocus<?= $erro !== null ? ' aria-invalid="true" aria-describedby="de-erro"' : '' ?>>
                    <button type="submit" class="btn btn-principal"><?= e(t('Ligar')) ?></button>
                </span>
            </div>
            <?php if ($erro !== null): ?><p class="aj-erro" id="de-erro" role="alert"><?= e($erro) ?></p><?php endif; ?>
        </form>
        </div>
    </section>
</div>
