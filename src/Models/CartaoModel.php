<?php
namespace Financeiro\Models;

use Financeiro\Database;
use Financeiro\Sql;

/**
 * O cartão de crédito da casa (tabela `cartoes`; por enquanto um ativo, a tabela aceita mais) e as
 * compras feitas nele (`cartao_compras`). As parcelas não têm tabela: saem de valor_total, parcelas
 * e primeira_fatura (ver CartaoFatura). As compras não contam nas categorias: no plano só a fatura.
 */
final class CartaoModel
{
    /** O cartão ativo, ou null antes de cadastrar. */
    public static function ativo(): ?array
    {
        $r = Database::connection()->query('SELECT * FROM cartoes WHERE ativo = 1 ORDER BY id LIMIT 1')->fetch();
        return $r ? self::cartao($r) : null;
    }

    /** Cadastra (id null) ou atualiza o cartão; devolve o id. */
    public static function salvar(?int $id, string $nome, int $fecha, int $vence, ?float $limite): int
    {
        $pdo = Database::connection();
        $dados = ['n' => $nome, 'f' => $fecha, 'v' => $vence, 'l' => $limite];
        if ($id === null) {
            $pdo->prepare('INSERT INTO cartoes (nome, dia_fechamento, dia_vencimento, limite) VALUES (:n, :f, :v, :l)')->execute($dados);
            return (int) $pdo->lastInsertId();
        }
        $pdo->prepare('UPDATE cartoes SET nome = :n, dia_fechamento = :f, dia_vencimento = :v, limite = :l WHERE id = :id')
            ->execute($dados + ['id' => $id]);
        return $id;
    }

    /**
     * Traz as compras da tabela da versão antiga do app (cartao_compras_v1) para o cartão recém-cadastrado:
     * valor_total = valor_parcela × n_parcelas, e a 1ª fatura é o mês da 1ª parcela. Sem categoria (a
     * versão antiga não guardava). Só importa uma vez: as que já vieram ficam marcadas pelo id antigo.
     * @return int quantas vieram
     */
    public static function importarAntigas(int $cartaoId): int
    {
        $pdo = Database::connection();
        // esta tabela só existe em site antigo, em MySQL; o SQLite nunca chega ao INSERT abaixo
        if (!Sql::tabelaExiste($pdo, 'cartao_compras_v1')) {
            return 0;
        }
        $st = $pdo->prepare(
            "INSERT INTO cartao_compras (cartao_id, data, descricao, categoria_id, valor_total, parcelas, primeira_fatura, usuario_id, criado_em)
             SELECT :c, DATE(v.criado_em), v.descricao, NULL, ROUND(v.valor_parcela * v.n_parcelas, 2), LEAST(GREATEST(v.n_parcelas, 1), 48),
                    DATE_FORMAT(v.data_primeira_parcela, '%Y-%m-01'), v.usuario_id, v.criado_em
             FROM cartao_compras_v1 v
             WHERE NOT EXISTS (SELECT 1 FROM cartao_compras n WHERE n.descricao = v.descricao AND n.criado_em = v.criado_em)"
        );
        $st->execute(['c' => $cartaoId]);
        return $st->rowCount();
    }

    public static function setAlimentaPlano(int $id, bool $sim): void
    {
        Database::connection()->prepare('UPDATE cartoes SET alimenta_plano = :s WHERE id = :id')->execute(['s' => $sim ? 1 : 0, 'id' => $id]);
    }

    /** @return list<array> as compras do cartão, com o nome e o grupo da categoria; as mais antigas primeiro. */
    public static function compras(int $cartaoId): array
    {
        $st = Database::connection()->prepare(
            'SELECT cc.*, c.nome AS categoria_nome, c.apelido AS categoria_apelido, c.grupo AS categoria_grupo
             FROM cartao_compras cc LEFT JOIN categorias c ON c.id = cc.categoria_id
             WHERE cc.cartao_id = :c ORDER BY cc.primeira_fatura, cc.data, cc.id'
        );
        $st->execute(['c' => $cartaoId]);
        return array_map([self::class, 'compra'], $st->fetchAll());
    }

    public static function acharCompra(int $id): ?array
    {
        $st = Database::connection()->prepare(
            'SELECT cc.*, c.nome AS categoria_nome, c.apelido AS categoria_apelido, c.grupo AS categoria_grupo
             FROM cartao_compras cc LEFT JOIN categorias c ON c.id = cc.categoria_id WHERE cc.id = :id'
        );
        $st->execute(['id' => $id]);
        $r = $st->fetch();
        return $r ? self::compra($r) : null;
    }

