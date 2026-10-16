<?php
namespace Financeiro\Services;

/**
 * O que a aba Anual conta além dos números de cada mês: os marcos do plano (o mês mais apertado,
 * os meses no vermelho ou abaixo da reserva, quando a conta volta à reserva, quando o empréstimo
 * acaba, como termina) e, em cada mês, o que mais mudou em relação ao mês anterior.
 * Aritmética pura sobre MotorFinanceiro::calcularPlano() e ::dados(): nenhuma consulta própria.
 */
final class AnualResumo
{
    /** Mudança de uma categoria menor que isto (R$) não entra no "o que mudou". */
    public const LIMIAR = 150.0;

    /**
     * Marcos contados a partir do mês de referência (os meses fechados antes dele já passaram).
     * @param array<string, array> $plano mês => linha de MotorFinanceiro::calcular
     * @return array{
     *   apertado: ?array{mes:string, fica:float},
     *   faixas: list<array{tipo:string, ini:string, fim:string}>,
     *   volta: ?string, quita: ?string, quitaAntes: bool, meta: ?string,
     *   fim: ?array{mes:string, fica:float, desde:float}
     * }
     */
    public static function marcos(array $plano, float $reserva, string $ref): array
    {
        $out = ['apertado' => null, 'faixas' => [], 'volta' => null, 'quita' => null, 'quitaAntes' => false, 'meta' => null, 'fim' => null];
        $adiante = array_filter($plano, static fn($l) => (string) $l['mes'] >= $ref);
        if ($adiante === []) {
            return $out;
        }
        $primeiro = reset($adiante);
        foreach ($adiante as $l) {
            $fica = (float) $l['fica_na_conta'];
            if ($out['apertado'] === null || $fica < $out['apertado']['fica'] - 0.004) {
                $out['apertado'] = ['mes' => (string) $l['mes'], 'fica' => $fica];
            }
            // faixas seguidas de meses no vermelho (< 0) ou abaixo da reserva
            $tipo = $fica < -0.004 ? 'neg' : ($fica < $reserva - 0.004 ? 'warn' : null);
            $ult = $out['faixas'] === [] ? null : $out['faixas'][count($out['faixas']) - 1];
            if ($tipo !== null && $ult !== null && $ult['tipo'] === $tipo && self::seguinte($ult['fim']) === (string) $l['mes']) {
                $out['faixas'][count($out['faixas']) - 1]['fim'] = (string) $l['mes'];
            } elseif ($tipo !== null) {
                $out['faixas'][] = ['tipo' => $tipo, 'ini' => (string) $l['mes'], 'fim' => (string) $l['mes']];
            }
            if ($out['meta'] === null && !empty($l['bateu_meta'])) {
                $out['meta'] = (string) $l['mes'];
            }
        }
        // volta à reserva: o 1º mês depois do último abaixo dela (se houve algum e se ela volta)
        if ($out['faixas'] !== []) {
            $ultimoAbaixo = $out['faixas'][count($out['faixas']) - 1]['fim'];
            $depois = self::seguinte($ultimoAbaixo);
            $out['volta'] = isset($adiante[$depois]) ? $depois : null;
        }
        foreach ($plano as $l) {
            if (!empty($l['quitado'])) {
                $out['quita'] = (string) $l['mes'];
                $out['quitaAntes'] = (string) $l['mes'] < $ref;
                break;
            }
        }
        $ultimo = end($adiante);
        $out['fim'] = ['mes' => (string) $ultimo['mes'], 'fica' => (float) $ultimo['fica_na_conta'],
                       'desde' => (float) $ultimo['fica_na_conta'] - (float) $primeiro['fica_na_conta']];
        return $out;
    }

    /**
     * Em cada mês, até 3 coisas que mais mudaram em relação ao mês anterior: categorias
     * (entradas e gastos) e o desconto do consignado. Sinal do ponto de vista da conta:
     * efeito > 0 deixa o mês melhor (entrou mais ou gastou menos).
     * @return array<string, list<array{nome:string, valor:float, antes:float, efeito:float}>>
     */
    public static function mudancas(array $plano, array $dados): array
    {
        $out = [];
        $antes = null;
        $antesLinha = null;
        foreach ($plano as $mes => $l) {
            $agora = self::valoresDoMes($dados, $l);
            $lista = [];
            if ($antes !== null) {
                foreach ($agora + $antes as $c => $_) {
                    $cat = $dados['cats'][$c] ?? null;
                    $v = (float) ($agora[$c] ?? 0.0);
                    $a = (float) ($antes[$c] ?? 0.0);
                    if ($cat === null || abs($v - $a) < self::LIMIAR) {
                        continue;
                    }
                    $lista[] = ['nome' => self::nome($cat), 'valor' => $v, 'antes' => $a,
                                'efeito' => $cat['sinal'] === 1 ? $v - $a : $a - $v];
                }
                $p = (float) $l['parcela_consignado'];
                $pa = (float) $antesLinha['parcela_consignado'];
                if (abs($p - $pa) >= 0.005 && ($p < 0.005 || $pa < 0.005)) {   // começou ou acabou
                    $lista[] = ['nome' => 'Desconto do consignado', 'valor' => $p, 'antes' => $pa, 'efeito' => $pa - $p];
                }
                usort($lista, static fn($x, $y) => abs($y['efeito']) <=> abs($x['efeito']));
            }
            $out[(string) $mes] = array_slice($lista, 0, 3);
            $antes = $agora;
            $antesLinha = $l;
        }
        return $out;
    }

