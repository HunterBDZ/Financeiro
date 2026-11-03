<?php
/**
 * A primeira conta do site, sem terminal: aparece só enquanto o banco não tem nenhuma. Página sem o layout (auth/_casca).
 * Variáveis: $erro (?string), $nome e $login (o que foi digitado), $pedeCodigo (servidor: o código de instalação).
 * A regra da senha fica à vista embaixo do campo (não some ao digitar), e o olho mostra o que foi digitado.
 */
$erro  = $erro ?? null;
$pedeCodigo = (bool) ($pedeCodigo ?? false);
$nome  = (string) ($nome ?? '');
$login = (string) ($login ?? '');
$casca = ['titulo' => t('Criar conta · MyMonetto'), 'classe' => 'pub-entrar', 'marca' => false, 'vitrine' => true, 'js' => ['/assets/js/entrar.js']];
require __DIR__ . '/_casca.php';
?>
    <div class="pub-lado">
        <form class="pub-form" method="post" action="/criar-conta" aria-labelledby="pub-form-t">
            <h1 class="pub-form-t" id="pub-form-t"><?= e(t('Falta só a sua conta')) ?></h1>
            <p class="pub-form-sub"><?= e(t('O MyMonetto está instalado. Depois, em Ajustes, você pode dar acesso a quem divide as contas com você.')) ?></p>
            <?= csrfField() ?>
            <label class="campo">
                <span><?= e(t('Seu nome')) ?></span>
                <input type="text" name="nome" value="<?= e($nome) ?>" required maxlength="100" autocomplete="name" enterkeyhint="next" data-autofocus>
            </label>
            <label class="campo">
                <span><?= e(t('Nome de usuário')) ?></span>
                <input type="text" name="login" value="<?= e($login) ?>" required maxlength="100" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" enterkeyhint="next">
            </label>
            <div class="campo">
                <label for="pub-senha"><?= e(t('Senha')) ?></label>
                <span class="pub-senha">
                    <input id="pub-senha" type="password" name="senha" required minlength="8" autocomplete="new-password" aria-describedby="pub-senha-dica" enterkeyhint="<?= $pedeCodigo ? 'next' : 'go' ?>">
                    <button type="button" class="pub-olho" data-mostrar-senha="pub-senha" aria-pressed="false" aria-label="<?= e(t('Mostrar a senha')) ?>" data-rotulo-mostrar="<?= e(t('Mostrar a senha')) ?>" data-rotulo-esconder="<?= e(t('Esconder a senha')) ?>" hidden><?= svgIcon('eye', 18) ?></button>
                </span>
                <small class="pub-dica" id="pub-senha-dica"><?= e(t('Pelo menos 8 caracteres.')) ?></small>
            </div>
            <?php if ($pedeCodigo): ?>
            <label class="campo">
                <span><?= e(t('Código de instalação')) ?></span>
                <input type="text" name="codigo" required maxlength="40" autocomplete="one-time-code" autocapitalize="characters" autocorrect="off" spellcheck="false" aria-describedby="codigo-ajuda" enterkeyhint="go">
                <small class="pub-dica" id="codigo-ajuda"><?= e(t('Para ninguém criar a conta antes de você, o MyMonetto mostrou um código no fim da instalação. No Docker, ele está em docker compose logs.')) ?></small>
            </label>
            <?php endif; ?>
            <?php if ($erro !== null): ?>
                <p class="pub-erro" role="alert"><?= e($erro) ?></p>
            <?php endif; ?>
            <button type="submit" class="btn btn-principal btn-block"><?= e(t('Criar conta')) ?></button>
        </form>

        <div class="pub-links">
            <a href="/privacidade"><?= e(t('Privacidade e aviso')) ?></a>
        </div>
    </div>
<?php require __DIR__ . '/_casca_fim.php'; ?>
