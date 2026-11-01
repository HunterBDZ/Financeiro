<?php
/**
 * Mês — entradas, saídas (essenciais × não essenciais) e o saldo em conta.
 * Base dos números, por estado do mês: fechado = só o lançado; aberto = lançado até hoje e,
 * nas contas, o previsto do que ainda falta (max); futuro = previsto.
 * Mês aberto ou futuro: tocar numa linha abre a edição da previsão do mês (POST /mes/previsao);
 * "+ incluir conta" põe no mês uma conta que ainda não tem previsão. O título abre o seletor de meses.
 * Nenhum cálculo de motor aqui: tudo vem do MesController.
 */
use Financeiro\View;

$fmt     = static fn(float $v): string => num($v, fmod(abs($v), 1) > 0.004 ? 2 : 0);
$csrf    = csrfField();
$mesLbl  = monthLabel($mes);
$pct = static fn(float $x): string => round(max(0.0, min(1.0, $x)) * 100, 2) . '%';

// Estado da meta de uma categoria: 'na-meta' (gasto dentro) ou 'fora-meta' (passou). null = sem meta.
$metaEstado = static function (float $gasto, ?float $meta): ?array {
    if ($meta === null || $meta <= 0.004) {
        return null;
    }
    $fora = $gasto > $meta + 0.004;
    return ['cls' => $fora ? 'fora-meta' : 'na-meta', 'fora' => $fora, 'meta' => $meta, 'frac' => $gasto / $meta];
};
// Barrinha verde (dentro) / vermelha (fora) + referência da meta. '' quando não há meta.
$metaBarra = static function (float $gasto, ?float $meta) use ($pct, $metaEstado): string {
    $m = $metaEstado($gasto, $meta);
    if ($m === null) {
        return '';
    }
    $ref = $m['fora'] ? t('passou de {valor}', ['valor' => num($m['meta'], 0)]) : t('meta {valor}', ['valor' => num($m['meta'], 0)]);
    return '<span class="mes-l2 meta-barra ' . $m['cls'] . '">'
        . '<span class="bullet bullet-sm meta-bar" style="--pct:' . $pct($m['frac']) . '"><span class="bullet-fill"></span></span>'
        . '<span class="meta-ref num">' . e($ref) . '</span></span>';
};
// Sufixo de classe para pintar o valor de verde/vermelho quando há meta.
$metaValCls = static function (float $gasto, ?float $meta) use ($metaEstado): string {
    $m = $metaEstado($gasto, $meta);
    return $m === null ? '' : ' ' . $m['cls'];
};

/* ---------------------------------------------------------------- base dos valores */
// conta (Fixos/Cartão/Ano): fechado → lançado; aberto → o maior entre lançado e previsto; futuro → previsto
$valorConta = static fn(array $l): float => match ($estado) {
    'real'   => $l['pago'],
    'aberto' => max($l['pago'], $l['prev']),
    default  => $l['prev'],
};
// dia a dia e entradas: fechado/aberto → lançado; futuro → previsto
$valorDia = static fn(array $l): float => $estado === 'previsto' ? $l['prev'] : $l['real'];
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
// zeradas com meta continuam no grupo (mostram a meta); as sem meta ficam recolhidas em "Sem movimento"
$temMeta       = static fn(array $l): bool => (float) ($l['meta'] ?? 0.0) > 0.004;
$comMetaEss    = array_values(array_filter($diaZerEss, $temMeta));
$comMetaNaoEss = array_values(array_filter($diaZerNaoEss, $temMeta));
$semMov        = array_values(array_filter($diaZeradas, static fn($l) => !$temMeta($l)));

$totalEss    = array_sum(array_map($valorConta, $essContas)) + array_sum(array_map($valorDia, $diaEss));
$totalNaoEss = array_sum(array_map($valorConta, $naoEssContas)) + array_sum(array_map($valorDia, $diaNaoEss));
$totalSaidas = $totalEss + $totalNaoEss;
$balanco     = round($totalEntrou - $totalSaidas, 2);
$contaCls    = $emConta < -0.004 ? 'neg' : ($emConta < $reserva - 0.004 ? 'warn' : '');
$nivel       = $emConta < $reserva - 0.004 ? 'is-abaixo' : 'is-acima';   // campo do resumo: menta ou laranja

