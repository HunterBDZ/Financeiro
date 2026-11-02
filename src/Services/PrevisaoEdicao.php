<?php
namespace Financeiro\Services;

/**
 * Edição da previsão na tela Mês (POST /mes/previsao): transforma o pedido do casal nas células
 * de PrevisaoLote::gravar(). Aritmética pura sobre MotorFinanceiro::dados() — quem grava é o
 * MesController, pelo PrevisaoLote (invariante 7).
 *
 * Ações: salvar (valor digitado) · tirar (0 neste mês) · meta (apaga o ajuste: volta a seguir a meta).
 * Com $propagar, vale para este mês e para todos os meses PREVISAO seguintes (até MesModel::ultimo()).
 * Salvar exatamente o valor da meta também apaga o ajuste (a categoria continua seguindo a meta).
 */
final class PrevisaoEdicao
{
    public const ACOES = ['salvar', 'tirar', 'meta'];
    public const ERRO_VALOR = 'Digite um valor, por exemplo 1.200 ou 1.200,50.';

    /** Só categorias operacionais (renda e gasto): empréstimo e poupança ficam com o motor. */
    public static function editavel(?array $cat): bool
    {
        return $cat !== null && (int) $cat['operacional'] === 1;
    }

    /** A meta que vale como previsão padrão da categoria (só gasto ativo), ou null. */
    public static function meta(array $d, int $cat): ?float
    {
        return isset($d['metas'][$cat]) ? (float) $d['metas'][$cat] : null;
    }

    /**
     * @param array $d MotorFinanceiro::dados()
     * @return list<array{0:int,1:string,2:?float}> células para PrevisaoLote::gravar()
     * @throws \InvalidArgumentException mensagem curta, pronta para o usuário
     */
    public static function celulas(array $d, int $cat, string $mes, string $acao, ?float $valor, bool $propagar): array
    {
        if (!self::editavel($d['cats'][$cat] ?? null)) {
            throw new \InvalidArgumentException(t('Escolha uma categoria.'));
        }
        if (!in_array($acao, self::ACOES, true)) {
            throw new \InvalidArgumentException(t('Algo deu errado. Tente de novo.'));
        }

        $situacao = null;
        $alvo = [];
        foreach ($d['meses'] as $m) {
            $k = (string) $m['mes'];
            if ($k === $mes) {
                $situacao = (string) $m['situacao'];
            }
            if ($m['situacao'] === 'PREVISAO' && ($k === $mes || ($propagar && $k > $mes))) {
                $alvo[] = $k;
            }
        }
        if ($situacao === null) {
            throw new \InvalidArgumentException(t('Não encontramos esse mês. Volte e tente de novo.'));
        }
        if ($situacao !== 'PREVISAO') {
            throw new \InvalidArgumentException(t('{mes} está fechado', ['mes' => monthLabel($mes)]));
        }

        $meta = self::meta($d, $cat);
        switch ($acao) {
            case 'salvar':
                if ($valor === null || $valor < 0 || $valor > valorMaximo()) {
                    // i18n: t('Digite um valor, por exemplo 1.200 ou 1.200,50.')
                    throw new \InvalidArgumentException(t(self::ERRO_VALOR));
                }
                $v = round($valor, 2);
                $novo = $meta !== null && abs($v - $meta) < 0.005 ? null : $v;
                break;
            case 'tirar':
                $novo = 0.0;
                break;
            default: // 'meta'
                if ($meta === null) {
                    throw new \InvalidArgumentException(t('sem meta'));
                }
                $novo = null;
        }

        return array_map(static fn(string $k): array => [$cat, $k, $novo], $alvo);
    }

    /**
     * Primeiro mês (a partir de $desde) que a mudança empurrou para baixo da reserva ou para o
     * vermelho: ['mes' => …, 'tipo' => 'reserva'|'vermelho'], ou null. Compara dois planos do motor.
     */
    public static function piorou(array $antes, array $depois, string $desde, float $reserva): ?array
    {
        foreach ($depois as $mes => $l) {
            if ($mes < $desde || !isset($antes[$mes]) || $l['estado'] === 'real') {
                continue;
            }
            $a = round((float) $antes[$mes]['fica_na_conta'], 2);
            $b = round((float) $l['fica_na_conta'], 2);
            if ($a >= 0 && $b < 0) {
                return ['mes' => $mes, 'tipo' => 'vermelho'];
            }
            if ($a >= $reserva - 0.004 && $b < $reserva - 0.004) {
                return ['mes' => $mes, 'tipo' => 'reserva'];
            }
        }
        return null;
    }
}
