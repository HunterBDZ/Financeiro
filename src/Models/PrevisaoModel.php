<?php
namespace Financeiro\Models;

use Financeiro\Database;

final class PrevisaoModel
{
    /** Todas as categorias ativas com o valor previsto no mês (0 se ainda não tiver linha). */
    public static function listarPorMes(string $mes): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT c.id AS categoria_id, c.nome, c.grupo, c.sinal, c.operacional,
                    COALESCE(p.valor, 0) AS valor
             FROM categorias c
             LEFT JOIN previsao p ON p.categoria_id = c.id AND p.mes = :mes
             WHERE c.ativo = 1
             ORDER BY FIELD(c.grupo, "Entradas","Fixos","Dia a dia","Cartao","Ano","Emprestimo","Poupanca"), c.ordem, c.nome'
        );
        $stmt->execute(['mes' => $mes]);
        return $stmt->fetchAll();
    }

    /** Valor previsto de uma categoria num mês (0 se não houver linha). */
    public static function valor(int $cat, string $mes): float
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT valor FROM previsao WHERE categoria_id = :c AND mes = :m');
        $stmt->execute(['c' => $cat, 'm' => $mes]);
        $v = $stmt->fetchColumn();
        return $v === false ? 0.0 : (float) $v;
    }

    /** Gravação crua de uma célula. Fora dos testes, só PrevisaoLote::gravar() chama isto (invariante 7). */
    public static function setValor(int $categoriaId, string $mes, float $valor): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO previsao (categoria_id, mes, valor) VALUES (:categoria_id, :mes, :valor)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)'
        );
        $stmt->execute(['categoria_id' => $categoriaId, 'mes' => $mes, 'valor' => $valor]);
    }

    public static function somaOperacional(string $mes, int $sinal): float
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(p.valor), 0)
             FROM previsao p
             JOIN categorias c ON c.id = p.categoria_id
             WHERE c.operacional = 1 AND c.sinal = :sinal AND p.mes = :mes'
        );
        $stmt->execute(['sinal' => $sinal, 'mes' => $mes]);
        return (float) $stmt->fetchColumn();
    }

    public static function somaPorCategoriaNome(string $categoriaNome, string $mes): float
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(p.valor), 0)
             FROM previsao p
             JOIN categorias c ON c.id = p.categoria_id
             WHERE c.nome = :nome AND p.mes = :mes'
        );
        $stmt->execute(['nome' => $categoriaNome, 'mes' => $mes]);
        return (float) $stmt->fetchColumn();
    }

    /** Breakdown de gastos previstos por categoria, pra meses ainda em PREVISÃO (sem lançamentos reais). */
    public static function breakdownPorCategoria(string $mes): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT c.nome, c.grupo, p.valor AS total
             FROM previsao p
             JOIN categorias c ON c.id = p.categoria_id
             WHERE c.operacional = 1 AND c.sinal = -1 AND p.mes = :mes AND p.valor > 0
             ORDER BY p.valor DESC'
        );
        $stmt->execute(['mes' => $mes]);
        return $stmt->fetchAll();
    }
}
