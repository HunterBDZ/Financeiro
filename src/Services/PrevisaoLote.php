<?php
namespace Financeiro\Services;

use Financeiro\Database;
use Financeiro\Models\AjusteModel;
use Financeiro\Models\MetaModel;
use Financeiro\Models\PrevisaoModel;
use Financeiro\Sql;

/**
 * Único caminho de gravação da previsão: grava um lote de células
 * com log (antes/depois) para desfazer e auditar. Transação só é aberta aqui se ninguém abriu
 * antes (POST de Fechar mês e tests/run.php já abrem a sua).
 * Valor null numa célula = apagar a linha do mês (a categoria volta a seguir a meta); no log,
 * antes/depois NULL = "sem linha", e o desfazer apaga ou recria a linha conforme o caso.
 * A aba Metas também grava em mês fechado ($fechados) e muda a meta no mesmo lote ($meta). A meta de antes
 * fica em `ajustes`, na chave meta_lote_<lote>, porque o schema não tem coluna para isso; o desfazer a devolve.
 */
final class PrevisaoLote
{
    public const ORIGENS = ['manual', 'propagar', 'sugestao', 'fechar', 'migracao', 'congelar', 'cartao'];

    /** Chave de `ajustes` que guarda a meta de antes de um lote: meta_lote_<lote>. */
    public const CHAVE_META = 'meta_lote_';

