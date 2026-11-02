<?php
/**
 * Segunda parte do login, com a verificação em duas etapas ligada. Página sem o layout, com o visual do login.
 * Variáveis: $erro (?string), $recuperacao (bool: o campo pede um código de recuperação, com letras).
 * O POST aceita os dois tipos de código; o modo só muda o texto e o teclado do celular.
 */
$erro        = $erro ?? null;
$recuperacao = !empty($recuperacao);
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
<title><?= e(t('Código de verificação · financeiro')) ?></title>
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
        <h1 class="lg-titulo"><?= e(t('Falta só o código.')) ?></h1>
        <p class="lg-sub"><?= e(t('A sua conta usa a verificação em duas etapas: além da senha, ela pede um código do app autenticador.')) ?></p>
    </div>

    <div class="lg-dir">
        <form class="lg-card lg-card-sem-espia" method="post" action="/login/codigo">
            <h2 class="lg-card-t"><?= e(t('Verificação em duas etapas')) ?></h2>
            <?= csrfField() ?>
            <?php if ($recuperacao): ?><input type="hidden" name="recuperacao" value="1"><?php endif; ?>
            <p class="lg-nota"><?= e($recuperacao
                ? t('Digite um dos códigos de recuperação que você guardou ao ligar a verificação. Cada um vale uma vez.')
                : t('Abra o app autenticador no celular e digite o código de 6 dígitos que aparece para o Financeiro.')) ?></p>
            <label class="lg-campo">
                <?= svgIcon('lock', 19) ?>
                <span class="sr-only"><?= e($recuperacao ? t('Código de recuperação') : t('Código de 6 dígitos')) ?></span>
                <?php if ($recuperacao): ?>
                    <input type="text" name="codigo" required maxlength="40" placeholder="<?= e(t('Código de recuperação')) ?>"
                           autocomplete="off" autocapitalize="characters" autocorrect="off" spellcheck="false" enterkeyhint="go" data-autofocus>
                <?php else: ?>
                    <input type="text" name="codigo" required maxlength="12" placeholder="<?= e(t('Código de 6 dígitos')) ?>"
                           inputmode="numeric" pattern="[0-9 ]*" autocomplete="one-time-code" enterkeyhint="go" data-autofocus>
                <?php endif; ?>
            </label>

            <?php if ($erro !== null): ?>
                <div class="aviso aviso-error" role="alert"><?= e($erro) ?></div>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary btn-block"><?= e(t('Entrar')) ?></button>
        </form>

        <?php if ($recuperacao): ?>
            <a class="lg-ajuda" href="/login/codigo"><?= e(t('Usar o código do app autenticador')) ?></a>
        <?php else: ?>
            <a class="lg-ajuda" href="/login/codigo?recuperacao=1"><?= e(t('Sem o celular? Use um código de recuperação')) ?></a>
        <?php endif; ?>
        <details class="lg-cert">
            <summary><?= e(t('Perdi o celular e os códigos')) ?></summary>
            <p><?= e(t('Peça a quem instalou o app: o comando que define uma senha nova também desliga a verificação em duas etapas. Depois, ligue de novo em Ajustes.')) ?></p>
        </details>
        <a class="lg-ajuda" href="/login"><?= e(t('Voltar e entrar com outra conta')) ?></a>
    </div>
</main>
<script src="<?= e(asset('/assets/js/app.js')) ?>"></script>
</body>
</html>
