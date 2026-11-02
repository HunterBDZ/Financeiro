<?php
use Financeiro\Auth;

/**
 * Início — o mês de referência pelo que aconteceu de verdade (lançamentos).
 * Saudação; o cartão do saldo (em conta hoje, uma frase que responde quanto dá para gastar e o resto
 * dobrado em "Ver detalhes", com o olho que esconde os valores); o mês em três linhas (entrou, saiu e a
 * sobra com o nome da base; o detalhe fica dobrado); para onde foi; conferir com o banco; últimos lançamentos.
 * Variáveis: $hoje, $mesRef, $mesCal, $estadoRef, $reserva, $conta, $conf, $conferirAberto,
 * $conferirErro, $painel, $sobra (Sobra), $resposta (DashboardController::resposta), $ultimos, $alertas.
 */
use Financeiro\Services\Sobra;

$p        = $painel;
$previsto = $p['base'] === 'previsto';
$mesNome  = mesExtenso($mesRef);
$share    = static fn(float $v, float $tot): int => $tot > 0.004 ? (int) round($v / $tot * 100) : 0;

// distância até a reserva (o cartão do saldo mostra acima/abaixo/no vermelho)
$dif      = $conta - $reserva;
$abaixo   = $dif < -0.004;
$vermelho = $conta < -0.004;

$prog = null;
if ($mesRef === $mesCal && $estadoRef !== 'real') {
    $dia  = (int) substr($hoje, 8, 2);
    $dias = diasNoMes($mesRef);
    $prog = ['dia' => $dia, 'dias' => $dias, 'pct' => (int) round($dia / $dias * 100)];
}

// a sobra do mês com o nome da base; a renda é a sem a parcela (a ponte mostra a conta)
$balanco  = (float) $sobra['sobra'];
$topMax   = $p['top'] !== [] ? max(array_column($p['top'], 'valor')) : 0.0;

// a frase do cartão do saldo: o mês da previsão, o dia em que ele termina e o valor em destaque
$mesFrase  = $mesPrevisao === $mesCal ? t('Este mês') : mb_convert_case(mesExtenso($mesPrevisao), MB_CASE_TITLE);
$diaFim    = diasNoMes($mesPrevisao);
$valorB    = static fn(float $v): string => '<b class="num">' . e(money($v)) . '</b>';
$frase     = null;
$fraseMais = null;
if ($resposta !== null) {
    $v = (float) $resposta['valor'];
    $frase = match ($resposta['tipo']) {
        'vermelho' => t('{mes} fecha no vermelho: faltam {valor} para zerar.', ['mes' => e($mesFrase), 'valor' => $valorB($v)]),
        'abaixo'   => t('{mes} fecha abaixo da reserva: faltam {valor}.', ['mes' => e($mesFrase), 'valor' => $valorB($v)]),
        'justo'    => e($reserva > 0.004 ? t('{mes} fecha na reserva, sem folga para gastar mais.', ['mes' => $mesFrase]) : t('{mes} fecha zerado, sem folga para gastar mais.', ['mes' => $mesFrase])),
        default    => $mesPrevisao === $mesCal
            ? ($reserva > 0.004
                ? t('Dá para gastar {valor} até o dia {dia} sem passar da reserva.', ['valor' => $valorB($v), 'dia' => $diaFim])
                : t('Dá para gastar {valor} até o dia {dia} sem ficar no vermelho.', ['valor' => $valorB($v), 'dia' => $diaFim]))
            : ($reserva > 0.004
                ? t('Dá para gastar {valor} até o fim de {mes} sem passar da reserva.', ['valor' => $valorB($v), 'mes' => e(mesExtenso($mesPrevisao))])
                : t('Dá para gastar {valor} até o fim de {mes} sem ficar no vermelho.', ['valor' => $valorB($v), 'mes' => e(mesExtenso($mesPrevisao))])),
    };
    $a = (float) $resposta['adianta'];
    if ($a > 0.004) {
        $fraseMais = abs($a - $v) < 0.01
            ? e(t('Pelo plano, esse valor vai adiantar o empréstimo, e o mês fecha na reserva.'))
            : t('Pelo plano, {valor} disso vai adiantar o empréstimo.', ['valor' => $valorB($a)]);
    }
}

