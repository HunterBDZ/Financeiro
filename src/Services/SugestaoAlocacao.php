<?php
namespace Financeiro\Services;

use Financeiro\Models\ContaRecorrenteModel;

/**
 * Sugestão de divisão do Dia a dia. Nunca grava nada: devolve valores, mudanças e
 * impacto; quem grava é PrevisaoLote, a partir de celulas(), depois do clique da pessoa.
 *
 * Algoritmo: essenciais = ceil10(max(referência, previsão de k0 — ou de k1 com k0 aberto)); o "livre" (Delivery,
 * Lanches, Lazer) é nivelado max-min em múltiplos de 10, com o motor puro dentro do laço
 * (bisseção), respeitando piso da reserva, meta e/ou quitação conforme o objetivo:
 *   1 os dois (meta e quitação, regra atual) · 2 só quitar · 3 só a meta (sem amortização automática).
 *
 * Regra de ouro: a lista (`mudancas[escopo]`), as células gravadas (`celulas()`) e a linha de
 * impacto (`impacto[escopo]`) saem do mesmo escopo.
 */
final class SugestaoAlocacao
{
    public const CAT_PEDIDOS = 'Delivery / comer fora';

    private const ITERACOES = 24;

    private static array $cache = [];
    private static ?array $recorrentes = null;

    public static function limparCache(): void
    {
        self::$cache = [];
        self::$recorrentes = null;
    }

    // ------------------------------------------------------------------ referência

    /**
     * Referência histórica: média do real por categoria nos até 3 meses mais recentes REAL (ou em
     * opts.tratarComoReal) antes de $mesInicio que tenham lançamento de Dia a dia.
     * @return array{meses:string[], rotulo:string, ref:array<int,float>, n:array<int,int>, ticket:array<int,float>}
     *   ref/n/ticket para TODAS as categorias operacionais de gasto; ticket 0 sem histórico.
     */
    public static function referencia(string $mesInicio, array $opts = []): array
    {
        $d   = MotorFinanceiro::dados();
        $tcr = array_flip($opts['tratarComoReal'] ?? []);

        $diaIds = [];
        $gasto  = [];
        foreach ($d['cats'] as $id => $c) {
            if ($c['operacional'] === 1 && $c['sinal'] === -1) {
                $gasto[] = $id;
                if ($c['grupo'] === 'Dia a dia') {
                    $diaIds[] = $id;
                }
            }
        }

        $R = [];
        for ($i = count($d['meses']) - 1; $i >= 0 && count($R) < 3; $i--) {
            $m = $d['meses'][$i];
            if ($m['mes'] >= $mesInicio || ($m['situacao'] !== 'REAL' && !isset($tcr[$m['mes']]))) {
                continue;
            }
            $tem = false;
            foreach ($diaIds as $c) {
                if (($d['n'][$m['mes']][$c] ?? 0) > 0) {
                    $tem = true;
                    break;
                }
            }
            if ($tem) {
                $R[] = $m['mes'];
            }
        }
        $R = array_reverse($R); // cronológico

        $ref = $n = $ticket = [];
        if ($R === []) {
            $k0 = self::mesesK($d, $mesInicio, $tcr)[0] ?? $mesInicio;
            foreach ($gasto as $c) {
                $ref[$c]    = (float) ($d['prev'][$k0][$c] ?? 0.0);
                $n[$c]      = 0;
                $ticket[$c] = 0.0;
            }
            $rotulo = t('sem histórico');
        } else {
            foreach ($gasto as $c) {
                $soma = 0.0;
                $cnt  = 0;
                foreach ($R as $m) {
                    $soma += (float) ($d['real'][$m][$c] ?? 0.0);
                    $cnt  += (int) ($d['n'][$m][$c] ?? 0);
                }
                $ref[$c]    = round($soma / count($R), 2);
                $n[$c]      = $cnt;
                $ticket[$c] = $cnt > 0 ? round($soma / $cnt, 2) : 0.0;
            }
            if (count($R) === 1) {
                $m = $R[0];
                $ult = '';
                foreach ($diaIds as $c) {
                    $u = $d['ult'][$m][$c] ?? '';
                    if ($u > $ult) {
                        $ult = $u;
                    }
                }
                $diaUlt = (int) substr($ult, 8, 2);
                $diasMes = (int) date('t', strtotime($m));
                $rotulo = $diaUlt / $diasMes < 0.9 ? t('{mes} · {n} dias', ['mes' => mesCurto($m), 'n' => $diaUlt]) : mesCurto($m);
            } else {
                $rotulo = t('média {n}m', ['n' => count($R)]);
            }
        }

        return ['meses' => $R, 'rotulo' => $rotulo, 'ref' => $ref, 'n' => $n, 'ticket' => $ticket];
    }

