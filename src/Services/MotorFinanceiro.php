<?php
namespace Financeiro\Services;

use Financeiro\Config;
use Financeiro\Database;
use Financeiro\Models\AjusteModel;
use Financeiro\Models\LancamentoModel;
use Financeiro\Models\MesModel;

/**
 * Motor de cálculo — porta fiel da cascata "Plano" da planilha original (SPEC §5).
 *
 * Regras (ver docs/01-modelo-financeiro.md e docs/02-historico-decisoes.md):
 * - crédito do empréstimo, amortização extra e poupança NUNCA entram como renda ou gasto
 *   (`categorias.operacional = 0`); entram só no fluxo de caixa, em linhas separadas;
 * - "balanço" nas telas é sempre balancoTotal() (fluxo de caixa completo). `resultado_op`
 *   (entra − sai) é interno do motor e dos testes;
 * - mês aberto (PREVISAO e ≤ mês de hoje): gastos = Σ max(real, previsto) por categoria (D5);
 * - com MOTOR_V2=1, a sobra acima da reserva amortiza o empréstimo "olhando à frente": só o
 *   que mantém todos os meses seguintes ≥ reserva (D4); `objetivo` 3 (só a meta) não amortiza.
 *
 * - saldo devedor informado (ajustes saldo_devedor e saldo_devedor_mes): a dívida passa a esse valor
 *   no início do mês informado, antes da parcela. O extrato do banco já contém o que aconteceu antes,
 *   então nos meses anteriores o motor não supõe adiantamento automático.
 *
 * dados() lê o banco uma vez; calcular() é aritmética pura sobre esse array (a sugestão roda
 * o motor centenas de vezes por requisição sem tocar no banco).
 */
final class MotorFinanceiro
{
    public const CAT_CREDITO = 'Crédito do empréstimo';
    public const CAT_AMORT   = 'Amortização extra do empréstimo';
    public const CAT_GUARDAR = 'Guardar na poupança';
    public const CAT_RESGATE = 'Resgate da poupança';

    private static ?array $planoCache = null;
    private static ?array $dadosCache = null;

    public static function v2(): bool
    {
        return Config::get('MOTOR_V2', '0') === '1';
    }

    public static function limparCache(): void
    {
        self::$planoCache = null;
        self::$dadosCache = null;
        SugestaoAlocacao::limparCache();
        GastosMensais::limparCache();
    }

