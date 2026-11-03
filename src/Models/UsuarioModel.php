<?php
namespace Financeiro\Models;

use Financeiro\Database;
use Financeiro\Sql;

final class UsuarioModel
{
    /**
     * A senha de quem teve o acesso removido em Pessoas: nenhum password_verify confere com ela. A pessoa continua no
     * banco, porque os lançamentos dela ficam (e apontam para ela); o login continua reservado. Definir uma senha nova
     * para ela, em Pessoas ou pelo tools/redefinir-senha.php, devolve o acesso. Sem migração: o banco não muda.
     */
    public const SEM_ACESSO = '!';

    /** @return array<int,array{id:int,nome:string,login:string,acesso:int}> todas, também as sem acesso (o Extrato filtra por elas) */
    public static function todos(): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, nome, login, CASE WHEN senha_hash = ? THEN 0 ELSE 1 END AS acesso FROM usuarios ORDER BY id');
        $stmt->execute([self::SEM_ACESSO]);
        return array_map(static fn(array $u): array => ['acesso' => (int) $u['acesso']] + $u, $stmt->fetchAll());
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

    /** A conta mais antiga com acesso é a que administra o site: backup, atualização e acesso pela rede. */
    public static function administradorId(): ?int
    {
        $st = Database::connection()->prepare('SELECT MIN(id) FROM usuarios WHERE senha_hash <> ?');
        $st->execute([self::SEM_ACESSO]);
        $id = $st->fetchColumn();
        return $id === null || $id === false ? null : (int) $id;
    }

    /**
     * Remover o acesso de uma pessoa (Pessoas): ela não entra mais, e o que ela lançou fica. Não vale para quem está
     * fazendo, nem para a última pessoa com acesso. Confere e grava numa transação, para duas remoções ao mesmo tempo
     * não tirarem o acesso de todos. Quem chama derruba as sessões e os aparelhos dela (Auth::sairDeTodos).
     * 'ok' | 'proprio' | 'ultimo' | 'inexistente'.
     */
    public static function removerAcesso(int $alvo, int $quem): string
    {
        if ($alvo === $quem) {
            return 'proprio';
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT senha_hash FROM usuarios WHERE id = ?');
            $st->execute([$alvo]);
            $hash = $st->fetchColumn();
            $st = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE senha_hash <> ?');
            $st->execute([self::SEM_ACESSO]);
            $comAcesso = (int) $st->fetchColumn();
            $r = match (true) {
                $hash === false || $hash === self::SEM_ACESSO => 'inexistente',
                $comAcesso <= 1                                => 'ultimo',
                default                                        => 'ok',
            };
            if ($r === 'ok') {
                $pdo->prepare('UPDATE usuarios SET senha_hash = ?, duas_etapas_segredo = NULL, duas_etapas_passo = NULL WHERE id = ?')->execute([self::SEM_ACESSO, $alvo]);
                $pdo->prepare('DELETE FROM duas_etapas_recuperacao WHERE usuario_id = ?')->execute([$alvo]);
            }
            $pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
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
            self::erroSenha($senha) !== null => self::erroSenha($senha),
            self::loginExiste($login) => t('Esse login já existe.'),
            default => null,
        };
    }

    /**
     * A regra de senha, a mesma em todo lugar: criar conta, Pessoas e trocar a própria. Pelo menos 8 caracteres, e não
     * só espaços (de qualquer tipo). null = serve.
     */
    public static function erroSenha(string $senha): ?string
    {
        return match (true) {
            mb_strlen($senha) < 8 => t('A senha precisa de pelo menos 8 caracteres.'),
            preg_match('/\A[\s\p{Z}]*\z/u', $senha) === 1 => t('A senha não pode ser só espaços.'),
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
