<?php
namespace Financeiro\Models;

use Financeiro\Database;

/**
 * Previsão por categoria × mês. Leitura sempre pela view `previsao_efetiva` (a meta é a previsão
 * padrão de todo mês; uma linha em `previsao` é a exceção daquele mês — migração 2026_10_16);
 * gravação na tabela `previsao`, e fora dos testes só por PrevisaoLote (invariante 7).
 */
final class PrevisaoModel
{
    /** Todas as categorias ativas com o valor previsto no mês (0 se não houver linha nem meta). */
    public static function listarPorMes(string $mes): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT c.id AS categoria_id, c.nome, c.grupo, c.sinal, c.operacional,
                    COALESCE(p.valor, 0) AS valor
             FROM categorias c
             LEFT JOIN previsao_efetiva p ON p.categoria_id = c.id AND p.mes = :mes
             WHERE c.ativo = 1
             ORDER BY FIELD(c.grupo, "Entradas","Fixos","Dia a dia","Cartao","Ano","Emprestimo","Poupanca","Investimento"), c.ordem, c.nome'
        );
        $stmt->execute(['mes' => $mes]);
        return $stmt->fetchAll();
    }

    /** Valor previsto de uma categoria num mês (o do mês, senão a meta, senão 0). */
    public static function valor(int $cat, string $mes): float
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT valor FROM previsao_efetiva WHERE categoria_id = :c AND mes = :m');
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

    /** Apaga a célula do mês (a categoria volta a seguir a meta). Mesma regra de setValor(). */
    public static function remover(int $categoriaId, string $mes): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('DELETE FROM previsao WHERE categoria_id = :c AND mes = :m');
        $stmt->execute(['c' => $categoriaId, 'm' => $mes]);
    }

    public static function somaOperacional(string $mes, int $sinal): float
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(p.valor), 0)
             FROM previsao_efetiva p
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
             FROM previsao_efetiva p
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
             FROM previsao_efetiva p
             JOIN categorias c ON c.id = p.categoria_id
             WHERE c.operacional = 1 AND c.sinal = -1 AND p.mes = :mes AND p.valor > 0
             ORDER BY p.valor DESC'
        );
        $stmt->execute(['mes' => $mes]);
        return $stmt->fetchAll();
    }
}
