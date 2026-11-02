<?php
namespace Financeiro\Services;

use Financeiro\Models\CartaoModel;
use Financeiro\Models\MesModel;
use Financeiro\Relogio;

/**
 * As contas do cartão. "Fatura de M" = a que vence no mês M: é quando o dinheiro sai da conta, e
 * é nesse mês que ela entra no plano como a categoria Fatura do cartão (regime de caixa; a compra
 * não conta de novo na categoria dela). As funções de cima são puras (atribuir() diz de que fatura
 * é cada pagamento); painel() monta a tela; faturasPagas() lê os pagamentos para o plano, o Mês e a
 * aba; sincronizarPlano() escreve a previsão da fatura quando o cartão alimenta o plano.
 */
final class CartaoFatura
{
    /** Diferença que ainda conta como fatura toda paga: centavos de arredondamento, não um pedaço da fatura. */
    public const TOLERANCIA = 0.05;

    /** Mês (Y-m-01) da fatura que recebe uma compra feita em $data. No dia do fechamento já vai para a seguinte. */
    public static function faturaDe(string $data, int $fecha, int $vence): string
    {
        $t = strtotime($data);
        $mes = (int) date('n', $t);
        if ((int) date('j', $t) >= min($fecha, (int) date('t', $t))) {
            $mes++;                                  // fecha no mês seguinte
        }
        if ($vence <= $fecha) {
            $mes++;                                  // vence no mês depois do fechamento
        }
        return date('Y-m-01', mktime(0, 0, 0, $mes, 1, (int) date('Y', $t)));
    }

    /** Dia (Y-m-d) em que fecha a fatura que vence em $fatura. */
    public static function fechamento(string $fatura, int $fecha, int $vence): string
    {
        $base = strtotime(self::somaMeses($fatura, $vence <= $fecha ? -1 : 0));
        return date('Y-m-', $base) . sprintf('%02d', min($fecha, (int) date('t', $base)));
    }

    /** Dia (Y-m-d) em que vence a fatura de $fatura. */
    public static function vencimento(string $fatura, int $vence): string
    {
        $t = strtotime($fatura);
        return date('Y-m-', $t) . sprintf('%02d', min($vence, (int) date('t', $t)));
    }

    /** A fatura (Y-m-01) mais recente que já tinha fechado em $data (fechamento ≤ data): a anterior à que recebe compras nesse dia. */
    public static function ultimaFechada(string $data, int $fecha, int $vence): string
    {
        return self::somaMeses(self::faturaDe($data, $fecha, $vence), -1);
    }

    /** Toda paga: o pago cobre o valor da fatura, com tolerância de centavos. Sem valor conhecido (0), basta ter recebido um pagamento. */
    public static function todaPaga(?float $pago, float $valor): bool
    {
        return $pago !== null && $pago > 0.004 && ($valor <= 0.004 || $pago >= $valor - self::TOLERANCIA);
    }

    /**
     * De que fatura é cada pagamento. A regra mora só aqui: quando o vínculo do pagamento com a fatura
     * for gravado, é esta função que preenche os pagamentos antigos. Em ordem de data, cada pagamento vai
     * para a fatura mais recente que já tinha fechado na data dele; se essa já está toda paga, vai para a
     * seguinte, a que estava aberta naquele dia (é pagar adiantado). Pagamentos parciais somam na mesma
     * fatura até completar. Assim, pagar no dia 31 a fatura que vence no dia 2 conta para ela, e não para
     * a fatura do mês da data.
     * @param array<array-key, array{data:string, valor:float}> $pagamentos
     * @param array<string, float> $valores fatura (Y-m-01) => quanto ela cobra; 0 ou ausente = não se sabe
     * @return array<array-key, string> a fatura (Y-m-01) de cada pagamento, com as chaves e a ordem de $pagamentos
     */
    public static function atribuir(array $pagamentos, int $fecha, int $vence, array $valores): array
    {
        $porData = $pagamentos;
        uasort($porData, static fn(array $a, array $b): int => strcmp($a['data'], $b['data']));   // estável: no mesmo dia, a ordem recebida
        $pago = $daFatura = [];
        foreach ($porData as $k => $p) {
            $f = self::ultimaFechada($p['data'], $fecha, $vence);
            if (self::todaPaga($pago[$f] ?? null, (float) ($valores[$f] ?? 0.0))) {
                $f = self::somaMeses($f, 1);
            }
            $pago[$f] = ($pago[$f] ?? 0.0) + (float) $p['valor'];
            $daFatura[$k] = $f;
        }
        $out = [];
        foreach (array_keys($pagamentos) as $k) {
            $out[$k] = $daFatura[$k];
        }
        return $out;
    }