    /**
     * Tudo o que o motor precisa, numa leitura só (cache por requisição; limparCache() zera).
     * prev = previsão efetiva (view previsao_efetiva: o valor do mês, senão a meta, senão nada);
     * excecao[mes][cat] = o mês tem linha própria em `previsao`; metas[cat] = a meta que vale como
     * previsão padrão (só gasto ativo — a mesma condição da view).
     * @return array{meses:list<array{mes:string,situacao:string}>, aj:array<string,float>, loanIni:float,
     *   cats:array<int,array{id:int,nome:string,apelido:?string,grupo:string,sinal:int,operacional:int,essencial:int,ativo:int,ordem:int}>,
     *   prev:array<string,array<int,float>>, excecao:array<string,array<int,true>>, metas:array<int,float>,
     *   real:array<string,array<int,float>>, n:array<string,array<int,int>>,
     *   ult:array<string,array<int,string>>, esp:array{cred:?int,amort:?int,guard:?int,resg:?int}}
     */
    public static function dados(): array
    {
        if (self::$dadosCache !== null) {
            return self::$dadosCache;
        }
        $pdo = Database::connection();

        $meses = array_map(fn($m) => ['mes' => (string) $m['mes'], 'situacao' => (string) $m['situacao']], MesModel::todos());
        $aj    = AjusteModel::mapa();
        $loanIni = AjusteModel::devedorPor($aj['parcela_mensal'] ?? 0.0, $aj['n_parcelas'] ?? 0.0, $aj['juros_mes'] ?? 0.0);

        $cats = [];
        $esp  = ['cred' => null, 'amort' => null, 'guard' => null, 'resg' => null];
        $nomesEsp = [self::CAT_CREDITO => 'cred', self::CAT_AMORT => 'amort', self::CAT_GUARDAR => 'guard', self::CAT_RESGATE => 'resg'];
        foreach ($pdo->query('SELECT id, nome, apelido, grupo, sinal, operacional, essencial, ativo, ordem FROM categorias ORDER BY ordem, id') as $c) {
            $id = (int) $c['id'];
            $cats[$id] = [
                'id' => $id, 'nome' => (string) $c['nome'], 'apelido' => $c['apelido'] !== null ? (string) $c['apelido'] : null,
                'grupo' => (string) $c['grupo'], 'sinal' => (int) $c['sinal'], 'operacional' => (int) $c['operacional'],
                'essencial' => (int) $c['essencial'], 'ativo' => (int) $c['ativo'], 'ordem' => (int) $c['ordem'],
            ];
            if (isset($nomesEsp[$c['nome']])) {
                $esp[$nomesEsp[$c['nome']]] = $id;
            }
        }

        $prev = $excecao = $metas = [];
        foreach ($pdo->query('SELECT mes, categoria_id, valor, fonte FROM previsao_efetiva WHERE fonte IS NOT NULL') as $r) {
            $m = (string) $r['mes'];
            $c = (int) $r['categoria_id'];
            $prev[$m][$c] = (float) $r['valor'];
            if ($r['fonte'] === 'mes') {
                $excecao[$m][$c] = true;
            }
        }
        foreach ($pdo->query('SELECT categoria_id, valor FROM metas') as $r) {
            $c = $cats[(int) $r['categoria_id']] ?? null;
            if ($c !== null && $c['ativo'] === 1 && $c['operacional'] === 1 && $c['sinal'] === -1) {
                $metas[$c['id']] = (float) $r['valor'];
            }
        }

        $real = $n = $ult = [];
        $sql = "SELECT DATE_FORMAT(data, '%Y-%m-01') AS mes, categoria_id, SUM(valor) AS total, COUNT(*) AS n, MAX(data) AS ult
                FROM lancamentos GROUP BY DATE_FORMAT(data, '%Y-%m-01'), categoria_id";
        foreach ($pdo->query($sql) as $r) {
            $m = (string) $r['mes'];
            $c = (int) $r['categoria_id'];
            $real[$m][$c] = (float) $r['total'];
            $n[$m][$c]    = (int) $r['n'];
            $ult[$m][$c]  = (string) $r['ult'];
        }

        return self::$dadosCache = [
            'meses' => $meses, 'aj' => $aj, 'loanIni' => $loanIni, 'cats' => $cats,
            'prev' => $prev, 'excecao' => $excecao, 'metas' => $metas,
            'real' => $real, 'n' => $n, 'ult' => $ult, 'esp' => $esp,
        ];
    }

