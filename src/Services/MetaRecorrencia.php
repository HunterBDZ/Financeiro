<?php
namespace Financeiro\Services;

/**
 * Meta com recorrência (aba Metas): transforma "valor V a partir de M, até N ou sem fim" nas células de
 * PrevisaoLote::gravar() e na meta padrão. Aritmética pura sobre MotorFinanceiro::dados(); quem grava é o
 * MetaController, pelo PrevisaoLote, numa transação só (invariante 7).
 *
 * Sem fim a partir de M: V vira a meta padrão. De M em diante, os meses em PREVISAO perdem o valor próprio e
 * seguem a meta, e os fechados guardam V como linha do mês. Antes de M, os meses em PREVISAO que seguiam a meta
 * guardam o valor de antes, para não mudar.
 * Intervalo [M, N], ou só M: V em cada mês do intervalo, aberto ou fechado; a meta padrão fica como está.
 * V null ou zero: sem fim, a meta sai; no intervalo, o mês fica com zero.
 */
final class MetaRecorrencia
{
    /** Só gasto ativo: as categorias de CategoriaModel::gastos(), as únicas que têm meta. */
    public static function editavel(?array $cat): bool
    {
        return $cat !== null && (int) $cat['ativo'] === 1 && (int) $cat['operacional'] === 1 && (int) $cat['sinal'] === -1;
    }

    /**
     * @param array $d MotorFinanceiro::dados()
     * @param ?float $valor null = sem meta
     * @param ?string $ate null = sem fim; igual a $desde = só este mês
     * @return array{celulas: list<array{0:int,1:string,2:?float}>, meta: ?array{cat:int, valor:?float, mes:string}}
     * @throws \InvalidArgumentException mensagem curta, pronta para a pessoa
     */
    public static function plano(array $d, int $cat, ?float $valor, string $desde, ?string $ate): array
    {
        if (!self::editavel($d['cats'][$cat] ?? null)) {
            throw new \InvalidArgumentException(t('Escolha uma categoria.'));
        }
        if ($valor !== null && ($valor < 0 || $valor > valorMaximo())) {
            // i18n: t('Digite um valor, por exemplo 1.200 ou 1.200,50.')
            throw new \InvalidArgumentException(t(PrevisaoEdicao::ERRO_VALOR));
        }
        $sit = [];
        foreach ($d['meses'] as $m) {
            $sit[(string) $m['mes']] = (string) $m['situacao'];
        }
        if (!isset($sit[$desde]) || ($ate !== null && !isset($sit[$ate]))) {
            throw new \InvalidArgumentException(t('Não encontramos esse mês. Volte e tente de novo.'));
        }
        if ($ate !== null && $ate < $desde) {
            throw new \InvalidArgumentException(t('O mês final vem antes do primeiro. Escolha outro.'));
        }

        $v = $valor !== null && $valor > 0.004 ? round($valor, 2) : null;
        $meta = PrevisaoEdicao::meta($d, $cat);
        $celulas = [];

        if ($ate === null) {
            foreach ($sit as $m => $s) {
                $proprio = !empty($d['excecao'][$m][$cat]);
                if ($m < $desde) {
                    // seguia a meta e mudaria com ela: guarda o que valia
                    if ($s === 'PREVISAO' && !$proprio && !self::iguais($meta, $v)) {
                        $celulas[] = [$cat, $m, round((float) ($d['prev'][$m][$cat] ?? 0.0), 2)];
                    }
                } elseif ($s === 'REAL') {
                    $celulas[] = [$cat, $m, $v];                 // mês fechado não segue a meta: o valor fica no mês
                } elseif ($proprio) {
                    $celulas[] = [$cat, $m, null];               // volta a seguir a meta
                }
            }
            return ['celulas' => $celulas, 'meta' => ['cat' => $cat, 'valor' => $v, 'mes' => $desde]];
        }

        foreach ($sit as $m => $s) {
            if ($m < $desde || $m > $ate) {
                continue;
            }
            if ($s === 'REAL') {
                $celulas[] = [$cat, $m, $v];                     // fechado sem linha = zero
            } elseif ($v === null) {
                $celulas[] = [$cat, $m, $meta !== null ? 0.0 : null];
            } else {
                $celulas[] = [$cat, $m, $meta !== null && abs($v - $meta) < 0.005 ? null : $v];   // o valor da meta = seguir a meta
            }
        }
        return ['celulas' => $celulas, 'meta' => null];
    }

    /** Mesmo valor? null = sem meta; dois valores iguais ao centavo. */
    private static function iguais(?float $a, ?float $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        return abs($a - $b) < 0.005;
    }
}
