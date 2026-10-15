<?php
namespace Financeiro\Services;

use Financeiro\Database;
use Financeiro\Models\AjusteModel;
use Financeiro\Models\PrevisaoModel;

/**
 * Único caminho de gravação da previsão (invariante 7, SPEC §4.6): grava um lote de células
 * com log (antes/depois) para desfazer e auditar. Transação só é aberta aqui se ninguém abriu
 * antes (POST de Fechar mês e tests/run.php já abrem a sua).
 * Valor null numa célula = apagar a linha do mês (a categoria volta a seguir a meta); no log,
 * antes/depois NULL = "sem linha", e o desfazer apaga ou recria a linha conforme o caso.
 */
final class PrevisaoLote
{
    public const ORIGENS = ['manual', 'propagar', 'sugestao', 'fechar', 'migracao', 'congelar'];

    /**
     * @param list<array{0:int,1:string,2:?float}> $celulas [categoria_id, mes 'Y-m-01', valor | null]
     * @return ?string o id do lote; null se não houver nada a gravar (lista vazia, ou todas as
     *                 células já têm esse valor) — o chamador mostra "nada mudou".
     * @throws \InvalidArgumentException origem/célula inválida, ou mês que não está em PREVISAO
     */
    public static function gravar(string $origem, array $celulas, int $usuarioId, ?int $objetivoNovo = null): ?string
    {
        if (!in_array($origem, self::ORIGENS, true)) {
            throw new \InvalidArgumentException('Origem inválida: ' . $origem);
        }
        if ($celulas === []) {
            return null;
        }
        if ($objetivoNovo !== null && !in_array($objetivoNovo, [1, 2, 3], true)) {
            throw new \InvalidArgumentException('Objetivo inválido');
        }

        $pdo = Database::connection();
        $abriu = !$pdo->inTransaction();
        if ($abriu) {
            $pdo->beginTransaction();
        }
        try {
            // situação dos meses (trava as linhas: ninguém fecha o mês no meio da gravação)
            $sit = [];
            $stSit = $pdo->prepare('SELECT situacao FROM meses WHERE mes = :mes FOR UPDATE');
            $stAntes = $pdo->prepare('SELECT valor FROM previsao WHERE categoria_id = :c AND mes = :m FOR UPDATE');
            $mudar = [];
            foreach ($celulas as $cel) {
                $valor = $cel[2] ?? null;
                [$cat, $mes, $valor] = [(int) $cel[0], (string) $cel[1], $valor === null ? null : round((float) $valor, 2)];
                if ($cat <= 0 || !preg_match('/^\d{4}-\d{2}-01$/', $mes) || ($valor !== null && $valor < 0)) {
                    throw new \InvalidArgumentException('Célula inválida');
                }
                if (!isset($sit[$mes])) {
                    $stSit->execute(['mes' => $mes]);
                    $sit[$mes] = $stSit->fetchColumn() ?: null;
                }
                if ($sit[$mes] !== 'PREVISAO') {
                    throw new \InvalidArgumentException('Mês fora de PREVISAO: ' . $mes);
                }
                $stAntes->execute(['c' => $cat, 'm' => $mes]);
                $a = $stAntes->fetchColumn();
                $antes = $a === false ? null : (float) $a;
                if (self::iguais($antes, $valor)) {
                    continue; // já está assim: não entra no lote
                }
                $mudar[$cat . '|' . $mes] = [$cat, $mes, $valor, $antes];
            }

            if ($mudar === []) {
                if ($abriu) {
                    $pdo->commit();
                }
                return null;
            }

            $lote = bin2hex(random_bytes(8));
            $mesInicio = min(array_map(fn($m) => $m[1], $mudar));
            $objAntes = null;
            if ($objetivoNovo !== null) {
                $objAntes = AjusteModel::valor('objetivo', 1.0);
            }
            $pdo->prepare(
                'INSERT INTO previsao_lotes (lote, origem, mes_inicio, usuario_id, objetivo_antes, objetivo_depois)
                 VALUES (:lote, :origem, :mes, :u, :oa, :od)'
            )->execute([
                'lote' => $lote, 'origem' => $origem, 'mes' => $mesInicio, 'u' => $usuarioId,
                'oa' => $objAntes, 'od' => $objetivoNovo,
            ]);

            $stLog = $pdo->prepare(
                'INSERT INTO previsao_log (lote, categoria_id, mes, antes, depois) VALUES (:lote, :c, :m, :a, :d)'
            );
            foreach ($mudar as [$cat, $mes, $valor, $antes]) {
                if ($valor === null) {
                    PrevisaoModel::remover($cat, $mes);
                } else {
                    PrevisaoModel::setValor($cat, $mes, $valor);
                }
                $stLog->execute(['lote' => $lote, 'c' => $cat, 'm' => $mes, 'a' => $antes, 'd' => $valor]);
            }
            if ($objetivoNovo !== null) {
                AjusteModel::setValor('objetivo', (float) $objetivoNovo);
            }

            if ($abriu) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($abriu && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        MotorFinanceiro::limparCache();
        return $lote;
    }

    /**
     * Desfaz um lote: restaura `antes` só nas células que continuam iguais a `depois` e cujo mês
     * ainda está em PREVISAO (antes NULL = apaga a linha: volta a valer a meta, ou 0 sem meta).
     * Restaura o objetivo se ninguém o mudou depois.
     * @return array{restauradas:int,total:int,ja_desfeito:bool}
     */
    public static function desfazer(string $lote, int $usuarioId): array
    {
        $pdo = Database::connection();
        $abriu = !$pdo->inTransaction();
        if ($abriu) {
            $pdo->beginTransaction();
        }
        try {
            $st = $pdo->prepare('SELECT * FROM previsao_lotes WHERE lote = :l FOR UPDATE');
            $st->execute(['l' => $lote]);
            $info = $st->fetch();
            if (!$info) {
                throw new \InvalidArgumentException('Lote inexistente');
            }
            $st = $pdo->prepare('SELECT categoria_id, mes, antes, depois FROM previsao_log WHERE lote = :l ORDER BY id');
            $st->execute(['l' => $lote]);
            $log = $st->fetchAll();

            if ($info['desfeito_em'] !== null) {
                if ($abriu) {
                    $pdo->commit();
                }
                return ['restauradas' => 0, 'total' => count($log), 'ja_desfeito' => true];
            }

            $stAtual = $pdo->prepare(
                "SELECT p.valor, m.situacao FROM meses m
                 LEFT JOIN previsao p ON p.mes = m.mes AND p.categoria_id = :c
                 WHERE m.mes = :m FOR UPDATE"
            );
            $restauradas = 0;
            foreach ($log as $l) {
                $stAtual->execute(['c' => (int) $l['categoria_id'], 'm' => $l['mes']]);
                $atual = $stAtual->fetch();
                // valor NULL no LEFT JOIN = o mês não tem linha (previsao.valor é NOT NULL)
                $valorAtual = $atual && $atual['valor'] !== null ? (float) $atual['valor'] : null;
                $depois = $l['depois'] !== null ? (float) $l['depois'] : null;
                if (!$atual || $atual['situacao'] !== 'PREVISAO' || !self::iguais($valorAtual, $depois)) {
                    continue;
                }
                if ($l['antes'] === null) {
                    PrevisaoModel::remover((int) $l['categoria_id'], (string) $l['mes']);
                } else {
                    PrevisaoModel::setValor((int) $l['categoria_id'], (string) $l['mes'], (float) $l['antes']);
                }
                $restauradas++;
            }

            if ($info['objetivo_depois'] !== null && $info['objetivo_antes'] !== null
                && abs(AjusteModel::valor('objetivo', 1.0) - (float) $info['objetivo_depois']) < 0.0001) {
                AjusteModel::setValor('objetivo', (float) $info['objetivo_antes']);
            }

            $pdo->prepare('UPDATE previsao_lotes SET desfeito_em = CURRENT_TIMESTAMP WHERE lote = :l')->execute(['l' => $lote]);

            if ($abriu) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($abriu && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        MotorFinanceiro::limparCache();
        return ['restauradas' => $restauradas, 'total' => count($log), 'ja_desfeito' => false];
    }

    /**
     * O lote mais recente ainda não desfeito, dos últimos 7 dias.
     * @return ?array{lote:string, origem:string, criado_em:string, n:int, mes_inicio:string, usuario_id:int}
     */
    public static function ultimoDesfazivel(): ?array
    {
        $pdo = Database::connection();
        $row = $pdo->query(
            'SELECT l.lote, l.origem, l.criado_em, l.mes_inicio, l.usuario_id,
                    (SELECT COUNT(*) FROM previsao_log g WHERE g.lote = l.lote) AS n
             FROM previsao_lotes l
             WHERE l.desfeito_em IS NULL AND l.origem <> \'congelar\' AND l.criado_em >= (NOW() - INTERVAL 7 DAY)
             ORDER BY l.criado_em DESC,
                      (SELECT COALESCE(MAX(g2.id), 0) FROM previsao_log g2 WHERE g2.lote = l.lote) DESC
             LIMIT 1'
        )->fetch();
        if (!$row) {
            return null;
        }
        return [
            'lote' => (string) $row['lote'], 'origem' => (string) $row['origem'], 'criado_em' => (string) $row['criado_em'],
            'n' => (int) $row['n'], 'mes_inicio' => (string) $row['mes_inicio'], 'usuario_id' => (int) $row['usuario_id'],
        ];
    }

    /**
     * Ao fechar o mês (ainda em PREVISAO): grava como linhas do próprio mês a previsão que vinha da
     * meta, para o mês fechado não mudar quando a meta mudar depois. Lote 'congelar' (fica fora do
     * "desfazer última alteração"); reabrir o mês chama descongelar().
     */
    public static function congelar(string $mes, int $usuarioId): ?string
    {
        $d = MotorFinanceiro::dados();
        $celulas = [];
        foreach ($d['prev'][$mes] ?? [] as $cat => $valor) {
            if (empty($d['excecao'][$mes][$cat])) {          // veio da meta (sem linha no mês)
                $celulas[] = [(int) $cat, $mes, (float) $valor];
            }
        }
        return self::gravar('congelar', $celulas, $usuarioId);
    }

    /** Ao reabrir: desfaz o último congelamento do mês (só nas células em que ninguém mexeu depois). */
    public static function descongelar(string $mes, int $usuarioId): void
    {
        $st = Database::connection()->prepare(
            "SELECT lote FROM previsao_lotes WHERE origem = 'congelar' AND mes_inicio = :m AND desfeito_em IS NULL
             ORDER BY criado_em DESC LIMIT 1"
        );
        $st->execute(['m' => $mes]);
        $lote = $st->fetchColumn();
        if ($lote !== false) {
            self::desfazer((string) $lote, $usuarioId);
        }
    }

    /** Mesma célula? null = sem linha no mês; dois valores iguais ao centavo. */
    private static function iguais(?float $a, ?float $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        return abs($a - $b) < 0.005;
    }
}
