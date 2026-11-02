<?php
/**
 * Layout. Variáveis: $content, $pageTitle, $active (inicio|extrato|meta|mes|cartao|relatorio|emprestimo|wishlist|temas|ajustes),
 * $semDialogoLancar (true = não renderiza o <dialog id="lancar">).
 * Computador: menu lateral (marca, Lançar, abas, Claro/Escuro, quem está e sair).
 * Celular: barra de cima (marca, sol/lua, botão Menu) e barra flutuante embaixo com o "+" no
 * meio de 4 abas (Início, Extrato, +, Mês, Cartão). O resto das abas fica no botão Menu, junto com a conta.
 * CSS/JS dos pacotes entram só se o arquivo existir (D19): nenhum 404 durante o build.
 */
use Financeiro\Auth;
use Financeiro\Models\AjusteModel;
use Financeiro\Idioma;
use Financeiro\SiteIcone;
use Financeiro\Temas;
use Financeiro\View;

$activePage = (string) ($active ?? '');

// chave => [href, rótulo, ícone]; a ordem é a da barra de baixo (o "+" entra depois da 2ª)
$abas = [
    'inicio'  => ['/', t('Início'), 'home'],
    'extrato' => ['/lancamentos', t('Extrato'), 'list'],
    'mes'     => ['/mes', t('Mês'), 'calendar'],
    'cartao'  => ['/cartao', t('Cartão'), 'cartao'],
    'relatorio' => ['/relatorio', t('Relatório'), 'relatorio'],
    'simular' => ['/futuro', t('Simular'), 'trend'],
    'emprestimo' => ['/emprestimo', t('Empréstimo'), 'emprestimo'],
    'investimentos' => ['/investimentos', t('Investimentos'), 'invest'],
    'wishlist' => ['/wishlist', t('Wishlist'), 'gift'],
    'temas'   => ['/temas', t('Temas'), 'paleta'],
    'meta'    => ['/meta', t('Metas'), 'target'],
];

// recursos opcionais: a aba do que a pessoa não usa fica escondida
$usaCartao = AjusteModel::usa('usa_cartao');
$usaEmprestimo = AjusteModel::usa('usa_emprestimo');
if (!$usaCartao) {
    unset($abas['cartao']);
}
if (!$usaEmprestimo) {
    unset($abas['emprestimo']);
}

$publico = __DIR__ . '/../../../public';
$existe  = static fn(string $p): bool => is_file($publico . $p);
$estilos = array_filter(['/assets/css/style.css', '/assets/css/inicio.css', '/assets/css/lancar.css', '/assets/css/mes.css', '/assets/css/investimentos.css', '/assets/css/relatorio.css', '/assets/css/cartao.css', '/assets/css/emprestimo.css', '/assets/css/wishlist.css', '/assets/css/importar.css', '/assets/css/temas-tela.css', '/assets/css/temas.css'], $existe);
$scripts = array_filter(['/assets/js/app.js', '/assets/js/lancar.js', '/assets/js/mes.js', '/assets/js/cartao.js', '/assets/js/emprestimo.js', '/assets/js/wishlist.js', '/assets/js/importar.js', '/assets/js/temas.js', '/assets/js/relatorio.js', '/assets/js/fuso.js'], $existe);

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
// barra do celular: Início, Extrato, +, Mês, Cartão. Com cartão, o Relatório abre pelo menu e quem fica acesa nele é a do Mês; sem cartão, ele toma o lugar
$barra = array_diff_key($abas, ($usaCartao ? ['relatorio' => true] : []) + ['simular' => true, 'meta' => true, 'emprestimo' => true, 'investimentos' => true, 'wishlist' => true, 'temas' => true]);
$acesaNaBarra = $usaCartao && $activePage === 'relatorio' ? 'mes' : $activePage;
// o tema da conta vem no HTML: nada de piscar, e vale em qualquer aparelho. Null = automático (theme.js)
$temaConta = Temas::doUsuario();
$dadosTema = $temaConta !== null ? Temas::todos()[$temaConta] : null;
$csrfTema = e(csrfToken());
// o atalho claro/escuro vai para o outro lado do tema escolhido; tema sem o outro lado mostra o nome dele e leva à tela Temas
$ladosTema = Temas::lados($temaConta);
$dadosAtalho = '';
if ($ladosTema !== null) {
    $todosTemas = Temas::todos();
    $dadosAtalho = ' data-tema-csrf="' . $csrfTema . '" data-lado-claro="' . e($ladosTema[0]) . '" data-lado-escuro="' . e($ladosTema[1])
        . '" data-bg-claro="' . e((string) $todosTemas[$ladosTema[0]]['bg']) . '" data-bg-escuro="' . e((string) $todosTemas[$ladosTema[1]]['bg']) . '"';
}
$marca = '<span class="marca"><img src="' . e(SiteIcone::url()) . '" alt="" width="96" height="96"></span>';
?>
<!doctype html>
<html lang="<?= e(Idioma::html()) ?>"<?= $dadosTema !== null ? ' data-theme="' . e((string) $dadosTema['modo']) . '" data-tema="' . e((string) $temaConta) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
<?php if ($dadosTema !== null): ?>
<meta name="theme-color" content="<?= e((string) $dadosTema['bg']) ?>">
<?php else: ?>
<meta name="theme-color" content="#f4f5f7" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0a0b0f" media="(prefers-color-scheme: dark)">
<?php endif; ?>
<meta name="format-detection" content="telephone=no">
<title><?= e($pageTitle ?? 'financeiro') ?></title>
<?= SiteIcone::tags() ?><link rel="preload" href="/assets/fonts/manrope-var-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/fonts/sora-var-latin.woff2" as="font" type="font/woff2" crossorigin>
<script src="<?= e(asset('/assets/js/theme.js')) ?>"></script>
<?php if (Idioma::atual() !== Idioma::PADRAO): ?>
<script src="<?= e(asset('/assets/js/i18n-' . Idioma::atual() . '.js')) ?>"></script>
<?php endif; ?>
<script src="<?= e(asset('/assets/js/i18n.js')) ?>" data-dec="<?= e(sepDecimal()) ?>" data-mil="<?= e(Idioma::separadores()[1]) ?>" data-moeda="<?= e(\Financeiro\Site::moeda()) ?>" data-simbolo="<?= e(moedaSimbolo()) ?>"<?= Idioma::atual() === 'en' ? ' data-meses="' . e(implode(',', array_map([Idioma::class, 'mesCurto'], range(1, 12)))) . '"' : '' ?>></script>
<?php foreach ($estilos as $css): ?>
<link rel="stylesheet" href="<?= e(asset($css)) ?>">
<?php endforeach; ?>
</head>
<body style="--ic-sprite:url('<?= e(asset('/assets/img/icones.webp')) ?>')">
<a class="pular" href="#conteudo"><?= e(t('Pular para o conteúdo')) ?></a>

