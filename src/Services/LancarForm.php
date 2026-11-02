<?php
namespace Financeiro\Services;

use Financeiro\Auth;
use Financeiro\Database;
use Financeiro\Models\CartaoModel;
use Financeiro\Models\CategoriaModel;
use Financeiro\Models\LancamentoModel;

/**
 * Dados do formulário de lançar. O layout chama dados() em toda página
 * (o <dialog id="lancar">), então: no máximo 4 consultas (6 com o cartão cadastrado), todas limitadas,
 * e cache por requisição.
 */
final class LancarForm
{
    /** Quantos botões de categoria aparecem antes do "Mais…": duas fileiras de 4 no celular. */
    public const RAPIDAS = 8;

    private static array $cache = [];

    /**
     * @return array{
     *   outras: array<string, list<array{id:int,nome:string,curto:string,grupo:string}>>,
     *   descricoes: string[], hoje:string, ontem:string, data_min:string, data_max:string,
     *   cartao: ?array{nome:string, fecha:int, vence:int, aberta:string, serie:list<array{0:string,1:float}>}
     * }
     * cartao = para o "Pagou com: Cartão" e a prévia (as próximas 14 faturas, da aberta em diante).
     * outras = a lista completa por classe, gastos antes de entradas; em cada grupo, o dia a dia primeiro,
     * pela frequência do usuário logado nos últimos 90 dias, depois a da casa, depois categorias.ordem.
     * rapidas = as RAPIDAS mais usadas (pela mesma frequência; sem uso, o dia a dia), os botões de um toque,
     * gastos antes de entradas.
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
        // 4) limites de data
        $lim = self::limites($mesHoje);

        // A lista completa por classe, gastos antes de entradas: essenciais, não essenciais, entradas e ⇄
        // (empréstimo/poupança). O dia a dia é distribuído nos grupos pela sua classe, com os mais usados primeiro.
        $outras = ['essenciais' => [], 'não essenciais' => [], 'entradas' => [], '⇄ movimentações' => []];
        $candidatas = [];
        foreach ($cats as $i => $c) {
            $id = (int) $c['id'];
            $base = ['id' => $id, 'nome' => (string) $c['nome'], 'curto' => nomeCurto($c), 'grupo' => (string) $c['grupo']];
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
            $classe = (int) $c['operacional'] === 0 ? 2 : ((int) $c['sinal'] === 1 ? 1 : 0);   // gasto · entrada · ⇄
            $outras[$k][] = $base + ['_ord' => [$ehDia ? 0 : 1, $fq[0], $fq[1], $i]];
            // botões: primeiro o que foi usado (do mais usado ao menos); sem uso, os gastos do dia a dia
            $candidatas[] = $base + ['_ord' => [$fq[0] + $fq[1] < 0 ? 0 : 1, $fq[0], $fq[1], $classe, $ehDia ? 0 : 1, $i], '_classe' => $classe];
        }
        usort($candidatas, static fn(array $a, array $b): int => $a['_ord'] <=> $b['_ord']);
        $rapidas = array_slice($candidatas, 0, self::RAPIDAS);
        usort($rapidas, static fn(array $a, array $b): int => [$a['_classe'], $a['_ord']] <=> [$b['_classe'], $b['_ord']]);
        $rapidas = array_map(static fn(array $c): array => array_diff_key($c, ['_ord' => 1, '_classe' => 1]), $rapidas);
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
            'rapidas'    => $rapidas,
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
