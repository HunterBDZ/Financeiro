<?php
namespace Financeiro\Services;

/**
 * Aba Empréstimo: quanto falta e o que um adiantamento muda. Compara sempre com "só as parcelas"
 * (objetivo 3, sem o adiantamento automático da sobra): dentro do plano normal, um adiantamento
 * só troca de lugar com a sobra que o motor já ia adiantar e o resultado quase não muda.
 * Aritmética pura sobre MotorFinanceiro::dados(); nada é gravado.
 */
final class EmprestimoSimulacao
{
    /** Limite da curva quando a parcela não cobre os juros (não há quitação para calcular). */
    private const MAX_CURVA = 600;

    /** @return array<string, array> mês => linha do motor, só com as parcelas e o que já foi lançado */
    private static function soParcelas(array $dados, string $hoje, array $adiantar = []): array
    {
        return MotorFinanceiro::calcular($dados, ['v2' => true, 'objetivo' => 3, 'hoje' => $hoje, 'adiantar' => $adiantar]);
    }

    /**
     * A situação de hoje: o saldo no fim do mês de referência, quando acaba, os juros que faltam,
     * os meses em que dá para simular, o fim pelo contrato e a quitação no plano do Anual.
     */
    public static function situacao(array $dados, string $ref, string $hoje): array
    {
        $aj       = $dados['aj'];
        $parcela  = (float) ($aj['parcela_mensal'] ?? 0.0);
        $jurosMes = (float) ($aj['juros_mes'] ?? 0.0);
        $base     = self::soParcelas($dados, $hoje);
        $q        = AnualResumo::quitacao($base, $parcela, $jurosMes);
        $falta    = max(0.0, (float) ($base[$ref]['falta_pagar'] ?? 0.0));

        $meses = [];
        foreach ($base as $m => $l) {
            if ((string) $m >= $ref && $l['estado'] !== 'real' && (float) $l['falta_pagar'] > 0.01) {
                $meses[] = (string) $m;
            }
        }

        $ini = MotorFinanceiro::mesInicioParcela($aj);
        $n   = (int) round((float) ($aj['n_parcelas'] ?? 0.0));
        $contrato = $ini !== null && $n > 0 ? self::somaMeses($ini, $n - 1) : null;

        return [
            'ref' => $ref, 'falta' => $falta, 'quitado' => $falta <= 0.01, 'parcela' => $parcela, 'jurosMes' => $jurosMes,
            'nParcelas' => $n, 'reserva' => (float) ($aj['reserva_minima'] ?? 0.0),
            'quita' => $q['mes'] ?? null,
            'restantes' => $q !== null ? max(0, AnualResumo::mesesEntre($ref, $q['mes'])) : null,
            'jurosFaltam' => $q !== null ? self::jurosDepois($base, $q['juros'], $ref) : null,
            'meses' => $meses, 'contrato' => $contrato, 'informado' => MotorFinanceiro::saldoInformado($aj),
            'quitaPlano' => MotorFinanceiro::quitaEm(MotorFinanceiro::calcular($dados, ['v2' => true, 'hoje' => $hoje])),
        ];
    }

    /** A dívida no fim de $mes, depois da parcela e do que já foi lançado. */
    public static function dividaNoMes(array $dados, string $mes, string $hoje): float
    {
        return max(0.0, (float) (self::soParcelas($dados, $hoje)[$mes]['falta_pagar'] ?? 0.0));
    }

