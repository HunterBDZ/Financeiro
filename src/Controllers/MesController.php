<?php
namespace Financeiro\Controllers;

use Financeiro\Auth;
use Financeiro\Database;
use Financeiro\Models\LancamentoModel;
use Financeiro\Models\MesModel;
use Financeiro\Models\MetaModel;
use Financeiro\Models\SaldoConferenciaModel;
use Financeiro\Services\ChecklistContas;
use Financeiro\Services\MotorFinanceiro;
use Financeiro\Services\PrevisaoLote;
use Financeiro\Services\SugestaoAlocacao;
use Financeiro\View;

/**
 * Mês (SPEC §2.5) e Fechar mês (§2.6). A previsão só é gravada por PrevisaoLote::gravar()
 * (invariante 7); desfazer e reabrir ficam no menu ⋯ do mês.
 */
final class MesController
{
    public const OBJETIVOS = [1 => 'os dois', 3 => 'só a meta', 2 => 'só quitar'];

    /* ================================================================== GET /mes */

    public function index(): void
    {
        Auth::requireLogin();
        $hoje  = date('Y-m-d');
        $meses = MesModel::todos();
        if ($meses === []) {
            View::render('dashboard/vazio', ['pageTitle' => 'mês · financeiro'], 'mes');
            return;
        }
        $mes      = mesValido($_GET['mes'] ?? null, $meses, MesModel::referencia($hoje));
        $situacao = self::situacaoEm($meses, $mes);
        $estado   = estadoMes($mes, $situacao, $hoje);
        $editavel = $situacao === 'PREVISAO';
        $mesCal   = substr($hoje, 0, 7) . '-01';
        $ultimo   = (string) end($meses)['mes'];

        $d     = MotorFinanceiro::dados();
        $plano = MotorFinanceiro::calcularPlano();
        $linha = $plano[$mes];
        $prev  = $d['prev'][$mes] ?? [];
        $real  = $d['real'][$mes] ?? [];
        $cats  = $d['cats'];
        $metas = MetaModel::todas();
        $saldoIni = (float) ($d['aj']['saldo_ini'] ?? 0.0);
        $reserva  = (float) ($d['aj']['reserva_minima'] ?? 0.0);

        // em conta: saldo real pelos lançamentos até hoje (mês corrente ou passado); projeção do motor (mês futuro)
        $projetado = $mes > $mesCal;
        $emConta = $projetado
            ? (float) $linha['fica_na_conta']
            : LancamentoModel::saldoCalculado($saldoIni, min($hoje, date('Y-m-t', strtotime($mes))));

        $status = [];
        foreach (ChecklistContas::statusPorMes($mes, $hoje) as $s) {
            $status[(int) $s['categoria_id']] = $s;
        }

        $dia = $diaZeradas = $contasLinhas = $entradas = [];
        foreach ($cats as $id => $c) {
            $p = round((float) ($prev[$id] ?? 0.0), 2);
            $r = round((float) ($real[$id] ?? 0.0), 2);
            $l = [
                'id' => $id, 'nome' => nomeCurto($c), 'nome_completo' => $c['nome'], 'grupo' => $c['grupo'],
                'essencial' => (bool) ($c['essencial'] ?? true), 'prev' => $p, 'real' => $r, 'ativo' => $c['ativo'] === 1,
                'meta' => $metas[$id] ?? null,
            ];
            $temValor = $p > 0.004 || $r > 0.004;
            if (!$temValor && !$l['ativo']) {
                continue;
            }
            switch ($c['grupo']) {
                case 'Dia a dia':
                    if ($temValor) {
                        $dia[] = $l;
                    } else {
                        $diaZeradas[] = $l;
                    }
                    break;
                case 'Fixos':
                case 'Cartao':
                case 'Ano':
                    if (!$temValor) {
                        break;
                    }
                    $s = $status[$id] ?? null;
                    $l['recorrente'] = $s !== null;
                    $l['status'] = $s['status'] ?? null;
                    $l['vencimento'] = $s['vencimento'] ?? null;
                    $l['pago'] = $s !== null ? round((float) $s['pago'], 2) : $r;
                    $contasLinhas[] = $l;
                    break;
                case 'Entradas':
                    if ($l['ativo'] || $temValor) {
                        $entradas[] = $l;
                    }
                    break;
            }
        }

        // ordem das contas: atrasadas → a vencer (por data) → sem vencimento; pagas à parte
        $pagas = $abertas = [];
        foreach ($contasLinhas as $l) {
            if ($editavel && in_array($l['status'], ['pago', 'pago_atrasado'], true)) {
                $pagas[] = $l;
            } else {
                $abertas[] = $l;
            }
        }
        $ordem = static fn(array $l): array => [
            $l['status'] === 'atrasado' ? 0 : ($l['recorrente'] && $l['status'] !== 'inexistente' ? 1 : 2),
            $l['recorrente'] ? (string) $l['vencimento'] : '',
            array_search($l['grupo'], ['Fixos', 'Cartao', 'Ano'], true), $l['nome'],
        ];
        if ($editavel) {
            usort($abertas, static fn($a, $b) => $ordem($a) <=> $ordem($b));
        }

        $menu = [
            'fechar'   => $editavel && $mes <= $mesCal,
            'reabrir'  => !$editavel,
            'desfazer' => PrevisaoLote::ultimoDesfazivel(),
        ];

        View::render('mes/index', [
            'pageTitle' => monthLabel($mes) . ' · mês · financeiro',
            'mes' => $mes, 'meses' => $meses, 'hoje' => $hoje, 'situacao' => $situacao, 'estado' => $estado,
            'editavel' => $editavel, 'ultimo' => $ultimo,
            'emConta' => round($emConta, 2), 'projetado' => $projetado, 'reserva' => $reserva,
            'dia' => $dia, 'diaZeradas' => $diaZeradas, 'contasAbertas' => $abertas, 'contasPagas' => $pagas,
            'entradas' => $entradas, 'linha' => $linha, 'menu' => $menu,
        ], 'mes');
    }

