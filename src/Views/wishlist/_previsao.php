<?php
/**
 * Quando comprar o 1º item (também é a resposta de ?parcial=1): a resposta em uma frase. O resto fica em "Ver mês a mês":
 * como pagar, a fileira de meses (o estado vem com símbolo e rótulo, nunca só com cor) e o que acontece no mês escolhido.
 * Variáveis: $itens, $prev (WishlistPrevisao::calcular ou null), $mes, $parcelas.
 */
use Financeiro\Services\WishlistPrevisao;
use Financeiro\Site;

if ($prev === null) {
    if ($itens !== [] && $itens[0]['valor'] === null): ?>
        <p class="wl-sem-valor"><?= e(t('Informe o valor para ver em que mês ele cabe.')) ?> <a href="/wishlist/<?= (int) $itens[0]['id'] ?>/editar"><?= e(t('Informar o valor')) ?></a></p>
    <?php elseif ($itens !== [] && $itens[0]['valor_site'] === null): ?>
        <p class="wl-sem-valor"><?= e(t('Sem cotação de {moeda} agora, então não dá para prever. Tente de novo mais tarde.', ['moeda' => (string) $itens[0]['moeda']])) ?></p>
    <?php endif;
    return;
}
$rotuloMes = static fn(string $m): string => mesAno($m);
$link = static fn(string $m, int $p): string => '/wishlist?' . http_build_query(['mes' => $m, 'parcelas' => $p]);
$agora = (string) array_key_first($prev['meses']);
// "custo": cabe, mas atrasa o empréstimo. É o que separa comprar logo de esperar.
$estadoDe = static fn(array $i): string => $i['estado'] === 'ok' && ($i['custo'] >= 1 || $i['atraso'] > 0) ? 'custo' : $i['estado'];
$nomes = ['ok' => t('cabe'), 'custo' => t('cabe, mas atrasa o empréstimo'), 'warn' => t('abaixo da reserva'), 'neg' => t('fica no vermelho'), 'fora' => t('as parcelas passam do plano')];
$icones = ['ok' => 'check', 'custo' => 'relogio', 'warn' => 'alert', 'neg' => 'x'];   // cada estado com um formato próprio
$sel = $prev['meses'][$mes] ?? null;
$presentes = array_unique(array_map($estadoDe, $prev['meses']));
$verMeses = isset($_GET['mes']) || isset($_GET['parcelas']);   // quem escolheu um mês ou uma forma de pagar continua vendo
?>
<div class="wl-prev" data-wl-previsao>
    <h3 class="wl-prev-t"><?= e(t('Quando comprar')) ?></h3>
    <?php if ($prev['melhor'] !== null): $cedo = $prev['meses'][$prev['cedo']]; $esperar = $prev['melhor'] !== $prev['cedo']; ?>
        <p class="wl-resposta"><?= t('Melhor mês: {link}', ['link' => '<a href="' . e($link($prev['melhor'], $parcelas)) . '" data-wl-mes>' . e($rotuloMes($prev['melhor'])) . '</a>']) ?></p>
        <p class="wl-resposta-2"><?= e($prev['temDivida'] && $prev['semCusto'] !== null ? t('A conta fica acima da reserva e o empréstimo não atrasa.') : ($prev['temDivida'] ? t('É o primeiro que cabe, mas qualquer compra atrasa o empréstimo neste plano.') : t('A conta fica acima da reserva.'))) ?></p>
        <?php if ($esperar): $linkCedo = '<a href="' . e($link($prev['cedo'], $parcelas)) . '" data-wl-mes>' . ($prev['cedo'] === $agora ? e(t('dá para comprar agora')) : e(t('dá em {mes}', ['mes' => $rotuloMes($prev['cedo'])]))) . '</a>'; ?>
            <p class="wl-resposta-2"><?= $cedo['custo'] >= 1 ? t('Sem esperar: {link}, com {valor} de juros a mais.', ['link' => $linkCedo, 'valor' => moedaSimbolo() . NBSP . e(num((float) $cedo['custo'], 0))]) : t('Sem esperar: {link}.', ['link' => $linkCedo]) ?></p>
        <?php endif; ?>
    <?php else: ?>
        <p class="wl-resposta"><?= e(t('Não cabe até {mes}', ['mes' => $rotuloMes($prev['ultimo'])])) ?></p>
        <?php if ($prev['menorFalta'] !== null): ?>
            <p class="wl-resposta-2"><?= t('O mais perto é {link}: faltariam {valor} para a conta não ficar abaixo da reserva.', ['link' => '<a href="' . e($link($prev['menorFalta']['mes'], $parcelas)) . '" data-wl-mes>' . e($rotuloMes($prev['menorFalta']['mes'])) . '</a>', 'valor' => e(money($prev['menorFalta']['valor']))]) ?></p>
        <?php endif; ?>
    <?php endif; ?>

    <details class="wl-detalhes"<?= $verMeses ? ' open' : '' ?>>
    <summary><?= e(t('Ver mês a mês')) ?></summary>
    <div class="wl-pagar" role="group" aria-label="<?= e(t('Como pagar')) ?>">
        <?php foreach (WishlistPrevisao::PARCELAS as $p): ?>
            <a class="wl-pagar-op<?= $p === $parcelas ? ' is-on' : '' ?>" href="<?= e('/wishlist?' . http_build_query(['parcelas' => $p])) ?>" data-wl-mes<?= $p === $parcelas ? ' aria-current="true"' : '' ?>><?= $p === 1 ? e(t('À vista')) : $p . '×' ?></a>
        <?php endforeach; ?>
    </div>

    <ol class="wl-trilho" aria-label="<?= e(t('Meses para comprar')) ?>">
        <?php foreach ($prev['meses'] as $m => $info): $m = (string) $m; $primeiro = $m === $agora || substr($m, 5, 2) === '01'; $est = $estadoDe($info); ?>
            <li><a class="wl-mes is-<?= e($est) ?><?= $m === $mes ? ' is-sel' : '' ?><?= $m === $prev['melhor'] ? ' is-melhor' : '' ?>" href="<?= e($link($m, $parcelas)) ?>" data-wl-mes<?= $m === $mes ? ' aria-current="true"' : '' ?> aria-label="<?= e($m === $prev['melhor'] ? t('{mes}, melhor mês: {estado}', ['mes' => monthLabel($m), 'estado' => $nomes[$est]]) : monthLabel($m) . ': ' . $nomes[$est]) ?>">
                <span class="wl-mes-n"><?= e(mesCurto($m)) ?></span>
                <span class="wl-mes-s" aria-hidden="true"><?= $est === 'fora' ? '–' : svgIcon($icones[$est], 15) ?></span>
                <span class="wl-mes-a num"><?= $primeiro ? e(substr($m, 0, 4)) : '&nbsp;' ?></span>
            </a></li>
        <?php endforeach; ?>
    </ol>
    <ul class="wl-legenda" aria-hidden="true">
        <?php foreach (['ok', 'custo', 'warn', 'neg', 'fora'] as $e): if (in_array($e, $presentes, true)): ?>
            <li class="is-<?= $e ?>"><span><?= $e === 'fora' ? '–' : svgIcon($icones[$e], 13) ?></span><?= e(ucfirst($nomes[$e])) ?></li>
        <?php endif; endforeach; ?>
    </ul>

    <?php if ($sel !== null): ?>
        <div class="wl-mes-det" aria-live="polite">
            <?php if ($sel['estado'] === 'fora'): ?>
                <p><?= e(t('Comprando em {mes}, alguma das {n} parcelas cairia depois de {ultimo}, o fim do plano.', ['mes' => $rotuloMes($mes), 'n' => (int) $parcelas, 'ultimo' => $rotuloMes($prev['ultimo'])])) ?></p>
            <?php else: ?>
                <p class="wl-det-t"><?= e($parcelas > 1 ? t('Comprando em {mes}, em {n}×', ['mes' => $rotuloMes($mes), 'n' => (int) $parcelas]) : t('Comprando em {mes}', ['mes' => $rotuloMes($mes)])) ?></p>
                <dl>
                    <div><dt><?= e(t('Mês mais apertado')) ?></dt><dd class="num<?= $sel['pior']['fica'] < -0.004 ? ' neg' : ($sel['estado'] !== 'ok' ? ' warn' : '') ?>"><?= e(money((float) $sel['pior']['fica'])) ?><small><?= e($rotuloMes($sel['pior']['mes'])) ?></small></dd></div>
                    <?php if ($prev['temDivida']): ?>
                        <div><dt><?= e(t('Empréstimo termina em')) ?></dt><dd class="num"><?= $sel['quita'] !== null ? e($rotuloMes($sel['quita'])) : e(t('sem data')) ?><?php if ($sel['atraso'] > 0): ?><small><?= e(tn((int) $sel['atraso'], '1 mês depois', '{n} meses depois')) ?></small><?php endif; ?></dd></div>
                        <div><dt><?= e(t('Juros a mais')) ?></dt><dd class="num"><?= $sel['custo'] >= 1 ? moedaSimbolo() . NBSP . e(num((float) $sel['custo'], 0)) : e(t('nenhum')) ?></dd></div>
                    <?php endif; ?>
                </dl>
                <?php if ($parcelas === 1 && $sel['estado'] === 'ok'): ?>
                    <a class="wl-ver-anual" href="<?= e('/futuro?' . http_build_query(['se' => 1, 'uma' => numInput($prev['valor']), 'uma_tipo' => 'gasto', 'uma_mes' => $mes, 'adiantar' => 1])) ?>"><?= e(t('Simular esta compra')) ?></a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    </details>
    <p class="wl-prev-nota"><?= e(t('É uma estimativa com os dados de hoje. Compras parceladas: sem juros, a partir da fatura seguinte.')) ?><?php if (($itens[0]['moeda'] ?? Site::moeda()) !== Site::moeda()): ?> <?= e(t('O valor em {moeda} muda com a cotação.', ['moeda' => moedaPlural()])) ?><?php endif; ?></p>
</div>
