<?php
/**
 * Layout. Variáveis: $content, $pageTitle, $active (inicio|extrato|meta|mes|cartao|relatorio|emprestimo|wishlist|temas|ajustes),
 * $semDialogoLancar (true = não renderiza o <dialog id="lancar">).
 * Computador: menu lateral (a marca, Lançar com a tecla N, as abas na ordem da conta em 3 grupos e a pessoa no pé).
 * Celular: sem barra de cima; a barra de baixo tem Início, Extrato, o "+", Mês e Mais. O Mais abre as outras abas,
 * na ordem da conta, e o Sair. O tema (claro, escuro ou automático) fica em Ajustes > Temas.
 * O conteúdo vai em <main class="conteudo">: as telas novas usam a grade (.grade, .faixa-grade) e o topo (partials/topo).
 * CSS/JS dos pacotes entram só se o arquivo existir (D19): nenhum 404 durante o build.
 */
use Financeiro\Auth;
use Financeiro\Models\AjusteModel;
use Financeiro\Idioma;
use Financeiro\Menu;
use Financeiro\SiteIcone;
use Financeiro\Temas;
use Financeiro\View;

$activePage = (string) ($active ?? '');
// Temas e Ajuda abrem por Ajustes: lá, a aba acesa é a de Ajustes
$acesa = in_array($activePage, ['temas', 'ajuda'], true) ? 'ajustes' : $activePage;

// chave => [href, rótulo, ícone], na ordem padrão (Menu::abas)
$abas = Menu::abas();

// recursos opcionais: a aba do que a pessoa não usa fica escondida, mas guarda o lugar na ordem da conta
$usaCartao = AjusteModel::usa('usa_cartao');
$usaEmprestimo = AjusteModel::usa('usa_emprestimo');
if (!$usaCartao) {
    unset($abas['cartao']);
}
if (!$usaEmprestimo) {
    unset($abas['emprestimo']);
}
// a ordem que a pessoa escolheu (modo organizar, menu.js): vale no menu lateral e na lista do Mais
$ordemMenu = array_values(array_filter(Menu::daConta(Auth::id()), static fn(string $c): bool => isset($abas[$c])));
// a barra de baixo do celular é fixa: Início, Extrato, +, Mês e Mais. O Mais tem o resto, na ordem da conta
$naBarra = ['inicio', 'extrato', 'mes'];
$doBotaoMenu = array_values(array_diff($ordemMenu, $naBarra));
$csrfMenu = e(csrfToken());
// o que o menu.js precisa: o token, a ordem padrão daquela lista e o lugar do aviso para leitor de tela
$organizar = static function (array $chaves) use ($abas, $csrfMenu): string {
    return ' data-org data-org-csrf="' . $csrfMenu . '" data-org-padrao="' . e(implode(',', array_values(array_intersect(array_keys($abas), $chaves)))) . '"';
};

$publico = __DIR__ . '/../../../public';
$existe  = static fn(string $p): bool => is_file($publico . $p);
$estilos = array_filter(['/assets/css/style.css', '/assets/css/inicio.css', '/assets/css/lancar.css', '/assets/css/extrato.css', '/assets/css/mes.css', '/assets/css/metas.css', '/assets/css/simular.css', '/assets/css/investimentos.css', '/assets/css/relatorio.css', '/assets/css/cartao.css', '/assets/css/emprestimo.css', '/assets/css/wishlist.css', '/assets/css/importar.css', '/assets/css/temas-tela.css', '/assets/css/menu.css', '/assets/css/icones.css', '/assets/css/temas.css'], $existe);
$scripts = array_filter(['/assets/js/app.js', '/assets/js/lancar.js', '/assets/js/extrato.js', '/assets/js/mes.js', '/assets/js/metas.js', '/assets/js/cartao.js', '/assets/js/emprestimo.js', '/assets/js/wishlist.js', '/assets/js/importar.js', '/assets/js/temas.js', '/assets/js/relatorio.js', '/assets/js/fuso.js', '/assets/js/menu.js'], $existe);

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

// classe + aria-current da aba ativa (aria-current só na página que é ela mesma)
$aba = static function (string $chave) use ($acesa): string {
    return $acesa === $chave ? ' is-active' : '';
};
$atual = static function (string $chave) use ($activePage): string {
    return $activePage === $chave ? ' aria-current="page"' : '';
};
$barra = array_intersect_key($abas, array_flip($naBarra));
// com o Relatório aberto, quem fica acesa na barra é a do Mês (ele abre pelo seletor de meses do Mês)
$acesaNaBarra = $activePage === 'relatorio' ? 'mes' : $acesa;
$maisAceso = in_array($acesaNaBarra, $doBotaoMenu, true);
// o tema da conta vem no HTML: nada de piscar, e vale em qualquer aparelho. Null = automático (theme.js)
$temaConta = Temas::doUsuario();
$dadosTema = $temaConta !== null ? Temas::todos()[$temaConta] : null;
$modoTema = $dadosTema !== null ? (string) $dadosTema['modo'] : 'auto';
// a marca: o sinal, ou a imagem enviada em Ajustes
$marca = SiteIcone::personalizado() !== null
    ? '<span class="marca-sinal is-imagem"><img src="' . e(SiteIcone::url()) . '" alt="" width="28" height="28"></span>'
    : '<span class="marca-sinal">' . svgIcon('marca', 18) . '</span>';
