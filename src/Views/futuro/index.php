<?php
/**
 * Anual — os próximos meses pelo plano do motor, em três blocos:
 *  · Resumo: como o plano termina e os marcos em ordem (meses abaixo da reserva ou no vermelho,
 *    volta à reserva, sobra adiantando o empréstimo, quitação), cada um levando ao seu mês.
 *  · Gráfico da conta no fim de cada mês: linha reta com um ponto por mês (● fechado, ◐ aberto,
 *    ○ previsto), sólida no que já fechou e tracejada na previsão; faixa âmbar abaixo da reserva,
 *    vermelha abaixo de zero; "hoje" marcado e poucos números anotados. O título diz a conclusão.
 *    No celular só ficam os pontos de hoje, do mês mais apertado e do fim (o casal achou os 16 ruins).
 *    A dívida vai junto, em cinza: enquanto a conta fica parada na reserva, é ela que cai.
 *  · Mês a mês: tabela por ano com o total do ano. No celular só Balanço e Conta no fim (o resto
 *    ao abrir a linha); no computador largo, "o que mudou" em relação ao mês anterior na linha. A
 *    linha aberta mostra a conta do mês: começou com + entradas − gastos − adiantado = conta no fim.
 * No computador (≥ 1280 px) o resumo fica numa coluna à direita, parado enquanto a tabela rola.
 * "E se" (FuturoController::simulacao): com a simulação ativa, tudo acima mostra o simulado; um aviso
 *    diz o que foi simulado, o cartão "E se…?" compara com o plano e o gráfico mostra o plano apagado.
 * Variáveis: $hoje, $ref, $ultimo, $reserva, $meta, $linhas, $quita, $bate, $marcos, $ganho (AnualResumo::ganho),
 *            $se, $comparar (null sem simulação), $temDivida.
 */
$n    = count($linhas);
$vals = array_map(static fn($l) => (float) $l['fica'], $linhas);
$porMes = [];
$iRef = 0;
$iReal = -1;
foreach ($linhas as $i => $l) {
    $porMes[$l['mes']] = $l;
    if ($l['mes'] === $ref) {
        $iRef = $i;
    }
    if ($l['estado'] === 'real') {
        $iReal = $i;
    }
}
$fim    = $marcos['fim'] ?? ['mes' => $ultimo, 'fica' => $vals[$n - 1], 'desde' => 0.0];
$valor  = static fn(float $v): string => ($v < -0.004 ? MENOS : '') . num(abs($v), 0);
$valor2 = static fn(float $v): string => ($v < -0.004 ? MENOS : '') . num(abs($v));
$sinal  = static fn(float $v): string => ($v > 0.004 ? '+' : ($v < -0.004 ? MENOS : '')) . num(abs($v), 0);
$faixa  = static function (string $ini, string $fimM): string {
    if ($ini === $fimM) {
        return monthLabel($ini);
    }
    return substr($ini, 0, 4) === substr($fimM, 0, 4) ? mesCurto($ini) . '–' . monthLabel($fimM) : monthLabel($ini) . '–' . monthLabel($fimM);
};
$seguinte = static fn(string $m): string => date('Y-m-01', strtotime($m . ' +1 month'));
$sim = !empty($se['ativo']);
$din = static fn(float $v): string => num($v, abs($v - round($v)) < 0.005 ? 0 : 2);   // 500 ou 1.250,50

// ---- resumo: trechos seguidos abaixo da reserva (âmbar e vermelho juntos), volta, adiantamento, quitação
$trechos = [];
foreach ($marcos['faixas'] as $fx) {
    $k = count($trechos) - 1;
    if ($k >= 0 && $seguinte($trechos[$k]['fim']) === $fx['ini']) {
        $trechos[$k]['fim'] = $fx['fim'];
        $trechos[$k]['neg'] = $trechos[$k]['neg'] || $fx['tipo'] === 'neg';
    } else {
        $trechos[] = ['ini' => $fx['ini'], 'fim' => $fx['fim'], 'neg' => $fx['tipo'] === 'neg'];
    }
}
$itens = [];
$negMeses = [];
foreach ($trechos as $t) {
    $mMin = $t['ini'];
    for ($m = $t['ini']; $m <= $t['fim']; $m = $seguinte($m)) {
        if (isset($porMes[$m]) && $porMes[$m]['fica'] < $porMes[$mMin]['fica']) {
            $mMin = $m;
        }
        if (isset($porMes[$m]) && $porMes[$m]['fica'] < -0.004) {
            $negMeses[] = $m;
        }
    }
    $vMin = '<b class="' . ($porMes[$mMin]['fica'] < -0.004 ? 'neg' : 'warn') . '">' . e($valor((float) $porMes[$mMin]['fica'])) . '</b>';
    if ($t['ini'] === $t['fim']) {
        $txt = $t['neg'] ? t('No vermelho: a conta fica em {valor}', ['valor' => $vMin]) : t('Abaixo da reserva: a conta fica em {valor}', ['valor' => $vMin]);
    } elseif ($t['neg']) {
        $txt = t('Abaixo da reserva; no vermelho em {mes}, com {valor}', ['mes' => e(monthLabel($mMin)), 'valor' => $vMin]);
    } else {
        $txt = t('Abaixo da reserva; o menor saldo é {valor}, em {mes}', ['valor' => $vMin, 'mes' => e(monthLabel($mMin))]);
    }
    $itens[] = ['ord' => $t['ini'] . '1', 'quando' => $faixa($t['ini'], $t['fim']), 'tipo' => $t['neg'] ? 'neg' : 'warn', 'html' => $txt, 'mes' => $mMin];
}
if ($marcos['volta'] !== null) {
    $itens[] = ['ord' => $marcos['volta'] . '2', 'quando' => monthLabel($marcos['volta']), 'tipo' => 'pos',
                'html' => t('A conta volta à reserva de {valor}', ['valor' => '<b>' . e(num($reserva, 0)) . '</b>']), 'mes' => $marcos['volta']];
}
$adi = array_values(array_filter($linhas, static fn($l) => $l['mes'] >= $ref && $l['estado'] !== 'real' && $l['amort'] > 0.004));
if ($adi !== []) {
    $soma = array_sum(array_column($adi, 'amort'));
    $itens[] = ['ord' => $adi[0]['mes'] . '3', 'quando' => $faixa($adi[0]['mes'], $adi[count($adi) - 1]['mes']), 'tipo' => 'info',
                'html' => t('A sobra do mês adianta {valor} do empréstimo', ['valor' => '<b>' . e(num($soma, 0)) . '</b>']), 'mes' => $adi[0]['mes']];
}
if ($quita !== null && $quita >= $ref) {
    if (!empty($ganho)) {
        // o que adiantar rende: o "só as parcelas" iria até quitaSem, com mais juros
        $antes = $ganho['meses'] >= 24 ? t('{n} anos', ['n' => intdiv($ganho['meses'], 12)]) : tn($ganho['meses'], '1 mês', '{n} meses');
        $html = t('Empréstimo quitado, {antes} antes do que só com as parcelas ({mes}). Adiantar evita cerca de {juros} de juros', ['antes' => e($antes), 'mes' => e(monthLabel($ganho['quitaSem'])), 'juros' => '<b class="pos">' . e(num(round($ganho['juros'], -2), 0)) . '</b>']);
    } else {
        $html = t('Empréstimo quitado (hoje faltam {valor})', ['valor' => '<b>' . e(num((float) $linhas[$iRef]['divida'], 0)) . '</b>']);
    }
    $itens[] = ['ord' => $quita . '4', 'quando' => monthLabel($quita), 'tipo' => 'pos', 'html' => $html, 'mes' => $quita];
}
usort($itens, static fn($a, $b) => strcmp($a['ord'], $b['ord']));

