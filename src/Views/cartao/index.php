<?php
/**
 * Aba Cartão. Sem cartão: o cadastro (nome, fechamento, vencimento, limite). Com cartão:
 *  · o cartão como objeto: a fatura aberta, quando fecha e vence, o limite livre;
 *  · a escada das próximas faturas (só as parcelas já lançadas); cada degrau é uma fatura e a escolhe;
 *  · a fatura escolhida (parcelas k/N e compras do mês, cada uma leva ao editar);
 *  · os parcelamentos, do que acaba antes para o que acaba depois; os dados do cartão.
 * Enquanto o cartão não alimenta o plano, um quadro compara a previsão digitada da Fatura do cartão
 * com a soma das parcelas lançadas, mês a mês, e oferece ligar.
 * Com mais de um cartão, um seletor no topo escolhe o cartão; "Novo cartão" (no menu, ou no seletor) cadastra outro.
 * Uma fatura fechada ou vencida tem "Pagar fatura", que lança o pagamento já ligado a ela.
 * O cartão desenhado tem o visual escolhido no configurar de cada cartão (CartaoVisual; sem escolha, as cores do
 * tema), e o seletor de visual mostra a escolha nele antes de salvar (cartao.js). Com vários, cada um tem o seu.
 * Variáveis: $cartao (null = cadastrar), $cartoes (os ativos), $contas (as ativas, só com mais de uma), $painel,
 * $hoje, $sel (fatura escolhida), $erro, $temCompras, $lancadoTodos (com vários cartões, a soma das faturas de todos).
 */
use Financeiro\Services\CartaoVisual;

