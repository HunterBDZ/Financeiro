<?php
/**
 * Ajustes (SPEC §2.8) — dono: P5. Tudo em <details class="dobra">, cada grupo com o seu SALVAR.
 * Todo value de dinheiro sai de numInput() (hífen ASCII): parseMoedaBr() lê de volta sem perder o sinal.
 */
$csrf = csrfField();
$ultLbl = $ultimo ? monthLabel($ultimo) : '';
$valorDe = static function (string $chave, int $dec = 2) use ($aj, $erro): string {
    if ($erro !== null && isset($erro['valores'][$chave]) && is_string($erro['valores'][$chave])) {
        return $erro['valores'][$chave];                     // devolve o que foi digitado
    }
    $v = $aj[$chave]['valor'] ?? 0.0;
    return $chave === 'juros_mes' ? numInput($v * 100) : numInput($v, $dec);
};
/** "Saldo da conta em 31/08/2026" → "31/08/2026" (a data mora no label do ajuste) */
$dataDoLabel = static function (string $chave) use ($aj): string {
    return preg_match('~\d{2}/\d{2}/\d{4}~', $aj[$chave]['label'] ?? '', $m) ? ' em ' . $m[0] : '';
};
$aberto = static fn(string $g): string => !empty($abertos[$g]) ? ' open' : '';
$erroDe = static function (string $g) use ($erro): void {
    if ($erro !== null && ($erro['grupo'] ?? '') === $g) {
        echo '<p class="aviso aviso-error">' . e((string) $erro['msg']) . '</p>';
    }
};
$campo = static function (string $chave, string $rotulo, string $un = '', int $dec = 2) use ($valorDe, $erro): void {
    $id = 'aj-' . $chave;
    $inval = $erro !== null && ($erro['campo'] ?? '') === $chave; ?>
    <div class="aj-campo">
        <label for="<?= e($id) ?>"><?= e($rotulo) ?></label>
        <span class="aj-input<?= $un !== '' ? ' tem-un' : '' ?>">
            <input id="<?= e($id) ?>" type="text" name="valores[<?= e($chave) ?>]" inputmode="<?= $dec === 0 ? 'numeric' : 'decimal' ?>" autocomplete="off"
                   value="<?= e($valorDe($chave, $dec)) ?>"<?= $inval ? ' aria-invalid="true" autofocus' : '' ?>>
            <?php if ($un !== ''): ?><span class="aj-un"><?= e($un) ?></span><?php endif; ?>
        </span>
    </div>
<?php };
?>
<div class="aj">
    <h1 class="aj-titulo">Ajustes</h1>

    <!-- 1: parâmetros -->
    <details class="card dobra aj-grupo" id="parametros"<?= $aberto('parametros') ?>>
        <summary><span class="titulo">Parâmetros</span></summary>
        <form class="aj-form" method="post" action="/ajustes/salvar">
            <?= $csrf ?><input type="hidden" name="grupo" value="parametros">
            <?php $campo('reserva_minima', 'Reserva mínima'); ?>
            <?php $campo('meta_final', 'Meta de conta e poupança' . ($ultLbl !== '' ? ' até ' . $ultLbl : '')); ?>
            <?php $campo('juros_mes', 'Juros ao mês', '%'); ?>
            <div class="aj-extra">
                <label class="aj-check"><input type="checkbox" name="confirmado[juros_mes]" value="1"<?= !empty($aj['juros_mes']['confirmado']) ? ' checked' : '' ?>><span>Confirmado</span></label>
                <?php if ($taxa !== null): ?><span class="aj-taxa num">Pelo crédito recebido: <?= e(num($taxa * 100)) ?>%</span><?php endif; ?>
            </div>
            <?php $campo('parcela_mensal', 'Parcela'); ?>
            <?php $campo('n_parcelas', 'Número de parcelas', '', 0); ?>
            <?php
            $ini = (int) round($aj['consignado_inicio']['valor'] ?? 0);
            $iniTxt = $erro !== null && is_string($erro['valores']['consignado_inicio'] ?? null)
                ? $erro['valores']['consignado_inicio']
                : ($ini > 0 ? sprintf('%04d-%02d', intdiv($ini, 100), $ini % 100) : '');
            ?>
            <div class="aj-campo">
                <label for="aj-consignado_inicio">1ª parcela descontada em</label>
                <span class="aj-input aj-input-mes"><input id="aj-consignado_inicio" type="month" name="valores[consignado_inicio]" value="<?= e($iniTxt) ?>"<?= $erro !== null && ($erro['campo'] ?? '') === 'consignado_inicio' ? ' aria-invalid="true" autofocus' : '' ?>></span>
            </div>
            <p class="aj-ajuda">A parcela só abate a dívida e o soldo a partir desse mês. Em branco, vale desde o início do plano.</p>
            <?php $erroDe('parametros'); ?>
            <button type="submit" class="btn btn-primary aj-salvar">Salvar</button>
        </form>
    </details>

    <!-- 2: ponto de partida -->
    <details class="card dobra aj-grupo" id="partida"<?= $aberto('partida') ?>>
        <summary><span class="titulo">Ponto de partida</span></summary>
        <form class="aj-form" method="post" action="/ajustes/salvar">
            <?= $csrf ?><input type="hidden" name="grupo" value="partida">
            <?php $campo('saldo_ini', 'Conta' . $dataDoLabel('saldo_ini')); ?>
            <?php $campo('poupanca_ini', 'Poupança' . $dataDoLabel('poupanca_ini')); ?>
            <?php $campo('recebido_emprestimo', 'Crédito recebido'); ?>
            <?php $erroDe('partida'); ?>
            <button type="submit" class="btn btn-primary aj-salvar">Salvar</button>
        </form>
    </details>

    <!-- 3: contas recorrentes -->
    <details class="card dobra aj-grupo" id="contas"<?= $aberto('contas') ?>>
        <summary><span class="titulo">Contas recorrentes</span></summary>
        <?php if ($recorrentes !== []): ?>
            <ul class="aj-contas">
                <?php foreach ($recorrentes as $r): ?>
                    <li>
                        <span class="aj-conta-nome"><?= e((string) $r['categoria_nome']) ?></span>
                        <span class="aj-conta-dia num">dia <?= e((string) (int) $r['dia_vencimento']) ?></span>
                        <form method="post" action="/ajustes/contas/<?= e((string) (int) $r['id']) ?>/remover">
                            <?= $csrf ?>
                            <button type="submit" class="aj-x" aria-label="remover <?= e((string) $r['categoria_nome']) ?>"><?= svgIcon('x', 16) ?></button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ($elegiveis !== []): ?>
        <form class="aj-nova" method="post" action="/ajustes/contas">
            <?= $csrf ?>
            <select name="categoria_id" aria-label="conta" required>
                <?php $grupoAtual = null; foreach ($elegiveis as $c): ?>
                    <?php if ($c['grupo'] !== $grupoAtual): ?>
                        <?= $grupoAtual !== null ? '</optgroup>' : '' ?><optgroup label="<?= e(grupoLabel($c['grupo'])) ?>">
                        <?php $grupoAtual = $c['grupo']; ?>
                    <?php endif; ?>
                    <option value="<?= e((string) $c['id']) ?>"><?= e($c['nome']) ?></option>
                <?php endforeach; ?>
                <?= $grupoAtual !== null ? '</optgroup>' : '' ?>
            </select>
            <label class="aj-dia"><span>dia</span><input type="text" name="dia_vencimento" inputmode="numeric" pattern="[0-9]*" maxlength="2" autocomplete="off" required aria-label="dia do vencimento"></label>
            <button type="submit" class="btn btn-ghost">Adicionar</button>
        </form>
        <?php endif; ?>
        <?php $erroDe('contas'); ?>
    </details>

    <!-- 4: essenciais -->
    <details class="card dobra aj-grupo" id="essenciais"<?= $aberto('essenciais') ?>>
        <summary><span class="titulo">Essenciais</span></summary>
        <form class="aj-form" method="post" action="/ajustes/essenciais">
            <?= $csrf ?>
            <?php $grupoAtual = null; foreach ($gastos as $c): ?>
                <?php if ($c['grupo'] !== $grupoAtual): ?>
                    <?= $grupoAtual !== null ? '</div>' : '' ?>
                    <p class="aj-ess-grupo"><?= e(grupoLabel((string) $c['grupo'])) ?></p>
                    <div class="aj-ess">
                    <?php $grupoAtual = $c['grupo']; ?>
                <?php endif; ?>
                <label class="chip-r"><input type="checkbox" name="essencial[]" value="<?= e((string) $c['id']) ?>"<?= (int) $c['essencial'] === 1 ? ' checked' : '' ?>><span><?= e(nomeCurto($c)) ?></span></label>
            <?php endforeach; ?>
            <?= $grupoAtual !== null ? '</div>' : '' ?>
            <button type="submit" class="btn btn-primary aj-salvar">Salvar</button>
        </form>
    </details>

    <!-- 5: aparelhos -->
    <details class="card dobra aj-grupo" id="aparelhos"<?= $aberto('aparelhos') ?>>
        <summary><span class="titulo">Aparelhos</span></summary>
        <form method="post" action="/ajustes/aparelhos/sair" class="aj-form" data-confirm="Sair de todos os aparelhos? Cada um vai pedir login de novo.">
            <?= $csrf ?>
            <button type="submit" class="btn btn-ghost aj-sair"><?= svgIcon('logout', 16) ?>Sair de todos os aparelhos</button>
        </form>
    </details>

    <!-- 6: senha -->
    <details class="card dobra aj-grupo" id="senha"<?= $aberto('senha') ?>>
        <summary><span class="titulo">Senha</span></summary>
        <form class="aj-form" method="post" action="/ajustes/senha">
            <?= $csrf ?>
            <input type="text" name="username" value="<?= e((string) ($login ?? '')) ?>" autocomplete="username" hidden>
            <label class="campo"><span>Senha atual</span><input type="password" name="senha_atual" autocomplete="current-password" required></label>
            <label class="campo"><span>Nova senha</span><input type="password" name="senha_nova" autocomplete="new-password" minlength="6" required></label>
            <label class="campo"><span>Repita a nova senha</span><input type="password" name="senha_repetir" autocomplete="new-password" minlength="6" required></label>
            <?php $erroDe('senha'); ?>
            <button type="submit" class="btn btn-primary aj-salvar">Trocar senha</button>
        </form>
    </details>
</div>
