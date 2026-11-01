<?php
namespace Financeiro\Pdf;

/**
 * Gerador de PDF sem dependências: o servidor pode não ter GD, Imagick nem navegador. Escreve o arquivo
 * direto: páginas, texto com fontes embutidas (Type0/Identity-H com ToUnicode), vetores e imagens JPEG e PNG.
 * As coordenadas da API são em pontos, com a origem no canto superior esquerdo. O PDF usa o inferior.
 */
final class Documento
{
    public const LARGURA = 595.28;
    public const ALTURA = 841.89;

    /** @var list<string> conteúdo de cada página */
    private array $paginas = [];
    private int $atual = -1;
    /** @var array<string, array{dados:array, usados:array<int,array{0:int,1:int}>, n:int}> */
    private array $fontes = [];
    /** @var list<array{tipo:string, l:int, a:int, dados:string, filtro:string, cor:string, bits:int, parms:string, smask:?string, paleta:?string}> */
    private array $imagens = [];

    public function __construct(
        private string $titulo = '',
        private string $idioma = 'pt-BR',
        private string $data = '',
    ) {
    }

    public function novaPagina(): void
    {
        $this->paginas[] = '';
        $this->atual = count($this->paginas) - 1;
    }

    public function totalPaginas(): int
    {
        return count($this->paginas);
    }

    public function irParaPagina(int $i): void
    {
        $this->atual = $i;
    }

    /* ---------------------------------------------------------------- texto */

    private function fonte(string $chave): array
    {
        if (!isset($this->fontes[$chave])) {
            $this->fontes[$chave] = ['dados' => self::lerFonte($chave), 'usados' => [], 'n' => count($this->fontes) + 1];
        }
        return $this->fontes[$chave];
    }

    private static function lerFonte(string $chave): array
    {
        static $cache = [];
        return $cache[$chave] ??= require __DIR__ . '/fontes/' . $chave . '.php';
    }

    /** Largura do texto em pontos. */
    public function medir(string $texto, string $chave, float $corpo, float $espaco = 0.0): float
    {
        $glifos = self::lerFonte($chave)['glifos'];
        $total = 0;
        $n = 0;
        foreach (self::pontos($texto) as $cp) {
            $total += ($glifos[$cp] ?? $glifos[self::SUBSTITUTO])[1];
            $n++;
        }
        return $total / 1000 * $corpo + $espaco * max(0, $n - 1);
    }

    /** Caractere sem glifo na fonte (emoji, outro alfabeto) vira este: um ponto médio, nunca uma caixinha. */
    private const SUBSTITUTO = 0xB7;

    /** @return list<int> */
    private static function pontos(string $texto): array
    {
        $saida = [];
        foreach (mb_str_split($texto, 1, 'UTF-8') as $c) {
            $cp = mb_ord($c, 'UTF-8') ?: self::SUBSTITUTO;
            $saida[] = $cp === 0xA0 ? 0x20 : $cp;                // o espaço que não quebra já não quebra aqui
        }
        return $saida;
    }

    /**
     * Escreve o texto com a linha de base em $y.
     * @param array{0:float,1:float,2:float} $cor RGB de 0 a 1
     */
    public function texto(float $x, float $y, string $texto, string $chave, float $corpo, array $cor = [0, 0, 0], string $alinhar = 'e', float $espaco = 0.0): void
    {
        if ($texto === '') {
            return;
        }
        $this->fonte($chave);
        $glifos = $this->fontes[$chave]['dados']['glifos'];
        if ($alinhar !== 'e') {
            $l = $this->medir($texto, $chave, $corpo, $espaco);
            $x -= $alinhar === 'd' ? $l : $l / 2;
        }
        $hex = '';
        foreach (self::pontos($texto) as $cp) {
            $cpReal = isset($glifos[$cp]) ? $cp : self::SUBSTITUTO;
            [$gid, $larg] = $glifos[$cpReal];
            $this->fontes[$chave]['usados'][$gid] = [$cpReal, $larg];
            $hex .= sprintf('%04X', $gid);
        }
        $this->emitir(sprintf(
            "BT /F%d %s Tf %s Tc %s %s %s Td <%s> Tj ET\n",
            $this->fontes[$chave]['n'], self::n($corpo), self::n($espaco), self::rgb($cor),
            self::n($x), self::n(self::ALTURA - $y), $hex
        ));
    }

