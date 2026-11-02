<?php
namespace Financeiro\Models;

use Financeiro\Database;
use Financeiro\Sql;

/**
 * Os cartões de crédito da casa (tabela `cartoes`, na ordem de `ordem`) e as compras feitas neles
 * (`cartao_compras`; o estorno é uma compra negativa de 1 parcela). As parcelas não têm tabela: saem de
 * valor_total, parcelas e primeira_fatura (ver CartaoFatura). No plano, as compras não contam nas categorias: só a
 * fatura, que é uma categoria só (chave `fatura`) para todos os cartões, cada pagamento com o seu cartão. Nas Metas e
 * no "Para onde foi", cada compra conta na categoria dela, pela data da compra (Services\GastoPorCategoria).
 */
final class CartaoModel
{
    /** @return list<array> os cartões ativos, na ordem da tela; o primeiro é o padrão */
    public static function ativos(): array
    {
        return array_map([self::class, 'cartao'], Database::connection()->query('SELECT * FROM cartoes WHERE ativo = 1 ORDER BY ordem, id')->fetchAll());
    }

    /** O primeiro cartão ativo (o padrão), ou null antes de cadastrar. */
    public static function padrao(): ?array
    {
        return self::ativos()[0] ?? null;
    }

    /** Um cartão pelo id, ativo ou não. */
    public static function achar(int $id): ?array
    {
        $st = Database::connection()->prepare('SELECT * FROM cartoes WHERE id = :id');
        $st->execute(['id' => $id]);
        $r = $st->fetch();
        return $r ? self::cartao($r) : null;
    }

    /** Cadastra (id null) ou atualiza o cartão; devolve o id. Um cartão novo vai para o fim da fila. */
    public static function salvar(?int $id, string $nome, int $fecha, int $vence, ?float $limite, ?int $contaId = null): int
    {
        $pdo = Database::connection();
        $dados = ['n' => $nome, 'f' => $fecha, 'v' => $vence, 'l' => $limite, 'c' => $contaId ?? ContaModel::PRINCIPAL];
        if ($id === null) {
            $pdo->prepare('INSERT INTO cartoes (nome, dia_fechamento, dia_vencimento, limite, conta_id, ordem)
                           VALUES (:n, :f, :v, :l, :c, (SELECT COALESCE(MAX(ordem), 0) + 1 FROM cartoes))')->execute($dados);
            return (int) $pdo->lastInsertId();
        }
        $pdo->prepare('UPDATE cartoes SET nome = :n, dia_fechamento = :f, dia_vencimento = :v, limite = :l, conta_id = :c WHERE id = :id')
            ->execute($dados + ['id' => $id]);
        return $id;
    }

    /** Tira o cartão da tela e do plano; as compras e os pagamentos dele ficam. */
    public static function arquivar(int $id): void
    {
        Database::connection()->prepare('UPDATE cartoes SET ativo = 0, alimenta_plano = 0 WHERE id = :id')->execute(['id' => $id]);
    }

    /** Já existe outro cartão com esse nome? (sem acento e sem caixa) */
    public static function nomeUsado(string $nome, ?int $fora = null): bool
    {
        $st = Database::connection()->prepare('SELECT 1 FROM cartoes WHERE nome = :n AND ativo = 1 AND id <> :id');
        $st->execute(['n' => $nome, 'id' => $fora ?? 0]);
        return $st->fetchColumn() !== false;
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

    /**
     * As compras de todos os cartões, ativos ou não, só com o que a conta pede (Services\GastoPorCategoria).
     * @return list<array{id:int, cartao_id:int, data:string, categoria_id:?int, valor_total:float, parcelas:int, primeira_fatura:string}>
     */
    public static function todasAsCompras(): array
    {
        $st = Database::connection()->query('SELECT id, cartao_id, data, categoria_id, valor_total, parcelas, primeira_fatura FROM cartao_compras ORDER BY data, id');
        return array_map(static fn(array $r): array => ['id' => (int) $r['id'], 'cartao_id' => (int) $r['cartao_id'], 'data' => (string) $r['data'],
            'categoria_id' => $r['categoria_id'] !== null ? (int) $r['categoria_id'] : null, 'valor_total' => (float) $r['valor_total'],
            'parcelas' => (int) $r['parcelas'], 'primeira_fatura' => (string) $r['primeira_fatura']], $st->fetchAll());
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

    /** A categoria que recebe as faturas no plano (chave `fatura`, a "Fatura do cartão"), ou null. */
    public static function categoriaFatura(): ?int
    {
        $v = Database::connection()->query("SELECT id FROM categorias WHERE chave = 'fatura' AND ativo = 1")->fetchColumn();
        return $v === false ? null : (int) $v;
    }

    /**
     * Os pagamentos de fatura já ligados a um cartão, do mais antigo para o mais novo, cada um com a fatura que pagou
     * (lancamentos.cartao_id e lancamentos.fatura). Os sem cartão contam no mês da data, como as outras contas.
     * @return list<array{id:int, data:string, valor:float, cartao_id:int, fatura:string}>
     */
    public static function pagamentos(int $categoriaFatura): array
    {
        $st = Database::connection()->prepare(
            'SELECT id, data, valor, cartao_id, fatura FROM lancamentos WHERE categoria_id = :c AND cartao_id IS NOT NULL ORDER BY data, id'
        );
        $st->execute(['c' => $categoriaFatura]);
        return array_map(static fn(array $r): array => ['id' => (int) $r['id'], 'data' => (string) $r['data'], 'valor' => (float) $r['valor'],
            'cartao_id' => (int) $r['cartao_id'], 'fatura' => (string) $r['fatura']], $st->fetchAll());
    }

    private static function cartao(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'nome' => (string) $r['nome'], 'fecha' => (int) $r['dia_fechamento'],
            'vence' => (int) $r['dia_vencimento'], 'limite' => $r['limite'] !== null ? (float) $r['limite'] : null,
            'alimenta_plano' => (int) $r['alimenta_plano'] === 1, 'ativo' => (int) $r['ativo'] === 1,
            'conta_id' => $r['conta_id'] !== null ? (int) $r['conta_id'] : ContaModel::PRINCIPAL,
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
