<?php
namespace Financeiro\Controllers;

use Financeiro\Auth;
use Financeiro\Config;
use Financeiro\Database;
use Financeiro\Models\AjusteModel;
use Financeiro\Models\LancamentoModel;
use Financeiro\Models\MesModel;
use Financeiro\Models\SaldoConferenciaModel;
use Financeiro\Services\GastosMensais;
use Financeiro\Services\MotorFinanceiro;
use Financeiro\View;

/**
 * Início (SPEC §2.1) e o gráfico "gastos mês a mês" (§3). Dono: P3.
 * Quanto ainda dá pra gastar? O que vence? A conta bate?
 */
final class DashboardController
{
    public function index(): void
    {
        Auth::requireLogin();

        $hoje = self::hoje();
        $ehHoje = $hoje === date('Y-m-d');
        $plano = $ehHoje
            ? MotorFinanceiro::calcularPlano()
            : MotorFinanceiro::calcular(MotorFinanceiro::dados(), ['hoje' => $hoje]);
        if ($plano === []) {
            View::render('dashboard/vazio', ['pageTitle' => t('início · financeiro')], 'inicio');
            return;
        }

        $meses    = MesModel::todos();
        $mesCal   = substr($hoje, 0, 7) . '-01';
        // a previsão olha o próximo mês aberto; o resumo fica no mês do calendário enquanto ele dura,
        // mesmo fechado antes do fim: o mês seguinte ainda não começou
        $mesPrev  = MesModel::referencia($hoje);
        if (!isset($plano[$mesPrev])) {
            $mesPrev = (string) array_key_last($plano);
        }
        $mesRef   = isset($plano[$mesCal]) && $plano[$mesCal]['situacao'] === 'REAL' ? $mesCal : $mesPrev;
        $linhaPrev = $plano[$mesPrev];
        $linhaRef = $plano[$mesRef];
        $estadoRef = estadoMes($mesRef, (string) $linhaRef['situacao'], $hoje);
        $reserva  = AjusteModel::valor('reserva_minima');
        $saldoIni = AjusteModel::valor('saldo_ini');
        $matriz   = GastosMensais::matriz($ehHoje ? null : $hoje);

        $conf  = self::conferencia($saldoIni, $hoje);

        // CONTA (invariante 4): fica_na_conta do mês do CALENDÁRIO quando ele está REAL;
        // senão, o saldo calculado pelos lançamentos até hoje.
        $linhaCal = $plano[$mesCal] ?? null;
        $conta = ($linhaCal !== null && $linhaCal['situacao'] === 'REAL')
            ? (float) $linhaCal['fica_na_conta']
            : LancamentoModel::saldoCalculado($saldoIni, $hoje);

        $painel = self::painel($matriz[$mesRef] ?? null, $mesRef, $estadoRef);
        $ultimos = LancamentoModel::listar($mesRef, ['limite' => 3]);

        // meses anteriores ao calendário ainda abertos: lembrete de fechar
        $alertas = [];
        foreach ($meses as $m) {
            if ((string) $m['mes'] < $mesCal && $m['situacao'] === 'PREVISAO') {
                $alertas[] = ['tipo' => 'fechar', 'mes' => (string) $m['mes']];
                break;
            }
        }

        // erro da conferência volta pelo ?conferir=erro#conferir (a mensagem fica na sessão)
        $conferirErro = null;
        $conferirAberto = isset($_GET['conferir']);
        if (($_GET['conferir'] ?? '') === 'erro' && is_array($_SESSION['conferir_erro'] ?? null)) {
            $conferirErro = $_SESSION['conferir_erro'];   // recarregar a página não repete o erro
        }
        unset($_SESSION['conferir_erro']);

        View::render('dashboard/index', [
            'pageTitle'      => t('início · financeiro'),
            'hoje'           => $hoje,
            'mesRef'         => $mesRef,
            'mesCal'         => $mesCal,
            'estadoRef'      => $estadoRef,
            'reserva'        => $reserva,
            'previsaoFim'    => $linhaPrev['situacao'] !== 'REAL' ? (float) $linhaPrev['fica_na_conta'] : null,
            'previsaoSemAdiantar' => self::previsaoSemAdiantar($linhaPrev, $mesPrev),
            'mesPrevisao'    => $mesPrev,
            'roteiro'        => self::roteiro(),
            'conta'          => $conta,
            'conf'           => $conf,
            'conferirAberto' => $conferirAberto || $conferirErro !== null,
            'conferirErro'   => $conferirErro,
            'painel'         => $painel,
            'ultimos'        => $ultimos,
            'alertas'        => $alertas,
            'v2'             => MotorFinanceiro::v2(),
        ], 'inicio');
    }

