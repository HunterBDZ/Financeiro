<?php
namespace Financeiro\Models;

use Financeiro\Database;
use PDO;
use PDOException;

/**
 * Lançamentos (dono: P4). Assinaturas CONGELADAS (outros pacotes dependem): somaPorCategoriaNome,
 * primeiraDataPorCategoriaNome, saldoCalculado, somaOperacional.
 */
final class LancamentoModel
{
    /** Primeiro dia do mês seguinte a $mes (Y-m-01). */
    private static function fimDoMes(string $mes): string
    {
        return date('Y-m-01', strtotime('+1 month', strtotime($mes)));
    }

    /**
     * WHERE comum do extrato. $f: grupo (string[]), usuario_id (int), categoria_id (int), q (string).
     * @return array{0:string,1:array<string,mixed>}
     */
    private static function filtro(string $mes, array $f): array
    {
        $w = ['l.data >= :inicio', 'l.data < :fim'];
        $p = ['inicio' => $mes, 'fim' => self::fimDoMes($mes)];

        $grupos = array_values(array_filter((array) ($f['grupo'] ?? []), 'is_string'));
        if ($grupos !== []) {
            $ph = [];
            foreach ($grupos as $i => $g) {
                $ph[] = ':g' . $i;
                $p['g' . $i] = $g;
            }
            $w[] = 'c.grupo IN (' . implode(', ', $ph) . ')';
        }
        // classe: entradas / essenciais / não essenciais / movimentações (⇄)
        $classe = (string) ($f['classe'] ?? '');
        if ($classe === 'entradas') {
            $w[] = 'c.operacional = 1 AND c.sinal = 1';
        } elseif ($classe === 'essenciais') {
            $w[] = 'c.operacional = 1 AND c.sinal = -1 AND c.essencial = 1';
        } elseif ($classe === 'naoess') {
            $w[] = 'c.operacional = 1 AND c.sinal = -1 AND c.essencial = 0';
        } elseif ($classe === 'mov') {
            $w[] = 'c.operacional = 0';
        }
        if (!empty($f['usuario_id'])) {
            $w[] = 'l.usuario_id = :uid';
            $p['uid'] = (int) $f['usuario_id'];
        }
        if (!empty($f['categoria_id'])) {
            $w[] = 'l.categoria_id = :cat';
            $p['cat'] = (int) $f['categoria_id'];
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            // '!' como escape explícito: funciona igual com ou sem NO_BACKSLASH_ESCAPES
            $like = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $w[] = "(l.descricao LIKE :q1 ESCAPE '!' OR c.nome LIKE :q2 ESCAPE '!' OR c.apelido LIKE :q3 ESCAPE '!')";
            $p['q1'] = $p['q2'] = $p['q3'] = $like;
        }
        return [implode(' AND ', $w), $p];
    }

