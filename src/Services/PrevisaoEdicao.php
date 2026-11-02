<?php
namespace Financeiro\Services;

/**
 * Edição da previsão na tela Mês (POST /mes/previsao): transforma o pedido da pessoa nas células
 * de PrevisaoLote::gravar(). Aritmética pura sobre MotorFinanceiro::dados() — quem grava é o
 * MesController, pelo PrevisaoLote (invariante 7).
 *
 * Ações: salvar (valor digitado) · tirar (0 neste mês) · meta (apaga o ajuste: volta a seguir a meta).
 * Com $propagar, vale para este mês e para todos os meses PREVISAO seguintes (até MesModel::ultimo()).
 * Salvar exatamente o valor da meta também apaga o ajuste (a categoria continua seguindo a meta).
 * "Foi o valor final" (aMenos()) é salvar só no mês o que veio, quando veio menos que o previsto.
 */
final class PrevisaoEdicao
{
    public const ACOES = ['salvar', 'tirar', 'meta'];
    public const ERRO_VALOR = 'Digite um valor, por exemplo 1.200 ou 1.200,50.';
    /** Diferença de centavos que não conta como "veio menos". */
    public const TOLERANCIA = 0.05;

    /** Só categorias operacionais (renda e gasto): empréstimo e poupança ficam com o motor. */
    public static function editavel(?array $cat): bool
    {
        return $cat !== null && (int) $cat['operacional'] === 1;
    }

    /** Veio alguma coisa, mas menos que o previsto (com tolerância de centavos): parcial ou mais barato, só a pessoa sabe. */
    public static function veioMenos(float $veio, float $previsto): bool
    {
        return $veio > 0.004 && $veio < $previsto - self::TOLERANCIA;
    }

    /**
     * Uma conta ou entrada que veio menos que o previsto no mês: o que veio, o que se esperava e a previsão
     * que deixa o mês exato se esse foi o valor final (o mês aberto usa o maior entre lançado e previsto).
     * Null se não veio nada, se veio o previsto ou no dia a dia, onde gastar menos que a meta durante o mês
     * é o normal. O Salário com a parcela do empréstimo descontada é previsto bruto e chega líquido: compara
     * sem a parcela e grava com ela. A Fatura do cartão compara o que foi pago da fatura que vence no mês,
     * mesmo antes dele (CartaoFatura::atribuir).
     * @param array $d MotorFinanceiro::dados()
     * @param float $parcela a parcela descontada do salário no mês (linha do motor: parcela_descontada)
     * @return ?array{veio:float, previsto:float, final:float, parcela:bool}
     */
    public static function aMenos(array $d, int $cat, string $mes, float $parcela = 0.0): ?array
    {
        $c = $d['cats'][$cat] ?? null;
        if (!self::editavel($c) || $c['grupo'] === 'Dia a dia') {
            return null;
        }
        $veio = $cat === ($d['fatura']['cat'] ?? null)
            ? (float) ($d['fatura']['faturas'][$mes]['pago'] ?? 0.0)
            : (float) ($d['real'][$mes][$cat] ?? 0.0);
        $desconto = $c['nome'] === MotorFinanceiro::CAT_SALARIO && $parcela > 0.004 ? $parcela : 0.0;
        $previsto = (float) ($d['prev'][$mes][$cat] ?? 0.0) - $desconto;
        if (!self::veioMenos($veio, $previsto)) {
            return null;
        }
        return ['veio' => round($veio, 2), 'previsto' => round($previsto, 2), 'final' => round($veio + $desconto, 2), 'parcela' => $desconto > 0.0];
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