    /* ================================================================== POST /mes/desfazer */

    public function desfazerLote(): void
    {
        Auth::requireLogin();
        csrfCheck();

        $lote = is_string($_POST['lote'] ?? null) ? $_POST['lote'] : '';
        $mes  = self::mesPost();
        $volta = voltaSegura(is_string($_POST['volta'] ?? null) ? $_POST['volta'] : null, '/mes' . ($mes !== null ? '?mes=' . $mes : ''));
        if (!preg_match('/^[0-9a-f]{16}$/', $lote)) {
            flash('error', 'lote?');
            redirecionar($volta, 303);
        }
        try {
            $r = PrevisaoLote::desfazer($lote, (int) Auth::id());
        } catch (\InvalidArgumentException) {
            flash('error', 'lote?');
            redirecionar($volta, 303);
        } catch (\Throwable $e) {
            error_log('mes/desfazer: ' . $e->getMessage());
            flash('error', 'não desfez · tente de novo');
            redirecionar($volta, 303);
        }
        if (!empty($r['ja_desfeito'])) {
            flash('warn', 'já desfeito');
        } else {
            $n = (int) $r['restauradas'];
            $t = (int) $r['total'];
            flash($n === $t ? 'success' : 'warn', 'desfeito · ' . ($n === $t ? $n . ($n === 1 ? ' valor' : ' valores') : $n . ' de ' . $t));
        }
        redirecionar($volta, 303);
    }

    /* ================================================================== GET /mes/fechar */

