<?php
/**
 * Segunda parte do login, com a verificação em duas etapas ligada. Página sem o layout (auth/_casca), com o
 * desenho do login. Variáveis: $erro (?string), $recuperacao (bool: o campo pede um código de recuperação, com letras).
 * O POST aceita os dois tipos de código; o modo só muda o texto e o teclado do celular.
 */
$erro        = $erro ?? null;
$recuperacao = !empty($recuperacao);
$casca = ['titulo' => t('Código de verificação · MyMonetto'), 'classe' => 'pub-entrar', 'marca' => false, 'vitrine' => true, 'idiomas' => false, 'js' => ['/assets/js/entrar.js']];
require __DIR__ . '/_casca.php';
?>
    <div class="pub-lado">
        <form class="pub-form" method="post" action="/login/codigo" aria-labelledby="pub-form-t">
            <h1 class="pub-form-t" id="pub-form-t"><?= e(t('Falta só o código')) ?></h1>
            <p class="pub-form-sub"><?= e($recuperacao
                ? t('Digite um dos códigos de recuperação que você guardou ao ligar a verificação. Cada um vale uma vez.')
                : t('Abra o app autenticador no celular e digite o código de 6 dígitos que aparece para o MyMonetto.')) ?></p>
            <?= csrfField() ?>
            <?php if ($recuperacao): ?><input type="hidden" name="recuperacao" value="1"><?php endif; ?>
            <label class="campo">
                <span><?= e($recuperacao ? t('Código de recuperação') : t('Código de 6 dígitos')) ?></span>
                <?php if ($recuperacao): ?>
                    <input type="text" name="codigo" required maxlength="40"
                           autocomplete="off" autocapitalize="characters" autocorrect="off" spellcheck="false" enterkeyhint="go" data-autofocus<?= $erro !== null ? ' aria-invalid="true" aria-describedby="pub-erro"' : '' ?>>
                <?php else: ?>
                    <input class="pub-codigo" type="text" name="codigo" required maxlength="12"
                           inputmode="numeric" pattern="[0-9 ]*" autocomplete="one-time-code" enterkeyhint="go" data-autofocus<?= $erro !== null ? ' aria-invalid="true" aria-describedby="pub-erro"' : '' ?>>
                <?php endif; ?>
            </label>
            <?php if ($erro !== null): ?>
                <p class="pub-erro" id="pub-erro" role="alert"><?= e($erro) ?></p>
            <?php endif; ?>
            <button type="submit" class="btn btn-principal btn-block"><?= e(t('Entrar')) ?></button>
        </form>

        <div class="pub-links">
            <?php if ($recuperacao): ?>
                <a href="/login/codigo"><?= e(t('Usar o código do app autenticador')) ?></a>
            <?php else: ?>
                <a href="/login/codigo?recuperacao=1"><?= e(t('Sem o celular? Use um código de recuperação')) ?></a>
            <?php endif; ?>
            <details class="pub-dobra">
                <summary><?= e(t('Perdi o celular e os códigos')) ?></summary>
                <p><?= e(t('Peça a quem instalou o app: o comando que define uma senha nova também desliga a verificação em duas etapas. Depois, ligue de novo em Ajustes.')) ?></p>
            </details>
            <a href="/login"><?= e(t('Voltar e entrar com outra conta')) ?></a>
        </div>
    </div>
<?php require __DIR__ . '/_casca_fim.php'; ?>
