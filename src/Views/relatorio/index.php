<?php
/**
 * Aba Relatório: $tipo, $periodos e $r (RelatorioDados::mensal ou ::anual, null sem período). A direção é a do
 * relatório impresso: uma frase-resultado curta no topo, a cascata "Como a conta chegou até aqui" como peça central
 * (o realizado em tinta cheia, o planejado tracejado e chamado de planejado, o guardado neutro, nunca vermelho de
 * gasto) e as seções em fios. O PDF (Pdf\RelatorioPdf) segue o mesmo desenho, em papel claro.
 * Os nomes: "Entrou" é o que entrou no mês, sem o saldo de antes; "Gastou", o gasto do mês; o guardado e o
 * empréstimo têm o nome deles.
 */
$parametros = $r === null ? '' : http_build_query($r['tipo'] === 'anual' ? ['tipo' => 'anual', 'ano' => $r['ano']] : ['tipo' => 'mensal', 'mes' => $r['mes']]);
$itens = [['bom', t('O que foi bom'), t('Nada relevante neste período.')], ['ruim', t('O que foi ruim'), t('Nada relevante neste período.')], ['melhorar', t('O que pode melhorar'), t('Nenhum corte simples muda o resultado agora.')]];

// o topo: Mensal ou Anual, o período (o seletor de mês, ou o ano) e o PDF
$mesesSel = [];
foreach (array_reverse($periodos['meses']) as $m) {
    $mesesSel[] = ['mes' => $m['mes'], 'situacao' => $m['estado'] === 'real' ? 'REAL' : 'PREVISAO'];
}
ob_start(); ?>
<nav class="rl-tipo" aria-label="<?= e(t('Tipo de relatório')) ?>">
    <a class="rl-tipo-b<?= $tipo === 'mensal' ? ' is-on' : '' ?>" href="/relatorio?tipo=mensal"<?= $tipo === 'mensal' ? ' aria-current="page"' : '' ?>><?= e(t('Mensal')) ?></a>
    <a class="rl-tipo-b<?= $tipo === 'anual' ? ' is-on' : '' ?>" href="/relatorio?tipo=anual"<?= $tipo === 'anual' ? ' aria-current="page"' : '' ?>><?= e(t('Anual')) ?></a>
</nav>
<?php if ($r !== null && $tipo === 'anual'): ?>
    <form class="rl-periodo" method="get" action="/relatorio" data-rl-periodo>
        <input type="hidden" name="tipo" value="anual">
        <label class="sr-only" for="rl-sel"><?= e(t('Ano')) ?></label>
        <select id="rl-sel" name="ano">
            <?php foreach ($periodos['anos'] as $a): ?><option value="<?= (int) $a ?>"<?= $a === $r['ano'] ? ' selected' : '' ?>><?= (int) $a ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-contorno rl-ver" data-rl-ver><?= e(t('Ver')) ?></button>
    </form>
<?php endif; ?>
<?php if ($r !== null): ?>
    <a class="btn btn-contorno rl-baixar" href="/relatorio/pdf?<?= e($parametros) ?>" data-espera="<?= e(t('Gerando o PDF…')) ?>" data-espera-baixa><?= svgIcon('download', 16) ?><?= e(t('Baixar PDF')) ?></a>
<?php endif; ?>
<?php $acoes = (string) ob_get_clean();
$topo = ['titulo' => t('Relatório'), 'apoio' => $r !== null ? $r['titulo'] : '', 'acoes' => $acoes];
if ($r !== null && $tipo === 'mensal') {
    $topo += ['mes' => $r['mes'], 'meses' => $mesesSel, 'href' => '/relatorio?tipo=mensal&mes=', 'hoje' => $r['hoje']];
}