    /**
     * A cascata mês a mês, aritmética pura sobre dados().
     * opts: override [mes=>[cat=>valor]] (só meses PREVISAO), v2 (bool, padrão MOTOR_V2),
     *       aberto (bool, padrão true: regra D5), objetivo (1 os dois · 2 só quitar · 3 só a meta;
     *       padrão ajustes.objetivo; ignorado sem v2), hoje ('Y-m-d'), tratarComoReal (string[]),
     *       extra [mes=>valor] (o "e se" do Anual: fora de mês REAL, + entra e − sai no mês, como uma
     *       categoria operacional a mais; nada é gravado),
     *       adiantar [mes=>valor] (a simulação da aba Empréstimo: fora de mês REAL, soma à amortização
     *       extra lançada no mês; nada é gravado).
     */
    public static function calcular(array $dados, array $opts = []): array
    {
        $aj       = $dados['aj'];
        $cats     = $dados['cats'];
        $esp      = $dados['esp'];
        $v2       = (bool) ($opts['v2'] ?? self::v2());
        $regraAberto = (bool) ($opts['aberto'] ?? true);
        $objetivo = (int) ($opts['objetivo'] ?? (int) round($aj['objetivo'] ?? 1));
        if (!in_array($objetivo, [1, 2, 3], true)) {
            $objetivo = 1;
        }
        $hoje     = (string) ($opts['hoje'] ?? date('Y-m-d'));
        $mesHoje  = substr($hoje, 0, 7) . '-01';
        $tcr      = array_flip($opts['tratarComoReal'] ?? []);
        $override = $opts['override'] ?? [];
        $extra    = $opts['extra'] ?? [];
        $adiantar = $opts['adiantar'] ?? [];

        $reserva  = (float) ($aj['reserva_minima'] ?? 0.0);
        $meta     = (float) ($aj['meta_final'] ?? 0.0);
        $parcela  = (float) ($aj['parcela_mensal'] ?? 0.0);
        $jurosMes = (float) ($aj['juros_mes'] ?? 0.0);
        $iniParcela = self::mesInicioParcela($aj);
        $informado  = self::saldoInformado($aj);

        // ---- passo 1: componentes de cada mês (não dependem do encadeamento)
        $comp = [];
        foreach ($dados['meses'] as $m) {
            $mes   = $m['mes'];
            $ehReal = $m['situacao'] === 'REAL' || isset($tcr[$mes]);
            $estado = $ehReal ? 'real' : ($mes <= $mesHoje ? 'aberto' : 'previsto');
            $real  = $dados['real'][$mes] ?? [];

            $entra = $sai = 0.0;
            $cred = $amortReal = $guard = $resg = 0.0;
            $usaMov = false;
            if ($ehReal) {
                foreach ($real as $c => $v) {
                    $cat = $cats[$c] ?? null;
                    if ($cat !== null && $cat['operacional'] === 1) {
                        if ($cat['sinal'] === 1) { $entra += $v; } else { $sai += $v; }
                    }
                }
                $cred      = self::em($real, $esp['cred']);
                $amortReal = self::em($real, $esp['amort']);
                $guard     = self::em($real, $esp['guard']);
                $resg      = self::em($real, $esp['resg']);
                $usaMov    = true;
            } else {
                $p = $dados['prev'][$mes] ?? [];
                if (isset($override[$mes])) {
                    $p = array_replace($p, $override[$mes]);
                }
                if ($estado === 'aberto' && $regraAberto) {
                    foreach ($p + $real as $c => $_) {
                        $cat = $cats[$c] ?? null;
                        if ($cat === null || $cat['operacional'] !== 1) {
                            continue;
                        }
                        if ($cat['sinal'] === 1) {
                            $entra += max((float) ($real[$c] ?? 0.0), (float) ($p[$c] ?? 0.0));   // D5 vale para entradas também
                        } else {
                            $sai += max((float) ($real[$c] ?? 0.0), (float) ($p[$c] ?? 0.0));
                        }
                    }
                    $cred      = max(self::em($real, $esp['cred']), self::em($p, $esp['cred']));
                    $amortReal = self::em($real, $esp['amort']);
                    $guard     = self::em($real, $esp['guard']);
                    $resg      = self::em($real, $esp['resg']);
                    $usaMov    = true;
                } else {
                    foreach ($p as $c => $v) {
                        $cat = $cats[$c] ?? null;
                        if ($cat !== null && $cat['operacional'] === 1) {
                            if ($cat['sinal'] === 1) { $entra += (float) $v; } else { $sai += (float) $v; }
                        }
                    }
                    $cred = self::em($p, $esp['cred']);
                }
            }
            if (!$ehReal && isset($extra[$mes])) {
                $x = (float) $extra[$mes];
                if ($x > 0) {
                    $entra += $x;
                } else {
                    $sai -= $x;
                }
            }
            if (!$ehReal && isset($adiantar[$mes])) {
                $amortReal += (float) $adiantar[$mes];
            }
            // somas de dinheiro em centavos: arredondar reproduz o SUM() do banco ao centavo
            $comp[] = [
                'mes' => $mes, 'situacao' => $m['situacao'], 'estado' => $estado, 'real' => $ehReal,
                'entra' => round($entra, 2), 'sai' => round($sai, 2), 'cred' => round($cred, 2),
                'amort' => round($amortReal, 2), 'mov' => $usaMov ? round($guard - $resg, 2) : 0.0,
            ];
        }

        // ---- passo 2: encadeamento
        $saldoIni    = (float) ($aj['saldo_ini'] ?? 0.0);
        $poupancaIni = (float) ($aj['poupanca_ini'] ?? 0.0);
        $loanIni     = (float) $dados['loanIni'];
        $n = count($comp);
        $linhas = [];

        for ($i = 0; $i < $n; $i++) {
            $k = $comp[$i];
            if ($informado !== null && $k['mes'] === $informado['mes']) {
                $loanIni = $informado['valor'];
            }
            $antesDoSaldo = $informado !== null && $k['mes'] < $informado['mes'];

            // a parcela só começa a ser descontada (e a abater a dívida) a partir de ajustes.consignado_inicio
            $jurosV      = $loanIni > 0 ? $loanIni * $jurosMes : 0.0;
            $cobra       = $iniParcela === null || $k['mes'] >= $iniParcela;
            $parcelaPaga = ($cobra && $loanIni > 0.01) ? min($parcela, $loanIni + $jurosV) : 0.0;
            $la          = max(0.0, $loanIni + $jurosV - $parcelaPaga);

            // Fora de mês REAL, a parcela do consignado sai direto da folha: o salário previsto
            // chega líquido na conta. No REAL, o lançamento de salário já é o líquido.
            $entra = $k['entra'];
            $parcelaDesc = 0.0;
            if (!$k['real']) {
                $entra -= $parcelaPaga;
                $parcelaDesc = $parcelaPaga;
            }
            $resultadoOp = $entra - $k['sai'];
            $fluxo = $resultadoOp + $k['cred'];
            $mov   = $k['mov'];

            if ($k['real']) {
                $adiantado = min($k['amort'], $la);
            } elseif (!$v2) {
                $base  = min($k['amort'], $la);
                $livre = $fluxo - $base - $mov;
                $adiantado = $base + ($livre <= 0 || $antesDoSaldo
                    ? 0.0
                    : min($la - $base, max(0.0, $livre - max(0.0, $reserva - $saldoIni))));
            } else {
                $base = min($k['amort'], $la);
                $auto = 0.0;
                if ($objetivo !== 3 && !$antesDoSaldo) {
                    // olha à frente: o menor saldo projetado (sem novos adiantamentos) acima da reserva
                    $proj = $saldoIni + $fluxo - $base - $mov;
                    $melhor = $proj - $reserva;
                    $ll = $la - $base;
                    for ($q = $i + 1; $q < $n; $q++) {
                        $kq = $comp[$q];
                        $jq = $ll > 0 ? $ll * $jurosMes : 0.0;
                        $pq = (($iniParcela === null || $kq['mes'] >= $iniParcela) && $ll > 0.01) ? min($parcela, $ll + $jq) : 0.0;
                        $ll = max(0.0, $ll + $jq - $pq);
                        $bq = min($kq['amort'], $ll);
                        $ll -= $bq;
                        $proj += ($kq['real'] ? $kq['entra'] : $kq['entra'] - $pq) - $kq['sai'] + $kq['cred'] - $bq - $kq['mov'];
                        if ($proj - $reserva < $melhor) {
                            $melhor = $proj - $reserva;
                        }
                    }
                    $auto = min($la - $base, max(0.0, $melhor));
                }
                $adiantado = $base + $auto;
            }

            $fica    = $saldoIni + $fluxo - $adiantado - $mov;
            $loanFim = $la - $adiantado;
            $poupFim = $poupancaIni + $mov;
            $total   = $fica + $poupFim;

            $linhas[$k['mes']] = [
                'mes'                 => $k['mes'],
                'situacao'            => $k['situacao'],
                'estado'              => $k['estado'],
                'sobrou_mes_anterior' => $saldoIni,
                'entra'               => $entra,
                'entra_bruto'         => $k['entra'],
                'sai'                 => $k['sai'],
                'resultado_op'        => $resultadoOp,
                'parcela_consignado'  => $parcelaDesc,
                'juros'               => $jurosV,
                'credito_emprestimo'  => $k['cred'],
                'amortizacao_extra'   => $adiantado,
                'poupanca_liquido'    => $mov,
                'fica_na_conta'       => $fica,
                'falta_pagar'         => $loanFim,
                'poupanca_saldo'      => $poupFim,
                'total'               => $total,
                'quitado'             => $loanFim <= 0.01,
                'bateu_reserva'       => $fica >= $reserva,
                'bateu_meta'          => $total >= $meta,
            ];

            $saldoIni    = $fica;
            $loanIni     = $loanFim;
            $poupancaIni = $poupFim;
        }

        return $linhas;
    }

