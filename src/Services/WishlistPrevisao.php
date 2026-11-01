<?php
namespace Financeiro\Services;

/**
 * Em que mês um item cabe: roda o motor uma vez para cada mês de compra possível e olha o plano
 * inteiro. Um mês "cabe" quando nenhum mês fica abaixo da reserva por causa da compra; o que já
 * estava abaixo dela sem a compra só não pode piorar. Como o motor adianta o empréstimo com a sobra,
 * comprar antes pode custar juros. O melhor mês é o 1º que cabe sem atrasar o empréstimo; sem nenhum
 * assim dentro do plano, o 1º que cabe. O "mais cedo" fica ao lado, com o custo à mostra.
 * Aritmética pura sobre MotorFinanceiro::dados(); nada é gravado.
 */
final class WishlistPrevisao
{
    /** Parcelas que a tela oferece; 1 = à vista. */
    public const PARCELAS = [1, 2, 3, 6, 10, 12];

    /** Juros a mais abaixo disto (R$) contam como sem custo: é arredondamento. */
    private const SEM_CUSTO = 1.0;

    /**
     * @return array{valor:float, parcelas:int, reserva:float, temDivida:bool, quitaBase:?string, ultimo:string,
     *   meses:array<string, array{estado:string, pior:?array, quita:?string, custo:float, atraso:int, fim:float}>,
     *   cedo:?string, semCusto:?string, melhor:?string, menorFalta:?array{mes:string, valor:float}}
     */
    public static function calcular(array $dados, string $ref, float $valor, int $parcelas, string $hoje): array
    {
        $aj       = $dados['aj'];
        $parcela  = (float) ($aj['parcela_mensal'] ?? 0.0);
        $jurosMes = (float) ($aj['juros_mes'] ?? 0.0);
        $reserva  = (float) ($aj['reserva_minima'] ?? 0.0);
        $base     = MotorFinanceiro::calcular($dados, ['hoje' => $hoje]);
        $chaves   = array_map('strval', array_keys($base));
        $qBase    = AnualResumo::quitacao($base, $parcela, $jurosMes);
        // dívida que o próprio plano quita no mês de referência ainda é dívida: comprar compete com ela
        $linhaRef = $base[$ref] ?? [];
        $temDivida = (float) ($linhaRef['falta_pagar'] ?? 0.0) > 0.01 || (float) ($linhaRef['juros'] ?? 0.0) > 0.004;

        $out = ['valor' => $valor, 'parcelas' => $parcelas, 'reserva' => $reserva, 'temDivida' => $temDivida,
                'quitaBase' => $qBase['mes'] ?? null, 'ultimo' => (string) end($chaves), 'meses' => [],
                'cedo' => null, 'semCusto' => null, 'melhor' => null, 'menorFalta' => null];

        foreach ($base as $m => $linha) {
            $mes = (string) $m;
            if ($mes < $ref || $linha['estado'] === 'real') {
                continue;
            }
            $extra = self::saidas($chaves, $mes, $valor, $parcelas);
            if ($extra === null) {
                $out['meses'][$mes] = ['estado' => 'fora', 'pior' => null, 'quita' => null, 'custo' => 0.0, 'atraso' => 0, 'fim' => 0.0];
                continue;
            }
            $plano = MotorFinanceiro::calcular($dados, ['hoje' => $hoje, 'extra' => $extra]);

            $estado = 'ok';
            $pior = null;
            $falta = 0.0;
            foreach ($plano as $k => $l) {
                if ((string) $k < $ref) {
                    continue;
                }
                $fica = (float) $l['fica_na_conta'];
                if ($pior === null || $fica < $pior['fica'] - 0.004) {
                    $pior = ['mes' => (string) $k, 'fica' => $fica];
                }
                $limite = min($reserva, (float) $base[$k]['fica_na_conta']);
                if ($fica < $limite - 0.005) {
                    $falta = max($falta, $limite - $fica);
                    $estado = $fica < -0.004 || $estado === 'neg' ? 'neg' : 'warn';
                }
            }
            $q = AnualResumo::quitacao($plano, $parcela, $jurosMes);
            $custo = $qBase !== null && $q !== null ? max(0.0, $q['juros'] - $qBase['juros']) : 0.0;
            $atraso = $qBase !== null && $q !== null ? max(0, AnualResumo::mesesEntre($qBase['mes'], $q['mes'])) : 0;
            $ultimoPlano = end($plano);
            $out['meses'][$mes] = ['estado' => $estado, 'pior' => $pior, 'quita' => $q['mes'] ?? null, 'custo' => $custo,
                                   'atraso' => $atraso, 'fim' => (float) $ultimoPlano['fica_na_conta']];

            if ($estado === 'ok') {
                $out['cedo'] ??= $mes;
                if ($custo < self::SEM_CUSTO && $atraso === 0) {
                    $out['semCusto'] ??= $mes;
                }
            } elseif ($out['menorFalta'] === null || $falta < $out['menorFalta']['valor']) {
                $out['menorFalta'] = ['mes' => $mes, 'valor' => $falta];
            }
        }
        $out['melhor'] = $out['semCusto'] ?? $out['cedo'];
        return $out;
    }

    /**
     * O que sai da conta: à vista, no mês da compra; parcelado, da fatura do mês seguinte em diante.
     * Null quando alguma parcela passaria do último mês do plano e não dá para avaliar a compra.
     * @param list<string> $chaves os meses do plano
     * @return ?array<string, float> mês => valor negativo
     */
    private static function saidas(array $chaves, string $mes, float $valor, int $parcelas): ?array
    {
        if ($parcelas <= 1) {
            return [$mes => -$valor];
        }
        $i = array_search($mes, $chaves, true);
        if ($i === false || $i + $parcelas >= count($chaves)) {
            return null;
        }
        $cada = floor($valor / $parcelas * 100) / 100;
        $extra = [];
        for ($k = 1; $k <= $parcelas; $k++) {
            $extra[$chaves[$i + $k]] = -($k === 1 ? round($valor - $cada * ($parcelas - 1), 2) : $cada);
        }
        return $extra;
    }
}
