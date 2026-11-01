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
 *
 * "E se": a simulação vem na URL (GET, ?se=1&mensal=…), recalcula o plano com o motor
 * (opção extra/objetivo) e a página inteira mostra o simulado, comparado com o plano de verdade.
 * Nada é gravado.
 */
final class FuturoController
{
    /** Teto de cada valor simulado (evita número sem sentido na URL). */
    private const MAX_SIMULADO = 1000000.0;

    public function index(): void
    {
        Auth::requireLogin();
        $hoje  = date('Y-m-d');
        $base  = MotorFinanceiro::calcularPlano();
        if ($base === []) {
            View::render('dashboard/vazio', ['pageTitle' => t('anual · financeiro')], 'futuro');
            return;
        }
        $dados    = MotorFinanceiro::dados();
        $aj       = $dados['aj'];
        $reserva  = (float) ($aj['reserva_minima'] ?? 0.0);
        $meta     = (float) ($aj['meta_final'] ?? 0.0);
        $parcela  = (float) ($aj['parcela_mensal'] ?? 0.0);
        $jurosMes = (float) ($aj['juros_mes'] ?? 0.0);
        $meses    = array_keys($base);
        $ref      = MesModel::referencia($hoje);
        $adiantaPadrao = (int) round((float) ($aj['objetivo'] ?? 1)) !== 3;

        $se = self::simulacao($base, $ref, $adiantaPadrao);
        $plano = $base;
        $semAdiantar = $adiantaPadrao ? MotorFinanceiro::calcular($dados, ['objetivo' => 3]) : [];
        if ($se['ativo']) {
            $opts = ['extra' => $se['extra']];
            if ($se['adiantar'] !== $adiantaPadrao) {
                $opts['objetivo'] = $se['adiantar'] ? 1 : 3;
            }
            $plano = MotorFinanceiro::calcular($dados, $opts);
            $semAdiantar = $se['adiantar'] ? MotorFinanceiro::calcular($dados, ['extra' => $se['extra'], 'objetivo' => 3]) : [];
        }
        $mudou = AnualResumo::mudancas($plano, $dados);
        $ganho = AnualResumo::ganho($plano, $semAdiantar, $parcela, $jurosMes);

        $linhas = [];
        foreach ($plano as $m => $l) {
            [$entradas, $saidas, $balanco] = MotorFinanceiro::balancoTotal($l);
            $fica = (float) $l['fica_na_conta'];
            $linhas[] = [
                'mes' => $m, 'estado' => $l['estado'] ?? estadoMes($m, $l['situacao'], $hoje),
                'antes' => (float) $l['sobrou_mes_anterior'], 'entra_op' => (float) $l['entra'],
                'entra' => round($entradas, 2), 'entra_bruto' => (float) $l['entra_bruto'],
                'consignado' => (float) $l['parcela_consignado'],
                'sai' => round($saidas, 2), 'gastos' => (float) $l['sai'], 'amort' => (float) $l['amortizacao_extra'],
                'sobra' => (float) $l['resultado_op'], 'mudou' => $mudou[$m] ?? [], 'simulado' => (float) ($se['extra'][$m] ?? 0.0),
                'cred' => (float) $l['credito_emprestimo'], 'poup' => -(float) $l['poupanca_liquido'], 'inv' => -(float) ($l['invest_liquido'] ?? 0.0), 'parte' => (float) ($l['parcela_parte'] ?? 0.0),
                'bal' => $balanco, 'fica' => $fica, 'divida' => max(0.0, (float) $l['falta_pagar']),
                'base_fica' => (float) $base[$m]['fica_na_conta'],
                'alerta' => $fica < 0 ? 'neg' : (round($fica, 2) < $reserva - 0.004 ? 'warn' : ''),
            ];
        }
        // a tabela e o gráfico mostram de um ano atrás em diante; o histórico inteiro fica no Mês
        $desde = date('Y-m-01', strtotime($ref . ' -12 month'));
        $linhas = array_values(array_filter($linhas, static fn(array $l): bool => $l['mes'] >= $desde));
        $metaInt = (int) round((float) ($aj['meta_mes'] ?? 0.0));
        $metaMes = $metaInt > 0 ? sprintf('%04d-%02d-01', intdiv($metaInt, 100), $metaInt % 100) : null;
        $quitacao = AnualResumo::quitacao($plano, $parcela, $jurosMes);
        $bate = null;
        foreach ($plano as $m => $l) {
            if (!empty($l['bateu_meta'])) {
                $bate = (string) $m;
                break;
            }
        }

        View::render('futuro/index', [
            'pageTitle' => t('anual · financeiro'),
            'hoje' => $hoje, 'ref' => $ref, 'ultimo' => (string) end($meses),
            'marcos' => AnualResumo::marcos($plano, $reserva, $ref), 'ganho' => $ganho,
            'reserva' => $reserva, 'meta' => $meta, 'metaMes' => $metaMes, 'linhas' => $linhas,
            'quita' => ($quitacao['noPlano'] ?? false) ? $quitacao['mes'] : null, 'bate' => $bate,
            'se' => $se,
            'comparar' => $se['ativo'] ? [
                'plano' => AnualResumo::numeros($base, $reserva, $ref, $parcela, $jurosMes),
                'se'    => AnualResumo::numeros($plano, $reserva, $ref, $parcela, $jurosMes),
            ] : null,
            'temDivida' => (float) ($base[$ref]['falta_pagar'] ?? reset($base)['falta_pagar']) > 0.004,
        ], 'futuro');
    }

