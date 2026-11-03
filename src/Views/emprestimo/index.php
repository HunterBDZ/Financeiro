<?php
/**
 * Aba Empréstimo, na grade: a resposta da tela é quanto falta pagar (o número grande); embaixo, os números de
 * resumo e a linha do tempo das parcelas, com "faltam N parcelas" e um fim só, explicado: o fim das parcelas (pagando
 * só as parcelas) e a quitação prevista pelo plano, cada um com o seu nome. Depois, simular um adiantamento
 * (o formulário e o resultado lado a lado; _resultado), o que já foi pago e os dados do contrato.
 * Sem JS o formulário é um GET normal. "Consolidar" lança no Extrato o que já foi pago; ele fica dobrado e vazio:
 * simular não preenche nada, só o "Já paguei" do resultado leva o valor ($pago).
 * Variáveis: $sit (EmprestimoSimulacao::situacao), $sim, $erro, $mes, $campo, $hoje, $catAmort,
 *            $feitos (lançamentos da Amortização extra), $form (o que voltou com erro ou duplicado, ou null).
 *            $aj (ajustes por chave), $taxaCredito, $dadosErro, $dadosAberto: "Dados do empréstimo", o contrato.
 */
use Financeiro\Services\AnualResumo;

// com adiantamento lançado, acabar antes do contrato é o esperado: só compara enquanto não houver nenhum
$informado = $sit['informado'];
$difContrato = $feitos === [] && $sit['contrato'] !== null && $sit['quita'] !== null && !$sit['quitado'] && $sit['quita'] !== $sit['contrato'];

