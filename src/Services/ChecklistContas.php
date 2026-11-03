<?php
namespace Financeiro\Services;

use Financeiro\Models\ContaRecorrenteModel;
use Financeiro\Models\LancamentoModel;
use Financeiro\DiaUtil;
use Financeiro\Models\PrevisaoModel;
use Financeiro\Relogio;

/**
 * Checklist de contas recorrentes — 100% derivado (nunca marcado à mão):
 * "existe" vem da Previsão, "pago" vem da soma de Lançamentos daquela categoria no mês. A Fatura do
 * cartão é a exceção: "pago" é o que foi pago da fatura que vence no mês, mesmo antes dele
 * (CartaoFatura::atribuir), e o vencimento é o do cartão. Vencimento em dia que não é útil só atrasa depois do
 * próximo dia útil (DiaUtil): 'paga_ate' é esse dia, e é ele que decide atrasado e pago com atraso.
 */
final class ChecklistContas
{
    /**
     * @param ?string $hoje 'Y-m-d' (padrão: hoje) — define o que está atrasado.
     * @return list<array{categoria_id:int,categoria_nome:string,grupo:string,dia_vencimento:int,vencimento:string,
     *   paga_ate:string,previsto:float,pago:float,data_pagamento:?string,status:string}>
     *   status: inexistente | pago_parcial | pago_atrasado | pago | atrasado | pendente
     */
    public static function statusPorMes(string $mes, ?string $hoje = null): array
    {
        $hoje = new \DateTimeImmutable($hoje ?? Relogio::hoje());
        $hoje = $hoje->setTime(0, 0);
        $resultado = [];
        $fatura = MotorFinanceiro::dados()['fatura'];

        foreach (ContaRecorrenteModel::ativas() as $conta) {
            $previsto = PrevisaoModel::somaPorCategoriaNome($conta['categoria_nome'], $mes);
            if ((int) $conta['categoria_id'] === $fatura['cat']) {
                $conta['dia_vencimento'] = $fatura['vence'];
                $pagoFatura    = $fatura['faturas'][$mes] ?? null;
                $pago          = (float) ($pagoFatura['pago'] ?? 0.0);
                $dataPagamento = $pagoFatura['primeiro'] ?? null;
            } else {
                $pago          = LancamentoModel::somaPorCategoriaNome($conta['categoria_nome'], $mes);
                $dataPagamento = $pago > 0 ? LancamentoModel::primeiraDataPorCategoriaNome($conta['categoria_nome'], $mes) : null;
            }
            $existe   = $previsto > 0;

            $vencimento = self::dataVencimento($mes, (int) $conta['dia_vencimento']);
            $pagaAte    = new \DateTimeImmutable(DiaUtil::proximoUtil($vencimento->format('Y-m-d')));
            $atrasado   = $existe && $pago <= 0 && $pagaAte < $hoje;

            $pagoAtrasado  = $pago > 0 && $dataPagamento !== null && new \DateTimeImmutable($dataPagamento) > $pagaAte;
            // veio menos que o previsto: parcial até a pessoa dizer, no Mês, que esse foi o valor final
            // (a previsão do mês passa a ser o pago) ou que ainda falta pagar
            $pagoParcial   = $existe && PrevisaoEdicao::veioMenos($pago, $previsto);

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
                'paga_ate'       => $pagaAte->format('Y-m-d'),
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

    /**
     * As contas fixas do mês de hoje que ainda não foram pagas: o bloco "Contas do mês" do Início e do Extrato,
     * e o que o botão Pago aceita. A regra do "Não pago" do Mês: categoria ativa do grupo Fixos, previsão do mês
     * maior que zero (a mesma do Mês e do plano: MotorFinanceiro::dados) e nada lançado nela no mês. Conta que já
     * recebeu algum lançamento fica de fora, mesmo menor que o previsto: o Mês pergunta se foi o valor final.
     * Só com o mês de hoje aberto; fechado ou fora do plano, a lista é vazia.
     * Ordem do Mês: vencidas, depois pelo vencimento, as sem vencimento cadastrado no fim, e o nome.
     * @param ?string $hoje 'Y-m-d' (padrão: hoje)
     * @return list<array{categoria_id:int, nome:string, apelido:?string, grupo:string, previsto:float, vencimento:?string,
     *   paga_ate:?string, atrasado:bool}> paga_ate: o vencimento, ou o próximo dia útil se ele cai num dia que não é (DiaUtil)
     */
    public static function fixasAPagar(?string $hoje = null): array
    {
        $hoje ??= Relogio::hoje();
        $mes = substr($hoje, 0, 8) . '01';
        $d = MotorFinanceiro::dados();
        $aberto = false;
        foreach ($d['meses'] as $m) {
            if ($m['mes'] === $mes) {
                $aberto = $m['situacao'] === 'PREVISAO';
            }
        }
        if (!$aberto) {
            return [];
        }
        $dias = [];
        foreach (ContaRecorrenteModel::ativas() as $conta) {
            $dias[(int) $conta['categoria_id']] = (int) $conta['dia_vencimento'];
        }
        $itens = [];
        foreach ($d['cats'] as $id => $c) {
            $previsto = round((float) ($d['prev'][$mes][$id] ?? 0.0), 2);
            if ($c['grupo'] !== 'Fixos' || $c['ativo'] !== 1 || $previsto <= 0.004 || (float) ($d['real'][$mes][$id] ?? 0.0) > 0.004) {
                continue;
            }
            $vence = isset($dias[$id]) ? self::dataVencimento($mes, $dias[$id])->format('Y-m-d') : null;
            $pagaAte = $vence !== null ? DiaUtil::proximoUtil($vence) : null;    // como no Mês: atrasa depois do dia útil
            $itens[] = [
                'categoria_id' => $id, 'nome' => $c['nome'], 'apelido' => $c['apelido'], 'grupo' => $c['grupo'],
                'previsto' => $previsto, 'vencimento' => $vence, 'paga_ate' => $pagaAte, 'atrasado' => $pagaAte !== null && $pagaAte < $hoje,
            ];
        }
        usort($itens, static fn(array $a, array $b): int =>
            [$a['atrasado'] ? 0 : 1, $a['vencimento'] === null ? 1 : 0, (string) $a['vencimento'], $a['nome']]
            <=> [$b['atrasado'] ? 0 : 1, $b['vencimento'] === null ? 1 : 0, (string) $b['vencimento'], $b['nome']]);
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
