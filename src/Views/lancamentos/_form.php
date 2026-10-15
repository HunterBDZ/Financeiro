<?php
/**
 * Formulário de lançar/editar (P4, SPEC §2.2). Simplificado: dropdown de categoria,
 * calendário para data, descrição, botão lançar no final.
 */
$modo   = in_array($modo ?? '', ['dialog', 'pagina', 'editar'], true) ? $modo : 'dialog';
$pref   = preg_replace('/[^a-z0-9]/', '', (string) ($pref ?? 'lf')) ?: 'lf';
$editar = $modo === 'editar';
$l      = is_array($lancamento ?? null) ? $lancamento : null;
$a      = is_array($antigo ?? null) ? $antigo : [];
$topo   = $topo ?? true;
$volta  = voltaSegura((string) ($volta ?? '/'), '/');
$chips  = $chips ?? [];
$outras = $outras ?? [];
$hoje   = (string) ($hoje ?? date('Y-m-d'));

$erro = isset($a['erro']) && is_string($a['erro']) ? $a['erro'] : null;
$dup  = isset($a['duplicado']) && is_array($a['duplicado']) ? $a['duplicado'] : null;

$valorTxt = '';
if (isset($a['valor']) && $a['valor'] !== '') {
    $p = parseMoedaBr((string) $a['valor']);
    $valorTxt = $p !== null && abs($p) > 0 ? numInput(abs($p)) : (string) $a['valor'];
} elseif ($l !== null) {
    $valorTxt = numInput((float) $l['valor']);
}

$catSel = (int) ($a['categoria_id'] ?? ($l['categoria_id'] ?? 0));
$data   = (string) (($a['data'] ?? '') !== '' ? $a['data'] : ($l['data'] ?? $hoje));
$desc   = (string) ($a['descricao'] ?? ($l['descricao'] ?? ''));
if (!isset($a['descricao']) && $l !== null && $desc === (string) ($l['categoria_nome'] ?? '')) {
    $desc = '';
}

// grupos do select por classe (entradas, essenciais, não essenciais, ⇄), já ordenados no LancarForm
$gruposSel = $outras;

$acao   = $editar && $l !== null ? '/lancamentos/' . (int) $l['id'] : '/lancamentos';
$titulo = $editar ? 'editar' : 'novo lançamento';
$botao  = $editar ? 'salvar' : ($dup !== null ? 'lançar mesmo assim' : 'lançar');
$foco   = $modo === 'pagina' && $topo !== false;
?>
<form class="lf lf--<?= e($modo) ?>" method="post" action="<?= e($acao) ?>" novalidate
      data-lf data-lf-modo="<?= e($topo === false ? 'coluna' : $modo) ?>">
    <?php if ($topo === false): ?>
        <h2 class="titulo lf-titulo-col"><?= e($titulo) ?></h2>
    <?php elseif ($modo === 'dialog'): ?>
        <div class="lf-topo">
            <h2 class="lf-titulo"><?= e($titulo) ?></h2>
            <button type="button" class="lf-x" data-lancar-fechar aria-label="fechar"><?= svgIcon('x', 20) ?></button>
        </div>
    <?php else: ?>
        <div class="lf-topo">
            <a class="lf-x lf-voltar" href="<?= e($volta) ?>" aria-label="voltar"><?= svgIcon('chevron', 20, 'lf-seta') ?></a>
            <h1 class="lf-titulo"><?= e($titulo) ?></h1>
        </div>
    <?php endif; ?>

    <div class="lf-valor">
        <label class="lf-rs" for="<?= e($pref) ?>-valor">R$</label>
        <input id="<?= e($pref) ?>-valor" class="input-grande lf-valor-in" name="valor" type="text"
               inputmode="decimal" autocomplete="off" enterkeyhint="done" placeholder="0,00" aria-label="valor"
               value="<?= e($valorTxt) ?>" data-lf-valor<?= $foco ? ' data-autofocus' : '' ?>>
    </div>

    <select name="categoria_id" class="lf-select" aria-label="categoria">
        <option value="">selecione a categoria</option>
        <?php foreach ($gruposSel as $grupo => $itens): if ($itens === []) { continue; } ?>
            <optgroup label="<?= e((string) $grupo) ?>">
                <?php foreach ($itens as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === $catSel ? ' selected' : '' ?>><?= e($c['curto']) ?></option>
                <?php endforeach; ?>
            </optgroup>
        <?php endforeach; ?>
    </select>

    <label class="lf-dia">
        <span class="lf-dia-lbl">dia</span>
        <input type="date" name="data" class="lf-data" value="<?= e($data) ?>" aria-label="dia do lançamento"
               min="<?= e((string) ($data_min ?? '')) ?>" max="<?= e((string) ($data_max ?? '')) ?>">
    </label>

    <input type="text" name="descricao" class="lf-desc" placeholder="descrição" aria-label="descrição"
           enterkeyhint="send" maxlength="255" autocomplete="off" list="<?= e($pref) ?>-descs"
           value="<?= e($desc) ?>">
    <?php if (!empty($descricoes)): ?>
        <datalist id="<?= e($pref) ?>-descs">
            <?php foreach ($descricoes as $d): ?>
                <option value="<?= e((string) $d) ?>"></option>
            <?php endforeach; ?>
        </datalist>
    <?php endif; ?>

    <?php if ($erro !== null): ?>
        <div class="aviso aviso-error lf-aviso" role="alert"><?= e($erro) ?></div>
    <?php endif; ?>
    <?php if ($dup !== null): ?>
        <div class="aviso aviso-warn lf-aviso" role="status">igual: "<?= e((string) ($dup['descricao'] ?? '')) ?>" · <?= e((string) ($dup['inicial'] ?? '')) ?> · <?= e(diaMes((string) ($dup['data'] ?? ''))) ?></div>
    <?php endif; ?>
    <div class="aviso aviso-error lf-aviso" role="alert" data-lf-erro hidden></div>

    <button type="submit" class="btn btn-primary btn-block lf-enviar" data-lf-enviar><?= e($botao) ?></button>

    <?= csrfField() ?>
    <input type="hidden" name="volta" value="<?= e($volta) ?>" data-lf-volta>
    <?php if (!$editar): ?>
        <input type="hidden" name="confirmar_dup" value="<?= $dup !== null ? '1' : '' ?>" data-lf-dup>
    <?php endif; ?>
</form>
