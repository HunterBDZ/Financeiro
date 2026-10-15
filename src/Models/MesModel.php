<?php
namespace Financeiro\Models;

use Financeiro\Database;

final class MesModel
{
    public static function situacao(string $mes): string
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT situacao FROM meses WHERE mes = :mes');
        $stmt->execute(['mes' => $mes]);
        $row = $stmt->fetch();
        return $row['situacao'] ?? 'PREVISAO';
    }

    public static function ensureExists(string $mes): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT IGNORE INTO meses (mes, situacao) VALUES (:mes, "PREVISAO")');
        $stmt->execute(['mes' => $mes]);
    }

    public static function setSituacao(string $mes, string $situacao): void
    {
        if (!in_array($situacao, ['PREVISAO', 'REAL'], true)) {
            throw new \InvalidArgumentException('Situação inválida');
        }
        self::ensureExists($mes);
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE meses SET situacao = :sit, fechado_em = IF(:real = 1, NOW(), NULL) WHERE mes = :mes');
        $stmt->execute(['sit' => $situacao, 'real' => $situacao === 'REAL' ? 1 : 0, 'mes' => $mes]);
    }

    /** @return array<int,array{mes:string,situacao:string}> todos os meses cadastrados, em ordem cronológica */
    public static function todos(): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->query('SELECT mes, situacao FROM meses ORDER BY mes');
        return $stmt->fetchAll();
    }

    /**
     * Mês de referência (SPEC §1.3): o mês do calendário, se estiver em PREVISAO; senão o
     * primeiro PREVISAO depois dele; senão o último mês cadastrado. Hoje (29/09, set fechado): out/26.
     */
    public static function referencia(?string $hoje = null): string
    {
        $cal = date('Y-m-01', strtotime($hoje ?? date('Y-m-d')));
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT mes FROM meses WHERE situacao = 'PREVISAO' AND mes >= :cal ORDER BY mes LIMIT 1"
        );
        $stmt->execute(['cal' => $cal]);
        $mes = $stmt->fetchColumn();
        if ($mes) {
            return (string) $mes;
        }
        return self::ultimo() ?? $cal;
    }

    /** Primeiro mês cadastrado (MIN(mes)) — limite inferior da data de um lançamento. */
    public static function primeiro(): ?string
    {
        $mes = Database::connection()->query('SELECT MIN(mes) FROM meses')->fetchColumn();
        return $mes ? (string) $mes : null;
    }

    /** Último mês cadastrado (MAX(mes)) — limite superior da data de um lançamento e o "dez/27" das telas. */
    public static function ultimo(): ?string
    {
        $mes = Database::connection()->query('SELECT MAX(mes) FROM meses')->fetchColumn();
        return $mes ? (string) $mes : null;
    }

    /** Gera (se não existir) um horizonte de $quantidade meses a partir de $inicio (formato Y-m-01). */
    public static function garantirHorizonte(string $inicio, int $quantidade): void
    {
        $ts = strtotime($inicio);
        for ($i = 0; $i < $quantidade; $i++) {
            $mes = date('Y-m-01', strtotime("+{$i} month", $ts));
            self::ensureExists($mes);
        }
    }
}
