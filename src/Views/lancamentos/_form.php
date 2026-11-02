<?php
/**
 * Formulário de lançar/editar: valor, categoria em um toque (as mais usadas em botões e "Mais…" com a
 * lista completa, rádios que funcionam sem JS), calendário para data, descrição, botão lançar no final.
 * Com o cartão cadastrado: "Pagou com: Conta | Cartão". Cartão mostra as vezes, a prévia das faturas
 * que sobem (cartao.js) e "mais opções" (outra quantidade de vezes, compra antiga); o bloco some só
 * pelo CSS quando é Conta, então funciona sem JS. Quem grava é LancamentosController::store.
 * Com mais de uma conta (ou de um cartão), "Pagou com" / "Recebeu em" mostra as contas e, num gasto, os cartões
 * (valores conta:id e cartao:id), e o modo Transferir passa dinheiro de uma conta para outra. Com uma conta e um
 * cartão, o formulário é o de sempre.
 */
$modo   = in_array($modo ?? '', ['dialog', 'pagina', 'editar'], true) ? $modo : 'dialog';
$pref   = preg_replace('/[^a-z0-9]/', '', (string) ($pref ?? 'lf')) ?: 'lf';
$editar = $modo === 'editar';
$l      = is_array($lancamento ?? null) ? $lancamento : null;
$a      = is_array($antigo ?? null) ? $antigo : [];
$topo   = $topo ?? true;
$volta  = voltaSegura((string) ($volta ?? '/'), '/');
$outras = $outras ?? [];
$hoje   = (string) ($hoje ?? \Financeiro\Relogio::hoje());

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

// a lista completa por classe (gastos antes de entradas) e os botões das mais usadas, já ordenados no LancarForm.
// A categoria escolhida que não está nos botões fica marcada na lista, e o "Mais…" mostra o nome dela.
$gruposSel  = $outras;
$rapidasSel = is_array($rapidas ?? null) ? $rapidas : [];
$selNaLista = null;
if ($catSel > 0 && !in_array($catSel, array_map(static fn(array $c): int => (int) $c['id'], $rapidasSel), true)) {
    foreach ($gruposSel as $itens) {
        foreach ($itens as $c) {
            if ((int) $c['id'] === $catSel) {
                $selNaLista = $c;
            }
        }
    }
}

$acao   = $editar && $l !== null ? '/lancamentos/' . (int) $l['id'] : '/lancamentos';
$titulo = $editar ? t('Editar lançamento') : t('Novo lançamento');
$botao  = $editar ? t('Salvar') : ($dup !== null ? t('Registrar outro') : t('Lançar'));
$foco   = $modo === 'pagina' && $topo !== false;
$cartao = !$editar && is_array($cartao ?? null) ? $cartao : null;
$pagou  = $cartao !== null && ($a['pagou'] ?? '') === 'cartao' ? 'cartao' : 'conta';
$vezes  = (int) ($a['parcelas'] ?? 1) ?: 1;