// a conclusão, no título do gráfico
$listaMeses = static function (array $ms) use ($faixa, $seguinte): string {
    $partes = [];
    foreach ($ms as $m) {
        $k = count($partes) - 1;
        if ($k >= 0 && $seguinte($partes[$k][1]) === $m) {
            $partes[$k][1] = $m;
        } else {
            $partes[] = [$m, $m];
        }
    }
    $txt = array_map(static fn($p) => $faixa($p[0], $p[1]), $partes);
    return count($txt) > 1 ? t('{lista} e {ultimo}', ['lista' => implode(', ', array_slice($txt, 0, -1)), 'ultimo' => end($txt)]) : (string) ($txt[0] ?? '');
};
$nAbaixo = 0;
foreach ($linhas as $l) {
    if ($l['mes'] >= $ref && $l['fica'] < $reserva - 0.004) {
        $nAbaixo++;
    }
}
$volta = $marcos['volta'] !== null ? monthLabel($marcos['volta']) : null;
if ($negMeses !== []) {
    $titulo = $volta !== null
        ? t('A conta fica no vermelho em {meses} e volta à reserva em {volta}.', ['meses' => $listaMeses($negMeses), 'volta' => $volta])
        : t('A conta fica no vermelho em {meses}.', ['meses' => $listaMeses($negMeses)]);
} elseif ($nAbaixo > 0) {
    $titulo = $volta !== null
        ? tn($nAbaixo, 'A conta fica abaixo da reserva em 1 mês e volta à reserva em {volta}.', 'A conta fica abaixo da reserva em {n} meses e volta à reserva em {volta}.', ['volta' => $volta])
        : tn($nAbaixo, 'A conta fica abaixo da reserva em 1 mês.', 'A conta fica abaixo da reserva em {n} meses.');
} else {
    $titulo = t('A conta não fica abaixo da reserva em nenhum mês.');
}
if ($bate !== null) {
    $metaTxt = t('Bate a meta de {meta} em {mes}.', ['meta' => num($meta, 0), 'mes' => monthLabel($bate)]);
} elseif ($meta > 0.004 && $metaMes !== null && isset($porMes[$metaMes])) {
    $metaTxt = t('Meta de {meta} em {mes}: faltam {falta}.', ['meta' => num($meta, 0), 'mes' => monthLabel($metaMes), 'falta' => num(max(0.0, $meta - $porMes[$metaMes]['fica']), 0)]);
} elseif ($meta > 0.004 && $fim['fica'] < $meta) {
    $metaTxt = t('Meta de {meta}: faltam {falta}.', ['meta' => num($meta, 0), 'falta' => num($meta - $fim['fica'], 0)]);
} else {
    $metaTxt = '';
}