    /**
     * Lê o "e se" da URL. Só vale com se=1 (o formulário manda); valor inválido é ignorado e
     * avisado; os meses têm de ser do plano, a partir do mês de referência e fora de mês fechado.
     * @return array{ativo:bool, mensal:float, mensalTipo:string, mensalDe:string, uma:float, umaTipo:string,
     *               umaMes:string, adiantar:bool, extra:array<string,float>, meses:list<string>, erros:list<string>}
     */
    public static function simulacao(array $plano, string $ref, bool $adiantaPadrao): array
    {
        $meses = [];
        foreach ($plano as $m => $l) {
            if ((string) $m >= $ref && ($l['estado'] ?? '') !== 'real') {
                $meses[] = (string) $m;
            }
        }
        $padraoMes = $meses[1] ?? ($meses[0] ?? $ref);   // o mês que vem (o atual já está andando)
        $erros = [];
        $valor = static function (string $campo, string $nome) use (&$erros): float {
            $bruto = trim((string) ($_GET[$campo] ?? ''));
            if ($bruto === '') {
                return 0.0;
            }
            $v = parseMoedaBr($bruto);
            if ($v === null || $v < 0 || $v > self::MAX_SIMULADO) {
                $erros[] = t('Valor inválido em "{campo}": use um número como 500 ou 1.250,00.', ['campo' => $nome]);
                return 0.0;
            }
            return round($v, 2);
        };
        $mesDe = static fn(string $campo): string => in_array((string) ($_GET[$campo] ?? ''), $meses, true) ? (string) $_GET[$campo] : $padraoMes;

        $enviado = ($_GET['se'] ?? '') === '1';
        $s = [
            'mensal' => $enviado ? $valor('mensal', t('todo mês')) : 0.0,
            'mensalTipo' => ($_GET['mensal_tipo'] ?? '') === 'gastar' ? 'gastar' : 'economizar',
            'mensalDe' => $mesDe('mensal_de'),
            'uma' => $enviado ? $valor('uma', t('uma vez')) : 0.0,
            'umaTipo' => ($_GET['uma_tipo'] ?? '') === 'entrada' ? 'entrada' : 'gasto',
            'umaMes' => $mesDe('uma_mes'),
            'adiantar' => $enviado ? isset($_GET['adiantar']) : $adiantaPadrao,
            'meses' => $meses,
        ];
        $extra = [];
        foreach ($meses as $m) {
            if ($s['mensal'] > 0 && $m >= $s['mensalDe']) {
                $extra[$m] = ($extra[$m] ?? 0.0) + ($s['mensalTipo'] === 'economizar' ? $s['mensal'] : -$s['mensal']);
            }
            if ($s['uma'] > 0 && $m === $s['umaMes']) {
                $extra[$m] = ($extra[$m] ?? 0.0) + ($s['umaTipo'] === 'entrada' ? $s['uma'] : -$s['uma']);
            }
        }
        $s['extra'] = $extra;
        $s['erros'] = $erros;
        $s['ativo'] = $enviado && ($extra !== [] || $s['adiantar'] !== $adiantaPadrao);
        return $s;
    }
}