    /**
     * Quebra o texto em linhas que cabem na largura, por palavra. Palavra maior que a linha é cortada.
     * @return list<string>
     */
    public function quebrar(string $texto, string $chave, float $corpo, float $largura): array
    {
        $linhas = [];
        foreach (preg_split('/\R/u', $texto) ?: [] as $paragrafo) {
            $atual = '';
            foreach (preg_split('/\s+/u', trim($paragrafo)) ?: [] as $palavra) {
                $tenta = $atual === '' ? $palavra : $atual . ' ' . $palavra;
                if ($this->medir($tenta, $chave, $corpo) <= $largura) {
                    $atual = $tenta;
                    continue;
                }
                if ($atual !== '') {
                    $linhas[] = $atual;
                }
                $atual = $palavra;
                while ($this->medir($atual, $chave, $corpo) > $largura && mb_strlen($atual) > 1) {
                    $corte = mb_strlen($atual) - 1;
                    while ($corte > 1 && $this->medir(mb_substr($atual, 0, $corte), $chave, $corpo) > $largura) {
                        $corte--;
                    }
                    $linhas[] = mb_substr($atual, 0, $corte);
                    $atual = mb_substr($atual, $corte);
                }
            }
            $linhas[] = $atual;
        }
        return $linhas;
    }

    /* ---------------------------------------------------------------- vetores */

    /** Retângulo, com cantos arredondados opcionais. */
    public function retangulo(float $x, float $y, float $l, float $a, ?array $preenche = null, ?array $traco = null, float $raio = 0.0, float $espessura = 0.75): void
    {
        $this->emitir($this->estilo($preenche, $traco, $espessura) . $this->caminhoRet($x, $y, $l, $a, $raio) . self::pintura($preenche, $traco) . "Q\n");
    }

    /** Retângulo com só os cantos de cima arredondados: a ponta de uma barra presa à linha de base. */
    public function barra(float $x, float $y, float $l, float $a, array $preenche, float $raio = 2.0): void
    {
        $raio = min($raio, $l / 2, $a);
        $k = 0.5523 * $raio;
        [$x0, $y0, $x1, $y1] = [$x, self::ALTURA - $y - $a, $x + $l, self::ALTURA - $y];
        $p = static fn(float ...$v): string => implode(' ', array_map(self::n(...), $v));
        $this->emitir($this->estilo($preenche, null, 0.75)
            . $p($x0, $y0) . ' m ' . $p($x1, $y0) . ' l ' . $p($x1, $y1 - $raio) . ' l '
            . $p($x1, $y1 - $raio + $k, $x1 - $raio + $k, $y1, $x1 - $raio, $y1) . ' c '
            . $p($x0 + $raio, $y1) . ' l ' . $p($x0 + $raio - $k, $y1, $x0, $y1 - $raio + $k, $x0, $y1 - $raio) . " c h f Q\n");
    }

    public function linha(float $x1, float $y1, float $x2, float $y2, array $cor, float $espessura = 0.75, ?array $tracejado = null): void
    {
        $this->polilinha([[$x1, $y1], [$x2, $y2]], $cor, $espessura, $tracejado);
    }

    /** @param list<array{0:float,1:float}> $pontos */
    public function polilinha(array $pontos, array $cor, float $espessura = 1.5, ?array $tracejado = null): void
    {
        if (count($pontos) < 2) {
            return;
        }
        $d = $tracejado !== null ? '[' . implode(' ', array_map(self::n(...), $tracejado)) . '] 0 d ' : '';
        $c = 'q ' . self::rgb($cor, true) . ' ' . self::n($espessura) . " w 1 J 1 j $d";
        foreach ($pontos as $i => [$x, $y]) {
            $c .= self::n($x) . ' ' . self::n(self::ALTURA - $y) . ($i === 0 ? ' m ' : ' l ');
        }
        $this->emitir($c . "S Q\n");
    }

