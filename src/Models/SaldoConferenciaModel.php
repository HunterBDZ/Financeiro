<?php
namespace Financeiro\Models;

use Financeiro\Database;

/** Conferências do saldo com o banco. */
final class SaldoConferenciaModel
{
    /** A conferência mais recente (maior data; empate = a última registrada), ou null. */
    public static function ultima(): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->query(
            'SELECT sc.id, sc.data, sc.saldo_informado, sc.criado_em, u.nome AS usuario_nome
             FROM saldo_conferencias sc
             JOIN usuarios u ON u.id = sc.usuario_id
             ORDER BY sc.data DESC, sc.id DESC
             LIMIT 1'
        );
        return $stmt->fetch() ?: null;
    }

    /** Uma conferência por dia: registrar de novo no mesmo dia substitui a anterior. */
    public static function registrar(string $data, float $saldoInformado, int $usuarioId): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM saldo_conferencias WHERE data = :data')->execute(['data' => $data]);
        $stmt = $pdo->prepare(
            'INSERT INTO saldo_conferencias (data, saldo_informado, usuario_id)
             VALUES (:data, :saldo, :usuario_id)'
        );
        $stmt->execute(['data' => $data, 'saldo' => $saldoInformado, 'usuario_id' => $usuarioId]);
    }

    /**
     * "Agendado" da conferência (D10, invariante 16): Σ sinal·valor dos lançamentos com data
     * DEPOIS da conferência que JÁ estavam registrados quando ela foi feita — o débito
     * agendado que o banco pode (ou não) já ter descontado do saldo mostrado.
     */
    public static function agendadoNa(array $conferencia): float
    {
        $data = (string) ($conferencia['data'] ?? '');
        $criado = (string) ($conferencia['criado_em'] ?? '');
        // Saldo de uma data já passada quando foi informado (ex.: Fechar mês em 05/11 com o "saldo
        // no banco em 31/10", ou "outro dia" na conferência): o extrato daquele dia não tem débito
        // agendado nenhum, e o que foi lançado depois dele já aconteceu — não é agendado.
        if ($data === '' || $criado === '' || $data < substr($criado, 0, 10)) {
            return 0.0;
        }
        $stmt = Database::connection()->prepare(
            'SELECT COALESCE(SUM(l.valor * c.sinal), 0)
             FROM lancamentos l
             JOIN categorias c ON c.id = l.categoria_id
             WHERE l.data > :data AND l.criado_em <= :criado'
        );
        $stmt->execute(['data' => $data, 'criado' => $criado]);
        return round((float) $stmt->fetchColumn(), 2);
    }
}
