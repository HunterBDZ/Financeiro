<?php
namespace Financeiro\Models;

use Financeiro\Database;
use Financeiro\Sql;

/** Itens da Wishlist. `posicao` é a ordem da lista; excluir é reversível (some de vez depois de 30 dias). */
final class WishlistModel
{
    private const CAMPOS = 'i.id, i.nome, i.url, i.valor, i.moeda, i.posicao, i.comprado_em, f.hash AS foto_hash, f.largura AS foto_largura, f.altura AS foto_altura';

    /** @return list<array> a lista, do 1º ao último */
    public static function ativos(): array
    {
        return Database::connection()->query(
            'SELECT ' . self::CAMPOS . ' FROM wishlist_itens i LEFT JOIN wishlist_fotos f ON f.item_id = i.id
             WHERE i.excluido_em IS NULL AND i.comprado_em IS NULL ORDER BY i.posicao, i.id'
        )->fetchAll();
    }

    /** @return list<array> o que já foi comprado, do mais recente para o mais antigo */
    public static function comprados(): array
    {
        return Database::connection()->query(
            'SELECT ' . self::CAMPOS . ' FROM wishlist_itens i LEFT JOIN wishlist_fotos f ON f.item_id = i.id
             WHERE i.excluido_em IS NULL AND i.comprado_em IS NOT NULL ORDER BY i.comprado_em DESC, i.id DESC'
        )->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $st = Database::connection()->prepare(
            'SELECT ' . self::CAMPOS . ' FROM wishlist_itens i LEFT JOIN wishlist_fotos f ON f.item_id = i.id
             WHERE i.id = ? AND i.excluido_em IS NULL'
        );
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function criar(string $nome, ?string $url, ?float $valor, int $usuarioId, string $moeda = 'BRL'): int
    {
        $pdo = Database::connection();
        $st = $pdo->prepare('INSERT INTO wishlist_itens (nome, url, valor, moeda, posicao, usuario_id) VALUES (?, ?, ?, ?, ?, ?)');
        $st->execute([$nome, $url, $valor, $moeda, self::proximaPosicao(), $usuarioId]);
        return (int) $pdo->lastInsertId();
    }

    public static function atualizar(int $id, string $nome, ?string $url, ?float $valor, string $moeda = 'BRL'): void
    {
        Database::connection()->prepare('UPDATE wishlist_itens SET nome = ?, url = ?, valor = ?, moeda = ? WHERE id = ?')
            ->execute([$nome, $url, $valor, $moeda, $id]);
    }

    /** @param array{tipo:string, largura:int, altura:int, dados:string} $foto */
    public static function salvarFoto(int $id, array $foto): void
    {
        $st = Database::connection()->prepare(
            'REPLACE INTO wishlist_fotos (item_id, tipo, largura, altura, hash, dados) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $st->bindValue(1, $id, \PDO::PARAM_INT);
        $st->bindValue(2, $foto['tipo']);
        $st->bindValue(3, $foto['largura'], \PDO::PARAM_INT);
        $st->bindValue(4, $foto['altura'], \PDO::PARAM_INT);
        $st->bindValue(5, sha1($foto['dados']));
        $st->bindValue(6, $foto['dados'], \PDO::PARAM_LOB);
        $st->execute();
    }

    public static function removerFoto(int $id): void
    {
        Database::connection()->prepare('DELETE FROM wishlist_fotos WHERE item_id = ?')->execute([$id]);
    }

    /** @return ?array{tipo:string, hash:string, dados:string} */
    public static function foto(int $id): ?array
    {
        $st = Database::connection()->prepare('SELECT tipo, hash, dados FROM wishlist_fotos WHERE item_id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Muda a ordem: subir, descer ou primeiro. A lista é regravada de 1 em diante. */
    public static function mover(int $id, string $para): void
    {
        $ids = array_map(static fn(array $i): int => (int) $i['id'], self::ativos());
        $pos = array_search($id, $ids, true);
        if ($pos === false) {
            return;
        }
        $alvo = match ($para) {
            'primeiro' => 0,
            'subir'    => max(0, $pos - 1),
            'descer'   => min(count($ids) - 1, $pos + 1),
            default    => $pos,
        };
        array_splice($ids, $pos, 1);
        array_splice($ids, $alvo, 0, [$id]);
        self::gravarOrdem($ids);
    }

    /** A lista nesta ordem, com as posições de 1 em diante. */
    private static function gravarOrdem(array $ids): void
    {
        $pdo = Database::connection();
        $abriu = !$pdo->inTransaction();
        if ($abriu) {
            $pdo->beginTransaction();
        }
        $st = $pdo->prepare('UPDATE wishlist_itens SET posicao = ? WHERE id = ?');
        foreach ($ids as $i => $item) {
            $st->execute([$i + 1, $item]);
        }
        if ($abriu) {
            $pdo->commit();
        }
    }

    /**
     * O item que voltou para a lista (desfazer "comprei" ou "excluir") fica no lugar que tinha: a posição dele continua
     * gravada, então ele entra antes do primeiro item que estava depois dele, e a lista é regravada de 1 em diante.
     */
    private static function voltarAoLugar(int $id): void
    {
        $st = Database::connection()->prepare('SELECT posicao FROM wishlist_itens WHERE id = ?');
        $st->execute([$id]);
        $pos = (int) $st->fetchColumn();
        $ids = [];
        $antes = 0;
        $naLista = false;                           // a comprada que volta da lixeira fica nas compradas
        foreach (self::ativos() as $i) {            // em ordem de posição
            if ((int) $i['id'] === $id) {
                $naLista = true;
                continue;
            }
            $ids[] = (int) $i['id'];
            $antes += (int) $i['posicao'] < $pos ? 1 : 0;
        }
        if (!$naLista) {
            return;
        }
        array_splice($ids, $antes, 0, [$id]);
        self::gravarOrdem($ids);
    }

    public static function marcarComprado(int $id, string $data): void
    {
        Database::connection()->prepare('UPDATE wishlist_itens SET comprado_em = ? WHERE id = ?')->execute([$data, $id]);
    }

    /** Volta para a lista, no lugar que tinha. */
    public static function devolver(int $id): void
    {
        Database::connection()->prepare('UPDATE wishlist_itens SET comprado_em = NULL WHERE id = ?')->execute([$id]);
        self::voltarAoLugar($id);
    }

    public static function excluir(int $id): void
    {
        $pdo = Database::connection();
        $pdo->prepare('UPDATE wishlist_itens SET excluido_em = ' . Sql::agora() . ' WHERE id = ?')->execute([$id]);
        $pdo->exec('DELETE FROM wishlist_itens WHERE excluido_em < ' . Sql::agoraMais(-30, 'DAY'));
    }

    /** A excluída volta para a lista, no lugar que tinha (a comprada volta para as compradas). */
    public static function restaurar(int $id): void
    {
        Database::connection()->prepare('UPDATE wishlist_itens SET excluido_em = NULL WHERE id = ?')->execute([$id]);
        self::voltarAoLugar($id);
    }

    private static function proximaPosicao(): int
    {
        return 1 + (int) Database::connection()->query(
            'SELECT COALESCE(MAX(posicao), 0) FROM wishlist_itens WHERE excluido_em IS NULL AND comprado_em IS NULL'
        )->fetchColumn();
    }
}
