<?php
/**
 * Mês — entradas, saídas (essenciais × não essenciais) e o saldo em conta.
 * Base dos números, por estado do mês: fechado = só o lançado; aberto = lançado até hoje e,
 * nas contas, o previsto do que ainda falta (max); futuro = previsto.
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
    $ref = ($m['fora'] ? 'passou de ' : 'meta ') . num($m['meta'], 0);
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

$totalEss    = array_sum(array_map($valorConta, $essContas)) + array_sum(array_map($valorDia, $diaEss));
$totalNaoEss = array_sum(array_map($valorConta, $naoEssContas)) + array_sum(array_map($valorDia, $diaNaoEss));
$totalSaidas = $totalEss + $totalNaoEss;
$balanco     = round($totalEntrou - $totalSaidas, 2);
$contaCls    = $emConta < -0.004 ? 'neg' : ($emConta < $reserva - 0.004 ? 'warn' : '');

/* ---------------------------------------------------------------- linhas */
$linhaDia = static function (array $l) use ($estado, $valorDia, $metaBarra, $metaValCls): void {
    $v    = $valorDia($l);
    $meta = $estado === 'previsto' ? null : ($l['meta'] ?? null);
    $barra = $metaBarra((float) $l['real'], $meta); ?>
    <div class="mes-linha">
        <span class="mes-sum">
            <span class="mes-l1">
                <span class="mes-tag mes-tag-var">variável</span>
                <span class="mes-nome"><?= e($l['nome']) ?></span>
                <span class="mes-val num<?= $metaValCls((float) $l['real'], $meta) ?>"><?= e(num($v, 0)) ?></span>
            </span>
            <?= $barra ?>
        </span>
    </div>
<?php };

$linhaConta = static function (array $l) use ($estado, $valorConta, $fmt, $metaBarra, $metaValCls): void {
    $st = $l['status'];
    $tipo = $l['grupo'] === 'Fixos' ? 'fixo' : 'variável';
    // Selo de status à direita do lançamento: verde = pago, amarelo = previsto (ainda não pago).
    $selo = '';
    $seloCls = 'previsto';
    $gastoConta = (float) ($l['pago'] ?? $l['real']);
    $metaConta  = $estado === 'previsto' ? null : ($l['meta'] ?? null);
    if ($estado === 'aberto') {
        $pagoTotal = $l['pago'] > 0.004 && $l['pago'] >= $l['prev'] - 0.004;
        if (in_array($st, ['pago', 'pago_atrasado'], true) || ($st === null && $pagoTotal)) {
            $selo = 'pago';
            $seloCls = 'pago';
        } elseif ($st === 'pago_parcial') {
            $selo = 'parcial';
        } elseif ($st === 'atrasado') {
            $selo = 'venceu ' . diaMes((string) $l['vencimento']);
        } elseif ($st === 'pendente') {
            $selo = 'vence ' . diaMes((string) $l['vencimento']);
        } else {
            $selo = 'previsto';
        }
    } ?>
    <div class="mes-linha">
        <span class="mes-sum">
            <span class="mes-l1">
                <span class="mes-tag mes-tag-<?= $tipo === 'fixo' ? 'fixo' : 'var' ?>"><?= e($tipo) ?></span>
                <span class="mes-nome"><?= e($l['nome_completo']) ?></span>
                <span class="mes-val num<?= $metaValCls($gastoConta, $metaConta) ?>"><?= e($fmt($valorConta($l))) ?></span>
                <?php if ($selo !== ''): ?><span class="mes-status mes-status-<?= $seloCls ?>"><?= e($selo) ?></span><?php endif; ?>
            </span>
            <?= $metaBarra($gastoConta, $metaConta) ?>
        </span>
    </div>
<?php };

$linhaEntrada = static function (array $l) use ($valorEnt, $fmt): void { ?>
    <div class="mes-linha">
        <span class="mes-sum">
            <span class="mes-l1">
                <span class="mes-nome"><?= e($l['nome']) ?></span>
                <span class="mes-val num"><?= e($fmt($valorEnt($l))) ?></span>
            </span>
        </span>
    </div>
<?php };

