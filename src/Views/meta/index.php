<?php
/**
 * Metas: o que vale em cada mês, por categoria de gasto, e as contas fixas.
 * Em cima, o seletor de meses; depois o dinheiro do mês (renda, o que foi gasto e o que resta) e o
 * "adicionar categoria". Cada linha mostra o valor que vale no mês escolhido e, quando o mês tem valor
 * próprio, isso fica escrito embaixo. Tocar na linha abre o editor: valor, a partir de que mês e até quando
 * (POST /meta/editar; nas contas, POST /meta/contas/{id}, com o dia do vencimento).
 * Variáveis: $mes, $meses, $hoje, $opcoes (mês => rótulo), $essenciais, $naoEssenciais, $contas (linhas do
 * MetaController::linha), $addEss, $addNao, $elegiveis, $erro, $renda, $totalMeta, $gastoTotal, $gastoComMeta,
 * $gastoSemMeta, $metaEss, $metaNaoEss, $gastoEss, $gastoNaoEss, $totalContas, $porCompra (o cartão conta pela data
 * da compra) e $semCategoria (?array{valor, compras}: as compras no cartão sem categoria do mês, com o atalho para categorizar).
 */
use Financeiro\View;

$pct  = static fn(float $x): string => round(max(0.0, min(100.0, $x)), 2) . '%';
$csrf = csrfField();
$mesLbl = monthLabel($mes);
$valorCampo = static fn(float $v): string => numInput($v, fmod(abs($v), 1) > 0.004 ? 2 : 0);
$erroDe = static fn(int $cat): ?array => $erro !== null && (int) ($erro['cat'] ?? 0) === $cat ? $erro : null;

/* -------- painel: renda = gasto (com meta + sem meta) + em conta (saldo real) -------- */
$base       = max($renda, $gastoTotal);
$temBar     = $base > 0.004 && $renda > 0.004;
$emConta    = round($renda - $gastoTotal, 2);             // saldo real; negativo = no vermelho
$noVermelho = $emConta < -0.004;
$pctMetas = $base > 0 ? $gastoComMeta / $base * 100 : 0.0;  // dinheiro já gasto no que tem meta
$pctGasto = $base > 0 ? $gastoSemMeta / $base * 100 : 0.0;
$pctConta = $base > 0 ? max(0.0, $emConta) / $base * 100 : 0.0;

