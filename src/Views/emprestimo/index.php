<?php
/**
 * Aba Empréstimo: o cartão escuro diz quanto falta (estimativa, com a data em que acaba); o formulário
 * simula um adiantamento num mês; _resultado mostra o que muda. Sem JS o formulário é um GET normal.
 * "Consolidar" lança no Extrato o que já foi pago, e os adiantamentos feitos ficam listados. Ele fica dobrado e
 * vazio: simular não preenche nada, só o "Já paguei" do resultado leva o valor ($pago).
 * Variáveis: $sit (EmprestimoSimulacao::situacao), $sim, $erro, $mes, $campo, $hoje, $catAmort,
 *            $feitos (lançamentos da Amortização extra), $form (o que voltou com erro ou duplicado, ou null).
 *            $aj (ajustes por chave), $taxaCredito, $dadosErro, $dadosAberto: "Dados do empréstimo", o contrato.
 */
// com adiantamento lançado, acabar antes do contrato é o esperado: só compara enquanto não houver nenhum
$informado = $sit['informado'];
$difContrato = $feitos === [] && $sit['contrato'] !== null && $sit['quita'] !== null && !$sit['quitado'] && $sit['quita'] !== $sit['contrato'];
[$inteiro, $centavos] = array_pad(explode(sepDecimal(), num((float) $sit['falta'])), 2, '00');
?>
<div class="emp">
    <header class="emp-topo"><h1><?= e(t('Empréstimo')) ?></h1></header>

    <div class="emp-cols">
        <div class="emp-col">
            <section class="saldo emp-saldo" aria-labelledby="emp-falta-t">
                <div class="saldo-l1">
                    <h2 id="emp-falta-t"><?= e(t('Falta pagar no fim de {mes}', ['mes' => monthLabel($sit['ref'])])) ?></h2>
                    <span class="saldo-estado"><?= e($informado !== null ? t('Saldo informado') : t('Estimativa')) ?></span>
                </div>
                <p class="saldo-v num"><small><?= e(moedaSimbolo()) ?></small><?= e($inteiro) ?><span><?= e(sepDecimal() . $centavos) ?></span></p>
                <?php if (!$sit['quitado']): ?>
                    <p class="emp-saldo-sub num"><?= e(t('Parcela de {valor} por mês', ['valor' => num((float) $sit['parcela'])])) ?><?php if ($informado !== null): ?><br><?= e(t('Extrato: {valor} antes da parcela de {mes}', ['valor' => num((float) $informado['valor']), 'mes' => monthLabel($informado['mes'])])) ?><?php endif; ?></p>
                    <div class="saldo-duas">
                        <div><small><?= e(t('Termina em')) ?></small><b class="num"><?= $sit['quita'] !== null ? e(monthLabel($sit['quita'])) : e(t('não dá para dizer')) ?></b>
                            <?php if ($sit['restantes'] !== null): ?><small class="num"><?= e(t('{n} parcelas', ['n' => (int) $sit['restantes']])) ?></small><?php endif; ?></div>
                        <div><small><?= e(t('Juros ainda a pagar')) ?></small><b class="num"><?= $sit['jurosFaltam'] !== null ? e(num((float) $sit['jurosFaltam'])) : '—' ?></b>
                            <small><?= e(t('se você pagar só as parcelas')) ?></small></div>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($difContrato && $informado !== null): ?>
                <p class="aviso aviso-warn"><?= e(t('Com o saldo informado, o cálculo termina em {fim}, e o contrato em {contrato}. Se o fim do contrato estiver certo, confira os juros e a parcela.', ['fim' => mesAno($sit['quita']), 'contrato' => mesAno($sit['contrato'])])) ?> <a class="aj-link" href="/emprestimo?dados=1#emp-dados"><?= e(t('Ver os dados')) ?></a></p>
            <?php elseif ($difContrato): ?>
                <p class="aviso aviso-warn"><?= e(t('Pelo contrato, as {n} parcelas acabam em {contrato}, mas este cálculo chega a {fim}. Até informar o saldo do extrato, os números são estimativa.', ['n' => (int) $sit['nParcelas'], 'contrato' => mesAno($sit['contrato']), 'fim' => mesAno($sit['quita'])])) ?> <a class="aj-link" href="/emprestimo?dados=1#emp-dados"><?= e(t('Informar o saldo')) ?></a></p>
            <?php endif; ?>

            <?php if ($sit['quitado']): ?>
                <section class="card vazio"><h2 class="titulo"><?= e(t('Empréstimo quitado')) ?></h2></section>
            <?php else: ?>
                <form class="card emp-form" method="get" action="/emprestimo" data-emp-form>
                    <div class="campo">
                        <label for="emp-valor"><?= e(t('Quanto quer adiantar?')) ?></label>
                        <span class="emp-valor"><span aria-hidden="true"><?= e(moedaSimbolo()) ?></span><input id="emp-valor" name="valor" type="text" inputmode="decimal" autocomplete="off" enterkeyhint="done" placeholder="<?= e(numInput(0)) ?>" value="<?= e($campo) ?>" aria-describedby="emp-ajuda"></span>
                    </div>
                    <div class="campo">
                        <label for="emp-mes"><?= e(t('Em que mês?')) ?></label>
                        <select id="emp-mes" name="mes">
                            <?php foreach ($sit['meses'] as $m): ?>
                                <option value="<?= e($m) ?>"<?= $m === $mes ? ' selected' : '' ?>><?= e(monthLabel($m)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <p class="emp-ajuda" id="emp-ajuda"><?= e(t('É só uma simulação: nada é salvo.')) ?></p>
                    <div class="emp-acoes">
                        <button class="btn btn-ghost" type="submit"><?= e(t('Simular')) ?></button>
                        <button class="btn btn-ghost" type="submit" name="quitar" value="1"><?= e(t('Simular quitar tudo')) ?></button>
                    </div>
                </form>
            <?php endif; ?>

            <?php if (!$sit['quitado'] && $sit['quitaPlano'] !== null && $sit['quitaPlano'] !== $sit['quita']): ?>
                <p class="emp-plano"><?= e(t('O plano usa a sobra de cada mês para adiantar e termina de pagar em {mes}.', ['mes' => mesAno($sit['quitaPlano'])])) ?> <a href="/futuro"><?= e(t('Ver o plano')) ?></a></p>
            <?php endif; ?>
        </div>

        <div class="emp-col">
            <p class="sr-only" role="status" data-emp-anuncio></p>
            <div id="emp-resultado"><?php \Financeiro\View::partial('emprestimo/_resultado', get_defined_vars()); ?></div>

            <?php if ($catAmort !== null && !$sit['quitado']): ?>
                <?php $dup = !empty($form['duplicado']); $erroForm = $form['erro'] ?? null; ?>
                <details class="card dobra emp-consolidar-d" id="emp-consolidar"<?= $pago !== '' || $dup || $erroForm !== null ? ' open' : '' ?>>
                <summary><span class="titulo"><?= e(t('Já paguei: consolidar um adiantamento')) ?></span></summary>
                <form class="emp-form emp-consolidar" method="post" action="/emprestimo/adiantamento" data-emp-consolidar>
                    <?= csrfField() ?>
                    <div class="emp-consolidar-cab">
                        <p class="emp-ajuda"><?= e(t('Depois de pagar o adiantamento, lance aqui: o valor entra no Extrato, sai da conta e abate a dívida.')) ?></p>
                    </div>
                    <?php if ($erroForm !== null): ?><p class="aviso aviso-error emp-inteira" role="alert"><?= e((string) $erroForm) ?></p><?php endif; ?>
                    <?php if ($dup): ?><p class="aviso aviso-warn emp-inteira" role="alert"><?= e(t('Já existe um adiantamento com esse valor nessa data. Se foi outro pagamento, confirme abaixo.')) ?></p><?php endif; ?>
                    <div class="campo">
                        <label for="emp-pago"><?= e(t('Valor pago')) ?></label>
                        <span class="emp-valor"><span aria-hidden="true"><?= e(moedaSimbolo()) ?></span><input id="emp-pago" name="valor" type="text" inputmode="decimal" autocomplete="off" enterkeyhint="done" placeholder="<?= e(numInput(0)) ?>" required value="<?= e((string) ($form['valor'] ?? $pago)) ?>"></span>
                    </div>
                    <div class="campo">
                        <label for="emp-data"><?= e(t('Data do pagamento')) ?></label>
                        <input id="emp-data" name="data" type="date" required max="<?= e($hoje) ?>" value="<?= e((string) ($form['data'] ?? $hoje)) ?>">
                    </div>
                    <?php if ($dup): ?><input type="hidden" name="confirmar_dup" value="1"><?php endif; ?>
                    <button class="btn btn-primary emp-inteira" type="submit"><?= e($dup ? t('Registrar outro') : t('Lançar no Extrato')) ?></button>
                </form>
                </details>
            <?php endif; ?>

            <?php if ($feitos !== []): ?>
                <section class="card emp-feitos" aria-labelledby="emp-feitos-t">
                    <div class="card-head">
                        <h2 class="titulo" id="emp-feitos-t"><?= e(t('Adiantamentos feitos')) ?></h2>
                        <b class="num"><?= e(money(array_sum(array_map(static fn($f) => (float) $f['valor'], $feitos)))) ?></b>
                    </div>
                    <ul>
                        <?php foreach ($feitos as $f): ?>
                            <li><a href="/lancamentos/<?= (int) $f['id'] ?>/editar?volta=<?= e(rawurlencode('/emprestimo')) ?>" aria-label="<?= e(t('Editar adiantamento de {data}', ['data' => diaMesAno((string) $f['data'])])) ?>">
                                <span class="num"><?= e(diaMesAno((string) $f['data'])) ?></span>
                                <b class="num"><?= e(num((float) $f['valor'])) ?></b>
                            </a></li>
                        <?php endforeach; ?>
                    </ul>
                    <a class="btn-link" href="/lancamentos?mes=<?= e(substr((string) $feitos[0]['data'], 0, 8) . '01') ?>&amp;cat=<?= (int) $catAmort ?>"><?= e(t('Ver no Extrato')) ?></a>
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
                <summary><?= e(t('Dados do empréstimo')) ?></summary>
                <form class="aj-form" method="post" action="/emprestimo/dados">
                    <?= csrfField() ?>
                    <p class="aj-ajuda"><?= e(t('Mudar estes dados recalcula o plano inteiro: Início, Mês, Relatório e esta aba.')) ?></p>
                    <div class="aj-campo">
                        <label for="emp-d-saldo_devedor"><?= e(t('Saldo devedor do extrato')) ?></label>
                        <span class="aj-input"><input id="emp-d-saldo_devedor" type="text" name="valores[saldo_devedor]" inputmode="decimal" autocomplete="off" placeholder="<?= e(numInput(0)) ?>" value="<?= e($saldoTxt) ?>"<?= $invalido('saldo_devedor') ?>></span>
                    </div>
                    <div class="aj-campo">
                        <label for="emp-d-saldo-mes"><?= e(t('Antes da parcela de')) ?></label>
                        <span class="aj-input aj-input-mes"><input id="emp-d-saldo-mes" type="month" name="valores[saldo_devedor_mes]" max="<?= e(substr($hoje, 0, 7)) ?>" value="<?= e($saldoMesTxt) ?>"<?= $invalido('saldo_devedor_mes') ?>></span>
                    </div>
                    <p class="aj-ajuda"><?= e(t('Copie o saldo do extrato do empréstimo. Sem ele, calculamos pela parcela, pelos juros e pelo prazo.')) ?></p>
                    <?php $campoDado('juros_mes', t('Juros ao mês'), '%'); ?>
                    <div class="aj-extra">
                        <label class="aj-check"><input type="checkbox" name="confirmado[juros_mes]" value="1"<?= !empty($aj['juros_mes']['confirmado']) ? ' checked' : '' ?>><span><?= e(t('Confirmado')) ?></span></label>
                        <?php if ($taxaCredito !== null): ?><span class="aj-taxa num"><?= e(t('Pelo crédito recebido: {valor}%', ['valor' => num($taxaCredito * 100)])) ?></span><?php endif; ?>
                    </div>
                    <?php $campoDado('parcela_mensal', t('Parcela')); ?>
                    <?php $aParte = (int) round((float) ($aj['parcela_modo']['valor'] ?? 1)) === 2; ?>
                    <fieldset class="aj-grupo-modo emp-modo">
                        <legend><?= e(t('Como a parcela é paga?')) ?></legend>
                        <label class="aj-marcar"><input type="radio" name="parcela_modo" value="1"<?= $aParte ? '' : ' checked' ?>><span><?= e(t('Descontada do salário (consignado em folha)')) ?></span></label>
                        <label class="aj-marcar"><input type="radio" name="parcela_modo" value="2"<?= $aParte ? ' checked' : '' ?>><span><?= e(t('Paga à parte, com o dinheiro da conta')) ?></span></label>
                    </fieldset>
                    <?php $campoDado('n_parcelas', t('Número de parcelas'), '', 0); ?>
                    <div class="aj-campo">
                        <label for="emp-d-inicio"><?= e(t('1ª parcela descontada em')) ?></label>
                        <span class="aj-input aj-input-mes"><input id="emp-d-inicio" type="month" name="valores[consignado_inicio]" value="<?= e($iniTxt) ?>"<?= $invalido('consignado_inicio') ?>></span>
                    </div>
                    <p class="aj-ajuda"><?= e(t('A parcela só abate a dívida e sai das entradas a partir desse mês. Em branco, vale desde o início do plano.')) ?></p>
                    <?php $campoDado('recebido_emprestimo', t('Crédito recebido')); ?>
                    <?php if ($dadosErro !== null): ?><p class="aviso aviso-error" role="alert"><?= e((string) $dadosErro['msg']) ?></p><?php endif; ?>
                    <button type="submit" class="btn btn-primary aj-salvar"><?= e(t('Salvar dados')) ?></button>
                </form>
            </details>

            <?php if (!$sit['quitado']): ?>
                <details class="card dobra emp-como">
                    <summary><?= e(t('Como calculamos')) ?></summary>
                    <ul>
                        <?php if ($informado !== null): ?><li><?= e(t('A dívida parte do saldo informado: {valor} antes da parcela de {mes}.', ['valor' => money((float) $informado['valor']), 'mes' => monthLabel($informado['mes'])])) ?></li><?php endif; ?>
                        <li><?= t('Juros de {juros}% ao mês sobre o saldo e parcela fixa, como em {link}.', ['juros' => e(num((float) $sit['jurosMes'] * 100)), 'link' => '<a href="/emprestimo?dados=1#emp-dados">' . e(t('Dados do empréstimo')) . '</a>']) ?></li>
                        <li><?= e(t('O adiantamento abate o saldo no mês escolhido, depois da parcela, e o prazo encurta.')) ?></li>
                        <li><?= e(t('Comparamos com pagar só as parcelas, sem nenhum adiantamento.')) ?></li>
                        <li><?= e(t('O valor que vale é o da instituição: peça o saldo atualizado antes de pagar.')) ?></li>
                    </ul>
                </details>
            <?php endif; ?>
        </div>
    </div>
</div>