    /**
     * O que adiantar a sobra rende, comparando o plano com o mesmo plano sem adiantamento (só as
     * parcelas): quando o empréstimo acaba em cada um e quantos juros se deixa de pagar. Depois do
     * último mês do plano, o "só as parcelas" segue com a parcela e os juros do mês até zerar.
     * Null quando o plano não adianta nada ou não quita dentro do horizonte.
     * @param array<string, array> $com plano atual; $sem plano com objetivo 3 (sem adiantamento automático)
     * @return ?array{quitaCom:string, quitaSem:string, meses:int, juros:float}
     */
    public static function ganho(array $com, array $sem, float $parcela, float $jurosMes): ?array
    {
        $quitaCom = null;
        $adiantou = false;
        $jurosCom = $jurosSem = 0.0;
        foreach ($com as $mes => $l) {
            $jurosCom += (float) $l['juros'];
            $adiantou = $adiantou || ($l['estado'] !== 'real' && (float) $l['amortizacao_extra'] > 0.004);
            if ($quitaCom === null && !empty($l['quitado'])) {
                $quitaCom = (string) $mes;
            }
        }
        if ($quitaCom === null || !$adiantou || $sem === []) {
            return null;
        }
        $quitaSem = null;
        foreach ($sem as $mes => $l) {
            $jurosSem += (float) $l['juros'];
            if ($quitaSem === null && !empty($l['quitado'])) {
                $quitaSem = (string) $mes;
            }
        }
        if ($quitaSem === null) {
            $ult = end($sem);
            $div = (float) $ult['falta_pagar'];
            $k = 0;
            while ($div > 0.01 && $k < 600 && $parcela > 0.004) {
                $j = $div * $jurosMes;
                $jurosSem += $j;
                $div = max(0.0, $div + $j - $parcela);
                $k++;
            }
            if ($div > 0.01) {
                return null;   // a parcela não cobre os juros: não dá para dizer quando acabaria
            }
            $quitaSem = date('Y-m-01', strtotime((string) $ult['mes'] . " +{$k} month"));
        }
        $meses = ((int) substr($quitaSem, 0, 4) - (int) substr($quitaCom, 0, 4)) * 12
               + (int) substr($quitaSem, 5, 2) - (int) substr($quitaCom, 5, 2);
        return ['quitaCom' => $quitaCom, 'quitaSem' => $quitaSem, 'meses' => $meses, 'juros' => max(0.0, $jurosSem - $jurosCom)];
    }

    /**
     * Valor de cada categoria operacional no mês, pela mesma regra do motor: fechado = lançado;
     * aberto = o maior entre lançado e previsto; seguinte = previsto.
     * @return array<int, float>
     */
    public static function valoresDoMes(array $dados, array $linha): array
    {
        $mes = (string) $linha['mes'];
        $real = $dados['real'][$mes] ?? [];
        $prev = $dados['prev'][$mes] ?? [];
        $estado = (string) ($linha['estado'] ?? 'previsto');
        $base = $estado === 'real' ? $real : ($estado === 'aberto' ? $prev + $real : $prev);
        $out = [];
        foreach ($base as $c => $_) {
            $cat = $dados['cats'][$c] ?? null;
            if ($cat === null || $cat['operacional'] !== 1) {
                continue;
            }
            $v = match ($estado) {
                'real'   => (float) $real[$c],
                'aberto' => max((float) ($real[$c] ?? 0.0), (float) ($prev[$c] ?? 0.0)),
                default  => (float) $prev[$c],
            };
            if (abs($v) >= 0.005) {
                $out[(int) $c] = $v;
            }
        }
        return $out;
    }

    /** "Outras entradas (13º, Pix, reembolsos)" → "Outras entradas": o apelido, ou o nome sem o parêntese. */
    private static function nome(array $cat): string
    {
        if (!empty($cat['apelido'])) {
            return (string) $cat['apelido'];
        }
        return trim((string) preg_replace('/\s*\(.*\)\s*$/u', '', (string) $cat['nome']));
    }

    private static function seguinte(string $mes): string
    {
        return date('Y-m-01', strtotime($mes . ' +1 month'));
    }
}