    public function fecharForm(): void
    {
        Auth::requireLogin();
        $hoje   = date('Y-m-d');
        $meses  = MesModel::todos();
        $mesCal = substr($hoje, 0, 7) . '-01';
        // sem ?mes=: o mais antigo ainda em PREVISAO até o mês do calendário
        $padrao = $mesCal;
        foreach ($meses as $m) {
            if ($m['situacao'] === 'PREVISAO' && $m['mes'] <= $mesCal) {
                $padrao = (string) $m['mes'];
                break;
            }
        }
        if ($meses === []) {
            View::render('dashboard/vazio', ['pageTitle' => 'fechar mês · financeiro'], 'mes');
            return;
        }
        $mes = mesValido($_GET['mes'] ?? null, $meses, $padrao);
        if (!in_array($mes, array_column($meses, 'mes'), true)) {
            $mes = (string) ($meses[0]['mes'] ?? $mesCal);
        }
        $situacao = self::situacaoEm($meses, $mes);

        if ($mes > $mesCal) {
            http_response_code(400);
            View::render('mes/fechar', [
                'pageTitle' => 'fechar ' . monthLabel($mes) . ' · financeiro',
                'mes' => $mes, 'meses' => $meses, 'hoje' => $hoje, 'bloqueado' => true,
            ], 'mes');
            return;
        }

        $real = $situacao === 'REAL';
        $d = MotorFinanceiro::dados();
        // bloco 1: como ficará depois de fechado (em REAL já é o fechado)
        $plano = $real ? MotorFinanceiro::calcularPlano()
                       : MotorFinanceiro::calcular($d, ['hoje' => $hoje, 'tratarComoReal' => [$mes]]);
        if (!isset($plano[$mes])) {
            View::render('dashboard/vazio', ['pageTitle' => 'fechar mês · financeiro'], 'mes');
            return;
        }
        $linha = $plano[$mes];
        [$entrou, $saiu, $balanco] = MotorFinanceiro::balancoTotal($linha);

        // Parcela do consignado deste mês: sai da folha, então o soldo lançado (real) é o líquido;
        // a previsão do Salário é o bruto. Só a partir de ajustes.consignado_inicio (mesma regra do motor).
        $chaves = array_keys($plano);
        $pos = array_search($mes, $chaves, true);
        $loanIni = $pos > 0 ? (float) $plano[$chaves[$pos - 1]]['falta_pagar'] : (float) ($d['loanIni'] ?? 0.0);
        $iniParcela = MotorFinanceiro::mesInicioParcela($d['aj']);
        $consignado = ($loanIni > 0.01 && ($iniParcela === null || $mes >= $iniParcela))
            ? round(min((float) ($d['aj']['parcela_mensal'] ?? 0.0), $loanIni + (float) $linha['juros']), 2)
            : 0.0;

        // bloco 2: desvios (3 maiores |real − previsto| ≥ 10, só operacionais)
        $desvios = [];
        $avisoConsignado = null;
        foreach ($d['cats'] as $id => $c) {
            if ($c['operacional'] !== 1) {
                continue;
            }
            $r = round((float) ($d['real'][$mes][$id] ?? 0.0), 2);
            $p = round((float) ($d['prev'][$mes][$id] ?? 0.0), 2);
            if (($c['nome'] ?? '') === 'Salário' && $consignado > 0 && $p > 0) {
                // o soldo deveria chegar líquido da parcela; se chegou igual ao bruto previsto, avisa
                $pLiq = round(max(0.0, $p - $consignado), 2);
                if ($r > 0 && abs($r - $p) < 1.0) {
                    $avisoConsignado = 'o soldo entrou bruto (' . num($r) . '): a parcela do consignado (' . num($consignado)
                        . ') não foi descontada? Lance o soldo líquido ou ajuste a 1ª parcela em Ajustes.';
                }
                if (abs($r - $pLiq) < abs($r - $p)) {
                    $p = $pLiq;
                }
            }
            if (abs($r - $p) >= 10 - 0.005) {
                $desvios[] = ['nome' => nomeCurto($c), 'real' => $r, 'prev' => $p, 'dif' => $r - $p, 'sinal' => $c['sinal']];
            }
        }
        usort($desvios, static fn($a, $b) => abs($b['dif']) <=> abs($a['dif']));
        $desvios = array_slice($desvios, 0, 3);

        $fimMes = date('Y-m-t', strtotime($mes));
        $dataBanco = min($hoje, $fimMes);
        $faltam = (int) ((strtotime($fimMes) - strtotime($hoje)) / 86400);

        // bloco 4: prévia da sugestão do mês seguinte, como se este já estivesse fechado
        $prox = null;
        foreach ($meses as $m) {
            if ($m['mes'] > $mes) {
                $prox = $m['situacao'] === 'PREVISAO' ? (string) $m['mes'] : null;
                break;
            }
        }
        $previa = null;
        if (!$real && $prox !== null && MotorFinanceiro::v2()) {
            $obj = (int) round((float) ($d['aj']['objetivo'] ?? 1));
            $obj = isset(self::OBJETIVOS[$obj]) ? $obj : 1;
            $s = SugestaoAlocacao::calcular($prox, $obj, false, ['hoje' => $hoje, 'tratarComoReal' => [$mes]]);
            if ($s['meses'] !== []) {
                $diaTotal = array_sum($s['valores'][$prox] ?? []);
                $refMeses = $s['ref']['meses'];
                $previa = [
                    'mes' => $prox, 'obj' => $obj, 'dia' => $diaTotal, 'livre' => (float) ($s['niveis'][$prox] ?? 0.0),
                    // §2.6: o rótulo da base é o do mês que está sendo fechado (`base out`)
                    'base' => in_array($mes, $refMeses, true)
                        ? mesCurto($mes)
                        : (self::refRotulo($refMeses) ?? $s['rotulo_ref']),
                    'n' => count(SugestaoAlocacao::celulas($s, 'so', array_keys($s['mudancas']['so']))),
                ];
            }
        }

        $erro = $_SESSION['fe_erro'] ?? null;
        unset($_SESSION['fe_erro']);

        View::render('mes/fechar', [
            'pageTitle' => ($real ? monthLabel($mes) . ' · fechado' : 'fechar ' . monthLabel($mes)) . ' · financeiro',
            'mes' => $mes, 'meses' => $meses, 'hoje' => $hoje, 'bloqueado' => false, 'real' => $real,
            'entrou' => $entrou, 'saiu' => $saiu, 'saldoMes' => $balanco,
            'contaAntes' => (float) $linha['sobrou_mes_anterior'], 'contaDepois' => (float) $linha['fica_na_conta'],
            'desvios' => $desvios, 'avisoConsignado' => $avisoConsignado,
            'dataBanco' => $dataBanco, 'dataMin' => self::dataMinBanco($hoje), 'faltam' => max(0, $faltam),
            'previa' => $previa, 'erro' => is_array($erro) && ($erro['mes'] ?? '') === $mes ? $erro : null,
        ], 'mes');
    }