    /**
     * Extrato: lançamentos de um mês (Y-m-01), mais novos primeiro.
     * $f: grupo (string[]), usuario_id (int), categoria_id (int), q (string), limite (int; 0 = todos).
     */
    public static function listar(string $mes, array $f): array
    {
        [$where, $params] = self::filtro($mes, $f);
        $orderBy = match ((string) ($f['ordem'] ?? '')) {
            'dia-asc'    => 'l.data ASC, l.id ASC',
            'valor-desc' => 'l.valor DESC, l.data DESC, l.id DESC',
            'valor-asc'  => 'l.valor ASC, l.data DESC, l.id DESC',
            'alfa'       => 'c.nome ASC, l.data DESC, l.id DESC',
            default      => 'l.data DESC, l.id DESC',
        };
        $sql = 'SELECT l.id, l.data, l.descricao, l.valor, l.categoria_id, l.usuario_id, l.criado_em,
                       c.nome AS categoria_nome, c.apelido, c.grupo, c.sinal, c.operacional, c.essencial,
                       u.nome AS usuario_nome
                FROM lancamentos l
                JOIN categorias c ON c.id = l.categoria_id
                JOIN usuarios u ON u.id = l.usuario_id
                WHERE ' . $where . '
                ORDER BY ' . $orderBy;
        $limite = (int) ($f['limite'] ?? 0);
        if ($limite > 0) {
            $sql .= ' LIMIT ' . $limite;
        }
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Totais do extrato com os mesmos filtros de listar() (sobre TODAS as linhas, não só as exibidas):
     * n, soma (Σ sinal·valor só das operacionais — invariante 2), n_op, mov (Σ valor de crédito,
     * amortização e poupança, mostrado só como ⇄), gasto (Σ gastos operacionais), por_dia [data => gasto operacional],
     * por_usuario [usuario_id => n].
     */
    public static function totais(string $mes, array $f): array
    {
        [$where, $params] = self::filtro($mes, $f);
        $stmt = Database::connection()->prepare(
            'SELECT l.data, l.usuario_id, COUNT(*) AS n,
                    SUM(CASE WHEN c.operacional = 1 THEN c.sinal * l.valor ELSE 0 END) AS soma,
                    SUM(CASE WHEN c.operacional = 1 THEN 1 ELSE 0 END) AS n_op,
                    SUM(CASE WHEN c.operacional = 1 THEN 0 ELSE l.valor END) AS mov,
                    SUM(CASE WHEN c.operacional = 1 AND c.sinal = -1 THEN l.valor ELSE 0 END) AS gasto
             FROM lancamentos l
             JOIN categorias c ON c.id = l.categoria_id
             WHERE ' . $where . '
             GROUP BY l.data, l.usuario_id'
        );
        $stmt->execute($params);
        $t = ['n' => 0, 'n_op' => 0, 'soma' => 0.0, 'mov' => 0.0, 'gasto' => 0.0, 'por_dia' => [], 'por_usuario' => []];
        foreach ($stmt->fetchAll() as $r) {
            $t['n'] += (int) $r['n'];
            $t['n_op'] += (int) $r['n_op'];
            $t['soma'] += (float) $r['soma'];
            $t['mov'] += (float) $r['mov'];
            $t['gasto'] += (float) $r['gasto'];
            $t['por_dia'][$r['data']] = ($t['por_dia'][$r['data']] ?? 0.0) + (float) $r['gasto'];
            $uid = (int) $r['usuario_id'];
            $t['por_usuario'][$uid] = ($t['por_usuario'][$uid] ?? 0) + (int) $r['n'];
        }
        return $t;
    }

    /** Um lançamento, com categoria e quem lançou. */
    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT l.*, c.nome AS categoria_nome, c.apelido, c.grupo, c.sinal, c.operacional,
                    u.nome AS usuario_nome
             FROM lancamentos l
             JOIN categorias c ON c.id = l.categoria_id
             JOIN usuarios u ON u.id = l.usuario_id
             WHERE l.id = :id'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function criar(string $data, string $descricao, int $categoriaId, float $valor, int $usuarioId): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO lancamentos (data, descricao, categoria_id, valor, usuario_id)
             VALUES (:data, :descricao, :categoria_id, :valor, :usuario_id)'
        );
        $stmt->execute([
            'data'         => $data,
            'descricao'    => $descricao,
            'categoria_id' => $categoriaId,
            'valor'        => $valor,
            'usuario_id'   => $usuarioId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Desfaz uma exclusão: recria a linha com o mesmo id e o mesmo criado_em (a conferência do
     * banco usa criado_em para o "agendado", D10). Se o id já foi reusado, cria com id novo.
     */
    public static function restaurar(array $l): int
    {
        $pdo = Database::connection();
        $dados = [
            'data'         => (string) $l['data'],
            'descricao'    => (string) $l['descricao'],
            'categoria_id' => (int) $l['categoria_id'],
            'valor'        => (float) $l['valor'],
            'usuario_id'   => (int) $l['usuario_id'],
            'criado_em'    => (string) $l['criado_em'],
        ];
        try {
            $pdo->prepare(
                'INSERT INTO lancamentos (id, data, descricao, categoria_id, valor, usuario_id, criado_em)
                 VALUES (:id, :data, :descricao, :categoria_id, :valor, :usuario_id, :criado_em)'
            )->execute(['id' => (int) $l['id']] + $dados);
            return (int) $l['id'];
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }
        $pdo->prepare(
            'INSERT INTO lancamentos (data, descricao, categoria_id, valor, usuario_id, criado_em)
             VALUES (:data, :descricao, :categoria_id, :valor, :usuario_id, :criado_em)'
        )->execute($dados);
        return (int) $pdo->lastInsertId();
    }

    public static function atualizar(int $id, string $data, string $descricao, int $categoriaId, float $valor): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'UPDATE lancamentos SET data = :data, descricao = :descricao, categoria_id = :categoria_id, valor = :valor
             WHERE id = :id'
        );
        $stmt->execute([
            'data'         => $data,
            'descricao'    => $descricao,
            'categoria_id' => $categoriaId,
            'valor'        => $valor,
            'id'           => $id,
        ]);
    }

