<?php
namespace Financeiro\Services;

use Financeiro\Auth;
use Financeiro\Database;
use Financeiro\Models\CartaoModel;
use Financeiro\Models\CategoriaModel;
use Financeiro\Models\LancamentoModel;
use Financeiro\Relogio;

/**
 * Dados do formulário de lançar. O layout chama dados() em toda página
 * (o <dialog id="lancar">), então: no máximo 4 consultas (6 com o cartão cadastrado), todas limitadas,
 * e cache por requisição.
 */
final class LancarForm
{
    private static array $cache = [];

    /**
     * @return array{
     *   outras: array<string, list<array{id:int,nome:string,curto:string,grupo:string}>>,
     *   descricoes: string[], hoje:string, ontem:string, data_min:string, data_max:string,
     *   cartao: ?array{nome:string, fecha:int, vence:int, aberta:string, serie:list<array{0:string,1:float}>}
     * }
     * cartao = para o "Pagou com: Cartão" e a prévia (as próximas 14 faturas, da aberta em diante).
     * outras = as categorias do select por classe; em cada grupo, o dia a dia primeiro, pela
     * frequência do usuário logado nos últimos 90 dias, depois a da casa, depois categorias.ordem.
     */
    public static function dados(?string $hoje = null): array
    {
        $hoje ??= Relogio::hoje();
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
        // 4) limites de data
        $lim = self::limites($mesHoje);

        // Grupos do select por classe: entradas, essenciais, não essenciais e ⇄ (empréstimo/poupança).
        // O dia a dia é distribuído nos grupos pela sua classe, com os mais usados primeiro.
        $outras = ['entradas' => [], 'essenciais' => [], 'não essenciais' => [], '⇄ movimentações' => []];
        foreach ($cats as $i => $c) {
            $id = (int) $c['id'];
            $base = ['id' => $id, 'nome' => (string) $c['nome'], 'curto' => nomeCurto($c)];
            $ehDia = $c['grupo'] === 'Dia a dia';
            $fq = [-($freq[$id]['meu'] ?? 0), -($freq[$id]['casa'] ?? 0)];
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
        foreach ($outras as &$g) {
            usort($g, static fn(array $a, array $b): int => $a['_ord'] <=> $b['_ord']);
            foreach ($g as &$it) {
                unset($it['_ord']);
            }
            unset($it);
        }
        unset($g);

        // 5–6) o cartão, se cadastrado: fechamento, vencimento e o que já está em cada fatura (para a prévia)
        $cartao = CartaoModel::ativo();
        $cartaoInfo = null;
        if ($cartao !== null) {
            $aberta = CartaoFatura::faturaDe($hoje, $cartao['fecha'], $cartao['vence']);
            $totais = CartaoFatura::totais(CartaoModel::compras($cartao['id']));
            $serie = [];
            for ($i = 0; $i < 14; $i++) {
                $m = CartaoFatura::somaMeses($aberta, $i);
                $serie[] = [$m, (float) ($totais[$m] ?? 0.0)];
            }
            $cartaoInfo = ['nome' => $cartao['nome'], 'fecha' => $cartao['fecha'], 'vence' => $cartao['vence'],
                           'aberta' => $aberta, 'serie' => $serie];
        }

        return self::$cache[$chave] = [
            'cartao'     => $cartaoInfo,
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
            'min'      => \Financeiro\Models\MesModel::primeiroDia() ?? ($pri ? (string) $pri : $mes),
            'max'      => $ult ? date('Y-m-t', strtotime((string) $ult)) : date('Y-m-t', strtotime($mes)),
            'situacao' => $r['sit'] ?? null,
        ];
    }
}
