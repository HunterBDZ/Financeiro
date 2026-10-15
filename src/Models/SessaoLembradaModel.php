<?php
namespace Financeiro\Models;

use Financeiro\Database;

/**
 * "Lembrar este aparelho" (SPEC §11 P4). Uma linha por aparelho, chave = seletor (público,
 * 24 hex). O validador (64 hex) nunca fica no banco: só o sha256 dele, o atual e o anterior
 * (aceito por JANELA_SEG segundos depois da rotação).
 *
 * Todo tempo é calculado e comparado no SQL (NOW() do banco), nunca misturado com o
 * relógio do PHP, para o fuso de um não bagunçar o outro.
 */
final class SessaoLembradaModel
{
    /** Validade absoluta do aparelho lembrado (a rotação não estende o prazo). */
    public const DIAS = 60;

    /** Quanto tempo o validador anterior ainda vale depois de uma rotação (duas abas juntas). */
    public const JANELA_SEG = 60;

    public static function criar(string $seletor, string $validadorHash, int $usuarioId): void
    {
        Database::connection()->prepare(
            'INSERT INTO sessoes_lembradas (seletor, validador_hash, usuario_id, expira_em, ultimo_uso)
             VALUES (:s, :h, :u, NOW() + INTERVAL ' . self::DIAS . ' DAY, NOW())'
        )->execute(['s' => $seletor, 'h' => $validadorHash, 'u' => $usuarioId]);
    }

    /**
     * A linha do seletor, com o usuário: validador_hash, validador_ant_hash, usuario_id, nome,
     * sessao_versao, na_janela (0/1: rotacionada há ≤ JANELA_SEG s) e resta_seg (até expirar).
     */
    public static function buscar(string $seletor): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT s.seletor, s.validador_hash, s.validador_ant_hash, s.usuario_id,
                    CASE WHEN s.rotacionado_em IS NOT NULL
                          AND s.rotacionado_em >= NOW() - INTERVAL ' . self::JANELA_SEG . ' SECOND
                         THEN 1 ELSE 0 END AS na_janela,
                    TIMESTAMPDIFF(SECOND, NOW(), s.expira_em) AS resta_seg,
                    u.id, u.nome, u.sessao_versao
             FROM sessoes_lembradas s
             JOIN usuarios u ON u.id = s.usuario_id
             WHERE s.seletor = :s'
        );
        $stmt->execute(['s' => $seletor]);
        $linha = $stmt->fetch();
        return $linha ?: null;
    }

    /**
     * Troca o validador só se o atual ainda for $hashAtual (duas requisições ao mesmo tempo:
     * só uma rotaciona; a outra relê e cai na janela do anterior). true = rotacionou.
     */
    public static function rotacionar(string $seletor, string $hashAtual, string $hashNovo): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE sessoes_lembradas
                SET validador_ant_hash = validador_hash, validador_hash = :novo,
                    rotacionado_em = NOW(), ultimo_uso = NOW()
              WHERE seletor = :s AND validador_hash = :atual'
        );
        $stmt->execute(['novo' => $hashNovo, 's' => $seletor, 'atual' => $hashAtual]);
        return $stmt->rowCount() === 1;
    }

    public static function marcarUso(string $seletor): void
    {
        Database::connection()->prepare('UPDATE sessoes_lembradas SET ultimo_uso = NOW() WHERE seletor = :s')
            ->execute(['s' => $seletor]);
    }

    public static function apagar(string $seletor): void
    {
        Database::connection()->prepare('DELETE FROM sessoes_lembradas WHERE seletor = :s')
            ->execute(['s' => $seletor]);
    }

    /** Apaga todos os aparelhos lembrados do usuário. Devolve quantos eram. */
    public static function apagarDoUsuario(int $usuarioId): int
    {
        $stmt = Database::connection()->prepare('DELETE FROM sessoes_lembradas WHERE usuario_id = :u');
        $stmt->execute(['u' => $usuarioId]);
        return $stmt->rowCount();
    }

    public static function limparExpiradas(): void
    {
        Database::connection()->exec('DELETE FROM sessoes_lembradas WHERE expira_em <= NOW()');
    }
}
