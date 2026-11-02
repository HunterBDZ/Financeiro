<?php
/**
 * Com mais de uma conta: um campo de saldo do banco para cada conta ativa (saldo_conta[id]), com o saldo do app na
 * data e a diferença enquanto se digita (app.js, [data-conf-app]). Usado no conferir do Início e no Fechar.
 * Variáveis: $contas (as ativas, cada uma com 'saldo' = o do app na data), $digitado (id => o que foi digitado),
 * $pref (prefixo dos ids), $invalido (a última tentativa voltou com erro), $confs (id => a última conferência, opcional).
 */
$digitado = $digitado ?? [];
$confs = $confs ?? [];
?>
<div class="conf-contas">
    <?php foreach ($contas as $i => $c): $id = (int) $c['id']; $campoId = $pref . '-conta-' . $id; $u = $confs[$id] ?? null; ?>
        <div class="conf-conta">
            <label for="<?= e($campoId) ?>"><span aria-hidden="true"><?= e(\Financeiro\Models\ContaModel::TIPOS[$c['tipo']] ?? '') ?></span> <?= e(nomeConta($c)) ?></label>
            <input id="<?= e($campoId) ?>" type="text" name="saldo_conta[<?= $id ?>]" inputmode="decimal" autocomplete="off" enterkeyhint="next"
                   placeholder="<?= e(t('ex.: 1.546,00')) ?>" value="<?= e((string) ($digitado[$id] ?? '')) ?>" data-conf-app="<?= e(number_format((float) $c['saldo'], 2, '.', '')) ?>"
                   aria-describedby="<?= e($campoId) ?>-app"<?= !empty($invalido) && $i === 0 ? ' aria-invalid="true" data-autofocus' : '' ?>>
            <small class="conf-app num" id="<?= e($campoId) ?>-app"><?= e(t('No app: {valor}', ['valor' => num((float) $c['saldo'])])) ?><?php if ($u !== null): ?> · <?= e($u['bate'] ? t('bateu em {data}', ['data' => diaMes($u['data'])]) : t('diferença de {dif} em {data}', ['dif' => moneySigned($u['dif']), 'data' => diaMes($u['data'])])) ?><?php endif; ?></small>
            <output class="conf-dif num" data-conf-dif aria-live="polite"></output>
        </div>
    <?php endforeach; ?>
</div>
