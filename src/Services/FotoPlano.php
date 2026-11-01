<?php
namespace Financeiro\Services;

use Financeiro\Database;
use Financeiro\Models\AjusteModel;

/**
 * A memória do plano: na primeira requisição de cada mês, guarda o que ele prevê a partir dali. O plano
 * é recalculado a toda hora, então sem isto não dá para saber depois o que ele dizia. A foto não depende
 * de fechar o mês, porque fechar tarde já conhece o resultado. Foto tirada depois do DIA_LIMITE não vale para comparar.
 */
final class FotoPlano
{
    public const VERSAO = 1;
    public const DIA_LIMITE = 7;

    public static function garantir(?string $hoje = null): void
    {
        $hoje ??= date('Y-m-d');
        $mes = substr($hoje, 0, 8) . '01';
        $pdo = Database::connection();
        $st = $pdo->prepare('SELECT 1 FROM plano_fotos WHERE mes = ?');
        $st->execute([$mes]);
        if ($st->fetchColumn() !== false || !AjusteModel::configurado()) {
            return;
        }
        $dados = MotorFinanceiro::dados();
        $plano = MotorFinanceiro::calcular($dados, ['hoje' => $hoje]);
        if (!isset($plano[$mes])) {
            return;
        }
        $meses = [];
        foreach ($plano as $m => $l) {
            if ($m >= $mes) {
                $meses[$m] = ['fica' => round((float) $l['fica_na_conta'], 2), 'falta' => round((float) $l['falta_pagar'], 2),
                              'entra' => round((float) $l['entra'], 2), 'sai' => round((float) $l['sai'], 2),
                              'amort' => round((float) $l['amortizacao_extra'], 2), 'mov' => round((float) $l['poupanca_liquido'], 2), 'inv' => round((float) ($l['invest_liquido'] ?? 0.0), 2)];
            }
        }
        $json = json_encode(['meses' => $meses, 'cats' => $dados['prev'][$mes] ?? []], JSON_UNESCAPED_UNICODE);
        $pdo->prepare('INSERT IGNORE INTO plano_fotos (mes, criada_em, versao, dados) VALUES (?, ?, ?, ?)')
            ->execute([$mes, $hoje, self::VERSAO, $json]);
    }

    /** A foto do mês, se ela foi tirada a tempo de valer. @return ?array{meses:array, cats:array, criada_em:string} */
    public static function valida(string $mes): ?array
    {
        $st = Database::connection()->prepare('SELECT criada_em, versao, dados FROM plano_fotos WHERE mes = ?');
        $st->execute([$mes]);
        $l = $st->fetch(\PDO::FETCH_ASSOC);
        if ($l === false || substr((string) $l['criada_em'], 0, 7) !== substr($mes, 0, 7) || (int) substr((string) $l['criada_em'], 8, 2) > self::DIA_LIMITE) {
            return null;
        }
        $d = json_decode((string) $l['dados'], true);
        return is_array($d) ? $d + ['criada_em' => (string) $l['criada_em']] : null;
    }

    /** O primeiro mês com foto que vale; null se ainda não há. */
    public static function primeiroMes(): ?string
    {
        $v = Database::connection()->query('SELECT MIN(mes) FROM plano_fotos WHERE DAY(criada_em) <= ' . self::DIA_LIMITE)->fetchColumn();
        return $v ? (string) $v : null;
    }
}
