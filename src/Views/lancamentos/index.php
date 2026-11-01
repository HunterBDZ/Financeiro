<?php
/**
 * Extrato (P4, SPEC §2.3). Variáveis: $mes, $meses, $situacao, $estado, $hoje, $usuarios, $f, $quem,
 * $categoria (?array), $categorias (array), $q, $params (filtros ativos, com mes), $limite, $linhas,
 * $totais, $filtrado, $renda (?float, só sem filtro), $urlAtual, $form (partial de lançar da coluna do desktop).
 */
use Financeiro\View;

$semana = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
$mesesCurtos = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
/** Cabeçalho do grupo de um dia: Hoje · Ontem · Qua, 14 de out (agendado, se for depois de hoje). */
$rotuloDia = static function (string $data) use ($hoje, $semana, $mesesCurtos): string {
    if ($data === $hoje) {
        return 'Hoje';
    }
    if ($data === date('Y-m-d', (int) strtotime($hoje . ' -1 day'))) {
        return 'Ontem';
    }
    $ts = (int) strtotime($data);
    return mb_convert_case($semana[(int) date('w', $ts)], MB_CASE_TITLE) . ', ' . (int) date('j', $ts) . ' de ' . $mesesCurtos[(int) date('n', $ts) - 1]
        . ($data > $hoje ? ' (agendado)' : '');
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
    'entradas'   => ['rotulo' => 'Entradas',        'todas' => 'Todas as entradas',       'cats' => []],
    'essenciais' => ['rotulo' => 'Essenciais',      'todas' => 'Todas as essenciais',     'cats' => []],
    'naoess'     => ['rotulo' => 'Não essenciais',  'todas' => 'Todas as não essenciais', 'cats' => []],
    'mov'        => ['rotulo' => '⇄ Movimentações', 'todas' => 'Todas as movimentações',  'cats' => []],
];
foreach ($categorias as $c) {
    $classes[$classeDe($c)]['cats'][] = $c;
}
$catAtual = $categoria !== null ? (int) $categoria['id'] : 0;
?>
<div class="ex">
    <aside class="ex-col" aria-label="lançar">
        <div class="card ex-form"><?php View::partial('lancamentos/_form', $form); ?></div>
    </aside>

    <section class="ex-main" aria-label="extrato">
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
                    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar descrição ou categoria…" aria-label="buscar"
                           enterkeyhint="search" autocomplete="off" maxlength="80" class="ex-busca-input">
                </div>

                <?php $selVal = $catAtual > 0 ? (string) $catAtual : ($f !== '' ? 'g:' . $f : ''); ?>
                <select class="ex-cat-select" aria-label="categoria" id="ex-cat-sel">
                    <option value="">Todas as categorias</option>
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
                $ordens = ['' => 'Mais recentes', 'dia-asc' => 'Mais antigos', 'valor-desc' => 'Maior valor', 'valor-asc' => 'Menor valor', 'alfa' => 'De A a Z'];
                ?>
                <select class="ex-ordem-select" name="ordem" aria-label="ordenar" data-autosubmit>
                    <?php foreach ($ordens as $ov => $olbl): ?>
                        <option value="<?= e($ov) ?>"<?= $ordem === $ov ? ' selected' : '' ?>><?= e($olbl) ?></option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="btn btn-primary ex-busca-btn" aria-label="buscar"><?= svgIcon('search', 16) ?><span class="ex-busca-txt">Buscar</span></button>
            </form>

            <?php if ($filtrado): ?>
                <div class="ex-filtros-ativos">
                    <?php if ($q !== ''): ?>
                        <a class="chip is-on ex-chip-x" href="<?= e($url(['q' => ''])) ?>" aria-label="tirar busca">“<?= e($q) ?>” <span aria-hidden="true">×</span></a>
                    <?php endif; ?>
                    <?php if ($categoria !== null): ?>
                        <a class="chip is-on ex-chip-x" href="<?= e($url(['cat' => ''])) ?>" aria-label="tirar filtro <?= e(nomeCurto($categoria)) ?>"><?= e(nomeCurto($categoria)) ?> <span aria-hidden="true">×</span></a>
                    <?php endif; ?>
                    <a class="ex-limpar" href="<?= e($url(['f' => '', 'cat' => '', 'q' => ''])) ?>">Limpar filtros</a>
                </div>
            <?php endif; ?>
        </div>

        <details class="card ex-nova" id="nova-categoria">
            <summary class="ex-nova-sum"><?= svgIcon('settings', 16) ?>Categorias</summary>

            <form class="ex-nova-form ex-nova-criar" method="post" action="/categorias">
                <?= csrfField() ?>
                <input type="hidden" name="volta" value="<?= e($urlAtual) ?>">
                <label class="campo ex-nova-nome"><span>Nome da categoria</span>
                    <input type="text" name="nome" maxlength="100" required autocomplete="off" placeholder="ex.: Pet, Farmácia">
                </label>
                <fieldset class="ex-nova-opts">
                    <legend>Tipo</legend>
                    <div class="chip-row">
                        <label class="chip-r"><input type="radio" name="tipo" value="saida" checked><span>Saída</span></label>
                        <label class="chip-r"><input type="radio" name="tipo" value="entrada"><span>Entrada</span></label>
                    </div>
                </fieldset>
                <fieldset class="ex-nova-opts ex-nova-ess">
                    <legend>Classe</legend>
                    <div class="chip-row">
                        <label class="chip-r"><input type="radio" name="essencial" value="1" checked><span>Essencial</span></label>
                        <label class="chip-r"><input type="radio" name="essencial" value="0"><span>Não essencial</span></label>
                    </div>
                </fieldset>
                <?php View::partial('partials/icone_picker', ['sugerir' => true, 'legenda' => 'Ícone']); ?>
                <button type="submit" class="btn btn-primary ex-nova-btn">Criar categoria</button>
            </form>

            <div class="ex-cats">
                <?php foreach ($classes as $cl): if ($cl['cats'] === []) { continue; } ?>
                    <p class="ex-cats-grupo"><?= e($cl['rotulo']) ?></p>
                    <ul class="ex-cats-lista">
                        <?php foreach ($cl['cats'] as $c): ?>
                            <li class="ex-cat-item">
                                <a class="ex-cat-ic" href="/categorias/<?= (int) $c['id'] ?>/icone?volta=<?= e(rawurlencode($urlAtual . '#nova-categoria')) ?>" title="Trocar o ícone" aria-label="trocar o ícone de <?= e(nomeCurto($c)) ?>"><?= iconeCategoria((int) $c['id'], (string) $c['nome'], (string) ($c['grupo'] ?? ''), 40) ?></a>
                                <span class="ex-cat-nome"><?= e(nomeCurto($c)) ?></span>
                                <form method="post" action="/categorias/<?= (int) $c['id'] ?>/remover" class="ex-cat-rm"
                                      data-confirm="Remover a categoria <?= e(nomeCurto($c)) ?>? Se ela tiver lançamentos, só sai das listas.">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="volta" value="<?= e($urlAtual) ?>">
                                    <button type="submit" class="ex-cat-x" aria-label="remover <?= e(nomeCurto($c)) ?>"><?= svgIcon('x', 16) ?></button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endforeach; ?>
            </div>
        </details>

        <?php if ($linhas === []): ?>
            <div class="card ex-vazio">
                <?php if ($q !== ''): ?>
                    <p>Nada com “<?= e($q) ?>” em <?= e(monthLabel($mes)) ?>.</p>
                <?php elseif ($categoria !== null): ?>
                    <p>Nada de <?= e(nomeCurto($categoria)) ?> em <?= e(monthLabel($mes)) ?>.</p>
                <?php else: ?>
                    <p>Nada lançado em <?= e(monthLabel($mes)) ?>.</p>
                    <a class="btn btn-primary" href="/lancar?volta=<?= e($voltaEnc) ?>" data-lancar>Lançar</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php if ($filtrado): ?>
                <div class="ex-resumo">
                    <span class="ex-resumo-item"><span class="ex-resumo-num num"><?= (int) $totais['n'] ?></span><span class="ex-resumo-label"><?= (int) $totais['n'] === 1 ? 'lançamento' : 'lançamentos' ?></span></span>
                    <?php if ((int) $totais['n_op'] > 0): ?>
                        <span class="ex-resumo-item"><span class="ex-resumo-num num"><?= e(money((float) $totais['soma'])) ?></span><span class="ex-resumo-label">total</span></span>
                    <?php endif; ?>
                    <?php if ((float) $totais['mov'] > 0.004): ?>
                        <span class="ex-resumo-item"><span class="ex-resumo-num num ex-v-mov">⇄ <?= e(num((float) $totais['mov'])) ?></span><span class="ex-resumo-label">movimentações</span></span>
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
                            <?php $desc = trim((string) $r['descricao']); $rot = $desc !== '' ? $desc : nomeCurto($cat); ?>
                            <li><a class="lista-i ex-linha" href="/lancamentos/<?= (int) $r['id'] ?>/editar?volta=<?= e($voltaEnc) ?>"<?= $i === $limite - 60 && $i > 0 ? ' id="ex-r' . $i . '"' : '' ?>>
                                <?= iconeCategoria((int) $r['categoria_id'], (string) $r['categoria_nome'], (string) $r['grupo'], 40) ?>
                                <span class="lista-n"><b><?= e($rot) ?></b><?php $mesmoNome = mb_strtolower($rot) === mb_strtolower(nomeCurto($cat)); $quando = $agrupado ? '' : $semana[(int) date('w', strtotime($r['data']))] . ' ' . diaMes($r['data']); $sub = implode(', ', array_filter([$mesmoNome ? '' : nomeCurto($cat), $quando])); ?><?php if ($sub !== ''): ?><small><?= e($sub) ?></small><?php endif; ?></span>
                                <span class="lista-v"><b class="num <?= $cls ?>"><?= e($txt) ?></b><?php if ($r['data'] > $hoje): ?><small class="ex-agendado">agendado</small><?php endif; ?></span>
                            </a></li>
                            <?php $i++; ?>
                        <?php endforeach; ?>
                    </ul>
                <?php endforeach; ?>

                <?php if ($faltam > 0): ?>
                    <a class="btn btn-ghost btn-block ex-mais" href="<?= e($url(['limite' => $limite + 60])) ?>#ex-r<?= (int) $limite ?>">Ver mais (<?= $faltam ?>)</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
