<?php
/**
 * Mês — entradas, saídas (essenciais × não essenciais) e o saldo em conta.
 * Base dos números, por estado do mês, a mesma do plano (a "sobra prevista" e a previsão do fim do mês):
 * fechado = só o lançado; aberto = em cada categoria, o maior entre o lançado e o previsto (a barra mostra
 * quanto já foi); futuro = previsto. Assim as linhas somam o resumo de cima.
 * Mês aberto ou futuro: tocar numa linha abre a edição da previsão do mês (POST /mes/previsao);
 * "+ incluir conta" põe no mês uma conta que ainda não tem previsão. O título abre o seletor de meses.
 * Nenhum cálculo de motor aqui: tudo vem do MesController.
 */
use Financeiro\Services\Sobra;
use Financeiro\View;

$fmt     = static fn(float $v): string => num($v);
$csrf    = csrfField();
$mesLbl  = monthLabel($mes);
$pct = static fn(float $x): string => round(max(0.0, min(1.0, $x)) * 100, 2) . '%';

// Estado da meta de uma categoria: 'na-meta' (gasto dentro) ou 'fora-meta' (passou). null = sem meta.
$metaEstado = static function (float $gasto, ?float $meta): ?array {
    // previsão zero (o mês tem valor próprio de zero): sem barra até haver gasto; com gasto, passou
    if ($meta === null || ($meta <= 0.004 && $gasto <= 0.004)) {
        return null;
    }
    $fora = $gasto > $meta + 0.004;
    return ['cls' => $fora ? 'fora-meta' : 'na-meta', 'fora' => $fora, 'meta' => $meta, 'frac' => $meta > 0.004 ? $gasto / $meta : 1.0];
};
// A situação diante da meta, em palavras e cor: abaixo (até 90%), na meta (90% a 100%), acima. '' sem meta.
$metaBarra = static function (float $gasto, ?float $meta) use ($metaEstado): string {
    $m = $metaEstado($gasto, $meta);
    if ($m === null) {
        return '';
    }
    [$txt, $cls] = $m['fora'] ? [t('Acima da meta'), 'venceu'] : ($m['frac'] >= 0.9 ? [t('Na meta'), 'vence'] : [t('Abaixo da meta'), 'pago']);
    return '<span class="mes-l2"><span class="mes-status mes-status-' . $cls . '">' . e($txt) . '</span>'
        . '<span class="mes-status-data"> · ' . e(t('meta {valor}', ['valor' => num((float) $m['meta'])])) . '</span></span>';
};
// Sufixo de classe para pintar o valor de verde/vermelho quando há meta.
$metaValCls = static function (float $gasto, ?float $meta) use ($metaEstado): string {
    $m = $metaEstado($gasto, $meta);
    return $m === null ? '' : ' ' . $m['cls'];
};

/* ---------------------------------------------------------------- base dos valores */
// conta (Fixos/Cartão/Ano): fechado → lançado; aberto → o maior entre lançado e previsto; futuro → previsto.
// Vem pronto do MesController, pela regra do motor: a Fatura do cartão paga num mês anterior não conta de novo.
$valorConta = static fn(array $l): float => $l['valor'];
// dia a dia: no mês aberto, o que o plano conta para o mês inteiro; o que já foi gasto fica na barra
$valorDia = static fn(array $l): float => match ($estado) {
    'real'   => $l['real'],
    'aberto' => max($l['real'], $l['prev']),
    default  => $l['prev'],
};
$valorEnt = static fn(array $l): float => match ($estado) {
    'real'   => $l['real'],
    'aberto' => max($l['real'], $l['prev']),
    default  => $l['prev'],
};
$baseTxt = match ($estado) {
    'real'   => 'só o que foi lançado',
    'aberto' => 'lançado até hoje; nas contas e entradas, o previsto do que ainda falta',
    default  => 'previsto',
};

$totalEntrou = array_sum(array_map($valorEnt, $entradas));

