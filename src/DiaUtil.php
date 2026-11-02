<?php
namespace Financeiro;

/**
 * Dia útil bancário no Brasil: não é sábado, domingo nem feriado bancário nacional. Conta que vence num dia que não
 * é útil pode ser paga no próximo dia útil sem atraso (docs/decisoes.md, "Vencimento em dia útil").
 *
 * Feriados fixos: 1/1, 21/4, 1/5, 7/9, 12/10, 2/11, 15/11, 20/11 e 25/12. Móveis, a partir da Páscoa: segunda e terça de
 * Carnaval (−48 e −47 dias), Sexta-feira Santa (−2) e Corpus Christi (+60). Nada de lista por ano: a Páscoa é calculada
 * (algoritmo de Meeus, Jones e Butcher, para o calendário gregoriano). Feriados estaduais e municipais ficam de fora.
 */
final class DiaUtil
{
    /** Os feriados fixos, em 'MM-DD'. */
    private const FIXOS = ['01-01', '04-21', '05-01', '09-07', '10-12', '11-02', '11-15', '11-20', '12-25'];

    /** Os móveis, em dias a partir do domingo de Páscoa. */
    private const MOVEIS = [-48, -47, -2, 60];

    /** @var array<int, array<string, true>> ano => feriados ('AAAA-MM-DD' => true) */
    private static array $cache = [];

    /** O domingo de Páscoa do ano, em 'AAAA-MM-DD' (Meeus/Jones/Butcher; sem a extensão calendar). */
    public static function pascoa(int $ano): string
    {
        $a = $ano % 19;
        $b = intdiv($ano, 100);
        $c = $ano % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31);
        $dia = (($h + $l - 7 * $m + 114) % 31) + 1;
        return sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
    }

    /** @return list<string> os feriados bancários nacionais do ano, em 'AAAA-MM-DD', em ordem */
    public static function feriados(int $ano): array
    {
        return array_keys(self::doAno($ano));
    }

    public static function feriado(string $data): bool
    {
        return isset(self::doAno((int) substr($data, 0, 4))[substr($data, 0, 10)]);
    }

    /** Dia útil: de segunda a sexta e fora dos feriados. */
    public static function util(string $data): bool
    {
        return (int) (new \DateTimeImmutable(substr($data, 0, 10)))->format('N') <= 5 && !self::feriado($data);
    }

    /** O próprio dia, se for útil; senão, o próximo dia útil. É até quando se paga sem atraso. */
    public static function proximoUtil(string $data): string
    {
        $d = new \DateTimeImmutable(substr($data, 0, 10));
        for ($i = 0; $i < 15 && !self::util($d->format('Y-m-d')); $i++) {
            $d = $d->modify('+1 day');
        }
        return $d->format('Y-m-d');
    }

    /** @return array<string, true> */
    private static function doAno(int $ano): array
    {
        if (isset(self::$cache[$ano])) {
            return self::$cache[$ano];
        }
        $dias = [];
        foreach (self::FIXOS as $md) {
            $dias[$ano . '-' . $md] = true;
        }
        $pascoa = new \DateTimeImmutable(self::pascoa($ano));
        foreach (self::MOVEIS as $n) {
            $dias[$pascoa->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d')] = true;
        }
        ksort($dias);
        return self::$cache[$ano] = $dias;
    }
}
