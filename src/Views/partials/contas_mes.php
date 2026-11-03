<?php
/**
 * "Contas do mês": as contas fixas do mês de hoje ainda não pagas, no Início e no Extrato. Uso:
 *   View::partial('partials/contas_mes', ['contas' => ChecklistContas::fixasAPagar(), 'volta' => '/', 'hoje' => $hoje]);
 * Cada conta: ícone, nome, vencimento ("vence dia 10"; a vencida em âmbar), valor previsto e o botão Pago, que lança
 * o valor previsto com a data de hoje (POST /lancamentos/pago, sem JS também). O nome abre o Lançar com a categoria,
 * para outro valor. As 3 primeiras ficam à vista e o resto em "Mais N contas", para o bloco não empurrar a tela.
 * Sem conta a pagar, o bloco não aparece.
 */
$contas = is_array($contas ?? null) ? $contas : [];
if ($contas === []) {
    return;
}
$volta   = voltaSegura((string) ($volta ?? '/'), '/');
$hoje    = (string) ($hoje ?? \Financeiro\Relogio::hoje());
$csrf    = csrfField();
$visiveis = 3;

$linha = static function (array $c) use ($volta, $hoje, $csrf): void {
    $id   = (int) $c['categoria_id'];
    $nome = nomeCurto($c);
    $vence = $c['vencimento'] !== null ? (string) $c['vencimento'] : null;
    $quando = match (true) {
        $vence === null => '',
        $vence === $hoje => t('vence hoje'),
        $c['atrasado'] => t('venceu dia {dia}', ['dia' => (int) substr($vence, 8, 2)]),
        // venceu num dia que não é útil: ainda dá para pagar sem atraso, como no Mês
        $vence < $hoje => t('vence {data} · paga até {util}', ['data' => diaMes($vence), 'util' => diaMes((string) $c['paga_ate'])]),
        default => t('vence dia {dia}', ['dia' => (int) substr($vence, 8, 2)]),
    }; ?>
    <li class="cm-i<?= $c['atrasado'] ? ' is-vencida' : '' ?>">
        <a class="cm-outro" href="/lancar?categoria=<?= $id ?>&amp;volta=<?= e(rawurlencode($volta)) ?>" data-lancar data-categoria="<?= $id ?>">
            <?= iconeCategoria($id, (string) $c['nome'], (string) $c['grupo'], 20, 'cm-ic') ?>
            <span class="cm-n">
                <span class="cm-l1"><b><?= e($nome) ?></b><span class="cm-v num"><?= e(num((float) $c['previsto'])) ?></span></span>
                <small><?php if ($quando !== ''): ?><span class="cm-vence"><?= e($quando) ?></span> · <?php endif; ?><span class="cm-outro-t"><?= e(t('outro valor')) ?></span></small>
            </span>
        </a>
        <form class="cm-f" method="post" action="/lancamentos/pago" data-espera="<?= e(t('Lançando…')) ?>">
            <?= $csrf ?>
            <input type="hidden" name="categoria_id" value="<?= $id ?>">
            <input type="hidden" name="volta" value="<?= e($volta) ?>">
            <button type="submit" class="btn btn-sm cm-pago"><?= svgIcon('check', 16) ?><?= e(t('Pago')) ?><span class="sr-only"> · <?= e($nome) ?></span></button>
        </form>
    </li>
<?php };
?>
<section class="card cm" id="contas-mes" aria-labelledby="cm-t">
    <div class="card-head">
        <h2 class="titulo" id="cm-t"><?= e(t('Contas do mês')) ?></h2>
        <span class="card-acao num"><?= e(tn(count($contas), '1 a pagar', '{n} a pagar')) ?></span>
    </div>
    <ul class="cm-lista">
        <?php foreach (array_slice($contas, 0, $visiveis) as $c) { $linha($c); } ?>
    </ul>
    <?php if (count($contas) > $visiveis): $resto = array_slice($contas, $visiveis); ?>
        <details class="cm-mais">
            <summary><?= e(tn(count($resto), 'Mais 1 conta', 'Mais {n} contas')) ?></summary>
            <ul class="cm-lista">
                <?php foreach ($resto as $c) { $linha($c); } ?>
            </ul>
        </details>
    <?php endif; ?>
</section>