$sortDesc = static function (array &$arr, callable $v): void {
    usort($arr, static fn($a, $b) => $v($b) <=> $v($a));
};
$contas = array_merge($contasAbertas, $contasPagas);
$essContas    = array_values(array_filter($contas, static fn($l) => $l['essencial']));
$naoEssContas = array_values(array_filter($contas, static fn($l) => !$l['essencial']));
$diaEss       = array_values(array_filter($dia, static fn($l) => $l['essencial']));
$diaNaoEss    = array_values(array_filter($dia, static fn($l) => !$l['essencial']));
$sortDesc($essContas, $valorConta);
$sortDesc($naoEssContas, $valorConta);
$sortDesc($diaEss, $valorDia);
$sortDesc($diaNaoEss, $valorDia);
$diaZerEss    = array_values(array_filter($diaZeradas, static fn($l) => $l['essencial']));
$diaZerNaoEss = array_values(array_filter($diaZeradas, static fn($l) => !$l['essencial']));
$temMeta       = static fn(array $l): bool => (float) ($l['meta'] ?? 0.0) > 0.004;
// o Mês mostra só o que tem meta ou previsão no mês, ou que teve gasto: sem nada disso, a categoria fica fora (e em "Incluir")
$comMetaEss    = [];
$comMetaNaoEss = [];
$semMov        = [];

$totalEss    = array_sum(array_map($valorConta, $essContas)) + array_sum(array_map($valorDia, $diaEss));
$totalNaoEss = array_sum(array_map($valorConta, $naoEssContas)) + array_sum(array_map($valorDia, $diaNaoEss));
$totalSaidas = $totalEss + $totalNaoEss;
$contaCls    = $emConta < -0.004 ? 'neg' : ($emConta < $reserva - 0.004 ? 'warn' : '');
$nivel       = $emConta < $reserva - 0.004 ? 'is-abaixo' : 'is-acima';   // campo do resumo: menta ou laranja

/* ---------------------------------------------------------------- edição da previsão (mês em PREVISAO) */
// Só meses editáveis usam $linhaDia/$linhaConta/$linhaEntrada; cada linha é um <details> cujo
// painel grava a previsão do mês (POST /mes/previsao). A meta é a previsão padrão de todo mês;
// um valor próprio do mês é "ajustado" e pode voltar à meta.
$parcelaDescontada = (float) ($linha['parcela_descontada'] ?? 0.0);
$ehUltimo   = $mes === $ultimo;
$ultLbl     = monthLabel($ultimo);
$erroDe     = static fn(array $l): ?array => $erroLinha !== null && (int) ($erroLinha['cat'] ?? 0) === (int) $l['id'] ? $erroLinha : null;

// 2ª linha nos meses futuros: de onde vem o previsto (só categorias com meta)
$origem = static function (array $l) use ($estado, $fmt): string {
    if ($estado !== 'previsto' || $l['meta_padrao'] === null) {
        return '';
    }
    return $l['ajuste']
        ? '<span class="mes-l2 mes-origem is-ajuste">' . e(t('ajustado neste mês, a meta é {valor}', ['valor' => $fmt((float) $l['meta_padrao'])])) . '</span>'
        : '<span class="mes-l2 mes-origem">' . e(t('segue a meta')) . '</span>';
};

// veio menos que o previsto (mês aberto): a pessoa diz se esse foi o valor final do mês ou se ainda falta
$aMenos = static function (array $l, string $tipo) use ($csrf, $mes): void {
    $am = $l['a_menos'] ?? null;
    if ($am === null) {
        return;
    } ?>
    <form class="mes-final" method="post" action="/mes/previsao">
        <?= $csrf ?>
        <input type="hidden" name="mes" value="<?= e($mes) ?>">
        <input type="hidden" name="categoria_id" value="<?= (int) $l['id'] ?>">
        <p class="mes-final-txt"><?= t('Veio {veio} de {previsto} previstos', ['veio' => '<b class="num">' . e(money($am['veio'])) . '</b>', 'previsto' => '<span class="num">' . e(money($am['previsto'])) . '</span>']) ?></p>
        <?php if ($am['parcela']): ?><p class="mes-final-nota"><?= e(t('Os dois já sem a parcela do empréstimo.')) ?></p><?php endif; ?>
        <div class="mes-final-acoes">
            <button type="submit" name="acao" value="final" class="btn btn-sm"><?= e(t('Foi o valor final')) ?></button>
            <button type="submit" name="acao" value="falta" class="btn btn-sm"><?= $tipo === 'entrada' ? e(t('Ainda falta receber')) : e(t('Ainda falta pagar')) ?></button>
        </div>
        <p class="mes-final-nota"><?= e(t('Valor final: a previsão deste mês passa a ser o que veio. Os outros meses não mudam.')) ?></p>
    </form>
<?php };