    // ------------------------------------------------------------------ cálculo

    /**
     * @param int  $objetivo 1 os dois · 2 só quitar · 3 só a meta
     * @param bool $colchao  true: nos meses em que o essencial já fura a reserva, o piso vira 0
     * @param array $opts    ['hoje'=>?string, 'tratarComoReal'=>string[]]
     * @return array dados da sugestão: ref, previsao, real_k0, k0, aberto, essenciais,
     *               abaixo_reserva, inviavel{meta,quitacao}
     */
    public static function calcular(string $mesInicio, int $objetivo, bool $colchao, array $opts = []): array
    {
        if (!in_array($objetivo, [1, 2, 3], true)) {
            $objetivo = 1;
        }
        $hoje   = (string) ($opts['hoje'] ?? date('Y-m-d'));
        $tcrLst = array_values($opts['tratarComoReal'] ?? []);
        $chave  = serialize([$mesInicio, $objetivo, $colchao, $hoje, $tcrLst]);
        if (isset(self::$cache[$chave])) {
            return self::$cache[$chave];
        }

        $d   = MotorFinanceiro::dados();
        $aj  = $d['aj'];
        $tcr = array_flip($tcrLst);
        $K   = self::mesesK($d, $mesInicio, $tcr);
        $N   = count($K);
        $ref = self::referencia($mesInicio, ['hoje' => $hoje, 'tratarComoReal' => $tcrLst]);

        $reserva = (float) ($aj['reserva_minima'] ?? 0.0);
        $meta    = (float) ($aj['meta_final'] ?? 0.0);
        $motorOpts = ['v2' => true, 'objetivo' => $objetivo, 'hoje' => $hoje, 'tratarComoReal' => $tcrLst];

        // categorias do Dia a dia (ativas); as com conta recorrente ativa ficam como estão (nunca zerar)
        $rec = self::recorrentes();
        $ess = $liv = [];
        foreach ($d['cats'] as $id => $c) {
            if ($c['grupo'] !== 'Dia a dia' || $c['ativo'] !== 1 || isset($rec[$id])) {
                continue;
            }
            if ($c['essencial'] === 1) { $ess[] = $id; } else { $liv[] = $id; }
        }
        $dia = array_merge($ess, $liv);
        usort($dia, fn($a, $b) => [$d['cats'][$a]['ordem'], $a] <=> [$d['cats'][$b]['ordem'], $b]);

        if ($N === 0) {
            return self::$cache[$chave] = self::vazio($objetivo, $colchao, $ref);
        }
        $k0 = $K[0];
        $ultimoK = $K[$N - 1];

        $prevK = [];
        foreach ($K as $k) {
            foreach ($dia as $c) {
                $prevK[$k][$c] = (float) ($d['prev'][$k][$c] ?? 0.0);
            }
        }
        $realK0 = [];
        foreach ($dia as $c) {
            $realK0[$c] = (float) ($d['real'][$k0][$c] ?? 0.0);
        }

        $mesHoje = substr($hoje, 0, 7) . '-01';
        $aberto  = $k0 <= $mesHoje;
        $E = self::essenciais($d, $K, $ess, $ref['ref'], $aberto);
        $refLivre = 0.0;
        foreach ($liv as $c) {
            $refLivre += $ref['ref'][$c] ?? 0.0;
        }
        $refLivre = round($refLivre, 2);
        $w = [];
        foreach ($liv as $c) {
            $w[$c] = $refLivre > 0 ? ($ref['ref'][$c] ?? 0.0) / $refLivre : 1 / max(1, count($liv));
        }

        // valores de um mês a partir do nível livre
        $valoresMes = function (float $L) use ($E, $liv, $w): array {
            return $E + self::split($L, $liv, $w);
        };
        // Regra de ouro: uma célula com |Δ| < 10 nunca é gravada (celulas()), então o motor também não
        // pode vê-la mudada — ela volta ao previsto. Vale no nivelamento, no plano e no impacto.
        $snap = function (string $k, array $v) use ($prevK): array {
            foreach ($v as $c => $x) {
                if (abs($x - $prevK[$k][$c]) < 10 - 0.005) {
                    $v[$c] = $prevK[$k][$c];
                }
            }
            return $v;
        };
        $fixo0 = null; // mês aberto: valores fixados em k0 (passo 9)
        $rodar = function (array $L) use ($d, $K, $N, $valoresMes, &$fixo0, $motorOpts, $snap): array {
            $ov = [];
            for ($i = 0; $i < $N; $i++) {
                $ov[$K[$i]] = $snap($K[$i], ($i === 0 && $fixo0 !== null) ? $fixo0 : $valoresMes($L[$i]));
            }
            return MotorFinanceiro::calcular($d, $motorOpts + ['override' => $ov]);
        };

        $piso = [];
        $P0 = [];
        $calcPiso = function () use (&$piso, &$P0, $rodar, $N, $K, $colchao, $reserva): void {
            $P0 = $rodar(array_fill(0, $N, 0.0));
            foreach ($K as $k) {
                $f = $P0[$k]['fica_na_conta'];
                // colchão: o piso cai pra 0, mas nunca acima de um mês que já fica negativo só com o essencial
                $piso[$k] = ($colchao && $f < $reserva) ? min(0.0, $f) : min($reserva, $f);
            }
        };
        $viavelPlano = function (array $plano) use (&$piso, $K, $ultimoK, $objetivo, $meta): bool {
            foreach ($K as $k) {
                if ($plano[$k]['fica_na_conta'] < $piso[$k] - 0.005) {
                    return false;
                }
            }
            if ($objetivo !== 2 && $plano[$ultimoK]['total'] < $meta - 0.005) {
                return false;
            }
            if ($objetivo !== 3 && $plano[$ultimoK]['falta_pagar'] > 0.01) {
                return false;
            }
            return true;
        };
        $viavel = fn(array $L): bool => $viavelPlano($rodar($L));

        $nivelar = function (array $L, int $i) use ($N, $refLivre, $viavel): array {
            while ($i < $N) {
                $com = function (float $x) use ($L, $i, $N): array {
                    for ($q = $i; $q < $N; $q++) {
                        $L[$q] = $x;
                    }
                    return $L;
                };
                if (!$viavel($com(0.0))) {
                    break;
                }
                if ($refLivre <= 0) {
                    $x = 0.0;
                } elseif ($viavel($com($refLivre))) {
                    $x = self::floor10($refLivre);
                } else {
                    $lo = 0.0;
                    $hi = $refLivre;
                    for ($it = 0; $it < self::ITERACOES; $it++) {
                        $mid = ($lo + $hi) / 2;
                        if ($viavel($com($mid))) { $lo = $mid; } else { $hi = $mid; }
                    }
                    $x = self::floor10($lo);
                    // a bisseção se aproxima por baixo: um múltiplo de 10 exato pode ter ficado de fora
                    if ($x + 10 <= $refLivre && $viavel($com($x + 10))) {
                        $x += 10;
                    }
                }
                $k = $N - 1;
                for ($j = $i; $j < $N; $j++) {
                    $T = $com($x);
                    for ($q = $i; $q <= $j; $q++) {
                        $T[$q] = $x + 10;
                    }
                    if (!$viavel($T)) {
                        $k = $j;
                        break;
                    }
                }
                for ($q = $i; $q <= $k; $q++) {
                    $L[$q] = $x;
                }
                $i = $k + 1;
            }
            return $L;
        };

        // passos 4–7
        $calcPiso();
        $abaixo = [];
        foreach ($K as $k) {
            if ($P0[$k]['fica_na_conta'] < $reserva) {
                $abaixo[] = $k;
            }
        }
        $L = array_fill(0, $N, 0.0);
        $ok = $viavel($L);
        if ($ok) {
            $L = $nivelar($L, 0);
        }

        // passo 9: mês aberto — o sugerido nunca fica abaixo do já gasto (só em k0; ver essenciais())
        if ($aberto) {
            $v0 = $valoresMes($L[0]);
            $mudou = false;
            foreach ($dia as $c) {
                $piso0 = self::ceil10($realK0[$c]);
                if ($piso0 > $v0[$c]) {
                    $v0[$c] = $piso0;
                    $mudou = true;
                }
            }
            if ($mudou) {
                $fixo0 = $v0;
                $soma = 0.0;
                foreach ($liv as $c) {
                    $soma += $v0[$c];
                }
                $L[0] = $soma;
                for ($q = 1; $q < $N; $q++) {
                    $L[$q] = 0.0;
                }
                $calcPiso();
                $ok = $viavel($L);
                if ($ok) {
                    $L = $nivelar($L, 1);
                }
            }
        }

        // passo 10: saída
        $valores = [];
        $niveis  = [];
        for ($i = 0; $i < $N; $i++) {
            $valores[$K[$i]] = $snap($K[$i], ($i === 0 && $fixo0 !== null) ? $fixo0 : $valoresMes($L[$i]));
            $niveis[$K[$i]]  = (float) $L[$i];
        }
        $plano = $rodar($L);

        $antes = MotorFinanceiro::calcular($d, ['v2' => true, 'hoje' => $hoje, 'tratarComoReal' => $tcrLst]); // objetivo gravado
        $mudancas = $impacto = [];
        foreach (['tudo' => $K, 'so' => [$k0]] as $esc => $mesesEsc) {
            $mud = [];
            foreach ($dia as $c) {
                $pv = $sg = [];
                $cnt = 0;
                foreach ($mesesEsc as $k) {
                    $pv[] = $prevK[$k][$c];
                    $sg[] = $valores[$k][$c];
                    if (abs($valores[$k][$c] - $prevK[$k][$c]) >= 10 - 0.005) {
                        $cnt++;
                    }
                }
                if ($cnt >= 1) {
                    $mud[$c] = [
                        'de'    => [min($pv), max($pv)],
                        'para'  => [min($sg), max($sg)],
                        'k0'    => [$prevK[$k0][$c], $valores[$k0][$c]],
                        'meses' => $cnt,
                    ];
                }
            }
            $mudancas[$esc] = $mud;

            $ov = [];
            foreach ($mesesEsc as $k) {
                $ov[$k] = $valores[$k];
            }
            $depois = MotorFinanceiro::calcular($d, $motorOpts + ['override' => $ov]);
            $min = null;
            foreach ($K as $k) {
                if ($min === null || $depois[$k]['fica_na_conta'] < $min[1]) {
                    $min = [$k, $depois[$k]['fica_na_conta']];
                }
            }
            $impacto[$esc] = [
                'fica_mes'    => [$antes[$k0]['fica_na_conta'], $depois[$k0]['fica_na_conta']],
                'total_final' => [$antes[$ultimoK]['total'], $depois[$ultimoK]['total']],
                'quita'       => [MotorFinanceiro::quitaEm($antes), MotorFinanceiro::quitaEm($depois)],
                'divida_final' => [max(0.0, $antes[$ultimoK]['falta_pagar']), max(0.0, $depois[$ultimoK]['falta_pagar'])],
                'minimo'      => $min,
            ];
        }

        $falta = [];
        foreach ($K as $k) {
            $falta[$k] = max(0.0, round($reserva - $plano[$k]['fica_na_conta'], 2));
        }
        $inviavel = [
            'meta'     => $objetivo !== 2 && $plano[$ultimoK]['total'] < $meta - 0.005,
            'quitacao' => $objetivo !== 3 && $plano[$ultimoK]['falta_pagar'] > 0.01,
        ];

        return self::$cache[$chave] = [
            'objetivo'        => $objetivo,
            'colchao'         => $colchao,
            'meses'           => $K,
            'niveis'          => $niveis,
            'valores'         => $valores,
            'mudancas'        => $mudancas,
            'plano'           => $plano,
            'impacto'         => $impacto,
            'falta'           => $falta,
            'viavel'          => $ok,
            'precisa_colchao' => $abaixo !== [],
            'rotulo_ref'      => $ref['rotulo'],
            // extras
            'ref'             => $ref,
            'previsao'        => $prevK,
            'real_k0'         => $realK0,
            'k0'              => $k0,
            'aberto'          => $aberto,
            'essenciais'      => $E,
            'abaixo_reserva'  => $abaixo,
            'inviavel'        => $inviavel,
        ];
    }

