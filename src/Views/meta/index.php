<?php
/**
 * Meta — orçamento do mês por categoria (aba entre extrato e mês).
 * O usuário escolhe quais categorias terão meta (seletor "adicionar categoria"); só as
 * escolhidas aparecem. Em cima: renda, total das metas e "livre pra alocar", com a barra
 * de alocação. Em cada categoria: meta editável e a barra de progresso gasto × meta.
 * Variáveis: $essenciais, $naoEssenciais (escolhidas, com 'meta' ?float e 'gasto' float),
 * $addEss, $addNao (disponíveis para adicionar), $renda, $totalMeta, $metaEss, $metaNaoEss,
 * $gastoEss, $gastoNaoEss, $mesAtual.
 */
$pct = static fn(float $x): string => round(max(0.0, min(100.0, $x)), 2) . '%';

/* -------- painel: renda = gasto (com meta + sem meta) + em conta (saldo real) -------- */
$base       = max($renda, $gastoTotal);
$temBar     = $base > 0.004 && $renda > 0.004;
$emConta    = round($renda - $gastoTotal, 2);             // saldo real; negativo = no vermelho
$noVermelho = $emConta < -0.004;
$pctMetas = $base > 0 ? $gastoComMeta / $base * 100 : 0.0;  // dinheiro já gasto nas categorias com meta
$pctGasto = $base > 0 ? $gastoSemMeta / $base * 100 : 0.0;
$pctConta = $base > 0 ? max(0.0, $emConta) / $base * 100 : 0.0;

/* -------- uma categoria escolhida -------- */
$linha = static function (array $c) use ($pct): void {
    $id    = (int) $c['id'];
    $meta  = $c['meta'];
    $gasto = (float) $c['gasto'];
    $val   = $meta !== null ? numInput((float) $meta, 0) : '';
    $grupo = (int) $c['essencial'] === 1 ? 'ess' : 'nao';

    if ($meta === null) {
        $cls = 'is-none'; $pctFill = 0.0;
        $resumo = $gasto > 0.004 ? 'Gasto ' . num($gasto, 0) : 'Defina a meta';
    } else {
        $r = $meta > 0.004 ? $gasto / (float) $meta : 0.0;
        $pctFill = min(100.0, $r * 100);
        $cls = $r > 1.0001 ? 'is-over' : ($r >= 0.8 ? 'is-near' : 'is-ok');
        $resto  = round((float) $meta - $gasto, 2);
        $resumo = num($gasto, 0) . ' de ' . num((float) $meta, 0)
                . ($resto >= -0.004 ? ', restam ' . num(max(0.0, $resto), 0) : ', passou ' . num(-$resto, 0));
    } ?>
    <div class="meta-cat <?= $cls ?>" id="c<?= $id ?>" data-grupo="<?= $grupo ?>" data-gasto="<?= e(number_format($gasto, 2, '.', '')) ?>">
        <div class="meta-cat-top">
            <label class="meta-cat-nome" for="meta-<?= $id ?>"><?= e(nomeCurto($c)) ?></label>
            <span class="aj-input meta-cat-input">
                <input id="meta-<?= $id ?>" type="text" name="meta[<?= $id ?>]" inputmode="decimal"
                       autocomplete="off" placeholder="sem meta" value="<?= e($val) ?>">
            </span>
            <button type="button" class="meta-cat-x" data-meta-rm aria-label="tirar meta de <?= e(nomeCurto($c)) ?>"><?= svgIcon('x', 16) ?></button>
        </div>
        <div class="meta-cat-prog">
            <span class="meta-pbar"><span class="meta-pbar-fill" style="--pct:<?= $pct($pctFill) ?>"></span></span>
            <span class="meta-cat-nums num" data-resumo><?= e($resumo) ?></span>
        </div>
    </div>
<?php };

$grupo = static function (string $titulo, array $cats, float $meta, float $gasto, string $tag) use ($linha): void { ?>
    <details class="card meta-grupo" data-grupo-card="<?= $tag ?>" open<?= $cats === [] ? ' hidden' : '' ?>>
        <summary class="meta-grupo-cab">
            <span class="meta-grupo-tit"><?= e($titulo) ?></span>
            <span class="meta-grupo-res num">
                <span class="meta-grupo-lbl">Meta</span>
                <span class="meta-grupo-meta" data-grupo-meta><?= e(num($meta, 0)) ?></span>
                <span class="meta-grupo-lbl">Gasto</span>
                <span class="meta-grupo-gasto" data-grupo-gasto><?= e(num($gasto, 0)) ?></span>
            </span>
        </summary>
        <div class="meta-lista" data-lista="<?= $tag ?>"><?php foreach ($cats as $c) { $linha($c); } ?></div>
    </details>
<?php };

