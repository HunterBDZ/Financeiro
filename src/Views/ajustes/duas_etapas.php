<?php
/** Ligar a verificação em duas etapas. Variáveis: $qr (SVG pronto), $chave (o segredo em Base32, em grupos), $erro. */
?>
<div class="aj sis">
    <p class="sis-voltar"><a href="/ajustes?aberto=duasetapas#duasetapas"><?= e(t('Voltar para Ajustes')) ?></a></p>
    <h1 class="aj-titulo"><?= e(t('Ligar a verificação em duas etapas')) ?></h1>

    <section class="card sis-grupo" aria-labelledby="de-app-t">
        <h2 class="titulo" id="de-app-t"><?= e(t('1. Leia o código com o app autenticador')) ?></h2>
        <p><?= e(t('No celular, abra o app autenticador, escolha adicionar uma conta e aponte a câmera para o código.')) ?></p>
        <div class="sis-qr"><?= $qr ?></div>
        <details class="sis-acao">
            <summary><?= e(t('Não dá para usar a câmera?')) ?></summary>
            <p class="aj-ajuda"><?= e(t('No app, escolha digitar a chave e copie esta, com o nome Financeiro:')) ?></p>
            <p class="de-chave num" translate="no"><?= e($chave) ?></p>
        </details>
    </section>

    <section class="card sis-grupo" aria-labelledby="de-conf-t">
        <h2 class="titulo" id="de-conf-t"><?= e(t('2. Confirme com um código')) ?></h2>
        <form class="aj-form" method="post" action="/ajustes/duas-etapas/ligar">
            <?= csrfField() ?>
            <label class="campo"><span><?= e(t('Código de 6 dígitos que o app mostra')) ?></span>
                <input type="text" name="codigo" class="de-codigo num" inputmode="numeric" pattern="[0-9 ]*" maxlength="12" autocomplete="one-time-code" required autofocus></label>
            <?php if ($erro !== null): ?><p class="aviso aviso-error" role="alert"><?= e($erro) ?></p><?php endif; ?>
            <button type="submit" class="btn btn-primary aj-salvar"><?= e(t('Ligar')) ?></button>
        </form>
    </section>
</div>