    /** POST /conferir — saldo do banco numa data (hoje ou outro dia), com volta. */
    public function conferir(): void
    {
        Auth::requireLogin();
        csrfCheck();

        $hoje  = date('Y-m-d');
        $volta = voltaSegura(is_string($_POST['volta'] ?? null) ? $_POST['volta'] : null, '/');
        $bruto = is_string($_POST['saldo_informado'] ?? null) ? trim($_POST['saldo_informado']) : '';
        $dataPost = is_string($_POST['data'] ?? null) ? trim($_POST['data']) : '';
        $quando = is_string($_POST['quando'] ?? null) ? $_POST['quando'] : ($dataPost !== '' ? 'outro' : 'hoje');
        $data = $quando === 'outro' ? $dataPost : $hoje;

        // limite: até 60 dias atrás, nunca antes do saldo inicial (véspera do 1º mês), nunca no futuro
        $minimo = date('Y-m-d', strtotime('-60 days', strtotime($hoje)));
        $primeiro = MesModel::primeiroDia();
        if ($primeiro !== null) {
            $minimo = max($minimo, date('Y-m-d', strtotime('-1 day', strtotime($primeiro))));
        }

        $saldo = parseMoedaBr($bruto);
        $erro = null;
        if ($saldo === null) {
            $erro = t('Digite o saldo como aparece no banco, por exemplo 1.200,50.');
        } elseif (!in_array($quando, ['hoje', 'outro'], true) || !self::dataIso($data) || $data > $hoje || $data < $minimo) {
            $erro = t('Escolha uma data válida.');
        }
        if ($erro !== null) {
            $_SESSION['conferir_erro'] = [
                'msg' => $erro, 'valor' => mb_substr($bruto, 0, 32),
                'quando' => $quando === 'outro' ? 'outro' : 'hoje', 'data' => self::dataIso($data) ? $data : $hoje,
            ];
            redirecionar('/?conferir=erro#conferir', 303);
        }

        SaldoConferenciaModel::registrar($data, $saldo, (int) Auth::id());
        flash('success', t('Saldo conferido com o banco em {data}.', ['data' => diaMes($data)]));
        redirecionar($volta, 303);
    }

    /* ------------------------------------------------------------------ blocos */

    /** Última conferência com a diferença contra saldoCalculado(data) e o agendado (D10). */
    private static function conferencia(float $saldoIni, string $hoje): ?array
    {
        $c = SaldoConferenciaModel::ultima();
        if ($c === null) {
            return null;
        }
        $data = (string) $c['data'];
        $dif = round((float) $c['saldo_informado'] - LancamentoModel::saldoCalculado($saldoIni, $data), 2);
        $agendado = SaldoConferenciaModel::agendadoNa($c);
        $idade = (int) (new \DateTimeImmutable($data))->diff(new \DateTimeImmutable($hoje))->format('%r%a');
        return [
            'data'     => $data,
            'saldo'    => (float) $c['saldo_informado'],
            'dif'      => $dif,
            'agendado' => $agendado,
            'bate'     => abs($dif) <= 0.5 || abs($dif - $agendado) <= 0.5,
            'idade'    => $idade,
        ];
    }