<aside class="lateral" aria-label="<?= e(t('menu')) ?>">
    <a class="lat-marca" href="/"><?= $marca ?><b translate="no">financeiro</b></a>
    <a class="lat-lancar" href="<?= e($hrefLancar) ?>" data-lancar><?= svgIcon('plus', 18) ?><?= e(t('Lançar')) ?></a>
    <nav class="lat-nav" aria-label="<?= e(t('principal')) ?>">
        <?php foreach ($abas + ['ajuda' => ['/ajuda', t('Ajuda'), 'ajuda'], 'ajustes' => ['/ajustes', t('Ajustes'), 'settings']] as $chave => [$href, $rotulo, $icone]): ?>
            <a class="lat-i<?= $aba($chave) ?>" href="<?= e($href) ?>"<?= $atual($chave) ?>><?= svgIcon($icone, 20) ?><span><?= e($rotulo) ?></span></a>
        <?php endforeach; ?>
    </nav>
    <div class="lat-fim">
        <?php if ($ladosTema !== null): ?>
            <div class="tema-seg" role="group" aria-label="<?= e(t('tema')) ?>"<?= $dadosAtalho ?>>
                <button type="button" class="t-claro" data-tema="light"><?= svgIcon('sun', 16) ?><?= e(t('Claro')) ?></button>
                <button type="button" class="t-escuro" data-tema="dark"><?= svgIcon('moon', 16) ?><?= e(t('Escuro')) ?></button>
            </div>
        <?php else: ?>
            <?php // i18n: t('Terminal') t('Âmbar') t('Portátil') t('Janela 95') t('Papel') t('Caramelo') t('Calçadão') t('Azulejo') t('Tropical') t('Cordel') t('Festa Junina') t('Daltônico')
            $nomeTema = (string) $dadosTema['nome']; ?>
            <a class="tema-atual" href="/temas"><?= svgIcon('paleta', 16) ?><span><?= e(t($nomeTema)) ?></span><?= svgIcon('chevron', 14) ?></a>
        <?php endif; ?>
        <div class="lat-user">
            <span class="avatar"><?= e($inicial) ?></span>
            <b><?= e((string) ($nome ?? '')) ?></b>
            <form method="post" action="/logout">
                <?= csrfField() ?>
                <button type="submit" class="lat-sair" aria-label="<?= e(t('sair')) ?>"><?= svgIcon('logout', 18) ?></button>
            </form>
        </div>
    </div>
</aside>