$erro = is_array($erro ?? null) ? $erro : null;
$cfg  = $cartao ?? null;
$cartoes = $cartoes ?? [];
$contas = $contas ?? [];
$varios = count($cartoes) > 1;
$urlCartao = static function (string $resto = '') use ($varios, $cfg): string {
    $q = array_filter([$varios && $cfg !== null ? 'cartao=' . (int) $cfg['id'] : '', $resto], static fn(string $x): bool => $x !== '');
    return '/cartao' . ($q !== [] ? '?' . implode('&', $q) : '');
};
$campo = static fn(string $k, string $padrao = ''): string => (string) ($erro[$k] ?? $padrao);
// o visual que o desenho e o seletor mostram: o que voltou com o erro do formulário ou o guardado
$visual = $erro !== null && array_key_exists('visual', $erro) ? CartaoVisual::normalizar($erro['visual']) : ($cfg['visual'] ?? null);
$plastico = static function (string $titulo, string $sub, string $valor, string $status, array $pe, bool $vazio = false, ?string $visual = null): void { ?>
    <section class="ct-plastico<?= $vazio ? ' ct-vazio-plastico' : '' ?>"<?= CartaoVisual::atributos($visual) ?> aria-label="<?= e($titulo) ?>" data-ct-plastico>
        <div class="ct-plastico-topo">
            <p class="ct-plastico-t"><?= e($titulo) ?><small><?= e($sub) ?></small></p>
            <span class="ct-chip" aria-hidden="true"></span>
        </div>
        <p class="ct-plastico-v num"><small><?= e(moedaSimbolo()) ?>&nbsp;</small><?= e($valor) ?></p>
        <div>
            <?php if ($status !== ''): ?><span class="ct-status"><i aria-hidden="true"></i><?= e($status) ?></span><?php endif; ?>
            <?php if ($pe !== []): ?>
                <dl class="ct-plastico-pe num">
                    <?php foreach ($pe as [$t, $v]): ?><div><dt><?= e($t) ?></dt><dd><?= e($v) ?></dd></div><?php endforeach; ?>
                </dl>
            <?php endif; ?>
        </div>
    </section>
<?php };
$formConfig = static function (?array $cfg, ?array $erro, callable $campo, bool $primeira, ?string $visual, bool $novo = false, array $contas = [], bool $comId = false): void {
    $marcado = CartaoVisual::escolha($visual);
    $cor = $marcado === 'propria' ? (string) $visual : CartaoVisual::COR_INICIAL; ?>
    <form class="ct-form" method="post" action="/cartao/config" id="cartao-config">
        <?= csrfField() ?>
        <?php if ($novo): ?><input type="hidden" name="novo" value="1"><?php elseif ($cfg !== null && $comId): ?><input type="hidden" name="id" value="<?= (int) $cfg['id'] ?>"><?php endif; ?>
        <?php if ($erro !== null && isset($erro['msg'])): ?><p class="aviso aviso-error" role="alert"><?= e((string) $erro['msg']) ?></p><?php endif; ?>
        <label><span><?= e(t('Nome')) ?></span><input type="text" name="nome" maxlength="60" required placeholder="<?= e(t('Meu cartão')) ?>" value="<?= e($campo('nome', (string) ($cfg['nome'] ?? ''))) ?>"></label>
        <div class="ct-2">
            <label><span><?= e(t('Fecha no dia')) ?></span><input type="text" name="dia_fechamento" inputmode="numeric" maxlength="2" required placeholder="25" value="<?= e($campo('fecha', isset($cfg['fecha']) ? (string) $cfg['fecha'] : '')) ?>"></label>
            <label><span><?= e(t('Vence no dia')) ?></span><input type="text" name="dia_vencimento" inputmode="numeric" maxlength="2" required placeholder="5" value="<?= e($campo('vence', isset($cfg['vence']) ? (string) $cfg['vence'] : '')) ?>"></label>
        </div>
        <label><span><?= e(t('Limite (opcional)')) ?></span><input type="text" name="limite" inputmode="decimal" placeholder="<?= e(numInput(0)) ?>" value="<?= e($campo('limite', isset($cfg['limite']) ? numInput((float) $cfg['limite']) : '')) ?>"></label>
        <?php if ($contas !== []): ?>
            <label><span><?= e(t('Conta que paga a fatura')) ?></span>
                <select name="conta_id">
                    <?php foreach ($contas as $ct): ?><option value="<?= (int) $ct['id'] ?>"<?= (int) $ct['id'] === (int) ($cfg['conta_id'] ?? 0) ? ' selected' : '' ?>><?= e(nomeConta($ct)) ?></option><?php endforeach; ?>
                </select></label>
        <?php endif; ?>
        <fieldset class="ct-vis" data-ct-vis>
            <legend><?= e(t('Visual do cartão')) ?></legend>
            <div class="ct-vis-grade">
                <?php foreach (CartaoVisual::nomes() as $chave => $nomeVis): ?>
                    <label class="ct-vis-op"><input type="radio" name="visual" value="<?= e($chave) ?>"<?= $marcado === $chave ? ' checked' : '' ?>>
                        <span class="ct-vis-mini"<?= CartaoVisual::atributos($chave === '' ? null : $chave) ?> aria-hidden="true"></span><span class="ct-vis-n"><?= e($nomeVis) ?></span></label>
                <?php endforeach; ?>
                <label class="ct-vis-op"><input type="radio" name="visual" value="propria"<?= $marcado === 'propria' ? ' checked' : '' ?>>
                    <span class="ct-vis-mini"<?= CartaoVisual::atributos($cor) ?> aria-hidden="true" data-ct-vis-propria></span><span class="ct-vis-n"><?= e(t('Cor própria')) ?></span></label>
            </div>
            <label class="ct-vis-cor"><span><?= e(t('Escolha a cor')) ?></span><input type="color" name="visual_cor" value="<?= e($cor) ?>"></label>
        </fieldset>
        <?php if ($primeira): ?>
            <p><?= e(t('Com o fechamento e o vencimento, o app sabe em que fatura cada compra cai. Depois de lançar as compras parceladas que já estão no cartão, você liga o cartão ao plano e a previsão da Fatura do cartão passa a sair delas.')) ?></p>
        <?php endif; ?>
        <button class="btn btn-primary" type="submit"><?= $primeira ? e(t('Salvar cartão')) : e(t('Salvar')) ?></button>
    </form>
<?php };