    /**
     * Os pagamentos somados por fatura, com atribuir(): só as faturas que receberam pagamento.
     * @param list<array{data:string, valor:float}> $pagamentos
     * @param array<string, float> $valores fatura => quanto ela cobra (ver atribuir())
     * @return array<string, array{valor:float, pago:float, primeiro:string, ultimo:string, meses:array<string, float>}>
     *   fatura => o valor dela, o pago, as datas do primeiro e do último pagamento e o pago em cada mês (Y-m-01)
     */
    public static function porFaturaPaga(array $pagamentos, int $fecha, int $vence, array $valores): array
    {
        $out = [];
        foreach (self::atribuir($pagamentos, $fecha, $vence, $valores) as $k => $f) {
            $p = $pagamentos[$k];
            $mesPg = substr($p['data'], 0, 8) . '01';
            $out[$f] ??= ['valor' => round((float) ($valores[$f] ?? 0.0), 2), 'pago' => 0.0, 'primeiro' => $p['data'], 'ultimo' => $p['data'], 'meses' => []];
            $out[$f]['pago'] = round($out[$f]['pago'] + (float) $p['valor'], 2);
            $out[$f]['primeiro'] = min($out[$f]['primeiro'], $p['data']);
            $out[$f]['ultimo'] = max($out[$f]['ultimo'], $p['data']);
            $out[$f]['meses'][$mesPg] = round(($out[$f]['meses'][$mesPg] ?? 0.0) + (float) $p['valor'], 2);
        }
        ksort($out);
        return $out;
    }

    /**
     * As parcelas de uma compra: [fatura (Y-m-01), nº, valor]. Cada parcela é o total dividido,
     * arredondado para baixo no centavo; o que sobra vai na 1ª, como fazem os bancos.
     * @return list<array{0:string,1:int,2:float}>
     */
    public static function parcelas(float $total, int $n, string $primeira): array
    {
        $n = max(1, $n);
        $cent = (int) round($total * 100);
        $base = intdiv($cent, $n);
        $resto = $cent - $base * $n;
        $out = [];
        for ($k = 1; $k <= $n; $k++) {
            $out[] = [self::somaMeses($primeira, $k - 1), $k, ($base + ($k === 1 ? $resto : 0)) / 100];
        }
        return $out;
    }

    /** Compra antiga: a parcela $atual cai na fatura aberta, então a 1ª foi $atual − 1 faturas antes. */
    public static function primeiraDaAntiga(string $faturaAberta, int $atual): string
    {
        return self::somaMeses($faturaAberta, -max(0, $atual - 1));
    }

    public static function somaMeses(string $mes, int $n): string
    {
        $t = strtotime(substr($mes, 0, 8) . '01');
        return date('Y-m-01', mktime(0, 0, 0, (int) date('n', $t) + $n, 1, (int) date('Y', $t)));
    }

    /**
     * As parcelas de cada fatura, com a compra de cada uma.
     * @param list<array> $compras linhas de CartaoModel::compras()
     * @return array<string, list<array{compra:array, k:int, valor:float}>>
     */
    public static function porFatura(array $compras): array
    {
        $out = [];
        foreach ($compras as $c) {
            foreach (self::parcelas((float) $c['valor_total'], (int) $c['parcelas'], (string) $c['primeira_fatura']) as [$f, $k, $v]) {
                $out[$f][] = ['compra' => $c, 'k' => $k, 'valor' => $v];
            }
        }
        ksort($out);
        return $out;
    }

