<?php
namespace Financeiro\Services;

use Financeiro\Database;
use Financeiro\Models\AjusteModel;

/** Quanto está guardado e investido: o valor de partida mais os lançamentos do Extrato até hoje. Rendimento só entra se for lançado. */
final class Investimentos
{
    private const PARTES = [
        'poupanca' => ['ini' => 'poupanca_ini', 'entra' => MotorFinanceiro::CAT_GUARDAR, 'sai' => MotorFinanceiro::CAT_RESGATE],
        'outros'   => ['ini' => 'investimentos_ini', 'entra' => MotorFinanceiro::CAT_APLICAR, 'sai' => MotorFinanceiro::CAT_RESGATE_INV],
    ];

    /** @return array{poupanca:array, outros:array, total:float} */
    public static function resumo(string $hoje, int $ultimos = 6): array
    {
        $saida = ['total' => 0.0];
        foreach (self::PARTES as $parte => $p) {
            $saida[$parte] = self::parte($p, $hoje, $ultimos);
            $saida['total'] += $saida[$parte]['saldo'];
        }
        $saida['total'] = round($saida['total'], 2);
        return $saida;
    }

    /**
     * O resgate (da poupança ou de investimentos) não pode passar do que estava guardado na data dele: o valor de
     * partida mais o que entrou menos o que saiu até ali, sem o lançamento que está sendo editado ($ignorar).
     * Outra categoria passa sem conta. null = cabe; senão a frase para a tela.
     */
    public static function erroResgate(int $categoriaId, float $valor, string $data, ?int $ignorar = null): ?string
    {
        $pdo = Database::connection();
        $st = $pdo->prepare('SELECT chave FROM categorias WHERE id = ?');
        $st->execute([$categoriaId]);
        $chave = (string) $st->fetchColumn();
        foreach (self::PARTES as $p) {
            if ($chave !== $p['sai']) {
                continue;
            }
            $soma = $pdo->prepare(
                'SELECT COALESCE(SUM(CASE WHEN c.chave = ? THEN l.valor ELSE -l.valor END), 0) FROM lancamentos l JOIN categorias c ON c.id = l.categoria_id
                 WHERE c.chave IN (?, ?) AND l.data <= ? AND l.id <> ?'
            );
            $soma->execute([$p['entra'], $p['entra'], $p['sai'], $data, $ignorar ?? 0]);
            $saldo = round(AjusteModel::valor($p['ini']) + (float) $soma->fetchColumn(), 2);
            return $valor > $saldo + 0.004 ? t('O resgate passa do saldo de {valor}.', ['valor' => money(max(0.0, $saldo))]) : null;
        }
        return null;
    }

    private static function parte(array $p, string $hoje, int $ultimos): array
    {
        $pdo = Database::connection();
        $ids = $pdo->prepare('SELECT chave, id FROM categorias WHERE chave IN (?, ?)');
        $ids->execute([$p['entra'], $p['sai']]);
        $id = $ids->fetchAll(\PDO::FETCH_KEY_PAIR);
        $idEntra = isset($id[$p['entra']]) ? (int) $id[$p['entra']] : 0;
        $idSai   = isset($id[$p['sai']]) ? (int) $id[$p['sai']] : 0;

        $soma = $pdo->prepare('SELECT categoria_id, COALESCE(SUM(valor), 0) FROM lancamentos WHERE categoria_id IN (?, ?) AND data <= ? GROUP BY categoria_id');
        $soma->execute([$idEntra, $idSai, $hoje]);
        $somas = $soma->fetchAll(\PDO::FETCH_KEY_PAIR);
        $entrou = (float) ($somas[$idEntra] ?? 0.0);
        $saiu   = (float) ($somas[$idSai] ?? 0.0);
        $inicio = AjusteModel::valor($p['ini']);

        $lista = $pdo->prepare(
            'SELECT l.id, l.data, l.descricao, l.valor, l.categoria_id, c.nome AS categoria_nome, c.apelido, c.grupo, c.sinal
             FROM lancamentos l JOIN categorias c ON c.id = l.categoria_id
             WHERE l.categoria_id IN (?, ?) AND l.data <= ?
             ORDER BY l.data DESC, l.id DESC LIMIT ' . max(1, $ultimos)
        );
        $lista->execute([$idEntra, $idSai, $hoje]);

        return [
            'inicio' => $inicio, 'entrou' => $entrou, 'saiu' => $saiu,
            'saldo' => round($inicio + $entrou - $saiu, 2),
            'idEntra' => $idEntra, 'idSai' => $idSai,
            'movimentos' => $lista->fetchAll(\PDO::FETCH_ASSOC),
        ];
    }
}
