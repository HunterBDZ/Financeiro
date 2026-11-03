<?php
/**
 * A casca das páginas de fora do app (entrar, criar conta, o código, o começo, privacidade, conexão): fundo liso,
 * a marca à esquerda do topo (menos nas telas de entrar, onde ela vem com o nome na coluna da esquerda) e os
 * idiomas numa seleção discreta à direita. Uso:
 *   $casca = ['titulo' => t('Entrar · MyMonetto'), 'classe' => 'pub-entrar', 'marca' => false, 'css' => [...], 'js' => [...]];
 *   require __DIR__ . '/../auth/_casca.php';     // abre o <html>, o <head>, o topo e o <main>
 *   ... o conteúdo ...
 *   require __DIR__ . '/../auth/_casca_fim.php'; // fecha o <main>, os scripts e o <html>
 * Chaves: titulo (o <title>), classe (classe do <main>), marca (bool, a marca no topo; padrão true),
 * idiomas (bool, padrão true), voltaIdioma (para onde a troca de idioma volta), css e js (arquivos a mais),
 * vitrine (bool: a tela dividida das telas de entrar, com o MyMonetto mostrado à esquerda; auth/_vitrine.php).
 */
use Financeiro\Idioma;
use Financeiro\SiteIcone;

$casca = (array) ($casca ?? []);
$publico = __DIR__ . '/../../../public';
$cssCasca = array_filter(array_merge(['/assets/css/style.css', '/assets/css/entrar.css'], (array) ($casca['css'] ?? []), ['/assets/css/temas.css']), static fn(string $p): bool => is_file($publico . $p));
$marcaSinal = SiteIcone::personalizado() !== null
    ? '<span class="marca-sinal is-imagem"><img src="' . e(SiteIcone::url()) . '" alt="" width="28" height="28"></span>'
    : '<span class="marca-sinal">' . svgIcon('marca', 18) . '</span>';
?>
<!doctype html>
<html lang="<?= e(Idioma::html()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
<meta name="theme-color" content="#F6F8FB" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0A1020" media="(prefers-color-scheme: dark)">
<meta name="format-detection" content="telephone=no">
<title><?= e((string) ($casca['titulo'] ?? 'MyMonetto')) ?></title>
<?= SiteIcone::tags() ?><link rel="preload" href="/assets/fonts/manrope-var-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/fonts/sora-var-latin.woff2" as="font" type="font/woff2" crossorigin>
<script src="<?= e(asset('/assets/js/theme.js')) ?>"></script>
<?php foreach ($cssCasca as $c): ?>
<link rel="stylesheet" href="<?= e(asset($c)) ?>">
<?php endforeach; ?>
</head>
<?php $comVitrine = !empty($casca['vitrine']); ?>
<body class="pub<?= $comVitrine ? ' pub-dividida' : '' ?>">
<?php if ($comVitrine): ?>
<aside class="pub-vitrine"><?php require __DIR__ . '/_vitrine.php'; ?></aside>
<div class="pub-coluna">
<?php endif; ?>
<header class="pub-topo">
    <?php if (($casca['marca'] ?? true) !== false): ?>
        <a class="pub-marca" href="/"><?= $marcaSinal ?><b translate="no">MyMonetto</b></a>
    <?php else: ?>
        <span></span>
    <?php endif; ?>
    <?php if (($casca['idiomas'] ?? true) !== false) {
        $idiomaDiscreto = true;
        $voltaIdioma = (string) ($casca['voltaIdioma'] ?? '');
        require __DIR__ . '/../partials/idioma_escolha.php';
    } ?>
</header>
<main class="pub-meio <?= e((string) ($casca['classe'] ?? '')) ?>" id="conteudo">
