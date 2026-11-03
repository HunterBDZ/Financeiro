<?php
/**
 * Fechar mês: conferir o mês e guardar o resultado, em até 4 faixas da grade: os números (com a escada que leva das
 * entradas e gastos ao balanço, somando cada parcela que ela lista), onde fugiu do previsto, o saldo no banco e a
 * prévia do mês seguinte. "Fechar {mês}" é um botão de verdade: antes do último dia do mês, desligado e com o motivo.
 * Mês REAL: os números e os desvios, só de leitura, e Reabrir. Mês futuro: "ainda não começou".
 */
use Financeiro\View;

$mesLbl  = monthLabel($mes);
$mesNome = mesExtenso($mes);
$csrf    = csrfField();
$volta   = '<a class="btn btn-contorno" href="/mes?mes=' . e($mes) . '">' . svgIcon('chevron', 16, 'fe-volta-seta') . e(t('Voltar ao mês')) . '</a>';
?>
<div class="grade fe">
<?php if (!empty($bloqueado)): ?>
    <?php View::partial('partials/topo', ['titulo' => t('Fechar {mes}', ['mes' => $mesNome]), 'apoio' => mesAno($mes), 'acoes' => $volta]); ?>
    <section class="faixa-grade" aria-label="<?= e(t('Fechar {mes}', ['mes' => $mesNome])) ?>">
        <p class="fe-bloqueado"><?= e(t('Esse mês ainda não começou. Ele pode ser fechado a partir do primeiro dia.')) ?></p>
    </section>
</div>
<?php return; endif; ?>