    /**
     * Painel do mês: entradas (salário × resto) e saídas (essenciais × não essenciais) a partir
     * dos lançamentos; num mês ainda previsto, da previsão. Também os maiores gastos.
     */
    private static function painel(?array $gm, string $mesRef, string $estadoRef): array
    {
        $previsto = $estadoRef === 'previsto';

        $sal = 0.0;
        $outras = 0.0;
        $outrasItens = [];
        foreach (LancamentoModel::entradasDoMes($mesRef) as $c) {
            $v = $previsto ? $c['previsto'] : $c['real'];
            if ($v < 0.005) {
                continue;
            }
            if ($c['grupo'] === 'Entradas' && mb_stripos($c['nome'], 'sal') === 0) {
                $sal += $v;
            } else {
                $outras += $v;
                $outrasItens[] = ['nome' => nomeCurto($c), 'valor' => round($v, 2)];
            }
        }
        usort($outrasItens, fn($a, $b) => $b['valor'] <=> $a['valor']);

        $ess = 0.0;
        $naoess = 0.0;
        $top = [];
        foreach (($gm['cats'] ?? []) as $c) {
            $v = $previsto ? (float) $c['previsto'] : (float) $c['real'];
            if ($v < 0.005) {
                continue;
            }
            if ($c['essencial']) {
                $ess += $v;
            } else {
                $naoess += $v;
            }
            $top[] = ['id' => $c['id'], 'nome' => nomeCurto($c), 'nome_banco' => $c['nome'], 'valor' => round($v, 2), 'essencial' => $c['essencial'], 'n' => $c['n']];
        }
        usort($top, fn($a, $b) => $b['valor'] <=> $a['valor']);

        $entradas = round($sal + $outras, 2);
        $saidas   = round($ess + $naoess, 2);
        return [
            'base'        => $previsto ? 'previsto' : 'real',
            'entradas'    => $entradas,
            'sal'         => round($sal, 2),
            'outras'      => round($outras, 2),
            'outrasItens' => $outrasItens,
            'saidas'      => $saidas,
            'ess'         => round($ess, 2),
            'naoess'      => round($naoess, 2),
            'balanco'     => round($entradas - $saidas, 2),
            'top'         => array_slice($top, 0, 6),
        ];
    }

    /* ------------------------------------------------------------------ utilidades */

    /** Hoje. Só em APP_ENV=development aceita ?hoje=AAAA-MM-DD (para ver os estados aberto/atrasado). */
    private static function hoje(): string
    {
        $h = $_GET['hoje'] ?? null;
        if (is_string($h) && Config::get('APP_ENV', 'production') === 'development' && self::dataIso($h)) {
            return $h;
        }
        return date('Y-m-d');
    }

    private static function dataIso(string $d): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** Quem escolheu adiantar o empréstimo vê também como a conta fica sem isso: o adiantamento não sai sozinho. */
    private static function previsaoSemAdiantar(array $linha, string $mes): ?float
    {
        if ($linha['situacao'] === 'REAL' || !AjusteModel::usa('usa_emprestimo') || (int) round(AjusteModel::valor('objetivo', 1.0)) === 3) {
            return null;
        }
        foreach (MotorFinanceiro::calcular(MotorFinanceiro::dados(), ['objetivo' => 3]) as $l) {
            if ($l['mes'] === $mes && abs((float) $l['fica_na_conta'] - (float) $linha['fica_na_conta']) >= 1.0) {
                return (float) $l['fica_na_conta'];
            }
        }
        return null;
    }

    /** O roteiro de quem está começando: some quando está tudo feito ou quando a pessoa dispensa. */
    private static function roteiro(): ?array
    {
        if (AjusteModel::valor('roteiro_ok') >= 1.0) {
            return null;
        }
        $pdo = Database::connection();
        $tem = static fn(string $tabela): bool => (bool) $pdo->query("SELECT 1 FROM {$tabela} LIMIT 1")->fetchColumn();
        $itens = [
            ['/lancar', t('Registre o seu primeiro gasto'), $tem('lancamentos')],
            ['/meta', t('Defina quanto quer gastar em uma categoria'), $tem('metas')],
            ['/#conferir', t('Compare o saldo com o do banco'), $tem('saldo_conferencias')],
        ];
        return in_array(false, array_column($itens, 2), true) ? $itens : null;
    }

    public function dispensarRoteiro(): void
    {
        Auth::requireLogin();
        csrfCheck();
        AjusteModel::setValor('roteiro_ok', 1.0);
        redirecionar('/', 303);
    }
}