$optAdd = static function (array $cats): void {
    foreach ($cats as $c) { ?>
        <option value="<?= (int) $c['id'] ?>"
                data-nome="<?= e(nomeCurto($c)) ?>"
                data-grupo="<?= (int) $c['essencial'] === 1 ? 'ess' : 'nao' ?>"
                data-gasto="<?= e(number_format((float) $c['gasto'], 2, '.', '')) ?>"><?= e(nomeCurto($c)) ?></option>
    <?php }
};
$temAdd = $addEss !== [] || $addNao !== [];
?>
<div class="aj meta-pg" data-renda="<?= e(number_format($renda, 2, '.', '')) ?>" data-gasto-total="<?= e(number_format($gastoTotal, 2, '.', '')) ?>">
    <div class="meta-cab">
        <h1 class="aj-titulo">Metas</h1>
        <span class="meta-mes num"><?= e(monthLabel($mesAtual)) ?></span>
    </div>

    <div class="meta-lado">
    <!-- orçamento do mês: dê função a cada real -->
    <section class="card meta-top">
        <div class="meta-top-head">
            <span class="rotulo">Renda do mês</span>
            <span class="meta-renda num"><?= e(num($renda, 0)) ?></span>
        </div>

        <?php if ($temBar): ?>
            <button type="button" class="meta-aloc" data-meta-aloc aria-expanded="false" aria-label="Como a renda foi usada. Toque para ver cada meta.">
                <span class="meta-aloc-seg seg-metas" data-seg-metas style="--w:<?= $pct($pctMetas) ?>"></span>
                <span class="meta-aloc-seg seg-gasto" data-seg-gasto style="--w:<?= $pct($pctGasto) ?>"></span>
                <span class="meta-aloc-seg seg-conta" data-seg-conta style="--w:<?= $pct($pctConta) ?>"></span>
            </button>
            <div class="meta-aloc-det" data-aloc-det hidden></div>

            <ul class="meta-contas">
                <li class="meta-conta-l">
                    <span class="meta-conta-nome"><i class="dot dot-metas"></i>Gasto em metas</span>
                    <span class="meta-conta-val num"><span data-v-metas><?= e(num($gastoComMeta, 0)) ?></span><span class="meta-conta-de">de <span data-v-metaalvo><?= e(num($totalMeta, 0)) ?></span></span></span>
                </li>
                <li class="meta-conta-l">
                    <span class="meta-conta-nome"><i class="dot dot-gasto"></i>Gasto sem meta</span>
                    <span class="meta-conta-val num" data-v-gasto><?= e(num($gastoSemMeta, 0)) ?></span>
                </li>
                <li class="meta-conta-l meta-conta-res <?= $noVermelho ? 'is-neg' : 'is-pos' ?>" data-conta-box>
                    <span class="meta-conta-nome"><i class="dot dot-conta"></i><span data-conta-lbl><?= $noVermelho ? 'No vermelho' : 'Em conta' ?></span></span>
                    <span class="meta-conta-val num" data-v-conta><?= e(num(abs($emConta), 0)) ?></span>
                </li>
            </ul>
        <?php else: ?>
            <p class="meta-dica">Lance as entradas do mês para acompanhar as metas.</p>
        <?php endif; ?>
    </section>

    <p class="meta-dica meta-previsao">A meta também é a previsão de todos os meses e entra na conta da aba Anual. Num mês diferente (férias, presente, conta extra), ajuste o valor na aba <a href="/mes">Mês</a>.</p>

    <!-- escolher quais categorias terão meta -->
    <form class="card meta-add" method="get" action="/meta" data-meta-add-form>
        <label class="meta-add-lbl" for="meta-add">Adicionar categoria</label>
        <div class="meta-add-ctl">
            <select id="meta-add" name="add" class="meta-add-sel" data-meta-add <?= $temAdd ? '' : 'disabled' ?>>
                <option value=""><?= $temAdd ? 'Escolher categoria…' : 'Todas as categorias já têm meta' ?></option>
                <?php if ($addEss !== []): ?><optgroup label="Essenciais"><?php $optAdd($addEss); ?></optgroup><?php endif; ?>
                <?php if ($addNao !== []): ?><optgroup label="Não essenciais"><?php $optAdd($addNao); ?></optgroup><?php endif; ?>
            </select>
            <button type="submit" class="btn btn-ghost meta-add-btn"<?= $temAdd ? '' : ' disabled' ?>>Adicionar</button>
        </div>
    </form>
    </div>

    <form class="meta-form" method="post" action="/meta/salvar">
        <?= csrfField() ?>
        <?php $grupo('Essenciais', $essenciais, $metaEss, $gastoEss, 'ess'); ?>
        <?php $grupo('Não essenciais', $naoEssenciais, $metaNaoEss, $gastoNaoEss, 'nao'); ?>
        <div class="meta-rodape">
            <button type="submit" class="btn btn-primary">Salvar metas</button>
        </div>
    </form>

    <template id="meta-cat-tpl">
        <div class="meta-cat is-none" data-grupo="__GRUPO__" data-gasto="__GASTO__">
            <div class="meta-cat-top">
                <label class="meta-cat-nome" for="meta-__ID__">__NOME__</label>
                <span class="aj-input meta-cat-input">
                    <input id="meta-__ID__" type="text" name="meta[__ID__]" inputmode="decimal" autocomplete="off" placeholder="sem meta" value="">
                </span>
                <button type="button" class="meta-cat-x" data-meta-rm aria-label="tirar meta de __NOME__"><?= svgIcon('x', 16) ?></button>
            </div>
            <div class="meta-cat-prog">
                <span class="meta-pbar"><span class="meta-pbar-fill" style="--pct:0%"></span></span>
                <span class="meta-cat-nums num" data-resumo>Defina a meta</span>
            </div>
        </div>
    </template>
</div>
