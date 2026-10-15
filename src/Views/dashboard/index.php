<?php
/**
 * Início — o mês de referência pelo que aconteceu de verdade (lançamentos).
 * Linha d'água: a reserva é a costura entre dois campos; o saldo em conta hoje flutua acima dela
 * (campo menta) ou afunda (campo laranja), na altura proporcional à distância. Depois, o mês como
 * recibo (entrou, saiu, resultado), conferir com o banco, maiores gastos e últimos lançamentos.
 * Variáveis: $hoje, $mesRef, $mesCal, $estadoRef, $reserva, $conta, $conf, $conferirAberto,
 * $conferirErro, $painel, $ultimos, $alertas.
 */

$p        = $painel;
$previsto = $p['base'] === 'previsto';
$semana   = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
$mesNome  = mesExtenso($mesRef);
$ano      = substr($mesRef, 0, 4);
$share    = static fn(float $v, float $tot): int => $tot > 0.004 ? (int) round($v / $tot * 100) : 0;
$fmtCurto = static fn(float $v): string => num($v, fmod(abs($v), 1) > 0.004 ? 2 : 0);

// linha d'água: distância até a reserva, em proporção da própria reserva (−1 = bem acima, 1 = bem abaixo)
$dif   = $conta - $reserva;
$razao = $reserva > 0.004 ? max(-1.0, min(1.0, -$dif / $reserva)) : ($dif >= 0 ? -1.0 : 1.0);
$abaixo = $dif < -0.004;
$vermelho = $conta < -0.004;
if ($vermelho) {
    $frase = 'No vermelho. Faltam ' . num(abs($conta)) . ' para zerar e ' . num($reserva, 0) . ' para a reserva.';
} elseif ($abaixo) {
    $frase = num(abs($dif)) . ' abaixo da reserva de ' . num($reserva, 0) . '.';
} else {
    $frase = num($dif) . ' acima da reserva de ' . num($reserva, 0) . '.';
}

$prog = null;
if ($mesRef === $mesCal) {
    $dia  = (int) substr($hoje, 8, 2);
    $dias = diasNoMes($mesRef);
    $prog = ['dia' => $dia, 'dias' => $dias, 'pct' => (int) round($dia / $dias * 100)];
}