    /** Limite inferior da data do saldo do banco: o mesmo de /conferir (60 dias, nunca antes da véspera do 1º mês). */
    private static function dataMinBanco(string $hoje): string
    {
        $minimo = date('Y-m-d', strtotime('-60 days', strtotime($hoje)));
        $primeiro = MesModel::primeiro();
        if ($primeiro !== null) {
            $minimo = max($minimo, date('Y-m-d', strtotime('-1 day', strtotime($primeiro))));
        }
        return $minimo;
    }

    /* ================================================================== POST /mes/fechar */

    public function fechar(): void
    {
        Auth::requireLogin();
        csrfCheck();

        $hoje = date('Y-m-d');
        $mes = self::mesPost();
        if ($mes === null) {
            flash('error', 'mês?');
            redirecionar('/mes', 303);
        }
        $meses = MesModel::todos();
        $volta = '/mes/fechar?mes=' . $mes;
        if (self::situacaoEm($meses, $mes) === 'REAL') {
            redirecionar($volta, 303);                           // já fechado: nada a gravar
        }
        if ($mes > substr($hoje, 0, 7) . '-01') {
            flash('error', 'mês ainda não começou');
            redirecionar('/mes?mes=' . $mes, 303);
        }

        // saldo do banco (opcional)
        $saldoBruto = trim(is_string($_POST['saldo'] ?? null) ? $_POST['saldo'] : '');
        $saldo = null;
        $dataSaldo = min($hoje, date('Y-m-t', strtotime($mes)));
        if ($saldoBruto !== '') {
            $saldo = parseMoedaBr($saldoBruto);
            $erro = null;
            if ($saldo === null) {
                $erro = 'saldo?';
            } elseif (($_POST['quando'] ?? 'hoje') === 'outro') {
                $data = (string) ($_POST['data'] ?? '');
                $ts = preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) ? strtotime($data) : false;
                if ($ts === false || date('Y-m-d', $ts) !== $data || $data > $hoje || $data < self::dataMinBanco($hoje)) {
                    $erro = 'data?';
                } else {
                    $dataSaldo = $data;
                }
            }
            if ($erro !== null) {
                $_SESSION['fe_erro'] = ['mes' => $mes, 'msg' => $erro, 'saldo' => $saldoBruto];
                redirecionar($volta . '#banco', 303);
            }
        }

