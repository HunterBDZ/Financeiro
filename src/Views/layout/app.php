<?php
/**
 * Layout (P1). Variáveis: $content, $pageTitle, $active (inicio|extrato|meta|mes|futuro|ajustes),
 * $semDialogoLancar (true = não renderiza o <dialog id="lancar">).
 * Computador: menu lateral (marca, Lançar, abas, Claro/Escuro, quem está e sair).
 * Celular: barra de cima (marca, sol/lua, menu do avatar) e barra flutuante embaixo com o "+".
 * CSS/JS dos pacotes entram só se o arquivo existir (D19): nenhum 404 durante o build.
 */
use Financeiro\Auth;
use Financeiro\View;

$activePage = (string) ($active ?? '');

// chave => [href, rótulo, ícone]; a ordem é a da barra de baixo (o "+" entra depois da 2ª)
$abas = [
    'inicio'  => ['/', 'Início', 'home'],
    'extrato' => ['/lancamentos', 'Extrato', 'list'],
    'mes'     => ['/mes', 'Mês', 'calendar'],
    'futuro'  => ['/futuro', 'Anual', 'trend'],
    'meta'    => ['/meta', 'Metas', 'target'],
];

$publico = __DIR__ . '/../../../public';
$existe  = static fn(string $p): bool => is_file($publico . $p);
$estilos = array_filter(['/assets/css/style.css', '/assets/css/inicio.css', '/assets/css/lancar.css', '/assets/css/mes.css'], $existe);
$scripts = array_filter(['/assets/js/app.js', '/assets/js/lancar.js', '/assets/js/mes.js'], $existe);

$uri     = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$volta   = voltaSegura($uri, '/');
$hrefLancar = '/lancar?volta=' . rawurlencode($volta);
$nome    = Auth::nome();
$inicial = inicialUsuario($nome);

$flash = $_SESSION['flash'] ?? null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
    unset($_SESSION['flash']);   // HEAD não consome o aviso
}
$flashTipo = is_array($flash) && in_array($flash['type'] ?? '', ['success', 'warn', 'error'], true) ? $flash['type'] : 'success';

$comDialogo = empty($semDialogoLancar)
    && class_exists(\Financeiro\Services\LancarForm::class)
    && is_file(__DIR__ . '/../lancamentos/_form.php');

// classe + aria-current da aba ativa
$aba = static function (string $chave) use ($activePage): string {
    return $activePage === $chave ? ' is-active' : '';
};
$atual = static function (string $chave) use ($activePage): string {
    return $activePage === $chave ? ' aria-current="page"' : '';
};
$marca = '<span class="marca"><img src="/assets/img/spitz.png" alt="" width="216" height="181"></span>';
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
<meta name="theme-color" content="#f4f5f7" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0a0b0f" media="(prefers-color-scheme: dark)">
<meta name="format-detection" content="telephone=no">
<title><?= e($pageTitle ?? 'financeiro') ?></title>
<link rel="icon" type="image/png" sizes="32x32" href="/assets/img/icone-32.png">
<link rel="icon" type="image/png" sizes="64x64" href="/assets/img/icone-64.png">
<link rel="apple-touch-icon" href="/assets/img/icone-180.png">
<link rel="preload" href="/assets/fonts/manrope-var-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/fonts/sora-var-latin.woff2" as="font" type="font/woff2" crossorigin>
<script src="<?= e(asset('/assets/js/theme.js')) ?>"></script>
<?php foreach ($estilos as $css): ?>
<link rel="stylesheet" href="<?= e(asset($css)) ?>">
<?php endforeach; ?>
</head>
<body>
<a class="pular" href="#conteudo">Pular para o conteúdo</a>

<aside class="lateral" aria-label="menu">
    <a class="lat-marca" href="/"><?= $marca ?><b translate="no">financeiro</b></a>
    <a class="lat-lancar" href="<?= e($hrefLancar) ?>" data-lancar><?= svgIcon('plus', 18) ?>Lançar</a>
    <nav class="lat-nav" aria-label="principal">
        <?php foreach ($abas + ['ajustes' => ['/ajustes', 'Ajustes', 'settings']] as $chave => [$href, $rotulo, $icone]): ?>
            <a class="lat-i<?= $aba($chave) ?>" href="<?= e($href) ?>"<?= $atual($chave) ?>><?= svgIcon($icone, 20) ?><span><?= e($rotulo) ?></span></a>
        <?php endforeach; ?>
    </nav>
    <div class="lat-fim">
        <div class="tema-seg" role="group" aria-label="tema">
            <button type="button" class="t-claro" data-tema="light"><?= svgIcon('sun', 16) ?>Claro</button>
            <button type="button" class="t-escuro" data-tema="dark"><?= svgIcon('moon', 16) ?>Escuro</button>
        </div>
        <div class="lat-user">
            <span class="avatar"><?= e($inicial) ?></span>
            <b><?= e((string) ($nome ?? '')) ?></b>
            <form method="post" action="/logout">
                <?= csrfField() ?>
                <button type="submit" class="lat-sair" aria-label="sair"><?= svgIcon('logout', 18) ?></button>
            </form>
        </div>
    </div>