    /**
     * Células a gravar: c ∈ $cats ∩ mudancas[$escopo], k ∈ escopo, |Δ| ≥ 10.
     * @return list<array{0:int,1:string,2:float}>
     */
    public static function celulas(array $sugestao, string $escopo, array $cats): array
    {
        $escopo = $escopo === 'so' ? 'so' : 'tudo';
        if (($sugestao['meses'] ?? []) === []) {
            return [];
        }
        $meses = $escopo === 'so' ? [$sugestao['k0']] : $sugestao['meses'];
        $mud   = $sugestao['mudancas'][$escopo] ?? [];
        $out   = [];
        foreach (array_unique(array_map('intval', $cats)) as $c) {
            if (!isset($mud[$c])) {
                continue;
            }
            foreach ($meses as $k) {
                $novo = (float) $sugestao['valores'][$k][$c];
                if (abs($novo - (float) $sugestao['previsao'][$k][$c]) >= 10 - 0.005) {
                    $out[] = [$c, $k, $novo];
                }
            }
        }
        return $out;
    }

    /**
     * Resumo dos 3 objetivos para os chips.
     * @return array<int, array{niveis:float[], quita:?string, total_final:float, divida_final:float, minimo:?array, viavel:bool}>
     */
    public static function opcoes(string $mesInicio, bool $colchao, array $opts = []): array
    {
        $out = [];
        foreach ([1, 2, 3] as $obj) {
            $s = self::calcular($mesInicio, $obj, $colchao, $opts);
            if ($s['meses'] === []) {
                $out[$obj] = ['niveis' => [], 'quita' => null, 'total_final' => 0.0, 'divida_final' => 0.0, 'minimo' => null, 'viavel' => false];
                continue;
            }
            $dist = [];
            foreach ($s['niveis'] as $v) {
                if ($dist === [] || end($dist) !== $v) {
                    $dist[] = $v;
                }
            }
            if (count($dist) > 3) {
                $dist = [$dist[0], $dist[1], end($dist)];
            }
            $ult = end($s['plano']);
            $out[$obj] = [
                'niveis'       => $dist,
                'quita'        => MotorFinanceiro::quitaEm($s['plano']),
                'total_final'  => $ult['total'],
                'divida_final' => max(0.0, $ult['falta_pagar']),
                'minimo'       => $s['impacto']['tudo']['minimo'],
                'viavel'       => $s['viavel'],
            ];
        }
        return $out;
    }

