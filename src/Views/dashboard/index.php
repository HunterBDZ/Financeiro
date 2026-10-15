<?php
/**
 * Início — só o que aconteceu de verdade (lançamentos): anel duplo (externo: saídas essenciais ×
 * não essenciais; interno: entradas salário × resto), composição, saldo em conta, balanço do mês,
 * gasto médio por dia, última conferência do banco, maiores gastos e últimos lançamentos.
 * Variáveis: $hoje, $mesRef, $mesCal, $estadoRef, $reserva, $conta, $conf, $painel, $ultimos, $alertas.
 */

$p        = $painel;
$mesLbl   = monthLabel($mesRef);
$previsto = $p['base'] === 'previsto';
$f        = static fn(float $x): string => rtrim(rtrim(number_format($x, 2, '.', ''), '0'), '.');
$max      = max($p['entradas'], $p['saidas'], 0.01);
$pc       = static fn(float $v): float => round($v / $max * 100, 2);
$share    = static fn(float $v, float $tot): int => $tot > 0.004 ? (int) round($v / $tot * 100) : 0;
$semana   = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];

// segmento do anel em % do perímetro (pathLength=100), com folga entre segmentos
$gap = 1.4;
$seg = static function (float $ini, float $len) use ($gap): ?array {
    if ($len < 0.05) {
        return null;
    }
    return $len <= $gap * 2 ? [$ini, $len] : [$ini + $gap / 2, $len - $gap];
};
$aneis = [
    'sai' => ['r' => 102, 'sw' => 20, 'segs' => [
        ['ess',    $seg(0, $pc($p['ess']))],
        ['naoess', $seg($pc($p['ess']), $pc($p['naoess']))],
    ]],
    'ent' => ['r' => 76, 'sw' => 18, 'segs' => [
        ['sal',    $seg(0, $pc($p['sal']))],
        ['outras', $seg($pc($p['sal']), $pc($p['outras']))],
    ]],
];
$rotulo = ['sal' => 'soldo', 'outras' => 'outras entradas', 'ess' => 'essenciais', 'naoess' => 'não essenciais'];
$baseLbl = $previsto ? 'previsto' : 'lançado até hoje';
$cor    = ['sal' => 'var(--g-sal)', 'outras' => 'var(--g-outras)', 'ess' => 'var(--g-ess)', 'naoess' => 'var(--g-naoess)'];
$valor  = ['sal' => $p['sal'], 'outras' => $p['outras'], 'ess' => $p['ess'], 'naoess' => $p['naoess']];

$balCls = $p['balanco'] < -0.004 ? 'neg' : ($p['balanco'] > 0.004 ? 'pos' : '');
$balLbl = $p['balanco'] < -0.004 ? 'déficit' : 'sobra';
$contaCls = $conta < -0.004 ? 'neg' : ($conta < $reserva ? 'warn' : 'pos');

