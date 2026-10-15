<?php
/**
 * Layout (P1). Variáveis: $content, $pageTitle, $active (inicio|extrato|meta|mes|futuro|ajustes),
 * $semDialogoLancar (true = não renderiza o <dialog id="lancar">).
 * CSS/JS dos pacotes entram só se o arquivo existir (D19): nenhum 404 durante o build.
 */
use Financeiro\Auth;
use Financeiro\View;

$activePage = (string) ($active ?? '');

$abas = [
    'inicio'  => ['/', 'início', 'home'],
    'extrato' => ['/lancamentos', 'extrato', 'list'],
    'meta'    => ['/meta', 'meta', 'target'],
    'mes'     => ['/mes', 'mês', 'calendar'],
    'futuro'  => ['/futuro', 'anual', 'trend'],
];

$publico = __DIR__ . '/../../../public';
$existe  = static fn(string $p): bool => is_file($publico . $p);
$estilos = array_filter(['/assets/css/style.css', '/assets/css/inicio.css', '/assets/css/lancar.css', '/assets/css/mes.css'], $existe);
$scripts = array_filter(['/assets/js/app.js', '/assets/js/lancar.js', '/assets/js/grafico.js'], $existe);

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
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
<meta name="theme-color" content="#0a0f0d">
<meta name="format-detection" content="telephone=no">
<title><?= e($pageTitle ?? 'financeiro') ?></title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<script src="<?= e(asset('/assets/js/theme.js')) ?>"></script>
<?php foreach ($estilos as $css): ?>
<link rel="stylesheet" href="<?= e(asset($css)) ?>">
<?php endforeach; ?>
</head>
<body>
<header class="topbar">
    <div class="topbar-in">
        <a class="marca" href="/" aria-label="financeiro — início">financeiro<span>_</span></a>

        <nav class="topnav" aria-label="principal">
            <?php foreach ($abas as $chave => [$href, $rotulo]): ?>
                <a class="topnav-item<?= $aba($chave) ?>" href="<?= e($href) ?>"<?= $atual($chave) ?>><?= e($rotulo) ?></a>
            <?php endforeach; ?>
        </nav>

        <a class="btn btn-primary btn-sm topbar-lancar" href="<?= e($hrefLancar) ?>" data-lancar><?= svgIcon('plus', 16) ?>lançar</a>

        <details class="menu menu-avatar">
            <summary aria-label="menu"><span class="avatar<?= $activePage === 'ajustes' ? ' is-active' : '' ?>"><?= e($inicial) ?></span></summary>
            <div class="menu-painel">
                <?php if ($nome): ?><div class="menu-quem"><?= e($nome) ?></div><?php endif; ?>
                <a class="menu-item<?= $activePage === 'ajustes' ? ' is-active' : '' ?>" href="/ajustes"><?= svgIcon('settings', 18) ?>ajustes</a>
                <button type="button" class="menu-item" data-theme-toggle>
                    <span class="so-escuro"><?= svgIcon('sun', 18) ?>tema claro</span>
                    <span class="so-claro"><?= svgIcon('moon', 18) ?>tema escuro</span>
                </button>
                <form method="post" action="/logout">
                    <?= csrfField() ?>
                    <button type="submit" class="menu-item"><?= svgIcon('logout', 18) ?>sair</button>
                </form>
            </div>
        </details>
    </div>
</header>

<main class="page" id="conteudo">
<?= $content ?>
</main>

<nav class="tabbar" aria-label="principal">
    <?php $i = 0; foreach ($abas as $chave => [$href, $rotulo, $icone]): ?>
        <?php if ($i++ === 3): ?>
            <a class="tab-add" href="<?= e($hrefLancar) ?>" data-lancar aria-label="lançar"><?= svgIcon('plus', 24) ?></a>
        <?php endif; ?>
        <a class="tab<?= $aba($chave) ?>" href="<?= e($href) ?>"<?= $atual($chave) ?>><?= svgIcon($icone, 20) ?><span><?= e($rotulo) ?></span></a>
    <?php endforeach; ?>
</nav>

<?php if (is_array($flash) && ($flash['message'] ?? '') !== ''): ?>
<div class="toast toast-<?= e($flashTipo) ?>" role="<?= $flashTipo === 'error' ? 'alert' : 'status' ?>" data-toast>
    <?php /* cada trecho entre " · " fica inteiro na mesma linha ("set/26 ● fechado", "Delivery −12,34"); o "·" é do CSS (.toast-seg::before) */ ?>
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