$linhaLeitura = static function (array $l, float $v): void { ?>
    <li class="mes-ro">
        <span class="mes-nome"><?= e(in_array($l['grupo'] ?? '', ['Fixos', 'Cartao', 'Ano'], true) ? $l['nome_completo'] : $l['nome']) ?></span>
        <span class="num"><?= e(num($v, fmod(abs($v), 1) > 0.004 ? 2 : 0)) ?></span>
    </li>
<?php };
?>
<div class="mes">

    <header class="mes-topo">
        <?php View::partial('partials/mes_nav', ['mes' => $mes, 'meses' => $meses, 'href' => '/mes?mes=', 'hoje' => $hoje]); ?>
        <?php if ($menu['fechar'] || $menu['reabrir'] || $menu['desfazer']): ?>
            <details class="menu mes-menu">
                <summary aria-label="mais ações"><?= svgIcon('more', 20) ?></summary>
                <div class="menu-painel">
                    <?php if ($menu['fechar']): ?>
                        <a class="menu-item" href="/mes/fechar?mes=<?= e($mes) ?>"><?= svgIcon('check', 18) ?>fechar <?= e($mesLbl) ?></a>
                    <?php endif; ?>
                    <?php if ($menu['reabrir']): ?>
                        <form method="post" action="/mes/reabrir">
                            <?= $csrf ?><input type="hidden" name="mes" value="<?= e($mes) ?>">
                            <button type="submit" class="menu-item"><?= svgIcon('chevron', 18) ?>reabrir</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($menu['desfazer']): ?>
                        <form method="post" action="/mes/desfazer">
                            <?= $csrf ?>
                            <input type="hidden" name="lote" value="<?= e($menu['desfazer']['lote']) ?>">
                            <input type="hidden" name="mes" value="<?= e($mes) ?>">
                            <button type="submit" class="menu-item mes-desfazer"><?= svgIcon('x', 18) ?><span class="mes-desfazer-txt">desfazer última alteração<small class="num"><?= e(diaMesHora($menu['desfazer']['criado_em'])) ?></small></span></button>
                        </form>
                    <?php endif; ?>
                </div>
            </details>
        <?php endif; ?>
    </header>

    <!-- resumo -->
    <div class="mes-resumo">
        <div class="mes-resumo-item">
            <span class="rotulo">entradas</span>
            <span class="mes-resumo-val pos num"><?= e(num($totalEntrou)) ?></span>
        </div>
        <div class="mes-resumo-item">
            <span class="rotulo">saídas</span>
            <span class="mes-resumo-val num"><?= e(num($totalSaidas)) ?></span>
        </div>
        <div class="mes-resumo-item">
            <span class="rotulo"><?= $projetado ? 'conta no fim do mês' : 'em conta hoje' ?></span>
            <span class="mes-resumo-val num <?= e($contaCls) ?>"><?= e(num($emConta)) ?></span>
        </div>
    </div>

    <div class="mes-grade">
    <div class="mes-col mes-esq">

        <!-- entradas -->
        <section class="card mes-sec" id="entradas">
            <div class="card-head">
                <h2 class="titulo">entradas</h2>
                <span class="card-acao num pos"><?= e(num($totalEntrou)) ?></span>
            </div>
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
                <h2 class="titulo">saídas</h2>
                <span class="card-acao num"><?= e(num($totalSaidas)) ?></span>
            </div>

            <?php if ($editavel): ?>

                <details class="mes-grupo" open>
                    <summary class="mes-sub-titulo">essenciais <span class="num"><?= e(num($totalEss, 0)) ?></span></summary>
                    <div class="mes-linhas">
                        <?php foreach ($essContas as $l) { $linhaConta($l); } ?>
                        <?php foreach ($diaEss as $l) { $linhaDia($l); } ?>
                        <?php foreach ($diaZerEss as $l) { $linhaDia($l); } ?>
                    </div>
                </details>

                <?php
                $naoEss = array_merge($naoEssContas, $diaNaoEss);
                usort($naoEss, static fn($a, $b) => ($b['grupo'] === 'Dia a dia' ? $valorDia($b) : $valorConta($b)) <=> ($a['grupo'] === 'Dia a dia' ? $valorDia($a) : $valorConta($a)));
                $naoEss = array_merge($naoEss, $diaZerNaoEss);
                if ($naoEss !== []): ?>
                    <details class="mes-grupo" open>
                        <summary class="mes-sub-titulo">não essenciais <span class="num"><?= e(num($totalNaoEss, 0)) ?></span></summary>
                        <div class="mes-linhas">
                            <?php foreach ($naoEss as $l) { $l['grupo'] === 'Dia a dia' ? $linhaDia($l) : $linhaConta($l); } ?>
                        </div>
                    </details>
                <?php endif; ?>

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
