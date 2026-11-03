<?php
/**
 * Tela Temas: cada paleta (a padrão, as da casa e as de acessibilidade) com a amostra dos dois lados e a escolha de
 * claro, escuro ou automático (segue o aparelho). Escolher mostra o tema no app inteiro, e só fica ao salvar
 * (temas.js); sem JS, escolher já salva. Variáveis: $paletas (Temas::paletas), $atual (id ou null = automático da padrão).
 */
use Financeiro\Temas;

$csrf = csrfField();
// i18n: t('Da casa') t('Acessibilidade')
$grupos = [];
foreach ($paletas as $pid => $p) {
    $grupos[$p['grupo']][$pid] = $p;
}
$modos = ['light' => t('Claro'), 'dark' => t('Escuro'), 'auto' => t('Automático')];
// um lado da prévia: o painel do mês em miniatura, com as cores do próprio tema. No fundo, o painel (superfície)
// com o rótulo, o número grande, a barra do dinheiro do mês (o que saiu, as contas, o que dá para gastar em azul)
// e uma linha de lista com um valor que entrou e outro que saiu
$lado = static fn(array $c): string => '<span class="tm-lado" style="--a-fundo:' . e($c['fundo']) . ';--a-sup:' . e($c['superficie']) . ';--a-tinta:' . e($c['tinta'])
    . ';--a-tinta2:' . e($c['tinta2']) . ';--a-fio:' . e($c['fio']) . ';--a-azul:' . e($c['azul']) . ';--a-entrada:' . e($c['entrada']) . ';--a-saida:' . e($c['saida']) . '">'
    . '<span class="tm-painel"><i class="tm-rot"></i><i class="tm-num"></i><span class="tm-segs"><i></i><i></i><i></i></span>'
    . '<span class="tm-vals"><i class="tm-e"></i><i class="tm-s"></i></span></span></span>';
?>
<div class="grade aj-subtela tms">
    <?php \Financeiro\View::partial('ajustes/_sub_topo', ['titulo' => t('Temas'), 'apoio' => t('Escolha para experimentar. Salve para manter em todos os seus aparelhos.')]); ?>
    <?php foreach ($grupos as $grupo => $lista): ?>
        <section class="faixa-grade" aria-labelledby="tm-g-<?= e(md5((string) $grupo)) ?>">
            <h2 class="tm-grupo" id="tm-g-<?= e(md5((string) $grupo)) ?>"><?= e(t((string) $grupo)) ?></h2>
            <ul class="tm-lista">
                <?php foreach ($lista as $pid => $p):
                    $nomeP = t((string) $p['nome']); ?>
                    <li class="tm-pal">
                        <span class="tm-am" aria-hidden="true"><?= $lado($p['claro']) . $lado($p['escuro']) ?></span>
                        <div class="tm-info">
                            <h3 class="tm-n"><?= e($nomeP) ?></h3>
                            <div class="tm-modos" role="group" aria-label="<?= e($nomeP) ?>">
                                <?php foreach ($modos as $modo => $rotulo):
                                    $id = $p['ids'][$modo];
                                    $global = $id === null;          // o automático da padrão: a conta sem tema
                                    if ($global && $pid !== 'padrao') {
                                        continue;
                                    }
                                    $on = $global ? $atual === null : $atual === $id;
                                    $bg = $modo === 'dark' ? $p['escuro']['fundo'] : $p['claro']['fundo']; ?>
                                    <form method="post" action="/temas" data-tm-form>
                                        <?= $csrf ?>
                                        <input type="hidden" name="tema" value="<?= e($global ? 'auto' : (string) $id) ?>">
                                        <button class="tm<?= $on ? ' is-on' : '' ?>" type="submit"<?= $on ? ' aria-current="true"' : '' ?>
                                            data-tm-nome="<?= e(Temas::nome($id)) ?>"<?= $global ? ' data-tm-auto' : ' data-tm-id="' . e((string) $id) . '" data-tm-modo="' . e($modo) . '"' ?> data-tm-bg="<?= e($bg) ?>">
                                            <?= $on ? svgIcon('check', 14) : '' ?><?= e($rotulo) ?>
                                        </button>
                                    </form>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>
    <div class="tm-barra" data-tm-barra hidden role="status">
        <span class="tm-barra-n" data-tm-barra-n></span>
        <button class="btn btn-contorno" type="button" data-tm-voltar><?= e(t('Voltar')) ?></button>
        <button class="btn btn-principal" type="button" data-tm-salvar><?= e(t('Salvar tema')) ?></button>
    </div>
</div>