/* ---------------------------------------------------------------- edição da previsão (mês em PREVISAO) */
// Só meses editáveis usam $linhaDia/$linhaConta/$linhaEntrada; cada linha é um <details> cujo
// painel grava a previsão do mês (POST /mes/previsao). A meta é a previsão padrão de todo mês;
// um valor próprio do mês é "ajustado" e pode voltar à meta.
$consignado = (float) ($linha['parcela_consignado'] ?? 0.0);
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

$painel = static function (array $l, string $tipo) use ($csrf, $mes, $mesLbl, $estado, $fmt, $erroDe, $consignado, $ehUltimo, $ultLbl): void {
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
    if ($tipo === 'entrada' && $l['nome_completo'] === 'Salário' && $consignado > 0.004) {
        $info[] = t('Valor bruto. A parcela do empréstimo ({valor}) sai deste valor.', ['valor' => MENOS . $fmt($consignado)]);
    }
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
            <label class="mes-prop"><input type="checkbox" name="propagar" value="1"<?= !empty($erro['propagar']) ? ' checked' : '' ?>><span><?= e(t('Também nos meses seguintes, até {mes}', ['mes' => $ultLbl])) ?></span></label>
        <?php endif; ?>
        <?php if ($temAcoes): ?>
            <div class="mes-form-acoes">
                <?php if ($meta !== null && $l['ajuste']): ?><button type="submit" name="acao" value="meta" class="btn-link"><?= e(t('Voltar à meta ({valor})', ['valor' => $fmt((float) $meta)])) ?></button><?php endif; ?>
                <?php if ($l['prev'] > 0.004): ?><button type="submit" name="acao" value="tirar" class="btn-link-danger"><?= e(t('Tirar deste mês')) ?></button><?php endif; ?>
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
    $v    = $valorDia($l);
    $meta = $estado === 'previsto' ? null : ($l['meta'] ?? null);
    $barra = $metaBarra((float) $l['real'], $meta);
    $abreLinha($l); ?>
        <span class="mes-sum">
            <span class="mes-l1">
                <span class="mes-nome"><?= e($l['nome']) ?></span>
                <span class="mes-val num<?= $metaValCls((float) $l['real'], $meta) ?>"><?= e(num($v, 0)) ?></span>
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
            <span class="mes-sum"><span class="mes-l1"><span class="mes-nome"><?= e($l['nome_completo']) ?></span><span class="mes-val num"><?= e(num($v, fmod(abs($v), 1) > 0.004 ? 2 : 0)) ?></span></span>
            <span class="mes-l2"><span class="mes-status mes-status-previsto"><?= e(t('Vem do Cartão · ver a fatura')) ?></span></span></span>
        </a>
        <?php return;
    }
    $st = $l['status'];
    // Situação da conta na 2ª linha, em texto com um ponto de cor: pago, parcial, vence, venceu, previsto.
    $selo = '';
    $seloCls = 'previsto';
    $gastoConta = (float) ($l['pago'] ?? $l['real']);
    $metaConta  = $estado === 'previsto' ? null : ($l['meta'] ?? null);
    if ($estado === 'aberto') {
        $pagoTotal = $l['pago'] > 0.004 && $l['pago'] >= $l['prev'] - 0.004;
        if (in_array($st, ['pago', 'pago_atrasado'], true) || ($st === null && $pagoTotal)) {
            $selo = t('Pago');
            $seloCls = 'pago';
        } elseif ($st === 'pago_parcial') {
            $selo = t('Pago em parte');
            $seloCls = 'parcial';
        } elseif ($st === 'atrasado') {
            $selo = t('Venceu {data}', ['data' => diaMes((string) $l['vencimento'])]);
            $seloCls = 'venceu';
        } elseif ($st === 'pendente') {
            $selo = t('Vence {data}', ['data' => diaMes((string) $l['vencimento'])]);
            $seloCls = 'vence';
        } else {
            $selo = t('Previsto');
        }
    }
    $abreLinha($l); ?>
        <span class="mes-sum">
            <span class="mes-l1">
                <span class="mes-nome"><?= e($l['nome_completo']) ?></span>
                <span class="mes-val num<?= $metaValCls($gastoConta, $metaConta) ?>"><?= e($fmt($valorConta($l))) ?></span>
            </span>
            <?php if ($selo !== ''): ?><span class="mes-l2"><span class="mes-status mes-status-<?= e($seloCls) ?>"><?= e($selo) ?></span></span><?php endif; ?>
            <?= $metaBarra($gastoConta, $metaConta) ?><?= $origem($l) ?>
        </span>
        </summary>
        <?php $painel($l, 'conta'); ?>
    </details>
