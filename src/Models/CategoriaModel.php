<?php
namespace Financeiro\Models;

use Financeiro\Database;
use Financeiro\Sql;

final class CategoriaModel
{
    /** As categorias de fábrica que só fazem sentido para quem tem carro. */
    private const DE_CARRO = ['Seguro do carro', 'Combustível', 'IPVA', 'Licenciamento do carro', 'Revisão do carro'];

    /** @return array<int, array> todas as categorias ativas, ordenadas por grupo/ordem (com apelido e essencial) */
    public static function ativas(): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->query(
            'SELECT id, nome, apelido, icone, grupo, sinal, operacional, essencial
             FROM categorias
             WHERE ativo = 1
             ORDER BY ' . Sql::posicao('grupo', ['Entradas', 'Fixos', 'Dia a dia', 'Cartao', 'Ano', 'Emprestimo', 'Poupanca', 'Investimento']) . ', ordem, nome'
        );
        return $stmt->fetchAll();
    }

    /** Agrupadas por grupo, prontas pra montar <optgroup> no formulário. */
    public static function agrupadas(): array
    {
        $grupos = [];
        foreach (self::ativas() as $cat) {
            $grupos[$cat['grupo']][] = $cat;
        }
        return $grupos;
    }

    /** As categorias ativas do Dia a dia na ordem cadastrada. */
    public static function diaADia(): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->query(
            "SELECT id, nome, apelido, grupo, sinal, operacional, essencial
             FROM categorias
             WHERE ativo = 1 AND grupo = 'Dia a dia'
             ORDER BY ordem, nome"
        );
        return $stmt->fetchAll();
    }

    /** Todas as categorias de gasto ativas (Fixos, Dia a dia, Cartão, Ano), na ordem dos grupos. */
    public static function gastos(): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->query(
            'SELECT id, nome, apelido, grupo, sinal, operacional, essencial
             FROM categorias
             WHERE ativo = 1 AND operacional = 1 AND sinal = -1
             ORDER BY ' . Sql::posicao('grupo', ['Fixos', 'Dia a dia', 'Cartao', 'Ano']) . ', ordem, nome'
        );
        return $stmt->fetchAll();
    }

    /**
     * "Tenho carro" na primeira configuração. Sem carro, as categorias de fábrica de carro sem nenhum uso saem das
     * listas (ativo = 0, nada é apagado); com carro, voltam. A que já tem algum dado não é tocada.
     */
    public static function marcarCarro(bool $temCarro): void
    {
        $pdo = Database::connection();
        $marcas = implode(', ', array_fill(0, count(self::DE_CARRO), '?'));
        if ($temCarro) {
            $pdo->prepare("UPDATE categorias SET ativo = 1 WHERE nome IN ({$marcas})")->execute(self::DE_CARRO);
            return;
        }
        $semUso = '';
        foreach (['lancamentos', 'previsao', 'metas', 'contas_recorrentes', 'cartao_compras'] as $tabela) {
            $semUso .= " AND NOT EXISTS (SELECT 1 FROM {$tabela} t WHERE t.categoria_id = categorias.id)";
        }
        $pdo->prepare("UPDATE categorias SET ativo = 0 WHERE nome IN ({$marcas})" . $semUso)->execute(self::DE_CARRO);
    }

    /** Marca/desmarca uma categoria de gasto como essencial. Entradas e ⇄: exceção. */
    public static function setEssencial(int $id, bool $essencial): void
    {
        $cat = self::find($id);
        if ($cat === null || (int) $cat['operacional'] !== 1 || (int) $cat['sinal'] !== -1) {
            throw new \InvalidArgumentException('Só categorias de gasto podem ser essenciais');
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE categorias SET essencial = :e WHERE id = :id');
        $stmt->execute(['e' => $essencial ? 1 : 0, 'id' => $id]);
    }

    /** @return array<int, ?string> [id => categorias.icone] de todas as categorias (ativas ou não) */
    public static function icones(): array
    {
        $out = [];
        foreach (Database::connection()->query('SELECT id, icone FROM categorias')->fetchAll() as $r) {
            $out[(int) $r['id']] = $r['icone'] !== null ? (string) $r['icone'] : null;
        }
        return $out;
    }

    /** Troca o ícone (chave de src/icones_lista.php, já validada pelo controller). */
    public static function setIcone(int $id, string $icone): bool
    {
        $stmt = Database::connection()->prepare('UPDATE categorias SET icone = :i WHERE id = :id');
        $stmt->execute(['i' => $icone, 'id' => $id]);
        return $stmt->rowCount() > 0 || self::find($id) !== null;
    }

    public static function find(int $id): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM categorias WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }


    /**
     * Cria uma categoria nova a partir do extrato. Tipos aceitos:
     *   'entrada' → grupo Entradas, sinal +1, nunca essencial
     *   'saida'   → grupo Dia a dia, sinal -1, essencial conforme $essencial
     * Retorna o id novo, ou null se o nome já existir (UNIQUE) ou for inválido.
     * $nome: 1–100 caracteres já aparados pelo controller. $icone: chave já validada (ou null:
     * vale a sugestão pelo nome, ver Icones::daCategoria).
     */
    public static function criar(string $nome, string $tipo, bool $essencial, ?string $icone = null): ?int
    {
        $nome = trim($nome);
        if ($nome === '' || mb_strlen($nome) > 100 || !in_array($tipo, ['entrada', 'saida'], true)) {
            return null;
        }
        if (self::porNome($nome) !== null) {
            return null;                                    // nome já usado (inclusive em categoria inativa)
        }
        $entrada = $tipo === 'entrada';
        $grupo = $entrada ? 'Entradas' : 'Dia a dia';
        $sinal = $entrada ? 1 : -1;
        $ess   = (!$entrada && $essencial) ? 1 : 0;

        $pdo = Database::connection();
        $ordem = (int) $pdo->query("SELECT COALESCE(MAX(ordem), 0) + 1 FROM categorias WHERE grupo = " . $pdo->quote($grupo))->fetchColumn();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO categorias (nome, icone, grupo, sinal, ativo, ordem, essencial)
                 VALUES (:nome, :icone, :grupo, :sinal, 1, :ordem, :ess)'
            );
            $stmt->execute(['nome' => $nome, 'icone' => $icone, 'grupo' => $grupo, 'sinal' => $sinal, 'ordem' => $ordem, 'ess' => $ess]);
        } catch (\PDOException) {
            return null;                                    // corrida no UNIQUE, p.ex.
        }
        return (int) $pdo->lastInsertId();
    }

    /**
     * Remove uma categoria. Se ela tem lançamentos, só arquiva (ativo = 0) para não perder
     * o histórico; se não tem nenhum, apaga de vez (e limpa previsão/contas recorrentes;
     * a meta sai por ON DELETE CASCADE). Devolve 'apagada', 'arquivada' ou 'inexistente'.
     */
    public static function remover(int $id): string
    {
        $pdo = Database::connection();
        if (self::find($id) === null) {
            return 'inexistente';
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM lancamentos WHERE categoria_id = :id');
        $stmt->execute(['id' => $id]);
        if ((int) $stmt->fetchColumn() > 0) {
            $pdo->prepare('UPDATE categorias SET ativo = 0 WHERE id = :id')->execute(['id' => $id]);
            return 'arquivada';
        }
        try {
            $pdo->beginTransaction();
            foreach (['previsao', 'previsao_log', 'contas_recorrentes'] as $t) {
                $pdo->prepare("DELETE FROM {$t} WHERE categoria_id = :id")->execute(['id' => $id]);
            }
            $pdo->prepare('DELETE FROM categorias WHERE id = :id')->execute(['id' => $id]);
            $pdo->commit();
            return 'apagada';
        } catch (\PDOException) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->prepare('UPDATE categorias SET ativo = 0 WHERE id = :id')->execute(['id' => $id]);
            return 'arquivada';
        }
    }

    /** Uma categoria especial pela chave (MotorFinanceiro::CAT_*), ativa ou não. */
    public static function porChave(string $chave): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM categorias WHERE chave = :c');
        $stmt->execute(['c' => $chave]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function porNome(string $nome): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM categorias WHERE nome = :nome');
        $stmt->execute(['nome' => $nome]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
