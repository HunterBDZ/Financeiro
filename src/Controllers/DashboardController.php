<?php
namespace Financeiro\Controllers;

use Financeiro\Auth;
use Financeiro\Config;
use Financeiro\Database;
use Financeiro\Models\AjusteModel;
use Financeiro\Models\ContaModel;
use Financeiro\Models\LancamentoModel;
use Financeiro\Models\MesModel;
use Financeiro\Models\SaldoConferenciaModel;
use Financeiro\Relogio;
use Financeiro\Services\Atencao;
use Financeiro\Services\GastoPorCategoria;
use Financeiro\Services\GastosMensais;
use Financeiro\Services\MotorFinanceiro;
use Financeiro\Services\Sobra;
use Financeiro\View;

/**
 * Início e o gráfico "gastos mês a mês".
 * Quanto ainda dá pra gastar? O que vence? A conta bate?
 */
final class DashboardController
{
    /** Cookie de quem prefere ver o "Para onde foi" por fatura. */
    public const COOKIE_ONDE = 'financeiro_onde';

    public function index(): void
    {
        Auth::requireLogin();

        $hoje = self::hoje();
        $ehHoje = $hoje === Relogio::hoje();
        $plano = $ehHoje
            ? MotorFinanceiro::calcularPlano()
            : MotorFinanceiro::calcular(MotorFinanceiro::dados(), ['hoje' => $hoje]);
        if ($plano === []) {
            View::render('dashboard/vazio', ['pageTitle' => t('início · financeiro')], 'inicio');
            return;
        }

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

        // com mais de uma conta: o saldo de cada uma (o total continua o do plano) e a conferência de cada uma
        $contas = ContaModel::varias() ? self::contasHoje($hoje) : [];
        $conf  = $contas !== [] ? self::conferenciaContas($contas, $hoje) : self::conferencia($saldoIni, $hoje);

        // CONTA (invariante 4): fica_na_conta do mês do CALENDÁRIO quando ele está REAL;
        // senão, o saldo calculado pelos lançamentos até hoje.
        $linhaCal = $plano[$mesCal] ?? null;
        $conta = ($linhaCal !== null && $linhaCal['situacao'] === 'REAL')
            ? (float) $linhaCal['fica_na_conta']
            : LancamentoModel::saldoCalculado($saldoIni, $hoje);

        $painel = self::painel($matriz[$mesRef] ?? null, $mesRef, $estadoRef, self::ondePorFatura());
        // a sobra do cartão do mês, com o nome da base (a mesma conta do Mês, do Fechar e do Relatório)
        $sobra = $estadoRef === 'previsto' ? Sobra::prevista($linhaRef) : (Sobra::ateHoje($mesRef, $ehHoje ? null : $hoje) ?? Sobra::prevista($linhaRef));
        $ultimos = LancamentoModel::listar($mesRef, ['limite' => 3]);

        // "Precisa de atenção": contas e faturas vencendo ou atrasadas, mês anterior aberto, conferência, metas
        $avisos = Atencao::avisos($hoje, $conf);

        // erro da conferência volta pelo ?conferir=erro#conferir (a mensagem fica na sessão)
        $conferirErro = null;
        $conferirAberto = isset($_GET['conferir']);
        if (($_GET['conferir'] ?? '') === 'erro' && is_array($_SESSION['conferir_erro'] ?? null)) {
            $conferirErro = $_SESSION['conferir_erro'];   // recarregar a página não repete o erro
        }
        unset($_SESSION['conferir_erro']);

        $previsaoFim = $linhaPrev['situacao'] !== 'REAL' ? (float) $linhaPrev['fica_na_conta'] : null;
        $fimSemAdiantar = $previsaoFim !== null ? self::fimSemAdiantar($linhaPrev, $mesPrev, $hoje) : null;

        View::render('dashboard/index', [
            'pageTitle'      => t('início · financeiro'),
            'hoje'           => $hoje,
            'mesRef'         => $mesRef,
            'mesCal'         => $mesCal,
            'estadoRef'      => $estadoRef,
            'reserva'        => $reserva,
            'previsaoFim'    => $previsaoFim,
            'previsaoSemAdiantar' => $fimSemAdiantar !== null && abs($fimSemAdiantar - (float) $previsaoFim) >= 1.0 ? $fimSemAdiantar : null,
            'resposta'       => self::resposta($previsaoFim, $fimSemAdiantar, $reserva),
            'mesPrevisao'    => $mesPrev,
            'roteiro'        => self::roteiro(),
            'conta'          => $conta,
            'contas'         => $contas,
            'conf'           => $conf,
            'conferirAberto' => $conferirAberto || $conferirErro !== null,
            'conferirErro'   => $conferirErro,
            'painel'         => $painel,
            'sobra'          => $sobra,
            'ultimos'        => $ultimos,
            'avisos'         => $avisos,
            'v2'             => MotorFinanceiro::v2(),
        ], 'inicio');
    }