<header class="topbar">
    <a class="topbar-marca" href="/"><?= $marca ?><b translate="no">financeiro</b></a>
    <?php if ($ladosTema !== null): ?><button type="button" class="ic" data-theme-toggle<?= $dadosAtalho ?> aria-label="<?= e(t('trocar tema')) ?>"><?= svgIcon('sun', 19, 'so-escuro') ?><?= svgIcon('moon', 19, 'so-claro') ?></button><?php else: ?><a class="ic" href="/temas" aria-label="<?= e(t('Temas')) ?>"><?= svgIcon('paleta', 19) ?></a><?php endif; ?>
    <details class="menu menu-geral">
        <summary class="menu-botao<?= in_array($activePage, ['ajuda', 'ajustes', 'simular', 'meta', 'emprestimo', 'investimentos', 'wishlist', 'temas'], true) ? ' is-active' : '' ?>"><?= svgIcon('menu', 19) ?><span><?= e(t('Menu')) ?></span></summary>
        <div class="menu-painel">
            <?php if ($nome): ?><div class="menu-quem"><?= e($nome) ?></div><?php endif; ?>
            <a class="menu-item<?= $activePage === 'relatorio' ? ' is-active' : '' ?>" href="/relatorio"<?= $atual('relatorio') ?>><?= svgIcon('relatorio', 18) ?><?= e(t('Relatório')) ?></a>
            <a class="menu-item<?= $activePage === 'simular' ? ' is-active' : '' ?>" href="/futuro"<?= $atual('simular') ?>><?= svgIcon('trend', 18) ?><?= e(t('Simular')) ?></a>
            <a class="menu-item<?= $activePage === 'meta' ? ' is-active' : '' ?>" href="/meta"<?= $atual('meta') ?>><?= svgIcon('target', 18) ?><?= e(t('Metas')) ?></a>
            <?php if ($usaEmprestimo): ?><a class="menu-item<?= $activePage === 'emprestimo' ? ' is-active' : '' ?>" href="/emprestimo"<?= $atual('emprestimo') ?>><?= svgIcon('emprestimo', 18) ?><?= e(t('Empréstimo')) ?></a><?php endif; ?>
            <a class="menu-item<?= $activePage === 'investimentos' ? ' is-active' : '' ?>" href="/investimentos"<?= $atual('investimentos') ?>><?= svgIcon('invest', 18) ?><?= e(t('Investimentos')) ?></a>
            <a class="menu-item<?= $activePage === 'wishlist' ? ' is-active' : '' ?>" href="/wishlist"<?= $atual('wishlist') ?>><?= svgIcon('gift', 18) ?><?= e(t('Wishlist')) ?></a>
            <a class="menu-item<?= $activePage === 'temas' ? ' is-active' : '' ?>" href="/temas"<?= $atual('temas') ?>><?= svgIcon('paleta', 18) ?><?= e(t('Temas')) ?></a>
            <a class="menu-item<?= $activePage === 'ajuda' ? ' is-active' : '' ?>" href="/ajuda"<?= $atual('ajuda') ?>><?= svgIcon('ajuda', 18) ?><?= e(t('Ajuda')) ?></a>
            <a class="menu-item<?= $activePage === 'ajustes' ? ' is-active' : '' ?>" href="/ajustes"<?= $atual('ajustes') ?>><?= svgIcon('settings', 18) ?><?= e(t('Ajustes')) ?></a>
            <form method="post" action="/logout">
                <?= csrfField() ?>
                <button type="submit" class="menu-item"><?= svgIcon('logout', 18) ?><?= e(t('Sair')) ?></button>
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
        <form class="toast-acao" method="post" action="<?= e(voltaSegura($flash['acao']['url'], '/')) ?>"<?= is_string($flash['acao']['confirmar'] ?? null) ? ' data-confirm="' . e($flash['acao']['confirmar']) . '"' : '' ?>>
            <?= csrfField() ?>
            <?php foreach ((array) ($flash['acao']['campos'] ?? []) as $campo => $valor): ?>
                <input type="hidden" name="<?= e((string) $campo) ?>" value="<?= e((string) $valor) ?>">
            <?php endforeach; ?>
            <button type="submit"><?= e((string) ($flash['acao']['label'] ?? t('desfazer'))) ?></button>
        </form>
    <?php endif; ?>
    <button type="button" class="toast-x" data-toast-fechar aria-label="<?= e(t('fechar')) ?>"><?= svgIcon('x', 16) ?></button>
</div>
<?php endif; ?>

<main class="page" id="conteudo">
<?= $content ?>
</main>

<nav class="tabbar" aria-label="<?= e(t('principal')) ?>">
    <?php $i = 0; foreach ($barra as $chave => [$href, $rotulo, $icone]): ?>
        <?php if ($i++ === 2): ?>
            <a class="tab-add" href="<?= e($hrefLancar) ?>" data-lancar aria-label="<?= e(t('lançar')) ?>"><?= svgIcon('plus', 24) ?></a>
        <?php endif; ?>
        <a class="tab<?= $acesaNaBarra === $chave ? ' is-active' : '' ?>" href="<?= e($href) ?>"<?= $atual($chave) ?>><?= svgIcon($icone, 21) ?><span><?= e($rotulo) ?></span></a>
    <?php endforeach; ?>
</nav>

<?php if ($comDialogo): ?>
<dialog id="lancar" class="sheet" aria-label="<?= e(t('lançar')) ?>">
<?php View::partial('lancamentos/_form', \Financeiro\Services\LancarForm::dados() + ['modo' => 'dialog', 'pref' => 'dlg', 'volta' => $volta]); ?>
</dialog>
<?php endif; ?>

<?php foreach ($scripts as $js): ?>
<script src="<?= e(asset($js)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
