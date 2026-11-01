<?php
namespace Financeiro\Services;

use Financeiro\Database;
use Financeiro\Models\AjusteModel;
use Financeiro\Models\MesModel;

/**
 * Os meses do plano não têm fim: sempre existem MESES_A_FRENTE depois do mês de hoje. Um mês novo nasce
 * com a previsão do anterior (entradas, contas fixas e dia a dia sem meta) e, nas contas pontuais, com a
 * do mesmo mês do ano passado. A meta já vale sozinha, pela view previsao_efetiva; a fatura vem do Cartão.
 */
final class Horizonte
{
    public const MESES_A_FRENTE = 24;

    public static function garantir(?string $hoje = null): void
    {
        $ultimo = MesModel::ultimo();
        if ($ultimo === null) {
            return;                                             // ainda sem início: a primeira configuração cria
        }
        $alvo = date('Y-m-01', strtotime(date('Y-m-01', strtotime($hoje ?? date('Y-m-d'))) . ' +' . self::MESES_A_FRENTE . ' month'));
        for ($mes = self::seguinte($ultimo); $mes <= $alvo; $mes = self::seguinte($mes)) {
            self::criar($mes);
        }
    }

    /**
     * Põe o início do plano nessa data: cria os meses até o horizonte e tira os que ficaram antes dela.
     * Quem chama confere antes, com problemaNaData().
     */
    public static function mudarInicio(string $data, ?string $hoje = null): void
    {
        $pdo = Database::connection();
        $mesIni = substr($data, 0, 8) . '01';
        $apagar = $pdo->prepare('DELETE FROM previsao WHERE mes < ?');
        $apagar->execute([$mesIni]);
        $pdo->prepare('DELETE FROM meses WHERE mes < ?')->execute([$mesIni]);
        $primeiro = MesModel::primeiro();
        if ($primeiro === null || $mesIni < $primeiro) {
            MesModel::ensureExists($mesIni);
            $ate = $primeiro ?? $mesIni;
            for ($mes = self::seguinte($mesIni); $mes < $ate; $mes = self::seguinte($mes)) {
                MesModel::ensureExists($mes);
            }
        }
        AjusteModel::setValor('data_inicio', (float) str_replace('-', '', $data));
        self::garantir($hoje);
    }

    /** Por que essa data não pode ser o início; null = pode. */
    public static function problemaNaData(string $data, ?string $hoje = null): ?string
    {
        if ($data > ($hoje ?? date('Y-m-d'))) {
            return t('O início não pode ser depois de hoje.');
        }
        $pdo = Database::connection();
        $st = $pdo->prepare('SELECT COUNT(*) FROM lancamentos WHERE data < ?');
        $st->execute([$data]);
        if ((int) $st->fetchColumn() > 0) {
            return t('Há lançamentos antes dessa data. Escolha uma data anterior a eles.');
        }
        $st = $pdo->prepare("SELECT COUNT(*) FROM meses WHERE situacao = 'REAL' AND mes < ?");
        $st->execute([substr($data, 0, 8) . '01']);
        if ((int) $st->fetchColumn() > 0) {
            return t('Há meses fechados antes dessa data. Reabra-os antes de mudar o início.');
        }
        return null;
    }

    private static function criar(string $mes): void
    {
        $pdo = Database::connection();
        $st = $pdo->prepare('INSERT IGNORE INTO meses (mes, situacao) VALUES (?, "PREVISAO")');
        $st->execute([$mes]);
        if ($st->rowCount() === 0) {
            return;                                             // outra requisição criou
        }
        $copia = $pdo->prepare(
            "INSERT IGNORE INTO previsao (categoria_id, mes, valor)
             SELECT p.categoria_id, ?, p.valor
             FROM previsao p JOIN categorias c ON c.id = p.categoria_id
             WHERE c.ativo = 1 AND p.valor > 0 AND (
                   (p.mes = ? AND c.grupo = 'Ano')
                OR (p.mes = ? AND c.grupo IN ('Entradas', 'Fixos', 'Dia a dia')
                    AND NOT EXISTS (SELECT 1 FROM metas m WHERE m.categoria_id = c.id)))"
        );
        $copia->execute([$mes, date('Y-m-01', strtotime($mes . ' -1 year')), date('Y-m-01', strtotime($mes . ' -1 month'))]);
    }

    private static function seguinte(string $mes): string
    {
        return date('Y-m-01', strtotime($mes . ' +1 month'));
    }
}
