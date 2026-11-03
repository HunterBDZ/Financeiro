<?php
namespace Financeiro;

use PDO;

/**
 * Cópias do banco SQLite em uma pasta `backups` dentro da pasta de dados: a do dia sai na primeira página do dia,
 * porque um computador pessoal costuma estar desligado de madrugada. No MySQL, quem faz backup é o financeiro-backup.sh.
 */
final class Backup
{
    /** Quantas cópias guardar de cada tipo. */
    private const GUARDAR = ['diario' => 30, 'manual' => 20, 'antes-atualizacao' => 10, 'antes-restaurar' => 10];
    private const NOME = '/^financeiro-(\d{8})-(\d{6})-(diario|manual|antes-atualizacao|antes-restaurar)(-[0-9a-f]{6})?\.sqlite$/';
    private const TABELAS = ['usuarios', 'ajustes', 'categorias', 'meses', 'lancamentos', 'migracoes'];

    public static function disponivel(): bool
    {
        return Sql::sqlite() && Paths::dados() !== null;
    }

    public static function pasta(): string
    {
        return Paths::dados() . '/backups';
    }

    /** @return string o caminho da cópia nova */
    public static function criar(string $motivo = 'manual'): string
    {
        if (!isset(self::GUARDAR[$motivo])) {
            throw new \InvalidArgumentException('Motivo inválido: ' . $motivo);
        }
        self::prepararPasta();
        $final = self::pasta() . '/financeiro-' . date('Ymd-His') . '-' . $motivo . '.sqlite';
        if (file_exists($final)) {
            $final = substr($final, 0, -7) . '-' . bin2hex(random_bytes(3)) . '.sqlite';
        }
        $tmp = self::pasta() . '/.novo-' . bin2hex(random_bytes(6));
        try {
            self::copiarPara($tmp);
            self::conferir($tmp);
            if (!Paths::renomear($tmp, $final)) {
                throw new \RuntimeException('Não deu para guardar a cópia.');
            }
        } finally {
            self::apagar($tmp);
        }
        self::rotacionar($motivo);
        return $final;
    }

    /** Um arquivo temporário com tudo, para baixar. Quem chama apaga. */
    public static function paraBaixar(): string
    {
        self::prepararPasta();
        $tmp = self::pasta() . '/.baixar-' . bin2hex(random_bytes(6));
        self::copiarPara($tmp);
        return $tmp;
    }

    /** Uma cópia consistente, mesmo com alguém gravando; o destino não pode existir. */
    public static function copiarPara(string $destino): void
    {
        $alvo = str_replace("'", "''", $destino);
        Database::connection()->exec("VACUUM INTO '{$alvo}'");
        @chmod($destino, 0600);
    }

    /** O arquivo é um banco do Financeiro, inteiro? @throws \RuntimeException com o motivo, para a tela */
    public static function conferir(string $arquivo): void
    {
        $inicio = @file_get_contents($arquivo, false, null, 0, 16);
        if ($inicio !== "SQLite format 3\0") {
            throw new \RuntimeException('Esse arquivo não é um backup do MyMonetto.');
        }
        try {
            $pdo = new SqliteConexao($arquivo);
            $pdo->exec('PRAGMA trusted_schema = OFF');
            $ok = $pdo->query('PRAGMA quick_check(1)')->fetchColumn();
            $tem = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
        } catch (\PDOException) {
            throw new \RuntimeException('Esse arquivo não é um backup do MyMonetto.');
        }
        if ($ok !== 'ok') {
            throw new \RuntimeException('Esse backup está danificado.');
        }
        if (array_diff(self::TABELAS, $tem) !== []) {
            throw new \RuntimeException('Esse arquivo não é um backup do MyMonetto.');
        }
        $pdo = null;
    }

    /** Na primeira página do dia. Nunca atrapalha a página: quem chegou junto segue em frente. */
    public static function diario(): void
    {
        if (!self::disponivel() || Models\UsuarioModel::nenhum()) {
            return;
        }
        $marca = self::pasta() . '/.ultimo-diario';
        $hoje = date('Y-m-d');
        if (@file_get_contents($marca) === $hoje) {
            return;
        }
        self::prepararPasta();
        $trava = fopen(self::pasta() . '/.trava-diario', 'c');
        if ($trava === false) {
            return;
        }
        try {
            if (flock($trava, LOCK_EX | LOCK_NB) && @file_get_contents($marca) !== $hoje) {
                self::criar('diario');
                file_put_contents($marca, $hoje);
            }
        } finally {
            fclose($trava);
        }
    }

    /** @return list<array{nome:string, quando:string, motivo:string, tamanho:int}> as mais novas primeiro */
    public static function listar(): array
    {
        $out = [];
        foreach (is_dir(self::pasta()) ? (array) scandir(self::pasta()) : [] as $a) {
            if (is_string($a) && preg_match(self::NOME, $a, $m)) {
                $d = \DateTimeImmutable::createFromFormat('YmdHis', $m[1] . $m[2]);
                $out[] = [
                    'nome' => $a, 'motivo' => $m[3], 'tamanho' => (int) filesize(self::pasta() . '/' . $a),
                    'quando' => $d !== false ? $d->format('Y-m-d H:i:s') : '',
                ];
            }
        }
        usort($out, static fn(array $a, array $b): int => $b['quando'] <=> $a['quando'] ?: $b['nome'] <=> $a['nome']);
        return $out;
    }

    /** O caminho de uma cópia da lista; null se o nome não for de uma delas. */
    public static function caminho(string $nome): ?string
    {
        $arq = self::pasta() . '/' . $nome;
        return preg_match(self::NOME, $nome) && is_file($arq) ? $arq : null;
    }

