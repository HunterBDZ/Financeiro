<?php
namespace Financeiro\Models;

use Financeiro\Database;

/** Contas recorrentes (dono: P5). ChecklistContas depende de ativas(): assinatura e colunas congeladas. */
final class ContaRecorrenteModel
{
    /** Grupos que podem ter conta recorrente (SPEC §2.8): Fixos, Cartão e Pontuais. */
    public const GRUPOS = ['Fixos', 'Cartao', 'Ano'];

    /** @return array<int,array> contas recorrentes ativas, com dados da categoria */
    public static function ativas(): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->query(
            'SELECT r.id, r.categoria_id, r.dia_vencimento, c.nome AS categoria_nome, c.grupo
             FROM contas_recorrentes r
             JOIN categorias c ON c.id = r.categoria_id
             WHERE r.ativo = 1
             ORDER BY c.nome'
        );
        return $stmt->fetchAll();
    }

    /** Uma conta (ativa ou não) com o nome da categoria, ou null. */
    public static function buscar(int $id): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT r.id, r.categoria_id, r.dia_vencimento, r.ativo, c.nome AS categoria_nome, c.apelido, c.grupo
             FROM contas_recorrentes r
             JOIN categorias c ON c.id = r.categoria_id
             WHERE r.id = :id'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Categorias que podem virar conta recorrente: ativas, dos grupos Fixos, Cartao e Ano.
     * @return list<array{id:int,nome:string,apelido:?string,grupo:string}>
     */
    public static function categoriasElegiveis(): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->query(
            "SELECT id, nome, apelido, grupo
             FROM categorias
             WHERE ativo = 1 AND grupo IN ('Fixos', 'Cartao', 'Ano')
             ORDER BY FIELD(grupo, 'Fixos', 'Cartao', 'Ano'), ordem, nome"
        );
        return array_map(static fn(array $c): array => [
            'id' => (int) $c['id'], 'nome' => (string) $c['nome'],
            'apelido' => $c['apelido'] !== null ? (string) $c['apelido'] : null, 'grupo' => (string) $c['grupo'],
        ], $stmt->fetchAll());
    }

    public static function upsert(int $categoriaId, int $diaVencimento): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO contas_recorrentes (categoria_id, dia_vencimento, ativo)
             VALUES (:categoria_id, :dia, 1)
             ON DUPLICATE KEY UPDATE dia_vencimento = VALUES(dia_vencimento), ativo = 1'
        );
        $stmt->execute(['categoria_id' => $categoriaId, 'dia' => $diaVencimento]);
    }

    public static function desativar(int $id): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE contas_recorrentes SET ativo = 0 WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