    /**
     * Um adiantamento no fim de $mes, depois da parcela do mês. Valor acima da dívida vira a dívida.
     * @return ?array null quando não há dívida nesse mês
     */
    public static function simular(array $dados, string $ref, string $mes, float $valor, string $hoje): ?array
    {
        $aj       = $dados['aj'];
        $parcela  = (float) ($aj['parcela_mensal'] ?? 0.0);
        $jurosMes = (float) ($aj['juros_mes'] ?? 0.0);
        $reserva  = (float) ($aj['reserva_minima'] ?? 0.0);
        $base     = self::soParcelas($dados, $hoje);
        $teto     = round(max(0.0, (float) ($base[$mes]['falta_pagar'] ?? 0.0)), 2);
        if ($teto <= 0.01 || $valor <= 0.0) {
            return null;
        }
        $aplicado = min($valor, $teto);
        $com = self::soParcelas($dados, $hoje, [$mes => $aplicado]);
        $qBase = AnualResumo::quitacao($base, $parcela, $jurosMes);
        $qCom  = AnualResumo::quitacao($com, $parcela, $jurosMes);

        $pior = null;
        foreach ($com as $m => $l) {
            if ((string) $m >= $mes && ($pior === null || (float) $l['fica_na_conta'] < $pior['fica'] - 0.004)) {
                $pior = ['mes' => (string) $m, 'fica' => (float) $l['fica_na_conta']];
            }
        }
        $falta = max(0.0, (float) $com[$mes]['falta_pagar']);

        return [
            'mes' => $mes, 'valor' => $valor, 'aplicado' => $aplicado, 'ajustado' => $valor > $teto + 0.004, 'teto' => $teto,
            'falta' => $falta, 'faltaAntes' => $teto, 'quita' => $qCom['mes'] ?? null, 'quitaAntes' => $qBase['mes'] ?? null,
            'mesesAntes' => $qBase !== null && $qCom !== null ? AnualResumo::mesesEntre($qCom['mes'], $qBase['mes']) : null,
            'jurosMenos' => $qBase !== null && $qCom !== null ? max(0.0, $qBase['juros'] - $qCom['juros']) : null,
            'contaAntes' => (float) $base[$mes]['fica_na_conta'], 'contaDepois' => (float) $com[$mes]['fica_na_conta'],
            'pior' => $pior, 'alerta' => $pior === null ? null : ($pior['fica'] < -0.004 ? 'neg' : ($pior['fica'] < $reserva - 0.004 ? 'warn' : null)),
            'menorParcela' => self::menorParcela($base, $qBase, $mes, $falta, $jurosMes),
            'curva' => self::curvas($base, $com, $ref, $parcela, $jurosMes),
        ];
    }

    /** Se o banco mantiver o prazo e baixar a parcela: a parcela nova e os juros a menos. */
    private static function menorParcela(array $base, ?array $qBase, string $mes, float $saldo, float $jurosMes): ?array
    {
        if ($qBase === null || $saldo <= 0.01) {
            return null;
        }
        $n = AnualResumo::mesesEntre($mes, $qBase['mes']);
        if ($n < 1) {
            return null;
        }
        $nova = $jurosMes > 0 ? $saldo * $jurosMes / (1 - (1 + $jurosMes) ** -$n) : $saldo / $n;
        return ['parcela' => $nova, 'quita' => $qBase['mes'],
                'jurosMenos' => max(0.0, self::jurosDepois($base, $qBase['juros'], $mes) - ($nova * $n - $saldo))];
    }

    /** Juros totais menos os dos meses até $mes (inclusive). */
    private static function jurosDepois(array $plano, float $total, string $mes): float
    {
        foreach ($plano as $m => $l) {
            if ((string) $m <= $mes) {
                $total -= (float) $l['juros'];
            }
        }
        return max(0.0, $total);
    }

    /**
     * Saldo no fim de cada mês, de $ref até o "só as parcelas" zerar.
     * @return list<array{mes:string, antes:float, com:float}>
     */
    private static function curvas(array $base, array $com, string $ref, float $parcela, float $jurosMes): array
    {
        $a = self::curva($base, $ref, $parcela, $jurosMes);
        $b = self::curva($com, $ref, $parcela, $jurosMes);
        $out = [];
        foreach ($a as $m => $v) {
            $out[] = ['mes' => $m, 'antes' => $v, 'com' => $b[$m] ?? 0.0];
        }
        return $out;
    }

    /** @return array<string, float> mês => saldo, pela linha do motor e depois só com a parcela. */
    private static function curva(array $plano, string $de, float $parcela, float $jurosMes): array
    {
        $out = [];
        $mes = $de;
        $saldo = 0.0;
        foreach ($plano as $m => $l) {
            $mes = (string) $m;
            $saldo = max(0.0, (float) $l['falta_pagar']);
            if ($mes >= $de) {
                $out[$mes] = $saldo;
            }
            if ($mes >= $de && $saldo <= 0.01) {
                return $out;
            }
        }
        for ($k = 0; $k < self::MAX_CURVA && $saldo > 0.01 && $parcela > 0.004; $k++) {
            $saldo = max(0.0, $saldo + $saldo * $jurosMes - $parcela);
            $mes = self::somaMeses($mes, 1);
            $out[$mes] = $saldo;
        }
        return $out;
    }

    private static function somaMeses(string $mes, int $n): string
    {
        return date('Y-m-01', strtotime($mes . ' ' . ($n >= 0 ? '+' : '') . $n . ' month'));
    }
}