    /**
     * @param list<array{0:int,1:string,2:?float}> $celulas [categoria_id, mes 'Y-m-01', valor | null]
     * @param bool $fechados aceita também mês fechado (a aba Metas corrige a meta de um mês que já passou)
     * @param ?array{cat:int, valor:?float, mes:string} $meta a meta padrão da categoria passa a ser valor (null = sem
     *        meta); mes é o mês do lote quando nenhuma célula muda
     * @return ?string o id do lote; null se não houver nada a gravar (lista vazia, ou todas as
     *                 células já têm esse valor, e a meta fica igual) — o chamador mostra "nada mudou".
     * @throws \InvalidArgumentException origem/célula inválida, ou mês que não está em PREVISAO
     */
    public static function gravar(string $origem, array $celulas, int $usuarioId, ?int $objetivoNovo = null, bool $fechados = false, ?array $meta = null): ?string
    {
        if (!in_array($origem, self::ORIGENS, true)) {
            throw new \InvalidArgumentException('Origem inválida: ' . $origem);
        }
        if ($celulas === [] && $meta === null) {
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
            $stSit = $pdo->prepare('SELECT situacao FROM meses WHERE mes = :mes' . Sql::travandoLinhas());
            $stAntes = $pdo->prepare('SELECT valor FROM previsao WHERE categoria_id = :c AND mes = :m' . Sql::travandoLinhas());
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
                if ($sit[$mes] !== 'PREVISAO' && !($fechados && $sit[$mes] === 'REAL')) {
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

            // a meta padrão: muda só se o valor for outro
            $metaMuda = null;
            if ($meta !== null) {
                $metaCat = (int) $meta['cat'];
                $metaDepois = $meta['valor'] === null ? null : round((float) $meta['valor'], 2);
                if ($metaCat <= 0 || ($metaDepois !== null && $metaDepois < 0) || !preg_match('/^\d{4}-\d{2}-01$/', (string) $meta['mes'])) {
                    throw new \InvalidArgumentException('Meta inválida');
                }
                $stMeta = $pdo->prepare('SELECT valor FROM metas WHERE categoria_id = :c' . Sql::travandoLinhas());
                $stMeta->execute(['c' => $metaCat]);
                $a = $stMeta->fetchColumn();
                $metaAntes = $a === false ? null : (float) $a;
                if (!self::iguais($metaAntes, $metaDepois)) {
                    $metaMuda = ['c' => $metaCat, 'a' => $metaAntes, 'd' => $metaDepois];
                }
            }

            if ($mudar === [] && $metaMuda === null) {
                if ($abriu) {
                    $pdo->commit();
                }
                return null;
            }

            $lote = bin2hex(random_bytes(8));
            $mesInicio = $mudar !== [] ? min(array_map(fn($m) => $m[1], $mudar)) : (string) $meta['mes'];
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
            if ($metaMuda !== null) {
                if ($metaMuda['d'] === null) {
                    MetaModel::remover($metaMuda['c']);
                } else {
                    MetaModel::definir($metaMuda['c'], $metaMuda['d']);
                }
                $pdo->prepare('INSERT INTO ajustes (chave, label, valor, confirmado) VALUES (:chave, :label, :valor, 0)')->execute([
                    'chave' => self::CHAVE_META . $lote, 'label' => (string) json_encode($metaMuda), 'valor' => $metaMuda['c'],
                ]);
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
     * Desfaz um lote: restaura `antes` só nas células que continuam iguais a `depois` e cujo mês ainda
     * está em PREVISAO, ou já estava fechado quando o lote foi gravado (a aba Metas corrige mês fechado).
     * antes NULL = apaga a linha: volta a valer a meta, ou 0 sem meta.
     * Restaura o objetivo e a meta se ninguém os mudou depois. A meta conta como um dos valores.
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
            $st = $pdo->prepare('SELECT * FROM previsao_lotes WHERE lote = :l' . Sql::travandoLinhas());
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
                "SELECT p.valor, m.situacao, m.fechado_em FROM meses m
                 LEFT JOIN previsao p ON p.mes = m.mes AND p.categoria_id = :c
                 WHERE m.mes = :m" . Sql::travandoLinhas()
            );
            $restauradas = 0;
            foreach ($log as $l) {
                $stAtual->execute(['c' => (int) $l['categoria_id'], 'm' => $l['mes']]);
                $atual = $stAtual->fetch();
                // valor NULL no LEFT JOIN = o mês não tem linha (previsao.valor é NOT NULL)
                $valorAtual = $atual && $atual['valor'] !== null ? (float) $atual['valor'] : null;
                $depois = $l['depois'] !== null ? (float) $l['depois'] : null;
                // mês fechado só volta se já estava fechado quando o lote mexeu nele: ao fechar, o mês guardou o que valia
                $mexivel = $atual && ($atual['situacao'] === 'PREVISAO'
                    || ($atual['fechado_em'] !== null && (string) $atual['fechado_em'] < (string) $info['criado_em']));
                if (!$mexivel || !self::iguais($valorAtual, $depois)) {
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

            // a meta que o lote mudou (aba Metas): volta, se continua a que o lote gravou
            $total = count($log);
            $st = $pdo->prepare('SELECT label FROM ajustes WHERE chave = :k');
            $st->execute(['k' => self::CHAVE_META . $lote]);
            $metaLog = json_decode((string) $st->fetchColumn(), true);
            if (is_array($metaLog) && isset($metaLog['c'])) {
                $total++;
                $c = (int) $metaLog['c'];
                $antesMeta = isset($metaLog['a']) ? (float) $metaLog['a'] : null;
                $existe = $pdo->prepare('SELECT COUNT(*) FROM categorias WHERE id = :c');
                $existe->execute(['c' => $c]);
                if ((int) $existe->fetchColumn() > 0 && self::iguais(MetaModel::valor($c), isset($metaLog['d']) ? (float) $metaLog['d'] : null)) {
                    if ($antesMeta === null) {
                        MetaModel::remover($c);
                    } else {
                        MetaModel::definir($c, $antesMeta);
                    }
                    $restauradas++;
                }
                $pdo->prepare('DELETE FROM ajustes WHERE chave = :k')->execute(['k' => self::CHAVE_META . $lote]);
            }

            $pdo->prepare('UPDATE previsao_lotes SET desfeito_em = ' . Sql::agora() . ' WHERE lote = :l')->execute(['l' => $lote]);

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
        return ['restauradas' => $restauradas, 'total' => $total, 'ja_desfeito' => false];
    }

    /**
     * O lote mais recente ainda não desfeito, dos últimos 7 dias (fora congelar e cartão: o cartão é
     * dono da Fatura do cartão enquanto alimenta o plano, e se ajeita sozinho a cada compra).
     * @return ?array{lote:string, origem:string, criado_em:string, n:int, mes_inicio:string, usuario_id:int}
     */
    public static function ultimoDesfazivel(): ?array
    {
        $pdo = Database::connection();
        $row = $pdo->query(
            'SELECT l.lote, l.origem, l.criado_em, l.mes_inicio, l.usuario_id,
                    (SELECT COUNT(*) FROM previsao_log g WHERE g.lote = l.lote) AS n
             FROM previsao_lotes l
             WHERE l.desfeito_em IS NULL AND l.origem NOT IN (\'congelar\', \'cartao\') AND l.criado_em >= (' . Sql::agoraMais(-7, 'DAY') . ')
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

    /**
     * Ao reabrir: desfaz o ajuste que fechar este mês aplicou no mês seguinte (lote 'fechar', que começa nele).
     * O que a pessoa mudou depois continua, porque desfazer() só restaura o que segue igual.
     * @return ?array{mes:string,restauradas:int,total:int} null se o fechamento não ajustou nada
     */
    public static function desfazerAjusteDoFechamento(string $mes, int $usuarioId): ?array
    {
        $pdo = Database::connection();
        $st = $pdo->prepare('SELECT MIN(mes) FROM meses WHERE mes > :m');
        $st->execute(['m' => $mes]);
        $prox = $st->fetchColumn();
        if (!$prox) {
            return null;
        }
        $st = $pdo->prepare(
            "SELECT lote FROM previsao_lotes WHERE origem = 'fechar' AND mes_inicio = :p AND desfeito_em IS NULL
             ORDER BY criado_em DESC LIMIT 1"
        );
        $st->execute(['p' => $prox]);
        $lote = $st->fetchColumn();
        if ($lote === false) {
            return null;
        }
        $r = self::desfazer((string) $lote, $usuarioId);
        return ['mes' => (string) $prox, 'restauradas' => $r['restauradas'], 'total' => $r['total']];
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
