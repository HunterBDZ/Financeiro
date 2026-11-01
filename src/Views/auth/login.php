<?php
/**
 * Login (P4, SPEC §2.9). Página sem o layout. Variáveis: $erro (?string), $expirou (bool),
 * $login (string, o que foi digitado), $lembrar (bool; marcado por padrão — decisão pendente
 * do casal, PESQUISA §5: trocar o padrão é mudar o `checked`).
 * Visual: linhas abstratas na página inteira, a marca com o spitz da casa, o título à esquerda
 * (computador) e o cartão de entrar com o spitz espiando por cima da borda.
 */
$erro    = $erro ?? null;
$expirou = !empty($expirou) && empty($erro);
$login   = (string) ($login ?? '');
$lembrar = $lembrar ?? false;
$css = array_filter(['/assets/css/style.css', '/assets/css/lancar.css', '/assets/css/temas.css'], static fn(string $p): bool => is_file(__DIR__ . '/../../../public' . $p));
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
<meta name="theme-color" content="#f4f5f7" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0a0b0f" media="(prefers-color-scheme: dark)">
<meta name="format-detection" content="telephone=no">
<title>Entrar · financeiro</title>
<link rel="icon" type="image/png" sizes="32x32" href="/assets/img/icone-32.png">
<link rel="apple-touch-icon" href="/assets/img/icone-180.png">
<link rel="preload" href="/assets/fonts/manrope-var-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/fonts/sora-var-latin.woff2" as="font" type="font/woff2" crossorigin>
<script src="<?= e(asset('/assets/js/theme.js')) ?>"></script>
<?php foreach ($css as $c): ?>
<link rel="stylesheet" href="<?= e(asset($c)) ?>">
<?php endforeach; ?>
</head>
<body class="lg-body">
<main class="lg">
    <div class="lg-topo">
        <span class="marca"><img src="/assets/img/spitz.png" alt="" width="216" height="181"></span>
        <b translate="no">financeiro</b>
        <button type="button" class="ic" data-theme-toggle aria-label="trocar tema"><?= svgIcon('sun', 19, 'so-escuro') ?><?= svgIcon('moon', 19, 'so-claro') ?></button>
    </div>

    <div class="lg-esq">
        <h1 class="lg-titulo">O dinheiro da casa, mês a mês.</h1>
        <p class="lg-sub">Saldo, contas, metas e os próximos meses, num lugar só.</p>
    </div>

    <div class="lg-dir">
        <form class="lg-card" method="post" action="/login">
            <img class="lg-spitz" src="/assets/img/spitz.png" alt="" width="216" height="181">
            <h2 class="lg-card-t">Entrar</h2>
            <?= csrfField() ?>
            <label class="lg-campo">
                <?= svgIcon('user', 19) ?>
                <span class="sr-only">Login</span>
                <input type="text" name="login" value="<?= e($login) ?>" required maxlength="100" placeholder="Login"
                       autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false"
                       enterkeyhint="next"<?= $login === '' ? ' data-autofocus' : '' ?>>
            </label>
            <label class="lg-campo">
                <?= svgIcon('lock', 19) ?>
                <span class="sr-only">Senha</span>
                <input type="password" name="senha" required placeholder="Senha" autocomplete="current-password" enterkeyhint="go"<?= $login !== '' ? ' data-autofocus' : '' ?>>
            </label>
            <label class="lg-lembrar">
                <span>Lembrar este aparelho</span>
                <input type="checkbox" name="lembrar" value="1"<?= $lembrar ? ' checked' : '' ?>>
            </label>

            <?php if ($erro !== null): ?>
                <div class="aviso aviso-error" role="alert"><?= e($erro) ?></div>
            <?php elseif ($expirou): ?>
                <div class="aviso aviso-warn" role="status">A sessão expirou. Entre de novo.</div>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary btn-block">Entrar</button>
        </form>

        <details class="lg-cert">
            <summary>O navegador avisou “conexão não segura”?</summary>
            <p>Esse aviso é esperado: o app roda só na sua rede local, com certificado próprio. Toque em
            <strong>Avançado</strong> (ou “Detalhes”) e depois em <strong>Continuar</strong> ou <strong>Prosseguir</strong>.
            Os dados não saem da sua rede.</p>
        </details>
    </div>
</main>
<script src="<?= e(asset('/assets/js/app.js')) ?>"></script>
</body>
</html>