// várias contas ou vários cartões: as opções do "Pagou com", cada uma [valor, conta ou cartão, só num gasto]
$cartoesF = $cartao !== null ? (is_array($cartoes ?? null) && $cartoes !== [] ? $cartoes : [$cartao]) : [];
$contasF  = is_array($contas ?? null) ? $contas : [];
$opcoes   = [];
if ($contasF !== [] || count($cartoesF) > 1) {
    foreach ($contasF !== [] ? $contasF : [null] as $ct) {
        $opcoes[] = [$ct !== null ? 'conta:' . (int) $ct['id'] : 'conta', $ct, false];
    }
    foreach ($cartoesF as $k) {
        $opcoes[] = [count($cartoesF) > 1 ? 'cartao:' . (int) $k['id'] : 'cartao', $k, true];
    }
    $valores = array_column($opcoes, 0);
    $pedido = (string) ($a['pagou'] ?? ($l !== null ? 'conta:' . (int) ($l['conta_id'] ?? 0) : ($padrao ?? '')));
    if ($pedido === 'cartao' && !in_array('cartao', $valores, true)) {
        $pedido = 'cartao:' . (int) ($cartoesF[0]['id'] ?? 0);           // do botão da aba Cartão: o primeiro
    }
    $padraoV = in_array((string) ($padrao ?? ''), $valores, true) ? (string) $padrao : $valores[0];
    $pagou = in_array($pedido, $valores, true) ? $pedido : $padraoV;
}
$transferir = !$editar && $contasF !== [] && ($a['modo'] ?? '') === 'transferir';
?>
<form class="lf lf--<?= e($modo) ?>" method="post" action="<?= e($acao) ?>" novalidate
      data-lf data-lf-modo="<?= e($topo === false ? 'coluna' : $modo) ?>">
    <?php if ($topo === false): ?>
        <h2 class="titulo lf-titulo-col"><?= e($titulo) ?></h2>
    <?php elseif ($modo === 'dialog'): ?>
        <div class="lf-topo">
            <h2 class="lf-titulo"><?= e($titulo) ?></h2>
            <button type="button" class="lf-x" data-lancar-fechar aria-label="<?= e(t('fechar')) ?>"><?= svgIcon('x', 20) ?></button>
        </div>
    <?php else: ?>
        <div class="lf-topo">
            <a class="lf-x lf-voltar" href="<?= e($volta) ?>" aria-label="<?= e(t('voltar')) ?>"><?= svgIcon('chevron', 20, 'lf-seta') ?></a>
            <h1 class="lf-titulo"><?= e($titulo) ?></h1>
        </div>
    <?php endif; ?>

    <?php if (!$editar && $contasF !== []): ?>
        <fieldset class="lf-modo" data-lf-modo-sel>
            <legend class="sr-only"><?= e(t('O que fazer')) ?></legend>
            <label class="chip-r"><input type="radio" name="modo" value="lancar"<?= $transferir ? '' : ' checked' ?>><span><?= e(t('Gasto ou entrada')) ?></span></label>
            <label class="chip-r"><input type="radio" name="modo" value="transferir"<?= $transferir ? ' checked' : '' ?>><span><?= e(t('↔ Transferir')) ?></span></label>
        </fieldset>
    <?php endif; ?>
    <?php if ($opcoes !== []): ?>
        <fieldset class="ct-pagou is-varias" data-ct-pagou>
            <legend class="lf-rot" data-pagou-rot data-gasto="<?= e(t('Pagou com')) ?>" data-entrada="<?= e(t('Recebeu em')) ?>"><?= e(t('Pagou com')) ?></legend>
            <?php foreach ($opcoes as [$v, $o, $soGasto]): ?>
                <label<?= $soGasto ? ' data-so-gasto' : '' ?>><input type="radio" name="pagou" value="<?= e($v) ?>"<?= $v === $pagou ? ' checked' : '' ?><?= $v === ($padraoV ?? null) ? ' data-lf-padrao' : '' ?>><?= $soGasto ? e($o['nome']) : ($o !== null ? '<span aria-hidden="true">' . e(\Financeiro\Models\ContaModel::TIPOS[$o['tipo']] ?? '') . '</span> ' . e(nomeConta($o)) : e(t('Conta'))) ?></label>
            <?php endforeach; ?>
        </fieldset>
    <?php elseif ($cartao !== null): ?>
        <fieldset class="ct-pagou" data-ct-pagou>
            <legend class="sr-only"><?= e(t('Pagou com')) ?></legend>
            <label><input type="radio" name="pagou" value="conta"<?= $pagou === 'conta' ? ' checked' : '' ?>><?= e(t('Conta')) ?></label>
            <label><input type="radio" name="pagou" value="cartao"<?= $pagou === 'cartao' ? ' checked' : '' ?>><?= e($cartao['nome']) ?></label>
        </fieldset>
    <?php endif; ?>
    <?php if (!$editar && $contasF !== []): ?>
        <div class="lf-transf" data-lf-transf>
            <label><span><?= e(t('De')) ?></span>
                <select name="de_conta">
                    <?php foreach ($contasF as $i => $ct): ?><option value="<?= (int) $ct['id'] ?>"<?= (int) $ct['id'] === (int) ($a['de_conta'] ?? $contasF[0]['id']) ? ' selected' : '' ?>><?= e(nomeConta($ct)) ?></option><?php endforeach; ?>
                </select></label>
            <label><span><?= e(t('Para')) ?></span>
                <select name="para_conta">
                    <?php foreach ($contasF as $i => $ct): ?><option value="<?= (int) $ct['id'] ?>"<?= (int) $ct['id'] === (int) ($a['para_conta'] ?? $contasF[1]['id']) ? ' selected' : '' ?>><?= e(nomeConta($ct)) ?></option><?php endforeach; ?>
                </select></label>
        </div>
    <?php endif; ?>

    <p class="lf-quanto" aria-hidden="true"><?= $editar ? e(t('Valor')) : e(t('Quanto foi?')) ?></p>
    <div class="lf-valor">
        <label class="lf-rs" for="<?= e($pref) ?>-valor"><?= e(moedaSimbolo()) ?></label>
        <input id="<?= e($pref) ?>-valor" class="input-grande lf-valor-in" name="valor" type="text"
               inputmode="decimal" autocomplete="off" enterkeyhint="done" placeholder="<?= e(numInput(0)) ?>" aria-label="<?= e(t('valor')) ?>"
               value="<?= e($valorTxt) ?>" data-lf-valor<?= $foco ? ' data-autofocus' : '' ?>>
    </div>

    <?php if ($cartao !== null): ?>
        <?php $cfgCt = ['fecha' => $cartao['fecha'], 'vence' => $cartao['vence'], 'aberta' => $cartao['aberta'], 'serie' => $cartao['serie']];
        if (count($cartoesF) > 1) {     // um por cartão, pelo valor do "Pagou com"
            foreach ($cartoesF as $k) {
                $cfgCt['cartoes']['cartao:' . (int) $k['id']] = ['fecha' => $k['fecha'], 'vence' => $k['vence'], 'aberta' => $k['aberta'], 'serie' => $k['serie']];
            }
        } ?>
        <div class="ct-bloco" data-ct-bloco data-ct="<?= e((string) json_encode($cfgCt)) ?>">
            <p class="lf-rot" id="<?= e($pref) ?>-vezes-rot"><?= e(t('Em quantas vezes?')) ?></p>
            <div class="ct-vezes" role="radiogroup" aria-labelledby="<?= e($pref) ?>-vezes-rot">
                <?php foreach ([1, 2, 3, 4, 5, 6, 10, 12] as $k): ?>
                    <label><input type="radio" name="parcelas" value="<?= $k ?>"<?= $k === $vezes ? ' checked' : '' ?>><?= e(t('{n}x', ['n' => $k])) ?></label>
                <?php endforeach; ?>
            </div>
            <div class="ct-previa">
                <output data-ct-previa aria-live="polite"><?= e(t('A fatura em que cai aparece aqui.')) ?></output>
                <div class="ct-escada ct-escada-mini" aria-hidden="true">
                    <?php foreach ($cartao['serie'] as [$m, $v]): ?>
                        <span class="ct-degrau"><span class="ct-degrau-barra"><span class="ct-degrau-novo"></span></span><span><?= e(mesCurto($m)) ?></span></span>
                    <?php endforeach; ?>
                </div>
                <small data-ct-previa-sub></small>
            </div>
            <details class="ct-antiga"<?= (($a['parcelas_outro'] ?? '0') !== '0' || ($a['parcela_atual'] ?? '0') !== '0' || ($a['estorno'] ?? '') === '1') ? ' open' : '' ?>>
                <summary><?= e(t('Outras vezes ou compra antiga')) ?></summary>
                <div class="ct-antiga-c">
                    <label for="<?= e($pref) ?>-outro"><?= e(t('Outra quantidade de vezes')) ?></label>
                    <input id="<?= e($pref) ?>-outro" name="parcelas_outro" type="text" inputmode="numeric" maxlength="2" placeholder="<?= e(t('ex.: {n}', ['n' => 18])) ?>"
                           value="<?= ($a['parcelas_outro'] ?? '0') !== '0' ? e((string) $a['parcelas_outro']) : '' ?>">
                    <label for="<?= e($pref) ?>-atual"><?= e(t('Compra antiga: qual parcela cai na fatura aberta?')) ?></label>
                    <input id="<?= e($pref) ?>-atual" name="parcela_atual" type="text" inputmode="numeric" maxlength="2" placeholder="<?= e(t('ex.: {n}', ['n' => 4])) ?>"
                           value="<?= ($a['parcela_atual'] ?? '0') !== '0' ? e((string) $a['parcela_atual']) : '' ?>" aria-describedby="<?= e($pref) ?>-atual-dica">
                    <small id="<?= e($pref) ?>-atual-dica"><?= e(t('Para lançar uma compra parcelada que já estava no cartão. Deixe vazio numa compra nova.')) ?></small>
                    <label class="ct-estorno"><input type="checkbox" name="estorno" value="1"<?= ($a['estorno'] ?? '') === '1' ? ' checked' : '' ?>><span><?= e(t('É um estorno: o valor volta para o cartão')) ?></span></label>
                </div>
            </details>
        </div>
    <?php endif; ?>

    <div class="lf-rot-linha">
        <span class="lf-rot" id="<?= e($pref) ?>-cat-rot"><?= e(t('Categoria')) ?></span>
        <?php if (!$editar): ?><a class="lf-criar-cat" href="/ajustes?aberto=categorias#categorias"><?= e(t('Criar categoria')) ?></a><?php endif; ?>
    </div>
    <?php // as mais usadas em botões de um toque; "Mais…" abre a lista completa (com busca, se houver JS). Um grupo de rádios só: funciona sem JS ?>
    <fieldset class="lf-cats" aria-labelledby="<?= e($pref) ?>-cat-rot" data-lf-cats>
        <div class="lf-chips">
            <?php foreach ($rapidasSel as $c): ?>
                <label class="lf-chip">
                    <input type="radio" name="categoria_id" value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === $catSel ? ' checked' : '' ?> data-lf-rapida<?= $opcoes !== [] && !empty($c['entra']) ? ' data-lf-entra' : '' ?>>
                    <span class="lf-chip-c"><?= iconeCategoria((int) $c['id'], (string) $c['nome'], (string) $c['grupo'], 36) ?><span class="lf-chip-n"><?= e($c['curto']) ?></span></span>
                </label>
            <?php endforeach; ?>
        </div>
        <details class="lf-mais<?= $selNaLista !== null ? ' is-sel' : '' ?>" data-lf-mais>
            <summary>
                <span class="lf-mais-rot" data-lf-mais-rot><?php if ($selNaLista !== null): ?><?= iconeCategoria((int) $selNaLista['id'], (string) $selNaLista['nome'], (string) $selNaLista['grupo'], 28) ?><span><?= e($selNaLista['curto']) ?></span><?php else: ?><span><?= e(t('Mais…')) ?></span><?php endif; ?></span>
                <span class="lf-mais-dica"><?= e(t('todas as categorias')) ?></span>
            </summary>
            <div class="lf-mais-painel">
                <input type="search" class="lf-busca" placeholder="<?= e(t('Buscar categoria')) ?>" aria-label="<?= e(t('Buscar categoria')) ?>" autocomplete="off" enterkeyhint="search" data-lf-busca hidden>
                <p class="lf-mais-vazio" data-lf-vazio hidden><?= e(t('Nenhuma categoria com esse nome.')) ?></p>
                <?php // i18n: t('entradas') t('essenciais') t('não essenciais') t('⇄ movimentações')
                foreach ($gruposSel as $grupo => $itens): if ($itens === []) { continue; } $gid = $pref . '-g-' . substr(md5((string) $grupo), 0, 6); ?>
                    <div class="lf-mais-g" role="group" aria-labelledby="<?= e($gid) ?>" data-lf-grupo>
                        <p class="lf-mais-gt" id="<?= e($gid) ?>"><?= e(t((string) $grupo)) ?></p>
                        <?php foreach ($itens as $c): ?>
                            <label class="lf-op" data-lf-op="<?= e(mb_strtolower($c['curto'] . ' ' . nomeCategoria($c['nome']))) ?>">
                                <input type="radio" name="categoria_id" value="<?= (int) $c['id'] ?>"<?= $selNaLista !== null && (int) $c['id'] === $catSel ? ' checked' : '' ?><?= $opcoes !== [] && !empty($c['entra']) ? ' data-lf-entra' : '' ?>>
                                <?= iconeCategoria((int) $c['id'], (string) $c['nome'], (string) $c['grupo'], 32) ?><span><?= e($c['curto']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </details>
    </fieldset>

    <label class="lf-dia">
        <span class="lf-dia-lbl" data-ct-dia><?= e(t('Dia')) ?></span>
        <input type="date" name="data" class="lf-data" value="<?= e($data) ?>" aria-label="<?= e(t('dia do lançamento')) ?>"
               min="<?= e((string) ($data_min ?? '')) ?>" max="<?= e((string) ($data_max ?? '')) ?>">
    </label>

    <input type="text" name="descricao" class="lf-desc" placeholder="<?= e(t('Descrição (opcional)')) ?>" aria-label="<?= e(t('descrição')) ?>"
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
        <div class="aviso aviso-warn lf-aviso" role="status"><?= e(t('Já existe um lançamento igual: {descricao}, de {quem}, em {data}. Quer registrar outro?', ['quem' => (string) ($dup['quem'] ?? ''), 'data' => diaMes((string) ($dup['data'] ?? '')), 'descricao' => nomeCategoria((string) ($dup['descricao'] ?? ''))])) ?></div>
    <?php endif; ?>
    <div class="aviso aviso-error lf-aviso" role="alert" data-lf-erro hidden></div>

    <button type="submit" class="btn btn-primary btn-block lf-enviar" data-lf-enviar data-ct-rot="<?= $cartao !== null ? e(t('Lançar no cartão')) : '' ?>"<?= !$editar && $contasF !== [] ? ' data-transf-rot="' . e(t('Transferir')) . '"' : '' ?>><?= e($transferir ? t('Transferir') : (str_starts_with($pagou, 'cartao') ? t('Lançar no cartão') : $botao)) ?></button>

    <?= csrfField() ?>
    <input type="hidden" name="volta" value="<?= e($volta) ?>" data-lf-volta>
    <?php if (!$editar): ?>
        <input type="hidden" name="confirmar_dup" value="<?= $dup !== null ? '1' : '' ?>" data-lf-dup>
    <?php endif; ?>
</form>
