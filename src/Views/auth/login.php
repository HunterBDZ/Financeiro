<?php
/**
 * Login. Página sem o layout. Variáveis: $erro (?string), $expirou (bool),
 * $login (string, o que foi digitado), $lembrar (bool; marcado por padrão: trocar o padrão
 * é mudar o `checked`).
 * Visual: linhas abstratas na página inteira, a marca com o ícone do site, o título à esquerda
 * (computador) e o cartão de entrar, com o ícone escolhido espiando por cima da borda.
 */
$erro    = $erro ?? null;
$expirou = !empty($expirou) && empty($erro);
$login   = (string) ($login ?? '');
$lembrar = $lembrar ?? false;
$css = array_filter(['/assets/css/style.css', '/assets/css/lancar.css', '/assets/css/temas.css'], static fn(string $p): bool => is_file(__DIR__ . '/../../../public' . $p));
?>
<!doctype html>
<html lang="<?= e(\Financeiro\Idioma::html()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
<meta name="theme-color" content="#f4f5f7" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0a0b0f" media="(prefers-color-scheme: dark)">
<meta name="format-detection" content="telephone=no">
<title><?= e(t('Entrar · financeiro')) ?></title>
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
        <b translate="no">financeiro</b>
        <button type="button" class="ic" data-theme-toggle aria-label="<?= e(t('trocar tema')) ?>"><?= svgIcon('sun', 19, 'so-escuro') ?><?= svgIcon('moon', 19, 'so-claro') ?></button>
    </div>

    <div class="lg-esq">
        <h1 class="lg-titulo"><?= e(t('O dinheiro da casa, mês a mês.')) ?></h1>
        <p class="lg-sub"><?= e(t('Saldo, contas, metas e os próximos meses, num lugar só.')) ?></p>
    </div>

    <div class="lg-dir">
        <form class="lg-card<?= \Financeiro\SiteIcone::personalizado() === null ? ' lg-card-sem-espia' : '' ?>" method="post" action="/login">
            <?php if (\Financeiro\SiteIcone::personalizado() !== null): ?><img class="lg-espia" src="<?= e(\Financeiro\SiteIcone::url()) ?>" alt="" width="216" height="181"><?php endif; ?>
            <h2 class="lg-card-t"><?= e(t('Entrar')) ?></h2>
            <?= csrfField() ?>
            <label class="lg-campo">
                <?= svgIcon('user', 19) ?>
                <span class="sr-only"><?= e(t('Login')) ?></span>
                <input type="text" name="login" value="<?= e($login) ?>" required maxlength="100" placeholder="<?= e(t('Login')) ?>"
                       autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false"
                       enterkeyhint="next"<?= $login === '' ? ' data-autofocus' : '' ?>>
            </label>
            <label class="lg-campo">
                <?= svgIcon('lock', 19) ?>
                <span class="sr-only"><?= e(t('Senha')) ?></span>
                <input type="password" name="senha" required placeholder="<?= e(t('Senha')) ?>" autocomplete="current-password" enterkeyhint="go"<?= $login !== '' ? ' data-autofocus' : '' ?>>
            </label>
            <label class="lg-lembrar">
                <span><?= e(t('Lembrar este aparelho')) ?></span>
                <input type="checkbox" name="lembrar" value="1"<?= $lembrar ? ' checked' : '' ?>>
            </label>

            <?php if ($erro !== null): ?>
                <div class="aviso aviso-error" role="alert"><?= e($erro) ?></div>
            <?php elseif (isset($_GET['restaurado'])): ?>
                <div class="aviso" role="status"><?= e(t('Backup restaurado. Entre de novo. O que havia antes ficou guardado em uma cópia.')) ?></div>
            <?php elseif ($expirou): ?>
                <div class="aviso aviso-warn" role="status"><?= e(t('A sessão expirou. Entre de novo.')) ?></div>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary btn-block"><?= e(t('Entrar')) ?></button>
        </form>
        <?php require __DIR__ . '/../partials/idioma_escolha.php'; ?>

        <details class="lg-cert">
            <summary><?= e(t('Esqueci a senha')) ?></summary>
            <p><?= e(t('Outra pessoa com conta pode definir uma senha nova em Ajustes, em Pessoas. Se não houver outra pessoa, peça a quem instalou o app.')) ?></p>
        </details>
        <a class="lg-ajuda" href="/ajuda/conexao"><?= e(t('O navegador avisou “conexão não segura”?')) ?></a>
    </div>
</main>
<script src="<?= e(asset('/assets/js/app.js')) ?>"></script>
</body>
</html>
