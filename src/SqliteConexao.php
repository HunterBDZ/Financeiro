<?php
namespace Financeiro;

use PDO;
use PDOException;
use PDOStatement;

/**
 * A conexão SQLite do app: colação, funções e transações que deixam o resultado igual ao do MySQL.
 * Toda escrita em transação pega a vez logo no início (BEGIN IMMEDIATE), então a espera do busy_timeout vale.
 */
final class SqliteConexao extends \Pdo\Sqlite
{
    /** Versão mais antiga com tabelas STRICT. */
    public const VERSAO_MINIMA = '3.37.0';

    private const ESPERA_MS = 15000;

    private bool $emTransacao = false;

    public function __construct(string $arquivo)
    {
        parent::__construct('sqlite:' . $arquivo, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => (int) (self::ESPERA_MS / 1000),
        ]);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SqliteConsulta::class, []]);
        parent::exec('PRAGMA busy_timeout = ' . self::ESPERA_MS);
        parent::exec('PRAGMA foreign_keys = ON');
        parent::exec('PRAGMA journal_mode = WAL');
        $this->createCollation('AI_CI', [Colacao::class, 'comparar']);
        $this->createFunction('agora_local', static fn(): string => date('Y-m-d H:i:s'), 0);
        $this->createFunction('dec_fix', [Colacao::class, 'decimal'], 2);
        $like = static function (?string $padrao, ?string $texto, ?string $escape = null): ?int {
            return $padrao === null || $texto === null ? null : (int) Colacao::like($padrao, $texto, $escape);
        };
        $this->createFunction('like', $like, 2);
        $this->createFunction('like', $like, 3);
        $this->criarSoma();
    }

    /** O SUM do SQLite é em ponto flutuante; este soma em décimos de milésimo inteiros, como o DECIMAL do MySQL. */
    private function criarSoma(): void
    {
        $passo = static function (mixed $estado, int $linha, mixed $valor): array {
            $estado ??= [0, true, false];
            if ($valor !== null) {
                $estado[0] += is_int($valor) ? $valor * 10000 : (int) round((float) $valor * 10000);
                $estado[1] = $estado[1] && is_int($valor);
                $estado[2] = true;
            }
            return $estado;
        };
        $fim = static function (mixed $estado, int $linhas): int|float|null {
            if ($estado === null || !$estado[2]) {
                return null;
            }
            return $estado[1] ? intdiv($estado[0], 10000) : $estado[0] / 10000;
        };
        $this->createAggregate('sum', $passo, $fim, 1);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $st = $this->prepare($query);
        $st->execute();
        return $st;
    }

    public function beginTransaction(): bool
    {
        if ($this->emTransacao) {
            throw new PDOException('There is already an active transaction');
        }
        parent::exec('BEGIN IMMEDIATE');
        $this->emTransacao = true;
        return true;
    }

    public function commit(): bool
    {
        if (!$this->emTransacao) {
            throw new PDOException('There is no active transaction');
        }
        parent::exec('COMMIT');
        $this->emTransacao = false;
        return true;
    }

    public function rollBack(): bool
    {
        if (!$this->emTransacao) {
            throw new PDOException('There is no active transaction');
        }
        parent::exec('ROLLBACK');
        $this->emTransacao = false;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->emTransacao;
    }
}
