<?php
namespace Financeiro\Models;

use Financeiro\Sql;
use Financeiro\Database;

/**
 * Metas de gasto por categoria (uma meta mensal por categoria; tabela `metas`).
 * Sem linha = sem meta. Só categorias de gasto recebem meta (operacional = 1, sinal = -1),
 * a mesma regra de CategoriaModel::setEssencial(); quem valida isso é o MetaController.
 */
final class MetaModel
{
    /** @return array<int, float> mapa categoria_id => valor da meta. */
    public static function todas(): array
    {
        $pdo = Database::connection();
        $map = [];
        foreach ($pdo->query('SELECT categoria_id, valor FROM metas') as $r) {
            $map[(int) $r['categoria_id']] = (float) $r['valor'];
        }
        return $map;
    }

    public static function valor(int $categoriaId): ?float
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT valor FROM metas WHERE categoria_id = :id');
        $stmt->execute(['id' => $categoriaId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (float) $v;
    }

    /** Grava (ou atualiza) a meta de uma categoria. */
    public static function definir(int $categoriaId, float $valor): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO metas (categoria_id, valor) VALUES (:id, :v)
             ' . Sql::aoRepetir('categoria_id', ['valor' => null])
        );
        $stmt->execute(['id' => $categoriaId, 'v' => round($valor, 2)]);
    }

    /** Remove a meta de uma categoria (volta a "sem meta"). */
    public static function remover(int $categoriaId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('DELETE FROM metas WHERE categoria_id = :id');
        $stmt->execute(['id' => $categoriaId]);
    }
}
