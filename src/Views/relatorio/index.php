<?php
/**
 * Aba Relatório: $tipo, $periodos e $r (RelatorioDados::mensal ou ::anual, null sem período). Mostra o que está
 * na primeira página do PDF; os gráficos e as previsões ficam no arquivo.
 */
$parametros = $r === null ? '' : http_build_query($r['tipo'] === 'anual' ? ['tipo' => 'anual', 'ano' => $r['ano']] : ['tipo' => 'mensal', 'mes' => $r['mes']]);
$rotuloEstado = static fn(string $e): string => match ($e) {
    'real' => t('encerrado'),
    default => t('em andamento'),
};
$itens = [['bom', t('O que foi bom'), t('Nada relevante neste período.')], ['ruim', t('O que foi ruim'), t('Nada relevante neste período.')], ['melhorar', t('O que pode melhorar'), t('Nenhum corte simples muda o resultado agora.')]];
?>
<div class="rl">
    <header class="rl-topo">
        <h1 class="pg-titulo"><?= e(t('Relatório')) ?></h1>
        <nav class="rl-tipo" aria-label="<?= e(t('Tipo de relatório')) ?>">
            <a class="rl-tipo-b<?= $tipo === 'mensal' ? ' is-on' : '' ?>" href="/relatorio?tipo=mensal"<?= $tipo === 'mensal' ? ' aria-current="page"' : '' ?>><?= e(t('Mensal')) ?></a>
            <a class="rl-tipo-b<?= $tipo === 'anual' ? ' is-on' : '' ?>" href="/relatorio?tipo=anual"<?= $tipo === 'anual' ? ' aria-current="page"' : '' ?>><?= e(t('Anual')) ?></a>
        </nav>
    </header>

    <?php if ($r === null): ?>
        <section class="card vazio">
            <h2 class="titulo"><?= e(t('Ainda não há período para mostrar')) ?></h2>
            <p><?= e(t('O primeiro relatório aparece depois do seu primeiro mês de lançamentos.')) ?></p>
        </section>
    <?php else: ?>
        <form class="rl-periodo" method="get" action="/relatorio" data-rl-periodo>
            <input type="hidden" name="tipo" value="<?= e($tipo) ?>">
            <?php if ($tipo === 'anual'): ?>
                <label for="rl-sel"><?= e(t('Ano')) ?></label>
                <select id="rl-sel" name="ano">
                    <?php foreach ($periodos['anos'] as $a): ?><option value="<?= (int) $a ?>"<?= $a === $r['ano'] ? ' selected' : '' ?>><?= (int) $a ?></option><?php endforeach; ?>
                </select>
            <?php else: ?>
                <label for="rl-sel"><?= e(t('Mês')) ?></label>
                <select id="rl-sel" name="mes">
                    <?php foreach ($periodos['meses'] as $m): ?><option value="<?= e($m['mes']) ?>"<?= $m['mes'] === $r['mes'] ? ' selected' : '' ?>><?= e(monthLabel($m['mes'])) ?>, <?= e($rotuloEstado($m['estado'])) ?></option><?php endforeach; ?>
                </select>
            <?php endif; ?>
            <button type="submit" class="btn btn-ghost rl-ver" data-rl-ver><?= e(t('Ver')) ?></button>
            <a class="btn btn-primary rl-baixar" href="/relatorio/pdf?<?= e($parametros) ?>" data-espera="<?= e(t('Gerando o PDF…')) ?>" data-espera-baixa><?= svgIcon('download', 18) ?><?= e(t('Baixar PDF')) ?></a>
        </form>

        <section class="rl-capa" aria-labelledby="rl-manchete">
            <p class="rl-periodo-nome"><?= e($r['titulo']) ?></p>
            <h2 class="rl-manchete" id="rl-manchete"><?= e($r['manchete']) ?></h2>
            <p class="rl-situacao"><?= e($r['situacao']) ?></p>
            <?php if ($r['tipo'] === 'mensal' && !$r['encerrado'] && !empty($r['sobras'])): ?>
                <dl class="rl-sobras num">
                    <?php foreach ($r['sobras'] as $s): $rot = \Financeiro\Services\Sobra::rotulo((string) $s['base']); ?>
                        <div><dt><?= e($rot) ?> <?= dica('numeros', $rot) ?></dt><dd data-sobra="<?= e((string) $s['base']) ?>"><?= e(money((float) $s['sobra'])) ?></dd></div>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>
            <?php if (!empty($r['conferencia'])): ?>
                <p class="rl-conf is-<?= e($r['conferencia']['tipo']) ?>"><?= $r['conferencia']['tipo'] === 'sem' ? '' : svgIcon($r['conferencia']['tipo'] === 'ok' ? 'check' : 'alert', 15) ?><span><?= e($r['conferencia']['texto']) ?></span></p>
            <?php endif; ?>
        </section>

        <?php if ($r['tipo'] === 'mensal'): ?>
            <?php
            $corrente = 0.0;
            $faixas = [];
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
            $todos = array_merge(...$faixas);
            [$min, $max] = [min(0.0, ...$todos), max(0.0, ...$todos)];
            $amp = max(1.0, $max - $min);
            ?>
            <section class="card rl-escada" aria-labelledby="rl-escada-t">
                <h2 class="titulo" id="rl-escada-t"><?= e(t('Como a conta chegou até aqui')) ?></h2>
                <ol class="rl-degraus">
                    <?php foreach ($r['ponte'] as $i => $l):
                        [$a, $b] = $faixas[$i];
                        $esq = (min($a, $b) - $min) / $amp * 100;
                        $larg = max(0.6, abs($b - $a) / $amp * 100);
                        $sinal = in_array($l['tipo'], ['inicio', 'fim'], true) ? money($l['valor']) : ($l['valor'] >= 0 ? '+' . money($l['valor']) : money($l['valor']));
                        $classe = in_array($l['tipo'], ['inicio', 'fim'], true) ? 'is-total' : ($l['valor'] >= 0 ? 'is-entra' : 'is-sai'); ?>
                        <li class="rl-degrau is-<?= e($l['tipo']) ?>">
                            <span class="rl-d-nome"><?= e($l['rotulo']) ?></span>
                            <span class="rl-d-trilho" aria-hidden="true"><i class="<?= e($classe) ?><?= $l['tipo'] === 'plan' ? ' is-plan' : '' ?>" style="left:<?= e(number_format($esq, 2, '.', '')) ?>%;width:<?= e(number_format($larg, 2, '.', '')) ?>%"></i></span>
                            <span class="rl-d-valor num"><?= e($sinal) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <?php if ($r['divida'] !== null): ?><p class="rl-nota"><?= e(t('Dívida do empréstimo ao fim do mês: {valor}.', ['valor' => money($r['divida']['fim'])])) ?></p><?php endif; ?>
            </section>
        <?php elseif ($r['encerrados'] > 0): ?>
            <section class="card rl-numeros" aria-label="<?= e(t('O ano em números')) ?>">
                <div><small><?= e(t('Entrou')) ?></small><b class="num"><?= e(money($r['entrou'])) ?></b></div>
                <div><small><?= e(t('Saiu')) ?></small><b class="num"><?= e(money($r['saiu'])) ?></b></div>
                <div><small><?= e(t('Guardou ou aplicou')) ?></small><b class="num"><?= e(money($r['guardou'])) ?></b></div>
                <?php if ($r['temDivida']): ?><div><small><?= e(t('Adiantou do empréstimo')) ?></small><b class="num"><?= e(money($r['adiantou'])) ?></b></div><?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($r['tipo'] === 'anual' ? $r['encerrados'] > 0 : $r['encerrado']): ?>
            <section class="rl-achados" aria-label="<?= e(t('Leitura do período')) ?>">
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
            </section>
            <?php foreach ($r['achados']['conferir'] as $texto): ?><p class="rl-nota"><?= e($texto) ?></p><?php endforeach; ?>
        <?php elseif ($r['tipo'] === 'mensal'): ?>
            <p class="card rl-sem-veredito"><?= e(t('Num mês em andamento, o planejamento usa o maior valor entre o lançado e o planejado, então gasto abaixo do planejado ainda não aparece. O que foi bom e o que foi ruim aparecem quando o mês for encerrado.')) ?></p>
        <?php endif; ?>

        <?php if (!empty($r['cartaoAberto']) && ($r['tipo'] === 'mensal' || $r['encerrados'] > 0)):
            // com o cartão, o "Para onde foi" também na tela: é aqui que se escolhe entre a data da compra e a fatura (vale para o PDF)
            $onde = $r['categorias'];
            $ondeMax = $onde['linhas'] !== [] ? max(array_column($onde['linhas'], 'real')) : 0.0;
            $semCatRl = $r['semCategoria'] ?? null;
            $categorizarRl = $semCatRl !== null ? '/cartao/compras/' . (int) $semCatRl['compras'][0] . '/editar' : null;
            $pct = static fn(float $v): int => $onde['totalReal'] > 0.004 ? (int) round($v / $onde['totalReal'] * 100) : 0; ?>
            <section class="card rl-onde" aria-labelledby="rl-onde-t">
                <div class="card-head">
                    <h2 class="titulo" id="rl-onde-t"><?= e(t('Para onde foi')) ?> <small class="dash-tit-nota"><?= e(t('% dos gastos')) ?></small></h2>
                    <span class="card-acao num"><?= e(num($onde['totalReal'])) ?></span>
                </div>
                <?php if ($onde['linhas'] === []): ?>
                    <p class="rl-nota"><?= e(t('Nenhum gasto lançado neste período.')) ?></p>
                <?php else: ?>
                    <ol class="barras">
                        <?php foreach ($onde['linhas'] as $l):
                            $semCat = $r['porCompra'] && (int) $l['id'] === \Financeiro\Services\GastoPorCategoria::SEM_CATEGORIA;
                            $href = $semCat ? $categorizarRl : ($r['tipo'] === 'mensal' ? '/lancamentos?mes=' . $r['mes'] . '&cat=' . (int) $l['id'] : null);
                            $tag = $href !== null ? 'a' : 'div'; ?>
                            <li>
                                <<?= $tag ?> class="barras-i<?= $href === null ? ' is-fixo' : '' ?>"<?= $href !== null ? ' href="' . e($href) . '"' : '' ?>>
                                    <?= iconeCategoria((int) $l['id'], (string) ($l['nome_banco'] ?? ''), $semCat ? 'Cartao' : '', 40, 'barras-ic') ?>
                                    <span class="barras-n"><?= e($l['nome']) ?></span>
                                    <span class="barras-v num"><?= e(num($l['real'])) ?><small><?= $pct($l['real']) ?>%</small></span>
                                    <span class="trilho" aria-hidden="true"><i style="--w:<?= $ondeMax > 0 ? round($l['real'] / $ondeMax * 100, 1) : 0 ?>%"></i></span>
                                </<?= $tag ?>>
                            </li>
                        <?php endforeach; ?>
                        <?php if ($onde['outros'] !== null): ?>
                            <li>
                                <div class="barras-i is-fixo">
                                    <?= iconeCategoria(-1, '', '', 40, 'barras-ic') ?>
                                    <span class="barras-n"><?= e(tn($onde['outros']['n'], 'Outra 1 categoria', 'Outras {n} categorias')) ?></span>
                                    <span class="barras-v num"><?= e(num($onde['outros']['real'])) ?><small><?= $pct($onde['outros']['real']) ?>%</small></span>
                                    <span class="trilho" aria-hidden="true"><i style="--w:<?= $ondeMax > 0 ? round(min(1.0, $onde['outros']['real'] / $ondeMax) * 100, 1) : 0 ?>%"></i></span>
                                </div>
                            </li>
                        <?php endif; ?>
                    </ol>
                <?php endif; ?>
                <?php if ($categorizarRl !== null): ?>
                    <p class="rl-nota num"><?= t('Compras no cartão sem categoria: {valor}. {link}', ['valor' => e(num($semCatRl['valor'])), 'link' => '<a href="' . e($categorizarRl) . '">' . e(t('Categorizar')) . '</a>']) ?></p>
                <?php endif; ?>
                <p class="rl-nota rl-onde-modo"><?= $r['porCompra']
                    ? t('Cartão pela data da compra. {link}', ['link' => '<a href="/relatorio?' . e($parametros) . '&amp;onde=fatura#rl-onde-t">' . e(t('Ver por fatura')) . '</a>'])
                    : t('Cartão pela fatura paga. {link}', ['link' => '<a href="/relatorio?' . e($parametros) . '&amp;onde=compra#rl-onde-t">' . e(t('Ver pela data da compra')) . '</a>']) ?></p>
            </section>
        <?php endif; ?>

        <section class="rl-fim">
            <p><?= e(t('O PDF traz também para onde foi o dinheiro, o que já está contratado, o estimado e os cenários.')) ?></p>
            <a class="btn-link" href="/futuro"><?= e(t('Simular uma mudança')) ?></a>
        </section>
    <?php endif; ?>
</div>
