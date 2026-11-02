<?php
namespace Financeiro\Services;

use Financeiro\Database;

/**
 * Dados do gráfico "gastos mês a mês": previsto e real por categoria × mês, só das
 * categorias operacionais de gasto (Dia a dia, Fixos, Cartao, Ano), SEM filtro de `ativo` — o
 * motor soma todas, e o gráfico tem que bater com ele. A renda vem do plano do motor. O previsto
 * é o da view previsao_efetiva (o do mês, senão a meta), o mesmo que o motor usa.
 */
final class GastosMensais
{
    public const GRUPOS = ['Dia a dia', 'Fixos', 'Cartao', 'Ano'];

    private static array $cache = [];

    /**
     * @param ?string $hoje 'Y-m-d' (padrão: hoje) — define o estado aberto/previsto e o plano usado.
     * @return array<string, array{
     *   mes:string, situacao:string, estado:string, renda:float,
     *   grupos: array<string, array{real:float, previsto:float}>,
     *   livre: array{real:float, previsto:float},
     *   fantasma: float,
     *   cats: array<int, array{id:int,nome:string,apelido:?string,grupo:string,essencial:bool,real:float,previsto:float,n:int}>
     * }>
     */
    public static function matriz(?string $hoje = null): array
    {
        $chave = $hoje ?? '';
        if (isset(self::$cache[$chave])) {
            return self::$cache[$chave];
        }

        $plano = $hoje === null
            ? MotorFinanceiro::calcularPlano()
            : MotorFinanceiro::calcular(MotorFinanceiro::dados(), ['hoje' => $hoje]);

        $sql = "SELECT m.mes, m.situacao, c.id, c.nome, c.apelido, c.grupo, c.essencial, c.ordem,
                       COALESCE(MAX(p.valor), 0) AS previsto,
                       COALESCE(SUM(l.valor), 0) AS realizado,
                       COUNT(l.id) AS n
                FROM meses m
                CROSS JOIN categorias c
                LEFT JOIN previsao_efetiva p ON p.categoria_id = c.id AND p.mes = m.mes
                LEFT JOIN lancamentos l ON l.categoria_id = c.id
                      AND l.data >= m.mes AND l.data < DATE_ADD(m.mes, INTERVAL 1 MONTH)
                WHERE c.operacional = 1 AND c.sinal = -1
                GROUP BY m.mes, m.situacao, c.id, c.nome, c.apelido, c.grupo, c.essencial, c.ordem
                ORDER BY m.mes, c.ordem, c.id";

        $out = [];
        foreach (Database::connection()->query($sql) as $r) {
            $mes = (string) $r['mes'];
            if (!isset($out[$mes])) {
                $linha = $plano[$mes] ?? null;
                $out[$mes] = [
                    'mes'      => $mes,
                    'situacao' => (string) $r['situacao'],
                    'estado'   => $linha['estado'] ?? 'previsto',
                    'renda'    => (float) ($linha['entra'] ?? 0.0),
                    'grupos'   => array_fill_keys(self::GRUPOS, ['real' => 0.0, 'previsto' => 0.0]),
                    'livre'    => ['real' => 0.0, 'previsto' => 0.0],
                    'fantasma' => 0.0,
                    'cats'     => [],
                ];
            }
            $grupo = (string) $r['grupo'];
            $real  = (float) $r['realizado'];
            $prev  = (float) $r['previsto'];
            $ess   = (int) $r['essencial'] === 1;

            $out[$mes]['grupos'][$grupo]['real']     += $real;
            $out[$mes]['grupos'][$grupo]['previsto'] += $prev;
            if ($grupo === 'Dia a dia' && !$ess) {
                $out[$mes]['livre']['real']     += $real;
                $out[$mes]['livre']['previsto'] += $prev;
            }
            if ($out[$mes]['estado'] === 'aberto') {
                $out[$mes]['fantasma'] += max(0.0, $prev - $real);
            }
            $out[$mes]['cats'][(int) $r['id']] = [
                'id' => (int) $r['id'], 'nome' => (string) $r['nome'],
                'apelido' => $r['apelido'] !== null ? (string) $r['apelido'] : null,
                'grupo' => $grupo, 'essencial' => $ess, 'real' => $real, 'previsto' => $prev, 'n' => (int) $r['n'],
            ];
        }

        // renda real: lançamentos de entrada para meses reais/abertos
        $sqlRenda = "SELECT DATE_FORMAT(l.data, '%Y-%m-01') AS mes, SUM(l.valor) AS total
                     FROM lancamentos l
                     JOIN categorias c ON c.id = l.categoria_id
                     WHERE c.operacional = 1 AND c.sinal = 1
                     GROUP BY DATE_FORMAT(l.data, '%Y-%m-01')";
        foreach (Database::connection()->query($sqlRenda) as $r) {
            $m = (string) $r['mes'];
            if (isset($out[$m]) && $out[$m]['estado'] !== 'previsto') {
                $out[$m]['renda'] = (float) $r['total'];
            }
        }

        // somas de centavos: arredonda para não exibir 5808.8199999
        foreach ($out as &$l) {
            foreach ($l['grupos'] as &$g) {
                $g['real'] = round($g['real'], 2);
                $g['previsto'] = round($g['previsto'], 2);
            }
            unset($g);
            $l['livre']['real'] = round($l['livre']['real'], 2);
            $l['livre']['previsto'] = round($l['livre']['previsto'], 2);
            $l['fantasma'] = round($l['fantasma'], 2);
            $l['renda'] = round($l['renda'], 2);
        }
        unset($l);

        return self::$cache[$chave] = $out;
    }

    /** Valor de um grupo como o gráfico o desenha: real→real; previsto→previsto; aberto→real. */
    public static function exibido(array $linhaMes, string $grupo): float
    {
        $g = $linhaMes['grupos'][$grupo] ?? ['real' => 0.0, 'previsto' => 0.0];
        return $linhaMes['estado'] === 'previsto' ? (float) $g['previsto'] : (float) $g['real'];
    }

    public static function limparCache(): void
    {
        self::$cache = [];
    }
}
