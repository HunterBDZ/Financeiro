<?php
/**
 * Editar uma transferência entre contas: de, para, valor, dia e descrição. Excluir fica aqui, em vermelho.
 * Num mês fechado, só para ler: o aviso diz como reabrir, Salvar fica desligado e Excluir some.
 * Variáveis: $transf, $contas (as ativas e as da própria transferência), $erro, $volta, $fechado (mensagem ou null).
 */
$tr = $transf;
?>
<div class="ct ct-editar">
    <header class="lf-topo ct-ed-topo">
        <a class="lf-x lf-voltar" href="<?= e($volta) ?>" aria-label="<?= e(t('voltar')) ?>"><?= svgIcon('chevron', 20, 'lf-seta') ?></a>
        <h1 class="lf-titulo"><?= e(t('Transferência')) ?></h1>
    </header>
    <p class="ct-escada-sub"><?= e(t('Não é gasto nem entrada: muda só o saldo de cada conta.')) ?></p>
    <form class="card ct-form" method="post" action="/transferencias/<?= (int) $tr['id'] ?>">
        <?= csrfField() ?>
        <input type="hidden" name="volta" value="<?= e($volta) ?>">
        <?php if ($erro !== null): ?><p class="aviso aviso-error" role="alert"><?= e($erro) ?></p><?php endif; ?>
        <?php if (($fechado ?? null) !== null): ?><p class="aviso aviso-warn" role="status"><?= e($fechado) ?> <a class="link" href="/mes?mes=<?= e(substr((string) $tr['data'], 0, 8) . '01') ?>"><?= e(t('Abrir o Mês')) ?></a></p><?php endif; ?>
        <div class="ct-2">
            <label><span><?= e(t('De')) ?></span>
                <select name="de_conta">
                    <?php foreach ($contas as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $c['id'] === (int) $tr['de_conta_id'] ? ' selected' : '' ?>><?= e(nomeConta($c)) ?></option><?php endforeach; ?>
                </select></label>
            <label><span><?= e(t('Para')) ?></span>
                <select name="para_conta">
                    <?php foreach ($contas as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $c['id'] === (int) $tr['para_conta_id'] ? ' selected' : '' ?>><?= e(nomeConta($c)) ?></option><?php endforeach; ?>
                </select></label>
        </div>
        <div class="ct-2">
            <label><span><?= e(t('Valor')) ?></span><input type="text" name="valor" inputmode="decimal" required value="<?= e(numInput((float) $tr['valor'])) ?>"></label>
            <label><span><?= e(t('Dia')) ?></span><input type="date" name="data" required value="<?= e((string) $tr['data']) ?>"></label>
        </div>
        <label><span><?= e(t('Descrição (opcional)')) ?></span><input type="text" name="descricao" maxlength="255" value="<?= e((string) $tr['descricao']) ?>"></label>
        <button class="btn btn-primary" type="submit"<?= ($fechado ?? null) !== null ? ' disabled' : '' ?>><?= e(t('Salvar')) ?></button>
    </form>
    <?php if (($fechado ?? null) === null): ?>
    <form method="post" action="/transferencias/<?= (int) $tr['id'] ?>/excluir" class="ct-excluir" data-confirm="<?= e(t('Excluir esta transferência?')) ?>">
        <?= csrfField() ?><input type="hidden" name="volta" value="<?= e($volta) ?>">
        <button type="submit" class="btn-link-danger"><?= e(t('Excluir transferência')) ?></button>
    </form>
    <?php endif; ?>
</div>