    /**
     * Troca todos os dados pelos da cópia, numa transação só: uma falha no meio deixa tudo como estava, e quem está
     * usando o site só espera. Antes, guarda uma cópia do que há agora e leva o backup à versão deste programa.
     * @return string o caminho da cópia do que havia antes
     * @throws \RuntimeException com o motivo, para a tela
     */
    public static function restaurar(string $origem, ?string $migracoes = null): string
    {
        self::prepararPasta();
        $tmp = self::pasta() . '/.restaurar-' . bin2hex(random_bytes(6));
        if (!@copy($origem, $tmp)) {
            throw new \RuntimeException('Não deu para ler o backup.');
        }
        @chmod($tmp, 0600);
        try {
            self::conferir($tmp);
            self::prepararCopia($tmp, $migracoes ?? Migrador::pasta());
            $antes = self::criar('antes-restaurar');
            self::trocarDados($tmp);
        } finally {
            self::apagar($tmp);
        }
        Services\MotorFinanceiro::limparCache();
        self::encerrarSessoes();
        return $antes;
    }

    /** A volta de uma atualização: o site está em manutenção, e ninguém mais tem o banco aberto. */
    public static function voltarArquivo(string $copia): void
    {
        Database::fechar();
        $banco = Paths::banco();
        $tmp = $banco . '.voltando';
        if (!copy($copia, $tmp)) {
            throw new \RuntimeException('Não deu para copiar o backup.');
        }
        @chmod($tmp, 0600);
        @unlink($banco . '-wal');
        @unlink($banco . '-shm');
        if (!Paths::renomear($tmp, $banco)) {
            throw new \RuntimeException('Não deu para pôr o backup no lugar.');
        }
    }

    private static function prepararCopia(string $tmp, string $migracoes): void
    {
        $pdo = new SqliteConexao($tmp);
        if (Migrador::desconhecidas($pdo, $migracoes) !== []) {
            throw new \RuntimeException('Esse backup veio de uma versão mais nova do MyMonetto. Atualize o MyMonetto antes de restaurar.');
        }
        if ($pdo->query('PRAGMA main.foreign_key_check')->fetch() !== false) {
            throw new \RuntimeException('Esse backup está danificado.');
        }
        Migrador::aplicar($pdo, $migracoes);
        $pdo->exec('PRAGMA journal_mode = DELETE');     // tudo no arquivo principal, para o ATTACH
    }

    private static function trocarDados(string $tmp): void
    {
        $pdo = Database::connection();
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->prepare('ATTACH DATABASE :a AS restaurar')->execute(['a' => $tmp]);
        try {
            $pdo->beginTransaction();
            $tabelas = $pdo->query("SELECT name FROM main.sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tabelas as $t) {
                $colunas = self::colunas($pdo, 'main', $t);
                if ($colunas !== self::colunas($pdo, 'restaurar', $t)) {
                    throw new \RuntimeException('Esse arquivo não é um backup do MyMonetto.');
                }
                $lista = '"' . implode('", "', $colunas) . '"';
                $pdo->exec('DELETE FROM main."' . $t . '"');
                $pdo->exec('INSERT INTO main."' . $t . '" (' . $lista . ') SELECT ' . $lista . ' FROM restaurar."' . $t . '"');
            }
            if ($pdo->query("SELECT 1 FROM restaurar.sqlite_master WHERE name = 'sqlite_sequence'")->fetchColumn() !== false) {
                $pdo->exec('DELETE FROM main.sqlite_sequence');
                $pdo->exec('INSERT INTO main.sqlite_sequence SELECT * FROM restaurar.sqlite_sequence');
            }
            if ($pdo->query('PRAGMA main.foreign_key_check')->fetch() !== false) {
                throw new \RuntimeException('Esse backup está danificado.');
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e instanceof \RuntimeException ? $e : new \RuntimeException('Esse backup está danificado.', 0, $e);
        } finally {
            $pdo->exec('DETACH DATABASE restaurar');
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    /** @return list<string> vazia se a tabela não existir, ou se não for tabela */
    private static function colunas(PDO $pdo, string $banco, string $tabela): array
    {
        $st = $pdo->prepare('SELECT type FROM ' . $banco . '.sqlite_master WHERE name = :t');
        $st->execute(['t' => $tabela]);
        if ($st->fetchColumn() !== 'table') {
            return [];
        }
        $out = [];
        foreach ($pdo->query('PRAGMA ' . $banco . '.table_info("' . $tabela . '")') as $c) {
            $out[] = (string) $c['name'];
        }
        return $out;
    }

    /** Os dados agora são outros: todo mundo entra de novo, inclusive quem restaurou. */
    private static function encerrarSessoes(): void
    {
        if (is_dir(Paths::sessoes())) {
            foreach ((array) glob(Paths::sessoes() . '/sess_*') as $s) {
                if (is_string($s) && basename($s) !== 'sess_' . session_id()) {
                    @unlink($s);
                }
            }
        }
        $_SESSION = [];
    }

    private static function rotacionar(string $motivo): void
    {
        $doTipo = array_values(array_filter(self::listar(), static fn(array $b): bool => $b['motivo'] === $motivo));
        foreach (array_slice($doTipo, self::GUARDAR[$motivo]) as $b) {
            @unlink(self::pasta() . '/' . $b['nome']);
        }
    }

    private static function prepararPasta(): void
    {
        if (!is_dir(self::pasta()) && !@mkdir(self::pasta(), 0700, true) && !is_dir(self::pasta())) {
            throw new \RuntimeException('Não consigo gravar na pasta de dados.');
        }
    }

    private static function apagar(string $arquivo): void
    {
        foreach (['', '-wal', '-shm', '-journal'] as $s) {
            @unlink($arquivo . $s);
        }
    }
}
