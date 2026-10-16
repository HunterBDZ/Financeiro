<?php
/**
 * Editar uma compra no cartão: descrição, categoria, valor total, vezes, data e a fatura da 1ª
 * parcela (é ela que decide em que faturas as parcelas caem). Excluir fica aqui, em vermelho.
 * Variáveis: $compra, $cartao, $meses (faturas possíveis para a 1ª parcela), $categorias, $erro, $aberta.
 */
$c = $compra;
$erro = is_array($erro ?? null) ? (string) ($erro['msg'] ?? '') : '';
$ps = \Financeiro\Services\CartaoFatura::parcelas((float) $c['valor_total'], (int) $c['parcelas'], (string) $c['primeira_fatura']);
$ultima = $ps[count($ps) - 1][0];
?>
<div class="ct ct-editar">
    <header class="lf-topo ct-ed-topo">
        <a class="lf-x lf-voltar" href="/cartao?fatura=<?= e($c['primeira_fatura']) ?>#fatura" aria-label="voltar"><?= svgIcon('chevron', 20, 'lf-seta') ?></a>
        <h1 class="lf-titulo">Compra no cartão</h1>
    </header>
    <p class="ct-escada-sub num"><?= (int) $c['parcelas'] === 1 ? 'À vista, na fatura de ' . e(monthLabel($c['primeira_fatura'])) : (int) $c['parcelas'] . ' × ' . e(num($ps[count($ps) - 1][2])) . ', de ' . e(monthLabel($c['primeira_fatura'])) . ' a ' . e(monthLabel($ultima)) ?></p>
    <form class="card ct-form" method="post" action="/cartao/compras/<?= (int) $c['id'] ?>">
        <?= csrfField() ?>
        <?php if ($erro !== ''): ?><p class="aviso aviso-error" role="alert"><?= e($erro) ?></p><?php endif; ?>
        <label><span>O que foi</span><input type="text" name="descricao" maxlength="255" value="<?= e($c['descricao']) ?>"></label>
        <label><span>Categoria</span>
            <select name="categoria_id" required>
                <?php foreach ($categorias as $cat): ?>
                    <option value="<?= (int) $cat['id'] ?>"<?= (int) $cat['id'] === (int) ($c['categoria_id'] ?? 0) ? ' selected' : '' ?>><?= e(nomeCurto($cat)) ?></option>
                <?php endforeach; ?>
            </select></label>
        <div class="ct-2">
            <label><span>Valor total</span><input type="text" name="valor" inputmode="decimal" required value="<?= e(numInput((float) $c['valor_total'])) ?>"></label>
            <label><span>Em quantas vezes</span><input type="text" name="parcelas" inputmode="numeric" maxlength="2" required value="<?= (int) $c['parcelas'] ?>"></label>
        </div>
        <div class="ct-2">
            <label><span>Data da compra</span><input type="date" name="data" required value="<?= e($c['data']) ?>"></label>
            <label><span>Fatura da 1ª parcela</span>
                <select name="primeira_fatura">
                    <?php foreach ($meses as $m): ?>
                        <option value="<?= e($m) ?>"<?= $m === $c['primeira_fatura'] ? ' selected' : '' ?>><?= e(monthLabel($m)) ?><?= $m === $aberta ? ' (aberta)' : '' ?></option>
                    <?php endforeach; ?>
                </select></label>
        </div>
        <button class="btn btn-primary" type="submit">Salvar</button>
    </form>
    <form method="post" action="/cartao/compras/<?= (int) $c['id'] ?>/excluir" class="ct-excluir" data-confirm="Excluir a compra <?= e($c['descricao']) ?>?">
        <?= csrfField() ?><input type="hidden" name="volta" value="/cartao?fatura=<?= e($c['primeira_fatura']) ?>#fatura">
        <button type="submit" class="btn-link-danger">Excluir compra</button>
    </form>
</div>