// a cascata: cada degrau começa onde o anterior parou; a barra é a faixa entre os dois, na escala de todos
$faixas = [];
if ($r !== null && $r['tipo'] === 'mensal') {
    $corrente = 0.0;
    foreach ($r['ponte'] as $l) {
        if ($l['tipo'] === 'inicio') {
            $corrente = $l['valor'];
            $faixas[] = [0.0, $corrente];
        } elseif ($l['tipo'] === 'fim') {
            $faixas[] = [0.0, $l['valor']];
        } else {
            $faixas[] = [$corrente, $corrente + $l['valor']];
            $corrente += $l['valor'];
        }
    }
}
?>
<div class="grade rl">
    <?php \Financeiro\View::partial('partials/topo', $topo); ?>

    <?php if ($r === null): ?>
        <section class="faixa-grade">
            <div class="vazio">
                <h2 class="titulo"><?= e(t('Ainda não há período para mostrar')) ?></h2>
                <p><?= e(t('O primeiro relatório aparece depois do seu primeiro mês de lançamentos.')) ?></p>
                <a class="btn btn-contorno" href="/lancar?volta=%2Frelatorio" data-lancar><?= svgIcon('plus', 16) ?><?= e(t('Lançar')) ?></a>
            </div>
        </section>
    <?php else: ?>
        <!-- a frase-resultado -->
        <section class="faixa-grade rl-capa" aria-labelledby="rl-manchete">
            <p class="rot-resposta"><?= e($r['situacao']) ?></p>
            <h2 class="rl-manchete" id="rl-manchete"><?= e($r['manchete']) ?></h2>
            <?php if ($r['tipo'] === 'mensal' && !$r['encerrado'] && !empty($r['sobras'])): ?>
                <dl class="numeros rl-sobras" style="--colunas:<?= count($r['sobras']) ?>">
                    <?php foreach ($r['sobras'] as $s): $rot = \Financeiro\Services\Sobra::rotulo((string) $s['base']); ?>
                        <div><dt><?= e($rot) ?> <?= dica('numeros', $rot) ?></dt><dd data-sobra="<?= e((string) $s['base']) ?>"><?= valor((float) $s['sobra'], (string) $s['base'] === 'prevista' ? 'previsto' : 'neutro') ?><?php if ((string) $s['base'] === 'prevista'): ?><span class="nota"><?= e(t('planejado')) ?></span><?php endif; ?></dd></div>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>
            <?php if (!empty($r['conferencia'])): ?>
                <p class="rl-conf is-<?= e($r['conferencia']['tipo']) ?>"><?= $r['conferencia']['tipo'] === 'sem' ? '' : svgIcon($r['conferencia']['tipo'] === 'ok' ? 'check' : 'alert', 16) ?><span><?= e($r['conferencia']['texto']) ?></span></p>
            <?php endif; ?>
        </section>

        <?php if ($r['tipo'] === 'mensal'): ?>
            <?php
            $todos = array_merge(...$faixas);
            [$min, $max] = [min(0.0, ...$todos), max(0.0, ...$todos)];
            $amp = max(1.0, $max - $min);
            $temPlano = in_array('plan', array_column($r['ponte'], 'tipo'), true);
            ?>
            <!-- a peça central: como a conta chegou até aqui -->
            <section class="faixa-grade rl-escada" aria-labelledby="rl-escada-t">
                <div class="secao-cab">
                    <h2 id="rl-escada-t"><?= e(t('Como a conta chegou até aqui')) ?></h2>
                    <?php if ($temPlano): ?><p class="rl-leg"><span class="rl-leg-fato" aria-hidden="true"></span><?= e(t('realizado')) ?> <span class="rl-leg-plan" aria-hidden="true"></span><?= e(t('planejado')) ?></p><?php endif; ?>
                </div>
                <table class="rl-cascata">
                    <caption class="sr-only"><?= e(t('Como a conta chegou até aqui')) ?></caption>
                    <thead class="sr-only"><tr><th scope="col"><?= e(t('O quê')) ?></th><th scope="col"><?= e(t('Valor')) ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($r['ponte'] as $i => $l):
                            [$a, $b] = $faixas[$i];
                            $esq = (min($a, $b) - $min) / $amp * 100;
                            $larg = max(0.6, abs($b - $a) / $amp * 100);
                            $classe = (string) ($l['classe'] ?? ($l['tipo'] === 'inicio' || $l['tipo'] === 'fim' ? 'total' : ($l['valor'] >= 0 ? 'entrada' : 'gasto')));
                            $plan = $l['tipo'] === 'plan';
                            // o valor pela regra de cor: o que entrou em verde, o que gastou em vermelho, o planejado em
                            // cinza, o guardado e o empréstimo neutros (só o sinal), os totais em tinta
                            $tipoValor = $plan ? 'previsto' : match ($classe) {
                                'entrada' => 'entrada',
                                'gasto' => 'saida',
                                default => 'neutro',
                            };
                            $txtValor = $classe === 'total' || $classe === 'entrada' || $plan
                                ? valor((float) $l['valor'], $tipoValor, ['centavos' => false])
                                : ($classe === 'gasto' ? valor((float) $l['valor'], 'saida', ['centavos' => false])
                                    : '<span class="num">' . ($l['valor'] > 0 ? '+' : '') . e(num((float) $l['valor'])) . '</span>'); ?>
                            <tr class="rl-degrau is-<?= e($l['tipo']) ?> is-<?= e($classe) ?>">
                                <th scope="row"><?= e($l['rotulo']) ?></th>
                                <td class="rl-d-trilho" aria-hidden="true"><span><i style="left:<?= e(number_format($esq, 2, '.', '')) ?>%;width:<?= e(number_format($larg, 2, '.', '')) ?>%"></i></span></td>
                                <td class="rl-d-valor"><?= $txtValor ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($r['divida'] !== null): ?><p class="rl-nota"><?= e(t('Dívida do empréstimo ao fim do mês: {valor}.', ['valor' => money($r['divida']['fim'])])) ?></p><?php endif; ?>
            </section>
        <?php elseif ($r['encerrados'] > 0): ?>
            <section class="faixa-grade" aria-label="<?= e(t('O ano em números')) ?>">
                <dl class="numeros" style="--colunas:<?= $r['temDivida'] ? 4 : 3 ?>">
                    <div><dt><?= e(t('Entrou')) ?></dt><dd><?= valor((float) $r['entrou'], 'entrada') ?></dd></div>
                    <div><dt><?= e(t('Gastou')) ?></dt><dd><?= valor(-(float) $r['saiu'], 'saida') ?></dd></div>
                    <div><dt><?= e(t('Guardou ou aplicou')) ?></dt><dd><?= valor((float) $r['guardou']) ?></dd></div>
                    <?php if ($r['temDivida']): ?><div><dt><?= e(t('Adiantou do empréstimo')) ?></dt><dd><?= valor((float) $r['adiantou']) ?></dd></div><?php endif; ?>
                </dl>
            </section>
        <?php endif; ?>

        <?php if ($r['tipo'] === 'anual' ? $r['encerrados'] > 0 : $r['encerrado']): ?>
            <section class="faixa-grade rl-achados" aria-label="<?= e(t('Leitura do período')) ?>">
                <?php foreach ($itens as [$chave, $titulo, $vazio]): ?>
                    <div class="rl-achado is-<?= e($chave) ?>">
                        <h2><?= svgIcon($chave === 'bom' ? 'arrow-up' : ($chave === 'ruim' ? 'arrow-down' : 'target'), 16) ?><?= e($titulo) ?></h2>
                        <?php if ($r['achados'][$chave] === []): ?>
                            <p class="rl-vazio"><?= e($vazio) ?></p>
                        <?php else: ?>
                            <ul><?php foreach ($r['achados'][$chave] as $texto): ?><li><?= e($texto) ?></li><?php endforeach; ?></ul>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php foreach ($r['achados']['conferir'] as $texto): ?><p class="rl-nota rl-conferir"><?= e($texto) ?></p><?php endforeach; ?>
            </section>
        <?php elseif ($r['tipo'] === 'mensal'): ?>
            <section class="faixa-grade">
                <p class="rl-nota rl-sem-veredito"><?= e(t('Num mês em andamento, o planejamento usa o maior valor entre o lançado e o planejado, então gasto abaixo do planejado ainda não aparece. O que foi bom e o que foi ruim aparecem quando o mês for encerrado.')) ?></p>
            </section>
        <?php endif; ?>

        <?php if (!empty($r['cartaoAberto']) && ($r['tipo'] === 'mensal' || $r['encerrados'] > 0)):
            // com o cartão, o "Para onde foi" também na tela: é aqui que se escolhe entre a data da compra e a fatura (vale para o PDF)
            $onde = $r['categorias'];
            $ondeMax = $onde['linhas'] !== [] ? max(array_column($onde['linhas'], 'real')) : 0.0;
            $semCatRl = $r['semCategoria'] ?? null;
            $categorizarRl = $semCatRl !== null ? '/cartao/compras/' . (int) $semCatRl['compras'][0] . '/editar' : null;
            $pct = static fn(float $v): int => $onde['totalReal'] > 0.004 ? (int) round($v / $onde['totalReal'] * 100) : 0; ?>
            <section class="faixa-grade rl-onde" aria-labelledby="rl-onde-t">
                <div class="secao-cab">
                    <h2 id="rl-onde-t"><?= e(t('Para onde foi')) ?></h2>
                    <p class="num"><?= e(t('{valor} gastos', ['valor' => num($onde['totalReal'])])) ?></p>
                </div>
                <?php if ($onde['linhas'] === []): ?>
                    <p class="rl-nota"><?= e(t('Nenhum gasto lançado neste período.')) ?></p>
                <?php else: ?>
                    <ol class="rl-barras">
                        <?php foreach ($onde['linhas'] as $l):
                            $semCat = $r['porCompra'] && (int) $l['id'] === \Financeiro\Services\GastoPorCategoria::SEM_CATEGORIA;
                            $href = $semCat ? $categorizarRl : ($r['tipo'] === 'mensal' ? '/lancamentos?mes=' . $r['mes'] . '&cat=' . (int) $l['id'] : null);
                            $tag = $href !== null ? 'a' : 'div'; ?>
                            <li>
                                <<?= $tag ?> class="rl-barra-i"<?= $href !== null ? ' href="' . e($href) . '"' : '' ?>>
                                    <?= iconeCategoria((int) $l['id'], (string) ($l['nome_banco'] ?? ''), $semCat ? 'Cartao' : '', 20, 'rl-ic') ?>
                                    <span class="rl-barra-n"><?= e($l['nome']) ?></span>
                                    <span class="rl-barra-v num"><?= e(num($l['real'])) ?></span>
                                    <span class="rl-barra-p num"><?= $pct($l['real']) ?>%</span>
                                    <span class="meta-barra" aria-hidden="true"><i style="--p:<?= $ondeMax > 0 ? number_format($l['real'] / $ondeMax, 3, '.', '') : 0 ?>"></i></span>
                                </<?= $tag ?>>
                            </li>
                        <?php endforeach; ?>
                        <?php if ($onde['outros'] !== null): ?>
                            <li>
                                <div class="rl-barra-i">
                                    <?= iconeCategoria(-1, '', '', 20, 'rl-ic') ?>
                                    <span class="rl-barra-n"><?= e(tn($onde['outros']['n'], 'Outra 1 categoria', 'Outras {n} categorias')) ?></span>
                                    <span class="rl-barra-v num"><?= e(num($onde['outros']['real'])) ?></span>
                                    <span class="rl-barra-p num"><?= $pct($onde['outros']['real']) ?>%</span>
                                    <span class="meta-barra" aria-hidden="true"><i style="--p:<?= $ondeMax > 0 ? number_format(min(1.0, $onde['outros']['real'] / $ondeMax), 3, '.', '') : 0 ?>"></i></span>
                                </div>
                            </li>
                        <?php endif; ?>
                    </ol>
                <?php endif; ?>
                <?php if ($categorizarRl !== null): ?>
                    <p class="rl-nota num"><?= t('Compras no cartão sem categoria: {valor}. {link}', ['valor' => e(num($semCatRl['valor'])), 'link' => '<a class="link" href="' . e($categorizarRl) . '">' . e(t('Categorizar')) . '</a>']) ?></p>
                <?php endif; ?>
                <p class="rl-nota rl-onde-modo"><?= $r['porCompra']
                    ? t('Cartão pela data da compra. {link}', ['link' => '<a class="link" href="/relatorio?' . e($parametros) . '&amp;onde=fatura#rl-onde-t">' . e(t('Ver por fatura')) . '</a>'])
                    : t('Cartão pela fatura paga. {link}', ['link' => '<a class="link" href="/relatorio?' . e($parametros) . '&amp;onde=compra#rl-onde-t">' . e(t('Ver pela data da compra')) . '</a>']) ?></p>
            </section>
        <?php endif; ?>

        <section class="faixa-grade rl-fim">
            <p><?= e(t('O PDF traz também para onde foi o dinheiro, o que já está contratado, o estimado e os cenários.')) ?></p>
            <a class="link" href="/futuro"><?= e(t('Simular uma mudança')) ?></a>
        </section>
    <?php endif; ?>
</div>