/* -------- o editor: valor, a partir de, até (e, nas contas, o dia do vencimento) -------- */
$editor = static function (array $l, string $acao, bool $conta) use ($csrf, $mes, $opcoes, $valorCampo, $erroDe): void {
    $erro  = $erroDe($l['id']);
    $valor = $l['valor'] > 0.004 || $l['proprio'] ? $valorCampo($l['valor']) : '';
    $desde = $erro !== null && isset($opcoes[$erro['desde'] ?? '']) ? (string) $erro['desde'] : $mes;
    $ate   = $erro !== null ? (string) ($erro['ate'] ?? '') : '';
    $id    = 'ed-' . ($conta ? 'conta-' : '') . $l['id']; ?>
    <form class="mes-form meta-ed" method="post" action="<?= e($acao) ?>">
        <?= $csrf ?>
        <input type="hidden" name="ver" value="<?= e($mes) ?>">
        <?php if ($conta): ?>
            <input type="hidden" name="valor_era" value="<?= e($valor) ?>">
            <input type="hidden" name="desde_era" value="<?= e($mes) ?>">
        <?php else: ?>
            <input type="hidden" name="categoria_id" value="<?= (int) $l['id'] ?>">
        <?php endif; ?>
        <div class="mes-form-l1">
            <label class="mes-form-campo">
                <span><?= e(t('Valor por mês')) ?></span>
                <input type="text" name="valor" inputmode="decimal" autocomplete="off" enterkeyhint="done" placeholder="<?= e(t('sem meta')) ?>"
                       value="<?= e($erro !== null ? (string) $erro['valor'] : $valor) ?>" data-mes-valor<?= $erro !== null ? ' aria-invalid="true" data-autofocus' : '' ?>>
            </label>
            <button type="submit" name="acao" value="salvar" class="btn btn-primary btn-sm mes-salvar"><?= e(t('Salvar')) ?></button>
        </div>
        <div class="meta-ed-per">
            <label class="mes-form-campo">
                <span><?= e(t('A partir de')) ?></span>
                <select name="desde" id="<?= e($id) ?>-desde" data-meta-desde>
                    <?php foreach ($opcoes as $k => $rotulo): ?>
                        <option value="<?= e($k) ?>"<?= $k === $desde ? ' selected' : '' ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="mes-form-campo">
                <span><?= e(t('Até')) ?></span>
                <select name="ate" data-meta-ate>
                    <option value=""<?= $ate === '' ? ' selected' : '' ?>><?= e(t('Sem fim')) ?></option>
                    <option value="so"<?= $ate === 'so' ? ' selected' : '' ?>><?= e(t('Só este mês')) ?></option>
                    <?php foreach ($opcoes as $k => $rotulo): ?>
                        <option value="<?= e($k) ?>"<?= $k === $ate ? ' selected' : '' ?><?= $k <= $desde ? ' disabled' : '' ?>><?= e(t('até {mes}', ['mes' => monthLabel($k)])) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if ($conta): ?>
                <label class="mes-form-campo meta-ed-dia">
                    <span><?= e(t('Vence no dia')) ?></span>
                    <input type="text" name="dia" inputmode="numeric" pattern="[0-9]*" maxlength="2" autocomplete="off" required
                           value="<?= e($erro !== null && isset($erro['dia']) ? (string) $erro['dia'] : (string) $l['dia']) ?>">
                </label>
            <?php endif; ?>
        </div>
        <?php if ($erro !== null): ?><p class="aviso aviso-error"><?= e((string) $erro['msg']) ?></p><?php endif; ?>
        <?php if (!$conta && ($l['padrao'] !== null || $l['valor'] > 0.004)): ?>
            <div class="mes-form-acoes">
                <button type="submit" name="acao" value="remover" class="btn-link mes-tirar"><?= e(t('Sem meta')) ?></button>
            </div>
        <?php endif; ?>
    </form>
<?php };

/* -------- a 2ª linha: quanto já foi, e o valor próprio do mês -------- */
$proprio = static fn(array $l): string => $l['proprio']
    ? '<span class="meta-proprio">' . e(t('só em {mes} · meta {valor}', ['mes' => mesExtenso($mes), 'valor' => num((float) $l['padrao'])])) . '</span>'
    : '';

/* -------- uma categoria -------- */
$linha = static function (array $l) use ($pct, $editor, $proprio): void {
    $valor = $l['valor'];
    $gasto = $l['gasto'];
    if ($valor <= 0.004) {
        $cls = 'is-none'; $pctFill = 0.0;
        $resumo = $gasto > 0.004 ? t('Gasto {valor}', ['valor' => num($gasto)]) : t('Defina a meta');
    } else {
        $r = $gasto / $valor;
        $pctFill = min(100.0, $r * 100);
        $cls = $r > 1.0001 ? 'is-over' : ($r >= 0.8 ? 'is-near' : 'is-ok');
        $resto  = round($valor - $gasto, 2);
        $resumo = $resto >= -0.004
            ? t('{gasto} de {meta}, restam {resto}', ['gasto' => num($gasto), 'meta' => num($valor), 'resto' => num(max(0.0, $resto))])
            : t('{gasto} de {meta}, passou {resto}', ['gasto' => num($gasto), 'meta' => num($valor), 'resto' => num(-$resto)]);
    } ?>
    <details class="mes-linha mes-ed meta-cat <?= $cls ?>" id="c<?= (int) $l['id'] ?>" data-valor="<?= e(number_format($valor, 2, '.', '')) ?>" data-gasto="<?= e(number_format($gasto, 2, '.', '')) ?>" data-nome="<?= e($l['nome']) ?>"<?= !empty($l['aberta']) ? ' open' : '' ?>>
        <summary>
            <?= iconeCategoria((int) $l['id'], (string) $l['nome'], $l['grupo'], 40, 'mes-ic') ?>
            <span class="mes-sum">
                <span class="mes-l1">
                    <span class="mes-nome"><?= e($l['nome']) ?></span>
                    <span class="mes-val num"><?= $valor > 0.004 || $l['proprio'] ? e(num($valor)) : '<span class="meta-sem">' . e(t('sem meta')) . '</span>' ?></span>
                </span>
                <span class="meta-pbar"><span class="meta-pbar-fill" style="--pct:<?= $pct($pctFill) ?>"></span></span>
                <span class="mes-l2 meta-l2"><span class="meta-cat-nums num"><?= e($resumo) ?></span><?= $proprio($l) ?></span>
            </span>
        </summary>
        <?php $editor($l, '/meta/editar', false); ?>
    </details>
<?php };