    /** @param list<array{0:float,1:float}> $pontos */
    public function poligono(array $pontos, array $preenche): void
    {
        $c = $this->estilo($preenche, null, 0.75);
        foreach ($pontos as $i => [$x, $y]) {
            $c .= self::n($x) . ' ' . self::n(self::ALTURA - $y) . ($i === 0 ? ' m ' : ' l ');
        }
        $this->emitir($c . "h f Q\n");
    }

    public function circulo(float $cx, float $cy, float $r, ?array $preenche, ?array $traco = null, float $espessura = 1.0): void
    {
        $this->emitir($this->estilo($preenche, $traco, $espessura) . $this->caminhoRet($cx - $r, $cy - $r, 2 * $r, 2 * $r, $r) . self::pintura($preenche, $traco) . "Q\n");
    }

    /** Listras diagonais dentro de um retângulo: previsão se distingue do real sem depender da cor. */
    public function hachura(float $x, float $y, float $l, float $a, array $cor, float $passo = 3.2, float $espessura = 0.7): void
    {
        $c = 'q ' . $this->caminhoRet($x, $y, $l, $a, 0) . 'W n ' . self::rgb($cor, true) . ' ' . self::n($espessura) . " w\n";
        for ($d = -$a; $d < $l; $d += $passo) {
            $c .= self::n($x + $d) . ' ' . self::n(self::ALTURA - $y - $a) . ' m ' . self::n($x + $d + $a) . ' ' . self::n(self::ALTURA - $y) . " l S\n";
        }
        $this->emitir($c . "Q\n");
    }

    /* ---------------------------------------------------------------- imagens */

    /** Põe uma imagem, se o formato for um dos que dá para embutir sem GD. @return bool se entrou */
    public function imagem(string $dados, float $x, float $y, float $l, float $a): bool
    {
        $im = self::lerImagem($dados);
        if ($im === null) {
            return false;
        }
        $this->imagens[] = $im;
        $this->emitir(sprintf("q %s 0 0 %s %s %s cm /I%d Do Q\n", self::n($l), self::n($a), self::n($x), self::n(self::ALTURA - $y - $a), count($this->imagens)));
        return true;
    }

    /** Proporção largura / altura da imagem; null se não der para ler. */
    public static function proporcao(string $dados): ?float
    {
        $i = @getimagesizefromstring($dados);
        return $i === false || $i[1] < 1 ? null : $i[0] / $i[1];
    }

    private static function lerImagem(string $dados): ?array
    {
        $i = @getimagesizefromstring($dados);
        if ($i === false) {
            return null;
        }
        if ($i[2] === IMAGETYPE_JPEG) {
            $canais = (int) ($i['channels'] ?? 3);
            return ['tipo' => 'jpg', 'l' => $i[0], 'a' => $i[1], 'dados' => $dados, 'filtro' => 'DCTDecode',
                    'cor' => $canais === 1 ? '/DeviceGray' : ($canais === 4 ? '/DeviceCMYK' : '/DeviceRGB'), 'bits' => 8, 'parms' => '', 'smask' => null, 'paleta' => null];
        }
        return $i[2] === IMAGETYPE_PNG ? self::lerPng($dados) : null;
    }