    private static function em(array $valores, ?int $cat): float
    {
        return $cat === null ? 0.0 : (float) ($valores[$cat] ?? 0.0);
    }

    /** ajustes.consignado_inicio (AAAAMM) → 'Y-m-01'; vazio/0 = a parcela desconta desde o primeiro mês. */
    public static function mesInicioParcela(array $aj): ?string
    {
        return self::mesDe((float) ($aj['consignado_inicio'] ?? 0));
    }

    /**
     * O saldo devedor informado (o que a POUPEX diz que falta antes da parcela do mês), ou null.
     * @return ?array{mes:string, valor:float}
     */
    public static function saldoInformado(array $aj): ?array
    {
        $mes   = self::mesDe((float) ($aj['saldo_devedor_mes'] ?? 0));
        $valor = (float) ($aj['saldo_devedor'] ?? 0);
        return $mes !== null && $valor > 0 ? ['mes' => $mes, 'valor' => $valor] : null;
    }

    /** AAAAMM → 'Y-m-01'; vazio ou inválido = null. */
    private static function mesDe(float $aaaamm): ?string
    {
        $v = (int) round($aaaamm);
        if ($v < 190001 || $v > 299912 || $v % 100 < 1 || $v % 100 > 12) {
            return null;
        }
        return sprintf('%04d-%02d-01', intdiv($v, 100), $v % 100);
    }

