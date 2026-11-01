<?php
/**
 * Aba Empréstimo: o cartão escuro diz quanto falta (estimativa, com a data em que acaba); o formulário
 * simula um adiantamento num mês; _resultado mostra o que muda. Sem JS o formulário é um GET normal.
 * "Consolidar" lança no Extrato o que já foi pago, e os adiantamentos feitos ficam listados.
 * Variáveis: $sit (EmprestimoSimulacao::situacao), $sim, $erro, $mes, $campo, $hoje, $catAmort,
 *            $feitos (lançamentos da Amortização extra), $form (o que voltou com erro ou duplicado, ou null).
 *            $aj (ajustes por chave), $taxaCredito, $dadosErro, $dadosAberto: "Dados do empréstimo", o contrato.
 */
// com adiantamento lançado, acabar antes do contrato é o esperado: só compara enquanto não houver nenhum
$informado = $sit['informado'];
$difContrato = $feitos === [] && $sit['contrato'] !== null && $sit['quita'] !== null && !$sit['quitado'] && $sit['quita'] !== $sit['contrato'];
[$inteiro, $centavos] = array_pad(explode(',', num((float) $sit['falta'])), 2, '00');
?>
<div class="emp">
    <header class="emp-topo"><h1>Empréstimo</h1></header>

    <div class="emp-cols">
        <div class="emp-col">
            <section class="saldo emp-saldo" aria-labelledby="emp-falta-t">
                <div class="saldo-l1">
                    <h2 id="emp-falta-t">Falta pagar no fim de <?= e(monthLabel($sit['ref'])) ?></h2>
                    <span class="saldo-estado"><?= $informado !== null ? 'Saldo informado' : 'Estimativa' ?></span>
                </div>
                <p class="saldo-v num"><small>R$</small><?= e($inteiro) ?><span>,<?= e($centavos) ?></span></p>
                <?php if (!$sit['quitado']): ?>
                    <p class="emp-saldo-sub num">Parcela de <?= e(num((float) $sit['parcela'])) ?> por mês<?php if ($informado !== null): ?><br>Extrato: <?= e(num((float) $informado['valor'])) ?> antes da parcela de <?= e(monthLabel($informado['mes'])) ?><?php endif; ?></p>
                    <div class="saldo-duas">
                        <div><small>Termina em</small><b class="num"><?= $sit['quita'] !== null ? e(monthLabel($sit['quita'])) : 'não dá para dizer' ?></b>
                            <?php if ($sit['restantes'] !== null): ?><small class="num"><?= (int) $sit['restantes'] ?> parcelas</small><?php endif; ?></div>
                        <div><small>Juros que faltam</small><b class="num"><?= $sit['jurosFaltam'] !== null ? e(num((float) $sit['jurosFaltam'], 0)) : '—' ?></b>
                            <small>só com as parcelas</small></div>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($difContrato && $informado !== null): ?>
                <p class="aviso aviso-warn">Com o saldo informado, o cálculo termina em <?= e(monthLabel($sit['quita'])) ?>, e o contrato em <?= e(monthLabel($sit['contrato'])) ?>. Se o fim do contrato estiver certo, confira os juros e a parcela. <a class="aj-link" href="/emprestimo?dados=1#emp-dados">Ver os dados</a></p>
            <?php elseif ($difContrato): ?>
                <p class="aviso aviso-warn">Pelo contrato, as <?= (int) $sit['nParcelas'] ?> parcelas acabam em <?= e(monthLabel($sit['contrato'])) ?>, mas este cálculo chega a <?= e(monthLabel($sit['quita'])) ?>. Até informar o saldo do extrato, os números são estimativa. <a class="aj-link" href="/emprestimo?dados=1#emp-dados">Informar o saldo</a></p>
            <?php endif; ?>

            <?php if ($sit['quitado']): ?>
                <section class="card vazio"><h2 class="titulo">Empréstimo quitado</h2></section>
            <?php else: ?>
                <form class="card emp-form" method="get" action="/emprestimo" data-emp-form>
                    <div class="campo">
                        <label for="emp-valor">Quanto vocês querem adiantar?</label>
                        <span class="emp-valor"><span aria-hidden="true">R$</span><input id="emp-valor" name="valor" type="text" inputmode="decimal" autocomplete="off" enterkeyhint="done" placeholder="0,00" value="<?= e($campo) ?>" aria-describedby="emp-ajuda"></span>
                    </div>
                    <div class="campo">
                        <label for="emp-mes">Em que mês?</label>
                        <select id="emp-mes" name="mes">
                            <?php foreach ($sit['meses'] as $m): ?>
                                <option value="<?= e($m) ?>"<?= $m === $mes ? ' selected' : '' ?>><?= e(monthLabel($m)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <p class="emp-ajuda" id="emp-ajuda">É só uma simulação: nada é salvo.</p>
                    <div class="emp-acoes">
                        <button class="btn btn-ghost" type="submit">Simular</button>
                        <button class="btn btn-ghost" type="submit" name="quitar" value="1">Quitar tudo</button>
                    </div>
                </form>
            <?php endif; ?>

            <?php if (!$sit['quitado'] && $sit['quitaPlano'] !== null && $sit['quitaPlano'] !== $sit['quita']): ?>
                <p class="emp-plano">No Anual, o plano usa a sobra de cada mês para adiantar e quita em <?= e(monthLabel($sit['quitaPlano'])) ?>. <a href="/futuro">Ver o plano</a></p>
            <?php endif; ?>
        </div>

        <div class="emp-col">
            <p class="sr-only" role="status" data-emp-anuncio></p>
            <div id="emp-resultado"><?php \Financeiro\View::partial('emprestimo/_resultado', get_defined_vars()); ?></div>

            <?php if ($catAmort !== null && !$sit['quitado']): ?>
                <?php $dup = !empty($form['duplicado']); $erroForm = $form['erro'] ?? null; ?>
                <form class="card emp-form emp-consolidar" id="emp-consolidar" method="post" action="/emprestimo/adiantamento" data-emp-consolidar>
                    <?= csrfField() ?>
                    <div class="emp-consolidar-cab">
                        <h2 class="titulo">Consolidar adiantamento</h2>
                        <p class="emp-ajuda">Depois de pagar o adiantamento, lance aqui: o valor entra no Extrato, sai da conta e abate a dívida.</p>
                    </div>
                    <?php if ($erroForm !== null): ?><p class="aviso aviso-error emp-inteira" role="alert"><?= e((string) $erroForm) ?></p><?php endif; ?>
                    <?php if ($dup): ?><p class="aviso aviso-warn emp-inteira" role="alert">Já existe um adiantamento com esse valor nessa data. Se foi outro pagamento, confirme abaixo.</p><?php endif; ?>
                    <div class="campo">
                        <label for="emp-pago">Valor pago</label>
                        <span class="emp-valor"><span aria-hidden="true">R$</span><input id="emp-pago" name="valor" type="text" inputmode="decimal" autocomplete="off" enterkeyhint="done" placeholder="0,00" required value="<?= e((string) ($form['valor'] ?? $campo)) ?>"></span>
                    </div>
                    <div class="campo">
                        <label for="emp-data">Data do pagamento</label>
                        <input id="emp-data" name="data" type="date" required max="<?= e($hoje) ?>" value="<?= e((string) ($form['data'] ?? $hoje)) ?>">
                    </div>
                    <?php if ($dup): ?><input type="hidden" name="confirmar_dup" value="1"><?php endif; ?>
                    <button class="btn btn-primary emp-inteira" type="submit"><?= $dup ? 'Lançar mesmo assim' : 'Lançar no Extrato' ?></button>
                </form>
            <?php endif; ?>

            <?php if ($feitos !== []): ?>
                <section class="card emp-feitos" aria-labelledby="emp-feitos-t">
                    <div class="card-head">
                        <h2 class="titulo" id="emp-feitos-t">Adiantamentos feitos</h2>
                        <b class="num"><?= e(money(array_sum(array_map(static fn($f) => (float) $f['valor'], $feitos)))) ?></b>
                    </div>
                    <ul>
                        <?php foreach ($feitos as $f): ?>
                            <li><a href="/lancamentos/<?= (int) $f['id'] ?>/editar?volta=<?= e(rawurlencode('/emprestimo')) ?>" aria-label="Editar adiantamento de <?= e(date('d/m/Y', strtotime((string) $f['data']))) ?>">
                                <span class="num"><?= e(date('d/m/Y', strtotime((string) $f['data']))) ?></span>
                                <b class="num"><?= e(num((float) $f['valor'])) ?></b>
                            </a></li>
                        <?php endforeach; ?>
                    </ul>
                    <a class="btn-link" href="/lancamentos?mes=<?= e(substr((string) $feitos[0]['data'], 0, 8) . '01') ?>&amp;cat=<?= (int) $catAmort ?>">Ver no Extrato</a>
                </section>
            <?php endif; ?>

            <?php
            $valorDado = static function (string $k, int $dec = 2) use ($aj, $dadosErro): string {
                if (is_string($dadosErro['valores'][$k] ?? null)) {
                    return $dadosErro['valores'][$k];       // devolve o que foi digitado
                }
                $v = (float) ($aj[$k]['valor'] ?? 0.0);
                return $k === 'juros_mes' ? numInput($v * 100) : numInput($v, $dec);
            };
            $invalido = static fn(string $k): string => ($dadosErro['campo'] ?? '') === $k ? ' aria-invalid="true" autofocus' : '';
            $iniDado = (int) round((float) ($aj['consignado_inicio']['valor'] ?? 0));
            $iniTxt = is_string($dadosErro['valores']['consignado_inicio'] ?? null) ? $dadosErro['valores']['consignado_inicio']
                : ($iniDado > 0 ? sprintf('%04d-%02d', intdiv($iniDado, 100), $iniDado % 100) : '');
            $saldoDado = (float) ($aj['saldo_devedor']['valor'] ?? 0.0);
            $saldoTxt = is_string($dadosErro['valores']['saldo_devedor'] ?? null) ? $dadosErro['valores']['saldo_devedor'] : ($saldoDado > 0 ? numInput($saldoDado) : '');
            $saldoMes = (int) round((float) ($aj['saldo_devedor_mes']['valor'] ?? 0));
            $saldoMesTxt = is_string($dadosErro['valores']['saldo_devedor_mes'] ?? null) ? $dadosErro['valores']['saldo_devedor_mes']
                : ($saldoMes > 0 ? sprintf('%04d-%02d', intdiv($saldoMes, 100), $saldoMes % 100) : '');
            $campoDado = static function (string $k, string $rotulo, string $un = '', int $dec = 2) use ($valorDado, $invalido): void { ?>
                <div class="aj-campo">
                    <label for="emp-d-<?= e($k) ?>"><?= e($rotulo) ?></label>
                    <span class="aj-input<?= $un !== '' ? ' tem-un' : '' ?>">
                        <input id="emp-d-<?= e($k) ?>" type="text" name="valores[<?= e($k) ?>]" inputmode="<?= $dec === 0 ? 'numeric' : 'decimal' ?>" autocomplete="off" value="<?= e($valorDado($k, $dec)) ?>"<?= $invalido($k) ?>>
                        <?php if ($un !== ''): ?><span class="aj-un"><?= e($un) ?></span><?php endif; ?>
                    </span>
                </div>
            <?php };
            ?>
            <details class="card dobra emp-dados" id="emp-dados"<?= $dadosAberto ? ' open' : '' ?>>
                <summary>Dados do empréstimo</summary>
                <form class="aj-form" method="post" action="/emprestimo/dados">
                    <?= csrfField() ?>
                    <p class="aj-ajuda">Mudar estes dados recalcula o plano inteiro: Início, Mês, Anual e esta aba.</p>
                    <div class="aj-campo">
                        <label for="emp-d-saldo_devedor">Saldo devedor do extrato</label>
                        <span class="aj-input"><input id="emp-d-saldo_devedor" type="text" name="valores[saldo_devedor]" inputmode="decimal" autocomplete="off" placeholder="0,00" value="<?= e($saldoTxt) ?>"<?= $invalido('saldo_devedor') ?>></span>
                    </div>
                    <div class="aj-campo">
                        <label for="emp-d-saldo-mes">Antes da parcela de</label>
                        <span class="aj-input aj-input-mes"><input id="emp-d-saldo-mes" type="month" name="valores[saldo_devedor_mes]" max="<?= e(substr($hoje, 0, 7)) ?>" value="<?= e($saldoMesTxt) ?>"<?= $invalido('saldo_devedor_mes') ?>></span>
                    </div>
                    <p class="aj-ajuda">Use o extrato: a dívida passa a partir desse valor, e os adiantamentos de meses anteriores já estão nele. Em branco, o saldo é calculado pela parcela, pelos juros e pelo número de parcelas.</p>
                    <?php $campoDado('juros_mes', 'Juros ao mês', '%'); ?>
                    <div class="aj-extra">
                        <label class="aj-check"><input type="checkbox" name="confirmado[juros_mes]" value="1"<?= !empty($aj['juros_mes']['confirmado']) ? ' checked' : '' ?>><span>Confirmado</span></label>
                        <?php if ($taxaCredito !== null): ?><span class="aj-taxa num">Pelo crédito recebido: <?= e(num($taxaCredito * 100)) ?>%</span><?php endif; ?>
                    </div>
                    <?php $campoDado('parcela_mensal', 'Parcela'); ?>
                    <?php $campoDado('n_parcelas', 'Número de parcelas', '', 0); ?>
                    <div class="aj-campo">
                        <label for="emp-d-inicio">1ª parcela descontada em</label>
                        <span class="aj-input aj-input-mes"><input id="emp-d-inicio" type="month" name="valores[consignado_inicio]" value="<?= e($iniTxt) ?>"<?= $invalido('consignado_inicio') ?>></span>
                    </div>
                    <p class="aj-ajuda">A parcela só abate a dívida e o soldo a partir desse mês. Em branco, vale desde o início do plano.</p>
                    <?php $campoDado('recebido_emprestimo', 'Crédito recebido'); ?>
                    <?php if ($dadosErro !== null): ?><p class="aviso aviso-error" role="alert"><?= e((string) $dadosErro['msg']) ?></p><?php endif; ?>
                    <button type="submit" class="btn btn-primary aj-salvar">Salvar dados</button>
                </form>
            </details>

            <?php if (!$sit['quitado']): ?>
                <details class="card dobra emp-como">
                    <summary>Como calculamos</summary>
                    <ul>
                        <?php if ($informado !== null): ?><li>A dívida parte do saldo informado: <?= e(money((float) $informado['valor'])) ?> antes da parcela de <?= e(monthLabel($informado['mes'])) ?>.</li><?php endif; ?>
                        <li>Juros de <?= e(num((float) $sit['jurosMes'] * 100)) ?>% ao mês sobre o saldo e parcela fixa, como em <a href="/emprestimo?dados=1#emp-dados">Dados do empréstimo</a>.</li>
                        <li>O adiantamento abate o saldo no mês escolhido, depois da parcela, e o prazo encurta.</li>
                        <li>A comparação é com pagar só as parcelas, sem os adiantamentos automáticos do plano.</li>
                        <li>O valor que vale é o da instituição: peça o saldo atualizado antes de pagar.</li>
                    </ul>
                </details>
            <?php endif; ?>
        </div>
    </div>
</div>
