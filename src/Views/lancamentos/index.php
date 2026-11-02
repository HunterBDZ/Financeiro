<?php
/**
 * Extrato. Variáveis: $mes, $meses, $situacao, $estado, $hoje, $usuarios, $f, $quem,
 * $categoria (?array), $categorias (array), $q, $params (filtros ativos, com mes), $limite, $linhas,
 * $totais, $filtrado, $renda (?float, só sem filtro), $urlAtual, $form (partial de lançar da coluna do desktop).
 */
use Financeiro\View;

/** Cabeçalho do grupo de um dia: Hoje · Ontem · Qua, 14 de out (agendado, se for depois de hoje). */
$rotuloDia = static function (string $data) use ($hoje): string {
    if ($data === $hoje) {
        return t('Hoje');
    }
    if ($data === date('Y-m-d', (int) strtotime($hoje . ' -1 day'))) {
        return t('Ontem');
    }
    $ts = (int) strtotime($data);
    $rotulo = mb_convert_case(\Financeiro\Idioma::diaSemana((int) date('w', $ts)), MB_CASE_TITLE) . ', ' . t('{dia} de {mes}', ['dia' => (int) date('j', $ts), 'mes' => \Financeiro\Idioma::mesCurto((int) date('n', $ts))]);
    return $data > $hoje ? t('{data} (agendado)', ['data' => $rotulo]) : $rotulo;
};

/** URL do extrato com os filtros atuais trocados por $mudar ('' remove). */
$url = static function (array $mudar = []) use ($params): string {
    $p = array_filter(array_merge($params, $mudar), static fn($v) => $v !== '' && $v !== null && $v !== 0);
    return '/lancamentos?' . http_build_query($p, '', '&', PHP_QUERY_RFC3986);
};
$semMes = array_diff_key($params, ['mes' => 1]);
$sufixo = $semMes !== [] ? '&' . http_build_query($semMes, '', '&', PHP_QUERY_RFC3986) : '';
$voltaEnc = rawurlencode($urlAtual);

// agrupa por dia só quando a ordem é por dia; em valor/alfabética a lista é corrida
// (cada linha já mostra a própria data).
$dias = [];
if ($ordem === '' || $ordem === 'dia-asc') {
    foreach ($linhas as $r) {
        $dias[$r['data']][] = $r;
    }
} else {
    $dias = $linhas === [] ? [] : ['tudo' => $linhas];
}
$exibidas = count($linhas);
$faltam   = max(0, (int) $totais['n'] - $exibidas);


