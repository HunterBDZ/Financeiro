<?php
/**
 * Login (P4, SPEC §2.9). Página sem o layout. Variáveis: $erro (?string), $expirou (bool),
 * $login (string, o que foi digitado), $lembrar (bool; marcado por padrão — decisão pendente
 * do casal, PESQUISA §5: trocar o padrão é mudar o `checked`).
 */
$erro    = $erro ?? null;
$expirou = !empty($expirou) && empty($erro);
$login   = (string) ($login ?? '');
$lembrar = $lembrar ?? false;
$css = array_filter(['/assets/css/style.css', '/assets/css/lancar.css'], static fn(string $p): bool => is_file(__DIR__ . '/../../../public' . $p));
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
<meta name="format-detection" content="telephone=no">
<title>Entrar · financeiro</title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="preload" href="/assets/fonts/archivo-var-latin.woff2" as="font" type="font/woff2" crossorigin>
<script src="<?= e(asset('/assets/js/theme.js')) ?>"></script>
<?php foreach ($css as $c): ?>
<link rel="stylesheet" href="<?= e(asset($c)) ?>">
<?php endforeach; ?>
</head>
<body class="lg-body">
<button type="button" class="lg-tema" data-theme-toggle aria-label="alternar tema">
    <span class="so-escuro"><?= svgIcon('sun', 18) ?></span>
    <span class="so-claro"><?= svgIcon('moon', 18) ?></span>
</button>

<main class="lg">
    <h1 class="lg-marca" translate="no">financeiro</h1>
    <p class="lg-sub">O dinheiro da casa, mês a mês.</p>

    <form class="lg-card" method="post" action="/login">
        <?= csrfField() ?>
        <label class="campo">
            <span>Login</span>
            <input type="text" name="login" value="<?= e($login) ?>" required maxlength="100"
                   autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false"
                   enterkeyhint="next"<?= $login === '' ? ' data-autofocus' : '' ?>>
        </label>
        <label class="campo">
            <span>Senha</span>
            <input type="password" name="senha" required autocomplete="current-password" enterkeyhint="go"<?= $login !== '' ? ' data-autofocus' : '' ?>>
        </label>
        <label class="lg-lembrar">
            <input type="checkbox" name="lembrar" value="1"<?= $lembrar ? ' checked' : '' ?>>
            <span>Lembrar este aparelho</span>
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
</main>
<script src="<?= e(asset('/assets/js/app.js')) ?>"></script>
</body>
</html>