$grupo = static function (string $titulo, array $cats, float $meta, float $gasto, string $tag) use ($linha): void {
    if ($cats === []) {
        return;
    } ?>
    <details class="card meta-grupo" data-grupo-card="<?= $tag ?>" open>
        <summary class="meta-grupo-cab">
            <span class="meta-grupo-tit"><?= e($titulo) ?></span>
            <span class="meta-grupo-res num">
                <span class="meta-grupo-lbl"><?= e(t('Meta')) ?></span>
                <span class="meta-grupo-meta"><?= e(num($meta)) ?></span>
                <span class="meta-grupo-lbl"><?= e(t('Gasto')) ?></span>
                <span class="meta-grupo-gasto"><?= e(num($gasto)) ?></span>
            </span>
        </summary>
        <div class="mes-linhas meta-lista"><?php foreach ($cats as $c) { $linha($c); } ?></div>
    </details>
<?php };

/* -------- uma conta fixa -------- */
$linhaConta = static function (array $l) use ($editor, $proprio, $csrf, $mes, $hoje): void {
    if ($l['do_cartao']) { ?>
        <a class="mes-linha mes-ro mes-do-cartao meta-cat" id="conta-<?= (int) $l['id'] ?>" href="/cartao#fatura" data-valor="<?= e(number_format($l['valor'], 2, '.', '')) ?>" data-gasto="<?= e(number_format($l['gasto'], 2, '.', '')) ?>" data-nome="<?= e($l['nome']) ?>">
            <?= iconeCategoria((int) $l['id'], (string) $l['nome'], $l['grupo'], 40, 'mes-ic') ?>
            <span class="mes-sum"><span class="mes-l1"><span class="mes-nome"><?= e($l['nome']) ?></span><span class="mes-val num"><?= e(num($l['valor'])) ?></span></span>
            <span class="mes-l2"><span class="mes-status mes-status-previsto"><?= e(t('Vem do Cartão · ver a fatura')) ?></span></span></span>
        </a>
        <?php return;
    }
    $desativarDe = max($mes, substr($hoje, 0, 7) . '-01'); ?>
    <details class="mes-linha mes-ed meta-cat meta-conta" id="conta-<?= (int) $l['id'] ?>" data-valor="<?= e(number_format($l['valor'], 2, '.', '')) ?>" data-gasto="<?= e(number_format($l['gasto'], 2, '.', '')) ?>" data-nome="<?= e($l['nome']) ?>"<?= !empty($l['aberta']) ? ' open' : '' ?>>
        <summary>
            <?= iconeCategoria((int) $l['id'], (string) $l['nome'], $l['grupo'], 40, 'mes-ic') ?>
            <span class="mes-sum">
                <span class="mes-l1">
                    <span class="mes-nome"><?= e($l['nome']) ?></span>
                    <span class="mes-val num"><?= e(num($l['valor'])) ?></span>
                </span>
                <span class="mes-l2 meta-l2"><span class="num"><?= e(t('dia {dia}', ['dia' => $l['dia']])) ?></span><?= $proprio($l) ?></span>
            </span>
        </summary>
        <?php $editor($l, '/meta/contas/' . $l['conta'], true); ?>
        <form class="meta-ed-fora" method="post" action="/meta/contas/<?= (int) $l['conta'] ?>/desativar">
            <?= $csrf ?>
            <input type="hidden" name="ver" value="<?= e($mes) ?>">
            <input type="hidden" name="desde" value="<?= e($desativarDe) ?>">
            <button type="submit" class="btn-link mes-tirar"><?= e(t('Desativar a partir de {mes}', ['mes' => monthLabel($desativarDe)])) ?></button>
        </form>
    </details>
<?php };