// agrupa as categorias por classe para o dropdown: entradas, essenciais, não essenciais, ⇄
$classeDe = static function (array $c): string {
    if ((int) $c['operacional'] === 0) { return 'mov'; }
    if ((int) $c['sinal'] === 1)       { return 'entradas'; }
    return (int) $c['essencial'] === 1 ? 'essenciais' : 'naoess';
};
$classes = [
    'entradas'   => ['rotulo' => t('Entradas'),        'todas' => t('Todas as entradas'),       'cats' => []],
    'essenciais' => ['rotulo' => t('Essenciais'),      'todas' => t('Todas as essenciais'),     'cats' => []],
    'naoess'     => ['rotulo' => t('Não essenciais'),  'todas' => t('Todas as não essenciais'), 'cats' => []],
    'mov'        => ['rotulo' => t('⇄ Movimentações'), 'todas' => t('Todas as movimentações'),  'cats' => []],
];
foreach ($categorias as $c) {
    $classes[$classeDe($c)]['cats'][] = $c;
}
$catAtual = $categoria !== null ? (int) $categoria['id'] : 0;
?>
<div class="ex">
    <aside class="ex-col" aria-label="<?= e(t('lançar')) ?>">
        <div class="card ex-form"><?php View::partial('lancamentos/_form', $form); ?></div>
    </aside>

    <section class="ex-main" aria-label="<?= e(t('extrato')) ?>">
        <div class="ex-topo">
            <?php View::partial('partials/mes_nav', ['mes' => $mes, 'meses' => $meses, 'href' => '/lancamentos?mes=', 'sufixo' => $sufixo]); ?>
        </div>

        <!-- filtros -->
        <div class="ex-filtros-wrap">
            <form class="ex-filtros-row" method="get" action="/lancamentos" role="search" id="ex-filtro-form">
                <?php foreach (array_diff_key($params, ['q' => 1, 'f' => 1, 'cat' => 1, 'ordem' => 1]) as $k => $v): ?>
                    <input type="hidden" name="<?= e((string) $k) ?>" value="<?= e((string) $v) ?>">
                <?php endforeach; ?>

                <div class="ex-busca-inline">
                    <span class="ex-busca-icon" aria-hidden="true"><?= svgIcon('search', 16) ?></span>
                    <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('Buscar descrição ou categoria…')) ?>" aria-label="<?= e(t('buscar')) ?>"
                           enterkeyhint="search" autocomplete="off" maxlength="80" class="ex-busca-input">
                </div>

                <?php $selVal = $catAtual > 0 ? (string) $catAtual : ($f !== '' ? 'g:' . $f : ''); ?>
                <select class="ex-cat-select" aria-label="<?= e(t('categoria')) ?>" id="ex-cat-sel">
                    <option value=""><?= e(t('Todas as categorias')) ?></option>
                    <?php foreach ($classes as $gf => $cl): if ($cl['cats'] === []) { continue; } ?>
                    <optgroup label="<?= e($cl['rotulo']) ?>">
                        <option value="g:<?= e($gf) ?>"<?= $selVal === 'g:' . $gf ? ' selected' : '' ?>><?= e($cl['todas']) ?></option>
                        <?php foreach ($cl['cats'] as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === $catAtual ? ' selected' : '' ?>><?= e(nomeCurto($c)) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endforeach; ?>
                </select>

                <?php
                $ordens = ['' => t('Mais recentes'), 'dia-asc' => t('Mais antigos'), 'valor-desc' => t('Maior valor'), 'valor-asc' => t('Menor valor'), 'alfa' => t('De A a Z')];
                ?>
                <select class="ex-ordem-select" name="ordem" aria-label="<?= e(t('ordenar')) ?>" data-autosubmit>
                    <?php foreach ($ordens as $ov => $olbl): ?>
                        <option value="<?= e($ov) ?>"<?= $ordem === $ov ? ' selected' : '' ?>><?= e($olbl) ?></option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="btn btn-primary ex-busca-btn" aria-label="<?= e(t('buscar')) ?>"><?= svgIcon('search', 16) ?><span class="ex-busca-txt"><?= e(t('Buscar')) ?></span></button>
            </form>

            <?php if ($filtrado): ?>
                <div class="ex-filtros-ativos">
                    <?php if ($q !== ''): ?>
                        <a class="chip is-on ex-chip-x" href="<?= e($url(['q' => ''])) ?>" aria-label="<?= e(t('tirar busca')) ?>">“<?= e($q) ?>” <span aria-hidden="true">×</span></a>
                    <?php endif; ?>
                    <?php if ($categoria !== null): ?>
                        <a class="chip is-on ex-chip-x" href="<?= e($url(['cat' => ''])) ?>" aria-label="<?= e(t('tirar filtro {nome}', ['nome' => nomeCurto($categoria)])) ?>"><?= e(nomeCurto($categoria)) ?> <span aria-hidden="true">×</span></a>
                    <?php endif; ?>
                    <a class="ex-limpar" href="<?= e($url(['f' => '', 'cat' => '', 'q' => ''])) ?>"><?= e(t('Limpar filtros')) ?></a>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($linhas === []): ?>
            <div class="card ex-vazio">
                <?php if ($q !== ''): ?>
                    <p><?= e(t('Nada com “{q}” em {mes}.', ['mes' => monthLabel($mes), 'q' => $q])) ?></p>
                <?php elseif ($categoria !== null): ?>
                    <p><?= e(t('Nada de {categoria} em {mes}.', ['mes' => monthLabel($mes), 'categoria' => nomeCurto($categoria)])) ?></p>
                <?php else: ?>
                    <?php if ($estado === 'previsto'): ?>
                        <p><?= e(t('{mes} ainda não chegou. Veja a estimativa na tela Mês.', ['mes' => mb_convert_case(mesExtenso($mes), MB_CASE_TITLE)])) ?></p>
                        <a class="btn btn-ghost" href="/mes?mes=<?= e($mes) ?>"><?= e(t('Mês')) ?></a>
                    <?php else: ?>
                        <p><?= e(t('Nada lançado em {mes}.', ['mes' => monthLabel($mes)])) ?></p>
                        <a class="btn btn-primary" href="/lancar?volta=<?= e($voltaEnc) ?>" data-lancar><?= e(t('Lançar')) ?></a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php if ($filtrado): ?>
                <div class="ex-resumo">
                    <span class="ex-resumo-item"><span class="ex-resumo-num num"><?= (int) $totais['n'] ?></span><span class="ex-resumo-label"><?= e(tn((int) $totais['n'], 'lançamento', 'lançamentos')) ?></span></span>
                    <?php if ((int) $totais['n_op'] > 0): ?>
                        <span class="ex-resumo-item"><span class="ex-resumo-num num"><?= e(money((float) $totais['soma'])) ?></span><span class="ex-resumo-label"><?= e(t('total')) ?></span></span>
                    <?php endif; ?>
                    <?php if ((float) $totais['mov'] > 0.004): ?>
                        <span class="ex-resumo-item"><span class="ex-resumo-num num ex-v-mov">⇄ <?= e(num((float) $totais['mov'])) ?></span><span class="ex-resumo-label"><?= e(t('movimentações')) ?></span></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="card ex-lista">
                <?php $agrupado = $ordem === '' || $ordem === 'dia-asc'; $i = 0; foreach ($dias as $data => $rows): ?>
                    <?php if ($agrupado): ?><h2 class="ex-dia"><?= e($rotuloDia((string) $data)) ?></h2><?php endif; ?>
                    <ul class="lista">
                        <?php foreach ($rows as $r): ?>
                            <?php
                            $mov = (int) $r['operacional'] !== 1;
                            $ent = !$mov && (int) $r['sinal'] > 0;
                            $v   = (float) $r['valor'];
                            $txt = $mov ? '⇄ ' . num($v) : ($ent ? '+' . num($v) : MENOS . num($v));
                            $cls = $mov ? 'ex-v-mov' : ($ent ? 'ex-v-ent' : 'ex-v-gasto neg');
                            $cat = ['nome' => $r['categoria_nome'], 'apelido' => $r['apelido']];
                            ?>
                            <?php $desc = descricaoTela(trim((string) $r['descricao']), $cat); $rot = $desc !== '' ? $desc : nomeCurto($cat); ?>
                            <li><a class="lista-i ex-linha" href="/lancamentos/<?= (int) $r['id'] ?>/editar?volta=<?= e($voltaEnc) ?>"<?= $i === $limite - 60 && $i > 0 ? ' id="ex-r' . $i . '"' : '' ?>>
                                <?= iconeCategoria((int) $r['categoria_id'], (string) $r['categoria_nome'], (string) $r['grupo'], 40) ?>
                                <span class="lista-n"><b><?= e($rot) ?></b><?php $mesmoNome = mb_strtolower($rot) === mb_strtolower(nomeCurto($cat)); $quando = $agrupado ? '' : \Financeiro\Idioma::diaSemana((int) date('w', strtotime($r['data']))) . ' ' . diaMes($r['data']); $sub = implode(', ', array_filter([$mesmoNome ? '' : nomeCurto($cat), $quando])); ?><?php if ($sub !== ''): ?><small><?= e($sub) ?></small><?php endif; ?></span>
                                <span class="lista-v"><b class="num <?= $cls ?>"><?= e($txt) ?></b><?php if ($mov): ?><small class="ex-mov"><?= e(t('Movimentação')) ?></small><?php endif; ?><?php if ($r['data'] > $hoje): ?><small class="ex-agendado"><?= e(t('agendado')) ?></small><?php endif; ?></span>
                            </a></li>
                            <?php $i++; ?>
                        <?php endforeach; ?>
                    </ul>
                <?php endforeach; ?>

                <?php if ($faltam > 0): ?>
                    <a class="btn btn-ghost btn-block ex-mais" href="<?= e($url(['limite' => $limite + 60])) ?>#ex-r<?= (int) $limite ?>"><?= e(t('Ver mais ({n})', ['n' => $faltam])) ?></a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