// ---- gráfico: escala, grade "redonda" (2 a 4 linhas) e caminhos
$divs = array_map(static fn($l) => (float) $l['divida'], $linhas);
$valsBase = array_map(static fn($l) => (float) $l['base_fica'], $linhas);
$comDivida = max($divs) > 0.004;
$comMeta = $meta > 0.004 && $meta <= max($vals) * 1.25;
$topo = max(max($vals), $comDivida ? max($divs) : 0.0, $sim ? max($valsBase) : 0.0, $reserva, $comMeta ? $meta : 0.0, 1.0);
$piso = min(0.0, min($vals), $sim ? min($valsBase) : 0.0);
$amp  = max(1e-9, $topo - $piso);
$yMax = $topo + $amp * 0.16;
$yMin = $piso - $amp * ($piso < -0.004 ? 0.16 : 0.04);
$VW = 1000; $VH = 300;
$x  = static fn(int $i): float => ($i + 0.5) / max(1, $n) * $VW;
$y  = static fn(float $v): float => ($yMax - $v) / ($yMax - $yMin) * $VH;
$f  = static fn(float $v): string => number_format($v, 1, '.', '');
$pc = static fn(float $u, int $total): string => number_format($u / $total * 100, 3, '.', '') . '%';
$passo = 100000.0;
foreach ([500, 1000, 2000, 2500, 5000, 10000, 20000, 25000, 50000] as $p) {
    if ($amp / $p <= 4) {
        $passo = (float) $p;
        break;
    }
}
$grade = [];
for ($g = ceil($yMin / $passo) * $passo; $g <= $yMax; $g += $passo) {
    if ($comMeta && abs($g - $meta) < $amp * 0.07) {
        continue;   // a linha da meta já marca esse valor (e o rótulo dela vai à esquerda)
    }
    $grade[] = (float) $g;
}
$pts = array_map(static fn(int $i): array => [$x($i), $y($vals[$i])], array_keys($vals));
$caminho = static function (int $de, int $ate) use ($pts, $f): string {
    $s = '';
    for ($i = $de; $i <= $ate; $i++) {
        $s .= ($i === $de ? 'M' : ' L') . $f($pts[$i][0]) . ',' . $f($pts[$i][1]);
    }
    return $s;
};
$solida    = $iReal >= 1 ? $caminho(0, $iReal) : '';
$tracejada = $iReal < $n - 1 ? $caminho(max(0, $iReal), $n - 1) : '';
// a dívida: até o mês em que zera (o ponto no zero entra, o resto some)
$iZera = $n - 1;
foreach ($divs as $i => $d) {
    if ($d <= 0.004) {
        $iZera = $i;
        break;
    }
}
$ptsD = array_map(static fn(int $i): array => [$x($i), $y($divs[$i])], array_keys($divs));
$caminhoD = static function (int $de, int $ate) use ($ptsD, $f): string {
    $s = '';
    for ($i = $de; $i <= $ate; $i++) {
        $s .= ($i === $de ? 'M' : ' L') . $f($ptsD[$i][0]) . ',' . $f($ptsD[$i][1]);
    }
    return $s;
};
// rótulo "Dívida" na descida (o 1º mês com até 2/3 da dívida de hoje), à direita do ponto; sem descida, no começo
$iDivRot = null;
for ($i = $iRef; $i <= $iZera; $i++) {
    if ($divs[$i] <= $divs[$iRef] * 0.66) {
        $iDivRot = $i;
        break;
    }
}
$baseCaminho = '';
if ($sim) {
    foreach ($valsBase as $i => $v) {
        $baseCaminho .= ($i === 0 ? 'M' : ' L') . $f($x($i)) . ',' . $f($y($v));
    }
}
$divSolida    = $comDivida && $iReal >= 1 ? $caminhoD(0, min($iReal, $iZera)) : '';
$divTracejada = $comDivida && $iReal < $iZera ? $caminhoD(max(0, $iReal), $iZera) : '';
$yRes  = $y($reserva);
$yZero = $y(0.0);
// números anotados: o mês mais apertado (se abaixo da reserva), a quitação e o fim
$apertado = $marcos['apertado'];
$iAp = $apertado !== null && $apertado['fica'] < $reserva - 0.004 ? array_search($apertado['mes'], array_column($linhas, 'mes'), true) : false;
// a quitação vai no eixo, embaixo do mês (em cima do ponto a linha passaria por cima do texto)
$iQuita = $quita !== null && $quita >= $ref && substr($quita, 5, 2) !== '01' ? array_search($quita, array_column($linhas, 'mes'), true) : false;
// eixo apertado (celular): meses alternados, na paridade do 1º janeiro (os janeiros ficam visíveis)
$par = 0;
foreach ($linhas as $i => $l) {
    if (substr($l['mes'], 5, 2) === '01') {
        $par = $i % 2;
        break;
    }
}
$marca = static fn(string $estado): string => '<i class="fu-mk is-' . ($estado === 'real' ? 'real' : ($estado === 'aberto' ? 'aberto' : 'prev')) . '" aria-hidden="true"></i>';