$painel = static function (array $l, string $tipo) use ($csrf, $mes, $mesLbl, $estado, $fmt, $erroDe, $parcelaDescontada, $ehUltimo, $ultLbl, $aMenos): void {
    $aMenos($l, $tipo);
    $erro = $erroDe($l);
    $meta = $l['meta_padrao'];
    $info = [];
    if ($meta !== null) {
        $info[] = $l['ajuste'] ? t('Valor só deste mês. A meta é {valor}.', ['valor' => $fmt((float) $meta)]) : t('Segue a meta, que vale para todo mês sem ajuste.');
    }
    if ($estado === 'aberto') {
        $ja = $tipo === 'conta' ? (float) ($l['pago'] ?? $l['real']) : (float) $l['real'];
        if ($ja > 0.004) {
            $info[] = $tipo === 'entrada' ? t('Recebido até hoje: {valor}.', ['valor' => $fmt($ja)]) : t('Lançado até hoje: {valor}.', ['valor' => $fmt($ja)]);
        }
    }
    $info[] = $tipo === 'entrada' ? t('Muda só a previsão. O que você já recebeu continua igual.') : t('Muda só a previsão. O que você já gastou continua igual.');
    $temAcoes = ($meta !== null && $l['ajuste']) || $l['prev'] > 0.004; ?>
    <form class="mes-form" method="post" action="/mes/previsao">
        <?= $csrf ?>
        <input type="hidden" name="mes" value="<?= e($mes) ?>">
        <input type="hidden" name="categoria_id" value="<?= (int) $l['id'] ?>">
        <div class="mes-form-l1">
            <label class="mes-form-campo">
                <span><?= e(t('previsto em {mes}', ['mes' => $mesLbl])) ?></span>
                <input type="text" name="valor" inputmode="decimal" autocomplete="off" enterkeyhint="done"
                       value="<?= e($erro !== null ? (string) $erro['valor'] : numInput((float) $l['prev'])) ?>" data-mes-valor<?= $erro !== null ? ' aria-invalid="true" data-autofocus' : '' ?>>
            </label>
            <button type="submit" name="acao" value="salvar" class="btn btn-primary btn-sm mes-salvar"><?= e(t('Salvar')) ?></button>
        </div>
        <?php if ($erro !== null): ?><p class="aviso aviso-error"><?= e((string) $erro['msg']) ?></p><?php endif; ?>
        <?php if ($info !== []): ?><p class="mes-form-info"><?= e(implode(' ', $info)) ?></p><?php endif; ?>
        <?php if (!$ehUltimo): ?>
            <label class="mes-prop"><input type="checkbox" name="propagar" value="1"<?= !empty($erro['propagar']) ? ' checked' : '' ?>><span><?= e(t('Também nos meses seguintes')) ?></span></label>
        <?php endif; ?>
        <?php if ($temAcoes): ?>
            <div class="mes-form-acoes">
                <?php if ($meta !== null && $l['ajuste']): ?><button type="submit" name="acao" value="meta" class="btn-link"><?= e(t('Voltar à meta ({valor})', ['valor' => $fmt((float) $meta)])) ?></button><?php endif; ?>
                <?php if ($l['prev'] > 0.004): ?><button type="submit" name="acao" value="tirar" class="btn-link mes-tirar"><?= e(t('Tirar deste mês')) ?></button><?php endif; ?>
            </div>
        <?php endif; ?>
    </form>
<?php };

// abre/fecha a linha editável (o resumo da linha vai no <summary>)
$abreLinha = static function (array $l) use ($erroDe): void { ?>
    <details class="mes-linha mes-ed" id="cat-<?= (int) $l['id'] ?>"<?= $erroDe($l) !== null ? ' open' : '' ?>>
        <summary>
            <?= iconeCategoria((int) $l['id'], (string) ($l['nome_completo'] ?? $l['nome']), (string) ($l['grupo'] ?? ''), 40, 'mes-ic') ?>
<?php };

