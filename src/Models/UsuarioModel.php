<?php
namespace Financeiro\Models;

use Financeiro\Database;
use Financeiro\Sql;

final class UsuarioModel
{
    /** @return array<int,array{id:int,nome:string,login:string}> */
    public static function todos(): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->query('SELECT id, nome, login FROM usuarios ORDER BY id');
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, nome, login FROM usuarios WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Ainda não há conta: a primeira é criada no site, em /criar-conta. */
    public static function nenhum(): bool
    {
        return (int) Database::connection()->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() === 0;
    }

    /** A conta mais antiga é a que administra o site: backup, atualização e acesso pela rede. */
    public static function administradorId(): ?int
    {
        $id = Database::connection()->query('SELECT MIN(id) FROM usuarios')->fetchColumn();
        return $id === null || $id === false ? null : (int) $id;
    }

    public static function loginExiste(string $login): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM usuarios WHERE login = ?');
        $stmt->execute([$login]);
        return (bool) $stmt->fetchColumn();
    }

    /** O que há de errado nos dados de uma conta nova; null = pode criar. */
    public static function erroNovaConta(string $nome, string $login, string $senha): ?string
    {
        return match (true) {
            $nome === '' => t('Informe o nome.'),
            $login === '' => t('Informe o login.'),
            mb_strlen($senha) < 8 => t('A senha precisa de pelo menos 8 caracteres.'),
            self::loginExiste($login) => t('Esse login já existe.'),
            default => null,
        };
    }

    public static function criar(string $nome, string $login, string $senha): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO usuarios (nome, login, senha_hash) VALUES (:nome, :login, :hash)'
        );
        $stmt->execute([
            'nome'  => $nome,
            'login' => $login,
            'hash'  => password_hash($senha, PASSWORD_BCRYPT),
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** O tema da conta; null = automático. */
    public static function tema(int $id): ?string
    {
        $st = Database::connection()->prepare('SELECT tema FROM usuarios WHERE id = ?');
        $st->execute([$id]);
        $t = $st->fetchColumn();
        return $t === false || $t === null ? null : (string) $t;
    }

    public static function salvarTema(int $id, ?string $tema): void
    {
        Database::connection()->prepare('UPDATE usuarios SET tema = ? WHERE id = ?')->execute([$tema, $id]);
    }

    /** O idioma da conta; null = ainda não escolheu. */
    public static function idioma(int $id): ?string
    {
        $st = Database::connection()->prepare('SELECT idioma FROM usuarios WHERE id = ?');
        $st->execute([$id]);
        $i = $st->fetchColumn();
        return $i === false || $i === null ? null : (string) $i;
    }

    public static function salvarIdioma(int $id, string $idioma): void
    {
        Database::connection()->prepare('UPDATE usuarios SET idioma = ? WHERE id = ?')->execute([$idioma, $id]);
    }

    /** A ordem do menu da conta, em JSON (Financeiro\Menu); null = a ordem padrão. */
    public static function menuOrdem(int $id): ?string
    {
        $st = Database::connection()->prepare('SELECT menu_ordem FROM usuarios WHERE id = ?');
        $st->execute([$id]);
        $o = $st->fetchColumn();
        return $o === false || $o === null ? null : (string) $o;
    }

    public static function salvarMenuOrdem(int $id, ?string $json): void
    {
        Database::connection()->prepare('UPDATE usuarios SET menu_ordem = ? WHERE id = ?')->execute([$json, $id]);
    }

    public static function senhaHash(int $id): ?string
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT senha_hash FROM usuarios WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $hash = $stmt->fetchColumn();
        return $hash ?: null;
    }

    public static function trocarSenha(int $id, string $novaSenha): void
    {
        $pdo = Database::connection();
        $pdo->prepare('UPDATE usuarios SET senha_hash = :hash WHERE id = :id')
            ->execute(['hash' => password_hash($novaSenha, PASSWORD_BCRYPT), 'id' => $id]);
    }

    /** Versão das sessões do usuário (null = usuário não existe mais). */
    public static function sessaoVersao(int $id): ?int
    {
        $stmt = Database::connection()->prepare('SELECT sessao_versao FROM usuarios WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (int) $v;
    }

    /** +1 na versão (derruba as sessões PHP vivas nos outros aparelhos). Devolve a versão nova. */
    public static function incrementarSessaoVersao(int $id): int
    {
        Database::connection()->prepare('UPDATE usuarios SET sessao_versao = sessao_versao + 1 WHERE id = :id')
            ->execute(['id' => $id]);
        return self::sessaoVersao($id) ?? 0;
    }

    public static function marcarLogin(int $id): void
    {
        Database::connection()->prepare('UPDATE usuarios SET ultimo_login = ' . Sql::agora() . ' WHERE id = :id')
            ->execute(['id' => $id]);
    }
}