if ($cfg === null): $novo = $cartoes !== []; ?>
<div class="ct">
    <?php if ($novo): ?>
        <header class="lf-topo ct-ed-topo">
            <a class="lf-x lf-voltar" href="/cartao" aria-label="<?= e(t('voltar')) ?>"><?= svgIcon('chevron', 20, 'lf-seta') ?></a>
            <h1 class="lf-titulo"><?= e(t('Novo cartão')) ?></h1>
        </header>
    <?php else: ?>
        <header class="ct-topo"><div><h1><?= e(t('Cartão')) ?></h1><p><?= e(t('Primeiro, conte ao app quando a fatura fecha e vence.')) ?></p></div></header>
    <?php endif; ?>
    <div class="ct-cima">
        <?php $plastico(t('Fatura do mês'), $novo ? t('novo cartão') : t('seu cartão'), '—', '', [[t('Fecha'), t('dia —')], [t('Vence'), t('dia —')]], true, $visual); ?>
        <section class="card" aria-labelledby="ct-cad-t">
            <h2 class="titulo" id="ct-cad-t"><?= $novo ? e(t('Cadastre o novo cartão')) : e(t('Cadastre o cartão')) ?></h2>
            <?php $formConfig(null, $erro, $campo, !$novo, $visual, $novo, $contas); ?>
        </section>
    </div>
</div>
<?php return; endif;

