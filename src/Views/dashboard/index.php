<?php
use Financeiro\Auth;

/**
 * Início — o mês de referência pelo que aconteceu de verdade (lançamentos).
 * Saudação; o cartão do saldo (em conta hoje contra a reserva, com o olho que esconde os valores);
 * o mês em três linhas (entrou, saiu, resultado; o detalhe fica dobrado); para onde foi;
 * conferir com o banco; últimos lançamentos.
 * Variáveis: $hoje, $mesRef, $mesCal, $estadoRef, $reserva, $conta, $conf, $conferirAberto,
 * $conferirErro, $painel, $ultimos, $alertas.
 */

$p        = $painel;
$previsto = $p['base'] === 'previsto';
$mesNome  = mesExtenso($mesRef);
$share    = static fn(float $v, float $tot): int => $tot > 0.004 ? (int) round($v / $tot * 100) : 0;

// distância até a reserva (o cartão do saldo mostra acima/abaixo/no vermelho)
$dif      = $conta - $reserva;
$abaixo   = $dif < -0.004;
$vermelho = $conta < -0.004;

$prog = null;
if ($mesRef === $mesCal) {
    $dia  = (int) substr($hoje, 8, 2);
    $dias = diasNoMes($mesRef);
    $prog = ['dia' => $dia, 'dias' => $dias, 'pct' => (int) round($dia / $dias * 100)];
}

$balanco  = (float) $p['balanco'];
$topMax   = $p['top'] !== [] ? max(array_column($p['top'], 'valor')) : 0.0;

// saudação e a data por extenso
$hora      = (int) date('G');
$saudacao  = $hora < 5 ? 'Boa noite' : ($hora < 12 ? 'Bom dia' : ($hora < 18 ? 'Boa tarde' : 'Boa noite'));
$quem      = trim((string) (Auth::nome() ?? ''));
$diasSem   = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
$tsHoje    = (int) strtotime($hoje);
$diaSem    = $diasSem[(int) date('w', $tsHoje)];
$dataLonga = mb_strtoupper(mb_substr($diaSem, 0, 1)) . mb_substr($diaSem, 1) . ', ' . (int) date('j', $tsHoje) . ' de ' . mesExtenso($hoje);