    /** PNG de 8 bits, sem entrelaçar. O alfa vira uma máscara separada, como o PDF pede. Fora disso, null. */
    private static function lerPng(string $d): ?array
    {
        if (substr($d, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return null;
        }
        $p = 8;
        $idat = '';
        $plte = null;
        $trns = null;
        $l = $a = $bits = $tipoCor = $entrelacado = 0;
        while ($p + 8 <= strlen($d)) {
            $n = unpack('N', substr($d, $p, 4))[1];
            $tipo = substr($d, $p + 4, 4);
            $bloco = substr($d, $p + 8, $n);
            if ($tipo === 'IHDR') {
                [$l, $a] = array_values(unpack('N2', substr($bloco, 0, 8)));
                $bits = ord($bloco[8]);
                $tipoCor = ord($bloco[9]);
                $entrelacado = ord($bloco[12]);
            } elseif ($tipo === 'PLTE') {
                $plte = $bloco;
            } elseif ($tipo === 'tRNS') {
                $trns = $bloco;
            } elseif ($tipo === 'IDAT') {
                $idat .= $bloco;
            } elseif ($tipo === 'IEND') {
                break;
            }
            $p += 12 + $n;
        }
        if ($bits !== 8 || $entrelacado !== 0 || $idat === '' || $l < 1 || $a < 1) {
            return null;
        }
        $canais = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$tipoCor] ?? 0;
        if ($canais === 0 || ($tipoCor === 3 && $plte === null)) {
            return null;
        }
        $cor = $tipoCor === 3 ? 'paleta' : (in_array($tipoCor, [0, 4], true) ? '/DeviceGray' : '/DeviceRGB');
        $base = ['tipo' => 'png', 'l' => $l, 'a' => $a, 'filtro' => 'FlateDecode', 'cor' => $cor, 'bits' => 8, 'paleta' => $tipoCor === 3 ? $plte : null];
        $colunas = in_array($tipoCor, [0, 3], true) ? 1 : ($tipoCor === 2 ? 3 : $canais);
        if (in_array($tipoCor, [0, 2], true) || ($tipoCor === 3 && $trns === null)) {
            return $base + ['dados' => $idat, 'parms' => "/DecodeParms << /Predictor 15 /Colors $colunas /BitsPerComponent 8 /Columns $l >> ", 'smask' => null];
        }
        // com transparência: desfaz os filtros do PNG e separa cor e alfa
        $bruto = @gzuncompress($idat);
        if ($bruto === false) {
            return null;
        }
        $porLinha = $l * $canais;
        if (strlen($bruto) < ($porLinha + 1) * $a) {
            return null;
        }
        $cores = '';
        $alfa = '';
        $anterior = str_repeat("\0", $porLinha);
        $mapaT = $tipoCor === 3 ? (string) $trns : '';
        for ($y = 0; $y < $a; $y++) {
            $filtro = ord($bruto[$y * ($porLinha + 1)]);
            $linha = substr($bruto, $y * ($porLinha + 1) + 1, $porLinha);
            $linha = self::desfiltrar($linha, $anterior, $filtro, $canais);
            $anterior = $linha;
            if ($tipoCor === 3) {
                $cores .= $linha;
                for ($i = 0; $i < $l; $i++) {
                    $idx = ord($linha[$i]);
                    $alfa .= $idx < strlen($mapaT) ? $mapaT[$idx] : "\xFF";
                }
                continue;
            }
            $corPx = $tipoCor === 6 ? 3 : 1;
            for ($i = 0; $i < $l; $i++) {
                $cores .= substr($linha, $i * $canais, $corPx);
                $alfa .= $linha[$i * $canais + $corPx];
            }
        }
        return $base + ['dados' => (string) gzcompress($cores, 9), 'parms' => '', 'smask' => (string) gzcompress($alfa, 9)];
    }