<?php
$sinal = static fn(float $v): string => ($v > 0.004 ? '+' : '') . num($v);
// das entradas e gastos ao balanço: as movimentações, com os nomes da escada do Relatório. O balanço é a soma da
// escada inteira: entradas menos gastos, mais o crédito, menos o adiantado, o guardado, o aplicado e a parcela paga à parte
$base = round((float) $sobra['renda'] - (float) $sobra['saidas'], 2);
$passos = [];
if (abs($movimentos['cred']) >= 0.005) {
    $passos[] = [t('Crédito do empréstimo'), $movimentos['cred']];
}
if (abs($movimentos['amort']) >= 0.005) {
    $passos[] = [t('Adiantou do empréstimo'), -$movimentos['amort']];
}
if (abs($movimentos['poup']) >= 0.005) {
    $passos[] = [$movimentos['poup'] >= 0 ? t('Guardou na poupança') : t('Tirou da poupança'), -$movimentos['poup']];
}
if (abs($movimentos['inv']) >= 0.005) {
    $passos[] = [$movimentos['inv'] >= 0 ? t('Aplicou em investimentos') : t('Resgatou de investimentos'), -$movimentos['inv']];
}
// o que sobra das movimentações é a parcela do empréstimo paga à parte (sai da conta)
$resto = round((float) $sobra['mov'] - array_sum(array_column($passos, 1)), 2);
if (abs($resto) >= 0.005) {
    $passos[] = [t('Parcela do empréstimo'), $resto];
}
$fimData = \Financeiro\Models\MesModel::fechaAPartirDe($mes);
// o botão: só a partir do último dia do mês (ou depois, num mês que já passou); o POST confere a mesma regra
$podeFechar = !$real && \Financeiro\Models\MesModel::podeFechar($mes, (string) $hoje);
$apoio = $real ? t('Fechado') : ($podeFechar ? t('Confira os números e feche') : t('Dá para fechar a partir de {data}', ['data' => t('{dia} de {mes}', ['dia' => (int) substr($fimData, 8, 2), 'mes' => $mesNome])]));
$titulo = $real ? mb_convert_case(mesAno($mes), MB_CASE_TITLE) : t('Fechar {mes}', ['mes' => $mesNome]);
?>
    <?php View::partial('partials/topo', ['titulo' => $titulo, 'apoio' => $apoio, 'acoes' => $volta]); ?>

    <?php if (!$real): ?><form class="fe-form" method="post" action="/mes/fechar" data-confirm="<?= e(t('Fechar {mes}? Dá para reabrir depois.', ['mes' => $mesLbl])) ?>"><?= $csrf ?><input type="hidden" name="mes" value="<?= e($mes) ?>"><?php endif; ?>

    <!-- 1: os números do mês -->
    <section class="faixa-grade fe-bloco" aria-labelledby="fe-numeros-t">
        <div class="secao-cab"><h2 id="fe-numeros-t"><?= e(t('Os números de {mes}', ['mes' => $mesNome])) ?></h2></div>
        <?php if (!$real): ?><p class="fe-intro"><?= e(t('Fechar é conferir o mês e guardar o resultado. Você pode reabrir depois.')) ?></p><?php endif; ?>
        <dl class="numeros fe-numeros">
            <div><dt><?= e(t('Entrou')) ?></dt><dd><?= valor($entrou, 'entrada') ?></dd></div>
            <div><dt><?= e(t('Saiu')) ?></dt><dd><?= valor($saiu, 'saida') ?></dd></div>
            <div><dt><?= e(t('Balanço')) ?> <?= dica('numeros', t('Balanço')) ?></dt><dd data-balanco><?= valor($saldoMes, 'auto') ?></dd></div>
            <div><dt><?= e(t('Conta')) ?></dt><dd><?= valor($contaDepois, 'neutro', ['classe' => $contaDepois < -0.004 ? 'alerta' : '']) ?><span class="nota num"><?= e(t('começou com {valor}', ['valor' => num($contaAntes)])) ?></span></dd></div>
        </dl>
        <table class="livro fe-ponte">
            <caption class="sr-only"><?= e(t('Das entradas e gastos ao balanço')) ?></caption>
            <tbody>
                <tr><td><?= e(t('Entradas menos gastos')) ?></td><td class="c-real" data-ponte-base><?= e($sinal($base)) ?></td></tr>
                <?php foreach ($passos as [$rotulo, $valor]): ?>
                    <tr><td><?= e($rotulo) ?></td><td class="c-real"><?= e($sinal($valor)) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="total-final"><td><?= e(t('Balanço')) ?></td><td class="c-real" data-sobra="<?= e((string) $sobra['base']) ?>"><?= e($sinal((float) $sobra['sobra'])) ?></td></tr>
            </tfoot>
        </table>
        <?php if (!empty($avisoParcela)): ?><p class="fe-aviso atencao" role="status"><?= e($avisoParcela) ?></p><?php endif; ?>
    </section>

    <!-- 2: desvios -->
    <section class="faixa-grade fe-bloco" aria-labelledby="fe-desvios-t">
        <div class="secao-cab"><h2 id="fe-desvios-t"><?= e(t('Onde fugiu do previsto')) ?></h2></div>
        <?php if ($desvios === []): ?>
            <p class="fe-nada"><?= e(t('Nenhuma categoria fugiu mais de 10 do previsto.')) ?></p>
        <?php else: ?>
            <ul class="fe-desvios">
                <?php foreach ($desvios as $dv):
                    $ruim = $dv['sinal'] === -1 ? $dv['dif'] > 0 : $dv['dif'] < 0;
                    $difV = num(abs($dv['dif']));
                    // com dias pela frente, gastar menos ainda não é resultado: só fica colorido o que já acabou
                    $acabou = $real || $faltam === 0;
                    $difTxt = $acabou
                        ? ($dv['dif'] > 0 ? t('{valor} a mais.', ['valor' => $difV]) : t('{valor} a menos.', ['valor' => $difV]))
                        : ($dv['dif'] > 0 ? t('Até agora, {valor} a mais.', ['valor' => $difV]) : t('Até agora, {valor} a menos.', ['valor' => $difV]));
                    $difCls = $ruim ? 'alerta' : ($acabou ? 'v-entrada' : 'fraco'); ?>
                    <li class="linha-lista">
                        <span class="linha-nome"><b><?= e($dv['nome']) ?></b><small class="num"><?= e(t('{gasto} de {meta}', ['gasto' => num($dv['real']), 'meta' => num($dv['prev'])])) ?></small></span>
                        <span class="num fe-dv-dif <?= $difCls ?>"><?= e($difTxt) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <?php if (!$real): ?>
    <!-- 3: banco -->
    <section class="faixa-grade fe-bloco" id="banco" aria-labelledby="fe-banco-t">
        <div class="secao-cab"><h2 id="fe-banco-t"><?= e(t('Saldo no banco')) ?></h2><p><?= e(t('opcional')) ?></p></div>
        <div class="fe-banco">
            <?php if (!empty($contasBanco)): ?>
                <p class="fe-intro"><?= e(t('Quanto cada banco mostra em {data} (opcional)', ['data' => diaMes($dataBanco)])) ?></p>
                <?php View::partial('partials/saldos_contas', ['contas' => $contasBanco, 'digitado' => (array) ($erro['contas'] ?? []), 'pref' => 'fe', 'invalido' => $erro !== null]); ?>
                <?php if ($erro !== null): ?><p class="campo-erro" id="fe-erro" role="alert"><?= e($erro['msg']) ?></p><?php endif; ?>
            <?php else: ?>
                <label class="campo">
                    <span><?= e(t('Quanto o banco mostra em {data} (opcional)', ['data' => diaMes($dataBanco)])) ?></span>
                    <input type="text" name="saldo" inputmode="decimal" autocomplete="off" enterkeyhint="done"
                           placeholder="<?= e(t('ex.: 1.546,00')) ?>" value="<?= e((string) ($erro['saldo'] ?? '')) ?>"<?= $erro !== null ? ' aria-invalid="true" aria-describedby="fe-erro" data-autofocus' : '' ?>>
                    <?php if ($erro !== null): ?><span class="campo-erro" id="fe-erro" role="alert"><?= e($erro['msg']) ?></span><?php endif; ?>
                </label>
            <?php endif; ?>
            <fieldset class="quando fe-quando" data-quando>
                <legend class="sr-only"><?= e(t('data do saldo')) ?></legend>
                <label class="chip-r"><input type="radio" name="quando" value="hoje" checked><span><?= $dataBanco === $hoje ? e(t('Hoje')) : e(diaMes($dataBanco)) ?></span></label>
                <label class="chip-r"><input type="radio" name="quando" value="outro"><span><?= e(t('Outro dia')) ?></span></label>
                <input type="date" name="data" class="quando-data" value="<?= e($dataBanco) ?>" min="<?= e($dataMin) ?>" max="<?= e($hoje) ?>" aria-label="<?= e(t('data do saldo')) ?>">
            </fieldset>
        </div>
    </section>

    <?php if ($previa !== null): ?>
    <!-- 4: o mês seguinte (prévia como se este já estivesse fechado) -->
    <section class="faixa-grade fe-bloco" aria-labelledby="fe-previa-t">
        <div class="secao-cab"><h2 id="fe-previa-t"><?= e(t('E {mes}', ['mes' => mesExtenso($previa['mes'])])) ?></h2><p><?= e(t('Com base em {base}.', ['base' => $previa['base']])) ?></p></div>
        <dl class="numeros fe-previa" style="--colunas:2">
            <div><dt><?= e(t('Dia a dia sugerido')) ?></dt><dd><?= valor($previa['dia'], 'previsto') ?></dd></div>
            <div><dt><?= e(t('Para os não essenciais')) ?></dt><dd><?= valor($previa['livre'], 'previsto') ?></dd></div>
        </dl>
        <label class="fe-aplicar">
            <input type="checkbox" name="aplicar" value="1">
            <span><?= e(t('Aplicar em {mes}', ['mes' => monthLabel($previa['mes'])])) ?><small class="fe-aplicar-nota"><?= e(t('Em {mes}, usar estes valores em vez das suas metas. Vale só para {mes}.', ['mes' => mesExtenso($previa['mes'])])) ?></small></span>
        </label>
    </section>
    <?php endif; ?>

    <div class="faixa-grade fe-fim">
        <?php if ($podeFechar): ?>
            <button type="submit" class="btn btn-principal fe-botao" data-fechar><?= e(t('Fechar {mes}', ['mes' => $mesNome])) ?></button>
        <?php else: ?>
            <button type="submit" class="btn btn-principal fe-botao" disabled aria-describedby="fe-quando-t" data-fechar><?= e(t('Fechar {mes}', ['mes' => $mesNome])) ?></button>
            <p class="fe-motivo" id="fe-quando-t"><?= e(tn($faltam, 'Ainda falta 1 dia em {mes}. Dá para fechar a partir de {data}.', 'Ainda faltam {n} dias em {mes}. Dá para fechar a partir de {data}.', ['mes' => $mesNome, 'data' => t('{dia} de {mes}', ['dia' => (int) substr($fimData, 8, 2), 'mes' => $mesNome])])) ?></p>
        <?php endif; ?>
    </div>
    </form>
    <?php else: ?>
    <div class="faixa-grade fe-fim">
        <form class="fe-reabrir" method="post" action="/mes/reabrir">
            <?= $csrf ?>
            <input type="hidden" name="mes" value="<?= e($mes) ?>">
            <button type="submit" class="btn btn-contorno"><?= e(t('Reabrir {mes}', ['mes' => $mesNome])) ?></button>
        </form>
    </div>
    <?php endif; ?>
</div>