    /** "Ritmo da referência": o motor com o livre da referência em todos os meses, sem amortização automática. */
    public static function ritmoReferencia(string $mesInicio, array $opts = []): array
    {
        $d      = MotorFinanceiro::dados();
        $hoje   = (string) ($opts['hoje'] ?? date('Y-m-d'));
        $tcrLst = array_values($opts['tratarComoReal'] ?? []);
        $K      = self::mesesK($d, $mesInicio, array_flip($tcrLst));
        $ref    = self::referencia($mesInicio, $opts);
        if ($K === []) {
            return ['livre' => 0.0, 'total_final' => 0.0];
        }
        $rec = self::recorrentes();
        $ess = $liv = [];
        foreach ($d['cats'] as $id => $c) {
            if ($c['grupo'] !== 'Dia a dia' || $c['ativo'] !== 1 || isset($rec[$id])) {
                continue;
            }
            if ($c['essencial'] === 1) { $ess[] = $id; } else { $liv[] = $id; }
        }
        $E = self::essenciais($d, $K, $ess, $ref['ref'], $K[0] <= substr($hoje, 0, 7) . '-01');
        $refLivre = 0.0;
        foreach ($liv as $c) {
            $refLivre += $ref['ref'][$c] ?? 0.0;
        }
        $refLivre = round($refLivre, 2);
        $w = [];
        foreach ($liv as $c) {
            $w[$c] = $refLivre > 0 ? ($ref['ref'][$c] ?? 0.0) / $refLivre : 1 / max(1, count($liv));
        }
        $ov = [];
        foreach ($K as $k) {
            $ov[$k] = $E + self::split($refLivre, $liv, $w);
        }
        $plano = MotorFinanceiro::calcular($d, [
            'v2' => true, 'objetivo' => 3, 'hoje' => $hoje, 'tratarComoReal' => $tcrLst, 'override' => $ov,
        ]);
        return ['livre' => $refLivre, 'total_final' => $plano[end($K)]['total']];
    }