// saudação e a data por extenso
$hora      = (int) \Financeiro\Relogio::agora()->format('G');
$saudacao  = $hora < 5 ? t('Boa noite') : ($hora < 12 ? t('Bom dia') : ($hora < 18 ? t('Boa tarde') : t('Boa noite')));
$quem      = trim((string) (Auth::nome() ?? ''));
$diasSem   = [t('domingo'), t('segunda-feira'), t('terça-feira'), t('quarta-feira'), t('quinta-feira'), t('sexta-feira'), t('sábado')];
$tsHoje    = (int) strtotime($hoje);
$diaSem    = $diasSem[(int) date('w', $tsHoje)];
$dataLonga = mb_strtoupper(mb_substr($diaSem, 0, 1)) . mb_substr($diaSem, 1) . ', ' . t('{dia} de {mes}', ['dia' => (int) date('j', $tsHoje), 'mes' => mesExtenso($hoje)]);

// cartão do saldo: estado e barra até a reserva
$estadoSaldo = $vermelho ? t('No vermelho') : ($abaixo ? t('Abaixo da reserva') : '');
$clsSaldo    = $vermelho ? 'is-vermelho' : ($abaixo ? 'is-abaixo' : 'is-acima');
$pReserva    = $reserva > 0.004 ? max(0.0, min(1.0, $conta / $reserva)) * 100 : ($conta >= 0 ? 100.0 : 0.0);
$txtConta    = num($conta);
$virgula     = strrpos($txtConta, sepDecimal());
$contaInt    = $virgula === false ? $txtConta : substr($txtConta, 0, $virgula);
$contaCent   = $virgula === false ? '' : substr($txtConta, $virgula);
$quandoFoi   = static function (string $data) use ($hoje): string {
    if ($data === $hoje) {
        return t('Hoje');
    }
    if ($data === date('Y-m-d', strtotime($hoje . ' -1 day'))) {
        return t('Ontem');
    }
    return diaMesCurto($data);
};
?>
<div class="dash">

    <header class="dash-head">
        <p class="dash-ola"><?= e($saudacao) ?><?= $quem !== '' ? ',' : '' ?></p>
        <h1 class="pg-titulo"><?= e($quem !== '' ? $quem : t('Início')) ?></h1>
        <p class="dash-data"><?= e($dataLonga) ?></p>
    </header>

    <?php if ($alertas !== []): ?>
        <div class="dash-alertas">
            <?php foreach ($alertas as $a): ?>
                <a class="dash-alerta" href="/mes/fechar?mes=<?= e(rawurlencode($a['mes'])) ?>">
                    <span class="dash-alerta-ic"><?= svgIcon('alert', 18) ?></span>
                    <span class="dash-alerta-txt"><b><?= e(t('{mes} terminou e ainda está aberto.', ['mes' => mesAno($a['mes'])])) ?></b><small><?= e(t('Feche o mês para conferir com o banco.')) ?></small></span>
                    <span class="dash-alerta-acao"><?= e(t('Fechar')) ?> <?= svgIcon('chevron', 16) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($roteiro)): ?>
    <section class="card roteiro" aria-labelledby="roteiro-t">
        <h2 class="titulo" id="roteiro-t"><?= e(t('Primeiros passos')) ?></h2>
        <ul class="roteiro-lista">
            <?php foreach ($roteiro as [$href, $texto, $feito]): ?>
                <li class="<?= $feito ? 'is-feito' : '' ?>"><?= svgIcon($feito ? 'check' : 'plus', 16) ?><?= $feito ? '<span>' . e($texto) . '</span>' : '<a href="' . e($href) . '">' . e($texto) . '</a>' ?></li>
            <?php endforeach; ?>
        </ul>
        <div class="roteiro-fim">
            <a href="/ajuda"><?= e(t('Ver a ajuda')) ?></a>
            <form method="post" action="/roteiro/dispensar"><?= csrfField() ?><button type="submit" class="btn btn-ghost"><?= e(t('Dispensar')) ?></button></form>
        </div>
    </section>
    <?php endif; ?>

    <div class="dash-grade">

        <!-- o saldo de hoje contra a reserva -->
        <section class="saldo <?= $clsSaldo ?>" aria-labelledby="saldo-t">
            <div class="saldo-l1">
                <h2 id="saldo-t"><?= e(t('Em conta hoje')) ?> <?= dica('em-conta', t('Em conta hoje')) ?></h2>
                <button type="button" class="saldo-olho" data-ocultar aria-pressed="false" aria-label="<?= e(t('esconder valores')) ?>"><?= svgIcon('eye', 18, 'olho-aberto') ?><?= svgIcon('eye-off', 18, 'olho-fechado') ?></button>
                <?php if ($estadoSaldo !== ''): ?><span class="saldo-estado"><?= e($estadoSaldo) ?></span><?php endif; ?>
            </div>
            <p class="saldo-v num"><small><?= e(moedaSimbolo()) ?></small><?= e($contaInt) ?><span><?= e($contaCent) ?></span></p>
            <?php if ($frase !== null): ?>
                <p class="saldo-frase is-<?= e((string) $resposta['tipo']) ?>" data-saldo-frase="<?= e((string) $resposta['tipo']) ?>"><?= $frase ?> <?= dica('gastar', t('esse valor')) ?><?php if ($fraseMais !== null): ?> <span class="saldo-frase-2"><?= $fraseMais ?></span><?php endif; ?></p>
            <?php endif; ?>
            <div class="saldo-medidor" aria-hidden="true"><i style="--p:<?= e(number_format($pReserva, 1, '.', '')) ?>%"></i></div>
            <?php if ($reserva > 0.004 || $vermelho || $previsaoFim !== null): ?>
            <details class="saldo-det">
                <summary><?= e(t('Ver detalhes')) ?></summary>
                <div class="saldo-det-c">
                    <?php if ($reserva > 0.004 || $vermelho): ?>
                    <p class="saldo-rod num">
                        <span><?php if ($vermelho): ?><?= t('Faltam {valor} para zerar', ['valor' => '<b>' . e(num(abs($conta))) . '</b>']) ?><?php elseif ($abaixo): ?><?= t('Faltam {valor} para a reserva', ['valor' => '<b>' . e(num(abs($dif))) . '</b>']) ?><?php else: ?><?= t('{valor} acima da reserva', ['valor' => '<b>' . e(num($dif)) . '</b>']) ?><?php endif; ?></span>
                        <span><?= t('Reserva {valor}', ['valor' => '<b>' . e(num($reserva)) . '</b>']) ?> <?= dica('reserva', t('Reserva')) ?></span>
                    </p>
                    <?php endif; ?>
                    <?php if ($previsaoFim !== null): ?>
                        <p class="saldo-prev num" data-previsao-fim><?= t('Previsão para o fim de {mes}: {valor}', ['mes' => e(mesExtenso($mesPrevisao)), 'valor' => '<b>' . e(num($previsaoFim)) . '</b>']) ?> <?= dica('previsao', t('previsão')) ?></p>
                        <?php if (!empty($previsaoSemAdiantar)): ?><p class="saldo-prev num"><?= t('Sem adiantar o empréstimo: {valor}', ['valor' => '<b>' . e(num($previsaoSemAdiantar)) . '</b>']) ?></p><?php endif; ?>
                    <?php endif; ?>
                </div>
            </details>
            <?php endif; ?>
        </section>

        <!-- o mês em três linhas; o detalhe fica dobrado -->
        <section class="card dash-resumo" aria-labelledby="dash-rec-t">
            <div class="card-head">
                <h2 class="titulo" id="dash-rec-t"><?= e($previsto ? t('{mes} previsto', ['mes' => mb_convert_case($mesNome, MB_CASE_TITLE)]) : t('{mes} até hoje', ['mes' => mb_convert_case($mesNome, MB_CASE_TITLE)])) ?></h2>
                <?php if ($prog !== null): ?><span class="card-acao num"><?= e(t('dia {dia} de {dias}', ['dia' => $prog['dia'], 'dias' => $prog['dias']])) ?></span><?php else: ?><span class="estado estado-<?= e($estadoRef) ?>"><?= e(estadoLabel($estadoRef)) ?></span><?php endif; ?>
            </div>
            <ul class="resumo">
                <li><span class="ri pos"><?= svgIcon('arrow-up', 16) ?></span><span class="resumo-n"><?= e(t('Entrou')) ?></span><b class="num pos"><?= e(num((float) $sobra['entrou'])) ?></b></li>
                <li><span class="ri neg"><?= svgIcon('arrow-down', 16) ?></span><span class="resumo-n"><?= e(t('Saiu')) ?></span><b class="num neg"><?= e(num((float) $sobra['saiu'])) ?></b></li>
                <li class="resumo-total"><span class="resumo-n"><?= e(Sobra::rotulo((string) $sobra['base'])) ?> <?= dica('numeros', Sobra::rotulo((string) $sobra['base'])) ?></span><b class="num <?= $balanco < -0.004 ? 'neg' : 'pos' ?>" data-sobra="<?= e((string) $sobra['base']) ?>"><?= e(num($balanco)) ?></b></li>
            </ul>
            <details class="resumo-det">
                <summary><?= e(t('Ver o detalhe')) ?></summary>
                <dl class="resumo-lista num">
                    <div><dt><?= e(t('Salário')) ?></dt><dd><?= e(num($p['sal'])) ?></dd></div>
                    <div><dt><?= e(t('Outras entradas')) ?></dt><dd><?= e(num($p['outras'])) ?></dd></div>
                    <?php if (count($p['outrasItens']) > 1): ?>
                        <?php foreach ($p['outrasItens'] as $it): ?>
                            <div class="resumo-sub"><dt><?= e($it['nome']) ?></dt><dd><?= e(num($it['valor'])) ?></dd></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <div><dt><?= e(t('Essenciais')) ?></dt><dd><?= e(num($p['ess'])) ?></dd></div>
                    <div><dt><?= e(t('Não essenciais')) ?></dt><dd><?= e(num($p['naoess'])) ?></dd></div>
                    <?php $movSai = (float) $sobra['saiu'] - (float) $sobra['saidas']; ?>
                    <?php if ($movSai >= 0.005): ?><div><dt><?= e(t('Empréstimo e poupança')) ?></dt><dd><?= e(num($movSai)) ?></dd></div><?php endif; ?>
                </dl>
                <?php if ((float) $sobra['entrou'] > 0.004): ?>
                    <p class="dash-nota"><?= e(t('As saídas foram {pct}% das entradas.', ['pct' => $share((float) $sobra['saiu'], (float) $sobra['entrou'])])) ?></p>
                <?php endif; ?>
            </details>
        </section>

        <!-- para onde foi -->
        <section class="card dash-gastos" aria-labelledby="dash-top-t">
            <div class="card-head">
                <h2 class="titulo" id="dash-top-t"><?= e(t('Para onde foi')) ?> <small class="dash-tit-nota"><?= e(t('% dos gastos')) ?></small></h2>
                <a class="card-acao" href="/lancamentos?mes=<?= e($mesRef) ?>"><?= e(t('Extrato')) ?> <?= svgIcon('chevron', 15) ?></a>
            </div>
            <?php if ($p['top'] === []): ?>
                <p class="dash-vazio"><?= e(t('Nada lançado em {mes} ainda.', ['mes' => $mesNome])) ?></p>
            <?php else: ?>
                <ol class="barras">
                    <?php foreach ($p['top'] as $t): ?>
                        <li>
                            <a class="barras-i" href="/lancamentos?mes=<?= e($mesRef) ?>&amp;cat=<?= (int) $t['id'] ?>">
                                <?= iconeCategoria((int) $t['id'], (string) $t['nome_banco'], '', 40, 'barras-ic') ?>
                                <span class="barras-n"><?= e($t['nome']) ?></span>
                                <span class="barras-v num"><?= e(num($t['valor'])) ?><small><?= $share($t['valor'], $p['saidas']) ?>%</small></span>
                                <span class="trilho" aria-hidden="true"><i style="--w:<?= $topMax > 0 ? round($t['valor'] / $topMax * 100, 1) : 0 ?>%"></i></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>

        <!-- conferir com o banco: o único jeito de validar o saldo calculado -->
        <details class="card dobra dash-conf" id="conferir"<?= $conferirAberto ? ' open' : '' ?>>
            <summary>
                <span class="titulo"><?= e(t('Conferir com o banco')) ?></span>
                <span class="dash-conf-ult num">
                    <?php if ($conf !== null): ?>
                        <?= e($conf['bate'] ? t('Bate, em {data}', ['data' => diaMes($conf['data'])]) : t('Diferença de {dif}, em {data}', ['dif' => moneySigned($conf['dif']), 'data' => diaMes($conf['data'])])) ?>
                    <?php else: ?>
                        <?= e(t('Nunca conferido')) . "\n" ?>
                    <?php endif; ?>
                </span>
            </summary>
            <form class="dash-conf-form" method="post" action="/conferir">
                <?= csrfField() ?>
                <input type="hidden" name="volta" value="/">
                <label class="campo">
                    <span><?= e(t('Saldo que o banco mostra')) ?></span>
                    <input type="text" name="saldo_informado" inputmode="decimal" autocomplete="off" enterkeyhint="done" placeholder="<?= e(t('ex.: 1.546,00')) ?>"
                           value="<?= e((string) ($conferirErro['valor'] ?? '')) ?>"<?= $conferirErro !== null ? ' aria-invalid="true" aria-describedby="conf-erro" data-autofocus' : '' ?>>
                </label>
                <?php if ($conferirErro !== null): ?><p class="aviso aviso-error" id="conf-erro" role="alert"><?= e((string) $conferirErro['msg']) ?></p><?php endif; ?>
                <fieldset class="quando" data-quando>
                    <legend class="sr-only"><?= e(t('quando')) ?></legend>
                    <label class="chip-r"><input type="radio" name="quando" value="hoje"<?= ($conferirErro['quando'] ?? 'hoje') !== 'outro' ? ' checked' : '' ?>><span><?= e(t('Hoje')) ?></span></label>
                    <label class="chip-r"><input type="radio" name="quando" value="outro"<?= ($conferirErro['quando'] ?? '') === 'outro' ? ' checked' : '' ?>><span><?= e(t('Outro dia')) ?></span></label>
                    <input type="date" name="data" class="quando-data" value="<?= e((string) ($conferirErro['data'] ?? $hoje)) ?>" max="<?= e($hoje) ?>" aria-label="<?= e(t('data do saldo')) ?>">
                </fieldset>
                <?php if ($conf !== null): ?>
                    <p class="dash-conf-det num"><?= e(t('Última conferência: {valor} em {data}.', ['valor' => num($conf['saldo']), 'data' => diaMes($conf['data'])])) ?></p>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary dash-conf-btn"><?= e(t('Conferir')) ?></button>
            </form>
        </details>

        <!-- últimos lançamentos -->
        <section class="card dash-ult" aria-labelledby="dash-ult-t">
            <div class="card-head">
                <h2 class="titulo" id="dash-ult-t"><?= e(t('Últimos lançamentos')) ?></h2>
                <a class="card-acao" href="/lancamentos"><?= e(t('Ver todos')) ?> <?= svgIcon('chevron', 15) ?></a>
            </div>
            <?php if ($ultimos === []): ?>
                <div class="dash-vazio">
                    <p><?= e(t('Nada lançado em {mes} ainda.', ['mes' => $mesNome])) ?></p>
                    <a class="btn btn-primary btn-sm" href="/lancar?volta=%2F" data-lancar><?= svgIcon('plus', 16) ?><?= e(t('Lançar')) ?></a>
                </div>
            <?php else: ?>
                <ul class="lista">
                    <?php foreach ($ultimos as $r):
                        $mov = (int) $r['operacional'] !== 1;
                        $ent = !$mov && (int) $r['sinal'] > 0;
                        $v   = (float) $r['valor'];
                        $txt = $mov ? '⇄ ' . num($v) : ($ent ? '+' . num($v) : MENOS . num($v));
                        $cls = $mov ? 'dim' : ($ent ? 'pos' : 'neg');
                        $cat = ['nome' => $r['categoria_nome'], 'apelido' => $r['apelido']];
                        $desc = descricaoTela(trim((string) $r['descricao']), $cat);
                        ?>
                        <li>
                            <a class="lista-i" href="/lancamentos/<?= (int) $r['id'] ?>/editar?volta=%2F">
                                <?= iconeCategoria((int) $r['categoria_id'], (string) $r['categoria_nome'], (string) ($r['grupo'] ?? ''), 40) ?>
                                <span class="lista-n"><b><?= e($desc !== '' ? $desc : nomeCurto($cat)) ?></b><?php if ($desc !== '' && mb_strtolower($desc) !== mb_strtolower(nomeCurto($cat))): ?><small><?= e(nomeCurto($cat)) ?></small><?php endif; ?></span>
                                <span class="lista-v"><b class="num <?= $cls ?>"><?= e($txt) ?></b><small><?= e($quandoFoi((string) $r['data'])) ?></small></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

    </div>
</div>
