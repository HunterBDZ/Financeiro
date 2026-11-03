<?php
namespace Financeiro\Services;

/**
 * "Para onde vai o dinheiro do mês" (a barra do Início e do Mês) e as linhas que o livro-razão do Mês precisa além das
 * categorias de renda e de gasto. Nada é calculado de novo: tudo sai da linha do plano (MotorFinanceiro::calcular) e
 * dos dados do motor, com a mesma regra dele (no mês aberto, cada categoria conta o maior entre o lançado e o previsto).
 *
 * O dinheiro do mês é o que sobrou do mês anterior mais tudo o que entra (MotorFinanceiro::balancoTotal). Ele se
 * divide em: o que já saiu, as contas que faltam pagar, o que falta das metas do dia a dia, o que dá para gastar e a
 * reserva. As cinco partes somam o dinheiro do mês; quando o mês fecha abaixo de zero, as saídas passam dele e
 * 'falta' diz quanto.
 */
final class Destino
{
    /**
     * As partes do dinheiro do mês. null num mês fechado (não há o que dividir: tudo já aconteceu).
     * @param array $dados MotorFinanceiro::dados()
     * @param array $linha a linha do mês no plano (MotorFinanceiro::calcular()[mes])
     * @param string $mesHoje o mês de hoje, Y-m-01
     * @return ?array{total:float, saiu:float, contas:float, metas:float, gastar:float, reserva:float, falta:float, livre:float}
     *   livre = o que fica na conta no fim do mês sem o adiantamento automático do empréstimo (gastar + reserva − falta)
     */
    public static function doMes(array $dados, array $linha, string $mesHoje): ?array
    {
        $estado = (string) ($linha['estado'] ?? 'real');
        if ($estado === 'real') {
            return null;
        }
        $mes = (string) $linha['mes'];
        $aberto = $estado === 'aberto';
        [$entradas, $saidas] = MotorFinanceiro::balancoTotal($linha);
        $auto = (float) ($linha['amortizacao_auto'] ?? 0.0);
        $total = round((float) $linha['sobrou_mes_anterior'] + $entradas, 2);

        // o que ainda falta sair, categoria por categoria: no mês aberto, o previsto menos o lançado; no futuro, o previsto
        $p = MotorFinanceiro::previsaoDoMes($dados, $mes, $aberto ? 'aberto' : 'previsto', $mesHoje);
        $r = $aberto ? ($dados['real'][$mes] ?? []) : [];
        $contas = $metas = 0.0;
        foreach ($p + $r as $c => $_) {
            $cat = $dados['cats'][$c] ?? null;
            if ($cat === null || $cat['operacional'] !== 1 || $cat['sinal'] === 1) {
                continue;
            }
            $falta = max(0.0, (float) ($p[$c] ?? 0.0) - (float) ($r[$c] ?? 0.0));
            if ($cat['grupo'] === 'Dia a dia') {
                $metas += $falta;
            } else {
                $contas += $falta;
            }
        }
        // a parcela do empréstimo paga à parte é uma conta: a que ainda não foi lançada falta pagar
        $parc = $dados['esp']['parc'] ?? null;
        $contas += max(0.0, (float) ($linha['parcela_parte'] ?? 0.0) - ($parc !== null ? (float) ($r[$parc] ?? 0.0) : 0.0));

        $contas = round($contas, 2);
        $metas = round($metas, 2);
        $saiu = round(max(0.0, $saidas - $auto - $contas - $metas), 2);
        $livre = round($total - $saiu - $contas - $metas, 2);
        $reserva = max(0.0, (float) ($dados['aj']['reserva_minima'] ?? 0.0));
        return [
            'total' => $total, 'saiu' => $saiu, 'contas' => $contas, 'metas' => $metas,
            'gastar' => round(max(0.0, $livre - $reserva), 2), 'reserva' => round(max(0.0, min($reserva, $livre)), 2),
            'falta' => round(max(0.0, -$livre), 2), 'livre' => $livre,
        ];
    }

    /**
     * Os segmentos do partials/destino, na ordem do fato ao previsto; o azul marca o que dá para gastar.
     * @param array $d Destino::doMes()
     */
    public static function segmentos(array $d): array
    {
        return [
            ['rotulo' => t('Já saiu'), 'valor' => $d['saiu'], 'classe' => 's-saiu'],
            ['rotulo' => t('Contas a pagar'), 'valor' => $d['contas'], 'classe' => 's-contas'],
            ['rotulo' => t('Metas que faltam'), 'valor' => $d['metas'], 'classe' => 's-metas'],
            ['rotulo' => t('Dá para gastar'), 'valor' => $d['gastar'], 'classe' => 's-gastar', 'destaque' => true],
            ['rotulo' => t('Reserva'), 'valor' => $d['reserva'], 'classe' => 's-reserva'],
        ];
    }

