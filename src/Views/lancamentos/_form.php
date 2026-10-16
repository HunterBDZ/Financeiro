<?php
/**
 * Formulário de lançar/editar (P4, SPEC §2.2). Simplificado: dropdown de categoria,
 * calendário para data, descrição, botão lançar no final.
 * Com o cartão cadastrado: "Pagou com: Conta | Cartão". Cartão mostra as vezes, a prévia das faturas
 * que sobem (cartao.js) e "mais opções" (outra quantidade de vezes, compra antiga); o bloco some só
 * pelo CSS quando é Conta, então funciona sem JS. Quem grava é LancamentosController::store.
 */
$modo   = in_array($modo ?? '', ['dialog', 'pagina', 'editar'], true) ? $modo : 'dialog';
$pref   = preg_replace('/[^a-z0-9]/', '', (string) ($pref ?? 'lf')) ?: 'lf';
$editar = $modo === 'editar';
$l      = is_array($lancamento ?? null) ? $lancamento : null;
$a      = is_array($antigo ?? null) ? $antigo : [];
$topo   = $topo ?? true;
$volta  = voltaSegura((string) ($volta ?? '/'), '/');
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
$titulo = $editar ? 'Editar lançamento' : 'Novo lançamento';
$botao  = $editar ? 'Salvar' : ($dup !== null ? 'Lançar mesmo assim' : 'Lançar');
$foco   = $modo === 'pagina' && $topo !== false;
$cartao = !$editar && is_array($cartao ?? null) ? $cartao : null;
$pagou  = $cartao !== null && ($a['pagou'] ?? '') === 'cartao' ? 'cartao' : 'conta';
$vezes  = (int) ($a['parcelas'] ?? 1) ?: 1;
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

    <?php if ($cartao !== null): ?>
        <fieldset class="ct-pagou" data-ct-pagou>
            <legend class="sr-only">Pagou com</legend>
            <label><input type="radio" name="pagou" value="conta"<?= $pagou === 'conta' ? ' checked' : '' ?>>Conta</label>
            <label><input type="radio" name="pagou" value="cartao"<?= $pagou === 'cartao' ? ' checked' : '' ?>><?= e($cartao['nome']) ?></label>
        </fieldset>
    <?php endif; ?>

    <p class="lf-quanto" aria-hidden="true"><?= $editar ? 'Valor' : 'Quanto foi?' ?></p>
    <div class="lf-valor">
        <label class="lf-rs" for="<?= e($pref) ?>-valor">R$</label>
        <input id="<?= e($pref) ?>-valor" class="input-grande lf-valor-in" name="valor" type="text"
               inputmode="decimal" autocomplete="off" enterkeyhint="done" placeholder="0,00" aria-label="valor"
               value="<?= e($valorTxt) ?>" data-lf-valor<?= $foco ? ' data-autofocus' : '' ?>>
    </div>

    <?php if ($cartao !== null): ?>
        <div class="ct-bloco" data-ct-bloco data-ct="<?= e((string) json_encode(['fecha' => $cartao['fecha'], 'vence' => $cartao['vence'], 'aberta' => $cartao['aberta'], 'serie' => $cartao['serie']])) ?>">
            <p class="lf-rot" id="<?= e($pref) ?>-vezes-rot">Em quantas vezes?</p>
            <div class="ct-vezes" role="radiogroup" aria-labelledby="<?= e($pref) ?>-vezes-rot">
                <?php foreach ([1, 2, 3, 4, 5, 6, 10, 12] as $k): ?>
                    <label><input type="radio" name="parcelas" value="<?= $k ?>"<?= $k === $vezes ? ' checked' : '' ?>><?= $k ?>x</label>
                <?php endforeach; ?>
            </div>
            <div class="ct-previa">
                <output data-ct-previa aria-live="polite">A fatura em que cai aparece aqui.</output>
                <div class="ct-escada ct-escada-mini" aria-hidden="true">
                    <?php foreach ($cartao['serie'] as [$m, $v]): ?>
                        <span class="ct-degrau"><span class="ct-degrau-barra"><span class="ct-degrau-novo"></span></span><span><?= e(mesCurto($m)) ?></span></span>
                    <?php endforeach; ?>
                </div>
                <small data-ct-previa-sub></small>
            </div>
            <details class="ct-antiga"<?= (($a['parcelas_outro'] ?? '0') !== '0' || ($a['parcela_atual'] ?? '0') !== '0') ? ' open' : '' ?>>
                <summary>Outras vezes ou compra antiga</summary>
                <div class="ct-antiga-c">
                    <label for="<?= e($pref) ?>-outro">Outra quantidade de vezes</label>
                    <input id="<?= e($pref) ?>-outro" name="parcelas_outro" type="text" inputmode="numeric" maxlength="2" placeholder="ex.: 18"
                           value="<?= ($a['parcelas_outro'] ?? '0') !== '0' ? e((string) $a['parcelas_outro']) : '' ?>">
                    <label for="<?= e($pref) ?>-atual">Compra antiga: qual parcela cai na fatura aberta?</label>
                    <input id="<?= e($pref) ?>-atual" name="parcela_atual" type="text" inputmode="numeric" maxlength="2" placeholder="ex.: 4"
                           value="<?= ($a['parcela_atual'] ?? '0') !== '0' ? e((string) $a['parcela_atual']) : '' ?>" aria-describedby="<?= e($pref) ?>-atual-dica">
                    <small id="<?= e($pref) ?>-atual-dica">Para lançar uma compra parcelada que já estava no cartão. Deixe vazio numa compra nova.</small>
                </div>
            </details>
        </div>
    <?php endif; ?>

    <label class="lf-rot" for="<?= e($pref) ?>-cat">Categoria</label>
    <select name="categoria_id" class="lf-select" id="<?= e($pref) ?>-cat">
        <option value="">Escolha a categoria</option>
        <?php foreach ($gruposSel as $grupo => $itens): if ($itens === []) { continue; } ?>
            <optgroup label="<?= e((string) $grupo) ?>">
                <?php foreach ($itens as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === $catSel ? ' selected' : '' ?>><?= e($c['curto']) ?></option>
                <?php endforeach; ?>
            </optgroup>
        <?php endforeach; ?>
    </select>

    <label class="lf-dia">
        <span class="lf-dia-lbl" data-ct-dia>Dia</span>
        <input type="date" name="data" class="lf-data" value="<?= e($data) ?>" aria-label="dia do lançamento"
               min="<?= e((string) ($data_min ?? '')) ?>" max="<?= e((string) ($data_max ?? '')) ?>">
    </label>

    <input type="text" name="descricao" class="lf-desc" placeholder="Descrição (opcional)" aria-label="descrição"
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
        <div class="aviso aviso-warn lf-aviso" role="status">Parece repetido: “<?= e((string) ($dup['descricao'] ?? '')) ?>”, lançado por <?= e((string) ($dup['inicial'] ?? '')) ?> em <?= e(diaMes((string) ($dup['data'] ?? ''))) ?>.</div>
    <?php endif; ?>
    <div class="aviso aviso-error lf-aviso" role="alert" data-lf-erro hidden></div>

    <button type="submit" class="btn btn-primary btn-block lf-enviar" data-lf-enviar data-ct-rot="<?= $cartao !== null ? 'Lançar no cartão' : '' ?>"><?= e($pagou === 'cartao' ? 'Lançar no cartão' : $botao) ?></button>

    <?= csrfField() ?>
    <input type="hidden" name="volta" value="<?= e($volta) ?>" data-lf-volta>
    <?php if (!$editar): ?>
        <input type="hidden" name="confirmar_dup" value="<?= $dup !== null ? '1' : '' ?>" data-lf-dup>
    <?php endif; ?>
</form>
