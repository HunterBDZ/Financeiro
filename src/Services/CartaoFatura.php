<?php
namespace Financeiro\Services;

use Financeiro\Database;
use Financeiro\Models\AjusteModel;
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
     * De que fatura é cada pagamento. A regra mora só aqui: desde a 2.0, o vínculo fica gravado no pagamento
     * (lancamentos.cartao_id e .fatura), e esta função preenche os antigos (preencherPagamentos) e os lançados
     * sem dizer a fatura (vinculoAutomatico, um por vez). Em ordem de data, cada pagamento vai
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
        $comFatura = [];
        foreach (self::atribuir($pagamentos, $fecha, $vence, $valores) as $k => $f) {
            $comFatura[$k] = ['fatura' => $f] + $pagamentos[$k];
        }
        return self::somarPorFatura($comFatura, $valores);
    }

    /**
     * Pagamentos que já dizem a fatura que pagaram (lancamentos.fatura), somados por fatura, na ordem recebida.
     * @param array<array-key, array{data:string, valor:float, fatura:string}> $pagamentos
     * @return array<string, array{valor:float, pago:float, primeiro:string, ultimo:string, meses:array<string, float>}> ver porFaturaPaga()
     */
    public static function somarPorFatura(array $pagamentos, array $valores): array
    {
        $out = [];
        foreach ($pagamentos as $p) {
            $f = (string) $p['fatura'];
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
     * Os pagamentos de fatura, cada um na fatura que pagou (lancamentos.cartao_id e .fatura), para o plano, o Mês e a
     * aba. O valor de cada fatura é o maior entre a soma das parcelas lançadas e, com um cartão só, a previsão da
     * Fatura do cartão no mês dela: o cartão pode não ter todas as compras, e a previsão pode ser só uma estimativa.
     * Sem cartão (ou sem a aba Cartão), cat é null e a Fatura do cartão segue a regra das outras contas: o pagamento
     * fica no mês da data. Com vários cartões, faturas soma os de todos; porCartao separa.
     * @param array<string, array<int, float>> $prev a previsão efetiva de cada mês (MotorFinanceiro::dados())
     * @return array{cat:?int, vence:int, faturas:array<string, array>, porCartao:array<int, array<string, array>>} ver porFaturaPaga()
     */
    public static function faturasPagas(array $prev, bool $usaCartao): array
    {
        $cartoes = $usaCartao ? CartaoModel::ativos() : [];
        $cat = $cartoes !== [] ? CartaoModel::categoriaFatura() : null;
        if ($cartoes === [] || $cat === null) {
            return ['cat' => null, 'vence' => 0, 'faturas' => [], 'porCartao' => []];
        }
        $porCartao = [];
        foreach (CartaoModel::pagamentos($cat) as $p) {
            $porCartao[$p['cartao_id']][] = $p;
        }
        $faturas = $separadas = [];
        foreach ($porCartao as $id => $pagamentos) {
            $separadas[$id] = self::somarPorFatura($pagamentos, self::valores($id, $prev, $cat, count($cartoes) === 1));
            foreach ($separadas[$id] as $f => $x) {
                if (!isset($faturas[$f])) {
                    $faturas[$f] = $x;
                    continue;
                }
                $faturas[$f]['valor'] = round($faturas[$f]['valor'] + $x['valor'], 2);
                $faturas[$f]['pago'] = round($faturas[$f]['pago'] + $x['pago'], 2);
                $faturas[$f]['primeiro'] = min($faturas[$f]['primeiro'], $x['primeiro']);
                $faturas[$f]['ultimo'] = max($faturas[$f]['ultimo'], $x['ultimo']);
                foreach ($x['meses'] as $m => $v) {
                    $faturas[$f]['meses'][$m] = round(($faturas[$f]['meses'][$m] ?? 0.0) + $v, 2);
                }
            }
        }
        ksort($faturas);
        return ['cat' => $cat, 'vence' => $cartoes[0]['vence'], 'faturas' => $faturas, 'porCartao' => $separadas];
    }

    /**
     * Quanto cobra cada fatura de um cartão, para saber quando ela está toda paga: a soma das parcelas e, se é o único
     * cartão, o maior entre ela e a previsão da Fatura do cartão (com vários, a previsão é a de todos juntos).
     * @return array<string, float>
     */
    private static function valores(int $cartaoId, array $prev, int $cat, bool $unico): array
    {
        $valores = self::totais(CartaoModel::compras($cartaoId));
        foreach ($unico ? $prev : [] as $mes => $p) {
            if (isset($p[$cat])) {
                $valores[$mes] = max($valores[$mes] ?? 0.0, (float) $p[$cat]);
            }
        }
        return $valores;
    }

    /**
     * O cartão e a fatura de um pagamento lançado sem dizer qual: a regra de atribuir(), um pagamento por vez, sobre o
     * que já está pago de cada fatura do cartão. Sem cartão escolhido, vale o padrão (o primeiro ativo).
     * @return ?array{cartao_id:int, fatura:string} null sem cartão
     */
    public static function vinculoAutomatico(string $data, ?int $cartaoId = null, ?int $ignorar = null): ?array
    {
        $cartoes = CartaoModel::ativos();
        $cartao = $cartaoId !== null ? CartaoModel::achar($cartaoId) : ($cartoes[0] ?? null);
        $cat = CartaoModel::categoriaFatura();
        if ($cartao === null || $cat === null) {
            return null;
        }
        $st = Database::connection()->prepare(
            'SELECT fatura, SUM(valor) FROM lancamentos WHERE categoria_id = :cat AND cartao_id = :c AND id <> :id GROUP BY fatura'
        );
        $st->execute(['cat' => $cat, 'c' => $cartao['id'], 'id' => $ignorar ?? 0]);
        $pago = array_map('floatval', $st->fetchAll(\PDO::FETCH_KEY_PAIR));
        $valores = self::valores($cartao['id'], MotorFinanceiro::dados()['prev'], $cat, count($cartoes) <= 1);
        $f = self::ultimaFechada($data, $cartao['fecha'], $cartao['vence']);
        if (self::todaPaga($pago[$f] ?? null, (float) ($valores[$f] ?? 0.0))) {
            $f = self::somaMeses($f, 1);
        }
        return ['cartao_id' => $cartao['id'], 'fatura' => $f];
    }

    /**
     * Liga os pagamentos de fatura sem cartão ao primeiro cartão ativo com atribuir(), a regra da 1.10: na migração
     * para a 2.0 e quando o primeiro cartão é cadastrado depois de já haver pagamentos. Usa só $pdo, porque a migração
     * de um backup roda numa conexão à parte. O valor de cada fatura é o da 1.10: o maior entre a soma das parcelas e
     * a previsão efetiva da Fatura do cartão, com a meta contada só pelos dias que faltam no mês do início.
     * @return int quantos pagamentos ligou
     */
    public static function preencherPagamentos(\PDO $pdo): int
    {
        $cartao = $pdo->query('SELECT id, dia_fechamento, dia_vencimento FROM cartoes WHERE ativo = 1 ORDER BY ordem, id LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        $cat = $pdo->query("SELECT id FROM categorias WHERE chave = 'fatura' AND ativo = 1")->fetchColumn();
        if ($cartao === false || $cat === false) {
            return 0;
        }
        $cat = (int) $cat;
        $st = $pdo->prepare('SELECT id, data, valor FROM lancamentos WHERE categoria_id = :c AND cartao_id IS NULL ORDER BY data, id');
        $st->execute(['c' => $cat]);
        $pagamentos = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $pagamentos[(int) $r['id']] = ['data' => (string) $r['data'], 'valor' => (float) $r['valor']];
        }
        if ($pagamentos === []) {
            return 0;
        }
        $st = $pdo->prepare('SELECT valor_total, parcelas, primeira_fatura FROM cartao_compras WHERE cartao_id = :c');
        $st->execute(['c' => (int) $cartao['id']]);
        $valores = self::totais($st->fetchAll(\PDO::FETCH_ASSOC));
        // a previsão efetiva como MotorFinanceiro::dados() a lê: no mês do início, a meta só pelos dias que faltam
        $ini = $pdo->query("SELECT valor FROM ajustes WHERE chave = 'data_inicio'")->fetchColumn();
        $inicio = $ini !== false ? AjusteModel::dataDe((float) $ini) : null;
        $mesIni = $inicio !== null && (int) substr($inicio, 8, 2) > 1 ? substr($inicio, 0, 8) . '01' : null;
        $st = $pdo->prepare('SELECT mes, valor, fonte FROM previsao_efetiva WHERE categoria_id = :c AND fonte IS NOT NULL');
        $st->execute(['c' => $cat]);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $mes = (string) $r['mes'];
            $v = (float) $r['valor'];
            if ($mes === $mesIni && $r['fonte'] !== 'mes') {
                $v = round($v * ((diasNoMes($mes) - (int) substr((string) $inicio, 8, 2) + 1) / diasNoMes($mes)), 2);
            }
            $valores[$mes] = max($valores[$mes] ?? 0.0, $v);
        }
        $up = $pdo->prepare('UPDATE lancamentos SET cartao_id = :c, fatura = :f WHERE id = :id');
        foreach (self::atribuir($pagamentos, (int) $cartao['dia_fechamento'], (int) $cartao['dia_vencimento'], $valores) as $id => $f) {
            $up->execute(['c' => (int) $cartao['id'], 'f' => $f, 'id' => $id]);
        }
        return count($pagamentos);
    }

    /**
     * Com cartões ligados ao plano: a previsão da Fatura do cartão em cada mês em PREVISAO, da fatura aberta em
     * diante, passa a ser a soma das parcelas daquela fatura nos cartões ligados (lote 'cartao', com log e desfazer).
     * Meses antes da aberta não mudam: a fatura já fechou e o valor é o do banco. Com cartões de fechamentos
     * diferentes, vale a aberta mais antiga entre eles.
     * @return ?string o lote, ou null se nada mudou (ou nenhum cartão alimenta o plano)
     */
    public static function sincronizarPlano(int $usuarioId, ?string $hoje = null): ?string
    {
        $ligados = self::ligados();
        $cat = CartaoModel::categoriaFatura();
        if ($ligados === [] || $cat === null) {
            return null;
        }
        $aberta = self::abertaDosLigados($ligados, $hoje ?? Relogio::hoje());
        $totais = [];
        foreach ($ligados as $c) {
            foreach (self::totais(CartaoModel::compras($c['id'])) as $m => $v) {
                $totais[$m] = round(($totais[$m] ?? 0.0) + $v, 2);
            }
        }
        $celulas = [];
        foreach (MesModel::todos() as $m) {
            if ($m['situacao'] === 'PREVISAO' && $m['mes'] >= $aberta) {
                $celulas[] = [$cat, (string) $m['mes'], (float) ($totais[$m['mes']] ?? 0.0)];
            }
        }
        return PrevisaoLote::gravar('cartao', $celulas, $usuarioId);
    }

    /** @return list<array> os cartões ativos que alimentam o plano */
    private static function ligados(): array
    {
        return array_values(array_filter(CartaoModel::ativos(), static fn(array $c): bool => $c['alimenta_plano']));
    }

    /** @param non-empty-list<array> $ligados */
    private static function abertaDosLigados(array $ligados, string $hoje): string
    {
        return min(array_map(static fn(array $c): string => self::faturaDe($hoje, $c['fecha'], $c['vence']), $ligados));
    }

    /** Os meses em que a Fatura do cartão vem do cartão (para o Mês mostrar a linha só para ler). */
    public static function mesesDoCartao(?string $hoje = null): ?string
    {
        $ligados = self::ligados();
        return $ligados !== [] ? self::abertaDosLigados($ligados, $hoje ?? Relogio::hoje()) : null;
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