/* ---------------------------------------------------------------- linhas */
$linhaDia = static function (array $l) use ($estado, $valorDia, $metaBarra, $metaValCls, $origem, $painel, $abreLinha): void {
    $v    = $estado === 'aberto' ? (float) $l['real'] : $valorDia($l);   // aberto: o que já foi gasto (o plano conta a meta)
    // o mês com valor próprio compara com ele, não com a meta de todo mês ("0 de 800", não "0 de 1.000")
    $meta = $estado === 'previsto' ? null : (!empty($l['ajuste']) ? (float) $l['prev'] : ($l['meta'] ?? null));
    // mês aberto sem meta, mas com previsão: a barra mostra quanto da previsão já foi gasto
    if ($meta === null && $estado === 'aberto' && $l['prev'] > 0.004) {
        $meta = $l['prev'];
    }
    $barra = $metaBarra((float) $l['real'], $meta);
    $abreLinha($l); ?>
        <span class="mes-sum">
            <span class="mes-l1">
                <span class="mes-nome"><?= e($l['nome']) ?></span>
                <span class="mes-val num<?= $metaValCls((float) $l['real'], $meta) ?>"><?= e(num($v)) ?></span>
            </span>
            <?= $barra ?><?= $origem($l) ?>
        </span>
        </summary>
        <?php $painel($l, 'dia'); ?>
    </details>
<?php };

$linhaConta = static function (array $l) use ($estado, $valorConta, $fmt, $metaBarra, $metaValCls, $origem, $painel, $abreLinha, $mes): void {
    if (!empty($l['do_cartao'])) {          // vem das compras do Cartão: só leitura, leva à fatura
        $v = $valorConta($l); ?>
        <a class="mes-linha mes-ro mes-do-cartao" href="/cartao?fatura=<?= e($mes) ?>#fatura">
            <?= iconeCategoria((int) $l['id'], (string) ($l['nome_completo'] ?? $l['nome']), (string) ($l['grupo'] ?? ''), 40, 'mes-ic') ?>
            <span class="mes-sum"><span class="mes-l1"><span class="mes-nome"><?= e(nomeCategoria($l['nome_completo'])) ?></span><span class="mes-val num"><?= e(num($v)) ?></span></span>
            <span class="mes-l2"><span class="mes-status mes-status-previsto"><?= e(t('Vem do Cartão · ver a fatura')) ?></span></span></span>
        </a>
        <?php return;
    }
    $st = $l['status'];
    // Situação da conta na 2ª linha, em texto com um ponto de cor: pago, veio menos, vence, venceu, previsto.
    $selo = '';
    $seloCls = 'previsto';
    $seloData = '';
    if ($estado === 'aberto') {
        $pagoTotal = $l['pago'] > 0.004 && $l['pago'] >= $l['prev'] - 0.004;
        $am = $l['a_menos'] ?? null;
        if ($am !== null || $st === 'pago_parcial') {
            // veio menos que o previsto: no painel, "foi o valor final" ou "ainda falta pagar"
            $selo = t('Veio {veio} de {previsto} previstos', ['veio' => $fmt((float) ($am['veio'] ?? $l['pago'])), 'previsto' => $fmt((float) ($am['previsto'] ?? $l['prev']))]);
            $seloCls = 'parcial';
        } elseif (in_array($st, ['pago', 'pago_atrasado'], true) || ($st === null && $pagoTotal)) {
            // a fatura paga no mês anterior ao vencimento diz quando: por isso ela não pesa neste mês
            $selo = ($l['data_pagamento'] ?? null) !== null && $l['data_pagamento'] < $mes
                ? t('Pago em {data}', ['data' => diaMes((string) $l['data_pagamento'])]) : t('Pago');
            $seloCls = 'pago';
        } else {
            $selo = t('Não pago');
            $seloCls = 'venceu';
            if (in_array($st, ['atrasado', 'pendente'], true)) {
                $seloData = $st === 'atrasado' ? t('venceu {data}', ['data' => diaMes((string) $l['vencimento'])]) : t('vence {data}', ['data' => diaMes((string) $l['vencimento'])]);
            }
        }
    }
    $abreLinha($l); ?>
        <span class="mes-sum">
            <span class="mes-l1">
                <span class="mes-nome"><?= e(nomeCategoria($l['nome_completo'])) ?></span>
                <span class="mes-val num"><?= e($fmt($valorConta($l))) ?></span>
            </span>
            <?php if ($selo !== ''): ?><span class="mes-l2"><span class="mes-status mes-status-<?= e($seloCls) ?>"><?= e($selo) ?></span><?php if ($seloData !== ''): ?><span class="mes-status-data"> · <?= e($seloData) ?></span><?php endif; ?></span><?php endif; ?>
            <?= $origem($l) ?>
        </span>
        </summary>
        <?php $painel($l, 'conta'); ?>
    </details>
<?php };

