<?php
namespace Financeiro\Services;

use Financeiro\Models\ContaRecorrenteModel;
use Financeiro\Models\LancamentoModel;
use Financeiro\Models\PrevisaoModel;

/**
 * Checklist de contas recorrentes — 100% derivado (nunca marcado à mão):
 * "existe" vem da Previsão, "pago" vem da soma de Lançamentos daquela categoria no mês.
 */
final class ChecklistContas
{
    /**
     * @param ?string $hoje 'Y-m-d' (padrão: hoje) — define o que está atrasado.
     * @return list<array{categoria_id:int,categoria_nome:string,grupo:string,dia_vencimento:int,vencimento:string,
     *   previsto:float,pago:float,data_pagamento:?string,status:string}>
     *   status: inexistente | pago_parcial | pago_atrasado | pago | atrasado | pendente
     */
    public static function statusPorMes(string $mes, ?string $hoje = null): array
    {
        $hoje = new \DateTimeImmutable($hoje ?? 'today');
        $hoje = $hoje->setTime(0, 0);
        $resultado = [];

        foreach (ContaRecorrenteModel::ativas() as $conta) {
            $previsto = PrevisaoModel::somaPorCategoriaNome($conta['categoria_nome'], $mes);
            $pago     = LancamentoModel::somaPorCategoriaNome($conta['categoria_nome'], $mes);
            $existe   = $previsto > 0;

            $vencimento = self::dataVencimento($mes, (int) $conta['dia_vencimento']);
            $atrasado   = $existe && $pago <= 0 && $vencimento < $hoje;

            $dataPagamento = $pago > 0 ? LancamentoModel::primeiraDataPorCategoriaNome($conta['categoria_nome'], $mes) : null;
            $pagoAtrasado  = $pago > 0 && $dataPagamento !== null && new \DateTimeImmutable($dataPagamento) > $vencimento;
            // menos de 90% do previsto lançado ainda conta como "faltando pagar o resto"
            $pagoParcial   = $pago > 0 && $existe && $pago < $previsto * 0.9;

            $status = match (true) {
                !$existe        => 'inexistente',
                $pagoParcial    => 'pago_parcial',
                $pagoAtrasado   => 'pago_atrasado',
                $pago > 0       => 'pago',
                $atrasado       => 'atrasado',
                default         => 'pendente',
            };

            $resultado[] = [
                'categoria_id'   => (int) $conta['categoria_id'],
                'categoria_nome' => $conta['categoria_nome'],
                'grupo'          => $conta['grupo'],
                'dia_vencimento' => (int) $conta['dia_vencimento'],
                'vencimento'     => $vencimento->format('Y-m-d'),
                'previsto'       => $previsto,
                'pago'           => $pago,
                'data_pagamento' => $dataPagamento,
                'status'         => $status,
            ];
        }

        return $resultado;
    }

    /**
     * O que ainda falta pagar no mês: status pendente | atrasado | pago_parcial, na ordem
     * atrasado → vencimento → nome. Cada item ganha 'falta' = max(0, previsto − pago).
     */
    public static function aPagar(string $mes, ?string $hoje = null): array
    {
        $itens = array_values(array_filter(
            self::statusPorMes($mes, $hoje),
            fn($c) => in_array($c['status'], ['pendente', 'atrasado', 'pago_parcial'], true)
        ));
        foreach ($itens as &$c) {
            $c['falta'] = max(0.0, round($c['previsto'] - $c['pago'], 2));
        }
        unset($c);
        usort($itens, fn($a, $b) =>
            [$a['status'] === 'atrasado' ? 0 : 1, $a['vencimento'], $a['categoria_nome']]
            <=> [$b['status'] === 'atrasado' ? 0 : 1, $b['vencimento'], $b['categoria_nome']]
        );
        return $itens;
    }

    private static function dataVencimento(string $mes, int $diaVencimento): \DateTimeImmutable
    {
        $inicio = new \DateTimeImmutable($mes);
        $ultimoDiaDoMes = (int) $inicio->format('t');
        $dia = min($diaVencimento, $ultimoDiaDoMes);
        return $inicio->setDate((int) $inicio->format('Y'), (int) $inicio->format('n'), $dia);
    }
}
