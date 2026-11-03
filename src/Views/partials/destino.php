<?php
/**
 * A barra "para onde vai o dinheiro do mês" (o momento do Início e do Mês): uma barra com um segmento por destino,
 * proporcional ao valor, e a legenda com cor, nome e valor (5 colunas no computador; linhas com fio no celular).
 * A cor vai do fato ao previsto, e o azul só marca a resposta. Uso:
 *   View::partial('partials/destino', ['titulo' => t('Para onde vai o dinheiro de {mes}', [...]), 'segmentos' => [
 *       ['rotulo' => t('Já saiu'), 'valor' => 908.98, 'classe' => 's-saiu'],
 *       ['rotulo' => t('Contas a pagar'), 'valor' => 4908.20, 'classe' => 's-contas'],
 *       ['rotulo' => t('Metas que faltam'), 'valor' => 2371.02, 'classe' => 's-metas'],
 *       ['rotulo' => t('Dá para gastar'), 'valor' => 2398.66, 'classe' => 's-gastar', 'destaque' => true],
 *       ['rotulo' => t('Reserva'), 'valor' => 1500.00, 'classe' => 's-reserva'],
 *   ]]);
 * Variáveis: $segmentos (rótulo, valor >= 0, classe: s-saiu|s-contas|s-metas|s-gastar|s-reserva, destaque opcional);
 * $titulo (opcional); $total (opcional; sem ele, a soma); $nivel (opcional, 'h2' ou 'h3', o nível do título).
 * Segmento sem valor (zero ou negativo) não entra na barra, mas fica na legenda; o negativo (a falta, quando o mês fecha
 * no vermelho) aparece com "−" em vermelho, e a legenda soma o total.
 */
$segmentos = array_values(array_filter((array) ($segmentos ?? []), 'is_array'));
$titulo = (string) ($titulo ?? '');
$nivel = ($nivel ?? 'h2') === 'h3' ? 'h3' : 'h2';
$total = isset($total) ? (float) $total : array_sum(array_map(static fn(array $s): float => max(0.0, (float) ($s['valor'] ?? 0)), $segmentos));
$partes = [];
foreach ($segmentos as $s) {
    $partes[] = t('{nome} {valor}', ['nome' => mb_strtolower((string) ($s['rotulo'] ?? '')), 'valor' => num((float) ($s['valor'] ?? 0))]);
}
$descricao = t('De {total}: {partes}.', ['total' => num($total), 'partes' => implode('; ', $partes)]);
?>
<div class="destino">
    <?php if ($titulo !== ''): ?>
        <div class="destino-cab"><<?= $nivel ?>><?= e($titulo) ?></<?= $nivel ?>><span class="num"><?= e(num($total)) ?></span></div>
    <?php endif; ?>
    <div class="barra" role="img" aria-label="<?= e($descricao) ?>">
        <?php foreach ($segmentos as $s):
            $v = round((float) ($s['valor'] ?? 0), 2);
            if ($v <= 0) {
                continue;
            } ?>
            <span class="<?= e((string) ($s['classe'] ?? '')) ?>" style="--v:<?= e(number_format($v, 2, '.', '')) ?>" title="<?= e(t('{nome}: {valor}', ['nome' => (string) ($s['rotulo'] ?? ''), 'valor' => num($v)])) ?>"></span>
        <?php endforeach; ?>
    </div>
    <ul class="legenda">
        <?php foreach ($segmentos as $s): ?>
            <li<?= !empty($s['destaque']) ? ' class="destaque"' : '' ?>><i class="<?= e((string) ($s['classe'] ?? '')) ?>" aria-hidden="true"></i><span class="rot"><?= e((string) ($s['rotulo'] ?? '')) ?></span><span class="val num<?= (float) ($s['valor'] ?? 0) < -0.004 ? ' alerta' : '' ?>"><?= e(num((float) ($s['valor'] ?? 0))) ?></span></li>
        <?php endforeach; ?>
    </ul>
</div>