    public static function criarCompra(int $cartaoId, string $data, string $descricao, ?int $categoriaId, float $total,
                                       int $parcelas, string $primeiraFatura, int $usuarioId): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO cartao_compras (cartao_id, data, descricao, categoria_id, valor_total, parcelas, primeira_fatura, usuario_id)
             VALUES (:c, :d, :ds, :cat, :t, :p, :pf, :u)'
        )->execute(['c' => $cartaoId, 'd' => $data, 'ds' => $descricao, 'cat' => $categoriaId, 't' => round($total, 2),
                    'p' => $parcelas, 'pf' => $primeiraFatura, 'u' => $usuarioId]);
        return (int) $pdo->lastInsertId();
    }

    public static function atualizarCompra(int $id, string $data, string $descricao, ?int $categoriaId, float $total,
                                           int $parcelas, string $primeiraFatura): void
    {
        Database::connection()->prepare(
            'UPDATE cartao_compras SET data = :d, descricao = :ds, categoria_id = :cat, valor_total = :t, parcelas = :p,
                    primeira_fatura = :pf WHERE id = :id'
        )->execute(['d' => $data, 'ds' => $descricao, 'cat' => $categoriaId, 't' => round($total, 2), 'p' => $parcelas,
                    'pf' => $primeiraFatura, 'id' => $id]);
    }

    public static function excluirCompra(int $id): void
    {
        Database::connection()->prepare('DELETE FROM cartao_compras WHERE id = :id')->execute(['id' => $id]);
    }

    /** Desfazer a exclusão: a mesma linha de volta, com o mesmo id. */
    public static function restaurarCompra(array $c): void
    {
        Database::connection()->prepare(
            'INSERT INTO cartao_compras (id, cartao_id, data, descricao, categoria_id, valor_total, parcelas, primeira_fatura, usuario_id, criado_em)
             VALUES (:id, :c, :d, :ds, :cat, :t, :p, :pf, :u, :cr)'
        )->execute(['id' => $c['id'], 'c' => $c['cartao_id'], 'd' => $c['data'], 'ds' => $c['descricao'], 'cat' => $c['categoria_id'],
                    't' => $c['valor_total'], 'p' => $c['parcelas'], 'pf' => $c['primeira_fatura'], 'u' => $c['usuario_id'],
                    'cr' => $c['criado_em']]);
    }

    /** A categoria que recebe a fatura no plano ("Fatura do cartão", grupo Cartao), ou null. */
    public static function categoriaFatura(): ?int
    {
        $v = Database::connection()->query(
            "SELECT id FROM categorias WHERE grupo = 'Cartao' AND ativo = 1 AND sinal = -1 ORDER BY ordem, id LIMIT 1"
        )->fetchColumn();
        return $v === false ? null : (int) $v;
    }

    /**
     * Quanto foi pago de fatura em cada mês (lançamentos da categoria da fatura).
     * @return array<string, float> mês (Y-m-01) => total pago
     */
    public static function pagamentos(int $categoriaFatura): array
    {
        $st = Database::connection()->prepare(
            'SELECT ' . Sql::inicioMes('data') . ' AS mes, SUM(valor) AS v FROM lancamentos WHERE categoria_id = :c GROUP BY mes'
        );
        $st->execute(['c' => $categoriaFatura]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(string) $r['mes']] = (float) $r['v'];
        }
        return $out;
    }

    private static function cartao(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'nome' => (string) $r['nome'], 'fecha' => (int) $r['dia_fechamento'],
            'vence' => (int) $r['dia_vencimento'], 'limite' => $r['limite'] !== null ? (float) $r['limite'] : null,
            'alimenta_plano' => (int) $r['alimenta_plano'] === 1,
        ];
    }

    private static function compra(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'cartao_id' => (int) $r['cartao_id'], 'data' => (string) $r['data'],
            'descricao' => (string) $r['descricao'], 'categoria_id' => $r['categoria_id'] !== null ? (int) $r['categoria_id'] : null,
            'categoria_nome' => $r['categoria_nome'] ?? null, 'categoria_apelido' => $r['categoria_apelido'] ?? null,
            'categoria_grupo' => $r['categoria_grupo'] ?? null,
            'valor_total' => (float) $r['valor_total'], 'parcelas' => (int) $r['parcelas'],
            'primeira_fatura' => (string) $r['primeira_fatura'], 'usuario_id' => (int) $r['usuario_id'],
            'criado_em' => (string) $r['criado_em'],
        ];
    }
}
