<?php
/** A resposta sobre o aviso de conexão, sem login: quem vê o aviso ainda não entrou. Página sem o layout. */
$css = ['/assets/css/style.css', '/assets/css/lancar.css', '/assets/css/temas.css'];
?>
<!doctype html>
<html lang="<?= e(\Financeiro\Idioma::html()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#f4f5f7" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0a0b0f" media="(prefers-color-scheme: dark)">
<title><?= e(t('ajuda · financeiro')) ?></title>
<?= \Financeiro\SiteIcone::tags() ?>
<script src="<?= e(asset('/assets/js/theme.js')) ?>"></script>
<?php foreach ($css as $c): ?>
<link rel="stylesheet" href="<?= e(asset($c)) ?>">
<?php endforeach; ?>
</head>
<body class="lg-body">
<main class="lg lg-texto">
    <div class="lg-topo">
        <span class="marca"><img src="<?= e(\Financeiro\SiteIcone::url()) ?>" alt="" width="96" height="96"></span>
        <b translate="no">financeiro</b>
    </div>
    <div class="lg-card lg-card-sem-espia">
        <h1 class="titulo"><?= e(t('Por que o navegador diz que a conexão não é segura?')) ?></h1>
        <p><?= e(t('Esse aviso é esperado: o app roda só na sua rede local, com certificado próprio. Escolha Avançado (ou Detalhes) e depois Continuar ou Prosseguir. Os dados não saem da sua rede.')) ?></p>
        <a class="btn btn-primary btn-block" href="/login"><?= e(t('Voltar para entrar')) ?></a>
    </div>
</main>
</body>
</html>
