<?php
/**
 * Aba Cartão. Sem cartão: o cadastro (nome, fechamento, vencimento, limite). Com cartão:
 *  · o cartão como objeto: a fatura aberta, quando fecha e vence, o limite livre;
 *  · a escada das próximas faturas (só as parcelas já lançadas); cada degrau é uma fatura e a escolhe;
 *  · a fatura escolhida (parcelas k/N e compras do mês, cada uma leva ao editar);
 *  · os parcelamentos, do que acaba antes para o que acaba depois; os dados do cartão.
 * Enquanto o cartão não alimenta o plano, um quadro compara a previsão digitada da Fatura do cartão
 * com a soma das parcelas lançadas, mês a mês, e oferece ligar.
 * Variáveis: $cartao (null = cadastrar), $painel, $hoje, $sel (fatura escolhida), $erro, $temCompras.
 */
$erro = is_array($erro ?? null) ? $erro : null;
$cfg  = $cartao ?? null;
$campo = static fn(string $k, string $padrao = ''): string => (string) ($erro[$k] ?? $padrao);
$plastico = static function (string $titulo, string $sub, string $valor, string $status, array $pe, bool $vazio = false): void { ?>
    <section class="ct-plastico<?= $vazio ? ' ct-vazio-plastico' : '' ?>" aria-label="<?= e($titulo) ?>">
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
$formConfig = static function (?array $cfg, ?array $erro, callable $campo, bool $primeira): void { ?>
    <form class="ct-form" method="post" action="/cartao/config" id="cartao-config">
        <?= csrfField() ?>
        <?php if ($erro !== null && isset($erro['msg'])): ?><p class="aviso aviso-error" role="alert"><?= e((string) $erro['msg']) ?></p><?php endif; ?>
        <label><span><?= e(t('Nome')) ?></span><input type="text" name="nome" maxlength="60" required placeholder="<?= e(t('Meu cartão')) ?>" value="<?= e($campo('nome', (string) ($cfg['nome'] ?? ''))) ?>"></label>
        <div class="ct-2">
            <label><span><?= e(t('Fecha no dia')) ?></span><input type="text" name="dia_fechamento" inputmode="numeric" maxlength="2" required placeholder="25" value="<?= e($campo('fecha', isset($cfg['fecha']) ? (string) $cfg['fecha'] : '')) ?>"></label>
            <label><span><?= e(t('Vence no dia')) ?></span><input type="text" name="dia_vencimento" inputmode="numeric" maxlength="2" required placeholder="5" value="<?= e($campo('vence', isset($cfg['vence']) ? (string) $cfg['vence'] : '')) ?>"></label>
        </div>
        <label><span><?= e(t('Limite (opcional)')) ?></span><input type="text" name="limite" inputmode="decimal" placeholder="<?= e(numInput(0)) ?>" value="<?= e($campo('limite', isset($cfg['limite']) ? numInput((float) $cfg['limite']) : '')) ?>"></label>
        <?php if ($primeira): ?>
            <p><?= e(t('Com o fechamento e o vencimento, o app sabe em que fatura cada compra cai. Depois de lançar as compras parceladas que já estão no cartão, você liga o cartão ao plano e a previsão da Fatura do cartão passa a sair delas.')) ?></p>
        <?php endif; ?>
        <button class="btn btn-primary" type="submit"><?= $primeira ? e(t('Salvar cartão')) : e(t('Salvar')) ?></button>
    </form>
<?php };

if ($cfg === null): ?>
<div class="ct">
    <header class="ct-topo"><div><h1><?= e(t('Cartão')) ?></h1><p><?= e(t('Primeiro, conte ao app quando a fatura fecha e vence.')) ?></p></div></header>
    <div class="ct-cima">
        <?php $plastico(t('Fatura do mês'), t('seu cartão'), '—', '', [[t('Fecha'), t('dia —')], [t('Vence'), t('dia —')]], true); ?>
        <section class="card" aria-labelledby="ct-cad-t">
            <h2 class="titulo" id="ct-cad-t"><?= e(t('Cadastre o cartão')) ?></h2>
            <?php $formConfig(null, $erro, $campo, true); ?>
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
    return match ($f['status']) {
        'aberta'   => $dias <= 1
            ? ($dias === 1 ? t('Aberta · fecha amanhã, dia {dia}', ['dia' => (int) substr($f['fecha'], 8, 2)]) : t('Aberta · fecha hoje, dia {dia}', ['dia' => (int) substr($f['fecha'], 8, 2)]))
            : t('Aberta · fecha em {n} dias, dia {dia}', ['n' => $dias, 'dia' => (int) substr($f['fecha'], 8, 2)]),
        // paga em parte: o que ainda falta da soma das parcelas
        'fechada'  => $f['falta'] !== null
            ? t('Fechada · vence dia {dia} · faltam {valor}', ['dia' => (int) substr($f['vence'], 8, 2), 'valor' => num((float) $f['falta'])])
            : t('Fechada · vence dia {dia}', ['dia' => (int) substr($f['vence'], 8, 2)]),
        // a data diz que o pagamento feito antes do mês do vencimento foi para esta fatura
        'paga'     => $f['pagaEm'] !== null ? t('Paga em {data}', ['data' => diaMes((string) $f['pagaEm'])]) : t('Paga'),
        'vencida'  => $f['falta'] !== null
            ? t('Venceu dia {dia} · faltam {valor}', ['dia' => (int) substr($f['vence'], 8, 2), 'valor' => num((float) $f['falta'])])
            : t('Venceu dia {dia} · lance o pagamento no Extrato', ['dia' => (int) substr($f['vence'], 8, 2)]),
        default    => t('Prevista · vence {data}', ['data' => diaMes($f['vence'])]),
    };
};
$ancora = static fn(string $m): string => 'f-' . substr($m, 0, 7);
$pe = [[t('Vence'), t('{dia} de {mes}', ['dia' => (int) substr($fAberta['vence'], 8, 2), 'mes' => mesExtenso($fAberta['vence'])])]];
if ($p['livre'] !== null) {
    $pe[] = [t('Limite livre'), t('{livre} de {limite}', ['livre' => num((float) $p['livre']), 'limite' => num((float) $cfg['limite'], 0)])];
}
$hrefLancar = '/lancar?pagou=cartao&volta=' . rawurlencode('/cartao');
?>
<div class="ct">
    <header class="ct-topo">
        <div><h1><?= e(t('Cartão')) ?></h1><p><?= e(t('{nome} · fecha dia {fecha} · vence dia {vence}', ['fecha' => (int) $cfg['fecha'], 'vence' => (int) $cfg['vence'], 'nome' => $cfg['nome']])) ?></p></div>
        <details class="menu mes-menu">
            <summary aria-label="<?= e(t('mais ações')) ?>"><?= svgIcon('more', 20) ?></summary>
            <div class="menu-painel">
                <a class="menu-item" href="#cartao-config"><?= svgIcon('settings', 18) ?><?= e(t('Configurar o cartão')) ?></a>
                <form method="post" action="/cartao/plano">
                    <?= csrfField() ?><input type="hidden" name="ligar" value="<?= $cfg['alimenta_plano'] ? '0' : '1' ?>">
                    <button type="submit" class="menu-item"><?= svgIcon('trend', 18) ?><?= $cfg['alimenta_plano'] ? e(t('Tirar do plano')) : e(t('Ligar ao plano')) ?></button>
                </form>
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
                        $dif = abs((float) ($f['previsto'] ?? 0.0) - (float) $f['total']) >= 0.01; ?>
                        <tr><th scope="row"><?= e(monthLabel($f['mes'])) ?></th><td><?= e(num((float) ($f['previsto'] ?? 0.0))) ?></td>
                            <td class="<?= $dif ? 'ct-dif' : 'ct-ok' ?>"><?= e(num((float) $f['total'])) ?><?= $dif ? '' : ' <span class="sr-only">' . e(t('(bate)')) . '</span>' ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <form method="post" action="/cartao/plano" class="ct-conf-acao">
                <?= csrfField() ?><input type="hidden" name="ligar" value="1">
                <button type="submit" class="btn"<?= $temCompras ? '' : ' disabled' ?>><?= e(t('Usar as faturas do cartão no plano')) ?></button>
            </form>
        </section>
    <?php endif; ?>

    <div class="ct-cima">
        <?php $plastico(t('Fatura de {mes}', ['mes' => mesExtenso($aberta)]), $cfg['nome'], num((float) $fAberta['total']), $statusTxt($fAberta), $pe); ?>
        <section class="card ct-escada-card" aria-labelledby="ct-esc-t">
            <div class="ct-escada-cab"><h2 id="ct-esc-t"><?= e(t('Próximas faturas')) ?></h2></div>
            <p class="ct-escada-sub"><?= e(t('Quanto já está comprometido em cada fatura. Novas compras somam a isso.')) ?></p>
            <div class="ct-escada">
                <?php foreach ($faturas as $j => $f):
                    $v = max((float) $f['total'], (float) ($f['pago'] ?? 0.0));
                    $cls = 'ct-degrau' . ($f['status'] === 'paga' ? ' is-paga' : '') . ($f['mes'] === $aberta ? ' is-aberta' : '');
                    $ano = $j === 0 || substr($f['mes'], 5, 2) === '01' ? substr($f['mes'], 0, 4) : '';
                    ?>
                    <a class="<?= $cls ?>" href="/cartao?fatura=<?= e($f['mes']) ?>#fatura" data-fatura="<?= e($ancora($f['mes'])) ?>"
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
        <p class="ct-lancar"><a class="btn btn-primary" href="<?= e($hrefLancar) ?>" data-lancar data-pagou="cartao"><?= svgIcon('plus', 18) ?><?= e(t('Lançar compra no cartão')) ?></a></p>

        <section class="card ct-parc-card" aria-labelledby="ct-parc-t">
            <div class="ct-parc-cab"><h2 id="ct-parc-t"><?= e(t('Parcelamentos')) ?></h2>
                <?php if ($p['parcelamentos'] !== []): ?><p class="num"><?= e(tn(count($p['parcelamentos']), '{valor} ainda a pagar em {n} compra', '{valor} ainda a pagar em {n} compras', ['valor' => num((float) $p['restante'])])) ?></p><?php endif; ?>
            </div>
            <?php if ($p['parcelamentos'] === []): ?>
                <p class="ct-escada-sub" style="margin-top:8px"><?= e(t('Nenhuma compra parcelada ainda. Compras parceladas aparecem aqui quando você as registra.')) ?></p>
            <?php else: ?>
                <ul class="ct-parc">
                    <?php foreach ($p['parcelamentos'] as $pc): $c = $pc['compra']; ?>
                        <li><a class="ct-parc-i" href="/cartao/compras/<?= (int) $c['id'] ?>/editar">
                            <?= iconeCategoria((int) ($c['categoria_id'] ?? 0), (string) ($c['categoria_nome'] ?? $c['descricao']), (string) ($c['categoria_grupo'] ?? ''), 40) ?>
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
                <?php $formConfig($cfg, $erro, $campo, false); ?>
            </details>
            <dl class="ct-dados num">
                <div><dt><?= e(t('Fecha')) ?></dt><dd><?= e(t('dia {n}', ['n' => (int) $cfg['fecha']])) ?></dd></div><div><dt><?= e(t('Vence')) ?></dt><dd><?= e(t('dia {n}', ['n' => (int) $cfg['vence']])) ?></dd></div>
                <div><dt><?= e(t('Limite')) ?></dt><dd><?= $cfg['limite'] !== null ? e(num((float) $cfg['limite'], 0)) : '—' ?></dd></div>
                <div><dt><?= e(t('No plano')) ?></dt><dd><?= $cfg['alimenta_plano'] ? e(t('pelas parcelas')) : e(t('previsão digitada')) ?></dd></div>
            </dl>
            <p class="ct-escada-sub" style="margin-top:10px"><?= e(t('A fatura conta como um gasto no mês em que vence. As compras não são somadas duas vezes.')) ?></p>
        </section>
    </div>

    <section class="card ct-fatura" id="fatura" aria-label="<?= e(t('fatura escolhida')) ?>">
        <?php foreach ($faturas as $f):
            $parcs = array_values(array_filter($f['itens'], static fn($it) => (int) $it['compra']['parcelas'] > 1));
            $avista = array_values(array_filter($f['itens'], static fn($it) => (int) $it['compra']['parcelas'] === 1));
            ?>
            <div class="ct-fat" id="<?= e($ancora($f['mes'])) ?>"<?= $f['mes'] === $selValido ? '' : ' hidden' ?>>
                <div class="ct-fat-cab"><h2><?= e(t('Fatura de {mes}', ['mes' => mesExtenso($f['mes'])])) ?> <span class="ct-fat-ano num"><?= e(substr($f['mes'], 0, 4)) ?></span></h2><b class="num"><?= e(num((float) $f['total'])) ?></b></div>
                <p class="ct-escada-sub"><?= e($statusTxt($f)) ?><?= $f['pago'] !== null ? ' · ' . e(t('pago no Extrato: {valor}', ['valor' => num((float) $f['pago'])])) : '' ?></p>
                <?php if ($f['itens'] === []): ?>
                    <p class="ct-vazio-fat"><?= $f['mes'] < $aberta ? e(t('As compras desta fatura não foram lançadas aqui.')) : e(t('Nenhuma compra nesta fatura ainda.')) ?></p>
                <?php endif; ?>
                <?php foreach ([[t('Parcelas'), $parcs], [t('Compras à vista'), $avista]] as [$rot, $lista]): if ($lista === []) { continue; } ?>
                    <p class="ct-grupo"><?= e($rot) ?></p>
                    <?php foreach ($lista as $it): $c = $it['compra']; ?>
                        <a class="ct-linha" href="/cartao/compras/<?= (int) $c['id'] ?>/editar">
                            <?= iconeCategoria((int) ($c['categoria_id'] ?? 0), (string) ($c['categoria_nome'] ?? $c['descricao']), (string) ($c['categoria_grupo'] ?? ''), 40) ?>
                            <span class="ct-linha-n"><b><?= e(descricaoTela((string) $c['descricao'], ['nome' => $c['categoria_nome'] ?? '', 'apelido' => $c['categoria_apelido'] ?? ''])) ?></b>
                                <small><?= e(implode(' · ', array_filter([nomeCurto(['apelido' => $c['categoria_apelido'], 'nome' => $c['categoria_nome'] ?? '']),
                                    (int) $c['parcelas'] === 1 ? diaMes($c['data']) : t('Parcela {k} de {n}', ['k' => (int) $it['k'], 'n' => (int) $c['parcelas']])], static fn($x) => $x !== ''))) ?></small></span>
                            <span class="ct-linha-v num"><?= e(num((float) $it['valor'])) ?></span>
                        </a>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <?php if ($f['itens'] !== []): ?><p class="ct-fat-total num"><span><?= e(t('Total da fatura')) ?></span><?= e(num((float) $f['total'])) ?></p><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </section>
</div>