    // ------------------------------------------------------------------ internos

    /**
     * Passo 1: E_c = ceil10(max(ref_c, previsao_c(kE))). kE = k0, salvo quando k0 é o mês aberto:
     * aí o passo 9 sobe k0 até o já gasto e o APLICAR grava isso em previsao(k0); se k0 voltasse a
     * alimentar E, esse piso de um mês só seria copiado para todos os meses no clique seguinte (a
     * sugestão mudaria de novo logo depois de aplicada). Então kE = k1, o primeiro mês não aberto;
     * sem k1 (só resta o mês aberto), vale previsao(k0), exceto quando ela é exatamente o piso do
     * passo 9 (= ceil10(real)).
     */
    private static function essenciais(array $d, array $K, array $ess, array $ref, bool $aberto): array
    {
        $k0 = $K[0];
        $kE = $aberto ? ($K[1] ?? null) : $k0;
        $E = [];
        foreach ($ess as $c) {
            if ($kE !== null) {
                $base = (float) ($d['prev'][$kE][$c] ?? 0.0);
            } else {
                $base = (float) ($d['prev'][$k0][$c] ?? 0.0);
                $real = (float) ($d['real'][$k0][$c] ?? 0.0);
                if ($real > 0 && abs($base - self::ceil10($real)) < 0.005) {
                    $base = 0.0;
                }
            }
            $E[$c] = self::ceil10(max($ref[$c] ?? 0.0, $base));
        }
        return $E;
    }

