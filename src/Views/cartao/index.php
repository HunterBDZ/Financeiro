<?php
/**
 * Aba Cartão. Sem cartão: o cadastro (nome, fechamento, vencimento, limite e o visual). Com cartão, de cima para baixo:
 *  · o topo: "Cartão", e numa linha só o nome, o fechamento, o vencimento e o limite; com vários, o seletor de cartões;
 *  · o momento: o cartão desenhado, liso, na cor do visual escolhido (CartaoVisual; sem escolha, a tinta do tema), e
 *    ao lado a fatura aberta (o valor grande, a situação, o limite livre) e o gráfico das próximas faturas: o fato em
 *    tinta cheia, o que ainda vai cair tracejado; cada barra escolhe a fatura mostrada embaixo (cartao.js; sem JS, link);
 *  · a fatura escolhida e os parcelamentos, como tabelas, com a ordem de cada uma;
 *  · enquanto o cartão não alimenta o plano, o quadro que compara a previsão digitada com as parcelas lançadas;
 *  · o configurar (o nome, os dias, o limite, a conta que paga e o visual).
 * Uma fatura fechada ou vencida tem "Pagar fatura", que lança o pagamento já ligado a ela.
 * Variáveis: $cartao (null = cadastrar), $cartoes (os ativos), $contas (as ativas, só com mais de uma), $painel,
 * $hoje, $sel (fatura escolhida), $erro, $temCompras, $lancadoTodos (com vários cartões, a soma das faturas de todos),
 * $temFatura, $ordem, $ordemp.
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

/** O cartão desenhado: liso, na cor do visual, com o nome e o sinal do app. Sem chip, sem reflexo, sem pílula. */
$plastico = static function (string $nome, ?string $visual, bool $vazio = false): void { ?>
    <div class="ct-plastico<?= $vazio ? ' is-vazio' : '' ?>"<?= CartaoVisual::atributos($visual) ?> role="img" aria-label="<?= e(t('Cartão {nome}', ['nome' => $nome])) ?>" data-ct-plastico>
        <span class="ct-plastico-nome"><?= e($nome) ?></span>
        <span class="ct-plastico-marca" aria-hidden="true"><?= svgIcon('marca', 20) ?></span>
    </div>
<?php };

$formConfig = static function (?array $cfg, ?array $erro, callable $campo, bool $primeira, ?string $visual, bool $novo = false, array $contas = [], bool $comId = false): void {
    $marcado = CartaoVisual::escolha($visual);
    $cor = $marcado === 'propria' ? (string) $visual : CartaoVisual::COR_INICIAL; ?>
    <form class="ct-form" method="post" action="/cartao/config" id="cartao-config">
        <?= csrfField() ?>
        <?php if ($novo): ?><input type="hidden" name="novo" value="1"><?php elseif ($cfg !== null && $comId): ?><input type="hidden" name="id" value="<?= (int) $cfg['id'] ?>"><?php endif; ?>
        <?php if ($erro !== null && isset($erro['msg'])): ?><p class="ct-erro" role="alert"><?= svgIcon('alert', 16) ?><?= e((string) $erro['msg']) ?></p><?php endif; ?>
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
            <p class="ct-nota"><?= e(t('Com o fechamento e o vencimento, o app sabe em que fatura cada compra cai. Depois de lançar as compras parceladas que já estão no cartão, você liga o cartão ao plano e a previsão da Fatura do cartão passa a sair delas.')) ?></p>
        <?php endif; ?>
        <div><button class="btn btn-principal" type="submit"><?= $primeira ? e(t('Salvar cartão')) : e(t('Salvar')) ?></button></div>
    </form>
<?php };