$prog = null;
if ($mesRef === $mesCal) {
    $dia  = (int) substr($hoje, 8, 2);
    $dias = diasNoMes($mesRef);
    $prog = ['dia' => $dia, 'dias' => $dias, 'pct' => (int) round($dia / $dias * 100)];
}
$topMax = $p['top'] !== [] ? max(array_column($p['top'], 'valor')) : 0.0;
?>
<div class="dash dash-focado">

    <header class="dash-head">
        <div class="dash-head-l">
            <span class="dash-head-mes"><?= e($mesLbl) ?></span>
            <span class="dash-head-est estado-<?= e($estadoRef) ?>"><?= e(estadoLabel($estadoRef)) ?></span>
        </div>
        <?php if ($prog !== null): ?>
            <div class="dash-prog" aria-label="dia <?= $prog['dia'] ?> de <?= $prog['dias'] ?>">
                <span class="num">dia <?= $prog['dia'] ?> de <?= $prog['dias'] ?></span>
                <span class="dash-prog-bar"><i style="--p:<?= $prog['pct'] ?>%"></i></span>
                <span class="num dash-prog-pct"><?= $prog['pct'] ?>%</span>
            </div>
        <?php endif; ?>
    </header>

    <?php if ($alertas !== []): ?>
        <div class="dash-alertas">
            <?php foreach ($alertas as $a): ?>
                <a class="dash-alerta warn" href="/mes/fechar?mes=<?= e(rawurlencode($a['mes'])) ?>"><?= svgIcon('alert', 14) ?>fechar <?= e(monthLabel($a['mes'])) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- anel duplo + composição -->
    <section class="card dash-hero" aria-label="entradas e saídas de <?= e($mesLbl) ?>">
        <div class="dash-anel-wrap">
            <div class="dash-anel" data-dash-anel>
                <svg class="dash-ring" viewBox="0 0 240 240" role="img"
                     aria-label="<?= e($mesLbl) ?>: entradas <?= e(num($p['entradas'])) ?>, saídas <?= e(num($p['saidas'])) ?>">
                    <?php foreach ($aneis as $a): ?>
                        <circle class="dash-track" cx="120" cy="120" r="<?= $a['r'] ?>" stroke-width="<?= $a['sw'] ?>"/>
                    <?php endforeach; ?>
                    <?php $i = 0; foreach ($aneis as $a): foreach ($a['segs'] as [$k, $s]): if ($s === null) { continue; } [$ini, $len] = $s; ?>
                        <circle class="dash-seg dash-seg-<?= e($k) ?>" data-seg="<?= e($k) ?>"
                                cx="120" cy="120" r="<?= $a['r'] ?>" stroke-width="<?= $a['sw'] ?>" pathLength="100"
                                stroke-dasharray="<?= e($f($len)) ?> <?= e($f(100 - $len)) ?>" stroke-dashoffset="<?= e($f(-$ini)) ?>"
                                transform="rotate(-90 120 120)" style="--i:<?= $i++ ?>"/>
                    <?php endforeach; endforeach; ?>
                </svg>
                <div class="dash-rc" data-dash-centro>
                    <span class="dash-rc-mes">em conta</span>
                    <span class="dash-rc-val num <?= e($contaCls) ?>" data-dash-val data-countup><?= e(num($conta)) ?></span>
                    <span class="dash-rc-lbl num" data-dash-lbl>hoje · reserva <?= e(num($reserva, 0)) ?></span>
                </div>
            </div>
            <div class="dash-anel-leg num">
                <span><i class="dash-key" style="--c:var(--g-ess)"></i><i class="dash-key" style="--c:var(--g-naoess)"></i>externo · saídas</span>
                <span><i class="dash-key" style="--c:var(--g-sal)"></i><i class="dash-key" style="--c:var(--g-outras)"></i>interno · entradas</span>
                <span class="dash-dica">toque numa linha ao lado para destacar</span>
            </div>
        </div>

        <div class="dash-bd">
            <div class="dash-bd-grp">
                <div class="dash-bd-head">
                    <span class="dash-bd-t">entradas <small><?= e($baseLbl) ?></small></span>
                    <span class="dash-bd-tot num pos" data-countup><?= e(num($p['entradas'])) ?></span>
                </div>
                <?php foreach (['sal', 'outras'] as $k): ?>
                    <div class="dash-bd-row" data-seg="<?= $k ?>" data-nome="<?= e($rotulo[$k]) ?>" data-valor="<?= e(num($valor[$k])) ?>" data-cor="<?= e($cor[$k]) ?>" tabindex="0" role="button" aria-label="destacar <?= e($rotulo[$k]) ?> no anel">
                        <i class="dash-dot" style="--c:<?= e($cor[$k]) ?>"></i>
                        <span class="dash-bd-nome"><?= e($rotulo[$k]) ?></span>
                        <span class="dash-bd-pct num"><?= $share($valor[$k], $p['entradas']) ?>%</span>
                        <span class="dash-bd-val num"><?= e(num($valor[$k])) ?></span>
                        <span class="dash-bd-bar"><i style="--w:<?= $share($valor[$k], $p['entradas']) ?>%;--c:<?= e($cor[$k]) ?>"></i></span>
                    </div>
                <?php endforeach; ?>
                <?php if (count($p['outrasItens']) > 1): ?>
                    <div class="dash-bd-sub num">
                        <?php foreach ($p['outrasItens'] as $it): ?>
                            <span><?= e($it['nome']) ?> <b><?= e(num($it['valor'])) ?></b></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="dash-bd-grp">
                <div class="dash-bd-head">
                    <span class="dash-bd-t">saídas <small><?= e($baseLbl) ?></small></span>
                    <span class="dash-bd-tot num" data-countup><?= e(num($p['saidas'])) ?></span>
                </div>
                <?php foreach (['ess', 'naoess'] as $k): ?>
                    <div class="dash-bd-row" data-seg="<?= $k ?>" data-nome="<?= e($rotulo[$k]) ?>" data-valor="<?= e(num($valor[$k])) ?>" data-cor="<?= e($cor[$k]) ?>" tabindex="0" role="button" aria-label="destacar <?= e($rotulo[$k]) ?> no anel">
                        <i class="dash-dot" style="--c:<?= e($cor[$k]) ?>"></i>
                        <span class="dash-bd-nome"><?= e($rotulo[$k]) ?></span>
                        <span class="dash-bd-pct num"><?= $share($valor[$k], $p['saidas']) ?>%</span>
                        <span class="dash-bd-val num"><?= e(num($valor[$k])) ?></span>
                        <span class="dash-bd-bar"><i style="--w:<?= $share($valor[$k], $p['saidas']) ?>%;--c:<?= e($cor[$k]) ?>"></i></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="dash-bd-foot num">
                <?php if ($p['entradas'] > 0.004): ?>
                    <span>saídas = <b><?= $share($p['saidas'], $p['entradas']) ?>%</b> das entradas</span>
                <?php endif; ?>
                <span class="<?= e($balCls) ?>"><?= e($balLbl) ?> <b><?= e(num(abs($p['balanco']))) ?></b></span>
            </div>
        </div>
    </section>

    <!-- conferir com o banco: o único jeito de validar o saldo calculado -->
    <details class="card dobra dash-conf" id="conferir"<?= $conferirAberto ? ' open' : '' ?>>
        <summary>
            <span class="titulo">conferir com o banco</span>
            <span class="dash-conf-ult num">
                <?php if ($conf !== null): ?>
                    última <?= e(diaMes($conf['data'])) ?> · <?= e(num($conf['saldo'])) ?> · <?= $conf['bate'] ? '<span class="pos">bate</span>' : '<span class="warn">diferença ' . e(moneySigned($conf['dif'])) . '</span>' ?>
                <?php else: ?>
                    nunca conferido
                <?php endif; ?>
            </span>
        </summary>
        <form class="dash-conf-form" method="post" action="/conferir">
            <?= csrfField() ?>
            <input type="hidden" name="volta" value="/">
            <label class="campo">
                <span>saldo que o banco mostra</span>
                <input type="text" name="saldo_informado" inputmode="decimal" autocomplete="off" enterkeyhint="done" placeholder="0,00" value="<?= e((string) ($conferirErro['valor'] ?? '')) ?>">
            </label>
            <fieldset class="quando" data-quando>
                <legend class="sr-only">quando</legend>
                <label class="chip-r"><input type="radio" name="quando" value="hoje"<?= ($conferirErro['quando'] ?? 'hoje') !== 'outro' ? ' checked' : '' ?>><span>hoje</span></label>
                <label class="chip-r"><input type="radio" name="quando" value="outro"<?= ($conferirErro['quando'] ?? '') === 'outro' ? ' checked' : '' ?>><span>outro dia</span></label>
                <input type="date" name="data" class="quando-data" value="<?= e((string) ($conferirErro['data'] ?? $hoje)) ?>" max="<?= e($hoje) ?>" aria-label="data do saldo">
            </fieldset>
            <?php if ($conferirErro !== null): ?><p class="aviso aviso-error" role="alert"><?= e((string) $conferirErro['msg']) ?></p><?php endif; ?>
            <button type="submit" class="btn btn-primary dash-conf-btn">conferir</button>
        </form>
    </details>

    <div class="dash-grade">
        <!-- maiores gastos -->
        <section class="card" aria-labelledby="dash-top-t">
            <div class="card-head">
                <h2 class="titulo" id="dash-top-t">maiores gastos</h2>
                <a class="card-acao" href="/lancamentos?mes=<?= e($mesRef) ?>">extrato ›</a>
            </div>
            <?php if ($p['top'] === []): ?>
                <p class="dash-vazio">nada lançado em <?= e($mesLbl) ?></p>
            <?php else: ?>
                <ol class="dash-top">
                    <?php foreach ($p['top'] as $t): $c = $t['essencial'] ? 'var(--g-ess)' : 'var(--g-naoess)'; ?>
                        <li class="dash-top-item">
                            <a class="dash-top-nome" href="/lancamentos?mes=<?= e($mesRef) ?>&cat=<?= (int) $t['id'] ?>">
                                <i class="dash-dot" style="--c:<?= $c ?>"></i><?= e($t['nome']) ?>
                                <span class="dash-top-pct num"><?= $share($t['valor'], $p['saidas']) ?>%</span>
                            </a>
                            <span class="dash-top-val num"><?= e(num($t['valor'])) ?></span>
                            <span class="dash-top-bar"><i style="--w:<?= $topMax > 0 ? round($t['valor'] / $topMax * 100, 1) : 0 ?>%;--c:<?= $c ?>"></i></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>

        <!-- últimos lançamentos -->
        <section class="card" aria-labelledby="dash-ult-t">
            <div class="card-head">
                <h2 class="titulo" id="dash-ult-t">últimos lançamentos</h2>
                <a class="card-acao" href="/lancar?volta=%2F" data-lancar>+ lançar</a>
            </div>
            <?php if ($ultimos === []): ?>
                <p class="dash-vazio">nada lançado em <?= e($mesLbl) ?></p>
            <?php else: ?>
                <ul class="dash-ult">
                    <?php foreach ($ultimos as $r):
                        $mov = (int) $r['operacional'] !== 1;
                        $ent = !$mov && (int) $r['sinal'] > 0;
                        $v   = (float) $r['valor'];
                        $txt = $mov ? '⇄ ' . num($v) : ($ent ? '+' . num($v) : num(-$v));
                        $cls = $mov ? 'dash-ult-mov' : ($ent ? 'pos' : 'neg');
                        $cat = ['nome' => $r['categoria_nome'], 'apelido' => $r['apelido']];
                        ?>
                        <li>
                            <a class="dash-ult-linha" href="/lancamentos/<?= (int) $r['id'] ?>/editar?volta=%2F">
                                <span class="dash-ult-desc"><?= e((string) $r['descricao']) ?></span>
                                <span class="dash-ult-val num <?= $cls ?>"><?= e($txt) ?></span>
                                <span class="dash-ult-meta num"><i class="dot-g <?= e(corClasse($r)) ?>"></i><?= e(nomeCurto($cat)) ?></span>
                                <span class="dash-ult-data num"><?= e($semana[(int) date('w', strtotime($r['data']))] . ' ' . diaMes($r['data'])) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</div>
