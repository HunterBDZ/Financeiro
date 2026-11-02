<?php
namespace Financeiro;

use PDO;
use PDOStatement;

/**
 * Consulta do SQLite que se comporta como a do MySQL: o resultado fica em memória logo ao executar, sem cursor
 * aberto (um cursor aberto seguraria uma leitura antiga e faria a próxima escrita falhar como "banco ocupado").
 * Inteiro vai como inteiro, para valer em conta sem coluna (`tentativas + 1 >= ?`).
 */
final class SqliteConsulta extends PDOStatement
{
    /** @var list<array<string, mixed>> */
    private array $linhas = [];
    private int $posicao = 0;

    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        foreach ($params ?? [] as $k => $v) {
            $this->bindValue(is_int($k) ? $k + 1 : $k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        try {
            $ok = parent::execute();
        } catch (\PDOException $e) {
            parent::closeCursor();                              // sem isto, a mesma consulta não executa de novo
            throw $e;
        }
        $this->linhas = $this->columnCount() > 0 ? parent::fetchAll(PDO::FETCH_ASSOC) : [];
        $this->posicao = 0;
        parent::closeCursor();
        return $ok;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $linha = $this->linhas[$this->posicao] ?? null;
        if ($linha === null) {
            return false;
        }
        $this->posicao++;
        return match ($mode) {
            PDO::FETCH_DEFAULT, PDO::FETCH_ASSOC => $linha,
            PDO::FETCH_NUM => array_values($linha),
            PDO::FETCH_COLUMN => array_values($linha)[0],
            default => throw new \LogicException('Modo de leitura sem suporte: ' . $mode),
        };
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $linha = $this->linhas[$this->posicao] ?? null;
        if ($linha === null) {
            return false;
        }
        $this->posicao++;
        return array_values($linha)[$column];
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $resto = array_slice($this->linhas, $this->posicao);
        $this->posicao = count($this->linhas);
        return match ($mode) {
            PDO::FETCH_DEFAULT, PDO::FETCH_ASSOC => $resto,
            PDO::FETCH_NUM => array_map('array_values', $resto),
            PDO::FETCH_COLUMN => array_map(static fn(array $l): mixed => array_values($l)[(int) ($args[0] ?? 0)], $resto),
            PDO::FETCH_KEY_PAIR => array_column(array_map('array_values', $resto), 1, 0),
            default => throw new \LogicException('Modo de leitura sem suporte: ' . $mode),
        };
    }

    public function getIterator(): \Iterator
    {
        $resto = array_slice($this->linhas, $this->posicao);
        $this->posicao = count($this->linhas);
        return new \ArrayIterator($resto);
    }
}