</aside>

<header class="topbar">
    <a class="topbar-marca" href="/"><?= $marca ?><b translate="no">financeiro</b></a>
    <button type="button" class="ic" data-theme-toggle aria-label="trocar tema"><?= svgIcon('sun', 19, 'so-escuro') ?><?= svgIcon('moon', 19, 'so-claro') ?></button>
    <details class="menu menu-avatar">
        <summary aria-label="menu"><span class="avatar<?= $activePage === 'ajustes' ? ' is-active' : '' ?>"><?= e($inicial) ?></span></summary>
        <div class="menu-painel">
            <?php if ($nome): ?><div class="menu-quem"><?= e($nome) ?></div><?php endif; ?>
            <a class="menu-item<?= $activePage === 'ajustes' ? ' is-active' : '' ?>" href="/ajustes"><?= svgIcon('settings', 18) ?>Ajustes</a>
            <form method="post" action="/logout">
                <?= csrfField() ?>
                <button type="submit" class="menu-item"><?= svgIcon('logout', 18) ?>Sair</button>
            </form>
        </div>
    </details>
</header>

<?php if (is_array($flash) && ($flash['message'] ?? '') !== ''): ?>
<div class="toast toast-<?= e($flashTipo) ?>" role="<?= $flashTipo === 'error' ? 'alert' : 'status' ?>" data-toast>
    <?php /* cada trecho da mensagem (separado por " · ") vira uma linha: a 1ª em destaque, as outras menores */ ?>
    <?php $segs = explode(' · ', (string) $flash['message']); ?>
    <span class="toast-msg"><span class="toast-linhas"><?php foreach ($segs as $i => $t): ?><span class="toast-seg"><?php if ($i > 0): ?><span class="sr-only"> · </span><?php endif; ?><?= e($t) ?></span><?php endforeach; ?></span></span>
    <?php if (!empty($flash['acao']['url']) && is_string($flash['acao']['url'])): ?>
        <form class="toast-acao" method="post" action="<?= e(voltaSegura($flash['acao']['url'], '/')) ?>">
            <?= csrfField() ?>
            <?php foreach ((array) ($flash['acao']['campos'] ?? []) as $campo => $valor): ?>
                <input type="hidden" name="<?= e((string) $campo) ?>" value="<?= e((string) $valor) ?>">
            <?php endforeach; ?>
            <button type="submit"><?= e((string) ($flash['acao']['label'] ?? 'desfazer')) ?></button>
        </form>
    <?php endif; ?>
    <button type="button" class="toast-x" data-toast-fechar aria-label="fechar"><?= svgIcon('x', 16) ?></button>
</div>
<?php endif; ?>

<main class="page" id="conteudo">
<?= $content ?>
</main>

<nav class="tabbar" aria-label="principal">
    <?php $i = 0; foreach ($abas as $chave => [$href, $rotulo, $icone]): ?>
        <?php if ($i++ === 2): ?>
            <a class="tab-add" href="<?= e($hrefLancar) ?>" data-lancar aria-label="lançar"><?= svgIcon('plus', 24) ?></a>
        <?php endif; ?>
        <a class="tab<?= $aba($chave) ?>" href="<?= e($href) ?>"<?= $atual($chave) ?>><?= svgIcon($icone, 21) ?><span><?= e($rotulo) ?></span></a>
    <?php endforeach; ?>
</nav>

<?php if ($comDialogo): ?>
<dialog id="lancar" class="sheet" aria-label="lançar">
<?php View::partial('lancamentos/_form', \Financeiro\Services\LancarForm::dados() + ['modo' => 'dialog', 'pref' => 'dlg', 'volta' => $volta]); ?>
</dialog>
<?php endif; ?>

<?php foreach ($scripts as $js): ?>
<script src="<?= e(asset($js)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
