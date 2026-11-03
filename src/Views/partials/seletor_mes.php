<?php
/**
 * O seletor de mês do topo: ‹ novembro ›. As setas levam ao mês anterior e ao próximo; o nome é o botão de um
 * seletor (details.menu, funciona sem JS) que abre todos os meses por ano. Usado por partials/topo.
 * Variáveis: $mes (AAAA-MM-01), $meses (linhas {mes, situacao} em ordem), $href (prefixo do link, recebe o mês no
 * fim), $sufixo (opcional, ex. '#contas'), $hoje (opcional, Y-m-d), $anual (opcional: o Relatório e o simulador no fim
 * do painel).
 */
$mes    = (string) ($mes ?? \Financeiro\Relogio::mes());
$href   = (string) ($href ?? '?mes=');
$sufixo = (string) ($sufixo ?? '');
$hoje   = $hoje ?? null;
$anual  = !empty($anual);

$lista = [];
$situacoes = [];
foreach ($meses ?? [] as $m) {
    $k = is_array($m) ? (string) ($m['mes'] ?? '') : (string) $m;
    if ($k === '') {
        continue;
    }
    $lista[] = $k;
    $situacoes[$k] = is_array($m) ? (string) ($m['situacao'] ?? 'PREVISAO') : 'PREVISAO';
}
if (!in_array($mes, $lista, true)) {
    $lista[] = $mes;
    sort($lista);
    $situacoes[$mes] = 'PREVISAO';
}
$i = array_search($mes, $lista, true);
$anterior = $i > 0 ? $lista[$i - 1] : null;
$proximo  = $i < count($lista) - 1 ? $lista[$i + 1] : null;
$porExtenso = static fn(string $k): string => t('{mes} de {ano}', ['mes' => mb_convert_case(mesExtenso($k), MB_CASE_TITLE), 'ano' => substr($k, 0, 4)]);
?>
<nav class="seletor-mes" aria-label="<?= e(t('mês')) ?>">
    <?php if ($anterior !== null): ?>
        <a class="seletor-seta" href="<?= e($href . $anterior . $sufixo) ?>" rel="prev" aria-label="<?= e($porExtenso($anterior)) ?>"><?= svgIcon('chevron', 18, 'ic-esq') ?></a>
    <?php else: ?>
        <span class="seletor-seta is-off" aria-hidden="true"><?= svgIcon('chevron', 18, 'ic-esq') ?></span>
    <?php endif; ?>
    <details class="menu mes-picker" data-mes-picker>
        <summary aria-label="<?= e(t('{mes} {ano}, trocar o mês', ['mes' => mb_convert_case(mesExtenso($mes), MB_CASE_TITLE), 'ano' => substr($mes, 0, 4)])) ?>">
            <span aria-hidden="true"><?= e(mesExtenso($mes)) ?></span><?= svgIcon('chevron', 14, 'mes-picker-seta') ?>
        </summary>
        <?php \Financeiro\View::partial('partials/_mes_painel', ['mes' => $mes, 'lista' => $lista, 'situacoes' => $situacoes, 'hoje' => $hoje, 'href' => $href, 'sufixo' => $sufixo, 'anual' => $anual]); ?>
    </details>
    <?php if ($proximo !== null): ?>
        <a class="seletor-seta" href="<?= e($href . $proximo . $sufixo) ?>" rel="next" aria-label="<?= e($porExtenso($proximo)) ?>"><?= svgIcon('chevron', 18) ?></a>
    <?php else: ?>
        <span class="seletor-seta is-off" aria-hidden="true"><?= svgIcon('chevron', 18) ?></span>
    <?php endif; ?>
</nav>
