<?php
namespace Financeiro\Controllers;

use Financeiro\Auth;
use Financeiro\Models\MesModel;
use Financeiro\Services\AnualResumo;
use Financeiro\Services\MotorFinanceiro;
use Financeiro\View;

/**
 * Anual (SPEC §2.7): a conta mês a mês pelo plano do motor — linha do saldo projetado e a tabela
 * entradas / saídas / balanço. "balanço" só de MotorFinanceiro::balancoTotal(). Os marcos do plano
 * e o "o que mudou" de cada mês vêm de AnualResumo.
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
        $dados   = MotorFinanceiro::dados();
        $aj      = $dados['aj'];
        $reserva = (float) ($aj['reserva_minima'] ?? 0.0);
        $meta    = (float) ($aj['meta_final'] ?? 0.0);
        $meses   = array_keys($plano);
        $ref     = MesModel::referencia($hoje);
        $mudou   = AnualResumo::mudancas($plano, $dados);

        $linhas = [];
        foreach ($plano as $m => $l) {
            [$entradas, $saidas, $balanco] = MotorFinanceiro::balancoTotal($l);
            $fica = (float) $l['fica_na_conta'];
            $linhas[] = [
                'mes' => $m, 'estado' => $l['estado'] ?? estadoMes($m, $l['situacao'], $hoje),
                'entra' => round($entradas, 2), 'entra_bruto' => (float) $l['entra_bruto'],
                'consignado' => (float) $l['parcela_consignado'],
                'sai' => round($saidas, 2), 'gastos' => (float) $l['sai'], 'amort' => (float) $l['amortizacao_extra'],
                'sobra' => (float) $l['resultado_op'], 'mudou' => $mudou[$m] ?? [],
                'cred' => (float) $l['credito_emprestimo'], 'poup' => -(float) $l['poupanca_liquido'],
                'bal' => $balanco, 'fica' => $fica, 'divida' => max(0.0, (float) $l['falta_pagar']),
                'alerta' => $fica < 0 ? 'neg' : (round($fica, 2) < $reserva - 0.004 ? 'warn' : ''),
            ];
        }

        View::render('futuro/index', [
            'pageTitle' => 'anual · financeiro',
            'hoje' => $hoje, 'ref' => $ref, 'ultimo' => (string) end($meses),
            'marcos' => AnualResumo::marcos($plano, $reserva, $ref),
            'reserva' => $reserva, 'meta' => $meta, 'linhas' => $linhas,
            'quita' => MotorFinanceiro::mesQueEmprestimoQuita(), 'bate' => MotorFinanceiro::mesQueBateMeta(),
        ], 'futuro');
    }
}
