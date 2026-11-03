<?php
namespace Financeiro\Services;

use Financeiro\Relogio;

/**
 * A sobra de um mês: a mesma conta e o mesmo nome em todas as telas (Início, Mês, Fechar e Relatório).
 * Conta: tudo o que entrou na conta menos tudo o que saiu, o mesmo balanço do Início (MotorFinanceiro::balancoTotal).
 * Crédito e resgates entram; adiantamento, poupança, investimentos e a parcela paga à parte saem. Foi decisão do
 * dono: "balanço é balanço" (docs/decisoes.md). A parcela descontada do salário não passa pela conta: ela só aparece
 * na ponte "8.000 − 289,90 de parcela", do bruto para o que entrou.
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
     * renda/saidas: o que é renda e gasto (as listas do Mês); entrou/saiu: tudo o que entrou e saiu da conta (o Início).
     * @return array{bruto:float, parcela:float, renda:float, saidas:float, mov:float, entrou:float, saiu:float, sobra:float}
     */
    public static function de(array $linha): array
    {
        $linha += ['entra' => 0.0, 'sai' => 0.0, 'credito_emprestimo' => 0.0, 'amortizacao_extra' => 0.0, 'poupanca_liquido' => 0.0,
                   'invest_liquido' => 0.0, 'parcela_parte' => 0.0, 'amortizacao_auto' => 0.0];
        // o adiantamento que o plano sugere sozinho ainda não saiu da conta: a frase do saldo, no Início, fala dele
        $linha['amortizacao_extra'] = max(0.0, (float) $linha['amortizacao_extra'] - (float) $linha['amortizacao_auto']);
        [$entradas, $saidas] = MotorFinanceiro::balancoTotal($linha);
        $descontada = round((float) ($linha['parcela_descontada'] ?? 0.0), 2);
        $renda = round((float) $linha['entra'], 2);
        $sai = round((float) $linha['sai'], 2);
        // o que não é renda nem gasto: crédito e resgates (+); adiantamento, poupança, investimentos e parcela paga à parte (−)
        $mov = round($entradas - $saidas - ($renda - $sai), 2);
        return ['bruto' => round($renda + $descontada, 2), 'parcela' => $descontada, 'renda' => $renda, 'saidas' => $sai,
                'mov' => $mov, 'entrou' => round($entradas, 2), 'saiu' => round($saidas, 2), 'sobra' => round($entradas - $saidas, 2)];
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
     * A sobra até hoje: só o que foi lançado no mês até hoje (o motor de novo, com o mês tratado como fechado; nada é
     * gravado). O lançamento agendado, com data depois de hoje, fica de fora até o dia dele. Num mês fechado, é a sobra
     * do mês. null = mês fora do plano.
     * @return ?array{bruto:float, parcela:float, renda:float, saidas:float, sobra:float, base:string}
     */
    public static function ateHoje(string $mes, ?string $hoje = null): ?array
    {
        $opts = ['tratarComoReal' => [$mes]];
        if ($hoje !== null) {
            $opts['hoje'] = $hoje;
        }
        $linha = MotorFinanceiro::calcular(MotorFinanceiro::comRealAte(MotorFinanceiro::dados(), $mes, $hoje ?? Relogio::hoje()), $opts)[$mes] ?? null;
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