// cartão do saldo: estado e barra até a reserva
$estadoSaldo = $vermelho ? 'No vermelho' : ($abaixo ? 'Abaixo da reserva' : 'Acima da reserva');
$clsSaldo    = $vermelho ? 'is-vermelho' : ($abaixo ? 'is-abaixo' : 'is-acima');
$pReserva    = $reserva > 0.004 ? max(0.0, min(1.0, $conta / $reserva)) * 100 : ($conta >= 0 ? 100.0 : 0.0);
$txtConta    = num($conta);
$virgula     = strrpos($txtConta, ',');
$contaInt    = $virgula === false ? $txtConta : substr($txtConta, 0, $virgula);
$contaCent   = $virgula === false ? '' : substr($txtConta, $virgula);
$quandoFoi   = static function (string $data) use ($hoje): string {
    if ($data === $hoje) {
        return 'Hoje';
    }
    if ($data === date('Y-m-d', strtotime($hoje . ' -1 day'))) {
        return 'Ontem';
    }
    return (int) date('j', (int) strtotime($data)) . ' ' . mesCurto($data);
};
?>
<div class="dash">

    <header class="dash-head">
        <p class="dash-ola"><?= e($saudacao) ?><?= $quem !== '' ? ',' : '' ?></p>
        <h1 class="pg-titulo"><?= e($quem !== '' ? $quem : 'Início') ?></h1>
        <p class="dash-data"><?= e($dataLonga) ?></p>
    </header>

    <?php if ($alertas !== []): ?>
        <div class="dash-alertas">
            <?php foreach ($alertas as $a): ?>
                <a class="dash-alerta" href="/mes/fechar?mes=<?= e(rawurlencode($a['mes'])) ?>">
                    <span class="dash-alerta-ic"><?= svgIcon('alert', 18) ?></span>
                    <span class="dash-alerta-txt"><b><?= e(monthLabel($a['mes'])) ?> terminou e ainda está aberto.</b><small>Feche o mês para conferir com o banco.</small></span>
                    <span class="dash-alerta-acao">Fechar <?= svgIcon('chevron', 16) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="dash-grade">

        <!-- o saldo de hoje contra a reserva -->
        <section class="saldo <?= $clsSaldo ?>" aria-labelledby="saldo-t">
            <div class="saldo-l1">
                <h2 id="saldo-t">Em conta hoje</h2>
                <button type="button" class="saldo-olho" data-ocultar aria-pressed="false" aria-label="esconder valores"><?= svgIcon('eye', 18, 'olho-aberto') ?><?= svgIcon('eye-off', 18, 'olho-fechado') ?></button>
                <span class="saldo-estado"><?= e($estadoSaldo) ?></span>
            </div>
            <p class="saldo-v num"><small>R$</small><?= e($contaInt) ?><span><?= e($contaCent) ?></span></p>
            <div class="saldo-medidor" aria-hidden="true"><i style="--p:<?= e(number_format($pReserva, 1, '.', '')) ?>%"></i></div>
            <p class="saldo-rod num">
                <span><?php if ($vermelho): ?>Faltam <b><?= e(num(abs($conta))) ?></b> para zerar<?php elseif ($abaixo): ?>Faltam <b><?= e(num(abs($dif))) ?></b> para a reserva<?php else: ?><b><?= e(num($dif)) ?></b> acima da reserva<?php endif; ?></span>
                <span>Reserva <b><?= e(num($reserva, 0)) ?></b></span>
            </p>
        </section>

        <!-- o mês em três linhas; o detalhe fica dobrado -->
        <section class="card dash-resumo" aria-labelledby="dash-rec-t">
            <div class="card-head">
                <h2 class="titulo" id="dash-rec-t"><?= e(mb_convert_case($mesNome, MB_CASE_TITLE)) ?> <?= $previsto ? 'previsto' : 'até hoje' ?></h2>
                <?php if ($prog !== null): ?><span class="card-acao num">dia <?= $prog['dia'] ?> de <?= $prog['dias'] ?></span><?php else: ?><span class="estado estado-<?= e($estadoRef) ?>"><?= e(estadoLabel($estadoRef)) ?></span><?php endif; ?>
            </div>
            <ul class="resumo">
                <li><span class="ri pos"><?= svgIcon('arrow-up', 16) ?></span><span class="resumo-n">Entrou</span><b class="num pos"><?= e(num($p['entradas'])) ?></b></li>
                <li><span class="ri neg"><?= svgIcon('arrow-down', 16) ?></span><span class="resumo-n">Saiu</span><b class="num neg"><?= e(num($p['saidas'])) ?></b></li>
                <li class="resumo-total"><span class="resumo-n"><?= $balanco < -0.004 ? 'Déficit do mês' : 'Sobra do mês' ?></span><b class="num <?= $balanco < -0.004 ? 'neg' : 'pos' ?>"><?= e(($balanco < -0.004 ? MENOS : '') . num(abs($balanco))) ?></b></li>
            </ul>
            <details class="resumo-det">
                <summary>Ver o detalhe</summary>
                <dl class="resumo-lista num">
                    <div><dt>Soldo</dt><dd><?= e(num($p['sal'])) ?></dd></div>
                    <div><dt>Outras entradas</dt><dd><?= e(num($p['outras'])) ?></dd></div>
                    <?php if (count($p['outrasItens']) > 1): ?>
                        <?php foreach ($p['outrasItens'] as $it): ?>
                            <div class="resumo-sub"><dt><?= e($it['nome']) ?></dt><dd><?= e(num($it['valor'])) ?></dd></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <div><dt>Essenciais</dt><dd><?= e(num($p['ess'])) ?></dd></div>
                    <div><dt>Não essenciais</dt><dd><?= e(num($p['naoess'])) ?></dd></div>
                </dl>
                <?php if ($p['entradas'] > 0.004): ?>
                    <p class="dash-nota">As saídas foram <?= $share($p['saidas'], $p['entradas']) ?>% das entradas.</p>
                <?php endif; ?>
            </details>
        </section>

        <!-- para onde foi -->
        <section class="card dash-gastos" aria-labelledby="dash-top-t">
            <div class="card-head">
                <h2 class="titulo" id="dash-top-t">Para onde foi</h2>
                <a class="card-acao" href="/lancamentos?mes=<?= e($mesRef) ?>">Extrato <?= svgIcon('chevron', 15) ?></a>
            </div>
            <?php if ($p['top'] === []): ?>
                <p class="dash-vazio">Nada lançado em <?= e($mesNome) ?> ainda.</p>
            <?php else: ?>
                <ol class="barras">
                    <?php foreach ($p['top'] as $t): ?>
                        <li>
                            <a class="barras-i" href="/lancamentos?mes=<?= e($mesRef) ?>&amp;cat=<?= (int) $t['id'] ?>">
                                <?= iconeCategoria((int) $t['id'], (string) $t['nome'], '', 40, 'barras-ic') ?>
                                <span class="barras-n"><?= e($t['nome']) ?></span>
                                <span class="barras-v num"><?= e(num($t['valor'], 0)) ?><small><?= $share($t['valor'], $p['saidas']) ?>%</small></span>
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
                <span class="titulo">Conferir com o banco</span>
                <span class="dash-conf-ult num">
                    <?php if ($conf !== null): ?>
                        <?= $conf['bate'] ? 'Bate' : 'Diferença de ' . e(moneySigned($conf['dif'])) ?>, em <?= e(diaMes($conf['data'])) ?>
                    <?php else: ?>
                        Nunca conferido
                    <?php endif; ?>
                </span>
            </summary>
            <form class="dash-conf-form" method="post" action="/conferir">
                <?= csrfField() ?>
                <input type="hidden" name="volta" value="/">
                <label class="campo">
                    <span>Saldo que o banco mostra</span>
                    <input type="text" name="saldo_informado" inputmode="decimal" autocomplete="off" enterkeyhint="done" placeholder="ex.: 1.546,00"
                           value="<?= e((string) ($conferirErro['valor'] ?? '')) ?>"<?= $conferirErro !== null ? ' aria-invalid="true" aria-describedby="conf-erro" data-autofocus' : '' ?>>
                </label>
                <?php if ($conferirErro !== null): ?><p class="aviso aviso-error" id="conf-erro" role="alert"><?= e((string) $conferirErro['msg']) ?></p><?php endif; ?>
                <fieldset class="quando" data-quando>
                    <legend class="sr-only">quando</legend>
                    <label class="chip-r"><input type="radio" name="quando" value="hoje"<?= ($conferirErro['quando'] ?? 'hoje') !== 'outro' ? ' checked' : '' ?>><span>Hoje</span></label>
                    <label class="chip-r"><input type="radio" name="quando" value="outro"<?= ($conferirErro['quando'] ?? '') === 'outro' ? ' checked' : '' ?>><span>Outro dia</span></label>
                    <input type="date" name="data" class="quando-data" value="<?= e((string) ($conferirErro['data'] ?? $hoje)) ?>" max="<?= e($hoje) ?>" aria-label="data do saldo">
                </fieldset>
                <?php if ($conf !== null): ?>
                    <p class="dash-conf-det num">Última conferência: <?= e(num($conf['saldo'])) ?> em <?= e(diaMes($conf['data'])) ?>.</p>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary dash-conf-btn">Conferir</button>
            </form>
        </details>

        <!-- últimos lançamentos -->
        <section class="card dash-ult" aria-labelledby="dash-ult-t">
            <div class="card-head">
                <h2 class="titulo" id="dash-ult-t">Últimos lançamentos</h2>
                <a class="card-acao" href="/lancamentos">Ver todos <?= svgIcon('chevron', 15) ?></a>
            </div>
            <?php if ($ultimos === []): ?>
                <div class="dash-vazio">
                    <p>Nada lançado em <?= e($mesNome) ?> ainda.</p>
                    <a class="btn btn-primary btn-sm" href="/lancar?volta=%2F" data-lancar><?= svgIcon('plus', 16) ?>Lançar</a>
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
                        $desc = trim((string) $r['descricao']);
                        ?>
                        <li>
                            <a class="lista-i" href="/lancamentos/<?= (int) $r['id'] ?>/editar?volta=%2F">
                                <?= iconeCategoria((int) $r['categoria_id'], (string) $r['categoria_nome'], (string) ($r['grupo'] ?? ''), 40) ?>
                                <span class="lista-n"><b><?= e($desc !== '' ? $desc : nomeCurto($cat)) ?></b><small><?= e(nomeCurto($cat)) ?></small></span>
                                <span class="lista-v"><b class="num <?= $cls ?>"><?= e($txt) ?></b><small><?= e($quandoFoi((string) $r['data'])) ?></small></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

    </div>
</div>
