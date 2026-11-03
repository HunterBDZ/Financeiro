<?php
namespace Financeiro\Services;

use Financeiro\Auth;
use Financeiro\Database;
use Financeiro\Models\CartaoModel;
use Financeiro\Models\CategoriaModel;
use Financeiro\Models\ContaModel;
use Financeiro\Models\LancamentoModel;
use Financeiro\Relogio;

/**
 * Dados do formulário de lançar. O layout chama dados() em toda página
 * (o <dialog id="lancar">), então: no máximo 4 consultas (mais 2 por cartão e 3 com várias contas), todas
 * limitadas, e cache por requisição.
 */
final class LancarForm
{
    private static array $cache = [];

    /**
     * @return array{
     *   grupos: array<string, list<array{id:int,nome:string,curto:string,grupo:string}>>,
     *   descricoes: string[], hoje:string, ontem:string, data_min:string, data_max:string,
     *   cartao: ?array{id:int, nome:string, fecha:int, vence:int, aberta:string, serie:list<array{0:string,1:float}>},
     *   cartoes: list<array>, contas: list<array>, padrao: ?string
     * }
     * cartao = para o "Pagou com: Cartão" e a prévia (as próximas 14 faturas, da aberta em diante): o primeiro cartão;
     * cartoes = todos, no mesmo formato. contas = as contas ativas, só com mais de uma (com uma, nada muda na tela);
     * padrao = com várias contas, o "Pagou com" da última vez desta pessoa (conta:id ou cartao:id).
     * grupos = as categorias da lista do campo Categoria, por classe, gastos antes de entradas; em cada grupo,
     * o dia a dia primeiro, pela frequência do usuário logado nos últimos 90 dias, depois a da casa, depois
     * categorias.ordem. Cada uma traz entra (renda ou resgate): com várias contas, o "Pagou com" vira "Recebeu em".
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

        // A lista completa por classe, gastos antes de entradas: essenciais, não essenciais, entradas e ⇄
        // (empréstimo/poupança). O dia a dia é distribuído nos grupos pela sua classe, com os mais usados primeiro.
        $grupos = ['essenciais' => [], 'não essenciais' => [], 'entradas' => [], '⇄ movimentações' => []];
        foreach ($cats as $i => $c) {
            $id = (int) $c['id'];
            // entra: dinheiro que chega (renda ou resgate); com várias contas, o "Pagou com" vira "Recebeu em"
            $base = ['id' => $id, 'nome' => (string) $c['nome'], 'curto' => nomeCurto($c), 'grupo' => (string) $c['grupo'], 'entra' => (int) $c['sinal'] === 1];
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
            $grupos[$k][] = $base + ['_ord' => [$ehDia ? 0 : 1, $fq[0], $fq[1], $i]];
        }
        foreach ($grupos as &$g) {
            usort($g, static fn(array $a, array $b): int => $a['_ord'] <=> $b['_ord']);
            foreach ($g as &$it) {
                unset($it['_ord']);
            }
            unset($it);
        }
        unset($g);

        // 5–6) os cartões: fechamento, vencimento e o que já está em cada fatura (para a prévia)
        $cartoes = [];
        foreach (CartaoModel::ativos() as $cartao) {
            $aberta = CartaoFatura::faturaDe($hoje, $cartao['fecha'], $cartao['vence']);
            $totais = CartaoFatura::totais(CartaoModel::compras($cartao['id']));
            $serie = [];
            for ($i = 0; $i < 14; $i++) {
                $m = CartaoFatura::somaMeses($aberta, $i);
                $serie[] = [$m, (float) ($totais[$m] ?? 0.0)];
            }
            $cartoes[] = ['id' => $cartao['id'], 'nome' => $cartao['nome'], 'fecha' => $cartao['fecha'], 'vence' => $cartao['vence'],
                          'aberta' => $aberta, 'serie' => $serie];
        }
        // 7–9) com várias contas: elas e o "Pagou com" da última vez
        $contas = ContaModel::varias() ? ContaModel::ativas() : [];

        return self::$cache[$chave] = [
            'cartao'     => $cartoes[0] ?? null,
            'cartoes'    => $cartoes,
            'contas'     => $contas,
            'padrao'     => $contas !== [] ? self::ultimaUsada($uid, $contas, $cartoes) : null,
            'grupos'     => array_filter($grupos),
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

    /**
     * O "Pagou com" do último lançamento ou compra desta pessoa, se a conta ou o cartão ainda está ativo.
     * @return ?string conta:id ou cartao:id
     */
    private static function ultimaUsada(int $uid, array $contas, array $cartoes): ?string
    {
        $pdo = Database::connection();
        $l = $pdo->prepare('SELECT conta_id, criado_em FROM lancamentos WHERE usuario_id = :u ORDER BY criado_em DESC, id DESC LIMIT 1');
        $l->execute(['u' => $uid]);
        $l = $l->fetch();
        $c = $pdo->prepare('SELECT cartao_id, criado_em FROM cartao_compras WHERE usuario_id = :u ORDER BY criado_em DESC, id DESC LIMIT 1');
        $c->execute(['u' => $uid]);
        $c = $c->fetch();
        $ativas = array_column($contas, 'id');
        $ativos = array_column($cartoes, 'id');
        if ($c && (!$l || (string) $c['criado_em'] > (string) $l['criado_em']) && in_array((int) $c['cartao_id'], $ativos, true)) {
            return 'cartao:' . (int) $c['cartao_id'];
        }
        return $l && in_array((int) $l['conta_id'], $ativas, true) ? 'conta:' . (int) $l['conta_id'] : null;
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