$linhaEntrada = static function (array $l) use ($valorEnt, $fmt, $painel, $abreLinha): void {
    $am = $l['a_menos'] ?? null;
    $abreLinha($l); ?>
        <span class="mes-sum">
            <span class="mes-l1">
                <span class="mes-nome"><?= e($l['nome']) ?></span>
                <span class="mes-val num"><?= e($fmt($valorEnt($l))) ?></span>
            </span>
            <?php if ($am !== null): ?><span class="mes-l2"><span class="mes-status mes-status-parcial"><?= e(t('Veio {veio} de {previsto} previstos', ['veio' => $fmt($am['veio']), 'previsto' => $fmt($am['previsto'])])) ?></span></span><?php endif; ?>
        </span>
        </summary>
        <?php $painel($l, 'entrada'); ?>
    </details>
<?php };

// "+ incluir": contas (Fixos, Cartão, Pontuais) sem previsão neste mês
$incluirConta = static function () use ($incluir, $csrf, $mes, $mesLbl, $erroLinha, $ehUltimo, $ultLbl): void {
    if ($incluir === []) {
        return;
    }
    $ids  = array_map(static fn($l) => (int) $l['id'], $incluir);
    $erro = $erroLinha !== null && (($erroLinha['ancora'] ?? null) === 'incluir' || in_array((int) ($erroLinha['cat'] ?? 0), $ids, true))
        ? $erroLinha : null;
    $porGrupo = [];
    foreach ($incluir as $l) {
        $porGrupo[grupoLabel($l['grupo'])][] = $l;
    } ?>
    <details class="mes-incluir" id="incluir"<?= $erro !== null ? ' open' : '' ?>>
        <summary><?= svgIcon('plus', 16) ?><?= e(t('Incluir categoria em {mes}', ['mes' => $mesLbl])) ?></summary>
        <form class="mes-form" method="post" action="/mes/previsao">
            <?= $csrf ?>
            <input type="hidden" name="mes" value="<?= e($mes) ?>">
            <input type="hidden" name="ancora" value="incluir">
            <label class="mes-form-campo">
                <span><?= e(t('categoria')) ?></span>
                <select name="categoria_id" required>
                    <option value=""><?= e(t('escolher…')) ?></option>
                    <?php foreach ($porGrupo as $g => $ls): ?>
                        <optgroup label="<?= e($g) ?>">
                            <?php foreach ($ls as $l): ?>
                                <option value="<?= (int) $l['id'] ?>"<?= $erro !== null && (int) $erro['cat'] === (int) $l['id'] ? ' selected' : '' ?>><?= e(nomeCategoria($l['nome_completo'])) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="mes-form-l1">
                <label class="mes-form-campo">
                    <span><?= e(t('previsto em {mes}', ['mes' => $mesLbl])) ?></span>
                    <input type="text" name="valor" inputmode="decimal" autocomplete="off" enterkeyhint="done" placeholder="<?= e(numInput(0)) ?>"
                           value="<?= e($erro !== null ? (string) $erro['valor'] : '') ?>"<?= $erro !== null ? ' aria-invalid="true" data-autofocus' : '' ?>>
                </label>
                <button type="submit" name="acao" value="salvar" class="btn btn-primary btn-sm mes-salvar"><?= e(t('Incluir')) ?></button>
            </div>
            <?php if ($erro !== null): ?><p class="aviso aviso-error"><?= e((string) $erro['msg']) ?></p><?php endif; ?>
            <?php if (!$ehUltimo): ?>
                <label class="mes-prop"><input type="checkbox" name="propagar" value="1"<?= !empty($erro['propagar']) ? ' checked' : '' ?>><span><?= e(t('Também nos meses seguintes')) ?></span></label>
            <?php endif; ?>
        </form>
    </details>
<?php };