    /** GET /atencao — todos os avisos de "Precisa de atenção", quando são mais do que a faixa do Início mostra. */
    public function atencao(): void
    {
        Auth::requireLogin();
        $hoje = self::hoje();
        View::render('dashboard/atencao', [
            'pageTitle' => t('precisa de atenção · financeiro'),
            'avisos'    => Atencao::avisos($hoje, self::conferenciaAtual($hoje)),
        ], 'inicio');
    }

    /** A última conferência com o banco, como o Início mostra: a do total ou, com várias contas, a de cada uma. */
    public static function conferenciaAtual(string $hoje): ?array
    {
        return ContaModel::varias()
            ? self::conferenciaContas(self::contasHoje($hoje), $hoje)
            : self::conferencia(AjusteModel::valor('saldo_ini'), $hoje);
    }

    /** POST /conferir — saldo do banco numa data (hoje ou outro dia), com volta. */
    public function conferir(): void
    {
        Auth::requireLogin();
        csrfCheck();

        $hoje  = Relogio::hoje();
        $volta = voltaSegura(is_string($_POST['volta'] ?? null) ? $_POST['volta'] : null, '/');
        if (is_array($_POST['saldo_conta'] ?? null) && ContaModel::varias()) {
            self::conferirContas($hoje, $volta);
        }
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

    /**
     * Com várias contas, cada uma é conferida com o extrato dela, na mesma data (POST saldo_conta[id]). Em branco,
     * a conta fica sem conferir.
     */
    private static function conferirContas(string $hoje, string $volta): never
    {
        $dataPost = is_string($_POST['data'] ?? null) ? trim($_POST['data']) : '';
        $quando = is_string($_POST['quando'] ?? null) ? $_POST['quando'] : ($dataPost !== '' ? 'outro' : 'hoje');
        $data = $quando === 'outro' ? $dataPost : $hoje;
        [$saldos, $brutos, $erro] = self::lerSaldosContas((array) $_POST['saldo_conta']);
        $minimo = date('Y-m-d', strtotime('-60 days', strtotime($hoje)));
        $primeiro = MesModel::primeiroDia();
        if ($primeiro !== null) {
            $minimo = max($minimo, date('Y-m-d', strtotime('-1 day', strtotime($primeiro))));
        }
        if ($erro === null && $saldos === []) {
            $erro = t('Digite o saldo de pelo menos uma conta.');
        } elseif ($erro === null && (!in_array($quando, ['hoje', 'outro'], true) || !self::dataIso($data) || $data > $hoje || $data < $minimo)) {
            $erro = t('Escolha uma data válida.');
        }
        if ($erro !== null) {
            $_SESSION['conferir_erro'] = ['msg' => $erro, 'contas' => $brutos, 'quando' => $quando === 'outro' ? 'outro' : 'hoje', 'data' => self::dataIso($data) ? $data : $hoje];
            redirecionar('/?conferir=erro#conferir', 303);
        }
        foreach ($saldos as $id => $v) {
            SaldoConferenciaModel::registrar($data, $v, (int) Auth::id(), $id);
        }
        flash('success', t('Saldo conferido com o banco em {data}.', ['data' => diaMes($data)]));
        redirecionar($volta, 303);
    }

    /**
     * Os saldos digitados por conta (saldo_conta[id]), só das contas ativas; em branco fica de fora.
     * @return array{0:array<int, float>, 1:array<int, string>, 2:?string} os valores, o que foi digitado e o erro
     */
    public static function lerSaldosContas(array $post): array
    {
        $saldos = $brutos = [];
        $erro = null;
        foreach (ContaModel::ativas() as $c) {
            $bruto = is_string($post[$c['id']] ?? null) ? mb_substr(trim($post[$c['id']]), 0, 32) : '';
            $brutos[$c['id']] = $bruto;
            if ($bruto === '') {
                continue;
            }
            $v = parseMoedaBr($bruto);
            if ($v === null) {
                $erro = t('Digite o saldo como aparece no banco, por exemplo 1.200,50.');
                continue;
            }
            $saldos[$c['id']] = round($v, 2);
        }
        return [$saldos, $brutos, $erro];
    }

    /**
     * As contas para o Início: as ativas e as arquivadas que ainda têm saldo, com o saldo de hoje.
     * @return list<array>
     */
    private static function contasHoje(string $hoje): array
    {
        $saldos = ContaModel::saldos($hoje);
        $out = [];
        foreach (ContaModel::todas() as $c) {
            $v = (float) ($saldos[$c['id']] ?? 0.0);
            if ($c['ativa'] || abs($v) >= 0.005) {
                $out[] = $c + ['saldo' => $v];
            }
        }
        return $out;
    }

    /**
     * A última conferência de cada conta ativa, juntas: a data é a mais recente, e bate se todas batem. Como
     * conferencia(), mas cada conta contra o saldo dela e o agendado dela.
     */
    private static function conferenciaContas(array $contas, string $hoje): ?array
    {
        $ultimas = SaldoConferenciaModel::ultimasPorConta();
        $por = [];
        foreach ($contas as $c) {
            $u = $ultimas[$c['id']] ?? null;
            if ($u === null || !$c['ativa']) {
                continue;
            }
            $dif = round((float) $u['saldo_informado'] - ContaModel::saldo($c['id'], (string) $u['data']), 2);
            $agendado = SaldoConferenciaModel::agendadoNa($u);
            $por[$c['id']] = ['conta' => $c, 'data' => (string) $u['data'], 'saldo' => (float) $u['saldo_informado'], 'dif' => $dif,
                              'bate' => abs($dif) <= 0.5 || abs($dif - $agendado) <= 0.5];
        }
        if ($por === []) {
            return null;
        }
        $data = max(array_column($por, 'data'));
        $difs = array_filter($por, static fn(array $p): bool => !$p['bate']);
        return [
            'data' => $data, 'saldo' => round(array_sum(array_column($por, 'saldo')), 2),
            'dif' => round(array_sum(array_column($difs, 'dif')), 2), 'agendado' => 0.0,
            'bate' => $difs === [], 'idade' => (int) (new \DateTimeImmutable($data))->diff(new \DateTimeImmutable($hoje))->format('%r%a'),
            'contas' => $por,
        ];
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
     * "Para onde foi" por fatura (como o plano) em vez de pela data da compra: ?onde=fatura ou ?onde=compra escolhe, e a
     * escolha fica num cookie, para o Início, o Relatório e o PDF.
     */
    public static function ondePorFatura(): bool
    {
        $pedido = $_GET['onde'] ?? null;
        if ($pedido === 'fatura' || $pedido === 'compra') {
            if (!headers_sent()) {
                setcookie(self::COOKIE_ONDE, $pedido, [
                    'expires' => time() + 31536000, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']),
                    'httponly' => true, 'samesite' => 'Lax',
                ]);
            }
            $_COOKIE[self::COOKIE_ONDE] = $pedido;
        }
        return ($_COOKIE[self::COOKIE_ONDE] ?? '') === 'fatura';
    }

    /**
     * Painel do mês: entradas (salário × resto) e saídas (essenciais × não essenciais) a partir
     * dos lançamentos; num mês ainda previsto, da previsão. Também os maiores gastos ("Para onde foi"): com o
     * cartão aberto e fora do mês previsto, por GastoPorCategoria (cada compra na categoria dela, pela data),
     * salvo se a pessoa prefere por fatura. Entradas e saídas continuam as da conta.
     */
    private static function painel(?array $gm, string $mesRef, string $estadoRef, bool $porFatura = false): array
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
        $porCompra = !$previsto && !$porFatura && GastoPorCategoria::ativo();
        if ($porCompra) {
            $top = self::ondeFoi($mesRef, $gm['cats'] ?? []);
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
            'onde'        => $top,
            'ondeTotal'   => $porCompra ? round(array_sum(array_column($top, 'valor')), 2) : $saidas,
            'porCompra'   => $porCompra,
            'cartaoAberto' => !$previsto && GastoPorCategoria::ativo(),
            'semCategoria' => $porCompra ? GastoPorCategoria::semCategoria($mesRef) : null,
        ];
    }

    /**
     * O "Para onde foi" pela data da compra: cada categoria de gasto com o valor de GastoPorCategoria, sem negativo. A
     * Fatura do cartão fica só com o que não tem compra registrada ("Fatura sem detalhe"), e as compras sem categoria
     * são uma linha à parte (id 0), que leva a categorizar.
     * @param array<int, array> $cats as categorias do mês em GastosMensais::matriz() (nome, apelido, essencial)
     */
    private static function ondeFoi(string $mes, array $cats): array
    {
        $fat = GastoPorCategoria::categoriaFatura();
        $out = [];
        foreach (GastoPorCategoria::doMes($mes) as $id => $v) {
            if ($v < 0.005) {
                continue;
            }
            if ($id === GastoPorCategoria::SEM_CATEGORIA) {
                $out[] = ['id' => $id, 'nome' => t('Cartão sem categoria'), 'nome_banco' => '', 'valor' => $v, 'essencial' => false, 'n' => 0];
                continue;
            }
            $c = $cats[$id] ?? null;
            if ($c === null) {
                continue;
            }
            $out[] = ['id' => $id, 'nome' => $id === $fat ? t('Fatura sem detalhe') : nomeCurto($c), 'nome_banco' => $c['nome'], 'valor' => $v,
                      'essencial' => $c['essencial'], 'n' => $c['n']];
        }
        return $out;
    }

    /* ------------------------------------------------------------------ utilidades */

    /** Hoje. Só em APP_ENV=development aceita ?hoje=AAAA-MM-DD (para ver os estados aberto/atrasado). */
    private static function hoje(): string
    {
        $h = $_GET['hoje'] ?? null;
        if (is_string($h) && Config::get('APP_ENV', 'production') === 'development' && self::dataIso($h)) {
            return $h;
        }
        return Relogio::hoje();
    }

    private static function dataIso(string $d): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * A conta no fim do mês sem o adiantamento automático do empréstimo: o mesmo plano com `objetivo` 3.
     * Sem empréstimo ou com "só a meta", é a própria previsão. O adiantamento não sai sozinho da conta.
     */
    private static function fimSemAdiantar(array $linha, string $mes, string $hoje): float
    {
        if ($linha['situacao'] === 'REAL' || !AjusteModel::usa('usa_emprestimo') || (int) round(AjusteModel::valor('objetivo', 1.0)) === 3) {
            return (float) $linha['fica_na_conta'];
        }
        $l = MotorFinanceiro::calcular(MotorFinanceiro::dados(), ['objetivo' => 3, 'hoje' => $hoje])[$mes] ?? null;
        return $l !== null ? (float) $l['fica_na_conta'] : (float) $linha['fica_na_conta'];
    }

    /**
     * A frase do cartão do saldo, com a base do plano (docs/decisoes.md). $semAdiantar (S) é o fim do mês sem
     * adiantar o empréstimo; $fim (P), a previsão com o plano. S abaixo da reserva: quanto falta (para zerar,
     * se S é negativo). S acima: S − reserva dá para gastar até o fim do mês; se o plano adianta o empréstimo,
     * S − P vai para lá.
     * @return ?array{tipo:string, valor:float, adianta:float}  tipo: vermelho | abaixo | justo | gastar
     */
    public static function resposta(?float $fim, ?float $semAdiantar, float $reserva): ?array
    {
        if ($fim === null || $semAdiantar === null) {
            return null;
        }
        $reserva = max(0.0, $reserva);
        if ($semAdiantar < -0.004) {
            return ['tipo' => 'vermelho', 'valor' => round(-$semAdiantar, 2), 'adianta' => 0.0];
        }
        if ($semAdiantar < $reserva - 0.004) {
            return ['tipo' => 'abaixo', 'valor' => round($reserva - $semAdiantar, 2), 'adianta' => 0.0];
        }
        $livre = round($semAdiantar - $reserva, 2);
        $adianta = round(max(0.0, $semAdiantar - $fim), 2);
        return ['tipo' => $livre < 0.005 ? 'justo' : 'gastar', 'valor' => $livre, 'adianta' => $adianta < 0.005 ? 0.0 : min($adianta, $livre)];
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
