<?php
namespace Financeiro;

use PDO;

/**
 * Mudanças de banco no SQLite: arquivos `sNNNN_nome.sql` ou `.php` em database/migrations-sqlite, cada um na sua
 * transação, registrados na tabela `migracoes`. Um .php devolve uma função que recebe a conexão. Sem nada a fazer,
 * custa só listar a pasta e ler a tabela.
 */
final class Migrador
{
    private const NOME = '/^s\d{4}_[a-z0-9_]+\.(sql|php)$/';

    public static function pasta(?string $raiz = null): string
    {
        return ($raiz ?? Paths::raiz()) . '/database/migrations-sqlite';
    }

    /** @return list<string> em ordem; o mesmo prefixo deixa o .php (dados) depois do .sql (estrutura) */
    public static function arquivos(string $pasta): array
    {
        $out = [];
        foreach (is_dir($pasta) ? (array) scandir($pasta) : [] as $a) {
            if (is_string($a) && preg_match(self::NOME, $a)) {
                $out[] = $a;
            }
        }
        usort($out, static fn(string $a, string $b): int => [pathinfo($a, PATHINFO_FILENAME), substr($b, -3)] <=> [pathinfo($b, PATHINFO_FILENAME), substr($a, -3)]);
        return $out;
    }

    /** @return list<string> */
    public static function feitas(PDO $pdo): array
    {
        return array_map('strval', $pdo->query('SELECT arquivo FROM migracoes')->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<string> */
    public static function pendentes(PDO $pdo, string $pasta): array
    {
        $arquivos = self::arquivos($pasta);
        return $arquivos === [] ? [] : array_values(array_diff($arquivos, self::feitas($pdo)));
    }

    /**
     * O banco veio de uma versão mais nova que este programa? Só vale para as migrações daqui: as do MySQL antigo
     * entram na tabela pelo importador e têm outro formato de nome.
     * @return list<string>
     */
    public static function desconhecidas(PDO $pdo, string $pasta): array
    {
        $nossas = array_filter(self::feitas($pdo), static fn(string $a): bool => (bool) preg_match(self::NOME, $a));
        return array_values(array_diff($nossas, self::arquivos($pasta)));
    }

    /** Banco novo, feito do schema: as mudanças da pasta já estão nele. Toda migração atualiza também o schema.sqlite.sql. */
    public static function marcarComoFeitas(PDO $pdo, string $pasta): void
    {
        $st = $pdo->prepare(Sql::inserirIgnorando() . ' INTO migracoes (arquivo) VALUES (:a)');
        foreach (self::arquivos($pasta) as $a) {
            $st->execute(['a' => $a]);
        }
    }

    /**
     * Aplica o que falta. Parou em uma: as anteriores ficam, e ela não fica pela metade.
     * @return list<string> o que aplicou
     */
    public static function aplicar(PDO $pdo, string $pasta): array
    {
        $feitas = [];
        foreach (self::pendentes($pdo, $pasta) as $a) {
            $pdo->beginTransaction();
            try {
                if (str_ends_with($a, '.sql')) {
                    $pdo->exec((string) file_get_contents($pasta . '/' . $a));
                } else {
                    (require $pasta . '/' . $a)($pdo);
                }
                $pdo->prepare('INSERT INTO migracoes (arquivo) VALUES (:a)')->execute(['a' => $a]);
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw new \RuntimeException($a . ': ' . $e->getMessage(), 0, $e);
            }
            $feitas[] = $a;
        }
        return $feitas;
    }

    public static function telaDeErro(\Throwable $e): never
    {
        error_log('migração: ' . $e->getMessage());
        Preflight::tela(500, t('O MyMonetto não pôde abrir'), [t('Não deu para atualizar o banco de dados. Os seus dados estão guardados: volte para a versão anterior e avise quem mantém o projeto.')]);
    }

    /** Pacote novo por cima do antigo, ou imagem Docker nova: o banco acompanha ao abrir, depois de uma cópia. */
    public static function aoAbrir(): void
    {
        if (!Sql::sqlite()) {
            return;
        }
        $pdo = Database::connection();
        if (self::desconhecidas($pdo, self::pasta()) !== []) {
            Preflight::tela(500, t('O MyMonetto não pôde abrir'), [t('Os seus dados são de uma versão mais nova do MyMonetto. Instale essa versão ou uma mais nova.')]);
        }
        if (self::pendentes($pdo, self::pasta()) === []) {
            return;
        }
        $trava = fopen(Paths::banco() . '.migrando', 'c');
        if ($trava === false || !flock($trava, LOCK_EX)) {
            throw new \RuntimeException('Não consegui a vez de atualizar o banco.');
        }
        try {
            if (self::pendentes($pdo, self::pasta()) !== []) {
                Backup::criar('antes-atualizacao');
                self::aplicar($pdo, self::pasta());
            }
        } finally {
            flock($trava, LOCK_UN);
            fclose($trava);
        }
    }
}
