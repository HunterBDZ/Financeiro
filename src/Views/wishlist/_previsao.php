<?php
/**
 * Quando comprar o 1º item (também é a resposta de ?parcial=1): como pagar, a resposta em uma frase, a
 * fileira de meses (o estado vem com símbolo e rótulo, nunca só com cor) e o que acontece no mês escolhido.
 * Variáveis: $itens, $prev (WishlistPrevisao::calcular ou null), $mes, $parcelas.
 */
use Financeiro\Services\WishlistPrevisao;

if ($prev === null) {
    if ($itens !== [] && $itens[0]['valor'] === null): ?>
        <p class="wl-sem-valor">Informe o valor para ver em que mês ele cabe. <a href="/wishlist/<?= (int) $itens[0]['id'] ?>/editar">Informar o valor</a></p>
    <?php elseif ($itens !== [] && $itens[0]['valor_brl'] === null): ?>
        <p class="wl-sem-valor">Sem cotação de <?= e((string) $itens[0]['moeda']) ?> agora, então não dá para prever. Tente de novo mais tarde.</p>
    <?php endif;
    return;
}
$rotuloMes = static fn(string $m): string => monthLabel($m);
$link = static fn(string $m, int $p): string => '/wishlist?' . http_build_query(['mes' => $m, 'parcelas' => $p]);
$agora = (string) array_key_first($prev['meses']);
// "custo": cabe, mas atrasa o empréstimo. É o que separa comprar logo de esperar.
$estadoDe = static fn(array $i): string => $i['estado'] === 'ok' && ($i['custo'] >= 1 || $i['atraso'] > 0) ? 'custo' : $i['estado'];
$nomes = ['ok' => 'cabe', 'custo' => 'cabe, mas atrasa o empréstimo', 'warn' => 'abaixo da reserva', 'neg' => 'fica no vermelho', 'fora' => 'as parcelas passam do plano'];
$icones = ['ok' => 'check', 'custo' => 'check', 'warn' => 'alert', 'neg' => 'x'];
$sel = $prev['meses'][$mes] ?? null;
$presentes = array_unique(array_map($estadoDe, $prev['meses']));
?>
<div class="wl-prev" data-wl-previsao>
    <h3 class="wl-prev-t">Quando comprar</h3>
    <?php if ($prev['melhor'] !== null): $cedo = $prev['meses'][$prev['cedo']]; $esperar = $prev['melhor'] !== $prev['cedo']; ?>
        <p class="wl-resposta">Melhor mês: <a href="<?= e($link($prev['melhor'], $parcelas)) ?>" data-wl-mes><?= e($rotuloMes($prev['melhor'])) ?></a></p>
        <p class="wl-resposta-2"><?= $prev['temDivida'] && $prev['semCusto'] !== null ? 'A conta fica acima da reserva e o empréstimo não atrasa.' : ($prev['temDivida'] ? 'É o primeiro que cabe, mas qualquer compra atrasa o empréstimo neste plano.' : 'A conta fica acima da reserva.') ?></p>
        <?php if ($esperar): ?>
            <p class="wl-resposta-2">Sem esperar: <a href="<?= e($link($prev['cedo'], $parcelas)) ?>" data-wl-mes><?= $prev['cedo'] === $agora ? 'dá para comprar agora' : 'dá em ' . e($rotuloMes($prev['cedo'])) ?></a><?= $cedo['custo'] >= 1 ? ', com R$' . NBSP . e(num((float) $cedo['custo'], 0)) . ' de juros a mais' : '' ?>.</p>
        <?php endif; ?>
    <?php else: ?>
        <p class="wl-resposta">Não cabe até <?= e($rotuloMes($prev['ultimo'])) ?></p>
        <?php if ($prev['menorFalta'] !== null): ?>
            <p class="wl-resposta-2">O mais perto é <a href="<?= e($link($prev['menorFalta']['mes'], $parcelas)) ?>" data-wl-mes><?= e($rotuloMes($prev['menorFalta']['mes'])) ?></a>: faltariam <?= e(money($prev['menorFalta']['valor'])) ?> para a conta não ficar abaixo da reserva.</p>
        <?php endif; ?>
    <?php endif; ?>

    <div class="wl-pagar" role="group" aria-label="Como pagar">
        <?php foreach (WishlistPrevisao::PARCELAS as $p): ?>
            <a class="wl-pagar-op<?= $p === $parcelas ? ' is-on' : '' ?>" href="<?= e('/wishlist?' . http_build_query(['parcelas' => $p])) ?>" data-wl-mes<?= $p === $parcelas ? ' aria-current="true"' : '' ?>><?= $p === 1 ? 'À vista' : $p . '×' ?></a>
        <?php endforeach; ?>
    </div>

    <ol class="wl-trilho" aria-label="Meses para comprar">
        <?php foreach ($prev['meses'] as $m => $info): $m = (string) $m; $primeiro = $m === $agora || substr($m, 5, 2) === '01'; $est = $estadoDe($info); ?>
            <li><a class="wl-mes is-<?= e($est) ?><?= $m === $mes ? ' is-sel' : '' ?><?= $m === $prev['melhor'] ? ' is-melhor' : '' ?>" href="<?= e($link($m, $parcelas)) ?>" data-wl-mes<?= $m === $mes ? ' aria-current="true"' : '' ?> aria-label="<?= e(monthLabel($m) . ($m === $prev['melhor'] ? ', melhor mês: ' : ': ') . $nomes[$est]) ?>">
                <span class="wl-mes-n"><?= e(mesCurto($m)) ?></span>
                <span class="wl-mes-s" aria-hidden="true"><?= $est === 'fora' ? '–' : svgIcon($icones[$est], 15) ?></span>
                <span class="wl-mes-a num"><?= $primeiro ? e(substr($m, 2, 2)) : '&nbsp;' ?></span>
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
                <p>Comprando em <?= e($rotuloMes($mes)) ?>, alguma das <?= (int) $parcelas ?> parcelas cairia depois de <?= e($rotuloMes($prev['ultimo'])) ?>, o fim do plano.</p>
            <?php else: ?>
                <p class="wl-det-t">Comprando em <?= e($rotuloMes($mes)) ?><?= $parcelas > 1 ? ', em ' . (int) $parcelas . '×' : '' ?></p>
                <dl>
                    <div><dt>Mês mais apertado</dt><dd class="num<?= $sel['pior']['fica'] < -0.004 ? ' neg' : ($sel['estado'] !== 'ok' ? ' warn' : '') ?>"><?= e(money((float) $sel['pior']['fica'])) ?><small><?= e($rotuloMes($sel['pior']['mes'])) ?></small></dd></div>
                    <?php if ($prev['temDivida']): ?>
                        <div><dt>Empréstimo termina em</dt><dd class="num"><?= $sel['quita'] !== null ? e($rotuloMes($sel['quita'])) : 'sem data' ?><?php if ($sel['atraso'] > 0): ?><small><?= $sel['atraso'] === 1 ? '1 mês depois' : (int) $sel['atraso'] . ' meses depois' ?></small><?php endif; ?></dd></div>
                        <div><dt>Juros a mais</dt><dd class="num"><?= $sel['custo'] >= 1 ? 'R$' . NBSP . e(num((float) $sel['custo'], 0)) : 'nenhum' ?></dd></div>
                    <?php endif; ?>
                </dl>
                <?php if ($parcelas === 1 && $sel['estado'] === 'ok'): ?>
                    <a class="wl-ver-anual" href="<?= e('/futuro?' . http_build_query(['se' => 1, 'uma' => numInput($prev['valor']), 'uma_tipo' => 'gasto', 'uma_mes' => $mes, 'adiantar' => 1])) ?>">Ver no Anual</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <p class="wl-prev-nota">Usa o plano de hoje e muda junto com ele. Nada é gravado. Parcelado, sem juros, da fatura do mês seguinte em diante.<?php if (($itens[0]['moeda'] ?? 'BRL') !== 'BRL'): ?> O valor em reais muda com a cotação.<?php endif; ?></p>
</div>