$optAdd = static function (array $cats): void {
    foreach ($cats as $c) { ?>
        <option value="<?= (int) $c['id'] ?>"><?= e($c['nome']) ?></option>
    <?php }
};
$temAdd = $addEss !== [] || $addNao !== [];
$erroNova = $erroDe(-1);
foreach ($contas as &$c) {
    $c['aberta'] = $erroDe($c['id']) !== null;
}
unset($c);
?>
<div class="aj meta-pg" data-renda="<?= e(number_format($renda, 2, '.', '')) ?>" data-gasto-total="<?= e(number_format($gastoTotal, 2, '.', '')) ?>">
    <div class="meta-cab">
        <h1 class="aj-titulo"><?= e(t('Metas')) ?> <?= dica('metas', t('Metas')) ?></h1>
        <?php View::partial('partials/mes_nav', ['mes' => $mes, 'meses' => $meses, 'href' => '/meta?mes=', 'hoje' => $hoje, 'semTitulo' => true]); ?>
    </div>

    <div class="meta-lado">
    <!-- o dinheiro do mês: dê função a cada real -->
    <section class="card meta-top">
        <div class="meta-top-head">
            <span class="rotulo"><?= substr($hoje, 0, 7) === substr($mes, 0, 7) ? e(t('Para usar neste mês')) : e(t('Para usar em {mes}', ['mes' => mesExtenso($mes)])) ?> <small class="meta-renda-nota"><?= e(t('o que entrou e o que já estava na conta')) ?></small></span>
            <span class="meta-renda num"><?= e(num($renda)) ?></span>
        </div>

        <?php if ($temBar): ?>
            <button type="button" class="meta-aloc" data-meta-aloc aria-expanded="false" aria-label="<?= e(t('Como esse valor foi usado. Toque para ver cada meta.')) ?>">
                <span class="meta-aloc-seg seg-metas" data-seg-metas style="--w:<?= $pct($pctMetas) ?>"></span>
                <span class="meta-aloc-seg seg-gasto" style="--w:<?= $pct($pctGasto) ?>"></span>
                <span class="meta-aloc-seg seg-conta" style="--w:<?= $pct($pctConta) ?>"></span>
            </button>
            <div class="meta-aloc-det" data-aloc-det hidden></div>

            <ul class="meta-contas">
                <li class="meta-conta-l">
                    <span class="meta-conta-nome"><i class="dot dot-metas"></i><?= e(t('Gasto em metas')) ?></span>
                    <span class="meta-conta-val num"><span><?= e(num($gastoComMeta)) ?></span><span class="meta-conta-de"><?= t('de {valor}', ['valor' => '<span>' . e(num($totalMeta)) . '</span>']) ?></span></span>
                </li>
                <li class="meta-conta-l">
                    <span class="meta-conta-nome"><i class="dot dot-gasto"></i><?= e(t('Gasto sem meta')) ?></span>
                    <span class="meta-conta-val num"><?= e(num($gastoSemMeta)) ?></span>
                </li>
                <li class="meta-conta-l meta-conta-res <?= $noVermelho ? 'is-neg' : 'is-pos' ?>">
                    <span class="meta-conta-nome"><i class="dot dot-conta"></i><span><?= $noVermelho ? e(t('No vermelho')) : e(t('Resta para usar')) ?></span></span>
                    <span class="meta-conta-val num"><?= e(num(abs($emConta))) ?></span>
                </li>
            </ul>
        <?php else: ?>
            <p class="meta-dica"><?= e(t('Lance as entradas do mês para acompanhar as metas.')) ?></p>
        <?php endif; ?>
    </section>

    <?php if ($porCompra): ?>
        <p class="meta-dica"><?= e(t('O cartão conta pela data da compra.')) ?></p>
    <?php endif; ?>
    <?php if ($semCategoria !== null): ?>
        <p class="meta-dica meta-previsao num"><?= t('Compras no cartão sem categoria: {valor}. {link}', ['valor' => e(num($semCategoria['valor'])), 'link' => '<a href="/cartao/compras/' . (int) $semCategoria['compras'][0] . '/editar">' . e(t('Categorizar')) . '</a>']) ?></p>
    <?php endif; ?>

    <!-- escolher quais categorias terão meta: a escolhida volta aberta, para digitar o valor -->
    <form class="card meta-add" method="get" action="/meta">
        <input type="hidden" name="mes" value="<?= e($mes) ?>">
        <label class="meta-add-lbl" for="meta-add"><?= e(t('Adicionar categoria')) ?></label>
        <div class="meta-add-ctl">
            <select id="meta-add" name="add" class="meta-add-sel" data-autosubmit<?= $temAdd ? '' : ' disabled' ?>>
                <option value=""><?= $temAdd ? e(t('Escolher categoria…')) : e(t('Todas as categorias já têm meta')) ?></option>
                <?php if ($addEss !== []): ?><optgroup label="<?= e(t('Essenciais')) ?>"><?php $optAdd($addEss); ?></optgroup><?php endif; ?>
                <?php if ($addNao !== []): ?><optgroup label="<?= e(t('Não essenciais')) ?>"><?php $optAdd($addNao); ?></optgroup><?php endif; ?>
            </select>
            <button type="submit" class="btn btn-ghost meta-add-btn"<?= $temAdd ? '' : ' disabled' ?>><?= e(t('Adicionar')) ?></button>
        </div>
    </form>
    </div>

    <div class="meta-listas">
        <?php $grupo(t('Essenciais'), $essenciais, $metaEss, $gastoEss, 'ess'); ?>
        <?php $grupo(t('Não essenciais'), $naoEssenciais, $metaNaoEss, $gastoNaoEss, 'nao'); ?>

        <!-- contas fixas: valor, vencimento e até quando valem -->
        <section class="card meta-contas-fixas" id="contas-fixas">
            <div class="card-head">
                <h2 class="titulo"><?= e(t('Contas fixas')) ?></h2>
                <span class="card-acao num"><?= e(num($totalContas)) ?></span>
            </div>
            <?php if ($contas !== []): ?>
                <div class="mes-linhas meta-lista"><?php foreach ($contas as $c) { $linhaConta($c); } ?></div>
            <?php endif; ?>
            <details class="mes-incluir" id="conta-nova"<?= $erroNova !== null || $contas === [] ? ' open' : '' ?>>
                <summary><?= svgIcon('plus', 16) ?><?= e(t('Adicionar conta fixa')) ?></summary>
                <form class="mes-form" method="post" action="/meta/contas">
                    <?= $csrf ?>
                    <input type="hidden" name="ver" value="<?= e($mes) ?>">
                    <label class="mes-form-campo meta-ed-nome">
                        <span><?= e(t('Nome')) ?></span>
                        <input type="text" name="nome" maxlength="100" autocomplete="off" required list="contas-elegiveis"
                               value="<?= e((string) ($erroNova['nome'] ?? '')) ?>"<?= $erroNova !== null ? ' aria-invalid="true" data-autofocus' : '' ?>>
                    </label>
                    <?php if ($elegiveis !== []): ?>
                        <datalist id="contas-elegiveis"><?php foreach ($elegiveis as $n): ?><option value="<?= e($n) ?>"></option><?php endforeach; ?></datalist>
                    <?php endif; ?>
                    <div class="meta-ed-per">
                        <label class="mes-form-campo">
                            <span><?= e(t('Valor por mês')) ?></span>
                            <input type="text" name="valor" inputmode="decimal" autocomplete="off" value="<?= e((string) ($erroNova['valor'] ?? '')) ?>">
                        </label>
                        <label class="mes-form-campo meta-ed-dia">
                            <span><?= e(t('Vence no dia')) ?></span>
                            <input type="text" name="dia" inputmode="numeric" pattern="[0-9]*" maxlength="2" autocomplete="off" required value="<?= e((string) ($erroNova['dia'] ?? '')) ?>">
                        </label>
                        <label class="mes-form-campo">
                            <span><?= e(t('A partir de')) ?></span>
                            <select name="desde">
                                <?php foreach ($opcoes as $k => $rotulo): ?>
                                    <option value="<?= e($k) ?>"<?= $k === $mes ? ' selected' : '' ?>><?= e($rotulo) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <?php if ($erroNova !== null): ?><p class="aviso aviso-error"><?= e((string) $erroNova['msg']) ?></p><?php endif; ?>
                    <button type="submit" class="btn btn-primary meta-nova-btn"><?= e(t('Adicionar')) ?></button>
                </form>
            </details>
        </section>
    </div>
</div>
