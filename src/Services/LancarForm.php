<?php
namespace Financeiro\Services;

use Financeiro\Auth;
use Financeiro\Database;
use Financeiro\Models\CategoriaModel;
use Financeiro\Models\LancamentoModel;

/**
 * Dados do formulário de lançar (P4, SPEC §2.2). O layout chama dados() em toda página
 * (o <dialog id="lancar">), então: no máximo 5 consultas, todas limitadas, e cache por requisição.
 */
final class LancarForm
{
    private static array $cache = [];

    /**
     * @return array{
     *   chips: list<array{id:int,nome:string,curto:string,resta:?float}>,
     *   outras: array<string, list<array{id:int,nome:string,curto:string,grupo:string}>>,
     *   descricoes: string[], hoje:string, ontem:string, data_min:string, data_max:string
     * }
     * chips = as categorias ativas do Dia a dia, pela frequência do usuário logado nos últimos
     * 90 dias, depois a da casa, depois categorias.ordem. resta = previsto − real no mês de
     * $hoje; null quando esse mês está REAL (ou não existe): os chips mostram só o nome.
     */
    public static function dados(?string $hoje = null): array
    {
        $hoje ??= date('Y-m-d');
        $uid = (int) (Auth::id() ?? 0);
        $chave = $hoje . '|' . $uid;
        if (isset(self::$cache[$chave])) {
            return self::$cache[$chave];
        }

        $mesHoje = substr($hoje, 0, 8) . '01';
        $desde   = date('Y-m-d', strtotime($hoje . ' -90 days'));

        // 1) categorias ativas (com apelido)
        $cats = CategoriaModel::ativas();
        // 2) frequência por categoria (usuário e casa)
        $freq = LancamentoModel::frequenciaCategorias($uid, $desde);
        // 3) descrições mais usadas
        $descricoes = LancamentoModel::descricoesFrequentes($desde, 30);
        // 4) limites de data + situação do mês de hoje
        $lim = self::limites($mesHoje);
        // 5) previsto e real do Dia a dia no mês de hoje (só se ele estiver em PREVISAO)
        $resta = $lim['situacao'] === 'PREVISAO' ? self::restaDiaADia($mesHoje) : [];

        // Grupos do select por classe: entradas, essenciais, não essenciais e ⇄ (empréstimo/poupança).
        // O dia a dia é distribuído nos grupos pela sua classe, com os mais usados primeiro.
        $dia = [];
        $outras = ['entradas' => [], 'essenciais' => [], 'não essenciais' => [], '⇄ movimentações' => []];
        foreach ($cats as $i => $c) {
            $id = (int) $c['id'];
            $base = ['id' => $id, 'nome' => (string) $c['nome'], 'curto' => nomeCurto($c)];
            $ehDia = $c['grupo'] === 'Dia a dia';
            $fq = [-($freq[$id]['meu'] ?? 0), -($freq[$id]['casa'] ?? 0)];
            if ($ehDia) {
                $dia[] = $base + ['resta' => array_key_exists($id, $resta) ? $resta[$id] : null, '_ord' => [$fq[0], $fq[1], $i]];
            }
            if ((int) $c['operacional'] === 0) {
                $k = '⇄ movimentações';
            } elseif ((int) $c['sinal'] === 1) {
                $k = 'entradas';
            } elseif ((int) $c['essencial'] === 1) {
                $k = 'essenciais';
            } else {
                $k = 'não essenciais';
            }
            $outras[$k][] = $base + ['grupo' => (string) $c['grupo'], '_ord' => [$ehDia ? 0 : 1, $fq[0], $fq[1], $i]];
        }
        usort($dia, static fn(array $a, array $b): int => $a['_ord'] <=> $b['_ord']);
        foreach ($dia as &$d) {
            unset($d['_ord']);
        }
        unset($d);
        foreach ($outras as &$g) {
            usort($g, static fn(array $a, array $b): int => $a['_ord'] <=> $b['_ord']);
            foreach ($g as &$it) {
                unset($it['_ord']);
            }
            unset($it);
        }
        unset($g);

        return self::$cache[$chave] = [
            'chips'      => $dia,
            'outras'     => array_filter($outras),
            'descricoes' => $descricoes,
            'hoje'       => $hoje,
            'ontem'      => date('Y-m-d', strtotime($hoje . ' -1 day')),
            'data_min'   => $lim['min'],
            'data_max'   => $lim['max'],
        ];
    }

    public static function limparCache(): void
    {
        self::$cache = [];
    }

    /** Primeiro dia de MesModel::primeiro(), último dia de MesModel::ultimo() e a situação de $mes — 1 consulta. */
    private static function limites(string $mes): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT MIN(mes) AS pri, MAX(mes) AS ult, MAX(CASE WHEN mes = :m THEN situacao END) AS sit FROM meses'
        );
        $stmt->execute(['m' => $mes]);
        $r = $stmt->fetch() ?: [];
        $pri = $r['pri'] ?? null;
        $ult = $r['ult'] ?? null;
        return [
            'min'      => $pri ? (string) $pri : $mes,
            'max'      => $ult ? date('Y-m-t', strtotime((string) $ult)) : date('Y-m-t', strtotime($mes)),
            'situacao' => $r['sit'] ?? null,
        ];
    }

    /** [categoria_id => previsto − real] do Dia a dia ativo no mês — 1 consulta. */
    private static function restaDiaADia(string $mes): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT c.id,
                    COALESCE((SELECT p.valor FROM previsao p WHERE p.categoria_id = c.id AND p.mes = :m), 0) AS prev,
                    COALESCE((SELECT SUM(l.valor) FROM lancamentos l
                               WHERE l.categoria_id = c.id AND l.data >= :ini AND l.data < :fim), 0) AS gasto
             FROM categorias c
             WHERE c.grupo = 'Dia a dia' AND c.ativo = 1"
        );
        $stmt->execute(['m' => $mes, 'ini' => $mes, 'fim' => date('Y-m-01', strtotime($mes . ' +1 month'))]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['id']] = round((float) $r['prev'] - (float) $r['gasto'], 2);
        }
        return $out;
    }
}