    /**
     * O que foi guardado no mês até agora, já descontados os resgates: poupança e investimentos (o lançado).
     */
    public static function guardado(array $dados, string $mes): float
    {
        $r = $dados['real'][$mes] ?? [];
        $v = static fn(string $k): float => ($id = $dados['esp'][$k] ?? null) !== null ? (float) ($r[$id] ?? 0.0) : 0.0;
        return round($v('guard') - $v('resg') + $v('aplic') - $v('resgInv'), 2);
    }

    /**
     * As linhas do livro-razão que não são renda nem gasto, pela regra do plano: crédito e resgates (entram) e poupança,
     * investimentos, adiantamento, a parcela paga à parte e o adiantamento que o plano sugere sozinho (saem). Com as
     * linhas de renda e de gasto e o saldo do mês anterior, elas fecham no fim do mês do plano.
     * Cada linha: id (0 no adiantamento sugerido), cat (a categoria, null no sugerido), entra, valor (o que o plano conta),
     * real (o lançado), prev (o previsto no mês) e ult (o dia do último lançamento).
     * @return list<array{id:int, cat:?array, entra:bool, valor:float, real:float, prev:float, auto:bool, ult:?string}>
     */
    public static function movimentos(array $dados, array $linha): array
    {
        $mes = (string) $linha['mes'];
        $estado = (string) ($linha['estado'] ?? 'real');
        $r = $estado === 'previsto' ? [] : ($dados['real'][$mes] ?? []);
        $p = $dados['prev'][$mes] ?? [];
        $esp = $dados['esp'];
        $auto = (float) ($linha['amortizacao_auto'] ?? 0.0);
        $out = [];
        $ult = $dados['ult'][$mes] ?? [];
        $linhaDe = static function (?int $id, bool $entra, float $valor) use ($dados, $r, $p, $ult): ?array {
            if ($id === null || !isset($dados['cats'][$id])) {
                return null;
            }
            $real = round((float) ($r[$id] ?? 0.0), 2);
            $prev = round((float) ($p[$id] ?? 0.0), 2);
            $valor = round($valor, 2);
            if (abs($valor) < 0.005 && abs($real) < 0.005) {
                return null;
            }
            return ['id' => $id, 'cat' => $dados['cats'][$id], 'entra' => $entra, 'valor' => $valor, 'real' => $real, 'prev' => $prev, 'auto' => false,
                    'ult' => $real >= 0.005 ? ($ult[$id] ?? null) : null];
        };
        $em = static fn(?int $id, array $v): float => $id !== null ? (float) ($v[$id] ?? 0.0) : 0.0;
        // o crédito do empréstimo segue a regra das entradas; poupança e investimentos, só o lançado
        $cred = match ($estado) {
            'real'   => $em($esp['cred'], $r),
            'aberto' => max($em($esp['cred'], $r), $em($esp['cred'], $p)),
            default  => $em($esp['cred'], $p),
        };
        $out[] = $linhaDe($esp['cred'], true, $cred);
        $out[] = $linhaDe($esp['resg'], true, $em($esp['resg'], $r));
        $out[] = $linhaDe($esp['resgInv'] ?? null, true, $em($esp['resgInv'] ?? null, $r));
        $out[] = $linhaDe($esp['guard'], false, $em($esp['guard'], $r));
        $out[] = $linhaDe($esp['aplic'] ?? null, false, $em($esp['aplic'] ?? null, $r));
        $out[] = $linhaDe($esp['amort'], false, max(0.0, (float) ($linha['amortizacao_extra'] ?? 0.0) - $auto));
        $out[] = $linhaDe($esp['parc'] ?? null, false, (float) ($linha['parcela_parte'] ?? 0.0));
        $out = array_values(array_filter($out));
        if ($auto >= 0.005) {
            $out[] = ['id' => 0, 'cat' => null, 'entra' => false, 'valor' => round($auto, 2), 'real' => 0.0, 'prev' => 0.0, 'auto' => true, 'ult' => null];
        }
        return $out;
    }
}
