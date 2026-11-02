<?php
namespace Financeiro;

use PDO;

/**
 * Tudo o que muda de um banco para o outro. Models e Services montam o SQL com estes pedaços e não perguntam
 * qual é o banco. Quando o MySQL sair (docs/decisoes.md), cada método fica só com o seu ramo SQLite.
 */
final class Sql
{
    /** @var array<string, resource> */
    private static array $travas = [];

    public static function sqlite(): bool
    {
        return Config::get('DB_DRIVER', 'mysql') === 'sqlite';
    }

    /** O relógio do site, em texto `AAAA-MM-DD HH:MM:SS`. */
    public static function agora(): string
    {
        return self::sqlite() ? 'agora_local()' : 'NOW()';
    }

    public static function hoje(): string
    {
        return self::sqlite() ? 'date(agora_local())' : 'CURDATE()';
    }

    /** Agora mais ou menos um intervalo. `$quanto` é negativo para o passado; a unidade é DAY, HOUR, MINUTE ou SECOND. */
    public static function agoraMais(int $quanto, string $unidade): string
    {
        if (self::sqlite()) {
            return sprintf("datetime(agora_local(), '%+d %s')", $quanto, strtolower($unidade));
        }
        return 'NOW() ' . ($quanto < 0 ? '-' : '+') . ' INTERVAL ' . abs($quanto) . ' ' . $unidade;
    }

    /** Segundos entre agora e a coluna, em número inteiro. */
    public static function segundosAte(string $coluna): string
    {
        return self::sqlite()
            ? "(CAST(strftime('%s', {$coluna}) AS INTEGER) - CAST(strftime('%s', agora_local()) AS INTEGER))"
            : "TIMESTAMPDIFF(SECOND, NOW(), {$coluna})";
    }

    public static function maior(string $a, string $b): string
    {
        return (self::sqlite() ? 'MAX(' : 'GREATEST(') . $a . ', ' . $b . ')';
    }

    public static function menor(string $a, string $b): string
    {
        return (self::sqlite() ? 'MIN(' : 'LEAST(') . $a . ', ' . $b . ')';
    }

    public static function se(string $condicao, string $sim, string $nao): string
    {
        return (self::sqlite() ? 'IIF(' : 'IF(') . $condicao . ', ' . $sim . ', ' . $nao . ')';
    }

    /** O dia 1 do mês da data, em texto. */
    public static function inicioMes(string $coluna): string
    {
        return self::sqlite() ? "strftime('%Y-%m-01', {$coluna})" : "DATE_FORMAT({$coluna}, '%Y-%m-01')";
    }

    public static function mesSeguinte(string $coluna): string
    {
        return self::sqlite() ? "date({$coluna}, '+1 month')" : "DATE_ADD({$coluna}, INTERVAL 1 MONTH)";
    }

    public static function diaDoMes(string $coluna): string
    {
        return self::sqlite() ? "CAST(strftime('%d', {$coluna}) AS INTEGER)" : "DAY({$coluna})";
    }

    /**
     * Para ORDER BY: a posição do valor na lista, ou 0 se não estiver nela.
     * @param list<string> $valores
     */
    public static function posicao(string $coluna, array $valores): string
    {
        $aspas = array_map(static fn(string $v): string => "'" . str_replace("'", "''", $v) . "'", $valores);
        if (!self::sqlite()) {
            return 'FIELD(' . $coluna . ', ' . implode(', ', $aspas) . ')';
        }
        $casos = '';
        foreach ($aspas as $i => $v) {
            $casos .= ' WHEN ' . $v . ' THEN ' . ($i + 1);
        }
        return 'CASE ' . $coluna . $casos . ' ELSE 0 END';
    }

    public static function inserirIgnorando(): string
    {
        return self::sqlite() ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
    }

    /**
     * O fim de um INSERT que atualiza a linha já existente.
     * @param string $chave colunas da chave repetida, separadas por vírgula
     * @param array<string, ?string> $colunas coluna => expressão; null = o valor que veio no INSERT
     */
    public static function aoRepetir(string $chave, array $colunas): string
    {
        $partes = [];
        foreach ($colunas as $coluna => $expressao) {
            $novo = self::sqlite() ? "excluded.{$coluna}" : "VALUES({$coluna})";
            $partes[] = $coluna . ' = ' . ($expressao ?? $novo);
        }
        return (self::sqlite() ? "ON CONFLICT({$chave}) DO UPDATE SET " : 'ON DUPLICATE KEY UPDATE ') . implode(', ', $partes);
    }

    /** No SQLite, a transação já tem a vez de escrever desde o início. */
    public static function travandoLinhas(): string
    {
        return self::sqlite() ? '' : ' FOR UPDATE';
    }

    public static function tabelaExiste(PDO $pdo, string $tabela): bool
    {
        $st = $pdo->prepare(self::sqlite()
            ? "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?"
            : 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $st->execute([$tabela]);
        return (int) $st->fetchColumn() > 0;
    }

    /** O fuso da conexão. O SQLite usa o relógio do PHP, que já está no fuso certo. */
    public static function fusoDaConexao(PDO $pdo, string $deslocamento): void
    {
        if (!self::sqlite()) {
            $pdo->exec("SET time_zone = '" . $deslocamento . "'");
        }
    }

    /**
     * Uma trava nomeada, que cai sozinha quando a conexão ou o processo termina. false = não conseguiu a vez.
     * No SQLite é um arquivo: segurar uma transação durante o bcrypt pararia todas as outras escritas.
     */
    public static function travar(PDO $pdo, string $nome, int $segundos): bool
    {
        if (!self::sqlite()) {
            return (int) $pdo->query("SELECT GET_LOCK('{$nome}', {$segundos})")->fetchColumn() === 1;
        }
        $h = fopen(Paths::banco() . '.' . $nome . '.trava', 'c');
        if ($h === false) {
            return false;
        }
        $limite = microtime(true) + $segundos;
        do {
            if (flock($h, LOCK_EX | LOCK_NB)) {
                self::$travas[$nome] = $h;
                return true;
            }
            usleep(50000);
        } while (microtime(true) < $limite);
        fclose($h);
        return false;
    }

    public static function soltar(PDO $pdo, string $nome): void
    {
        if (!self::sqlite()) {
            $pdo->query("DO RELEASE_LOCK('{$nome}')");
            return;
        }
        if (isset(self::$travas[$nome])) {
            flock(self::$travas[$nome], LOCK_UN);
            fclose(self::$travas[$nome]);
            unset(self::$travas[$nome]);
        }
    }
}