// a linha do tempo: da 1ª parcela (se informada) até o fim das parcelas; o que já foi pago em tinta cheia, o que falta
// claro. As parcelas pagas contam do início até o mês de referência; as que faltam vêm do cálculo ($sit['restantes'])
$iniInt = (int) round((float) ($aj['consignado_inicio']['valor'] ?? 0));
$iniMes = $iniInt > 0 ? sprintf('%04d-%02d-01', intdiv($iniInt, 100), $iniInt % 100) : null;
$restantes = $sit['restantes'] !== null ? (int) $sit['restantes'] : null;
$pagas = $iniMes !== null && $iniMes <= $sit['ref'] ? AnualResumo::mesesEntre($iniMes, $sit['ref']) : 0;
$total = $restantes !== null ? $pagas + $restantes : 0;
$pct = static fn(int $n): string => $total > 0 ? number_format(max(0, min(100, $n / $total * 100)), 2, '.', '') : '0';
$plano = !$sit['quitado'] && $sit['quitaPlano'] !== null && $sit['quita'] !== null && $sit['quitaPlano'] < $sit['quita'] ? (string) $sit['quitaPlano'] : null;
$noPlano = $plano !== null ? $pagas + max(0, AnualResumo::mesesEntre($sit['ref'], $plano)) : 0;
?>
<div class="grade emp">
    <?php \Financeiro\View::partial('partials/topo', ['titulo' => t('Empréstimo'), 'apoio' => $informado !== null ? t('Pelo saldo do extrato que você informou') : t('Estimativa pela parcela, pelos juros e pelo prazo')]); ?>

    <section class="faixa-grade emp-resposta" aria-labelledby="emp-falta-t">
        <?php if ($sit['quitado']): ?>
            <h2 class="rot-resposta" id="emp-falta-t"><?= e(t('Empréstimo quitado')) ?></h2>
            <p class="emp-quitado"><?= e(t('Não falta nada a pagar. Os dados do contrato continuam embaixo, se precisar conferir.')) ?></p>
        <?php else: ?>
            <h2 class="rot-resposta" id="emp-falta-t"><?= e(t('Falta pagar no fim de {mes}', ['mes' => monthLabel($sit['ref'])])) ?></h2>
            <p class="resposta-num"><?= valor((float) $sit['falta'], 'neutro', ['moeda' => true]) ?></p>
            <dl class="numeros emp-numeros" style="--colunas:3">
                <div><dt><?= e(t('Parcela por mês')) ?></dt><dd><?= valor((float) $sit['parcela']) ?></dd></div>
                <div><dt><?= e(t('Parcelas que faltam')) ?></dt><dd class="num"><?= $restantes !== null ? e((string) $restantes) : '—' ?></dd></div>
                <div><dt><?= e(t('Juros ainda a pagar')) ?></dt><dd><?= $sit['jurosFaltam'] !== null ? valor((float) $sit['jurosFaltam'], 'previsto') : '—' ?><span class="nota"><?= e(t('se você pagar só as parcelas')) ?></span></dd></div>
            </dl>

            <?php if ($restantes !== null && $total > 0): ?>
                <figure class="emp-linha" aria-labelledby="emp-linha-t">
                    <figcaption class="emp-linha-cab">
                        <span id="emp-linha-t"><?= e(tn($restantes, 'Falta 1 parcela', 'Faltam {n} parcelas')) ?></span>
                        <?php if ($pagas > 0): ?><span class="emp-linha-pagas num"><?= e(t('{pagas} de {total} pagas', ['pagas' => $pagas, 'total' => $total])) ?></span><?php endif; ?>
                    </figcaption>
                    <div class="emp-trilho<?= $total <= 120 ? ' com-parcelas' : '' ?>" aria-hidden="true">
                        <?php if ($pagas > 0): ?><i class="emp-t-pago" style="--k:<?= $pagas ?>"></i><?php endif; ?>
                        <i class="emp-t-falta" style="--k:<?= max(1, $restantes) ?>"></i>
                        <?php if ($plano !== null): ?><b class="emp-t-plano" style="--x:<?= $pct($noPlano) ?>%"></b><?php endif; ?>
                    </div>
                    <div class="emp-linha-eixo num">
                        <span><?= e($iniMes !== null && $pagas > 0 ? monthLabel($iniMes) : monthLabel($sit['ref'])) ?></span>
                        <span><?= e(monthLabel((string) $sit['quita'])) ?></span>
                    </div>
                    <dl class="emp-fins">
                        <div><dt><?= e(t('Fim das parcelas')) ?></dt><dd class="num"><?= e(mesAno((string) $sit['quita'])) ?></dd>
                            <dd class="emp-fins-nota"><?= e(t('pagando só as parcelas, sem adiantar')) ?></dd></div>
                        <?php if ($plano !== null): ?>
                            <div class="emp-fim-plano"><dt><?= e(t('Quitação prevista pelo plano')) ?></dt><dd class="num"><?= e(mesAno($plano)) ?></dd>
                                <dd class="emp-fins-nota"><?= t('com a sobra de cada mês indo para o empréstimo. {link}', ['link' => '<a class="link" href="/futuro">' . e(t('Ver o plano')) . '</a>']) ?></dd></div>
                        <?php endif; ?>
                    </dl>
                </figure>
            <?php elseif ($sit['quita'] === null): ?>
                <p class="emp-aviso atencao"><?= e(t('Com estes dados, a parcela não cobre os juros e não dá para dizer quando termina. Confira os dados do empréstimo.')) ?></p>
            <?php endif; ?>

            <?php if ($difContrato && $informado !== null): ?>
                <p class="emp-aviso atencao"><?= e(t('Com o saldo informado, o cálculo termina em {fim}, e o contrato em {contrato}. Se o fim do contrato estiver certo, confira os juros e a parcela.', ['fim' => mesAno($sit['quita']), 'contrato' => mesAno($sit['contrato'])])) ?> <a class="aj-link" href="/emprestimo?dados=1#emp-dados"><?= e(t('Ver os dados')) ?></a></p>
            <?php elseif ($difContrato): ?>
                <p class="emp-aviso atencao"><?= e(t('Pelo contrato, as {n} parcelas acabam em {contrato}, mas este cálculo chega a {fim}. Até informar o saldo do extrato, os números são estimativa.', ['n' => (int) $sit['nParcelas'], 'contrato' => mesAno($sit['contrato']), 'fim' => mesAno($sit['quita'])])) ?> <a class="aj-link" href="/emprestimo?dados=1#emp-dados"><?= e(t('Informar o saldo')) ?></a></p>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <?php if (!$sit['quitado']): ?>
    <section class="faixa-grade" aria-labelledby="emp-sim-t">
        <div class="secao-cab"><h2 id="emp-sim-t"><?= e(t('Simular um adiantamento')) ?></h2></div>
        <div class="emp-cols">
            <form class="emp-form" method="get" action="/emprestimo" data-emp-form>
                <div class="campo">
                    <label for="emp-valor"><?= e(t('Quanto quer adiantar?')) ?></label>
                    <span class="emp-valor"><span aria-hidden="true"><?= e(moedaSimbolo()) ?></span><input id="emp-valor" name="valor" type="text" inputmode="decimal" autocomplete="off" enterkeyhint="done" placeholder="<?= e(numInput(0)) ?>" value="<?= e($campo) ?>" aria-describedby="emp-ajuda"<?= $erro !== null ? ' aria-invalid="true"' : '' ?>></span>
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
                    <button class="btn btn-principal" type="submit"><?= e(t('Simular')) ?></button>
                    <button class="btn btn-contorno" type="submit" name="quitar" value="1"><?= e(t('Simular quitar tudo')) ?></button>
                </div>
            </form>
            <div class="emp-res-lugar">
                <p class="sr-only" role="status" data-emp-anuncio></p>
                <div id="emp-resultado"><?php \Financeiro\View::partial('emprestimo/_resultado', get_defined_vars()); ?></div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <section class="faixa-grade" aria-labelledby="emp-mais-t">
        <h2 class="sr-only" id="emp-mais-t"><?= e(t('Pagamentos e dados do empréstimo')) ?></h2>
        <div class="emp-dobras">
            <?php if ($catAmort !== null && !$sit['quitado']): ?>
                <?php $dup = !empty($form['duplicado']); $erroForm = $form['erro'] ?? null; ?>
                <details class="dobra emp-consolidar-d" id="emp-consolidar"<?= $pago !== '' || $dup || $erroForm !== null ? ' open' : '' ?>>
                <summary><?= e(t('Já paguei: consolidar um adiantamento')) ?></summary>
                <form class="emp-form emp-consolidar" method="post" action="/emprestimo/adiantamento" data-emp-consolidar>
                    <?= csrfField() ?>
                    <div class="emp-consolidar-cab">
                        <p class="emp-ajuda"><?= e(t('Depois de pagar o adiantamento, lance aqui: o valor entra no Extrato, sai da conta e abate a dívida.')) ?></p>
                    </div>
                    <?php if ($erroForm !== null): ?><p class="aj-erro emp-inteira" role="alert"><?= e((string) $erroForm) ?></p><?php endif; ?>
                    <?php if ($dup): ?><p class="aj-ajuda atencao emp-inteira" role="alert"><?= e(t('Já existe um adiantamento com esse valor nessa data. Se foi outro pagamento, confirme abaixo.')) ?></p><?php endif; ?>
                    <div class="campo">
                        <label for="emp-pago"><?= e(t('Valor pago')) ?></label>
                        <span class="emp-valor"><span aria-hidden="true"><?= e(moedaSimbolo()) ?></span><input id="emp-pago" name="valor" type="text" inputmode="decimal" autocomplete="off" enterkeyhint="done" placeholder="<?= e(numInput(0)) ?>" required value="<?= e((string) ($form['valor'] ?? $pago)) ?>"></span>
                    </div>
                    <div class="campo">
                        <label for="emp-data"><?= e(t('Data do pagamento')) ?></label>
                        <input id="emp-data" name="data" type="date" required max="<?= e($hoje) ?>" value="<?= e((string) ($form['data'] ?? $hoje)) ?>">
                    </div>
                    <?php if ($dup): ?><input type="hidden" name="confirmar_dup" value="1"><?php endif; ?>
                    <button class="btn btn-contorno emp-inteira" type="submit"><?= e($dup ? t('Registrar outro') : t('Lançar no Extrato')) ?></button>
                </form>
                </details>
            <?php endif; ?>

            <?php if ($feitos !== []): ?>
                <section class="emp-feitos" aria-labelledby="emp-feitos-t">
                    <div class="secao-cab"><h3 id="emp-feitos-t"><?= e(t('Adiantamentos feitos')) ?></h3><p><?= valor(array_sum(array_map(static fn($f) => (float) $f['valor'], $feitos)), 'saida') ?></p></div>
                    <ul>
                        <?php foreach ($feitos as $f): ?>
                            <li><a href="/lancamentos/<?= (int) $f['id'] ?>/editar?volta=<?= e(rawurlencode('/emprestimo')) ?>" aria-label="<?= e(t('Editar adiantamento de {data}', ['data' => diaMesAno((string) $f['data'])])) ?>">
                                <span class="num"><?= e(diaMesAno((string) $f['data'])) ?></span>
                                <?= valor(-(float) $f['valor'], 'saida') ?>
                            </a></li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="secao-pe"><a href="/lancamentos?mes=<?= e(substr((string) $feitos[0]['data'], 0, 8) . '01') ?>&amp;cat=<?= (int) $catAmort ?>"><?= e(t('Ver no Extrato')) ?></a></p>
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
            <details class="dobra emp-dados" id="emp-dados"<?= $dadosAberto ? ' open' : '' ?>>
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
                        <label class="aj-marcar"><input type="radio" name="parcela_modo" value="1"<?= $aParte ? '' : ' checked' ?>><span><?= e(t('Descontada do salário, antes de cair na conta')) ?></span></label>
                        <label class="aj-marcar"><input type="radio" name="parcela_modo" value="2"<?= $aParte ? ' checked' : '' ?>><span><?= e(t('Paga à parte, com o dinheiro da conta')) ?></span></label>
                    </fieldset>
                    <?php $campoDado('n_parcelas', t('Número de parcelas'), '', 0); ?>
                    <div class="aj-campo">
                        <label for="emp-d-inicio"><?= e(t('1ª parcela descontada em')) ?></label>
                        <span class="aj-input aj-input-mes"><input id="emp-d-inicio" type="month" name="valores[consignado_inicio]" value="<?= e($iniTxt) ?>"<?= $invalido('consignado_inicio') ?>></span>
                    </div>
                    <p class="aj-ajuda"><?= e(t('A parcela só abate a dívida e sai das entradas a partir desse mês. Em branco, vale desde o início do plano.')) ?></p>
                    <?php $campoDado('recebido_emprestimo', t('Crédito recebido')); ?>
                    <?php if ($dadosErro !== null): ?><p class="aj-erro" role="alert"><?= e((string) $dadosErro['msg']) ?></p><?php endif; ?>
                    <button type="submit" class="btn btn-contorno aj-salvar"><?= e(t('Salvar dados')) ?></button>
                </form>
            </details>

            <?php if (!$sit['quitado']): ?>
                <details class="dobra emp-como">
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
    </section>
</div>