    /**
     * Plano com as opções padrão (MOTOR_V2 do .env, mês aberto ligado, objetivo gravado, hoje).
     * O cache estático vale só para override vazio.
     */
    public static function calcularPlano(array $override = []): array
    {
        if ($override !== []) {
            return self::calcular(self::dados(), ['override' => $override]);
        }
        return self::$planoCache ??= self::calcular(self::dados());
    }

    public static function linhaDoMes(string $mes): ?array
    {
        return self::calcularPlano()[$mes] ?? null;
    }

    /**
     * Balanço "de verdade": tudo que entrou (salário/outras/empréstimo/resgates) menos tudo
     * que saiu (gastos/amortização extra/guardado na poupança) — fluxo de caixa completo do
     * mês. Único lugar onde essa conta é feita; toda tela que mostra "balanço" usa este método.
     * @return array{0:float,1:float,2:float} [entradas, saidas, balanco]
     */
    public static function balancoTotal(array $linha): array
    {
        $entradas = $linha['entra'] + $linha['credito_emprestimo'] + max(0, -$linha['poupanca_liquido']);
        $saidas   = $linha['sai'] + $linha['amortizacao_extra'] + max(0, $linha['poupanca_liquido']);
        return [$entradas, $saidas, $entradas - $saidas];
    }

    public static function saldoCalculadoHoje(): float
    {
        $saldoIni = AjusteModel::valor('saldo_ini');
        return LancamentoModel::saldoCalculado($saldoIni, date('Y-m-d'));
    }

    /** Mês com o menor "fica na conta" de toda a projeção. */
    public static function mesMaisApertado(): ?array
    {
        $plano = self::calcularPlano();
        if (empty($plano)) {
            return null;
        }
        return array_reduce($plano, fn($carry, $linha) =>
            $carry === null || $linha['fica_na_conta'] < $carry['fica_na_conta'] ? $linha : $carry
        );
    }

    public static function mesQueEmprestimoQuita(): ?string
    {
        return self::quitaEm(self::calcularPlano());
    }

    public static function mesQueBateMeta(): ?string
    {
        foreach (self::calcularPlano() as $mes => $linha) {
            if ($linha['bateu_meta']) {
                return $mes;
            }
        }
        return null;
    }

    /** Primeiro mês de um plano qualquer (saída de calcular()) com a dívida quitada. */
    public static function quitaEm(array $plano): ?string
    {
        foreach ($plano as $mes => $linha) {
            if ($linha['quitado']) {
                return $mes;
            }
        }
        return null;
    }

    public static function ultimoMes(): ?array
    {
        $plano = self::calcularPlano();
        return $plano === [] ? null : end($plano);
    }
}
