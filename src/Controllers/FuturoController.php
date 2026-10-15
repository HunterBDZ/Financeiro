<?php
namespace Financeiro\Controllers;

use Financeiro\Auth;
use Financeiro\Models\MesModel;
use Financeiro\Services\MotorFinanceiro;
use Financeiro\View;

/**
 * Anual (SPEC §2.7): a conta mês a mês pelo plano do motor — linha do saldo projetado e a tabela
 * entradas / saídas / balanço. "balanço" só de MotorFinanceiro::balancoTotal().
 */
final class FuturoController
{
    public function index(): void
    {
        Auth::requireLogin();
        $hoje  = date('Y-m-d');
        $plano = MotorFinanceiro::calcularPlano();
        if ($plano === []) {
            View::render('dashboard/vazio', ['pageTitle' => 'anual · financeiro'], 'futuro');
            return;
        }
        $aj      = MotorFinanceiro::dados()['aj'];
        $reserva = (float) ($aj['reserva_minima'] ?? 0.0);
        $meta    = (float) ($aj['meta_final'] ?? 0.0);
        $meses   = array_keys($plano);

        $linhas = [];
        foreach ($plano as $m => $l) {
            [$entradas, $saidas, $balanco] = MotorFinanceiro::balancoTotal($l);
            $fica = (float) $l['fica_na_conta'];
            $linhas[] = [
                'mes' => $m, 'estado' => $l['estado'] ?? estadoMes($m, $l['situacao'], $hoje),
                'entra' => round($entradas, 2), 'entra_bruto' => (float) $l['entra_bruto'],
                'consignado' => (float) $l['parcela_consignado'],
                'sai' => round($saidas, 2), 'amort' => (float) $l['amortizacao_extra'],
                'cred' => (float) $l['credito_emprestimo'], 'poup' => -(float) $l['poupanca_liquido'],
                'bal' => $balanco, 'fica' => $fica, 'divida' => max(0.0, (float) $l['falta_pagar']),
                'alerta' => $fica < 0 ? 'neg' : (round($fica, 2) < $reserva - 0.004 ? 'warn' : ''),
            ];
        }

        View::render('futuro/index', [
            'pageTitle' => 'anual · financeiro',
            'hoje' => $hoje, 'ref' => MesModel::referencia($hoje), 'ultimo' => (string) end($meses),
            'reserva' => $reserva, 'meta' => $meta, 'linhas' => $linhas,
            'quita' => MotorFinanceiro::mesQueEmprestimoQuita(), 'bate' => MotorFinanceiro::mesQueBateMeta(),
        ], 'futuro');
    }
}
