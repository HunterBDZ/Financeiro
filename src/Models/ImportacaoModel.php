<?php
namespace Financeiro\Models;

use Financeiro\Database;
use Financeiro\Sql;
use PDO;

/**
 * Importar extrato: os lotes gravados (`importacoes`), as regras "descrição contém → categoria"
 * (`importacao_regras`) e as colunas de cada CSV pelo hash do cabeçalho (`importacao_layouts`). Os lançamentos e as
 * compras de um lote levam importacao_id e importacao_chave (a identidade da linha no arquivo, ver Services\Importacao).
 */
final class ImportacaoModel
{
    /** Cria o lote; as linhas entram depois, na mesma transação. */
    public static function criar(?int $contaId, ?int $cartaoId, string $nome, string $formato, string $hash, int $usuarioId): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO importacoes (conta_id, cartao_id, nome, formato, hash, usuario_id) VALUES (:co, :ca, :n, :f, :h, :u)')
            ->execute(['co' => $contaId, 'ca' => $cartaoId, 'n' => mb_substr($nome, 0, 255), 'f' => $formato, 'h' => $hash, 'u' => $usuarioId]);
        return (int) $pdo->lastInsertId();
    }

    public static function contarLinhas(int $id, int $n): void
    {
        Database::connection()->prepare('UPDATE importacoes SET linhas = :n WHERE id = :id')->execute(['n' => $n, 'id' => $id]);
    }

    /** Liga um lançamento (ou uma compra, com $compra) ao lote, com a identidade da linha. */
    public static function marcar(int $id, bool $compra, int $importacaoId, string $chave): void
    {
        Database::connection()->prepare('UPDATE ' . ($compra ? 'cartao_compras' : 'lancamentos') . ' SET importacao_id = :i, importacao_chave = :k WHERE id = :id')
            ->execute(['i' => $importacaoId, 'k' => $chave, 'id' => $id]);
    }

    public static function achar(int $id): ?array
    {
        $st = Database::connection()->prepare('SELECT * FROM importacoes WHERE id = :id');
        $st->execute(['id' => $id]);
        $r = $st->fetch();
        return $r ? $r : null;
    }

    /**
     * As últimas importações, das mais novas para as mais velhas, com quem importou e quantas linhas ainda existem.
     * @return list<array>
     */
    public static function recentes(int $limite = 10): array
    {
        return Database::connection()->query(
            'SELECT i.*, u.nome AS usuario_nome,
                    (SELECT COUNT(*) FROM lancamentos l WHERE l.importacao_id = i.id) + (SELECT COUNT(*) FROM cartao_compras c WHERE c.importacao_id = i.id) AS existentes
             FROM importacoes i JOIN usuarios u ON u.id = i.usuario_id
             ORDER BY i.criado_em DESC, i.id DESC LIMIT ' . max(1, min(50, $limite))
        )->fetchAll();
    }

    /** O mesmo arquivo já entrou (e não foi desfeito)? A data da importação, ou null. */
    public static function jaImportado(string $hash): ?string
    {
        $st = Database::connection()->prepare('SELECT criado_em FROM importacoes WHERE hash = :h AND desfeita_em IS NULL ORDER BY id DESC LIMIT 1');
        $st->execute(['h' => $hash]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    /**
     * Os meses em que as linhas do lote caem (Y-m-01), do mais antigo ao mais novo: o mês da data, num lançamento, e o
     * da 1ª fatura, numa compra no cartão (é a fatura que muda o valor quando a compra sai).
     * @return list<string>
     */
    public static function meses(int $id): array
    {
        $st = Database::connection()->prepare(
            'SELECT ' . Sql::inicioMes('data') . ' AS mes FROM lancamentos WHERE importacao_id = :a
             UNION SELECT ' . Sql::inicioMes('primeira_fatura') . ' AS mes FROM cartao_compras WHERE importacao_id = :b ORDER BY mes'
        );
        $st->execute(['a' => $id, 'b' => $id]);
        return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Apaga as linhas do lote e marca o lote como desfeito; quem chama cuida da transação.
     * @return int quantas linhas saíram
     */
    public static function desfazer(int $id): int
    {
        $pdo = Database::connection();
        $n = 0;
        foreach (['lancamentos', 'cartao_compras'] as $tabela) {
            $st = $pdo->prepare('DELETE FROM ' . $tabela . ' WHERE importacao_id = :id');
            $st->execute(['id' => $id]);
            $n += $st->rowCount();
        }
        $pdo->prepare('UPDATE importacoes SET desfeita_em = ' . Sql::agora() . ' WHERE id = :id AND desfeita_em IS NULL')->execute(['id' => $id]);
        return $n;
    }

    /**
     * As identidades que já estão gravadas, entre as pedidas, no destino (lancamentos de uma conta ou compras de um
     * cartão). @param list<string> $chaves @return array<string, true>
     */
    public static function chavesGravadas(string $tipo, array $chaves): array
    {
        $out = [];
        $tabela = $tipo === 'cartao' ? 'cartao_compras' : 'lancamentos';
        foreach (array_chunk(array_values(array_unique($chaves)), 400) as $lote) {
            $ph = implode(', ', array_fill(0, count($lote), '?'));
            $st = Database::connection()->prepare('SELECT importacao_chave FROM ' . $tabela . ' WHERE importacao_chave IN (' . $ph . ')');
            $st->execute($lote);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $k) {
                $out[(string) $k] = true;
            }
        }
        return $out;
    }

    /**
     * Os lançamentos de uma conta entre duas datas, para achar o que já foi lançado à mão: id, data, valor, o sinal da
     * categoria, a descrição e a identidade de importação (null se não veio de uma).
     * @return list<array{id:int, data:string, valor:float, sinal:int, descricao:string, chave:?string}>
     */
    public static function lancamentosDaConta(int $contaId, string $de, string $ate): array
    {
        $st = Database::connection()->prepare(
            'SELECT l.id, l.data, l.valor, c.sinal, l.descricao, l.importacao_chave, c.nome AS cat_nome, c.apelido AS cat_apelido
             FROM lancamentos l JOIN categorias c ON c.id = l.categoria_id
             WHERE l.conta_id = :c AND l.data >= :de AND l.data <= :ate ORDER BY l.data, l.id'
        );
        $st->execute(['c' => $contaId, 'de' => $de, 'ate' => $ate]);
        return array_map(static fn(array $r): array => ['id' => (int) $r['id'], 'data' => (string) $r['data'], 'valor' => (float) $r['valor'],
            'sinal' => (int) $r['sinal'], 'descricao' => (string) $r['descricao'], 'chave' => $r['importacao_chave'] !== null ? (string) $r['importacao_chave'] : null,
            'categoria' => [(string) $r['cat_nome'], (string) ($r['cat_apelido'] ?? '')]], $st->fetchAll());
    }

    /** As compras de um cartão com a identidade de importação. @return list<array> */
    public static function comprasDoCartao(int $cartaoId): array
    {
        $st = Database::connection()->prepare(
            'SELECT cc.id, cc.data, cc.descricao, cc.valor_total, cc.parcelas, cc.primeira_fatura, cc.importacao_chave, c.nome AS cat_nome, c.apelido AS cat_apelido
             FROM cartao_compras cc LEFT JOIN categorias c ON c.id = cc.categoria_id WHERE cc.cartao_id = :c'
        );
        $st->execute(['c' => $cartaoId]);
        return array_map(static fn(array $r): array => ['id' => (int) $r['id'], 'data' => (string) $r['data'], 'descricao' => (string) $r['descricao'],
            'valor_total' => (float) $r['valor_total'], 'parcelas' => (int) $r['parcelas'], 'primeira_fatura' => (string) $r['primeira_fatura'],
            'chave' => $r['importacao_chave'] !== null ? (string) $r['importacao_chave'] : null,
            'categoria' => [(string) ($r['cat_nome'] ?? ''), (string) ($r['cat_apelido'] ?? '')]], $st->fetchAll());
    }

    /**
     * A memória das categorizações: quantas vezes cada descrição foi para cada categoria, e quando foi a última, nos
     * lançamentos e nas compras do cartão desde $desde. A descrição vem como está; quem chama normaliza.
     * @return list<array{descricao:string, categoria_id:int, n:int, ultima:string}>
     */
    public static function memoria(string $desde): array
    {
        $out = [];
        foreach (['lancamentos', 'cartao_compras'] as $tabela) {
            $st = Database::connection()->prepare(
                'SELECT descricao, categoria_id, COUNT(*) AS n, MAX(data) AS ultima FROM ' . $tabela . '
                 WHERE data >= :d AND categoria_id IS NOT NULL GROUP BY descricao, categoria_id'
            );
            $st->execute(['d' => $desde]);
            foreach ($st->fetchAll() as $r) {
                $out[] = ['descricao' => (string) $r['descricao'], 'categoria_id' => (int) $r['categoria_id'], 'n' => (int) $r['n'], 'ultima' => (string) $r['ultima']];
            }
        }
        return $out;
    }

    /* ================================================================== regras */

    /** @return list<array{id:int, contem:string, categoria_id:int, categoria_nome:string, apelido:?string}> */
    public static function regras(): array
    {
        return array_map(static fn(array $r): array => ['id' => (int) $r['id'], 'contem' => (string) $r['contem'], 'categoria_id' => (int) $r['categoria_id'],
            'categoria_nome' => (string) $r['categoria_nome'], 'apelido' => $r['apelido'] !== null ? (string) $r['apelido'] : null],
            Database::connection()->query(
                'SELECT r.id, r.contem, r.categoria_id, c.nome AS categoria_nome, c.apelido FROM importacao_regras r
                 JOIN categorias c ON c.id = r.categoria_id WHERE c.ativo = 1 ORDER BY r.contem, r.id'
            )->fetchAll());
    }

    /** Cria a regra, ou troca a categoria da que já tem esse texto (sem acento e sem caixa). */
    public static function salvarRegra(string $contem, int $categoriaId, int $usuarioId): void
    {
        Database::connection()->prepare(
            'INSERT INTO importacao_regras (contem, categoria_id, usuario_id) VALUES (:t, :c, :u) '
            . Sql::aoRepetir('contem', ['categoria_id' => null, 'usuario_id' => null])
        )->execute(['t' => $contem, 'c' => $categoriaId, 'u' => $usuarioId]);
    }

    public static function removerRegra(int $id): void
    {
        Database::connection()->prepare('DELETE FROM importacao_regras WHERE id = :id')->execute(['id' => $id]);
    }

    /* ================================================================== colunas do CSV */

    /** As colunas lembradas para esse cabeçalho, ou null. */
    public static function layout(string $hash): ?array
    {
        $st = Database::connection()->prepare('SELECT mapa FROM importacao_layouts WHERE hash = :h');
        $st->execute(['h' => $hash]);
        $v = $st->fetchColumn();
        $mapa = $v !== false ? json_decode((string) $v, true) : null;
        return is_array($mapa) ? $mapa : null;
    }

    public static function salvarLayout(string $hash, array $mapa): void
    {
        Database::connection()->prepare(
            'INSERT INTO importacao_layouts (hash, mapa) VALUES (:h, :m) ' . Sql::aoRepetir('hash', ['mapa' => null, 'atualizado_em' => Sql::agora()])
        )->execute(['h' => $hash, 'm' => json_encode($mapa)]);
    }
}