    /** Meses PREVISAO (fora de tratarComoReal) a partir de $mesInicio, em ordem. */
    private static function mesesK(array $d, string $mesInicio, array $tcr): array
    {
        $K = [];
        foreach ($d['meses'] as $m) {
            if ($m['situacao'] === 'PREVISAO' && !isset($tcr[$m['mes']]) && $m['mes'] >= $mesInicio) {
                $K[] = $m['mes'];
            }
        }
        return $K;
    }

    /** Divide o livre: B = floor10(L); cada um floor10(B·w); o que faltar vai de 10 em 10 aos maiores restos. */
    private static function split(float $L, array $liv, array $w): array
    {
        $B = self::floor10($L);
        $raw = $s = [];
        foreach ($liv as $c) {
            $raw[$c] = $B * $w[$c];
            $s[$c]   = self::floor10($raw[$c]);
        }
        $resto = $B - array_sum($s);
        $ordem = $liv;
        usort($ordem, fn($a, $b) => ($raw[$b] - $s[$b]) <=> ($raw[$a] - $s[$a])); // estável: empate mantém a ordem
        foreach ($ordem as $c) {
            if ($resto < 10 - 1e-6) {
                break;
            }
            $s[$c] += 10;
            $resto -= 10;
        }
        return $s;
    }

    private static function ceil10(float $x): float
    {
        return ceil(round($x, 6) / 10) * 10;
    }

    private static function floor10(float $x): float
    {
        return floor(round($x, 6) / 10) * 10;
    }

    /** @return array<int,true> categorias com conta recorrente ativa */
    private static function recorrentes(): array
    {
        if (self::$recorrentes === null) {
            self::$recorrentes = [];
            foreach (ContaRecorrenteModel::ativas() as $r) {
                self::$recorrentes[(int) $r['categoria_id']] = true;
            }
        }
        return self::$recorrentes;
    }

    private static function vazio(int $objetivo, bool $colchao, array $ref): array
    {
        return [
            'objetivo' => $objetivo, 'colchao' => $colchao, 'meses' => [], 'niveis' => [], 'valores' => [],
            'mudancas' => ['tudo' => [], 'so' => []], 'plano' => [],
            'impacto' => ['tudo' => null, 'so' => null], 'falta' => [], 'viavel' => false,
            'precisa_colchao' => false, 'rotulo_ref' => $ref['rotulo'], 'ref' => $ref, 'previsao' => [],
            'real_k0' => [], 'k0' => null, 'aberto' => false, 'essenciais' => [], 'abaixo_reserva' => [],
            'inviavel' => ['meta' => false, 'quitacao' => false],
        ];
    }
}