    /** @return array<string, float> fatura => soma das parcelas */
    public static function totais(array $compras): array
    {
        $out = [];
        foreach (self::porFatura($compras) as $f => $itens) {
            $out[$f] = round(array_sum(array_column($itens, 'valor')), 2);
        }
        return $out;
    }

    /**
     * O que a aba mostra. $pagas = fatura => total pago dela (atribuir()), $pagasEm = fatura => data do
     * último pagamento; $previsto = mês => previsão de hoje da Fatura do cartão (para conferir antes de
     * ligar o cartão ao plano). Paga = já fechou e o pago cobre a soma das parcelas (sem parcela lançada,
     * basta um pagamento). Faturas: a anterior à aberta e as 14 seguintes (o resto continua nos parcelamentos).
     */
    public static function painel(array $cartao, array $compras, string $hoje, array $pagas, array $previsto = [], array $pagasEm = []): array
    {
        [$fecha, $vence] = [(int) $cartao['fecha'], (int) $cartao['vence']];
        $aberta = self::faturaDe($hoje, $fecha, $vence);
        $porFatura = self::porFatura($compras);
        $faturas = [];
        for ($i = -1; $i <= 13; $i++) {
            $m = self::somaMeses($aberta, $i);
            $itens = $porFatura[$m] ?? [];
            usort($itens, static fn($a, $b) => [$a['compra']['parcelas'] === 1 ? 1 : 0, -$a['valor']] <=> [$b['compra']['parcelas'] === 1 ? 1 : 0, -$b['valor']]);
            $total = round(array_sum(array_column($itens, 'valor')), 2);
            $fechaEm = self::fechamento($m, $fecha, $vence);
            $venceEm = self::vencimento($m, $vence);
            $pago = isset($pagas[$m]) ? (float) $pagas[$m] : null;
            $status = match (true) {
                $hoje < $fechaEm                => $m === $aberta ? 'aberta' : 'prevista',
                self::todaPaga($pago, $total)   => 'paga',
                $hoje <= $venceEm               => 'fechada',
                default                         => 'vencida',
            };
            $faturas[] = ['mes' => $m, 'itens' => $itens, 'total' => $total, 'pago' => $pago, 'status' => $status,
                          'fecha' => $fechaEm, 'vence' => $venceEm, 'previsto' => $previsto[$m] ?? null, 'pagaEm' => $pagasEm[$m] ?? null,
                          // paga em parte: quanto ainda falta da soma das parcelas
                          'falta' => $pago !== null && $pago > 0.004 && $total - $pago > self::TOLERANCIA ? round($total - $pago, 2) : null];
        }
        // parcelamentos que ainda têm parcela da aberta em diante, do que acaba antes para o que acaba depois
        $parc = [];
        $comprometido = 0.0;
        foreach ($compras as $c) {
            $ps = self::parcelas((float) $c['valor_total'], (int) $c['parcelas'], (string) $c['primeira_fatura']);
            $futuras = array_values(array_filter($ps, static fn($p) => $p[0] >= $aberta));
            $comprometido += array_sum(array_column($futuras, 2));
            if ((int) $c['parcelas'] < 2 || $futuras === []) {
                continue;
            }
            $agora = $futuras[0][1];                 // nº da parcela na aberta (ou a próxima, se começa depois)
            $parc[] = ['compra' => $c, 'agora' => $agora, 'n' => (int) $c['parcelas'], 'valor' => $futuras[count($futuras) - 1][2],
                       'restante' => round(array_sum(array_column($futuras, 2)), 2), 'faltam' => count($futuras),
                       'ultima' => $ps[count($ps) - 1][0], 'comeca' => $futuras[0][0]];
        }
        usort($parc, static fn($a, $b) => [$a['ultima'], $a['compra']['id']] <=> [$b['ultima'], $b['compra']['id']]);
        $limite = $cartao['limite'] ?? null;
        return [
            'aberta' => $aberta, 'faturas' => $faturas, 'parcelamentos' => $parc,
            'restante' => round(array_sum(array_column($parc, 'restante')), 2),
            'comprometido' => round($comprometido, 2),
            'livre' => $limite !== null ? round($limite - $comprometido, 2) : null,
        ];
    }