<?php };

$linhaEntrada = static function (array $l) use ($valorEnt, $fmt, $painel, $abreLinha): void {
    $abreLinha($l); ?>
        <span class="mes-sum">
            <span class="mes-l1">
                <span class="mes-nome"><?= e($l['nome']) ?></span>
                <span class="mes-val num"><?= e($fmt($valorEnt($l))) ?></span>
            </span>
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
        <summary><?= svgIcon('plus', 16) ?><?= e(t('Incluir conta em {mes}', ['mes' => $mesLbl])) ?></summary>
        <form class="mes-form" method="post" action="/mes/previsao">
            <?= $csrf ?>
            <input type="hidden" name="mes" value="<?= e($mes) ?>">
            <input type="hidden" name="ancora" value="incluir">
            <label class="mes-form-campo">
                <span><?= e(t('conta')) ?></span>
                <select name="categoria_id" required>
                    <option value=""><?= e(t('escolher…')) ?></option>
                    <?php foreach ($porGrupo as $g => $ls): ?>
                        <optgroup label="<?= e($g) ?>">
                            <?php foreach ($ls as $l): ?>
                                <option value="<?= (int) $l['id'] ?>"<?= $erro !== null && (int) $erro['cat'] === (int) $l['id'] ? ' selected' : '' ?>><?= e($l['nome_completo']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="mes-form-l1">
                <label class="mes-form-campo">
                    <span><?= e(t('previsto em {mes}', ['mes' => $mesLbl])) ?></span>
                    <input type="text" name="valor" inputmode="decimal" autocomplete="off" enterkeyhint="done" placeholder="0,00"
                           value="<?= e($erro !== null ? (string) $erro['valor'] : '') ?>"<?= $erro !== null ? ' aria-invalid="true" data-autofocus' : '' ?>>
                </label>
                <button type="submit" name="acao" value="salvar" class="btn btn-primary btn-sm mes-salvar"><?= e(t('Incluir')) ?></button>
            </div>
            <?php if ($erro !== null): ?><p class="aviso aviso-error"><?= e((string) $erro['msg']) ?></p><?php endif; ?>
            <?php if (!$ehUltimo): ?>
                <label class="mes-prop"><input type="checkbox" name="propagar" value="1"<?= !empty($erro['propagar']) ? ' checked' : '' ?>><span><?= e(t('Também nos meses seguintes, até {mes}', ['mes' => $ultLbl])) ?></span></label>
            <?php endif; ?>
        </form>
    </details>
<?php };

$linhaLeitura = static function (array $l, float $v): void { ?>
    <li class="mes-ro">
        <?= iconeCategoria((int) $l['id'], (string) ($l['nome_completo'] ?? $l['nome']), (string) ($l['grupo'] ?? ''), 40, 'mes-ic') ?>
        <span class="mes-nome"><?= e(in_array($l['grupo'] ?? '', ['Fixos', 'Cartao', 'Ano'], true) ? $l['nome_completo'] : $l['nome']) ?></span>
        <span class="num"><?= e(num($v, fmod(abs($v), 1) > 0.004 ? 2 : 0)) ?></span>
    </li>
<?php };
?>
<div class="mes">

    <header class="mes-topo">
        <?php View::partial('partials/mes_nav', ['mes' => $mes, 'meses' => $meses, 'href' => '/mes?mes=', 'hoje' => $hoje, 'anual' => true]); ?>
        <?php if ($menu['fechar'] || $menu['reabrir'] || $menu['desfazer']): ?>
            <details class="menu mes-menu">
                <summary aria-label="<?= e(t('mais ações')) ?>"><?= svgIcon('more', 20) ?></summary>
                <div class="menu-painel">
                    <?php if ($menu['fechar']): ?>
                        <a class="menu-item" href="/mes/fechar?mes=<?= e($mes) ?>"><?= svgIcon('check', 18) ?><?= e(t('Fechar {mes}', ['mes' => $mesLbl])) ?></a>
                    <?php endif; ?>
                    <?php if ($menu['reabrir']): ?>
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

    <div class="mes-grade">
    <div class="mes-col mes-esq">

        <!-- resumo: o saldo no cartão escuro (o estado vem na etiqueta) e a previsão para o fim do mês -->
        <?php
        $txtConta  = num($emConta);
        $virgula   = strrpos($txtConta, ',');
        $estadoTxt = $emConta < -0.004 ? t('No vermelho')
            : ($emConta < $reserva - 0.004 ? t('{valor} abaixo da reserva', ['valor' => num($reserva - $emConta, 0)]) : t('{valor} acima da reserva', ['valor' => num($emConta - $reserva, 0)]));
        ?>
        <section class="saldo mes-resumo <?= $emConta < -0.004 ? 'is-vermelho' : $nivel ?>" aria-labelledby="mes-res-t">
            <div class="saldo-l1"><h2 id="mes-res-t"><?= substr((string) $hoje, 0, 7) === substr($mes, 0, 7) ? e(t('Em conta hoje')) : e(t('Conta no fim do mês')) ?></h2><span class="saldo-estado num"><?= e($estadoTxt) ?></span></div>
            <p class="saldo-v num"><small>R$</small><?= e($virgula === false ? $txtConta : substr($txtConta, 0, $virgula)) ?><span><?= e($virgula === false ? '' : substr($txtConta, $virgula)) ?></span></p>
            <?php if ($estado === 'aberto' && isset($linha['fica_na_conta'])): ?>
                <p class="saldo-prev num" data-previsao-fim><?= t('Previsão para o fim de {mes}: {valor}', ['mes' => e(mesExtenso($mes)), 'valor' => '<b>' . e(num((float) $linha['fica_na_conta'], 0)) . '</b>']) ?></p>
            <?php endif; ?>
        </section>

        <!-- entradas -->
        <section class="card mes-sec" id="entradas">
            <div class="card-head">
                <h2 class="titulo"><?= $estado === 'real' ? e(t('Entradas')) : e(t('Entradas previstas')) ?></h2>
                <span class="card-acao num pos"><?= e(num($totalEntrou)) ?></span>
            </div>
            <?php if ($estado !== 'real' && $consignado > 0.004): ?><p class="mes-nota num"><?= e(t('{valor} vão para a parcela do empréstimo', ['valor' => num($consignado)])) ?></p><?php endif; ?>
            <?php if ($editavel): ?>
                <div class="mes-linhas"><?php foreach ($entradas as $l) { $linhaEntrada($l); } ?></div>
            <?php else: ?>
                <ul class="mes-ros"><?php foreach ($entradas as $l) { $linhaLeitura($l, $valorEnt($l)); } ?></ul>
            <?php endif; ?>
        </section>

    </div>
    <div class="mes-col mes-dir">

        <!-- saídas -->
        <section class="card mes-sec" id="saidas">
            <div class="card-head">
                <h2 class="titulo"><?= e(t('Saídas')) ?></h2>
                <span class="card-acao num neg"><?= e(num($totalSaidas)) ?></span>
            </div>

            <?php if ($editavel): ?>

                <details class="mes-grupo" open>
                    <summary class="mes-sub-titulo"><?= e(t('Essenciais')) ?> <span class="num"><?= e(num($totalEss, 0)) ?></span></summary>
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
                        <summary class="mes-sub-titulo"><?= e(t('Não essenciais')) ?> <span class="num"><?= e(num($totalNaoEss, 0)) ?></span></summary>
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