    private static function desfiltrar(string $linha, string $acima, int $filtro, int $bpp): string
    {
        if ($filtro === 0) {
            return $linha;
        }
        $n = strlen($linha);
        for ($i = 0; $i < $n; $i++) {
            $e = $i >= $bpp ? ord($linha[$i - $bpp]) : 0;
            $c = ord($acima[$i]);
            $ec = $i >= $bpp ? ord($acima[$i - $bpp]) : 0;
            $v = ord($linha[$i]);
            $linha[$i] = chr(match ($filtro) {
                1 => $v + $e,
                2 => $v + $c,
                3 => $v + intdiv($e + $c, 2),
                default => $v + self::paeth($e, $c, $ec),
            } & 0xFF);
        }
        return $linha;
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        [$pa, $pb, $pc] = [abs($p - $a), abs($p - $b), abs($p - $c)];
        return $pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c);
    }

    /* ---------------------------------------------------------------- arquivo */

    public function saida(): string
    {
        $obj = [];                                               // número - 1 => corpo
        $novo = static function (string $corpo = '') use (&$obj): int {
            $obj[] = $corpo;
            return count($obj);
        };
        $catalogo = $novo();
        $arvore = $novo();
        $fontes = '';
        foreach ($this->fontes as $chave => $f) {
            $idF = $this->escreverFonte($chave, $f, $novo);
            $fontes .= '/F' . $f['n'] . ' ' . $idF . ' 0 R ';
        }
        $xobj = '';
        foreach ($this->imagens as $i => $im) {
            $xobj .= '/I' . ($i + 1) . ' ' . $this->escreverImagem($im, $novo) . ' 0 R ';
        }
        $recursos = $novo('<< /ProcSet [/PDF /Text /ImageC /ImageB /ImageI] /Font << ' . $fontes . '>> /XObject << ' . $xobj . '>> >>');
        $filhos = '';
        foreach ($this->paginas as $conteudo) {
            $comp = (string) gzcompress($conteudo, 9);
            $idC = $novo('<< /Length ' . strlen($comp) . " /Filter /FlateDecode >>\nstream\n" . $comp . "\nendstream");
            $idP = $novo('<< /Type /Page /Parent ' . $arvore . ' 0 R /MediaBox [0 0 ' . self::n(self::LARGURA) . ' ' . self::n(self::ALTURA) . '] /Resources ' . $recursos . ' 0 R /Contents ' . $idC . ' 0 R >>');
            $filhos .= $idP . ' 0 R ';
        }
        $obj[$arvore - 1] = '<< /Type /Pages /Kids [' . $filhos . '] /Count ' . count($this->paginas) . ' >>';
        $obj[$catalogo - 1] = '<< /Type /Catalog /Pages ' . $arvore . ' 0 R /Lang ' . self::textoPdf($this->idioma) . ' /ViewerPreferences << /DisplayDocTitle true >> >>';
        $data = $this->data !== '' ? 'D:' . $this->data : '';
        $info = $novo('<< /Title ' . self::textoPdf($this->titulo) . ' /Producer (financeiro)' . ($data !== '' ? ' /CreationDate ' . self::textoPdf($data) : '') . ' >>');

        $pdf = "%PDF-1.5\n%\xE2\xE3\xCF\xD3\n";
        $posicoes = [];
        foreach ($obj as $i => $corpo) {
            $posicoes[$i] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $corpo . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . (count($obj) + 1) . "\n0000000000 65535 f \n";
        foreach ($posicoes as $p) {
            $pdf .= sprintf("%010d 00000 n \n", $p);
        }
        $id = md5($pdf);
        return $pdf . 'trailer' . "\n<< /Size " . (count($obj) + 1) . ' /Root ' . $catalogo . ' 0 R /Info ' . $info . " 0 R /ID [<$id> <$id>] >>\nstartxref\n$xref\n%%EOF\n";
    }

    private function escreverFonte(string $chave, array $f, callable $novo): int
    {
        $ttf = (string) file_get_contents(__DIR__ . '/fontes/' . $chave . '.ttf');
        $comp = (string) gzcompress($ttf, 9);
        $idArq = $novo('<< /Length ' . strlen($comp) . ' /Length1 ' . strlen($ttf) . " /Filter /FlateDecode >>\nstream\n" . $comp . "\nendstream");
        $d = $f['dados'];
        $nome = '/' . $d['nome'];
        $idDesc = $novo('<< /Type /FontDescriptor /FontName ' . $nome . ' /Flags 32 /FontBBox [' . implode(' ', $d['bbox']) . '] /ItalicAngle 0 /Ascent ' . $d['ascent'] . ' /Descent ' . $d['descent'] . ' /CapHeight ' . $d['cap'] . ' /StemV 80 /FontFile2 ' . $idArq . ' 0 R >>');
        ksort($f['usados']);
        $larguras = '';
        $cmap = '';
        $blocos = [];
        foreach ($f['usados'] as $gid => [$cp, $l]) {
            $larguras .= $gid . ' [' . $l . '] ';
            $blocos[] = sprintf('<%04X> <%s>', $gid, strtoupper(bin2hex((string) mb_convert_encoding(mb_chr($cp, 'UTF-8'), 'UTF-16BE', 'UTF-8'))));
        }
        foreach (array_chunk($blocos, 100) as $parte) {
            $cmap .= count($parte) . " beginbfchar\n" . implode("\n", $parte) . "\nendbfchar\n";
        }
        $cm = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n" . $cmap . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
        $cmc = (string) gzcompress($cm, 9);
        $idCm = $novo('<< /Length ' . strlen($cmc) . " /Filter /FlateDecode >>\nstream\n" . $cmc . "\nendstream");
        $idCid = $novo('<< /Type /Font /Subtype /CIDFontType2 /BaseFont ' . $nome . ' /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor ' . $idDesc . ' 0 R /CIDToGIDMap /Identity /DW 600 /W [' . $larguras . '] >>');
        return $novo('<< /Type /Font /Subtype /Type0 /BaseFont ' . $nome . ' /Encoding /Identity-H /DescendantFonts [' . $idCid . ' 0 R] /ToUnicode ' . $idCm . ' 0 R >>');
    }

    private function escreverImagem(array $im, callable $novo): int
    {
        $mascara = '';
        if ($im['smask'] !== null) {
            $idM = $novo('<< /Type /XObject /Subtype /Image /Width ' . $im['l'] . ' /Height ' . $im['a'] . ' /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length ' . strlen($im['smask']) . " >>\nstream\n" . $im['smask'] . "\nendstream");
            $mascara = '/SMask ' . $idM . ' 0 R ';
        }
        $cor = $im['cor'];
        if ($cor === 'paleta') {
            $cor = '[/Indexed /DeviceRGB ' . (intdiv(strlen((string) $im['paleta']), 3) - 1) . ' <' . bin2hex((string) $im['paleta']) . '>]';
        }
        return $novo('<< /Type /XObject /Subtype /Image /Width ' . $im['l'] . ' /Height ' . $im['a'] . ' /ColorSpace ' . $cor . ' /BitsPerComponent ' . $im['bits'] . ' /Filter /' . $im['filtro'] . ' ' . $im['parms'] . $mascara . '/Length ' . strlen($im['dados']) . " >>\nstream\n" . $im['dados'] . "\nendstream");
    }

    /* ---------------------------------------------------------------- miúdos */

    private function emitir(string $c): void
    {
        if ($this->atual < 0) {
            $this->novaPagina();
        }
        $this->paginas[$this->atual] .= $c;
    }

    private function estilo(?array $preenche, ?array $traco, float $espessura): string
    {
        return 'q ' . ($preenche !== null ? self::rgb($preenche) . ' ' : '') . ($traco !== null ? self::rgb($traco, true) . ' ' . self::n($espessura) . ' w ' : '');
    }

    private static function pintura(?array $preenche, ?array $traco): string
    {
        return $preenche !== null && $traco !== null ? "B " : ($preenche !== null ? "f " : "S ");
    }

    private function caminhoRet(float $x, float $y, float $l, float $a, float $raio): string
    {
        $y0 = self::ALTURA - $y - $a;
        if ($raio <= 0.0) {
            return self::n($x) . ' ' . self::n($y0) . ' ' . self::n($l) . ' ' . self::n($a) . " re ";
        }
        $r = min($raio, $l / 2, $a / 2);
        $k = 0.5523 * $r;
        [$x1, $y1] = [$x + $l, $y0 + $a];
        $p = static fn(float ...$v): string => implode(' ', array_map(self::n(...), $v));
        return $p($x + $r, $y0) . ' m ' . $p($x1 - $r, $y0) . ' l ' . $p($x1 - $r + $k, $y0, $x1, $y0 + $r - $k, $x1, $y0 + $r) . ' c '
            . $p($x1, $y1 - $r) . ' l ' . $p($x1, $y1 - $r + $k, $x1 - $r + $k, $y1, $x1 - $r, $y1) . ' c '
            . $p($x + $r, $y1) . ' l ' . $p($x + $r - $k, $y1, $x, $y1 - $r + $k, $x, $y1 - $r) . ' c '
            . $p($x, $y0 + $r) . ' l ' . $p($x, $y0 + $r - $k, $x + $r - $k, $y0, $x + $r, $y0) . ' c h ';
    }

    private static function rgb(array $c, bool $traco = false): string
    {
        return self::n((float) $c[0]) . ' ' . self::n((float) $c[1]) . ' ' . self::n((float) $c[2]) . ($traco ? ' RG' : ' rg');
    }

    private static function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') ?: '0';
    }

    private static function textoPdf(string $s): string
    {
        return '<FEFF' . strtoupper(bin2hex((string) mb_convert_encoding($s, 'UTF-16BE', 'UTF-8'))) . '>';
    }
}