    /**
     * Os pagamentos de fatura, cada um na sua fatura (atribuir()), para o plano, o Mês e esta aba. O valor
     * de cada fatura é o maior entre a soma das parcelas lançadas e a previsão da Fatura do cartão no mês
     * dela: o cartão pode não ter todas as compras, e a previsão pode ser só uma estimativa. Sem cartão (ou
     * sem a aba Cartão), cat é null e a Fatura do cartão segue a regra das outras contas: o pagamento fica
     * no mês da data.
     * @param array<string, array<int, float>> $prev a previsão efetiva de cada mês (MotorFinanceiro::dados())
     * @return array{cat:?int, vence:int, faturas:array<string, array>} faturas: ver porFaturaPaga()
     */
    public static function faturasPagas(array $prev, bool $usaCartao): array
    {
        $cartao = $usaCartao ? CartaoModel::ativo() : null;
        $cat = $cartao !== null ? CartaoModel::categoriaFatura() : null;
        if ($cartao === null || $cat === null) {
            return ['cat' => null, 'vence' => 0, 'faturas' => []];
        }
        $pagamentos = CartaoModel::pagamentos($cat);
        $valores = $pagamentos === [] ? [] : self::totais(CartaoModel::compras($cartao['id']));
        foreach ($prev as $mes => $p) {
            if (isset($p[$cat])) {
                $valores[$mes] = max($valores[$mes] ?? 0.0, (float) $p[$cat]);
            }
        }
        return ['cat' => $cat, 'vence' => $cartao['vence'], 'faturas' => self::porFaturaPaga($pagamentos, $cartao['fecha'], $cartao['vence'], $valores)];
    }

    /**
     * Com o cartão ligado ao plano: a previsão da Fatura do cartão em cada mês em PREVISAO, da
     * fatura aberta em diante, passa a ser a soma das parcelas daquela fatura (lote 'cartao', com
     * log e desfazer). Meses antes da aberta não mudam: a fatura já fechou e o valor é o do banco.
     * @return ?string o lote, ou null se nada mudou (ou o cartão não alimenta o plano)
     */
    public static function sincronizarPlano(int $usuarioId, ?string $hoje = null): ?string
    {
        $cartao = CartaoModel::ativo();
        $cat = CartaoModel::categoriaFatura();
        if ($cartao === null || !$cartao['alimenta_plano'] || $cat === null) {
            return null;
        }
        $aberta = self::faturaDe($hoje ?? Relogio::hoje(), $cartao['fecha'], $cartao['vence']);
        $totais = self::totais(CartaoModel::compras($cartao['id']));
        $celulas = [];
        foreach (MesModel::todos() as $m) {
            if ($m['situacao'] === 'PREVISAO' && $m['mes'] >= $aberta) {
                $celulas[] = [$cat, (string) $m['mes'], (float) ($totais[$m['mes']] ?? 0.0)];
            }
        }
        return PrevisaoLote::gravar('cartao', $celulas, $usuarioId);
    }

    /** Os meses em que a Fatura do cartão vem do cartão (para o Mês mostrar a linha só para ler). */
    public static function mesesDoCartao(?string $hoje = null): ?string
    {
        $cartao = CartaoModel::ativo();
        return $cartao !== null && $cartao['alimenta_plano'] ? self::faturaDe($hoje ?? Relogio::hoje(), $cartao['fecha'], $cartao['vence']) : null;
    }

    /** A previsão de hoje da Fatura do cartão, mês a mês (para conferir antes de ligar), do cache do motor. */
    public static function previsaoAtual(int $categoriaFatura, string $de, int $meses = 15): array
    {
        $prev = MotorFinanceiro::dados()['prev'];
        $out = [];
        for ($i = 0; $i < $meses; $i++) {
            $m = self::somaMeses($de, $i);
            $out[$m] = round((float) ($prev[$m][$categoriaFatura] ?? 0.0), 2);
        }
        return $out;
    }
}