// ---- tabela: "o que mudou" de cada mês (fluxos do empréstimo e da poupança antes das categorias)
$chips = static function (array $l) use ($quita): array {
    $out = [];
    if (abs($l['simulado']) > 0.004) {
        $out[] = ['tipo' => 'sim', 'seta' => '', 'txt' => t('E se: {valor}', ['valor' => ($l['simulado'] > 0 ? '+' : MENOS) . num(abs($l['simulado']), 0)]), 'sr' => ''];
    }
    if ($quita !== null && $l['mes'] === $quita) {
        $out[] = ['tipo' => 'ok', 'seta' => '', 'txt' => t('Empréstimo quitado'), 'sr' => ''];
    }
    if ($l['cred'] > 0.004) {
        $out[] = ['tipo' => 'info', 'seta' => '', 'txt' => t('Crédito do empréstimo {valor}', ['valor' => num($l['cred'], 0)]), 'sr' => ''];
    }
    if ($l['amort'] > 0.004 && ($quita === null || $l['mes'] <= $quita)) {
        $out[] = ['tipo' => 'info', 'seta' => '', 'txt' => t('Adianta {valor} do empréstimo', ['valor' => num($l['amort'], 0)]), 'sr' => ''];
    }
    if (abs($l['poup']) > 0.004) {
        $out[] = ['tipo' => 'info', 'seta' => '', 'txt' => $l['poup'] > 0 ? t('Tira {valor} da poupança', ['valor' => num(abs($l['poup']), 0)]) : t('Guarda {valor} na poupança', ['valor' => num(abs($l['poup']), 0)]), 'sr' => ''];
    }
    if (abs($l['inv']) > 0.004) {
        $out[] = ['tipo' => 'info', 'seta' => '', 'txt' => $l['inv'] > 0 ? t('Resgata {valor} de investimentos', ['valor' => num(abs($l['inv']), 0)]) : t('Aplica {valor} em investimentos', ['valor' => num(abs($l['inv']), 0)]), 'sr' => ''];
    }
    foreach ($l['mudou'] as $m) {
        $sobe = $m['valor'] > $m['antes'];
        $d = num(abs($m['valor'] - $m['antes']), 0);
        $out[] = ['tipo' => $m['efeito'] > 0 ? 'pos' : 'neg', 'seta' => $sobe ? 'arrow-up' : 'arrow-down', 'txt' => $m['nome'] . ' ' . $d,
                  'sr' => $sobe ? t('{nome} sobe {valor} em relação ao mês anterior', ['valor' => $d, 'nome' => $m['nome']]) : t('{nome} cai {valor} em relação ao mês anterior', ['valor' => $d, 'nome' => $m['nome']])];
    }
    return array_slice($out, 0, 4);
};
$chipsHtml = static function (array $lista): string {
    $h = '';
    foreach ($lista as $c) {
        $h .= '<span class="fu-chip is-' . e($c['tipo']) . '">' . ($c['seta'] !== '' ? svgIcon($c['seta'], 12) : '')
            . ($c['sr'] !== '' ? '<span aria-hidden="true">' . e($c['txt']) . '</span><span class="sr-only">' . e($c['sr']) . '</span>' : e($c['txt']))
            . '</span>';
    }
    return $h;
};
$anos = [];
foreach ($linhas as $l) {
    $anos[substr($l['mes'], 0, 4)][] = $l;
}
$alertaIc = static fn(string $a): string => $a !== '' ? svgIcon('alert', 13, 'fu-al') : '';
?>
<?php
// o que está sendo simulado, em palavras
$oQue = [];
if ($sim) {
    if ($se['mensal'] > 0) {
        $oQue[] = $se['mensalTipo'] === 'economizar'
            ? t('economizar {valor} por mês a partir de {mes}', ['valor' => $din($se['mensal']), 'mes' => monthLabel($se['mensalDe'])])
            : t('gastar {valor} a mais por mês a partir de {mes}', ['valor' => $din($se['mensal']), 'mes' => monthLabel($se['mensalDe'])]);
    }
    if ($se['uma'] > 0) {
        $oQue[] = $se['umaTipo'] === 'entrada'
            ? t('uma entrada de {valor} em {mes}', ['valor' => $din($se['uma']), 'mes' => monthLabel($se['umaMes'])])
            : t('um gasto de {valor} em {mes}', ['valor' => $din($se['uma']), 'mes' => monthLabel($se['umaMes'])]);
    }
    if ($temDivida && !$se['adiantar']) {
        $oQue[] = t('sem adiantar o empréstimo');
    } elseif ($temDivida && $se['adiantar'] && $se['extra'] === []) {
        $oQue[] = t('adiantando o empréstimo');
    }
}
$juntar = static fn(array $p): string => count($p) > 1 ? t('{lista} e {ultimo}', ['lista' => implode(', ', array_slice($p, 0, -1)), 'ultimo' => end($p)]) : (string) ($p[0] ?? '');
$opcoesMes = static function (string $sel) use ($se): string {
    $h = '';
    foreach ($se['meses'] as $m) {
        $h .= '<option value="' . e($m) . '"' . ($m === $sel ? ' selected' : '') . '>' . e(monthLabel($m)) . '</option>';
    }
    return $h;
};
?>
<div class="fu<?= $sim ? ' is-sim' : '' ?>">
    <h1 class="fu-titulo pg-titulo"><?= e(t('Simular')) ?></h1>
    <p class="fu-sub"><?= e(t('Veja como uma mudança mexe no plano. Nada é salvo.')) ?> <a class="btn-link" href="/relatorio"><?= e(t('Ver relatório')) ?></a></p>

    <?php if ($sim): ?>
        <p class="fu-sim" role="status">
            <span><?= t('{titulo} {oque}. Nada foi salvo.', ['titulo' => '<b>' . e(t('Simulação:')) . '</b>', 'oque' => e($juntar($oQue))]) ?></span>
            <a class="btn-link" href="/futuro"><?= e(t('Voltar ao plano')) ?></a>
        </p>
    <?php endif; ?>

    <!-- resumo: como termina e os marcos -->
    <section class="card fu-res" aria-labelledby="fu-res-t">
        <h2 class="fu-res-t" id="fu-res-t"><?= e(t('Fim de {mes}', ['mes' => monthLabel($fim['mes'])])) ?></h2>
        <p class="fu-res-v num"><b>R$ <?= e($valor((float) $fim['fica'])) ?></b><span class="<?= $fim['desde'] >= 0 ? 'pos' : 'neg' ?>"><?= e(t('{valor} desde {mes}', ['valor' => $sinal((float) $fim['desde']), 'mes' => monthLabel($ref)])) ?></span></p>
        <?php if ($metaTxt !== ''): ?><p class="fu-res-meta num"><?= e($metaTxt) ?></p><?php endif; ?>
        <?php if ($itens !== []): ?>
            <ol class="fu-marcos">
                <?php foreach ($itens as $it): ?>
                    <li><a class="fu-marco is-<?= e($it['tipo']) ?>" href="/mes?mes=<?= e($it['mes']) ?>">
                        <span class="fu-marco-q num"><?= e($it['quando']) ?></span>
                        <span class="fu-marco-t num"><?= $it['html'] ?></span>
                    </a></li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
        <?php if ($temDivida): ?><a class="fu-res-emp" href="/emprestimo"><?= e(t('Simular um adiantamento do empréstimo')) ?></a><?php endif; ?>
    </section>

    <!-- e se: simulação pela URL, nada é gravado -->
    <details class="card fu-ese" id="e-se"<?= $sim || $se['erros'] !== [] ? ' open' : '' ?>>
        <summary class="fu-ese-cab">
            <span class="fu-ese-t"><?= e(t('E se…?')) ?></span>
            <span class="fu-ese-dica"><?= e(t('Simule uma economia por mês, um gasto ou uma entrada. Nada é salvo.')) ?></span>
        </summary>
        <?php if ($se['erros'] !== []): ?><p class="fu-ese-erro" role="alert"><?= e(implode(' ', $se['erros'])) ?></p><?php endif; ?>
        <?php if ($comparar !== null):
            [$cp, $cs] = [$comparar['plano'], $comparar['se']];
            $dFim = $cs['fim'] - $cp['fim'];
            $apert = static fn(?array $a): string => $a === null ? '—' : monthLabel($a['mes']) . ': ' . (($a['fica'] < -0.004 ? MENOS : '') . num(abs($a['fica']), 0));
            $quitaTxt = static fn(?string $q): string => $q === null ? t('não dá para dizer') : monthLabel($q);
            ?>
            <?php /* papéis explícitos: no celular cada linha vira um bloco (display grid) e alguns leitores de tela perdem a tabela */ ?>
            <table class="fu-cmp num" role="table">
                <caption class="sr-only"><?= e(t('Plano comparado com a simulação')) ?></caption>
                <thead role="rowgroup"><tr role="row"><th scope="col" role="columnheader"><span class="sr-only"><?= e(t('O quê')) ?></span></th><th scope="col" role="columnheader"><?= e(t('Plano')) ?></th><th scope="col" role="columnheader"><?= e(t('E se')) ?></th></tr></thead>
                <tbody role="rowgroup">
                    <tr role="row"><th scope="row" role="rowheader"><?= e(t('Conta no fim de {mes}', ['mes' => monthLabel($ultimo)])) ?></th><td role="cell" data-rot="<?= e(t('Plano')) ?>"><?= e($valor($cp['fim'])) ?></td><td role="cell" data-rot="<?= e(t('E se')) ?>"><b><?= e($valor($cs['fim'])) ?></b><?php if (abs($dFim) >= 0.5): ?> <small class="<?= $dFim > 0 ? 'pos' : 'neg' ?>"><?= e($sinal($dFim)) ?></small><?php endif; ?></td></tr>
                    <?php if ($temDivida): ?>
                        <tr role="row"><th scope="row" role="rowheader"><?= e(t('Dívida no fim de {mes}', ['mes' => monthLabel($ultimo)])) ?></th><td role="cell" data-rot="<?= e(t('Plano')) ?>"><?= $cp['divida'] > 0.004 ? e(num($cp['divida'], 0)) : e(t('quitada')) ?></td><td role="cell" data-rot="<?= e(t('E se')) ?>"><b><?= $cs['divida'] > 0.004 ? e(num($cs['divida'], 0)) : e(t('quitada')) ?></b></td></tr>
                    <?php endif; ?>
                    <tr role="row"><th scope="row" role="rowheader"><?= e(t('Mês mais apertado')) ?></th><td role="cell" data-rot="<?= e(t('Plano')) ?>"><?= e($apert($cp['apertado'])) ?></td><td role="cell" data-rot="<?= e(t('E se')) ?>"><b><?= e($apert($cs['apertado'])) ?></b></td></tr>
                    <tr role="row"><th scope="row" role="rowheader"><?= e(t('Meses abaixo da reserva')) ?></th><td role="cell" data-rot="<?= e(t('Plano')) ?>"><?= (int) $cp['abaixo'] ?></td><td role="cell" data-rot="<?= e(t('E se')) ?>"><b><?= (int) $cs['abaixo'] ?></b></td></tr>
                    <?php if ($temDivida): ?>
                        <tr role="row"><th scope="row" role="rowheader"><?= e(t('Empréstimo acaba')) ?></th><td role="cell" data-rot="<?= e(t('Plano')) ?>"><?= e($quitaTxt($cp['quita'])) ?></td><td role="cell" data-rot="<?= e(t('E se')) ?>"><b><?= e($quitaTxt($cs['quita'])) ?></b></td></tr>
                        <tr role="row"><th scope="row" role="rowheader"><?= e(t('Juros até quitar')) ?></th><td role="cell" data-rot="<?= e(t('Plano')) ?>"><?= $cp['juros'] === null ? '—' : e(num($cp['juros'], 0)) ?></td><td role="cell" data-rot="<?= e(t('E se')) ?>"><b><?= $cs['juros'] === null ? '—' : e(num($cs['juros'], 0)) ?></b></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <form class="fu-ese-form" method="get" action="/futuro#e-se">
            <input type="hidden" name="se" value="1">
            <div class="fu-ese-l" role="group" aria-labelledby="fu-ese-mensal">
                <span class="fu-ese-rot" id="fu-ese-mensal"><?= e(t('Todo mês')) ?></span>
                <select name="mensal_tipo" aria-label="<?= e(t('Economizar ou gastar a mais')) ?>"><option value="economizar"<?= $se['mensalTipo'] === 'economizar' ? ' selected' : '' ?>><?= e(t('Economizar')) ?></option><option value="gastar"<?= $se['mensalTipo'] === 'gastar' ? ' selected' : '' ?>><?= e(t('Gastar a mais')) ?></option></select>
                <input name="mensal" inputmode="decimal" autocomplete="off" placeholder="0,00" aria-label="<?= e(t('Valor todo mês')) ?>" value="<?= $se['mensal'] > 0 ? e(numInput($se['mensal'])) : '' ?>">
                <label class="fu-ese-mes"><?= t('a partir de {campo}', ['campo' => '<select name="mensal_de">' . $opcoesMes($se['mensalDe']) . '</select>']) ?></label>
            </div>
            <div class="fu-ese-l" role="group" aria-labelledby="fu-ese-uma">
                <span class="fu-ese-rot" id="fu-ese-uma"><?= e(t('Uma vez')) ?></span>
                <select name="uma_tipo" aria-label="<?= e(t('Gasto ou entrada')) ?>"><option value="gasto"<?= $se['umaTipo'] === 'gasto' ? ' selected' : '' ?>><?= e(t('Um gasto')) ?></option><option value="entrada"<?= $se['umaTipo'] === 'entrada' ? ' selected' : '' ?>><?= e(t('Uma entrada')) ?></option></select>
                <input name="uma" inputmode="decimal" autocomplete="off" placeholder="0,00" aria-label="<?= e(t('Valor uma vez')) ?>" value="<?= $se['uma'] > 0 ? e(numInput($se['uma'])) : '' ?>">
                <label class="fu-ese-mes"><?= t('em {campo}', ['campo' => '<select name="uma_mes">' . $opcoesMes($se['umaMes']) . '</select>']) ?></label>
            </div>
            <?php if ($temDivida): ?>
                <label class="fu-ese-adi"><input type="checkbox" name="adiantar" value="1"<?= $se['adiantar'] ? ' checked' : '' ?>> <?= e(t('Usar a sobra para adiantar o empréstimo')) ?></label>
            <?php elseif ($se['adiantar']): ?>
                <input type="hidden" name="adiantar" value="1">
            <?php endif; ?>
            <div class="fu-ese-acoes">
                <button type="submit" class="btn btn-primary fu-ese-btn"><?= e(t('Simular')) ?></button>
                <?php if ($sim): ?><a class="btn-link" href="/futuro"><?= e(t('Voltar ao plano')) ?></a><?php endif; ?>
            </div>
        </form>
    </details>

    <!-- conta no fim de cada mês -->
    <section class="card fu-graf" aria-labelledby="fu-graf-t">
        <h2 class="fu-graf-t" id="fu-graf-t"><?= e($titulo) ?></h2>
        <p class="fu-graf-sub"><?= $sim ? e(t('Simulação tracejada e o plano em cinza (linha cheia).')) . ' ' : '' ?><?= $comDivida ? e(t('Conta no fim de cada mês e a dívida do empréstimo, em R$')) : e(t('Conta no fim de cada mês, em R$')) ?></p>
        <figure class="fu-fig is-denso" role="img" aria-label="<?= e($titulo . ' ' . ($comDivida ? t('Conta no fim de cada mês e dívida do empréstimo, de {de} a {ate}. Os valores estão na tabela mês a mês.', ['de' => monthLabel($linhas[0]['mes']), 'ate' => monthLabel($ultimo)]) : t('Conta no fim de cada mês, de {de} a {ate}. Os valores estão na tabela mês a mês.', ['de' => monthLabel($linhas[0]['mes']), 'ate' => monthLabel($ultimo)]))) ?>">
            <div class="fu-plot" aria-hidden="true">
                <svg class="fu-svg" viewBox="0 0 <?= $VW ?> <?= $VH ?>" preserveAspectRatio="none" focusable="false">
                    <?php if ($reserva > 0.004 && $yRes < $VH): ?>
                        <rect class="fu-faixa-warn" x="0" width="<?= $VW ?>" y="<?= $f(max(0.0, $yRes)) ?>" height="<?= $f(max(0.0, min($VH, $yZero) - max(0.0, $yRes))) ?>"/>
                    <?php endif; ?>
                    <?php if ($piso < -0.004): ?>
                        <rect class="fu-faixa-neg" x="0" width="<?= $VW ?>" y="<?= $f($yZero) ?>" height="<?= $f($VH - $yZero) ?>"/>
                    <?php endif; ?>
                    <?php foreach ($grade as $g): ?>
                        <line class="<?= abs($g) < 0.004 ? 'fu-zero' : 'fu-grade' ?>" x1="0" x2="<?= $VW ?>" y1="<?= $f($y($g)) ?>" y2="<?= $f($y($g)) ?>"/>
                    <?php endforeach; ?>
                    <?php if ($reserva > 0.004): ?>
                        <line class="fu-reserva" x1="0" x2="<?= $VW ?>" y1="<?= $f($yRes) ?>" y2="<?= $f($yRes) ?>"/>
                    <?php endif; ?>
                    <?php if ($comMeta): ?>
                        <line class="fu-meta-l" x1="0" x2="<?= $VW ?>" y1="<?= $f($y($meta)) ?>" y2="<?= $f($y($meta)) ?>"/>
                    <?php endif; ?>
                    <line class="fu-hoje-l" x1="<?= $f($x($iRef)) ?>" x2="<?= $f($x($iRef)) ?>" y1="0" y2="<?= $VH ?>"/>
                    <?php if ($baseCaminho !== ''): ?><path class="fu-base-l" d="<?= $baseCaminho ?>"/><?php endif; ?>
                    <?php if ($divSolida !== ''): ?><path class="fu-div-l" d="<?= $divSolida ?>"/><?php endif; ?>
                    <?php if ($divTracejada !== ''): ?><path class="fu-div-l is-prev" d="<?= $divTracejada ?>"/><?php endif; ?>
                    <?php if ($solida !== ''): ?><path class="fu-linha" d="<?= $solida ?>"/><?php endif; ?>
                    <?php if ($tracejada !== ''): ?><path class="fu-linha is-prev" d="<?= $tracejada ?>"/><?php endif; ?>
                </svg>
                <?php foreach ($grade as $g): ?>
                    <span class="fu-gl num" style="top:<?= $pc($y($g), $VH) ?>"><?= e($valor($g)) ?></span>
                <?php endforeach; ?>
                <?php if ($reserva > 0.004): ?>
                    <span class="fu-ref fu-reserva-rot num" style="top:<?= $pc($yRes, $VH) ?>"><?= e(t('Reserva {valor}', ['valor' => num($reserva, 0)])) ?></span>
                <?php endif; ?>
                <?php if ($comMeta): ?>
                    <span class="fu-ref fu-meta-rot num" style="top:<?= $pc($y($meta), $VH) ?>"><?= e(t('Meta {valor}', ['valor' => num($meta, 0)])) ?></span>
                <?php endif; ?>
                <span class="fu-hoje" style="left:<?= $pc($x($iRef), $VW) ?>"><?= e(t('hoje')) ?></span>
                <?php if ($comDivida): ?>
                    <span class="fu-serie<?= $iDivRot === null ? ' is-inicio' : '' ?>" style="left:<?= $pc($x($iDivRot ?? 0), $VW) ?>;top:<?= $pc($y($divs[$iDivRot ?? 0]), $VH) ?>"><?= e(t('Dívida')) ?></span>
                <?php endif; ?>
                <?php foreach ($linhas as $i => $l): ?>
                    <i class="fu-pt is-<?= $l['estado'] === 'real' ? 'real' : ($l['estado'] === 'aberto' ? 'aberto' : 'prev') ?><?= $l['alerta'] !== '' ? ' is-' . e($l['alerta']) : '' ?><?= $i === $iRef || $i === $iAp || $i === $n - 1 ? ' is-chave' : '' ?>" style="left:<?= $pc($x($i), $VW) ?>;top:<?= $pc($y($vals[$i]), $VH) ?>"></i>
                <?php endforeach; ?>
                <?php if ($iAp !== false): ?>
                    <span class="fu-nota is-baixo <?= $vals[$iAp] < -0.004 ? 'neg' : 'warn' ?> num" style="left:<?= $pc($x($iAp), $VW) ?>;top:<?= $pc($y($vals[$iAp]), $VH) ?>"><?= e($valor($vals[$iAp])) ?></span>
                <?php endif; ?>
                <span class="fu-nota is-fim num" style="left:<?= $pc($x($n - 1), $VW) ?>;top:<?= $pc($y($vals[$n - 1]), $VH) ?>"><?= e($valor($vals[$n - 1])) ?></span>
                <?php if ($sim): ?>
                    <span class="fu-nota is-plano num" style="left:<?= $pc($x($n - 1), $VW) ?>;top:<?= $pc($y($valsBase[$n - 1]), $VH) ?>"><?= e(t('plano {valor}', ['valor' => $valor($valsBase[$n - 1])])) ?></span>
                <?php endif; ?>
            </div>
            <div class="fu-eixo" aria-hidden="true">
                <?php foreach ($linhas as $i => $l): ?>
                    <span class="fu-mx<?= $i % 2 !== $par ? ' is-salta' : '' ?><?= $l['mes'] === $ref ? ' is-ref' : '' ?>" style="left:<?= $pc($x($i), $VW) ?>"><?= e(mesCurto($l['mes'])) ?></span>
                    <?php if ($i === 0 || substr($l['mes'], 5, 2) === '01'): ?><span class="fu-ano" style="left:<?= $pc($x($i), $VW) ?>"><?= e(substr($l['mes'], 0, 4)) ?></span><?php endif; ?>
                    <?php if ($i === $iQuita): ?><span class="fu-ano fu-evento" style="left:<?= $pc($x($i), $VW) ?>"><?= e(t('fim do empréstimo')) ?></span><?php endif; ?>
                <?php endforeach; ?>
            </div>
        </figure>
    </section>

    <!-- mês a mês -->
    <details class="fu-tabela"<?= $sim ? ' open' : '' ?>>
    <summary class="btn btn-ghost"><?= e(t('Ver mês a mês')) ?></summary>
    <section class="card fu-meses" id="meses" aria-labelledby="fu-meses-t">
        <div class="fu-meses-cab">
            <h2 class="titulo" id="fu-meses-t"><?= e(t('Mês a mês')) ?> <span class="fu-unid"><?= e(t('em R$')) ?></span></h2>
            <p class="fu-leg"><span><?= $marca('real') ?><?= e(t('Fechado: só o lançado')) ?></span><span><?= $marca('aberto') ?><?= e(t('Aberto: lançado até hoje e o previsto do que falta')) ?></span><span><?= $marca('previsto') ?><?= e(t('Previsto')) ?></span></p>
        </div>
        <div class="fu-tab">
            <div class="fu-cols fu-cab num">
                <span class="fu-lbl fu-lbl-m"><?= e(t('Mês')) ?></span>
                <span class="fu-lbl fu-largo"><?= e(t('Entradas')) ?></span>
                <span class="fu-lbl fu-largo"><?= e(t('Saídas')) ?></span>
                <span class="fu-lbl"><?= e(t('Balanço')) ?></span>
                <span class="fu-lbl"><?= e(t('Conta no fim')) ?></span>
                <span class="fu-lbl fu-largo"><?= e(t('Dívida')) ?></span>
                <span class="fu-lbl fu-lbl-m fu-xl"><?= e(t('Mudou em relação ao mês anterior')) ?></span>
                <span></span>
            </div>
            <?php foreach ($anos as $ano => $doAno):
                $ultimoDoAno = $doAno[count($doAno) - 1];
                $somaE = array_sum(array_column($doAno, 'entra'));
                $somaS = array_sum(array_column($doAno, 'sai'));
                $somaB = array_sum(array_column($doAno, 'bal'));
                $parcial = count($doAno) < 12 ? ' (' . mesCurto($doAno[0]['mes']) . '–' . mesCurto($ultimoDoAno['mes']) . ')' : '';
                ?>
                <h3 class="fu-ano-cab num"><?= e($ano) ?></h3>
                <?php foreach ($doAno as $l):
                    $balCls = $l['bal'] < -0.004 ? ' neg' : '';
                    $lista = $chips($l);
                    // no detalhe, crédito, adiantamento e poupança já aparecem como valores: só as mudanças
                    $listaDet = array_values(array_filter($lista, static fn($c) => $c['tipo'] !== 'info'));
                    ?>
                    <details class="fu-mes<?= $l['mes'] === $ref ? ' is-ref' : '' ?>">
                        <summary class="fu-cols num">
                            <span class="fu-m"><?= $marca($l['estado']) ?><?= e(monthLabel($l['mes'])) ?><span class="sr-only">, <?= e(mb_strtolower(estadoLabel($l['estado']))) ?></span></span>
                            <span class="fu-v fu-largo"><span class="sr-only"><?= e(t('entradas')) ?> </span><?= e(num($l['entra'], 0)) ?></span>
                            <span class="fu-v fu-largo"><span class="sr-only"><?= e(t('saídas')) ?> </span><?= e(num($l['sai'], 0)) ?></span>
                            <span class="fu-v fu-bal<?= $balCls ?>"><span class="sr-only"><?= e(t('balanço')) ?> </span><?= e($sinal((float) $l['bal'])) ?></span>
                            <span class="fu-v fu-fica<?= $l['alerta'] !== '' ? ' ' . e($l['alerta']) : '' ?>"><span class="sr-only"><?= e(t('conta no fim')) ?> </span><?= $alertaIc($l['alerta']) ?><?= e($valor((float) $l['fica'])) ?><?= $l['alerta'] === 'warn' ? '<span class="sr-only">, ' . e(t('abaixo da reserva')) . '</span>' : ($l['alerta'] === 'neg' ? '<span class="sr-only">, ' . e(t('no vermelho')) . '</span>' : '') ?></span>
                            <span class="fu-v fu-largo fu-div"><span class="sr-only"><?= e(t('dívida')) ?> </span><?= $l['divida'] > 0.004 ? e(num((float) $l['divida'], 0)) : '<span aria-hidden="true">—</span><span class="sr-only">' . e(t('quitada')) . '</span>' ?></span>
                            <span class="fu-xl fu-chips"><?= $chipsHtml($lista) ?></span>
                        </summary>
                        <div class="fu-det">
                            <div class="fu-conta num">
                                <p><span><?= e(t('Começou o mês com')) ?></span> <?= e($valor2((float) $l['antes'])) ?></p>
                                <p><span>+ <?= e(t('Entradas')) ?></span> <?= e(num($l['entra_op'])) ?></p>
                                <?php if ($l['consignado'] > 0.004): ?><p class="fu-conta-sub"><span><?= e(t('salário de {bruto} menos {parcela} da parcela do empréstimo', ['bruto' => num($l['entra_bruto']), 'parcela' => num($l['consignado'])])) ?></span></p><?php endif; ?>
                                <?php if ($l['simulado'] > 0.004): ?><p class="fu-conta-sub"><span><?= t('inclui {valor} do "e se"', ['valor' => e(num($l['simulado']))]) ?></span></p><?php endif; ?>
                                <?php if ($l['cred'] > 0.004): ?><p><span>+ <?= e(t('Crédito do empréstimo')) ?></span> <?= e(num($l['cred'])) ?></p><?php endif; ?>
                                <?php if ($l['poup'] > 0.004): ?><p><span>+ <?= e(t('Tirado da poupança')) ?></span> <?= e(num($l['poup'])) ?></p><?php endif; ?>
                                <?php if ($l['inv'] > 0.004): ?><p><span>+ <?= e(t('Resgatado de investimentos')) ?></span> <?= e(num($l['inv'])) ?></p><?php endif; ?>
                                <p><span><?= MENOS ?> <?= e(t('Gastos')) ?></span> <?= e(num($l['gastos'])) ?></p>
                                <?php if ($l['simulado'] < -0.004): ?><p class="fu-conta-sub"><span><?= t('inclui {valor} do "e se"', ['valor' => e(num(abs($l['simulado'])))]) ?></span></p><?php endif; ?>
                                <?php if ($l['amort'] > 0.004): ?><p><span><?= MENOS ?> <?= e(t('Adiantado do empréstimo')) ?></span> <?= e(num($l['amort'])) ?></p><?php endif; ?>
                                <?php if ($l['poup'] < -0.004): ?><p><span><?= MENOS ?> <?= e(t('Guardado na poupança')) ?></span> <?= e(num(abs($l['poup']))) ?></p><?php endif; ?>
                                <?php if (($l['parte'] ?? 0.0) > 0.004): ?><p><span><?= MENOS ?> <?= e(t('Parcela do empréstimo')) ?></span> <?= e(num($l['parte'])) ?></p><?php endif; ?>
                                <?php if ($l['inv'] < -0.004): ?><p><span><?= MENOS ?> <?= e(t('Aplicado em investimentos')) ?></span> <?= e(num(abs($l['inv']))) ?></p><?php endif; ?>
                                <p class="fu-conta-fim<?= $l['alerta'] !== '' ? ' ' . e($l['alerta']) : '' ?>"><span>= <?= e(t('Conta no fim')) ?></span> <?= e($valor2((float) $l['fica'])) ?></p>
                                <p class="fu-conta-div"><span><?= e(t('Dívida no fim')) ?></span> <?= $l['divida'] > 0.004 ? e(num($l['divida'])) : e(t('quitada')) ?></p>
                            </div>
                            <div class="fu-det-lado">
                                <?php if ($listaDet !== []): ?><div class="fu-det-mudou"><span><?= e(t('O que mudou em relação ao mês anterior')) ?></span><div class="fu-chips"><?= $chipsHtml($listaDet) ?></div></div><?php endif; ?>
                                <a class="btn-link fu-abrir" href="/mes?mes=<?= e($l['mes']) ?>"><?= $l['estado'] === 'real' ? e(t('Ver {mes}', ['mes' => monthLabel($l['mes'])])) : e(t('Ver e ajustar {mes}', ['mes' => monthLabel($l['mes'])])) ?></a>
                            </div>
                        </div>
                    </details>
                <?php endforeach; ?>
                <div class="fu-cols fu-total num">
                    <span class="fu-m"><?= e(t('Total {ano}', ['ano' => $ano])) ?><small><?= e($parcial) ?></small></span>
                    <span class="fu-v fu-largo"><span class="sr-only"><?= e(t('entradas')) ?> </span><?= e(num($somaE, 0)) ?></span>
                    <span class="fu-v fu-largo"><span class="sr-only"><?= e(t('saídas')) ?> </span><?= e(num($somaS, 0)) ?></span>
                    <span class="fu-v fu-bal<?= $somaB < -0.004 ? ' neg' : '' ?>"><span class="sr-only"><?= e(t('balanço')) ?> </span><?= e($sinal($somaB)) ?></span>
                    <span class="fu-v fu-fica<?= $ultimoDoAno['alerta'] !== '' ? ' ' . e($ultimoDoAno['alerta']) : '' ?>"><span class="sr-only"><?= e(t('conta no fim de {mes}', ['mes' => monthLabel($ultimoDoAno['mes'])])) ?> </span><?= e($valor((float) $ultimoDoAno['fica'])) ?></span>
                    <span class="fu-v fu-largo fu-div"><span class="sr-only"><?= e(t('dívida no fim do ano')) ?> </span><?= $ultimoDoAno['divida'] > 0.004 ? e(num((float) $ultimoDoAno['divida'], 0)) : '<span aria-hidden="true">—</span><span class="sr-only">' . e(t('quitada')) . '</span>' ?></span>
                    <span class="fu-xl"></span>
                    <span></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    </details>
</div>