if ($cfg === null): $novo = $cartoes !== []; ?>
<div class="grade ct">
    <?php \Financeiro\View::partial('partials/topo', $novo
        ? ['titulo' => t('Novo cartão'), 'apoio' => t('Os dias de fechamento e de vencimento dizem em que fatura cada compra cai.'),
           'acoes' => '<a class="btn btn-contorno btn-sm" href="/cartao">' . e(t('Voltar ao cartão')) . '</a>']
        : ['titulo' => t('Cartão'), 'apoio' => t('Primeiro, conte ao app quando a fatura fecha e vence.')]); ?>
    <section class="faixa-grade ct-cadastro" aria-labelledby="ct-cad-t">
        <div class="ct-cadastro-cartao"><?php $plastico($novo ? t('Novo cartão') : t('Seu cartão'), $visual, true); ?></div>
        <div>
            <h2 class="titulo" id="ct-cad-t"><?= $novo ? e(t('Cadastre o novo cartão')) : e(t('Cadastre o cartão')) ?></h2>
            <?php $formConfig(null, $erro, $campo, !$novo, $visual, $novo, $contas); ?>
        </div>
    </section>
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
// a situação de uma fatura, em uma frase (vírgulas, sem "·")
$statusTxt = static function (array $f) use ($hoje): string {
    $dias = (int) round((strtotime($f['fecha']) - strtotime($hoje)) / 86400);
    $util = ($f['pagaAte'] ?? $f['vence']) !== $f['vence'];
    return match ($f['status']) {
        'aberta'   => $dias <= 1
            ? ($dias === 1 ? t('Aberta, fecha amanhã, dia {dia}', ['dia' => (int) substr($f['fecha'], 8, 2)]) : t('Aberta, fecha hoje, dia {dia}', ['dia' => (int) substr($f['fecha'], 8, 2)]))
            : t('Aberta, fecha em {n} dias, dia {dia}', ['n' => $dias, 'dia' => (int) substr($f['fecha'], 8, 2)]),
        // paga em parte: o que ainda falta da soma das parcelas; vence num dia que não é útil: até quando dá para pagar
        'fechada'  => $util
            ? ($f['falta'] !== null
                ? t('Fechada, vence {data} e pode ser paga até {util}; faltam {valor}', ['data' => diaMes($f['vence']), 'util' => diaMes($f['pagaAte']), 'valor' => num((float) $f['falta'])])
                : t('Fechada, vence {data} e pode ser paga até {util}', ['data' => diaMes($f['vence']), 'util' => diaMes($f['pagaAte'])]))
            : ($f['falta'] !== null
                ? t('Fechada, vence dia {dia}; faltam {valor}', ['dia' => (int) substr($f['vence'], 8, 2), 'valor' => num((float) $f['falta'])])
                : t('Fechada, vence dia {dia}', ['dia' => (int) substr($f['vence'], 8, 2)])),
        // a data diz que o pagamento feito antes do mês do vencimento foi para esta fatura
        'paga'     => $f['pagaEm'] !== null ? t('Paga em {data}', ['data' => diaMes((string) $f['pagaEm'])]) : t('Paga'),
        'vencida'  => $f['falta'] !== null
            ? t('Venceu dia {dia}; faltam {valor}', ['dia' => (int) substr($f['vence'], 8, 2), 'valor' => num((float) $f['falta'])])
            : t('Venceu dia {dia}; lance o pagamento no Extrato', ['dia' => (int) substr($f['vence'], 8, 2)]),
        default    => $util
            ? t('Prevista, vence {data} e pode ser paga até {util}', ['data' => diaMes($f['vence']), 'util' => diaMes($f['pagaAte'])])
            : t('Prevista, vence {data}', ['data' => diaMes($f['vence'])]),
    };
};
$ancora = static fn(string $m): string => 'f-' . substr($m, 0, 7);
$pagouCartao = $varios ? 'cartao:' . (int) $cfg['id'] : 'cartao';
$hrefLancar = '/lancar?pagou=' . rawurlencode($pagouCartao) . '&volta=' . rawurlencode($urlCartao());
$nomeCompra = static fn(array $c): string => descricaoTela((string) $c['descricao'], ['nome' => $c['categoria_nome'] ?? '', 'apelido' => $c['categoria_apelido'] ?? '']);
$catCompra = static fn(array $c): string => nomeCurto(['apelido' => $c['categoria_apelido'] ?? null, 'nome' => $c['categoria_nome'] ?? '']);
$valorFat = static fn(array $f): float => max((float) $f['total'], (float) ($f['pago'] ?? 0.0));

// o cabeçalho: o nome, o fechamento, o vencimento e o limite, uma vez só
$apoio = $cfg['limite'] !== null
    ? t('{nome}: fecha dia {fecha}, vence dia {vence}, limite de {limite}', ['nome' => $cfg['nome'], 'fecha' => (int) $cfg['fecha'], 'vence' => (int) $cfg['vence'], 'limite' => num((float) $cfg['limite'])])
    : t('{nome}: fecha dia {fecha}, vence dia {vence}', ['nome' => $cfg['nome'], 'fecha' => (int) $cfg['fecha'], 'vence' => (int) $cfg['vence']]);