    public static function excluir(int $id): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('DELETE FROM lancamentos WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /** Soma operacional (Entradas/Fixos/Dia a dia/Cartão/Ano) de um mês, filtrando por sinal (+1 ou -1). */
    public static function somaOperacional(string $mes, int $sinal): float
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(l.valor), 0) AS total
             FROM lancamentos l
             JOIN categorias c ON c.id = l.categoria_id
             WHERE c.operacional = 1 AND c.sinal = :sinal
               AND l.data >= :inicio AND l.data < :fim'
        );
        $stmt->execute([
            'sinal'  => $sinal,
            'inicio' => $mes,
            'fim'    => self::fimDoMes($mes),
        ]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Entradas (sinal +1, qualquer grupo) de um mês: previsto e real por categoria.
     * @return list<array{id:int,nome:string,apelido:?string,grupo:string,operacional:bool,previsto:float,real:float}>
     */
    public static function entradasDoMes(string $mes): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT c.id, c.nome, c.apelido, c.grupo, c.operacional,
                    COALESCE((SELECT MAX(p.valor) FROM previsao p WHERE p.categoria_id = c.id AND p.mes = :mes1), 0) AS previsto,
                    COALESCE((SELECT SUM(l.valor) FROM lancamentos l
                              WHERE l.categoria_id = c.id AND l.data >= :mes2 AND l.data < :fim), 0) AS realizado
             FROM categorias c
             WHERE c.sinal = 1
             ORDER BY c.ordem, c.id'
        );
        $stmt->execute(['mes1' => $mes, 'mes2' => $mes, 'fim' => self::fimDoMes($mes)]);
        $out = [];
        foreach ($stmt as $r) {
            $out[] = [
                'id' => (int) $r['id'], 'nome' => (string) $r['nome'],
                'apelido' => $r['apelido'] !== null ? (string) $r['apelido'] : null,
                'grupo' => (string) $r['grupo'], 'operacional' => (int) $r['operacional'] === 1,
                'previsto' => (float) $r['previsto'], 'real' => (float) $r['realizado'],
            ];
        }
        return $out;
    }

    /** Soma dos lançamentos de uma categoria (pelo id) num mês. */
    public static function somaPorCategoria(int $categoriaId, string $mes): float
    {
        $stmt = Database::connection()->prepare(
            'SELECT COALESCE(SUM(valor), 0) FROM lancamentos
             WHERE categoria_id = :c AND data >= :inicio AND data < :fim'
        );
        $stmt->execute(['c' => $categoriaId, 'inicio' => $mes, 'fim' => self::fimDoMes($mes)]);
        return (float) $stmt->fetchColumn();
    }

    /** Data do primeiro lançamento de uma categoria num mês (pra saber se foi pago antes/depois do vencimento). */
    public static function primeiraDataPorCategoriaNome(string $categoriaNome, string $mes): ?string
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT MIN(l.data) AS primeira
             FROM lancamentos l
             JOIN categorias c ON c.id = l.categoria_id
             WHERE c.nome = :nome AND l.data >= :inicio AND l.data < :fim'
        );
        $stmt->execute([
            'nome'   => $categoriaNome,
            'inicio' => $mes,
            'fim'    => self::fimDoMes($mes),
        ]);
        $data = $stmt->fetchColumn();
        return $data ?: null;
    }

    /** Soma lançamentos de uma categoria específica (pelo nome) num mês. */
    public static function somaPorCategoriaNome(string $categoriaNome, string $mes): float
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(l.valor), 0) AS total
             FROM lancamentos l
             JOIN categorias c ON c.id = l.categoria_id
             WHERE c.nome = :nome AND l.data >= :inicio AND l.data < :fim'
        );
        $stmt->execute([
            'nome'   => $categoriaNome,
            'inicio' => $mes,
            'fim'    => self::fimDoMes($mes),
        ]);
        return (float) $stmt->fetchColumn();
    }

    /** Saldo calculado na conta em uma data, a partir do saldo inicial + todos os lançamentos até lá. */
    public static function saldoCalculado(float $saldoInicial, string $ateData): float
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(l.valor * c.sinal), 0) AS total
             FROM lancamentos l
             JOIN categorias c ON c.id = l.categoria_id
             WHERE l.data <= :ate'
        );
        $stmt->execute(['ate' => $ateData]);
        return $saldoInicial + (float) $stmt->fetchColumn();
    }

    /**
     * Existe lançamento idêntico (mesma data, categoria e valor)? Usado pro aviso de duplicidade.
     * @return array{descricao:string,usuario_nome:string,data:string}|null
     */
    public static function existeIgual(string $data, int $categoriaId, float $valor): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT l.descricao, l.data, u.nome AS usuario_nome
             FROM lancamentos l JOIN usuarios u ON u.id = l.usuario_id
             WHERE l.data = :data AND l.categoria_id = :cat AND l.valor = :valor
             ORDER BY l.id DESC
             LIMIT 1'
        );
        $stmt->execute(['data' => $data, 'cat' => $categoriaId, 'valor' => $valor]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Quantas vezes cada categoria foi lançada desde $desde: pelo usuário ('meu') e pela casa ('casa').
     * @return array<int, array{meu:int, casa:int}>
     */
    public static function frequenciaCategorias(int $usuarioId, string $desde): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT categoria_id, SUM(CASE WHEN usuario_id = :u THEN 1 ELSE 0 END) AS meu, COUNT(*) AS casa
             FROM lancamentos
             WHERE data >= :desde
             GROUP BY categoria_id'
        );
        $stmt->execute(['u' => $usuarioId, 'desde' => $desde]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['categoria_id']] = ['meu' => (int) $r['meu'], 'casa' => (int) $r['casa']];
        }
        return $out;
    }

    /**
     * As descrições mais usadas desde $desde (para o <datalist>), sem as que são só o nome da
     * categoria (descrição vazia já vira o nome).
     * @return string[]
     */
    public static function descricoesFrequentes(string $desde, int $limite = 30): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT l.descricao
             FROM lancamentos l
             JOIN categorias c ON c.id = l.categoria_id
             WHERE l.data >= :desde AND l.descricao <> c.nome
             GROUP BY l.descricao
             ORDER BY COUNT(*) DESC, MAX(l.data) DESC
             LIMIT ' . max(1, min(100, $limite))
        );
        $stmt->execute(['desde' => $desde]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
