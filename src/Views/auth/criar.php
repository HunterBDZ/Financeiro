<?php
/**
 * A primeira conta do site, sem terminal: aparece só enquanto o banco não tem nenhuma.
 * Variáveis: $erro (?string), $nome e $login (o que foi digitado), $pedeCodigo (servidor: o código de instalação).
 */
$erro  = $erro ?? null;
$pedeCodigo = (bool) ($pedeCodigo ?? false);
$nome  = (string) ($nome ?? '');
$login = (string) ($login ?? '');
$css = array_filter(['/assets/css/style.css', '/assets/css/lancar.css', '/assets/css/temas.css'], static fn(string $p): bool => is_file(__DIR__ . '/../../../public' . $p));
?>
<!doctype html>
<html lang="<?= e(\Financeiro\Idioma::html()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
<meta name="theme-color" content="#F6F8FB" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0A1020" media="(prefers-color-scheme: dark)">
<meta name="format-detection" content="telephone=no">
<title><?= e(t('Criar conta · MyMonetto')) ?></title>
<?= \Financeiro\SiteIcone::tags() ?><link rel="preload" href="/assets/fonts/manrope-var-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/fonts/sora-var-latin.woff2" as="font" type="font/woff2" crossorigin>
<script src="<?= e(asset('/assets/js/theme.js')) ?>"></script>
<?php foreach ($css as $c): ?>
<link rel="stylesheet" href="<?= e(asset($c)) ?>">
<?php endforeach; ?>
</head>
<body class="lg-body">
<main class="lg">
    <div class="lg-topo">
        <span class="marca"><img src="<?= e(\Financeiro\SiteIcone::url()) ?>" alt="" width="96" height="96"></span>
        <b translate="no">MyMonetto</b>
        <button type="button" class="ic" data-theme-toggle aria-label="<?= e(t('trocar tema')) ?>"><?= svgIcon('sun', 19, 'so-escuro') ?><?= svgIcon('moon', 19, 'so-claro') ?></button>
    </div>

    <div class="lg-esq">
        <h1 class="lg-titulo"><?= e(t('Falta só a sua conta.')) ?></h1>
        <p class="lg-sub"><?= e(t('O MyMonetto está instalado. Crie o seu acesso para começar.')) ?></p>
    </div>

    <div class="lg-dir">
        <form class="lg-card lg-card-sem-espia" method="post" action="/criar-conta">
            <h2 class="lg-card-t"><?= e(t('Criar conta')) ?></h2>
            <?= csrfField() ?>
            <label class="lg-campo">
                <?= svgIcon('user', 19) ?>
                <span class="sr-only"><?= e(t('Seu nome')) ?></span>
                <input type="text" name="nome" value="<?= e($nome) ?>" required maxlength="100" placeholder="<?= e(t('Seu nome')) ?>" autocomplete="name" enterkeyhint="next" data-autofocus>
            </label>
            <label class="lg-campo">
                <?= svgIcon('user', 19) ?>
                <span class="sr-only"><?= e(t('Nome de usuário')) ?></span>
                <input type="text" name="login" value="<?= e($login) ?>" required maxlength="100" placeholder="<?= e(t('Nome de usuário')) ?>" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" enterkeyhint="next">
            </label>
            <label class="lg-campo">
                <?= svgIcon('lock', 19) ?>
                <span class="sr-only"><?= e(t('Senha (mínimo de 8 caracteres)')) ?></span>
                <input type="password" name="senha" required minlength="8" placeholder="<?= e(t('Senha (mínimo de 8 caracteres)')) ?>" autocomplete="new-password" enterkeyhint="<?= $pedeCodigo ? 'next' : 'go' ?>">
            </label>
            <?php if ($pedeCodigo): ?>
            <label class="lg-campo">
                <?= svgIcon('lock', 19) ?>
                <span class="sr-only"><?= e(t('Código de instalação')) ?></span>
                <input type="text" name="codigo" required maxlength="40" placeholder="<?= e(t('Código de instalação')) ?>" autocomplete="one-time-code" autocapitalize="characters" autocorrect="off" spellcheck="false" aria-describedby="codigo-ajuda" enterkeyhint="go">
            </label>
            <p class="lg-nota" id="codigo-ajuda"><?= e(t('Para ninguém criar a conta antes de você, o MyMonetto mostrou um código no fim da instalação. No Docker, ele está em docker compose logs.')) ?></p>
            <?php endif; ?>
            <?php if ($erro !== null): ?>
                <div class="aviso aviso-error" role="alert"><?= e($erro) ?></div>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary btn-block"><?= e(t('Criar conta')) ?></button>
        </form>
        <?php require __DIR__ . '/../partials/idioma_escolha.php'; ?>
    </div>
</main>
<script src="<?= e(asset('/assets/js/app.js')) ?>"></script>
</body>
</html>
