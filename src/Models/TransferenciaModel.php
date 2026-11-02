<?php
namespace Financeiro\Models;

use Financeiro\Database;

/**
 * Dinheiro que passou de uma conta para outra (tabela `transferencias`). Fica fora das categorias: não é renda nem
 * gasto, não entra no plano e não muda o total em conta, só o saldo de cada uma.
 */
final class TransferenciaModel
{
    public static function criar(string $data, int $de, int $para, float $valor, string $descricao, int $usuarioId): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO transferencias (data, de_conta_id, para_conta_id, valor, descricao, usuario_id) VALUES (:d, :de, :para, :v, :ds, :u)')
            ->execute(['d' => $data, 'de' => $de, 'para' => $para, 'v' => round($valor, 2), 'ds' => $descricao, 'u' => $usuarioId]);
        return (int) $pdo->lastInsertId();
    }

    public static function atualizar(int $id, string $data, int $de, int $para, float $valor, string $descricao): void
    {
        Database::connection()->prepare('UPDATE transferencias SET data = :d, de_conta_id = :de, para_conta_id = :para, valor = :v, descricao = :ds WHERE id = :id')
            ->execute(['d' => $data, 'de' => $de, 'para' => $para, 'v' => round($valor, 2), 'ds' => $descricao, 'id' => $id]);
    }

    public static function find(int $id): ?array
    {
        $st = Database::connection()->prepare(
            'SELECT t.*, u.nome AS usuario_nome FROM transferencias t JOIN usuarios u ON u.id = t.usuario_id WHERE t.id = :id'
        );
        $st->execute(['id' => $id]);
        $r = $st->fetch();
        return $r ?: null;
    }

    public static function excluir(int $id): void
    {
        Database::connection()->prepare('DELETE FROM transferencias WHERE id = :id')->execute(['id' => $id]);
    }

    /** Desfazer a exclusão: a mesma linha, com o mesmo id. */
    public static function restaurar(array $t): void
    {
        Database::connection()->prepare(
            'INSERT INTO transferencias (id, data, de_conta_id, para_conta_id, valor, descricao, usuario_id, criado_em)
             VALUES (:id, :d, :de, :para, :v, :ds, :u, :c)'
        )->execute(['id' => (int) $t['id'], 'd' => $t['data'], 'de' => (int) $t['de_conta_id'], 'para' => (int) $t['para_conta_id'],
                    'v' => (float) $t['valor'], 'ds' => (string) $t['descricao'], 'u' => (int) $t['usuario_id'], 'c' => $t['criado_em']]);
    }

    /**
     * As transferências de um período (datas inclusivas; null = sem limite), das mais novas para as mais antigas.
     * $conta: só as que saíram dela ou entraram nela. $q: a descrição contém.
     * @return list<array>
     */
    public static function listar(?string $de, ?string $ate, ?int $conta = null, string $q = '', int $limite = 0): array
    {
        $w = ['1 = 1'];
        $p = [];
        if ($de !== null) {
            $w[] = 't.data >= :de';
            $p['de'] = $de;
        }
        if ($ate !== null) {
            $w[] = 't.data <= :ate';
            $p['ate'] = $ate;
        }
        if ($conta !== null) {
            $w[] = '(t.de_conta_id = :c1 OR t.para_conta_id = :c2)';
            $p['c1'] = $p['c2'] = $conta;
        }
        if ($q !== '') {
            $w[] = "t.descricao LIKE :q ESCAPE '!'";
            $p['q'] = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        }
        $st = Database::connection()->prepare(
            'SELECT t.*, u.nome AS usuario_nome FROM transferencias t JOIN usuarios u ON u.id = t.usuario_id
             WHERE ' . implode(' AND ', $w) . ' ORDER BY t.data DESC, t.id DESC' . ($limite > 0 ? ' LIMIT ' . $limite : '')
        );
        $st->execute($p);
        return $st->fetchAll();
    }
}
