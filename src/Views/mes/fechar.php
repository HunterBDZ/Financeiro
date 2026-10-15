<?php
/**
 * Fechar mês (SPEC §2.6) — dono: P5. Ritual mensal: 4 blocos numerados pelo CSS ([1/4]).
 * Mês REAL: resumo só de leitura (blocos 1 e 2) + REABRIR. Mês futuro: 400 "mês ainda não começou".
 * Números grandes: o texto fica contíguo ("entrou 29.177,81") e o CSS (::first-line) faz do
 * rótulo a 1ª linha pequena e do valor a 2ª, grande.
 */
$mesLbl = monthLabel($mes);
$csrf = csrfField();
?>
<div class="fe">
    <header class="fe-topo">
        <a class="fe-volta" href="/mes?mes=<?= e($mes) ?>" aria-label="voltar para <?= e($mesLbl) ?>">‹</a>
        <?php if (!empty($bloqueado)): ?>
            <h1 class="fe-titulo">fechar <?= e($mesLbl) ?></h1>
        <?php elseif ($real): ?>
            <h1 class="fe-titulo"><?= e($mesLbl) ?></h1> <span class="estado estado-real"><?= e(estadoLabel('real')) ?></span>
        <?php else: ?>
            <h1 class="fe-titulo">fechar <?= e($mesLbl) ?></h1> <span class="estado estado-<?= e(estadoMes($mes, 'PREVISAO', $hoje)) ?>"><?= e(estadoLabel(estadoMes($mes, 'PREVISAO', $hoje))) ?></span>
        <?php endif; ?>
    </header>

<?php if (!empty($bloqueado)): ?>
    <p class="aviso aviso-warn fe-bloqueado">mês ainda não começou</p>
</div>
<?php return; endif; ?>

<?php
$total = $real ? 2 : ($previa !== null ? 4 : 3);
$contaTxt = num($contaAntes) . NBSP . '→' . NBSP . num($contaDepois);   // nunca quebra: a célula ocupa 2 colunas
$sinal = static fn(float $v): string => ($v > 0.004 ? '+' : '') . num($v);
?>
    <?php if (!$real): ?><form class="fe-form" method="post" action="/mes/fechar"><?= $csrf ?><input type="hidden" name="mes" value="<?= e($mes) ?>"><?php endif; ?>

    <div class="fe-blocos">
        <!-- 1: os números do mês -->
        <section class="card fe-bloco">
            <h2 class="fe-h" data-total="<?= e((string) $total) ?>"><?= e($mesLbl) ?></h2>
            <div class="fe-grade num">
                <p class="fe-num">entrou <?= e(num($entrou)) ?></p>
                <p class="fe-num">saiu <?= e(num($saiu)) ?></p>
                <p class="fe-num <?= $saldoMes < -0.004 ? 'neg' : 'pos' ?>">balanço <?= e($sinal($saldoMes)) ?></p>
                <p class="fe-num fe-conta <?= $contaDepois < 0 ? 'neg' : '' ?>">conta <?= e($contaTxt) ?></p>
            </div>
            <?php if (!empty($avisoConsignado)): ?><p class="aviso aviso-warn" role="status"><?= e($avisoConsignado) ?></p><?php endif; ?>
        </section>

        <!-- 2: desvios -->
        <section class="card fe-bloco">
            <h2 class="fe-h" data-total="<?= e((string) $total) ?>">desvios</h2>
            <?php if ($desvios === []): ?>
                <p class="fe-nada">—</p>
            <?php else: ?>
                <ul class="fe-desvios">
                    <?php foreach ($desvios as $dv):
                        $ruim = $dv['sinal'] === -1 ? $dv['dif'] > 0 : $dv['dif'] < 0; ?>
                        <li>
                            <span class="fe-dv-nome"><?= e($dv['nome']) ?></span>
                            <span class="num dim"><?= e(num($dv['real'], 0) . ' / ' . num($dv['prev'], 0)) ?></span>
                            <span class="num fe-dv-dif <?= $ruim ? 'neg' : 'pos' ?>"><?= e(($dv['dif'] > 0 ? '+' : '') . num($dv['dif'], 0)) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <?php if (!$real): ?>
        <!-- 3: banco -->
        <section class="card fe-bloco" id="banco">
            <h2 class="fe-h" data-total="<?= e((string) $total) ?>">banco</h2>
            <label class="campo">
                <span>saldo no banco em <?= e(diaMes($dataBanco)) ?></span>
                <input type="text" name="saldo" inputmode="decimal" autocomplete="off" enterkeyhint="done"
                       placeholder="0,00" value="<?= e((string) ($erro['saldo'] ?? '')) ?>">
            </label>
            <fieldset class="quando fe-quando" data-quando>
                <legend class="sr-only">data do saldo</legend>
                <label class="chip-r"><input type="radio" name="quando" value="hoje" checked><span><?= $dataBanco === $hoje ? 'hoje' : e(diaMes($dataBanco)) ?></span></label>
                <label class="chip-r"><input type="radio" name="quando" value="outro"><span>outro dia</span></label>
                <input type="date" name="data" class="quando-data" value="<?= e($dataBanco) ?>" min="<?= e($dataMin) ?>" max="<?= e($hoje) ?>" aria-label="data do saldo">
            </fieldset>
            <?php if ($erro !== null): ?><p class="aviso aviso-error"><?= e($erro['msg']) ?></p><?php endif; ?>
        </section>

        <?php if ($previa !== null): ?>
        <!-- 4: o mês seguinte (prévia como se este já estivesse fechado) -->
        <section class="card fe-bloco">
            <h2 class="fe-h" data-total="<?= e((string) $total) ?>"><?= e(monthLabel($previa['mes'])) ?></h2>
            <p class="fe-previa num">
                <span>dia a dia sugerido <?= e(num($previa['dia'], 0)) ?></span>
                <span>sobra acima da reserva <?= e(num($previa['livre'], 0)) ?></span>
                <span class="dim">com base em <?= e($previa['base']) ?></span>
            </p>
            <label class="fe-aplicar">
                <input type="checkbox" name="aplicar" value="1" checked>
                <span>aplicar em <?= e(monthLabel($previa['mes'])) ?></span>
            </label>
        </section>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if (!$real): ?>
        <button type="submit" class="btn btn-primary btn-block fe-botao">fechar <?= e($mesLbl) ?><?php if ($faltam > 0): ?><span class="fe-faltam"> · faltam <?= e((string) $faltam) ?> <?= $faltam === 1 ? 'dia' : 'dias' ?></span><?php endif; ?></button>
    </form>
    <?php else: ?>
        <form class="fe-reabrir" method="post" action="/mes/reabrir">
            <?= $csrf ?>
            <input type="hidden" name="mes" value="<?= e($mes) ?>">
            <button type="submit" class="btn btn-ghost btn-block">reabrir <?= e($mesLbl) ?></button>
        </form>
    <?php endif; ?>
</div>