?>
<!doctype html>
<html lang="<?= e(Idioma::html()) ?>"<?= $dadosTema !== null ? ($modoTema !== 'auto' ? ' data-theme="' . e($modoTema) . '"' : '') . ' data-tema="' . e((string) $temaConta) . '"' : ' data-tema-auto' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
<?php if ($dadosTema !== null && $modoTema !== 'auto'): ?>
<meta name="theme-color" content="<?= e((string) $dadosTema['bg']) ?>">
<?php else: ?>
<meta name="theme-color" content="<?= e((string) ($dadosTema['bg'] ?? Temas::FUNDO_CLARO)) ?>" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="<?= e((string) ($dadosTema['bgEscuro'] ?? Temas::FUNDO_ESCURO)) ?>" media="(prefers-color-scheme: dark)">
<?php endif; ?>
<meta name="format-detection" content="telephone=no">
<title><?= e($pageTitle ?? 'MyMonetto') ?></title>
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
<body>
<a class="pular" href="#conteudo"><?= e(t('Pular para o conteúdo')) ?></a>

<aside class="lateral" aria-label="<?= e(t('menu')) ?>">
    <a class="lat-marca" href="/"><?= $marca ?><b translate="no">MyMonetto</b></a>
    <a class="lat-lancar btn btn-principal" href="<?= e($hrefLancar) ?>" data-lancar aria-keyshortcuts="n"><span><?= e(t('Lançar')) ?></span><kbd aria-hidden="true">N</kbd></a>
    <div class="org"<?= $organizar($ordemMenu) ?>>
        <?php View::partial('layout/_organizar', ['parte' => 'barra']); ?>
        <nav class="lat-nav" aria-label="<?= e(t('principal')) ?>">
            <ul class="org-lista" data-org-lista>
                <?php foreach ($ordemMenu as $chave): [$href, $rotulo, $icone] = $abas[$chave]; ?>
                    <li class="org-i" data-aba="<?= e($chave) ?>" data-grupo="<?= (int) (Menu::GRUPOS[$chave] ?? 2) ?>"><a class="lat-i<?= $aba($chave) ?>" href="<?= e($href) ?>"<?= $atual($chave) ?>><?= svgIcon($icone, 18) ?><span><?= e($rotulo) ?></span></a></li>
                <?php endforeach; ?>
            </ul>
        </nav>
        <?php View::partial('layout/_organizar', ['parte' => 'abrir']); ?>
    </div>
    <div class="lat-fim">
        <div class="lat-user">
            <span class="avatar" aria-hidden="true"><?= e($inicial) ?></span>
            <b><?= e((string) ($nome ?? '')) ?></b>
            <form method="post" action="/logout">
                <?= csrfField() ?>
                <button type="submit" class="lat-sair" aria-label="<?= e(t('sair')) ?>"><?= svgIcon('logout', 18) ?></button>
            </form>
        </div>
    </div>
</aside>

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

<main class="conteudo" id="conteudo">
<?= $content ?>
</main>

<nav class="tabbar" aria-label="<?= e(t('principal')) ?>">
    <?php $i = 0; foreach ($barra as $chave => [$href, $rotulo, $icone]): ?>
        <?php if ($i++ === 2): ?>
            <a class="tab-add" href="<?= e($hrefLancar) ?>" data-lancar aria-label="<?= e(t('lançar')) ?>"><?= svgIcon('plus', 22) ?></a>
        <?php endif; ?>
        <a class="tab<?= $acesaNaBarra === $chave ? ' is-active' : '' ?>" href="<?= e($href) ?>"<?= $atual($chave) ?>><?= svgIcon($icone, 20) ?><span><?= e($rotulo) ?></span></a>
    <?php endforeach; ?>
    <details class="menu menu-geral tab-mais">
        <summary class="tab menu-botao<?= $maisAceso ? ' is-active' : '' ?>"><?= svgIcon('more', 20) ?><span><?= e(t('Mais')) ?></span></summary>
        <div class="menu-painel">
            <?php if ($nome): ?><div class="menu-quem"><?= e($nome) ?></div><?php endif; ?>
            <div class="org org-celular"<?= $organizar($doBotaoMenu) ?>>
                <?php View::partial('layout/_organizar', ['parte' => 'barra']); ?>
                <ul class="org-lista" data-org-lista>
                    <?php foreach ($doBotaoMenu as $chave): [$href, $rotulo, $icone] = $abas[$chave]; ?>
                        <li class="org-i" data-aba="<?= e($chave) ?>"><a class="menu-item<?= $aba($chave) ?>" href="<?= e($href) ?>"<?= $atual($chave) ?>><?= svgIcon($icone, 18) ?><?= e($rotulo) ?></a></li>
                    <?php endforeach; ?>
                </ul>
                <?php View::partial('layout/_organizar', ['parte' => 'abrir']); ?>
            </div>
            <form method="post" action="/logout">
                <?= csrfField() ?>
                <button type="submit" class="menu-item"><?= svgIcon('logout', 18) ?><?= e(t('Sair')) ?></button>
            </form>
        </div>
    </details>
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
