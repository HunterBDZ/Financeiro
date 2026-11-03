<?php
/**
 * Login. Página sem o layout (auth/_casca). Variáveis: $erro (?string), $expirou (bool),
 * $login (string, o que foi digitado), $lembrar (bool; trocar o padrão é mudar o `checked`).
 * À esquerda, a vitrine (auth/_vitrine.php); à direita, só o formulário. Embaixo dele, numa linha: esqueci a
 * senha, o aviso do navegador e a privacidade.
 */
$erro    = $erro ?? null;
$expirou = !empty($expirou) && empty($erro);
$login   = (string) ($login ?? '');
$lembrar = $lembrar ?? false;
$casca = ['titulo' => t('Entrar · MyMonetto'), 'classe' => 'pub-entrar', 'marca' => false, 'vitrine' => true, 'js' => ['/assets/js/entrar.js']];
require __DIR__ . '/_casca.php';
?>
    <div class="pub-lado">
        <form class="pub-form" method="post" action="/login" aria-labelledby="pub-form-t">
            <h1 class="pub-form-t" id="pub-form-t"><?= e(t('Entrar')) ?></h1>
            <?= csrfField() ?>
            <label class="campo">
                <span><?= e(t('Usuário')) ?></span>
                <input type="text" name="login" value="<?= e($login) ?>" required maxlength="100"
                       autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false"
                       enterkeyhint="next"<?= $login === '' ? ' data-autofocus' : '' ?><?= $erro !== null ? ' aria-describedby="pub-erro"' : '' ?>>
            </label>
            <label class="campo">
                <span><?= e(t('Senha')) ?></span>
                <input type="password" name="senha" required autocomplete="current-password" enterkeyhint="go"<?= $login !== '' ? ' data-autofocus' : '' ?><?= $erro !== null ? ' aria-invalid="true" aria-describedby="pub-erro"' : '' ?>>
            </label>
            <?php if ($erro !== null): ?>
                <p class="pub-erro" id="pub-erro" role="alert"><?= e($erro) ?></p>
            <?php elseif (isset($_GET['restaurado'])): ?>
                <p class="pub-nota" role="status"><?= e(t('Backup restaurado. Entre de novo. O que havia antes ficou guardado em uma cópia.')) ?></p>
            <?php elseif ($expirou): ?>
                <p class="pub-nota" role="status"><?= e(t('A sessão expirou. Entre de novo.')) ?></p>
            <?php endif; ?>
            <label class="pub-lembrar">
                <span><?= e(t('Lembrar este aparelho')) ?></span>
                <input type="checkbox" name="lembrar" value="1"<?= $lembrar ? ' checked' : '' ?>>
            </label>
            <button type="submit" class="btn btn-principal btn-block"><?= e(t('Entrar')) ?></button>
        </form>

        <div class="pub-links">
            <details class="pub-dobra">
                <summary><?= e(t('Esqueci a senha')) ?></summary>
                <p><?= e(t('Outra pessoa com conta pode definir uma senha nova em Ajustes, em Pessoas. Se não houver outra pessoa, peça a quem instalou o app.')) ?></p>
            </details>
            <a href="/ajuda/conexao"><?= e(t('Aviso do navegador')) ?></a>
            <a href="/privacidade"><?= e(t('Privacidade e aviso')) ?></a>
        </div>
    </div>
<?php require __DIR__ . '/_casca_fim.php'; ?>
