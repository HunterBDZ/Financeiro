<?php
/**
 * Fechar mês. Ritual mensal em até 4 blocos: os números, os desvios,
 * o saldo no banco e a prévia do mês seguinte. Mês REAL: resumo só de leitura (blocos 1 e 2) +
 * REABRIR. Mês futuro: aviso "ainda não começou". Nos números, o rótulo vem num <span> (linha
 * pequena) e o valor logo depois (linha grande).
 */
$mesLbl = monthLabel($mes);
$csrf = csrfField();
?>
<div class="fe">
    <header class="fe-topo">
        <a class="fe-volta" href="/mes?mes=<?= e($mes) ?>" aria-label="<?= e(t('voltar para {mes}', ['mes' => $mesLbl])) ?>"><?= svgIcon('chevron', 20, 'fe-volta-seta') ?></a>
        <?php if (!empty($bloqueado)): ?>
            <h1 class="fe-titulo"><?= e(t('Fechar {mes}', ['mes' => $mesLbl])) ?></h1>
        <?php elseif ($real): ?>
            <h1 class="fe-titulo"><?= e($mesLbl) ?></h1> <span class="estado estado-real"><?= e(estadoLabel('real')) ?></span>
        <?php else: ?>
            <h1 class="fe-titulo"><?= e(t('Fechar {mes}', ['mes' => $mesLbl])) ?></h1> <span class="estado estado-<?= e(estadoMes($mes, 'PREVISAO', $hoje)) ?>"><?= e(estadoLabel(estadoMes($mes, 'PREVISAO', $hoje))) ?></span>
        <?php endif; ?>
    </header>

<?php if (!empty($bloqueado)): ?>
    <p class="aviso aviso-warn fe-bloqueado"><?= e(t('Esse mês ainda não começou. Ele pode ser fechado a partir do primeiro dia.')) ?></p>
</div>
<?php return; endif; ?>