$linhaLeitura = static function (array $l, float $v): void { ?>
    <li class="mes-ro">
        <?= iconeCategoria((int) $l['id'], (string) ($l['nome_completo'] ?? $l['nome']), (string) ($l['grupo'] ?? ''), 40, 'mes-ic') ?>
        <span class="mes-nome"><?= e(in_array($l['grupo'] ?? '', ['Fixos', 'Cartao', 'Ano'], true) ? nomeCategoria($l['nome_completo']) : $l['nome']) ?></span>
        <span class="num"><?= e(num($v)) ?></span>
    </li>
<?php };
?>
<div class="mes">

    <header class="mes-topo">
        <?php View::partial('partials/mes_nav', ['mes' => $mes, 'meses' => $meses, 'href' => '/mes?mes=', 'hoje' => $hoje, 'anual' => true]); ?>
        <?php if ($menu['reabrir'] || $menu['desfazer']): ?>
            <details class="menu mes-menu">
                <summary aria-label="<?= e(t('mais ações')) ?>"><?= svgIcon('more', 20) ?></summary>
                <div class="menu-painel">
                    <?php if ($menu['reabrir']): ?>
                        <a class="menu-item" href="/relatorio?tipo=mensal&amp;mes=<?= e($mes) ?>"><?= svgIcon('relatorio', 18) ?><?= e(t('Relatório de {mes}', ['mes' => $mesLbl])) ?></a>
                        <form method="post" action="/mes/reabrir">
                            <?= $csrf ?><input type="hidden" name="mes" value="<?= e($mes) ?>">
                            <button type="submit" class="menu-item"><?= svgIcon('chevron', 18) ?><?= e(t('Reabrir {mes}', ['mes' => $mesLbl])) ?></button>
                        </form>
                    <?php endif; ?>
                    <?php if ($menu['desfazer']): ?>
                        <form method="post" action="/mes/desfazer">
                            <?= $csrf ?>
                            <input type="hidden" name="lote" value="<?= e($menu['desfazer']['lote']) ?>">
                            <input type="hidden" name="mes" value="<?= e($mes) ?>">
                            <button type="submit" class="menu-item mes-desfazer"><?= svgIcon('x', 18) ?><span class="mes-desfazer-txt"><?= e(t('Desfazer a última alteração')) ?><small class="num"><?= e(diaMesHora($menu['desfazer']['criado_em'])) ?></small></span></button>
                        </form>
                    <?php endif; ?>
                </div>
            </details>
        <?php endif; ?>
    </header>

    <?php if ($menu['fechar'] && substr($mes, 0, 7) < substr((string) $hoje, 0, 7)): ?>
        <p class="mes-fechar">
            <span><?= e(t('{mes} já terminou.', ['mes' => mb_convert_case(mesExtenso($mes), MB_CASE_TITLE)])) ?></span>
            <a class="btn btn-primary" href="/mes/fechar?mes=<?= e($mes) ?>"><?= e(t('Fechar {mes}', ['mes' => $mesLbl])) ?></a>
        </p>
    <?php endif; ?>

    <div class="mes-grade">
    <div class="mes-col mes-esq">

        <!-- resumo: o saldo no cartão escuro (o estado vem na etiqueta) e a previsão para o fim do mês -->
        <?php
        $txtConta  = num($emConta);
        $virgula   = strrpos($txtConta, sepDecimal());
        $estadoTxt = $emConta < -0.004 ? t('No vermelho')
            : ($reserva <= 0.004 ? '' : ($emConta < $reserva - 0.004 ? t('{valor} abaixo da reserva', ['valor' => num($reserva - $emConta)]) : t('{valor} acima da reserva', ['valor' => num($emConta - $reserva)])));
        $sobraV = (float) $sobra['sobra'];
        ?>
        <section class="saldo mes-resumo <?= $emConta < -0.004 ? 'is-vermelho' : $nivel ?>" aria-labelledby="mes-res-t">
            <div class="saldo-l1"><h2 id="mes-res-t"><?= substr((string) $hoje, 0, 7) === substr($mes, 0, 7) ? e(t('Em conta hoje')) : e(t('Conta no fim do mês')) ?></h2><?php if ($estadoTxt !== ''): ?><span class="saldo-estado num"><?= e($estadoTxt) ?></span><?php endif; ?></div>
            <p class="saldo-v num"><small><?= e(moedaSimbolo()) ?></small><?= e($virgula === false ? $txtConta : substr($txtConta, 0, $virgula)) ?><span><?= e($virgula === false ? '' : substr($txtConta, $virgula)) ?></span></p>
            <?php if ($estado === 'aberto' && isset($linha['fica_na_conta'])): ?>
                <p class="saldo-prev num" data-previsao-fim><?= t('Previsão para o fim de {mes}: {valor}', ['mes' => e(mesExtenso($mes)), 'valor' => '<b>' . e(num((float) $linha['fica_na_conta'])) . '</b>']) ?></p>
            <?php endif; ?>
        </section>

        <!-- o resumo das listas abaixo, antes delas. O saldo do mês anterior entra nas Entradas: Entradas − Saídas (± empréstimo
             e poupança) é o que fica na conta no fim do mês, o mesmo número da previsão do cartão escuro -->
        <?php
        $inicioMes = isset($linha['sobrou_mes_anterior'], $linha['fica_na_conta']) ? (float) $linha['sobrou_mes_anterior'] : null;
        // o adiantamento que o plano sugere sozinho também sai da conta no fim do mês: vai junto com empréstimo e poupança
        $movMes = (float) ($sobra['mov'] ?? 0.0) - ($inicioMes !== null ? (float) ($linha['amortizacao_auto'] ?? 0.0) : 0.0);
        ?>
        <div class="card mes-conta-rap-card">
            <dl class="mes-conta-rap num">
                <div><dt><?= e(t('Entradas')) ?></dt><dd class="pos"><?= e(num((float) $sobra['renda'] + ($inicioMes ?? 0.0))) ?></dd></div>
                <div><dt><?= e(t('Saídas')) ?></dt><dd class="neg"><?= e(num((float) $sobra['saidas'])) ?></dd></div>
                <?php if (abs($movMes) >= 0.005): ?>
                    <div><dt><?= e(t('Empréstimo e poupança')) ?></dt><dd class="<?= $movMes < 0 ? 'neg' : 'pos' ?>"><?= e(num($movMes)) ?></dd></div>
                <?php endif; ?>
                <?php if ($inicioMes !== null): $fimMes = (float) $linha['fica_na_conta']; ?>
                    <div><dt><?= e(t('Fim do mês')) ?></dt><dd class="<?= $fimMes < -0.004 ? 'neg' : 'pos' ?>" data-fim-mes><?= e(num($fimMes)) ?></dd></div>
                <?php else: ?>
                    <div><dt><?= e(Sobra::rotulo((string) $sobra['base'])) ?> <?= dica('numeros', Sobra::rotulo((string) $sobra['base'])) ?></dt><dd class="<?= $sobraV < -0.004 ? 'neg' : 'pos' ?>" data-sobra="<?= e((string) $sobra['base']) ?>"><?= e(num($sobraV)) ?></dd></div>
                <?php endif; ?>
            </dl>
        </div>

        <?php if ($menu['fechar'] && substr($mes, 0, 7) === substr((string) $hoje, 0, 7)): ?>
            <a class="mes-fechar-link" href="/mes/fechar?mes=<?= e($mes) ?>"><?= e(t('Fechar este mês')) ?></a>
        <?php endif; ?>

        <!-- entradas -->
        <section class="card mes-sec" id="entradas">
            <div class="card-head">
                <h2 class="titulo"><?= $estado === 'real' ? e(t('Entradas')) : e(t('Entradas previstas')) ?></h2>
                <span class="card-acao num pos"><?= e(num($totalEntrou + ($inicioMes ?? 0.0))) ?></span>
            </div>
            <?php if ($editavel): ?>
                <div class="mes-linhas"><?php foreach ($entradas as $l) { $linhaEntrada($l); } ?></div>
            <?php else: ?>
                <ul class="mes-ros"><?php foreach ($entradas as $l) { $linhaLeitura($l, $valorEnt($l)); } ?></ul>
            <?php endif; ?>
            <?php if ($inicioMes !== null): // não é categoria: vem do plano, o que ficou na conta no fim do mês anterior ?>
                <ul class="mes-ros mes-saldo-ant">
                    <li class="mes-ro" data-saldo-anterior>
                        <?= iconeCategoria(0, 'Guardar na poupança', 'Poupanca', 40, 'mes-ic') ?>
                        <span class="mes-nome"><?= e(t('Saldo do mês anterior')) ?></span>
                        <span class="num<?= $inicioMes < -0.004 ? ' neg' : '' ?>"><?= e(num($inicioMes)) ?></span>
                    </li>
                </ul>
            <?php endif; ?>
        </section>

    </div>
    <div class="mes-col mes-dir">

        <!-- saídas -->
        <section class="card mes-sec" id="saidas">
            <div class="card-head">
                <h2 class="titulo"><?= $estado === 'real' ? e(t('Saídas')) : e(t('Saídas previstas')) ?></h2>
                <span class="card-acao num neg"><?= e(num($totalSaidas)) ?></span>
            </div>

            <?php if ($editavel): ?>

                <details class="mes-grupo" open>
                    <summary class="mes-sub-titulo"><?= e(t('Essenciais')) ?> <span class="num"><?= e(num($totalEss)) ?></span></summary>
                    <div class="mes-linhas">
                        <?php foreach ($essContas as $l) { $linhaConta($l); } ?>
                        <?php foreach ($diaEss as $l) { $linhaDia($l); } ?>
                        <?php foreach ($comMetaEss as $l) { $linhaDia($l); } ?>
                    </div>
                </details>

                <?php
                $naoEss = array_merge($naoEssContas, $diaNaoEss);
                usort($naoEss, static fn($a, $b) => ($b['grupo'] === 'Dia a dia' ? $valorDia($b) : $valorConta($b)) <=> ($a['grupo'] === 'Dia a dia' ? $valorDia($a) : $valorConta($a)));
                $naoEss = array_merge($naoEss, $comMetaNaoEss);
                if ($naoEss !== []): ?>
                    <details class="mes-grupo" open>
                        <summary class="mes-sub-titulo"><?= e(t('Não essenciais')) ?> <span class="num"><?= e(num($totalNaoEss)) ?></span></summary>
                        <div class="mes-linhas">
                            <?php foreach ($naoEss as $l) { $l['grupo'] === 'Dia a dia' ? $linhaDia($l) : $linhaConta($l); } ?>
                        </div>
                    </details>
                <?php endif; ?>

                <?php if ($semMov !== []): ?>
                    <details class="mes-grupo mes-sem-mov"<?= array_filter($semMov, $erroDe) !== [] ? ' open' : '' ?>>
                        <summary class="mes-sub-titulo"><?= e(t('Sem movimento')) ?> <span class="num">(<?= count($semMov) ?>)</span></summary>
                        <div class="mes-linhas">
                            <?php foreach ($semMov as $l) { $linhaDia($l); } ?>
                        </div>
                    </details>
                <?php endif; ?>

                <?php $incluirConta(); ?>

            <?php else: ?>
                <ul class="mes-ros">
                    <?php foreach (array_merge($essContas, $naoEssContas) as $l) { $linhaLeitura($l, $valorConta($l)); } ?>
                    <?php foreach (array_merge($diaEss, $diaNaoEss) as $l) { $linhaLeitura($l, $valorDia($l)); } ?>
                </ul>
            <?php endif; ?>
        </section>

    </div>
    </div>
</div>