        $prox = null;
        foreach ($meses as $m) {
            if ($m['mes'] > $mes) {
                $prox = $m['situacao'] === 'PREVISAO' ? (string) $m['mes'] : null;
                break;
            }
        }
        $aplicar = ($_POST['aplicar'] ?? '') === '1' && $prox !== null && MotorFinanceiro::v2();

        // uma transação só (§2.6): conferência → REAL → limpa cache → sugestão do mês seguinte
        $pdo = Database::connection();
        $lote = null;
        try {
            $pdo->beginTransaction();
            if ($saldo !== null) {
                SaldoConferenciaModel::registrar($dataSaldo, round($saldo, 2), (int) Auth::id());
            }
            MesModel::setSituacao($mes, 'REAL');
            MotorFinanceiro::limparCache();
            if ($aplicar) {
                $obj = (int) round((float) (MotorFinanceiro::dados()['aj']['objetivo'] ?? 1));
                $obj = isset(self::OBJETIVOS[$obj]) ? $obj : 1;
                $s = SugestaoAlocacao::calcular($prox, $obj, false, ['hoje' => $hoje]);   // o mês fechado já conta
                $celulas = SugestaoAlocacao::celulas($s, 'so', array_keys($s['mudancas']['so']));
                $lote = PrevisaoLote::gravar('fechar', $celulas, (int) Auth::id());
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            MotorFinanceiro::limparCache();
            error_log('mes/fechar: ' . $e->getMessage());
            $_SESSION['fe_erro'] = ['mes' => $mes, 'msg' => 'não fechou · nada foi gravado', 'saldo' => $saldoBruto];
            redirecionar($volta, 303);
        }
        MotorFinanceiro::limparCache();

        flash('success', monthLabel($mes) . ' fechado' . ($lote !== null ? ' · ' . monthLabel($prox) . ' ajustado' : ''),
            ['url' => '/mes/reabrir', 'label' => 'reabrir', 'campos' => ['mes' => $mes]]);
        redirecionar('/', 303);
    }

    /* ================================================================== POST /mes/reabrir */

    public function reabrir(): void
    {
        Auth::requireLogin();
        csrfCheck();

        $mes = self::mesPost();
        if ($mes === null) {
            flash('error', 'mês?');
            redirecionar('/mes', 303);
        }
        $volta = voltaSegura(is_string($_POST['volta'] ?? null) ? $_POST['volta'] : null, '/mes?mes=' . $mes);
        if (self::situacaoEm(MesModel::todos(), $mes) === 'REAL') {
            MesModel::setSituacao($mes, 'PREVISAO');
            MotorFinanceiro::limparCache();
            flash('success', monthLabel($mes) . ' reaberto');
        } else {
            flash('warn', monthLabel($mes) . ' já está aberto');
        }
        redirecionar($volta, 303);
    }

    /* ================================================================== internos */

    private static function situacaoEm(array $meses, string $mes): string
    {
        foreach ($meses as $m) {
            if ($m['mes'] === $mes) {
                return (string) $m['situacao'];
            }
        }
        return 'PREVISAO';
    }

    /** `mes` do POST, só se for um mês cadastrado. */
    private static function mesPost(): ?string
    {
        $mes = $_POST['mes'] ?? null;
        if (!is_string($mes) || !preg_match('/^\d{4}-\d{2}-01$/', $mes)) {
            return null;
        }
        return in_array($mes, array_column(MesModel::todos(), 'mes'), true) ? $mes : null;
    }

    /**
     * Rótulo curto da referência, pelo nome dos meses: `set` (1 mês) ou `set–out` (média de 2+,
     * do primeiro ao último); null sem histórico.
     */
    public static function refRotulo(array $mesesRef): ?string
    {
        if ($mesesRef === []) {
            return null;
        }
        return count($mesesRef) === 1 ? mesCurto($mesesRef[0]) : mesCurto($mesesRef[0]) . '–' . mesCurto(end($mesesRef));
    }
}