// recibo: barras de entrou × saiu na mesma escala
$escala   = max($p['entradas'], $p['saidas'], 0.01);
$wEnt     = round($p['entradas'] / $escala * 100, 1);
$wSai     = round($p['saidas'] / $escala * 100, 1);
$balanco  = (float) $p['balanco'];
$topMax   = $p['top'] !== [] ? max(array_column($p['top'], 'valor')) : 0.0;
?>
<div class="dash">

    <header class="dash-head">
        <h1 class="dash-titulo"><?= e(mb_convert_case($mesNome, MB_CASE_TITLE)) ?> <span class="dash-ano num"><?= e($ano) ?></span></h1>
        <p class="dash-sub">
            <span class="estado estado-<?= e($estadoRef) ?>"><?= e(estadoLabel($estadoRef)) ?></span>
            <?php if ($prog !== null): ?>
                <span class="dash-prog num">dia <?= $prog['dia'] ?> de <?= $prog['dias'] ?>
                    <span class="dash-prog-bar" aria-hidden="true"><i style="--p:<?= $prog['pct'] ?>%"></i></span>
                </span>
            <?php endif; ?>
        </p>
    </header>

    <?php if ($alertas !== []): ?>
        <div class="dash-alertas">
            <?php foreach ($alertas as $a): ?>
                <a class="dash-alerta" href="/mes/fechar?mes=<?= e(rawurlencode($a['mes'])) ?>">
                    <span><?= e(monthLabel($a['mes'])) ?> terminou e ainda está aberto</span>
                    <span class="dash-alerta-acao">Fechar <?= e(monthLabel($a['mes'])) ?> <?= svgIcon('chevron', 16) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- linha d'água: o saldo de hoje contra a reserva -->
    <section class="agua<?= $abaixo ? ' is-abaixo' : '' ?>" style="--y:<?= e(number_format($razao, 3, '.', '')) ?>" aria-labelledby="agua-t">
        <h2 class="sr-only" id="agua-t">Em conta hoje</h2>
        <p class="sr-only">Em conta hoje: <?= e(num($conta)) ?>. <?= e($frase) ?></p>
        <div class="agua-campo agua-sup" aria-hidden="true"></div>
        <div class="agua-campo agua-inf" aria-hidden="true"></div>
        <span class="agua-rotulo" aria-hidden="true">em conta hoje</span>
        <span class="agua-reserva num" aria-hidden="true">reserva <?= e(num($reserva, 0)) ?></span>
        <?php foreach (['sup', 'inf'] as $camada): ?>
            <div class="agua-camada agua-c-<?= $camada ?>" aria-hidden="true">
                <p class="agua-valor num"><?= e(num($conta)) ?></p>
            </div>
        <?php endforeach; ?>
        <p class="agua-frase num" aria-hidden="true"><?= e($frase) ?></p>
    </section>

    <div class="dash-grade">
        <div class="dash-col">
            <!-- o mês como recibo -->
            <section class="card dash-recibo" aria-labelledby="dash-rec-t">
                <div class="card-head">
                    <h2 class="titulo" id="dash-rec-t"><?= e(mb_convert_case($mesNome, MB_CASE_TITLE)) ?> <?= $previsto ? 'previsto' : 'até hoje' ?></h2>
                </div>
                <dl class="recibo num">
                    <div class="r-linha r-total"><dt>Entrou</dt><dd class="pos"><?= e(num($p['entradas'])) ?></dd><dd class="r-barra" aria-hidden="true"><i style="--w:<?= $wEnt ?>%"></i></dd></div>
                    <div class="r-linha"><dt>Soldo</dt><dd><?= e(num($p['sal'])) ?></dd></div>
                    <div class="r-linha"><dt>Outras entradas</dt><dd><?= e(num($p['outras'])) ?></dd></div>
                    <?php if (count($p['outrasItens']) > 1): ?>
                        <?php foreach ($p['outrasItens'] as $it): ?>
                            <div class="r-linha r-sub"><dt><?= e($it['nome']) ?></dt><dd><?= e(num($it['valor'])) ?></dd></div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <div class="r-linha r-total r-sep"><dt>Saiu</dt><dd><?= e(num($p['saidas'])) ?></dd><dd class="r-barra<?= $p['saidas'] > $p['entradas'] + 0.004 ? ' is-passou' : '' ?>" aria-hidden="true"><i style="--w:<?= $wSai ?>%"></i></dd></div>
                    <div class="r-linha"><dt>Essenciais</dt><dd><?= e(num($p['ess'])) ?></dd></div>
                    <div class="r-linha"><dt>Não essenciais</dt><dd><?= e(num($p['naoess'])) ?></dd></div>

                    <div class="r-fim<?= $balanco < -0.004 ? ' is-neg' : '' ?>"><dt><?= $balanco < -0.004 ? 'Déficit' : 'Sobra' ?></dt><dd><?= e(num(abs($balanco))) ?></dd></div>
                </dl>
                <?php if ($p['entradas'] > 0.004): ?>
                    <p class="dash-nota">As saídas foram <?= $share($p['saidas'], $p['entradas']) ?>% das entradas.</p>
                <?php endif; ?>
            </section>

            <!-- conferir com o banco: o único jeito de validar o saldo calculado -->
            <details class="card dobra dash-conf" id="conferir"<?= $conferirAberto ? ' open' : '' ?>>
                <summary>
                    <span class="titulo">Conferir com o banco</span>
                    <span class="dash-conf-ult num">
                        <?php if ($conf !== null): ?>
                            <?= $conf['bate'] ? 'bate' : 'diferença ' . e(moneySigned($conf['dif'])) ?>, em <?= e(diaMes($conf['data'])) ?>
                        <?php else: ?>
                            nunca conferido
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
                        <label class="chip-r"><input type="radio" name="quando" value="hoje"<?= ($conferirErro['quando'] ?? 'hoje') !== 'outro' ? ' checked' : '' ?>><span>hoje</span></label>
                        <label class="chip-r"><input type="radio" name="quando" value="outro"<?= ($conferirErro['quando'] ?? '') === 'outro' ? ' checked' : '' ?>><span>outro dia</span></label>
                        <input type="date" name="data" class="quando-data" value="<?= e((string) ($conferirErro['data'] ?? $hoje)) ?>" max="<?= e($hoje) ?>" aria-label="data do saldo">
                    </fieldset>
                    <?php if ($conf !== null): ?>
                        <p class="dash-conf-det num">Última conferência: <?= e(num($conf['saldo'])) ?> em <?= e(diaMes($conf['data'])) ?>.</p>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary dash-conf-btn">Conferir</button>
                </form>
            </details>
        </div>

        <div class="dash-col">
            <!-- maiores gastos -->
            <section class="card" aria-labelledby="dash-top-t">
                <div class="card-head">
                    <h2 class="titulo" id="dash-top-t">Maiores gastos</h2>
                    <a class="card-acao" href="/lancamentos?mes=<?= e($mesRef) ?>">ver extrato</a>
                </div>
                <?php if ($p['top'] === []): ?>
                    <p class="dash-vazio">Nada lançado em <?= e($mesNome) ?> ainda.</p>
                <?php else: ?>
                    <ol class="dash-top">
                        <?php foreach ($p['top'] as $t): ?>
                            <li>
                                <a class="dash-top-item" href="/lancamentos?mes=<?= e($mesRef) ?>&amp;cat=<?= (int) $t['id'] ?>">
                                    <span class="dash-top-nome"><?= e($t['nome']) ?></span>
                                    <span class="dash-top-pct num"><?= $share($t['valor'], $p['saidas']) ?>%</span>
                                    <span class="dash-top-val num"><?= e(num($t['valor'])) ?></span>
                                    <span class="dash-top-bar<?= $t['essencial'] ? '' : ' is-naoess' ?>" aria-hidden="true"><i style="--w:<?= $topMax > 0 ? round($t['valor'] / $topMax * 100, 1) : 0 ?>%"></i></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                    <p class="dash-legenda"><i class="dash-chave"></i>essencial <i class="dash-chave is-naoess"></i>não essencial</p>
                <?php endif; ?>
            </section>

            <!-- últimos lançamentos -->
            <section class="card" aria-labelledby="dash-ult-t">
                <div class="card-head">
                    <h2 class="titulo" id="dash-ult-t">Últimos lançamentos</h2>
                    <a class="card-acao" href="/lancar?volta=%2F" data-lancar>lançar</a>
                </div>
                <?php if ($ultimos === []): ?>
                    <p class="dash-vazio">Nada lançado em <?= e($mesNome) ?> ainda.</p>
                <?php else: ?>
                    <ul class="dash-ult">
                        <?php foreach ($ultimos as $r):
                            $mov = (int) $r['operacional'] !== 1;
                            $ent = !$mov && (int) $r['sinal'] > 0;
                            $v   = (float) $r['valor'];
                            $txt = $mov ? '⇄ ' . num($v) : ($ent ? '+' . num($v) : num(-$v));
                            $cls = $mov ? 'dash-ult-mov' : ($ent ? 'pos' : '');
                            $cat = ['nome' => $r['categoria_nome'], 'apelido' => $r['apelido']];
                            ?>
                            <li>
                                <a class="dash-ult-linha" href="/lancamentos/<?= (int) $r['id'] ?>/editar?volta=%2F">
                                    <span class="dash-ult-desc"><?= e((string) $r['descricao']) ?></span>
                                    <span class="dash-ult-val num <?= $cls ?>"><?= e($txt) ?></span>
                                    <span class="dash-ult-meta"><i class="dot-g <?= e(corClasse($r)) ?>"></i><?= e(nomeCurto($cat)) ?>, <?= e($semana[(int) date('w', strtotime($r['data']))] . ' ' . diaMes($r['data'])) ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </div>
    </div>
</div>