<?php
$total = $real ? 2 : ($previa !== null ? 4 : 3);
$contaTxt = num($contaAntes) . NBSP . '→' . NBSP . num($contaDepois);   // nunca quebra: a célula ocupa 2 colunas
$sinal = static fn(float $v): string => ($v > 0.004 ? '+' : '') . num($v);
?>
    <?php if (!$real): ?><form class="fe-form" method="post" action="/mes/fechar" data-confirm="<?= e($faltam > 0 ? tn($faltam, 'Ainda falta 1 dia em {mes}. Fechar agora? Dá para reabrir depois.', 'Ainda faltam {n} dias em {mes}. Fechar agora? Dá para reabrir depois.', ['mes' => $mesLbl]) : t('Fechar {mes}? Dá para reabrir depois.', ['mes' => $mesLbl])) ?>"><?= $csrf ?><input type="hidden" name="mes" value="<?= e($mes) ?>"><?php endif; ?>

    <?php if (!$real): ?><p class="fe-intro"><?= e(t('Fechar é conferir o mês e guardar o resultado. Você pode reabrir depois.')) ?></p><?php endif; ?>

    <div class="fe-blocos">
        <!-- 1: os números do mês -->
        <section class="card fe-bloco">
            <h2 class="fe-h"><?= e(t('Os números de {mes}', ['mes' => $mesLbl])) ?></h2>
            <div class="fe-grade num">
                <p class="fe-num"><span><?= e(t('Entrou')) ?></span> <?= e(num($entrou)) ?></p>
                <p class="fe-num"><span><?= e(t('Saiu')) ?></span> <?= e(num($saiu)) ?></p>
                <p class="fe-num <?= $saldoMes < -0.004 ? 'neg' : 'pos' ?>"><span><?= e(t('Balanço')) ?> <?= dica('numeros', t('Balanço')) ?></span> <?= e($sinal($saldoMes)) ?></p>
                <p class="fe-num fe-conta <?= $contaDepois < 0 ? 'neg' : '' ?>"><span><?= e(t('Conta')) ?></span> <?= e($contaTxt) ?></p>
            </div>
            <?php if (($guardado ?? 0.0) > 0.004): ?><p class="fe-ponte num"><?= e(t('Sobra {sobra} − guardado {guardado} = {balanco} na conta.', ['sobra' => num($saldoMes + $guardado), 'guardado' => num($guardado), 'balanco' => $sinal($saldoMes)])) ?></p><?php endif; ?>
            <?php if (!empty($avisoParcela)): ?><p class="aviso aviso-warn" role="status"><?= e($avisoParcela) ?></p><?php endif; ?>
        </section>

        <!-- 2: desvios -->
        <section class="card fe-bloco">
            <h2 class="fe-h"><?= e(t('Onde fugiu do previsto')) ?></h2>
            <?php if ($desvios === []): ?>
                <p class="fe-nada"><?= e(t('Nenhuma categoria fugiu mais de 10 do previsto.')) ?></p>
            <?php else: ?>
                <ul class="fe-desvios">
                    <?php foreach ($desvios as $dv):
                        $ruim = $dv['sinal'] === -1 ? $dv['dif'] > 0 : $dv['dif'] < 0;
                        $difV = num(abs($dv['dif']), 0);
                        // com dias pela frente, gastar menos ainda não é resultado: só fica colorido o que já acabou
                        $acabou = $real || $faltam === 0;
                        $difTxt = $acabou
                            ? ($dv['dif'] > 0 ? t('{valor} a mais.', ['valor' => $difV]) : t('{valor} a menos.', ['valor' => $difV]))
                            : ($dv['dif'] > 0 ? t('Até agora, {valor} a mais.', ['valor' => $difV]) : t('Até agora, {valor} a menos.', ['valor' => $difV]));
                        $difCls = $ruim ? 'neg' : ($acabou ? 'pos' : 'dim'); ?>
                        <li>
                            <span class="fe-dv-nome"><?= e($dv['nome']) ?></span>
                            <span class="num dim"><?= e(t('{gasto} de {meta}', ['gasto' => num($dv['real'], 0), 'meta' => num($dv['prev'], 0)])) ?></span>
                            <span class="num fe-dv-dif <?= $difCls ?>"><?= e($difTxt) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <?php if (!$real): ?>
        <!-- 3: banco -->
        <section class="card fe-bloco" id="banco">
            <h2 class="fe-h"><?= e(t('Saldo no banco')) ?></h2>
            <label class="campo">
                <span><?= e(t('Quanto o banco mostra em {data} (opcional)', ['data' => diaMes($dataBanco)])) ?></span>
                <input type="text" name="saldo" inputmode="decimal" autocomplete="off" enterkeyhint="done"
                       placeholder="<?= e(t('ex.: 1.546,00')) ?>" value="<?= e((string) ($erro['saldo'] ?? '')) ?>"<?= $erro !== null ? ' aria-invalid="true" aria-describedby="fe-erro" data-autofocus' : '' ?>>
            </label>
            <fieldset class="quando fe-quando" data-quando>
                <legend class="sr-only"><?= e(t('data do saldo')) ?></legend>
                <label class="chip-r"><input type="radio" name="quando" value="hoje" checked><span><?= $dataBanco === $hoje ? e(t('Hoje')) : e(diaMes($dataBanco)) ?></span></label>
                <label class="chip-r"><input type="radio" name="quando" value="outro"><span><?= e(t('Outro dia')) ?></span></label>
                <input type="date" name="data" class="quando-data" value="<?= e($dataBanco) ?>" min="<?= e($dataMin) ?>" max="<?= e($hoje) ?>" aria-label="<?= e(t('data do saldo')) ?>">
            </fieldset>
            <?php if ($erro !== null): ?><p class="aviso aviso-error" id="fe-erro" role="alert"><?= e($erro['msg']) ?></p><?php endif; ?>
        </section>

        <?php if ($previa !== null): ?>
        <!-- 4: o mês seguinte (prévia como se este já estivesse fechado) -->
        <section class="card fe-bloco">
            <h2 class="fe-h"><?= e(t('E {mes}', ['mes' => monthLabel($previa['mes'])])) ?></h2>
            <p class="fe-previa num">
                <span><?= t('Dia a dia sugerido: {valor}', ['valor' => '<b>' . e(num($previa['dia'], 0)) . '</b>']) ?></span>
                <span><?= t('Sobra acima da reserva: {valor}', ['valor' => '<b>' . e(num($previa['livre'], 0)) . '</b>']) ?></span>
                <span class="dim"><?= e(t('Com base em {base}.', ['base' => $previa['base']])) ?></span>
            </p>
            <label class="fe-aplicar">
                <input type="checkbox" name="aplicar" value="1">
                <span><?= e(t('Aplicar em {mes}', ['mes' => monthLabel($previa['mes'])])) ?><small class="fe-aplicar-nota"><?= e(t('Em {mes}, usar estes valores em vez das suas metas. Vale só para {mes}.', ['mes' => mesExtenso($previa['mes'])])) ?></small></span>
            </label>
        </section>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if (!$real): ?>
        <button type="submit" class="btn btn-primary btn-block fe-botao"><?= e(t('Fechar {mes}', ['mes' => $mesLbl])) ?><?php if ($faltam > 0): ?><span class="fe-faltam"><?= e(tn($faltam, 'ainda faltam 1 dia', 'ainda faltam {n} dias')) ?></span><?php endif; ?></button>
    </form>
    <?php else: ?>
        <form class="fe-reabrir" method="post" action="/mes/reabrir">
            <?= $csrf ?>
            <input type="hidden" name="mes" value="<?= e($mes) ?>">
            <button type="submit" class="btn btn-ghost btn-block"><?= e(t('Reabrir {mes}', ['mes' => $mesLbl])) ?></button>
        </form>
    <?php endif; ?>
</div>
