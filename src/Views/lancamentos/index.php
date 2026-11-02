<?php
/**
 * Extrato. Variáveis: $mes, $meses, $situacao, $estado, $hoje, $usuarios, $f, $quem,
 * $categoria (?array), $categorias (array), $q, $modo ('' = o mês; ano, tudo, datas), $periodo (?de/ate),
 * $rotuloPeriodo, $valores (min/max como digitados), $erroValor, $params (filtros ativos, com mes), $limite,
 * $linhas, $totais, $filtrado, $renda (?float, só sem filtro), $urlAtual, $urlPlanilha,
 * $form (partial de lançar da coluna do desktop).
 * Com mais de uma conta: $contas (todas, para o filtro), $conta (a filtrada, ou null), $transferencias (as do período,
 * com ↔ e fora dos totais), $saldoConta (o saldo da conta filtrada); $nomesCartao (com mais de um cartão, o nome de
 * cada um, para o pagamento de fatura dizer de qual cartão é).
 * A busca vale para o mês, o ano dele, todos os meses ou entre datas; "Mais filtros" tem valor e quem lançou.
 * Com filtro ou fora do mês, o topo responde com o total do que foi achado.
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
    $rotulo = mb_convert_case(\Financeiro\Idioma::diaSemana((int) date('w', $ts)), MB_CASE_TITLE) . ', ' . (substr($data, 0, 4) === substr($hoje, 0, 4)
        ? t('{dia} de {mes}', ['dia' => (int) date('j', $ts), 'mes' => \Financeiro\Idioma::mesCurto((int) date('n', $ts))])
        : t('{dia} de {mes} de {ano}', ['dia' => (int) date('j', $ts), 'mes' => \Financeiro\Idioma::mesCurto((int) date('n', $ts)), 'ano' => date('Y', $ts)]));
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
// (cada linha já mostra a própria data). As transferências entram no dia delas (ou no fim da lista corrida).
$contas = $contas ?? [];
$conta = $conta ?? null;
$transferencias = $transferencias ?? [];
$nomesCartao = $nomesCartao ?? [];
$nomeContaDe = [];
foreach ($contas as $c) {
    $nomeContaDe[$c['id']] = nomeConta($c);
}
$dias = [];
if ($ordem === '' || $ordem === 'dia-asc') {
    foreach ($linhas as $r) {
        $dias[$r['data']][] = $r;
    }
    foreach ($transferencias as $tr) {
        $dias[$tr['data']][] = ['transf' => true] + $tr;
    }
    $ordem === '' ? krsort($dias) : ksort($dias);
} else {
    $dias = $linhas === [] && $transferencias === [] ? [] : ['tudo' => array_merge($linhas, array_map(static fn(array $tr): array => ['transf' => true] + $tr, $transferencias))];
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
// "Importar extrato": com a conta filtrada, ela já vem escolhida
$urlImportar = '/importar' . ($conta !== null ? '?destino=conta%3A' . (int) $conta['id'] : '');
?>
<div class="ex">
    <aside class="ex-col" aria-label="<?= e(t('lançar')) ?>">
        <div class="card ex-form"><?php View::partial('lancamentos/_form', $form); ?></div>
    </aside>

    <section class="ex-main" aria-label="<?= e(t('extrato')) ?>">
        <div class="ex-topo">
            <?php if ($modo === ''): ?>
                <?php View::partial('partials/mes_nav', ['mes' => $mes, 'meses' => $meses, 'href' => '/lancamentos?mes=', 'sufixo' => $sufixo]); ?>
            <?php else: ?>
                <div class="ex-periodo-topo">
                    <h1 class="pg-titulo"><?= e($rotuloPeriodo) ?></h1>
                    <a class="ex-voltar-mes" href="<?= e($url(['periodo' => '', 'de' => '', 'ate' => ''])) ?>"><?= e(t('Ver só {mes}', ['mes' => mesAno($mes)])) ?></a>
                </div>
            <?php endif; ?>
        </div>

        <!-- filtros -->
        <div class="ex-filtros-wrap">
            <form class="ex-filtros-row" method="get" action="/lancamentos" role="search" id="ex-filtro-form">
                <?php foreach (array_diff_key($params, ['q' => 1, 'f' => 1, 'cat' => 1, 'ordem' => 1, 'periodo' => 1, 'de' => 1, 'ate' => 1, 'min' => 1, 'max' => 1, 'quem' => 1]) as $k => $v): ?>
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

                <?php $periodos = ['' => mb_convert_case(mesAno($mes), MB_CASE_TITLE), 'ano' => t('O ano de {ano}', ['ano' => substr($mes, 0, 4)]), 'tudo' => t('Todos os meses'), 'datas' => t('Entre datas')]; ?>
                <select class="ex-periodo-select" name="periodo" aria-label="<?= e(t('período')) ?>" data-ex-periodo>
                    <?php foreach ($periodos as $pv => $plbl): ?>
                        <option value="<?= e($pv) ?>"<?= $modo === $pv ? ' selected' : '' ?>><?= e($plbl) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="ex-datas<?= $modo === 'datas' ? ' is-on' : '' ?>" data-ex-datas>
                    <label class="ex-campo"><span><?= e(t('De')) ?></span><input type="date" name="de" value="<?= e((string) ($periodo['de'] ?? '')) ?>"></label>
                    <label class="ex-campo"><span><?= e(t('Até')) ?></span><input type="date" name="ate" value="<?= e((string) ($periodo['ate'] ?? '')) ?>"></label>
                </div>

                <?php
                $ordens = ['' => t('Mais recentes'), 'dia-asc' => t('Mais antigos'), 'valor-desc' => t('Maior valor'), 'valor-asc' => t('Menor valor'), 'alfa' => t('De A a Z')];
                ?>
                <select class="ex-ordem-select" name="ordem" aria-label="<?= e(t('ordenar')) ?>" data-autosubmit>
                    <?php foreach ($ordens as $ov => $olbl): ?>
                        <option value="<?= e($ov) ?>"<?= $ordem === $ov ? ' selected' : '' ?>><?= e($olbl) ?></option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="btn btn-primary ex-busca-btn" aria-label="<?= e(t('buscar')) ?>"><?= svgIcon('search', 16) ?><span class="ex-busca-txt"><?= e(t('Buscar')) ?></span></button>

                <?php $maisAberto = $valores['min'] !== '' || $valores['max'] !== '' || $quem > 0 || $erroValor !== null || $conta !== null; ?>
                <details class="ex-mais-filtros"<?= $maisAberto ? ' open' : '' ?>>
                    <summary><?= $contas !== [] ? e(t('Mais filtros: conta, valor e quem lançou')) : e(t('Mais filtros: valor e quem lançou')) ?></summary>
                    <div class="ex-mais-c">
                        <?php if ($contas !== []): ?>
                            <label class="ex-campo ex-campo-conta"><span><?= e(t('Conta')) ?></span>
                                <select name="conta">
                                    <option value=""><?= e(t('Todas as contas')) ?></option>
                                    <?php foreach ($contas as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $conta !== null && $c['id'] === $conta['id'] ? ' selected' : '' ?>><?= e(nomeConta($c)) ?></option><?php endforeach; ?>
                                </select>
                            </label>
                        <?php endif; ?>
                        <label class="ex-campo"><span><?= e(t('Valor mínimo')) ?></span><input type="text" name="min" inputmode="decimal" autocomplete="off" placeholder="<?= e(numInput(0)) ?>" value="<?= e($valores['min']) ?>"<?= $erroValor !== null ? ' aria-describedby="ex-erro-valor"' : '' ?>></label>
                        <label class="ex-campo"><span><?= e(t('Valor máximo')) ?></span><input type="text" name="max" inputmode="decimal" autocomplete="off" placeholder="<?= e(numInput(0)) ?>" value="<?= e($valores['max']) ?>"<?= $erroValor !== null ? ' aria-describedby="ex-erro-valor"' : '' ?>></label>
                        <?php if (count($usuarios) > 1): ?>
                            <label class="ex-campo ex-campo-quem"><span><?= e(t('Quem lançou')) ?></span>
                                <select name="quem">
                                    <option value=""><?= e(t('Todas as pessoas')) ?></option>
                                    <?php foreach ($usuarios as $u): ?><option value="<?= (int) $u['id'] ?>"<?= (int) $u['id'] === $quem ? ' selected' : '' ?>><?= e((string) $u['nome']) ?></option><?php endforeach; ?>
                                </select>
                            </label>
                        <?php endif; ?>
                        <?php if ($erroValor !== null): ?><p class="aviso aviso-error ex-erro" id="ex-erro-valor" role="alert"><?= e($erroValor) ?></p><?php endif; ?>
                    </div>
                </details>
            </form>

            <?php if ($filtrado): ?>
                <div class="ex-filtros-ativos">
                    <?php if ($q !== ''): ?>
                        <a class="chip is-on ex-chip-x" href="<?= e($url(['q' => ''])) ?>" aria-label="<?= e(t('tirar busca')) ?>">“<?= e($q) ?>” <span aria-hidden="true">×</span></a>
                    <?php endif; ?>
                    <?php if ($conta !== null): ?>
                        <a class="chip is-on ex-chip-x" href="<?= e($url(['conta' => ''])) ?>" aria-label="<?= e(t('tirar filtro {nome}', ['nome' => nomeConta($conta)])) ?>"><?= e(nomeConta($conta)) ?> <span aria-hidden="true">×</span></a>
                    <?php endif; ?>
                    <?php if ($categoria !== null): ?>
                        <a class="chip is-on ex-chip-x" href="<?= e($url(['cat' => ''])) ?>" aria-label="<?= e(t('tirar filtro {nome}', ['nome' => nomeCurto($categoria)])) ?>"><?= e(nomeCurto($categoria)) ?> <span aria-hidden="true">×</span></a>
                    <?php endif; ?>
                    <?php if ($valores['min'] !== '' || $valores['max'] !== ''): ?>
                        <a class="chip is-on ex-chip-x" href="<?= e($url(['min' => '', 'max' => ''])) ?>" aria-label="<?= e(t('tirar filtro de valor')) ?>"><?= e($valores['max'] === '' ? t('A partir de {valor}', ['valor' => money((float) parseMoedaBr($valores['min']))]) : ($valores['min'] === '' ? t('Até {valor}', ['valor' => money((float) parseMoedaBr($valores['max']))]) : t('De {min} a {max}', ['min' => money((float) parseMoedaBr($valores['min'])), 'max' => money((float) parseMoedaBr($valores['max']))]))) ?> <span aria-hidden="true">×</span></a>
                    <?php endif; ?>
                    <?php foreach ($usuarios as $u): if ($quem > 0 && (int) $u['id'] === $quem): ?>
                        <a class="chip is-on ex-chip-x" href="<?= e($url(['quem' => ''])) ?>" aria-label="<?= e(t('tirar filtro {nome}', ['nome' => (string) $u['nome']])) ?>"><?= e((string) $u['nome']) ?> <span aria-hidden="true">×</span></a>
                    <?php endif; endforeach; ?>
                    <a class="ex-limpar" href="<?= e($url(['f' => '', 'cat' => '', 'conta' => '', 'q' => '', 'min' => '', 'max' => '', 'quem' => '', 'periodo' => '', 'de' => '', 'ate' => ''])) ?>"><?= e(t('Limpar filtros')) ?></a>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($saldoConta)): ?>
            <p class="ex-saldo-conta num"><?= t('Saldo de {conta} em {data}: {valor}', ['conta' => e(nomeConta($saldoConta['conta'])), 'data' => e(diaMes($saldoConta['data'])), 'valor' => '<b class="' . ($saldoConta['valor'] < -0.004 ? 'neg' : '') . '">' . e(money($saldoConta['valor'])) . '</b>']) ?></p>
        <?php endif; ?>
        <?php if ($linhas === [] && $transferencias === []): ?>
            <div class="card ex-vazio">
                <?php if ($modo !== '' || $valores['min'] !== '' || $valores['max'] !== '' || $quem > 0): ?>
                    <p><?= e(t('Nada encontrado com esses filtros em {periodo}.', ['periodo' => $rotuloPeriodo])) ?></p>
                <?php elseif ($q !== ''): ?>
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
                        <a class="btn btn-ghost ex-importar" href="<?= e($urlImportar) ?>"><?= svgIcon('arrow-up', 16) ?><?= e(t('Importar extrato')) ?></a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php // o total no topo: com filtro ou fora do mês, responde "quanto foi"; a planilha leva o mesmo resultado ?>
            <div class="ex-resumo-linha">
                <?php if ($filtrado): ?>
                    <div class="ex-resumo">
                        <span class="ex-resumo-item"><span class="ex-resumo-num num"><?= e(num((int) $totais['n'], 0)) ?></span><span class="ex-resumo-label"><?= e(tn((int) $totais['n'], 'lançamento', 'lançamentos')) ?></span></span>
                        <?php if ((float) $totais['gasto'] > 0.004): ?>
                            <span class="ex-resumo-item"><span class="ex-resumo-num num neg" data-ex-total="gasto"><?= e(money((float) $totais['gasto'])) ?></span><span class="ex-resumo-label"><?= e(t('em gastos')) ?></span></span>
                        <?php endif; ?>
                        <?php if ((float) $totais['entrada'] > 0.004): ?>
                            <span class="ex-resumo-item"><span class="ex-resumo-num num pos" data-ex-total="entrada"><?= e(money((float) $totais['entrada'])) ?></span><span class="ex-resumo-label"><?= e(t('em entradas')) ?></span></span>
                        <?php endif; ?>
                        <?php if ((float) $totais['gasto'] > 0.004 && (float) $totais['entrada'] > 0.004): ?>
                            <span class="ex-resumo-item"><span class="ex-resumo-num num" data-ex-total="soma"><?= e(moneySigned((float) $totais['soma'])) ?></span><span class="ex-resumo-label"><?= e(t('total')) ?></span></span>
                        <?php endif; ?>
                        <?php if ((float) $totais['mov'] > 0.004): ?>
                            <span class="ex-resumo-item"><span class="ex-resumo-num num ex-v-mov">⇄ <?= e(num((float) $totais['mov'])) ?></span><span class="ex-resumo-label"><?= e(t('movimentações')) ?></span></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <a class="btn btn-ghost btn-sm ex-importar" href="<?= e($urlImportar) ?>"><?= svgIcon('arrow-up', 16) ?><?= e(t('Importar extrato')) ?></a>
                <a class="btn btn-ghost btn-sm ex-csv" href="<?= e($urlPlanilha) ?>"><?= svgIcon('download', 16) ?><?= e(t('Exportar planilha (CSV)')) ?></a>
            </div>

            <div class="card ex-lista">
                <?php $agrupado = $ordem === '' || $ordem === 'dia-asc'; $i = 0; foreach ($dias as $data => $rows): ?>
                    <?php if ($agrupado): ?><h2 class="ex-dia"><?= e($rotuloDia((string) $data)) ?></h2><?php endif; ?>
                    <ul class="lista">
                        <?php foreach ($rows as $r): ?>
                            <?php if (!empty($r['transf'])):
                                $de = (int) $r['de_conta_id'];
                                $para = (int) $r['para_conta_id'];
                                $sinalT = $conta === null ? '' : ($conta['id'] === $para ? '+' : MENOS);
                                $quandoT = $agrupado ? '' : ', ' . \Financeiro\Idioma::diaSemana((int) date('w', strtotime($r['data']))) . ' ' . diaMes($r['data']); ?>
                                <li><a class="lista-i ex-linha ex-transf" href="/transferencias/<?= (int) $r['id'] ?>/editar?volta=<?= e($voltaEnc) ?>">
                                    <span class="ex-transf-ic" aria-hidden="true">↔</span>
                                    <span class="lista-n"><b><?= e(trim((string) $r['descricao']) !== '' ? (string) $r['descricao'] : t('Transferência')) ?></b><small><?= e(t('De {de} para {para}', ['de' => $nomeContaDe[$de] ?? '', 'para' => $nomeContaDe[$para] ?? '']) . $quandoT) ?></small></span>
                                    <span class="lista-v"><b class="num ex-v-mov">↔ <?= e($sinalT . num((float) $r['valor'])) ?></b><small class="ex-mov"><?= e(t('Transferência')) ?></small><?php if ($r['data'] > $hoje): ?><small class="ex-agendado"><?= e(t('agendado')) ?></small><?php endif; ?></span>
                                </a></li>
                            <?php continue; endif; ?>
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
                                <span class="lista-n"><b><?= e($rot) ?></b><?php $mesmoNome = mb_strtolower($rot) === mb_strtolower(nomeCurto($cat)); $quando = $agrupado ? '' : \Financeiro\Idioma::diaSemana((int) date('w', strtotime($r['data']))) . ' ' . diaMes($r['data']); $sub = implode(', ', array_filter([$mesmoNome ? '' : nomeCurto($cat), isset($r['cartao_id'], $nomesCartao[(int) $r['cartao_id']]) ? $nomesCartao[(int) $r['cartao_id']] : '', $contas !== [] && $conta === null ? ($nomeContaDe[(int) $r['conta_id']] ?? '') : '', $quando])); ?><?php if ($sub !== ''): ?><small><?= e($sub) ?></small><?php endif; ?></span>
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
