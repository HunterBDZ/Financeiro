<?php
namespace Financeiro;

/**
 * Gera QR Code (ISO/IEC 18004), modo byte, correção M, versões 1 a 10: mais de 200 caracteres, sobra para um endereço.
 * Sem biblioteca e sem rede: o endereço da casa da pessoa não sai do computador.
 */
final class QrCode
{
    /** Versão => [códigos de correção por bloco, blocos do tipo 1, dados por bloco do tipo 1, blocos do tipo 2, dados por bloco do tipo 2]. */
    private const BLOCOS = [
        1 => [10, 1, 16, 0, 0], 2 => [16, 1, 28, 0, 0], 3 => [26, 1, 44, 0, 0], 4 => [18, 2, 32, 0, 0], 5 => [24, 2, 43, 0, 0],
        6 => [16, 4, 27, 0, 0], 7 => [18, 4, 31, 0, 0], 8 => [22, 2, 38, 2, 39], 9 => [22, 3, 36, 2, 37], 10 => [26, 4, 43, 1, 44],
    ];
    private const ALINHAMENTO = [
        2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    /** @var list<int> */
    private static array $exp = [];
    /** @var list<int> */
    private static array $log = [];

    /**
     * @param ?int $mascara só para testar: 0 a 7; sem ela, a de menor penalidade, como manda a norma
     * @return list<list<bool>> linhas de módulos; true = escuro
     */
    public static function matriz(string $texto, ?int $mascara = null): array
    {
        $versao = self::versaoPara(strlen($texto));
        $dados = self::codigosDeDados($texto, $versao);
        $final = self::intercalar($dados, $versao);
        [$base, $reservado] = self::moldura($versao);
        self::porDados($base, $reservado, $final);

        $melhor = null;
        $menor = PHP_INT_MAX;
        foreach ($mascara === null ? range(0, 7) : [$mascara] as $m) {
            $t = self::aplicarMascara($base, $reservado, $m);
            self::porFormato($t, $m, $versao);
            $nota = $mascara === null ? self::penalidade($t) : 0;
            if ($nota < $menor) {
                $menor = $nota;
                $melhor = $t;
            }
        }
        return $melhor;
    }

    /** Fundo sempre branco e margem de 4 módulos: tema escuro invertido não lê em todo celular. */
    public static function svg(string $texto, string $rotulo): string
    {
        $m = self::matriz($texto);
        $lado = count($m) + 8;
        $d = '';
        foreach ($m as $y => $linha) {
            foreach ($linha as $x => $escuro) {
                if ($escuro) {
                    $d .= 'M' . ($x + 4) . ' ' . ($y + 4) . 'h1v1h-1z';
                }
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $lado . ' ' . $lado . '" role="img" aria-label="' . e($rotulo) . '" shape-rendering="crispEdges">'
            . '<rect width="' . $lado . '" height="' . $lado . '" fill="#fff"/><path d="' . $d . '" fill="#000"/></svg>';
    }

    private static function versaoPara(int $bytes): int
    {
        foreach (self::BLOCOS as $v => [, $b1, $d1, $b2, $d2]) {
            $cabeca = $v < 10 ? 2 : 3;      // modo e contagem, arredondados para bytes
            if ($bytes + $cabeca <= $b1 * $d1 + $b2 * $d2) {
                return $v;
            }
        }
        throw new \InvalidArgumentException('Texto longo demais para o QR Code.');
    }

    /** @return list<int> */
    private static function codigosDeDados(string $texto, int $versao): array
    {
        [, $b1, $d1, $b2, $d2] = self::BLOCOS[$versao];
        $capacidade = $b1 * $d1 + $b2 * $d2;
        $bits = '0100' . str_pad(decbin(strlen($texto)), $versao < 10 ? 8 : 16, '0', STR_PAD_LEFT);
        for ($i = 0, $n = strlen($texto); $i < $n; $i++) {
            $bits .= str_pad(decbin(ord($texto[$i])), 8, '0', STR_PAD_LEFT);
        }
        $bits .= str_repeat('0', min(4, $capacidade * 8 - strlen($bits)));
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - strlen($bits) % 8);
        }
        $out = array_map('bindec', str_split($bits, 8));
        for ($p = 0; count($out) < $capacidade; $p++) {
            $out[] = $p % 2 === 0 ? 0xEC : 0x11;
        }
        return $out;
    }

    /** @param list<int> $dados  @return list<int> dados e correção, intercalados por bloco */
    private static function intercalar(array $dados, int $versao): array
    {
        [$ec, $b1, $d1, $b2, $d2] = self::BLOCOS[$versao];
        $blocos = [];
        $pos = 0;
        foreach ([[$b1, $d1], [$b2, $d2]] as [$qtd, $tam]) {
            for ($i = 0; $i < $qtd; $i++) {
                $blocos[] = array_slice($dados, $pos, $tam);
                $pos += $tam;
            }
        }
        $correcoes = array_map(static fn(array $b): array => self::reedSolomon($b, $ec), $blocos);
        $out = [];
        for ($i = 0, $max = max($d1, $d2); $i < $max; $i++) {
            foreach ($blocos as $b) {
                if (isset($b[$i])) {
                    $out[] = $b[$i];
                }
            }
        }
        for ($i = 0; $i < $ec; $i++) {
            foreach ($correcoes as $c) {
                $out[] = $c[$i];
            }
        }
        return $out;
    }

    /** @param list<int> $dados  @return list<int> */
    private static function reedSolomon(array $dados, int $grau): array
    {
        self::prepararGalois();
        $gerador = [1];
        for ($i = 0; $i < $grau; $i++) {
            $novo = array_fill(0, count($gerador) + 1, 0);
            foreach ($gerador as $j => $c) {
                $novo[$j] ^= $c;
                $novo[$j + 1] ^= self::multiplicar($c, self::$exp[$i]);
            }
            $gerador = $novo;
        }
        $resto = array_merge($dados, array_fill(0, $grau, 0));
        foreach (array_keys($dados) as $i) {
            $fator = $resto[$i];
            if ($fator !== 0) {
                foreach ($gerador as $j => $c) {
                    $resto[$i + $j] ^= self::multiplicar($c, $fator);
                }
            }
        }
        return array_slice($resto, count($dados));
    }

    private static function prepararGalois(): void
    {
        if (self::$exp !== []) {
            return;
        }
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
    }

    private static function multiplicar(int $a, int $b): int
    {
        return $a === 0 || $b === 0 ? 0 : self::$exp[(self::$log[$a] + self::$log[$b]) % 255];
    }

    /** @return array{0:list<list<bool>>,1:list<list<bool>>} módulos fixos e onde não entra dado */
    private static function moldura(int $versao): array
    {
        $n = 17 + 4 * $versao;
        $m = array_fill(0, $n, array_fill(0, $n, false));
        $r = $m;
        $por = static function (int $x, int $y, bool $escuro) use (&$m, &$r): void {
            $m[$y][$x] = $escuro;
            $r[$y][$x] = true;
        };
        // localizadores, com a borda clara
        foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$ox, $oy]) {
            for ($dy = -1; $dy <= 7; $dy++) {
                for ($dx = -1; $dx <= 7; $dx++) {
                    $x = $ox + $dx;
                    $y = $oy + $dy;
                    if ($x >= 0 && $y >= 0 && $x < $n && $y < $n) {
                        $dentro = $dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6;
                        $anel = $dx === 0 || $dx === 6 || $dy === 0 || $dy === 6;
                        $miolo = $dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4;
                        $por($x, $y, $dentro && ($anel || $miolo));
                    }
                }
            }
        }
        // alinhamento
        foreach (self::ALINHAMENTO[$versao] ?? [] as $cy) {
            foreach (self::ALINHAMENTO[$versao] as $cx) {
                if ($r[$cy][$cx]) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $por($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        // linhas de tempo
        for ($i = 8; $i < $n - 8; $i++) {
            if (!$r[6][$i]) {
                $por($i, 6, $i % 2 === 0);
            }
            if (!$r[$i][6]) {
                $por(6, $i, $i % 2 === 0);
            }
        }
        // área do formato e o módulo escuro
        for ($i = 0; $i < 9; $i++) {
            if (!$r[8][$i]) {
                $por($i, 8, false);
            }
            if (!$r[$i][8]) {
                $por(8, $i, false);
            }
        }
        for ($i = 0; $i < 8; $i++) {
            $por($n - 1 - $i, 8, false);
            $por(8, $n - 1 - $i, false);
        }
        $por(8, $n - 8, true);
        // área da versão
        if ($versao >= 7) {
            for ($i = 0; $i < 6; $i++) {
                for ($j = 0; $j < 3; $j++) {
                    $por($n - 11 + $j, $i, false);
                    $por($i, $n - 11 + $j, false);
                }
            }
        }
        return [$m, $r];
    }

    /** @param list<list<bool>> $m  @param list<list<bool>> $r  @param list<int> $codigos */
    private static function porDados(array &$m, array $r, array $codigos): void
    {
        $n = count($m);
        $bits = '';
        foreach ($codigos as $c) {
            $bits .= str_pad(decbin($c), 8, '0', STR_PAD_LEFT);
        }
        $i = 0;
        $total = strlen($bits);
        $sobe = true;
        for ($col = $n - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col--;
            }
            for ($k = 0; $k < $n; $k++) {
                $y = $sobe ? $n - 1 - $k : $k;
                foreach ([$col, $col - 1] as $x) {
                    if (!$r[$y][$x]) {
                        $m[$y][$x] = $i < $total && $bits[$i] === '1';
                        $i++;
                    }
                }
            }
            $sobe = !$sobe;
        }
    }

    /**
     * @param list<list<bool>> $m  @param list<list<bool>> $r
     * @return list<list<bool>>
     */
    private static function aplicarMascara(array $m, array $r, int $mascara): array
    {
        foreach ($m as $y => $linha) {
            foreach ($linha as $x => $escuro) {
                if ($r[$y][$x]) {
                    continue;
                }
                $troca = match ($mascara) {
                    0 => ($y + $x) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($y + $x) % 3 === 0,
                    4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
                    5 => ($y * $x) % 2 + ($y * $x) % 3 === 0,
                    6 => (($y * $x) % 2 + ($y * $x) % 3) % 2 === 0,
                    default => (($y + $x) % 2 + ($y * $x) % 3) % 2 === 0,
                };
                if ($troca) {
                    $m[$y][$x] = !$escuro;
                }
            }
        }
        return $m;
    }

    /** @param list<list<bool>> $m */
    private static function porFormato(array &$m, int $mascara, int $versao): void
    {
        $n = count($m);
        $dados = $mascara;      // correção M = 00
        $f = $dados << 10;
        for ($i = 14; $i >= 10; $i--) {
            if ($f & (1 << $i)) {
                $f ^= 0x537 << ($i - 10);
            }
        }
        $f = (($dados << 10) | $f) ^ 0x5412;
        $bit = static fn(int $v, int $i): bool => (bool) (($v >> $i) & 1);
        // bit 0 é o menos significativo
        $primeira = [[8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8], [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8]];
        foreach ($primeira as $i => [$x, $y]) {
            $m[$y][$x] = $bit($f, $i);
        }
        for ($i = 0; $i < 8; $i++) {
            $m[8][$n - 1 - $i] = $bit($f, $i);
        }
        for ($i = 8; $i < 15; $i++) {
            $m[$n - 15 + $i][8] = $bit($f, $i);
        }
        if ($versao >= 7) {
            $v = $versao << 12;
            for ($i = 17; $i >= 12; $i--) {
                if ($v & (1 << $i)) {
                    $v ^= 0x1F25 << ($i - 12);
                }
            }
            $v = ($versao << 12) | $v;
            for ($i = 0; $i < 18; $i++) {
                $a = intdiv($i, 3);
                $b = $i % 3 + $n - 11;
                $m[$a][$b] = $bit($v, $i);
                $m[$b][$a] = $bit($v, $i);
            }
        }
    }

    /** @param list<list<bool>> $m */
    private static function penalidade(array $m): int
    {
        $n = count($m);
        $nota = 0;
        $padrao = [true, false, true, true, true, false, true];
        $escuros = 0;
        for ($i = 0; $i < $n; $i++) {
            foreach ([array_values($m[$i]), array_column($m, $i)] as $linha) {
                $seq = 1;
                for ($j = 1; $j < $n; $j++) {
                    if ($linha[$j] === $linha[$j - 1]) {
                        $seq++;
                    } else {
                        $nota += $seq >= 5 ? $seq - 2 : 0;
                        $seq = 1;
                    }
                }
                $nota += $seq >= 5 ? $seq - 2 : 0;
                for ($j = 0; $j + 7 <= $n; $j++) {
                    if (array_slice($linha, $j, 7) === $padrao) {
                        $antes = $j >= 4 && !in_array(true, array_slice($linha, $j - 4, 4), true);
                        $depois = $j + 11 <= $n && !in_array(true, array_slice($linha, $j + 7, 4), true);
                        $nota += ($antes ? 40 : 0) + ($depois ? 40 : 0);
                    }
                }
            }
            foreach ($m[$i] as $j => $e) {
                if ($e) {
                    $escuros++;
                }
                if ($i + 1 < $n && $j + 1 < $n && $e === $m[$i][$j + 1] && $e === $m[$i + 1][$j] && $e === $m[$i + 1][$j + 1]) {
                    $nota += 3;
                }
            }
        }
        return $nota + intdiv(abs($escuros * 100 - $n * $n * 50), $n * $n) * 2;
    }
}
