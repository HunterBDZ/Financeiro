<?php
namespace Financeiro\Services;

use Financeiro\Models\CartaoModel;
use Financeiro\Relogio;

/**
 * O gasto de cada categoria mês a mês, com a fatura aberta nas compras: cada compra no cartão conta na categoria
 * dela, pela data da compra, parcela a parcela (a parcela i no mês da compra + i − 1; o estorno abate no mês dele).
 * Serve às Metas e ao "Para onde foi". O plano (MotorFinanceiro) continua por fatura, porque é quando o dinheiro sai
 * da conta; aqui só muda o mês e a categoria em que o gasto aparece, sem contar nada duas vezes:
 * - o pagamento ligado a um cartão (lancamentos.cartao_id) sai, e as compras daquele cartão entram no lugar;
 * - o que uma fatura paga tem a mais que as parcelas registradas nela fica na Fatura do cartão ("Fatura sem
 *   detalhe"), no mês do pagamento;
 * - o pagamento sem cartão fica na Fatura do cartão, como antes.
 * Compra sem categoria (ou numa categoria que não é gasto) fica em SEM_CATEGORIA. Compra antiga lançada com a data
 * do registro, depois da 1ª fatura, conta a partir do mês da 1ª fatura. Sem a aba Cartão ou sem cartão ativo, é o
 * mesmo que os lançamentos. É gasto realizado: o lançamento, o pagamento e a compra com data depois de hoje são
 * agendados e ficam de fora até o dia deles (MotorFinanceiro::realizado).
 */
final class GastoPorCategoria
{
    /** A "categoria" das compras no cartão sem categoria: "Cartão sem categoria". */
    public const SEM_CATEGORIA = 0;

    /** @var array<string, array{gasto: array<string, array<int, float>>, sem: array<string, array{valor:float, compras:list<int>}>, fatura:?int}> por dia */
    private static array $cache = [];

    /** O cartão abre a fatura nas categorias: a aba Cartão em uso, a categoria da fatura e um cartão ativo. */
    public static function ativo(): bool
    {
        return self::tudo()['fatura'] !== null;
    }

    /** A categoria da fatura quando ela está aberta nas compras (ver ativo()), senão null. */
    public static function categoriaFatura(): ?int
    {
        return self::tudo()['fatura'];
    }

    /**
     * O gasto de cada categoria de gasto operacional no mês (Y-m-01), com SEM_CATEGORIA para as compras sem categoria.
     * Pode ser negativo, se um estorno passar das compras da categoria no mês. Sem as que dão zero.
     * @param ?string $hoje Y-m-d (padrão: hoje): o que tem data depois dele é agendado e fica de fora
     * @return array<int, float>
     */
    public static function doMes(string $mes, ?string $hoje = null): array
    {
        return self::tudo($hoje)['gasto'][$mes] ?? [];
    }

    /** @return array<string, array<int, float>> todos os meses (Y-m-01) que têm gasto, em ordem, como doMes() */
    public static function todos(?string $hoje = null): array
    {
        return self::tudo($hoje)['gasto'];
    }

    /** @return ?array{valor:float, compras:list<int>} as compras sem categoria com parcela no mês, das mais antigas para as mais novas */
    public static function semCategoria(string $mes, ?string $hoje = null): ?array
    {
        return self::tudo($hoje)['sem'][$mes] ?? null;
    }

    public static function limparCache(): void
    {
        self::$cache = [];
    }

    private static function tudo(?string $hoje = null): array
    {
        $hoje ??= Relogio::hoje();
        if (isset(self::$cache[$hoje])) {
            return self::$cache[$hoje];
        }
        $dados = MotorFinanceiro::realizado($hoje);
        $ehGasto = static fn(?array $c): bool => $c !== null && $c['operacional'] === 1 && $c['sinal'] === -1;
        $gasto = [];
        foreach ($dados['real'] as $m => $porCat) {
            foreach ($porCat as $c => $v) {
                if ($ehGasto($dados['cats'][$c] ?? null)) {
                    $gasto[$m][$c] = ($gasto[$m][$c] ?? 0.0) + (float) $v;
                }
            }
        }

        $fat = ($dados['aj']['usa_cartao'] ?? 1.0) >= 1.0 && CartaoModel::ativos() !== [] ? CartaoModel::categoriaFatura() : null;
        $sem = [];
        if ($fat !== null) {
            // o pagamento ligado a um cartão sai; guarda quanto cada fatura recebeu e em que mês
            $pagas = [];
            foreach (CartaoModel::pagamentos($fat, $hoje) as $p) {
                $m = substr($p['data'], 0, 8) . '01';
                $gasto[$m][$fat] = ($gasto[$m][$fat] ?? 0.0) - $p['valor'];
                $k = $p['cartao_id'] . '|' . $p['fatura'];
                $pagas[$k]['pago'] = ($pagas[$k]['pago'] ?? 0.0) + $p['valor'];
                $pagas[$k]['meses'][$m] = ($pagas[$k]['meses'][$m] ?? 0.0) + $p['valor'];
            }
            // as compras entram pela data, parcela a parcela; a soma por fatura acha o que a fatura paga tem a mais
            $parcelas = [];
            foreach (CartaoModel::todasAsCompras($hoje) as $c) {
                $cat = $c['categoria_id'];
                if ($cat === null || $cat === $fat || !$ehGasto($dados['cats'][$cat] ?? null)) {
                    $cat = self::SEM_CATEGORIA;
                }
                $base = min(substr($c['data'], 0, 8) . '01', $c['primeira_fatura']);
                foreach (CartaoFatura::parcelas($c['valor_total'], $c['parcelas'], $c['primeira_fatura']) as [$f, $k, $v]) {
                    $m = CartaoFatura::somaMeses($base, $k - 1);
                    $gasto[$m][$cat] = ($gasto[$m][$cat] ?? 0.0) + $v;
                    $parcelas[$c['cartao_id'] . '|' . $f] = ($parcelas[$c['cartao_id'] . '|' . $f] ?? 0.0) + $v;
                    if ($cat === self::SEM_CATEGORIA) {
                        $sem[$m]['valor'] = ($sem[$m]['valor'] ?? 0.0) + $v;
                        $sem[$m]['compras'][$c['id']] = $c['id'];
                    }
                }
            }
            // "Fatura sem detalhe": o pago além das parcelas fica na Fatura do cartão, no mês de cada pagamento
            foreach ($pagas as $k => $x) {
                $resto = round($x['pago'] - ($parcelas[$k] ?? 0.0), 2);
                if ($resto <= CartaoFatura::TOLERANCIA) {
                    continue;
                }
                foreach ($x['meses'] as $m => $v) {
                    $gasto[$m][$fat] = ($gasto[$m][$fat] ?? 0.0) + $resto * $v / $x['pago'];
                }
            }
        }

        foreach ($gasto as $m => $porCat) {
            foreach ($porCat as $c => $v) {
                $v = round($v, 2);
                if (abs($v) < 0.005) {
                    unset($gasto[$m][$c]);
                } else {
                    $gasto[$m][$c] = $v;
                }
            }
        }
        foreach ($sem as $m => $s) {
            $sem[$m] = ['valor' => round($s['valor'], 2), 'compras' => array_values($s['compras'])];
            if ($sem[$m]['valor'] <= 0.004) {
                unset($sem[$m]);
            }
        }
        ksort($gasto);
        return self::$cache[$hoje] = ['gasto' => $gasto, 'sem' => $sem, 'fatura' => $fat];
    }
}
