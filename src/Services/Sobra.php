<?php
namespace Financeiro\Services;

/**
 * A sobra de um mês: a mesma conta e o mesmo nome em todas as telas (Início, Mês, Fechar e Relatório).
 * Conta: a renda sem a parcela do empréstimo, menos os gastos (entra − sai − parcela_parte da linha do motor).
 * Fora de mês fechado, o salário previsto é bruto e a parcela descontada sai dele; paga à parte, ela sai da
 * conta. Crédito, adiantamento, poupança e investimentos não entram: são movimentações (o balanço inclui).
 * Base, que vai no nome:
 *   'hoje'     "Sobra até hoje": só o que foi lançado, com o mês tratado como fechado;
 *   'prevista' "Sobra prevista": o mês inteiro, com o previsto (a linha do plano, a mesma da previsão do fim do mês);
 *   'mes'      "Sobra do mês": mês fechado, em que as duas são a mesma.
 */
final class Sobra
{
    /**
     * A conta de uma linha do motor. `bruto` é a renda como aparece nas listas e `parcela` o que vai para o
     * empréstimo: juntos são a ponte "8.000 − 289,90 de parcela".
     * @return array{bruto:float, parcela:float, renda:float, saidas:float, sobra:float}
     */
    public static function de(array $linha): array
    {
        $descontada = (float) ($linha['parcela_descontada'] ?? 0.0);
        $parcela = round($descontada + (float) ($linha['parcela_parte'] ?? 0.0), 2);
        $bruto   = round((float) $linha['entra'] + $descontada, 2);
        $renda   = round($bruto - $parcela, 2);
        $saidas  = round((float) $linha['sai'], 2);
        return ['bruto' => $bruto, 'parcela' => $parcela, 'renda' => $renda, 'saidas' => $saidas, 'sobra' => round($renda - $saidas, 2)];
    }

    /**
     * A sobra prevista: da linha do plano (num mês fechado, a sobra do mês).
     * @return array{bruto:float, parcela:float, renda:float, saidas:float, sobra:float, base:string}
     */
    public static function prevista(array $linhaPlano): array
    {
        return self::de($linhaPlano) + ['base' => ($linhaPlano['situacao'] ?? '') === 'REAL' ? 'mes' : 'prevista'];
    }

    /**
     * A sobra até hoje: só o que foi lançado no mês (o motor de novo, com o mês tratado como fechado; nada é gravado).
     * Num mês fechado, é a sobra do mês. null = mês fora do plano.
     * @return ?array{bruto:float, parcela:float, renda:float, saidas:float, sobra:float, base:string}
     */
    public static function ateHoje(string $mes, ?string $hoje = null): ?array
    {
        $opts = ['tratarComoReal' => [$mes]];
        if ($hoje !== null) {
            $opts['hoje'] = $hoje;
        }
        $linha = MotorFinanceiro::calcular(MotorFinanceiro::dados(), $opts)[$mes] ?? null;
        return $linha === null ? null : self::de($linha) + ['base' => ($linha['situacao'] ?? '') === 'REAL' ? 'mes' : 'hoje'];
    }

    /** O nome com a base: "Sobra até hoje", "Sobra prevista" ou "Sobra do mês". */
    public static function rotulo(string $base): string
    {
        return match ($base) {
            'hoje'     => t('Sobra até hoje'),
            'prevista' => t('Sobra prevista'),
            default    => t('Sobra do mês'),
        };
    }

    /** A ponte da renda bruta para a usada na sobra: "8.000,00 − 289,90 de parcela". null sem parcela no mês. */
    public static function ponte(array $s): ?string
    {
        if ((float) $s['parcela'] < 0.005) {
            return null;
        }
        return t('{bruto} − {parcela} de parcela', ['bruto' => num((float) $s['bruto']), 'parcela' => num((float) $s['parcela'])]);
    }
}