$p = $painel;
$aberta = $p['aberta'];
$faturas = $p['faturas'];
$fAberta = null;
foreach ($faturas as $f) {
    if ($f['mes'] === $aberta) {
        $fAberta = $f;
    }
}
$topo = 1.0;
foreach ($faturas as $f) {
    $topo = max($topo, (float) $f['total'], (float) ($f['pago'] ?? 0.0));
}
$selValido = in_array($sel, array_column($faturas, 'mes'), true) ? $sel : $aberta;
$statusTxt = static function (array $f) use ($hoje): string {
    $dias = (int) round((strtotime($f['fecha']) - strtotime($hoje)) / 86400);
    $util = ($f['pagaAte'] ?? $f['vence']) !== $f['vence'];
    return match ($f['status']) {
        'aberta'   => $dias <= 1
            ? ($dias === 1 ? t('Aberta · fecha amanhã, dia {dia}', ['dia' => (int) substr($f['fecha'], 8, 2)]) : t('Aberta · fecha hoje, dia {dia}', ['dia' => (int) substr($f['fecha'], 8, 2)]))
            : t('Aberta · fecha em {n} dias, dia {dia}', ['n' => $dias, 'dia' => (int) substr($f['fecha'], 8, 2)]),
        // paga em parte: o que ainda falta da soma das parcelas; vence num dia que não é útil: até quando dá para pagar
        'fechada'  => $util
            ? ($f['falta'] !== null
                ? t('Fechada · vence {data} · paga até {util} · faltam {valor}', ['data' => diaMes($f['vence']), 'util' => diaMes($f['pagaAte']), 'valor' => num((float) $f['falta'])])
                : t('Fechada · vence {data} · paga até {util}', ['data' => diaMes($f['vence']), 'util' => diaMes($f['pagaAte'])]))
            : ($f['falta'] !== null
                ? t('Fechada · vence dia {dia} · faltam {valor}', ['dia' => (int) substr($f['vence'], 8, 2), 'valor' => num((float) $f['falta'])])
                : t('Fechada · vence dia {dia}', ['dia' => (int) substr($f['vence'], 8, 2)])),
        // a data diz que o pagamento feito antes do mês do vencimento foi para esta fatura
        'paga'     => $f['pagaEm'] !== null ? t('Paga em {data}', ['data' => diaMes((string) $f['pagaEm'])]) : t('Paga'),
        'vencida'  => $f['falta'] !== null
            ? t('Venceu dia {dia} · faltam {valor}', ['dia' => (int) substr($f['vence'], 8, 2), 'valor' => num((float) $f['falta'])])
            : t('Venceu dia {dia} · lance o pagamento no Extrato', ['dia' => (int) substr($f['vence'], 8, 2)]),
        default    => $util
            ? t('Prevista · vence {data} · paga até {util}', ['data' => diaMes($f['vence']), 'util' => diaMes($f['pagaAte'])])
            : t('Prevista · vence {data}', ['data' => diaMes($f['vence'])]),
    };
};
$ancora = static fn(string $m): string => 'f-' . substr($m, 0, 7);
$pe = [[t('Vence'), t('{dia} de {mes}', ['dia' => (int) substr($fAberta['vence'], 8, 2), 'mes' => mesExtenso($fAberta['vence'])])]];
if ($p['livre'] !== null) {
    $pe[] = [t('Limite livre'), t('{livre} de {limite}', ['livre' => num((float) $p['livre']), 'limite' => num((float) $cfg['limite'])])];
}
$pagouCartao = $varios ? 'cartao:' . (int) $cfg['id'] : 'cartao';
$hrefLancar = '/lancar?pagou=' . rawurlencode($pagouCartao) . '&volta=' . rawurlencode($urlCartao());
?>
<div class="ct">
    <header class="ct-topo">
        <div><h1><?= e(t('Cartão')) ?></h1><p><?= e(t('{nome} · fecha dia {fecha} · vence dia {vence}', ['fecha' => (int) $cfg['fecha'], 'vence' => (int) $cfg['vence'], 'nome' => $cfg['nome']])) ?></p><?php if ($varios): ?>
            <nav class="ct-cartoes" aria-label="<?= e(t('cartões')) ?>">
                <?php foreach ($cartoes as $c): ?>
                    <a class="chip<?= $c['id'] === $cfg['id'] ? ' is-on' : '' ?>" href="/cartao?cartao=<?= (int) $c['id'] ?>"<?= $c['id'] === $cfg['id'] ? ' aria-current="page"' : '' ?>><?= e($c['nome']) ?></a>
                <?php endforeach; ?>
                <a class="chip ct-novo" href="/cartao?novo=1"><?= svgIcon('plus', 16) ?><?= e(t('Novo cartão')) ?></a>
            </nav>
        <?php endif; ?></div>
        <details class="menu mes-menu">
            <summary aria-label="<?= e(t('mais ações')) ?>"><?= svgIcon('more', 20) ?></summary>
            <div class="menu-painel">
                <a class="menu-item" href="#cartao-config"><?= svgIcon('settings', 18) ?><?= e(t('Configurar o cartão')) ?></a>
                <form method="post" action="/cartao/plano">
                    <?= csrfField() ?><?php if ($varios): ?><input type="hidden" name="cartao" value="<?= (int) $cfg['id'] ?>"><?php endif; ?><input type="hidden" name="ligar" value="<?= $cfg['alimenta_plano'] ? '0' : '1' ?>">
                    <button type="submit" class="menu-item"><?= svgIcon('trend', 18) ?><?= $cfg['alimenta_plano'] ? e(t('Tirar do plano')) : e(t('Ligar ao plano')) ?></button>
                </form>
                <a class="menu-item" href="/cartao?novo=1"><?= svgIcon('plus', 18) ?><?= e(t('Novo cartão')) ?></a>
                <?php if ($varios): ?>
                    <form method="post" action="/cartao/arquivar" data-confirm="<?= e(t('Arquivar o cartão {nome}? As compras e os pagamentos dele continuam no Extrato e no plano.', ['nome' => $cfg['nome']])) ?>">
                        <?= csrfField() ?><input type="hidden" name="cartao" value="<?= (int) $cfg['id'] ?>">
                        <button type="submit" class="menu-item"><?= svgIcon('x', 18) ?><?= e(t('Arquivar o cartão')) ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </details>
    </header>

    <?php if (!$cfg['alimenta_plano']): ?>
        <section class="card ct-conf" aria-labelledby="ct-conf-t">
            <h2 class="ct-conf-t" id="ct-conf-t"><?= e(t('O plano ainda usa a previsão digitada da Fatura do cartão')) ?></h2>
            <p class="ct-escada-sub"><?= t('Lance as compras parceladas que já estão no cartão (em "Outras vezes ou compra antiga", diga qual parcela cai na fatura aberta). Quando as faturas baterem, ligue o cartão ao plano.') ?></p>
            <table class="ct-conf-tab num">
                <thead><tr><th scope="col"><?= e(t('Fatura')) ?></th><th scope="col"><?= e(t('Previsão de hoje')) ?></th><th scope="col"><?= e(t('Lançado no cartão')) ?></th></tr></thead>
                <tbody>
                    <?php foreach (array_slice(array_filter($faturas, static fn($f) => $f['mes'] >= $aberta), 0, 6) as $f):
                        $lancadoF = $varios ? (float) ($lancadoTodos[$f['mes']] ?? 0.0) : (float) $f['total'];
                        $dif = abs((float) ($f['previsto'] ?? 0.0) - $lancadoF) >= 0.01; ?>
                        <tr><th scope="row"><?= e(monthLabel($f['mes'])) ?></th><td><?= e(num((float) ($f['previsto'] ?? 0.0))) ?></td>
                            <td class="<?= $dif ? 'ct-dif' : 'ct-ok' ?>"><?= e(num($lancadoF)) ?><?= $dif ? '' : ' <span class="sr-only">' . e(t('(bate)')) . '</span>' ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <form method="post" action="/cartao/plano" class="ct-conf-acao">
                <?= csrfField() ?><?php if ($varios): ?><input type="hidden" name="cartao" value="<?= (int) $cfg['id'] ?>"><?php endif; ?><input type="hidden" name="ligar" value="1">
                <button type="submit" class="btn"<?= $temCompras ? '' : ' disabled' ?>><?= e(t('Usar as faturas do cartão no plano')) ?></button>
            </form>
        </section>
    <?php endif; ?>

    <div class="ct-cima">
        <?php $plastico(t('Fatura de {mes}', ['mes' => mesExtenso($aberta)]), $cfg['nome'], num((float) $fAberta['total']), $statusTxt($fAberta), $pe, false, $visual); ?>
        <section class="card ct-escada-card" aria-labelledby="ct-esc-t">
            <div class="ct-escada-cab"><h2 id="ct-esc-t"><?= e(t('Próximas faturas')) ?></h2></div>
            <p class="ct-escada-sub"><?= e(t('Quanto já está comprometido em cada fatura. Novas compras somam a isso.')) ?></p>
            <div class="ct-escada">
                <?php foreach ($faturas as $j => $f):
                    $v = max((float) $f['total'], (float) ($f['pago'] ?? 0.0));
                    $cls = 'ct-degrau' . ($f['status'] === 'paga' ? ' is-paga' : '') . ($f['mes'] === $aberta ? ' is-aberta' : '');
                    $ano = $j === 0 || substr($f['mes'], 5, 2) === '01' ? substr($f['mes'], 0, 4) : '';
                    ?>
                    <a class="<?= $cls ?>" href="<?= e($urlCartao('fatura=' . $f['mes'])) ?>#fatura" data-fatura="<?= e($ancora($f['mes'])) ?>"
                       data-rot="<?= e(monthLabel($f['mes'])) ?>" data-v="<?= e(num($v)) ?>"<?= $f['mes'] === $selValido ? ' aria-current="true"' : '' ?>>
                        <span class="ct-degrau-barra" style="--h:<?= number_format(max(2.0, $v / $topo * 100), 1, '.', '') ?>%"></span>
                        <span><?= e(mesCurto($f['mes'])) ?></span><small><?= $ano !== '' ? e($ano) : '&nbsp;' ?></small>
                        <span class="sr-only"><?= $f['status'] === 'paga' ? e(t('Fatura de {mes}: {valor}, paga', ['mes' => monthLabel($f['mes']), 'valor' => num($v)])) : ($f['mes'] === $aberta ? e(t('Fatura de {mes}: {valor}, aberta', ['mes' => monthLabel($f['mes']), 'valor' => num($v)])) : e(t('Fatura de {mes}: {valor}', ['mes' => monthLabel($f['mes']), 'valor' => num($v)]))) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php $fs = $faturas[array_search($selValido, array_column($faturas, 'mes'), true)]; ?>
            <p class="ct-escada-leg"><output data-escada-leg><?= e(monthLabel($fs['mes'])) ?> · <b class="num"><?= e(num(max((float) $fs['total'], (float) ($fs['pago'] ?? 0.0)))) ?></b></output><span><?= e(t('toque num mês para ver a fatura')) ?></span></p>
        </section>
    </div>

    <div class="ct-lado">
        <p class="ct-lancar"><a class="btn btn-primary" href="<?= e($hrefLancar) ?>" data-lancar data-pagou="<?= e($pagouCartao) ?>"><?= svgIcon('plus', 18) ?><?= e(t('Lançar compra no cartão')) ?></a></p>

        <section class="card ct-parc-card" aria-labelledby="ct-parc-t">
            <div class="ct-parc-cab"><h2 id="ct-parc-t"><?= e(t('Parcelamentos')) ?></h2>
                <?php if ($p['parcelamentos'] !== []): ?><p class="num"><?= e(tn(count($p['parcelamentos']), '{valor} ainda a pagar em {n} compra', '{valor} ainda a pagar em {n} compras', ['valor' => num((float) $p['restante'])])) ?></p><?php endif; ?>
            </div>
            <?php if ($p['parcelamentos'] === []): ?>
                <p class="ct-escada-sub" style="margin-top:8px"><?= e(t('Nenhuma compra parcelada ainda. Compras parceladas aparecem aqui quando você as registra.')) ?></p>
            <?php else:
                $listaP = $p['parcelamentos'];
                $nomeP = static fn(array $x): string => mb_strtolower((string) $x['compra']['descricao']);
                match ($ordemp ?? '') {
                    'valor-desc'  => usort($listaP, static fn($a, $b) => $b['valor'] <=> $a['valor']),
                    'valor-asc'   => usort($listaP, static fn($a, $b) => $a['valor'] <=> $b['valor']),
                    'alfa'        => usort($listaP, static fn($a, $b) => strcoll($nomeP($a), $nomeP($b))),
                    'faltam-desc' => usort($listaP, static fn($a, $b) => [$b['faltam'], $b['restante']] <=> [$a['faltam'], $a['restante']]),
                    default       => null,                          // o padrão: a que acaba antes
                };
                $ordem = $ordem ?? ''; ?>
                <form class="ct-ordem" method="get" action="/cartao#ct-parc-t">
                    <?php foreach (array_filter(['cartao' => is_string($_GET['cartao'] ?? null) ? $_GET['cartao'] : '', 'fatura' => $selValido, 'ordem' => $ordem]) as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
                    <select name="ordemp" aria-label="<?= e(t('ordenar os parcelamentos')) ?>" data-autosubmit>
                        <?php foreach (['' => t('Acaba antes'), 'faltam-desc' => t('Mais parcelas faltando'), 'valor-desc' => t('Maior parcela'), 'valor-asc' => t('Menor parcela'), 'alfa' => t('De A a Z')] as $ov => $olbl): ?>
                            <option value="<?= e($ov) ?>"<?= ($ordemp ?? '') === $ov ? ' selected' : '' ?>><?= e($olbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button type="submit" class="btn btn-ghost"><?= e(t('Ordenar')) ?></button></noscript>
                </form>
                <ul class="ct-parc">
                    <?php foreach ($listaP as $pc): $c = $pc['compra']; ?>
                        <li><a class="ct-parc-i" href="/cartao/compras/<?= (int) $c['id'] ?>/editar">
                            <?= iconeCategoria((int) ($c['categoria_id'] ?? 0), (string) ($c['categoria_nome'] ?? $c['descricao']), (string) ($c['categoria_grupo'] ?? ''), 20) ?>
                            <span class="ct-parc-l1"><span><?= e(descricaoTela((string) $c['descricao'], ['nome' => $c['categoria_nome'] ?? '', 'apelido' => $c['categoria_apelido'] ?? ''])) ?></span><span class="num"><?= e(num((float) $pc['valor'])) ?></span></span>
                            <span class="trilho" aria-hidden="true"><i style="--w:<?= (int) round(($pc['agora'] - 1) / $pc['n'] * 100) ?>%"></i></span>
                            <span class="ct-parc-l3 num"><span><?= $pc['comeca'] > $aberta ? e(t('começa em {mes}', ['mes' => monthLabel($pc['comeca'])])) : e(t('Parcela {agora} de {n}. Faltam {faltam}, incluindo a fatura atual.', ['agora' => $pc['agora'], 'n' => $pc['n'], 'faltam' => $pc['faltam']])) ?></span><span><?= e(t('última em {mes}', ['mes' => monthLabel($pc['ultima'])])) ?></span></span>
                        </a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="card ct-dados-card" aria-labelledby="ct-dados-t">
            <details class="ct-config"<?= $erro !== null ? ' open' : '' ?>>
                <summary><h2 class="titulo" id="ct-dados-t"><?= e($cfg['nome']) ?></h2><span class="ct-config-acao"><span class="se-fechado"><?= e(t('Configurar')) ?></span><span class="se-aberto"><?= e(t('Fechar')) ?></span></span></summary>
                <?php $formConfig($cfg, $erro, $campo, false, $visual, false, $contas, $varios); ?>
            </details>
            <dl class="ct-dados num">
                <div><dt><?= e(t('Fecha')) ?></dt><dd><?= e(t('dia {n}', ['n' => (int) $cfg['fecha']])) ?></dd></div><div><dt><?= e(t('Vence')) ?></dt><dd><?= e(t('dia {n}', ['n' => (int) $cfg['vence']])) ?></dd></div>
                <div><dt><?= e(t('Limite')) ?></dt><dd><?= $cfg['limite'] !== null ? e(num((float) $cfg['limite'])) : '—' ?></dd></div>
                <div><dt><?= e(t('No plano')) ?></dt><dd><?= $cfg['alimenta_plano'] ? e(t('pelas parcelas')) : e(t('previsão digitada')) ?></dd></div>
            </dl>
            <p class="ct-escada-sub" style="margin-top:10px"><?= e(t('A fatura conta como um gasto no mês em que vence. As compras não são somadas duas vezes.')) ?></p>
        </section>
    </div>

    <section class="card ct-fatura" id="fatura" aria-label="<?= e(t('fatura escolhida')) ?>">
        <?php $ordem = $ordem ?? ''; ?>
        <form class="ct-ordem ct-ordem-fat" method="get" action="/cartao#fatura">
            <?php foreach (array_filter(['cartao' => is_string($_GET['cartao'] ?? null) ? $_GET['cartao'] : '', 'fatura' => $selValido, 'ordemp' => $ordemp ?? '']) as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
            <select name="ordem" aria-label="<?= e(t('ordenar a fatura')) ?>" data-autosubmit>
                <?php foreach (['' => t('Maior valor'), 'valor-asc' => t('Menor valor'), 'alfa' => t('De A a Z'), 'parcelas-desc' => t('Mais parcelas'), 'parcelas-asc' => t('Menos parcelas')] as $ov => $olbl): ?>
                    <option value="<?= e($ov) ?>"<?= $ordem === $ov ? ' selected' : '' ?>><?= e($olbl) ?></option>
                <?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="btn btn-ghost"><?= e(t('Ordenar')) ?></button></noscript>
        </form>
        <?php foreach ($faturas as $f):
            $itensF = $f['itens'];
            $nomeF = static fn(array $x): string => mb_strtolower((string) $x['compra']['descricao']);
            match ($ordem) {
                'valor-asc'     => usort($itensF, static fn($a, $b) => $a['valor'] <=> $b['valor']),
                'alfa'          => usort($itensF, static fn($a, $b) => strcoll($nomeF($a), $nomeF($b))),
                'parcelas-desc' => usort($itensF, static fn($a, $b) => [(int) $b['compra']['parcelas'], $b['valor']] <=> [(int) $a['compra']['parcelas'], $a['valor']]),
                'parcelas-asc'  => usort($itensF, static fn($a, $b) => [(int) $a['compra']['parcelas'], $b['valor']] <=> [(int) $b['compra']['parcelas'], $a['valor']]),
                default         => null,
            };
            // no padrão, parcelas e compras à vista em dois grupos; com outra ordem, uma lista só, na ordem pedida
            $grupos = $ordem === ''
                ? [[t('Parcelas'), array_values(array_filter($itensF, static fn($it) => (int) $it['compra']['parcelas'] > 1))],
                   [t('Compras à vista'), array_values(array_filter($itensF, static fn($it) => (int) $it['compra']['parcelas'] === 1))]]
                : [['', $itensF]];
            ?>
            <div class="ct-fat" id="<?= e($ancora($f['mes'])) ?>"<?= $f['mes'] === $selValido ? '' : ' hidden' ?>>
                <div class="ct-fat-cab"><h2><?= e(t('Fatura de {mes}', ['mes' => mesExtenso($f['mes'])])) ?> <span class="ct-fat-ano num"><?= e(substr($f['mes'], 0, 4)) ?></span></h2><b class="num"><?= e(num((float) $f['total'])) ?></b></div>
                <p class="ct-escada-sub"><?= e($statusTxt($f)) ?><?= $f['pago'] !== null ? ' · ' . e(t('pago no Extrato: {valor}', ['valor' => num((float) $f['pago'])])) : '' ?></p>
                <?php if ($f['itens'] === []): ?>
                    <p class="ct-vazio-fat"><?= $f['mes'] < $aberta ? e(t('As compras desta fatura não foram lançadas aqui.')) : e(t('Nenhuma compra nesta fatura ainda.')) ?></p>
                <?php endif; ?>
                <?php foreach ($grupos as [$rot, $lista]): if ($lista === []) { continue; } ?>
                    <?php if ($rot !== ''): ?><p class="ct-grupo"><?= e($rot) ?></p><?php endif; ?>
                    <?php foreach ($lista as $it): $c = $it['compra']; ?>
                        <a class="ct-linha" href="/cartao/compras/<?= (int) $c['id'] ?>/editar">
                            <?= iconeCategoria((int) ($c['categoria_id'] ?? 0), (string) ($c['categoria_nome'] ?? $c['descricao']), (string) ($c['categoria_grupo'] ?? ''), 20) ?>
                            <span class="ct-linha-n"><b><?= e(descricaoTela((string) $c['descricao'], ['nome' => $c['categoria_nome'] ?? '', 'apelido' => $c['categoria_apelido'] ?? ''])) ?></b>
                                <small><?= e(implode(' · ', array_filter([nomeCurto(['apelido' => $c['categoria_apelido'], 'nome' => $c['categoria_nome'] ?? '']),
                                    (int) $c['parcelas'] === 1 ? diaMes($c['data']) : t('Parcela {k} de {n}', ['k' => (int) $it['k'], 'n' => (int) $c['parcelas']])], static fn($x) => $x !== ''))) ?></small></span>
                            <span class="ct-linha-v num"><?= e(num((float) $it['valor'])) ?></span>
                        </a>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <?php if ($f['itens'] !== []): ?><p class="ct-fat-total num"><span><?= e(t('Total da fatura')) ?></span><?= e(num((float) $f['total'])) ?></p><?php endif; ?>
                <?php if (in_array($f['status'], ['fechada', 'vencida'], true) && !empty($temFatura)):
                    $falta = $f['falta'] ?? max(0.0, (float) $f['total'] - (float) ($f['pago'] ?? 0.0)); ?>
                    <details class="ct-pagar">
                        <summary class="btn btn-ghost"><?= e(t('Pagar fatura')) ?></summary>
                        <form class="ct-form" method="post" action="/cartao/pagar">
                            <?= csrfField() ?><input type="hidden" name="cartao" value="<?= (int) $cfg['id'] ?>"><input type="hidden" name="fatura" value="<?= e($f['mes']) ?>">
                            <div class="ct-2">
                                <label><span><?= e(t('Valor pago')) ?></span><input type="text" name="valor" inputmode="decimal" required value="<?= $falta > 0.004 ? e(numInput($falta)) : '' ?>"></label>
                                <label><span><?= e(t('Dia do pagamento')) ?></span><input type="date" name="data" required value="<?= e($hoje) ?>" max="<?= e($hoje) ?>"></label>
                            </div>
                            <?php if ($contas !== []): ?>
                                <label><span><?= e(t('Pagou com')) ?></span>
                                    <select name="conta_id">
                                        <?php foreach ($contas as $ct): ?><option value="<?= (int) $ct['id'] ?>"<?= (int) $ct['id'] === (int) $cfg['conta_id'] ? ' selected' : '' ?>><?= e(nomeConta($ct)) ?></option><?php endforeach; ?>
                                    </select></label>
                            <?php endif; ?>
                            <button class="btn btn-primary" type="submit"><?= e(t('Lançar o pagamento')) ?></button>
                        </form>
                    </details>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </section>
</div>