ob_start(); ?>
<a class="btn btn-contorno ct-lancar" href="<?= e($hrefLancar) ?>" data-lancar data-pagou="<?= e($pagouCartao) ?>"><?= svgIcon('plus', 16) ?><?= e(t('Lançar compra')) ?></a>
<details class="menu ct-menu">
    <summary aria-label="<?= e(t('mais ações')) ?>"><?= svgIcon('more', 20) ?></summary>
    <div class="menu-painel">
        <a class="menu-item" href="#cartao-config" data-ct-configurar><?= svgIcon('settings', 18) ?><?= e(t('Configurar o cartão')) ?></a>
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
<?php $acoes = (string) ob_get_clean();
$fs = $faturas[array_search($selValido, array_column($faturas, 'mes'), true)];
?>
<div class="grade ct">
    <?php \Financeiro\View::partial('partials/topo', ['titulo' => t('Cartão'), 'apoio' => $apoio, 'acoes' => $acoes]); ?>

    <?php if ($varios): ?>
        <nav class="faixa-grade ct-cartoes" aria-label="<?= e(t('cartões')) ?>">
            <?php foreach ($cartoes as $c): ?>
                <a class="ct-cartao-i<?= $c['id'] === $cfg['id'] ? ' is-on' : '' ?>" href="/cartao?cartao=<?= (int) $c['id'] ?>"<?= $c['id'] === $cfg['id'] ? ' aria-current="page"' : '' ?>>
                    <span class="ct-cartao-cor"<?= CartaoVisual::atributos($c['visual'] ?? null) ?> aria-hidden="true"></span><?= e($c['nome']) ?>
                </a>
            <?php endforeach; ?>
            <a class="btn-link ct-novo" href="/cartao?novo=1"><?= svgIcon('plus', 16) ?><?= e(t('Novo cartão')) ?></a>
        </nav>
    <?php endif; ?>

    <!-- o momento: o cartão e a fatura aberta, com as próximas faturas -->
    <section class="faixa-grade ct-momento" aria-labelledby="ct-aberta-t">
        <div class="ct-momento-cartao"><?php $plastico((string) $cfg['nome'], $visual); ?></div>
        <div class="ct-aberta">
            <h2 class="rot-resposta" id="ct-aberta-t"><?= e(t('Fatura de {mes}', ['mes' => mesExtenso($aberta)])) ?></h2>
            <p class="resposta-num ct-aberta-v"><?= valor((float) $fAberta['total'], 'neutro', ['moeda' => true]) ?></p>
            <p class="ct-aberta-sit"><?= e($statusTxt($fAberta)) ?></p>
            <?php if ($p['livre'] !== null): $usado = (float) $cfg['limite'] > 0.004 ? max(0.0, min(1.0, 1 - (float) $p['livre'] / (float) $cfg['limite'])) : 0.0; ?>
                <div class="ct-limite">
                    <p class="num"><?= t('Livre {livre} de {limite}', ['livre' => '<b>' . e(num((float) $p['livre'])) . '</b>', 'limite' => e(num((float) $cfg['limite']))]) ?></p>
                    <span class="meta-barra<?= (float) $p['livre'] < -0.004 ? ' passou' : '' ?>" aria-hidden="true"><i style="--p:<?= e(number_format($usado, 3, '.', '')) ?>"></i></span>
                </div>
            <?php endif; ?>

            <div class="ct-graf" role="group" aria-labelledby="ct-graf-t">
                <div class="ct-graf-cab">
                    <h3 id="ct-graf-t"><?= e(t('Próximas faturas')) ?></h3>
                    <output class="num" data-escada-leg><?= e(monthLabel($fs['mes'])) ?>: <b><?= e(num($valorFat($fs))) ?></b></output>
                </div>
                <ol class="ct-barras" style="--n:<?= count($faturas) ?>">
                    <?php foreach ($faturas as $j => $f):
                        $v = $valorFat($f);
                        $estado = $f['status'] === 'paga' ? 'paga' : ($f['mes'] === $aberta ? 'aberta' : ($f['mes'] > $aberta ? 'prevista' : 'fechada'));
                        $ano = $j === 0 || substr($f['mes'], 5, 2) === '01' ? substr($f['mes'], 0, 4) : '';
                        $sr = match ($estado) {
                            'paga' => t('Fatura de {mes}: {valor}, paga', ['mes' => monthLabel($f['mes']), 'valor' => num($v)]),
                            'aberta' => t('Fatura de {mes}: {valor}, aberta', ['mes' => monthLabel($f['mes']), 'valor' => num($v)]),
                            default => t('Fatura de {mes}: {valor}', ['mes' => monthLabel($f['mes']), 'valor' => num($v)]),
                        }; ?>
                        <li class="<?= $j % 2 === 1 ? 'is-par' : '' ?>">
                            <a class="ct-barra is-<?= $estado ?>" href="<?= e($urlCartao('fatura=' . $f['mes'])) ?>#fatura" data-fatura="<?= e($ancora($f['mes'])) ?>"
                               data-rot="<?= e(monthLabel($f['mes'])) ?>" data-v="<?= e(num($v)) ?>"<?= $f['mes'] === $selValido ? ' aria-current="true"' : '' ?>>
                                <span class="ct-barra-b" aria-hidden="true"><i style="--h:<?= number_format(max(2.0, $v / $topo * 100), 1, '.', '') ?>%"></i></span>
                                <span class="ct-barra-m"><?= e(mesCurto($f['mes'])) ?></span><span class="ct-barra-a"><?= $ano !== '' ? e($ano) : '' ?></span>
                                <span class="sr-only"><?= e($sr) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <p class="ct-graf-leg"><span class="ct-leg-fato" aria-hidden="true"></span><?= e(t('fechada ou aberta')) ?><span class="ct-leg-prev" aria-hidden="true"></span><?= e(t('parcelas que ainda vão cair')) ?></p>
            </div>
        </div>
    </section>

    <?php if (!$cfg['alimenta_plano']): ?>
        <section class="faixa-grade ct-conf" aria-labelledby="ct-conf-t">
            <div class="secao-cab"><h2 id="ct-conf-t"><?= e(t('O plano ainda usa a previsão digitada da Fatura do cartão')) ?></h2></div>
            <p class="ct-nota"><?= e(t('Lance as compras parceladas que já estão no cartão (em "Outras vezes ou compra antiga", diga qual parcela cai na fatura aberta). Quando as faturas baterem, ligue o cartão ao plano.')) ?></p>
            <table class="ct-tab ct-conf-tab">
                <thead><tr><th scope="col"><?= e(t('Fatura')) ?></th><th scope="col" class="ct-c-num"><?= e(t('Previsão de hoje')) ?></th><th scope="col" class="ct-c-num"><?= e(t('Lançado no cartão')) ?></th></tr></thead>
                <tbody>
                    <?php foreach (array_slice(array_filter($faturas, static fn($f) => $f['mes'] >= $aberta), 0, 6) as $f):
                        $lancadoF = $varios ? (float) ($lancadoTodos[$f['mes']] ?? 0.0) : (float) $f['total'];
                        $dif = abs((float) ($f['previsto'] ?? 0.0) - $lancadoF) >= 0.01; ?>
                        <tr><th scope="row"><?= e(monthLabel($f['mes'])) ?></th><td class="ct-c-num num v-previsto"><?= e(num((float) ($f['previsto'] ?? 0.0))) ?></td>
                            <td class="ct-c-num num<?= $dif ? ' ct-dif' : '' ?>"><?= e(num($lancadoF)) ?> <?= $dif ? '<small>' . e(t('diferente')) . '</small>' : '<small>' . e(t('bate')) . '</small>' ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <form method="post" action="/cartao/plano" class="ct-conf-acao">
                <?= csrfField() ?><?php if ($varios): ?><input type="hidden" name="cartao" value="<?= (int) $cfg['id'] ?>"><?php endif; ?><input type="hidden" name="ligar" value="1">
                <button type="submit" class="btn btn-contorno"<?= $temCompras ? '' : ' disabled' ?>><?= e(t('Usar as faturas do cartão no plano')) ?></button>
            </form>
        </section>
    <?php endif; ?>

    <div class="faixa-grade ct-tabelas">
        <!-- a fatura escolhida -->
        <section class="ct-fatura" id="fatura" aria-label="<?= e(t('fatura escolhida')) ?>">
            <?php $ordem = $ordem ?? '';
            foreach ($faturas as $f):
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
                    <div class="secao-cab">
                        <h2><?= e(t('Fatura de {mes}', ['mes' => mesAno($f['mes'])])) ?></h2>
                        <?php if ($f['itens'] !== []): ?>
                            <form class="ct-ordem" method="get" action="/cartao#fatura">
                                <?php foreach (array_filter(['cartao' => is_string($_GET['cartao'] ?? null) ? $_GET['cartao'] : '', 'fatura' => $f['mes'], 'ordemp' => $ordemp ?? '']) as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
                                <select name="ordem" aria-label="<?= e(t('ordenar a fatura')) ?>" data-autosubmit>
                                    <?php foreach (['' => t('Maior valor'), 'valor-asc' => t('Menor valor'), 'alfa' => t('De A a Z'), 'parcelas-desc' => t('Mais parcelas'), 'parcelas-asc' => t('Menos parcelas')] as $ov => $olbl): ?>
                                        <option value="<?= e($ov) ?>"<?= $ordem === $ov ? ' selected' : '' ?>><?= e($olbl) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <noscript><button type="submit" class="btn btn-contorno btn-sm"><?= e(t('Ordenar')) ?></button></noscript>
                            </form>
                        <?php endif; ?>
                    </div>
                    <p class="ct-fat-sit"><?= e($statusTxt($f)) ?><?= $f['pago'] !== null ? '. ' . e(t('Pago no Extrato: {valor}', ['valor' => num((float) $f['pago'])])) : '' ?></p>
                    <?php if ($f['itens'] === []): ?>
                        <p class="ct-vazio-fat"><?= $f['mes'] < $aberta ? e(t('As compras desta fatura não foram lançadas aqui.')) : e(t('Nenhuma compra nesta fatura ainda. As compras no cartão entram pelo Lançar, em “Pagou com: Cartão”.')) ?></p>
                    <?php else: ?>
                        <table class="ct-tab">
                            <caption class="sr-only"><?= e(t('Compras da fatura de {mes}', ['mes' => monthLabel($f['mes'])])) ?></caption>
                            <thead><tr><th scope="col"><?= e(t('Compra')) ?></th><th scope="col" class="ct-c-parc"><?= e(t('Parcela ou dia')) ?></th><th scope="col" class="ct-c-num"><?= e(t('Valor')) ?></th></tr></thead>
                            <?php foreach ($grupos as [$rot, $lista]): if ($lista === []) { continue; } ?>
                                <tbody>
                                    <?php if ($rot !== ''): ?><tr class="ct-grupo"><th scope="rowgroup" colspan="3"><?= e($rot) ?></th></tr><?php endif; ?>
                                    <?php foreach ($lista as $it): $c = $it['compra']; ?>
                                        <tr class="ct-linha">
                                            <td class="ct-c-nome">
                                                <span class="ct-nome"><?= iconeCategoria((int) ($c['categoria_id'] ?? 0), (string) ($c['categoria_nome'] ?? $c['descricao']), (string) ($c['categoria_grupo'] ?? ''), 20, 'ct-ic') ?>
                                                <span class="ct-nome-txt"><a href="/cartao/compras/<?= (int) $c['id'] ?>/editar"><?= e($nomeCompra($c)) ?></a><small><?= e($catCompra($c)) ?></small></span></span>
                                            </td>
                                            <td class="ct-c-parc num"><?= (int) $c['parcelas'] === 1 ? e(diaMes($c['data'])) : e(t('{k} de {n}', ['k' => (int) $it['k'], 'n' => (int) $c['parcelas']])) ?></td>
                                            <td class="ct-c-num"><?= valor((float) $it['valor'], 'neutro', ['centavos' => false]) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            <?php endforeach; ?>
                            <tfoot><tr><th scope="row" colspan="2"><?= e(t('Total da fatura')) ?></th><td class="ct-c-num"><?= valor((float) $f['total'], 'neutro', ['centavos' => false]) ?></td></tr></tfoot>
                        </table>
                    <?php endif; ?>
                    <?php if (in_array($f['status'], ['fechada', 'vencida'], true) && !empty($temFatura)):
                        $falta = $f['falta'] ?? max(0.0, (float) $f['total'] - (float) ($f['pago'] ?? 0.0)); ?>
                        <details class="ct-pagar">
                            <summary class="btn btn-contorno"><?= e(t('Pagar fatura')) ?></summary>
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
                                <div><button class="btn btn-principal" type="submit"><?= e(t('Lançar o pagamento')) ?></button></div>
                            </form>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>

        <!-- os parcelamentos, do que acaba antes para o que acaba depois -->
        <section class="ct-parcelas" aria-labelledby="ct-parc-t">
            <div class="secao-cab">
                <h2 id="ct-parc-t"><?= e(t('Parcelamentos')) ?></h2>
                <?php if ($p['parcelamentos'] !== []): ?>
                    <form class="ct-ordem" method="get" action="/cartao#ct-parc-t">
                        <?php foreach (array_filter(['cartao' => is_string($_GET['cartao'] ?? null) ? $_GET['cartao'] : '', 'fatura' => $selValido, 'ordem' => $ordem ?? '']) as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
                        <select name="ordemp" aria-label="<?= e(t('ordenar os parcelamentos')) ?>" data-autosubmit>
                            <?php foreach (['' => t('Acaba antes'), 'faltam-desc' => t('Mais parcelas faltando'), 'valor-desc' => t('Maior parcela'), 'valor-asc' => t('Menor parcela'), 'alfa' => t('De A a Z')] as $ov => $olbl): ?>
                                <option value="<?= e($ov) ?>"<?= ($ordemp ?? '') === $ov ? ' selected' : '' ?>><?= e($olbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <noscript><button type="submit" class="btn btn-contorno btn-sm"><?= e(t('Ordenar')) ?></button></noscript>
                    </form>
                <?php endif; ?>
            </div>
            <?php if ($p['parcelamentos'] === []): ?>
                <p class="ct-vazio-fat"><?= e(t('Nenhuma compra parcelada ainda. Compras parceladas aparecem aqui quando você as registra.')) ?></p>
            <?php else:
                $listaP = $p['parcelamentos'];
                $nomeP = static fn(array $x): string => mb_strtolower((string) $x['compra']['descricao']);
                match ($ordemp ?? '') {
                    'valor-desc'  => usort($listaP, static fn($a, $b) => $b['valor'] <=> $a['valor']),
                    'valor-asc'   => usort($listaP, static fn($a, $b) => $a['valor'] <=> $b['valor']),
                    'alfa'        => usort($listaP, static fn($a, $b) => strcoll($nomeP($a), $nomeP($b))),
                    'faltam-desc' => usort($listaP, static fn($a, $b) => [$b['faltam'], $b['restante']] <=> [$a['faltam'], $a['restante']]),
                    default       => null,                          // o padrão: a que acaba antes
                }; ?>
                <p class="ct-fat-sit num"><?= e(tn(count($p['parcelamentos']), '{valor} ainda a pagar em {n} compra', '{valor} ainda a pagar em {n} compras', ['valor' => num((float) $p['restante'])])) ?></p>
                <table class="ct-tab">
                    <caption class="sr-only"><?= e(t('Parcelamentos')) ?></caption>
                    <thead><tr><th scope="col"><?= e(t('Compra')) ?></th><th scope="col" class="ct-c-parc"><?= e(t('Parcela')) ?></th><th scope="col" class="ct-c-num"><?= e(t('Por mês')) ?></th><th scope="col" class="ct-c-fim"><?= e(t('Última')) ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($listaP as $pc): $c = $pc['compra']; ?>
                            <tr class="ct-linha">
                                <td class="ct-c-nome">
                                    <span class="ct-nome"><?= iconeCategoria((int) ($c['categoria_id'] ?? 0), (string) ($c['categoria_nome'] ?? $c['descricao']), (string) ($c['categoria_grupo'] ?? ''), 20, 'ct-ic') ?>
                                    <span class="ct-nome-txt"><a href="/cartao/compras/<?= (int) $c['id'] ?>/editar"><?= e($nomeCompra($c)) ?></a><small><?= e($catCompra($c)) ?></small></span></span>
                                </td>
                                <td class="ct-c-parc num"><?= $pc['comeca'] > $aberta ? e(t('começa em {mes}', ['mes' => monthLabel($pc['comeca'])])) : e(t('{k} de {n}', ['k' => $pc['agora'], 'n' => $pc['n']])) ?></td>
                                <td class="ct-c-num"><?= valor((float) $pc['valor'], 'neutro', ['centavos' => false]) ?></td>
                                <td class="ct-c-fim num"><?= e(monthLabel($pc['ultima'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </div>

    <!-- configurar: o nome, os dias, o limite e o visual -->
    <section class="faixa-grade ct-config-faixa" aria-labelledby="ct-dados-t">
        <details class="ct-config"<?= $erro !== null ? ' open' : '' ?>>
            <summary><h2 class="titulo" id="ct-dados-t"><?= e(t('Configurar {nome}', ['nome' => $cfg['nome']])) ?></h2></summary>
            <?php $formConfig($cfg, $erro, $campo, false, $visual, false, $contas, $varios); ?>
        </details>
        <p class="ct-nota"><?= $cfg['alimenta_plano'] ? e(t('No plano, a Fatura do cartão sai das parcelas lançadas.')) : e(t('No plano, a Fatura do cartão ainda usa a previsão digitada.')) ?> <?= e(t('A fatura conta como um gasto no mês em que vence. As compras não são somadas duas vezes.')) ?></p>
    </section>
</div>
