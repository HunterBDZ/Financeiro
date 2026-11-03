<?php
namespace Financeiro\Services;

/**
 * Lê o arquivo de extrato que a pessoa enviou: OFX/QFX (o SGML da versão 1 e o XML da 2) ou CSV. Só funções puras: o
 * arquivo chega como texto, nada é gravado em disco, e o que não dá para ler vira \InvalidArgumentException com a
 * frase para a tela.
 *
 * Segurança: limite de tamanho e de linhas; o OFX é lido por expressões regulares, sem parser de XML, então não há
 * entidade nem DTD para expandir, e um arquivo com <!DOCTYPE ou <!ENTITY é recusado; binário (NUL, ZIP, PDF) também.
 * Charset: o do cabeçalho do OFX (CHARSET:1252, encoding="…"); no CSV, UTF-8 (com ou sem BOM), UTF-16 com BOM ou
 * Windows-1252. Um OFX que diz 1252 mas é UTF-8 válido com acentos é lido como UTF-8, que é o que ele é.
 *
 * Uma linha lida: ['data' => AAAA-MM-DD, 'valor' => float com sinal (− saiu, + entrou), 'descricao' => string,
 * 'fitid' => string ('' sem), 'categoria' => string (só do CSV com coluna de categoria)]; no CSV, 'duvida' => true
 * quando o valor foge do padrão da coluna (noPadrao()), e a revisão traz a linha desmarcada.
 */
final class ExtratoArquivo
{
    /** Tamanho máximo do arquivo: um ano de extrato fica bem abaixo disso. */
    public const MAX_BYTES = 2 * 1024 * 1024;
    /** Linhas de lançamento por arquivo. */
    public const MAX_LINHAS = 2000;
    /** Colunas por linha do CSV: mais que isso não é extrato. */
    private const MAX_COLUNAS = 60;

    /**
     * O arquivo inteiro: formato e conteúdo.
     * @return array{formato:'ofx', linhas:list<array>, ignoradas:int}|array{formato:'csv', sep:string, linhas:list<list<string>>}
     */
    public static function ler(string $bruto): array
    {
        if (strlen($bruto) > self::MAX_BYTES) {
            throw new \InvalidArgumentException(t('O arquivo passa de {limite}. Baixe um período menor.', ['limite' => '2 MB']));
        }
        if (trim($bruto) === '') {
            throw new \InvalidArgumentException(t('O arquivo está vazio.'));
        }
        if (str_starts_with($bruto, "PK\x03\x04")) {
            throw new \InvalidArgumentException(t('Esse arquivo é uma planilha do Excel. Salve como CSV e envie de novo.'));
        }
        if (str_starts_with($bruto, '%PDF')) {
            throw new \InvalidArgumentException(t('PDF não dá para ler. Baixe o extrato em OFX ou CSV.'));
        }
        $texto = self::texto($bruto);
        if (preg_match('/<!(DOCTYPE|ENTITY)/i', $texto)) {
            throw new \InvalidArgumentException(t('Esse arquivo tem partes que o app não lê, por segurança.'));
        }
        if (preg_match('/OFXHEADER|<OFX>/i', substr($texto, 0, 4000))) {
            return ['formato' => 'ofx'] + self::ofx($texto);
        }
        [$sep, $linhas] = self::csv($texto);
        return ['formato' => 'csv', 'sep' => $sep, 'linhas' => $linhas];
    }

    /* ================================================================== charset */

    /** Os bytes em UTF-8, pelo cabeçalho do OFX, pelo BOM ou pelo que eles são. Binário é recusado. */
    public static function texto(string $bruto): string
    {
        if (str_starts_with($bruto, "\xFF\xFE") || str_starts_with($bruto, "\xFE\xFF")) {
            $s = (string) mb_convert_encoding(substr($bruto, 2), 'UTF-8', str_starts_with($bruto, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        } else {
            $s = str_starts_with($bruto, "\xEF\xBB\xBF") ? substr($bruto, 3) : $bruto;
            $declarado = self::charsetDeclarado($s);
            $utf8 = mb_check_encoding($s, 'UTF-8');
            if ($declarado === 'utf-8' && !$utf8) {
                $declarado = '1252';
            }
            if ($declarado === '1252' && $utf8 && preg_match('/[\x80-\xFF]/', $s)) {
                $declarado = 'utf-8';                // diz 1252, mas os acentos estão em UTF-8 válido
            }
            if ($declarado === '1252' || ($declarado === null && !$utf8)) {
                $s = (string) mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
            }
        }
        // NUL ou muito caractere de controle: não é texto
        if (str_contains($s, "\0") || preg_match_all('/[\x01-\x08\x0E-\x1F\x7F]/', substr($s, 0, 65536)) > 20) {
            throw new \InvalidArgumentException(t('Esse arquivo não é um extrato em OFX ou CSV.'));
        }
        return str_replace(["\r\n", "\r"], "\n", $s);
    }

    /** O charset que o OFX declara: 'utf-8', '1252' ou null (não diz, ou diz só ASCII). */
    private static function charsetDeclarado(string $s): ?string
    {
        $topo = substr($s, 0, 1000);
        if (preg_match('/<\?xml[^>]*encoding\s*=\s*["\']([\w-]+)["\']/i', $topo, $m)) {
            $enc = strtolower($m[1]);
            return in_array($enc, ['utf-8', 'utf8'], true) ? 'utf-8' : (preg_match('/1252|8859-1|latin/', $enc) ? '1252' : null);
        }
        if (preg_match('/^ENCODING:\s*(\S+)/mi', $topo, $m) && preg_match('/UTF-?8/i', $m[1])) {
            return 'utf-8';
        }
        if (preg_match('/^CHARSET:\s*(\S+)/mi', $topo, $m) && preg_match('/1252|8859|LATIN/i', $m[1])) {
            return '1252';
        }
        return null;
    }

    /* ================================================================== OFX */

    /**
     * Os STMTTRN do OFX: DTPOSTED, TRNAMT, FITID, NAME e MEMO. Serve ao SGML (folhas sem fechar) e ao XML.
     * Lançamento sem data ou sem valor legível fica de fora e é contado, como no CSV.
     * @return array{linhas:list<array{data:string, valor:float, descricao:string, fitid:string, categoria:string}>, ignoradas:int}
     */
    public static function ofx(string $texto): array
    {
        if (preg_match_all('/<STMTTRN>(.*?)(?=<\/STMTTRN>|<STMTTRN>|<\/BANKTRANLIST>|<\/OFX>|$)/is', $texto, $blocos) === false) {
            throw new \InvalidArgumentException(t('Não deu para ler esse OFX.'));
        }
        if (count($blocos[1]) > self::MAX_LINHAS) {
            throw new \InvalidArgumentException(t('O arquivo tem mais de {n} lançamentos. Baixe um período menor.', ['n' => num(self::MAX_LINHAS, 0)]));
        }
        $out = [];
        $ignoradas = 0;
        foreach ($blocos[1] as $b) {
            $campo = static function (string $tag) use ($b): string {
                return preg_match('/<' . $tag . '>([^<]*)/i', $b, $m) ? self::entidades(trim($m[1])) : '';
            };
            $dt = $campo('DTPOSTED');
            $data = preg_match('/^(\d{4})(\d{2})(\d{2})/', $dt, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
            $valor = self::numero($campo('TRNAMT'), null);
            if ($data === null || $valor === null) {
                $ignoradas++;
                continue;
            }
            $out[] = ['data' => $data, 'valor' => $valor, 'descricao' => self::juntarNomeMemo($campo('NAME'), $campo('MEMO')),
                      'fitid' => mb_substr($campo('FITID'), 0, 255), 'categoria' => ''];
        }
        if ($out === []) {
            throw new \InvalidArgumentException(t('Esse OFX não tem nenhum lançamento.'));
        }
        return ['linhas' => $out, 'ignoradas' => $ignoradas];
    }

    /** NAME costuma vir cortado, e MEMO completo: fica o que contém o outro, ou os dois. */
    private static function juntarNomeMemo(string $nome, string $memo): string
    {
        $nome = self::limpar($nome);
        $memo = self::limpar($memo);
        if ($nome === '' || $memo === '' || mb_stripos($memo, $nome) !== false) {
            return $memo !== '' ? $memo : $nome;
        }
        return mb_stripos($nome, $memo) !== false ? $nome : mb_substr($nome . ' - ' . $memo, 0, 255);
    }

    /** As cinco entidades do XML e as numéricas; nenhuma outra existe aqui. */
    private static function entidades(string $s): string
    {
        $s = (string) preg_replace_callback('/&#(x[0-9a-f]{1,6}|\d{1,7});/i', static function (array $m): string {
            $n = $m[1][0] === 'x' || $m[1][0] === 'X' ? hexdec(substr($m[1], 1)) : (int) $m[1];
            return $n > 31 && $n < 0x110000 ? (string) mb_chr((int) $n, 'UTF-8') : ' ';
        }, $s);
        return strtr($s, ['&lt;' => '<', '&gt;' => '>', '&quot;' => '"', '&apos;' => "'", '&amp;' => '&']);
    }

    /** Sem caractere de controle e sem espaço repetido, até 255 letras. */
    public static function limpar(string $s): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F\x{A0}\s]+/u', ' ', $s)), 0, 255);
    }

    /* ================================================================== CSV */

    /**
     * O separador e as linhas do CSV (aspas e quebra de linha dentro de aspas valem). Linhas vazias saem.
     * @return array{0:string, 1:list<list<string>>}
     */
    public static function csv(string $texto): array
    {
        $fisicas = explode("\n", $texto);
        if (count($fisicas) > self::MAX_LINHAS + 50) {
            throw new \InvalidArgumentException(t('O arquivo tem mais de {n} lançamentos. Baixe um período menor.', ['n' => num(self::MAX_LINHAS, 0)]));
        }
        $sep = self::separador($fisicas);
        foreach ($fisicas as $f) {
            if (substr_count($f, $sep) > self::MAX_COLUNAS * 4) {
                throw new \InvalidArgumentException(t('Esse arquivo não é um extrato em OFX ou CSV.'));
            }
        }
        $h = fopen('php://memory', 'w+b');
        fwrite($h, $texto);
        rewind($h);
        $linhas = [];
        while (($r = fgetcsv($h, null, $sep, '"', '')) !== false) {
            $r = array_map(static fn($c): string => trim((string) $c), $r);
            if (implode('', $r) === '') {
                continue;
            }
            if (count($r) > self::MAX_COLUNAS) {
                fclose($h);
                throw new \InvalidArgumentException(t('Esse arquivo não é um extrato em OFX ou CSV.'));
            }
            $linhas[] = $r;
        }
        fclose($h);
        if (count($linhas) < 1) {
            throw new \InvalidArgumentException(t('O arquivo está vazio.'));
        }
        // a linha mais curta ganha colunas vazias: o mapa vale para todas
        $n = max(array_map('count', $linhas));
        foreach ($linhas as &$r) {
            $r = array_pad($r, $n, '');
        }
        unset($r);
        return [$sep, $linhas];
    }

    /** ; , tab ou |: o que divide as primeiras linhas no mesmo número de colunas, com mais colunas. */
    public static function separador(array $fisicas): string
    {
        $amostra = array_slice(array_values(array_filter($fisicas, static fn($l): bool => trim((string) $l) !== '')), 0, 25);
        $melhor = ';';
        $nota = -1.0;
        foreach ([';', ',', "\t", '|'] as $sep) {
            $contagens = array_map(static fn(string $l): int => count(str_getcsv($l, $sep, '"', '')), $amostra);
            if ($contagens === [] || max($contagens) < 2) {
                continue;
            }
            $freq = array_count_values($contagens);
            arsort($freq);
            $moda = (int) array_key_first($freq);
            $iguais = $freq[$moda] / count($contagens);
            $n = $iguais * 100 + min($moda, 20);
            if ($moda >= 2 && $n > $nota) {
                [$melhor, $nota] = [$sep, $n];
            }
        }
        return $melhor;
    }

    /* ================================================================== números e datas */

    /**
     * Um valor de extrato em número: "1.234,56" → 1234.56, "-45.90", "(12,00)" e "12,00-" e "12,00 D" → negativos,
     * "R$ 7,50" → 7.5. $decimal é o separador decimal do arquivo (',' ou '.'); null = um separador só é o decimal.
     * Com os dois, o da direita é o decimal. null quando não é número.
     */
    public static function numero(string $s, ?string $decimal): ?float
    {
        $s = trim(str_replace(["\u{A0}", "\u{202F}", ' '], '', $s));
        $neg = false;
        if (preg_match('/^\((.*)\)$/', $s, $m)) {
            [$s, $neg] = [$m[1], true];
        }
        if (preg_match('/^(.*?)([DC])$/i', $s, $m) && $m[1] !== '' && preg_match('/\d$/', $m[1])) {
            [$s, $neg] = [$m[1], $neg || strtoupper($m[2]) === 'D'];
        }
        $s = (string) preg_replace('/^(R\$|US\$|\$|€|£|BRL|USD|EUR)/i', '', $s);
        if (preg_match('/^[-−–](.*)$/u', $s, $m)) {
            [$s, $neg] = [$m[1], !$neg];
        } elseif (preg_match('/^(.*)[-−–]$/u', $s, $m)) {
            [$s, $neg] = [$m[1], !$neg];
        } elseif (str_starts_with($s, '+')) {
            $s = substr($s, 1);
        }
        $s = (string) preg_replace('/^(R\$|US\$|\$|€|£)/i', '', $s);
        if (!preg_match('/^\d[\d.,]*$/', $s) || preg_match('/[.,]$/', $s)) {
            return null;
        }
        $temPonto = str_contains($s, '.');
        $temVirgula = str_contains($s, ',');
        if ($temPonto && $temVirgula) {
            $dec = strrpos($s, ',') > strrpos($s, '.') ? ',' : '.';
            $s = str_replace($dec === ',' ? '.' : ',', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif ($temPonto || $temVirgula) {
            $sp = $temPonto ? '.' : ',';
            $uma = substr_count($s, $sp) === 1;
            $ehDecimal = $uma && ($decimal === null ? true : $decimal === $sp);
            $s = $ehDecimal ? str_replace(',', '.', $s) : str_replace($sp, '', $s);
        }
        if (!is_numeric($s)) {
            return null;
        }
        $v = round((float) $s, 2);
        return $neg ? -$v : $v;
    }

    /**
     * O separador decimal de uma coluna de valores: o que aparece com 1 ou 2 casas no fim, ou o da direita quando há
     * os dois. Sem pista, o comum para o separador de campos (";" → vírgula; "," → ponto).
     * @param list<string> $valores
     */
    public static function decimal(array $valores, string $sep = ';'): string
    {
        $votos = [',' => 0, '.' => 0];
        foreach ($valores as $v) {
            $v = trim($v);
            if (preg_match('/\d[.,]\d{1,2}(?!\d)/', $v) && !(str_contains($v, '.') && str_contains($v, ','))) {
                $votos[str_contains($v, ',') ? ',' : '.']++;
            } elseif (str_contains($v, '.') && str_contains($v, ',')) {
                $votos[strrpos($v, ',') > strrpos($v, '.') ? ',' : '.']++;
            }
        }
        if ($votos[','] !== $votos['.']) {
            return $votos[','] > $votos['.'] ? ',' : '.';
        }
        return $sep === ',' ? '.' : ',';
    }

    /**
     * O valor segue o padrão da coluna: o separador de milhar (o que não é o decimal) só em grupos de 3 dígitos, e no
     * máximo 2 casas depois do decimal. "1234.56" numa coluna de vírgula decimal, "1,234" (milhar ou decimal?) e
     * "1,234.56" ali fogem do padrão: o app não adivinha, e a linha vem desmarcada com "Confira o valor".
     */
    public static function noPadrao(string $s, string $decimal): bool
    {
        if (!preg_match('/\d[\d.,]*/', str_replace(["\u{A0}", "\u{202F}", ' '], '', $s), $m)) {
            return true;                             // sem número: numero() é que recusa
        }
        $d = preg_quote($decimal, '/');
        $mil = preg_quote($decimal === ',' ? '.' : ',', '/');
        return (bool) preg_match('/^(\d{1,3}(' . $mil . '\d{3})+|\d+)(' . $d . '\d{1,2})?$/D', rtrim($m[0], '.,'));
    }

    /**
     * Uma data: dd/mm/aaaa, dd/mm/aa, dd-mm-aaaa, dd.mm.aaaa, aaaa-mm-dd, aaaa/mm/dd ou aaaammdd, com ou sem hora.
     * $ordem 'mdy' lê 03/10/2026 como 10 de março. null quando não é data.
     */
    public static function data(string $s, string $ordem = 'dmy'): ?string
    {
        $s = trim((string) preg_replace('/[T\s].*$/', '', trim($s)));
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/D', $s, $m) || preg_match('/^(\d{4})(\d{2})(\d{2})$/D', $s, $m)) {
            [$a, $me, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4}|\d{2})$/D', $s, $m)) {
            [$d, $me] = $ordem === 'mdy' ? [(int) $m[2], (int) $m[1]] : [(int) $m[1], (int) $m[2]];
            $a = (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3];
        } else {
            return null;
        }
        return $a >= 1990 && $a <= 2100 && checkdate($me, $d, $a) ? sprintf('%04d-%02d-%02d', $a, $me, $d) : null;
    }

    /** dd/mm ou mm/dd numa coluna de datas: o lado que passa de 12 é o dia. Sem pista, dd/mm. @param list<string> $valores */
    public static function ordemDatas(array $valores): string
    {
        foreach ($valores as $v) {
            if (preg_match('/^\s*(\d{1,2})[-\/.](\d{1,2})[-\/.]\d{2,4}/', $v, $m)) {
                if ((int) $m[1] > 12) {
                    return 'dmy';
                }
                if ((int) $m[2] > 12) {
                    return 'mdy';
                }
            }
        }
        return 'dmy';
    }

    /* ================================================================== colunas do CSV */

    /** A primeira linha é cabeçalho quando nenhuma célula dela é data nem valor, e alguma tem letra. */
    public static function temCabecalho(array $linhas): bool
    {
        $primeira = $linhas[0] ?? [];
        foreach ($primeira as $c) {
            if (self::data($c) !== null || (preg_match('/\d/', $c) && self::numero($c, null) !== null)) {
                return false;
            }
        }
        return (bool) preg_match('/\p{L}/u', implode('', $primeira));
    }

    /** O "layout" do CSV, para lembrar as colunas: o hash do cabeçalho (ou, sem ele, do número de colunas). */
    public static function layout(array $linhas, string $sep, bool $cabecalho): string
    {
        $base = $cabecalho
            ? 'cab:' . $sep . ':' . implode('|', array_map(static fn(string $c): string => \Financeiro\Colacao::dobrar(trim($c)), $linhas[0] ?? []))
            : 'sem:' . $sep . ':' . count($linhas[0] ?? []);
        return hash('sha256', $base);
    }

    /**
     * As colunas que parecem ser data, descrição, valor (ou débito e crédito) e categoria: pelo nome do cabeçalho e,
     * sem ele, pelo conteúdo. -1 = nenhuma.
     * @return array{data:int, descricao:int, valor:int, debito:int, credito:int, categoria:int, cabecalho:bool}
     */
    public static function mapaSugerido(array $linhas): array
    {
        $cab = self::temCabecalho($linhas);
        $n = count($linhas[0] ?? []);
        $mapa = ['data' => -1, 'descricao' => -1, 'valor' => -1, 'debito' => -1, 'credito' => -1, 'categoria' => -1, 'cabecalho' => $cab];
        if ($cab) {
            $padroes = [
                'data'      => '/^(data|date|fecha|dt|dia)\b|data (do|da|de) (lanc|mov|compra|transa)/',
                'debito'    => '/debito|debit|saida|retirada|cargo/',
                'credito'   => '/credito|credit|entrada|deposito|abono/',
                'valor'     => '/valor|value|amount|importe|quantia|montante|monto/',
                'descricao' => '/descri|historico|lancamento|estabelecimento|memo|detalh|concepto|titulo|nome|name|payee|beneficiario/',
                'categoria' => '/categor/',
            ];
            foreach ($linhas[0] as $i => $c) {
                $norm = \Financeiro\Colacao::dobrar($c);
                if (preg_match('/saldo|balance/', $norm)) {
                    continue;                        // a coluna de saldo não é o valor do lançamento
                }
                foreach ($padroes as $papel => $re) {
                    if ($mapa[$papel] === -1 && preg_match($re, $norm)) {
                        $mapa[$papel] = $i;
                        break;
                    }
                }
            }
        }
        if ($mapa['valor'] >= 0) {
            [$mapa['debito'], $mapa['credito']] = [-1, -1];   // com a coluna de valor, débito e crédito sobram
        }
        // pelo conteúdo, o que o nome não achou
        $dados = array_slice($linhas, $cab ? 1 : 0, 40);
        $usadas = array_filter([$mapa['data'], $mapa['descricao'], $mapa['valor'], $mapa['debito'], $mapa['credito'], $mapa['categoria']], static fn($i) => $i >= 0);
        $proporcao = static function (int $i, callable $ok) use ($dados): float {
            $cheias = array_filter(array_column($dados, $i), static fn($c): bool => trim((string) $c) !== '');
            return $cheias === [] ? 0.0 : count(array_filter($cheias, $ok)) / count($cheias);
        };
        if ($mapa['data'] === -1) {
            for ($i = 0; $i < $n; $i++) {
                if (!in_array($i, $usadas, true) && $proporcao($i, static fn($c) => self::data((string) $c) !== null) >= 0.8) {
                    $mapa['data'] = $usadas[] = $i;
                    break;
                }
            }
        }
        if ($mapa['valor'] === -1 && ($mapa['debito'] === -1 || $mapa['credito'] === -1)) {
            [$mapa['debito'], $mapa['credito']] = [-1, -1];
            for ($i = 0; $i < $n; $i++) {
                if (!in_array($i, $usadas, true) && $proporcao($i, static fn($c) => preg_match('/\d/', (string) $c) && self::numero((string) $c, null) !== null) >= 0.8) {
                    $mapa['valor'] = $usadas[] = $i;
                    break;
                }
            }
        }
        if ($mapa['descricao'] === -1) {
            $maior = 0.0;
            for ($i = 0; $i < $n; $i++) {
                if (in_array($i, $usadas, true)) {
                    continue;
                }
                $media = $dados === [] ? 0 : array_sum(array_map(static fn($r) => preg_match_all('/\p{L}/u', (string) ($r[$i] ?? '')), $dados)) / count($dados);
                if ($media > $maior) {
                    [$maior, $mapa['descricao']] = [$media, $i];
                }
            }
        }
        return $mapa;
    }

    /**
     * As linhas do CSV com o mapa de colunas. Linha sem data ou sem valor legível fica de fora e é contada; valor fora
     * do padrão da coluna (noPadrao()) entra com 'duvida'.
     * @param array{data:int, descricao:int, valor:int, debito:int, credito:int, categoria:int, cabecalho:bool} $mapa
     * @return array{linhas:list<array{data:string, valor:float, descricao:string, fitid:string, categoria:string}>, ignoradas:int}
     */
    public static function aplicarMapa(array $linhas, array $mapa, string $sep): array
    {
        $dados = array_slice($linhas, $mapa['cabecalho'] ? 1 : 0);
        $duas = $mapa['valor'] < 0;
        $colunasValor = $duas ? [$mapa['debito'], $mapa['credito']] : [$mapa['valor']];
        $amostra = [];
        foreach ($colunasValor as $c) {
            if ($c >= 0) {
                array_push($amostra, ...array_column($dados, $c));
            }
        }
        $dec = self::decimal(array_map('strval', $amostra), $sep);
        $ordem = self::ordemDatas(array_map('strval', array_column($dados, $mapa['data'])));
        $out = [];
        $ignoradas = 0;
        foreach ($dados as $r) {
            $data = self::data((string) ($r[$mapa['data']] ?? ''), $ordem);
            $duvida = false;
            foreach ($colunasValor as $c) {
                $duvida = $duvida || ($c >= 0 && !self::noPadrao((string) ($r[$c] ?? ''), $dec));
            }
            if ($duas) {
                $deb = $mapa['debito'] >= 0 ? self::numero((string) ($r[$mapa['debito']] ?? ''), $dec) : null;
                $cred = $mapa['credito'] >= 0 ? self::numero((string) ($r[$mapa['credito']] ?? ''), $dec) : null;
                $valor = $deb === null && $cred === null ? null : round(abs($cred ?? 0.0) - abs($deb ?? 0.0), 2);
            } else {
                $valor = self::numero((string) ($r[$mapa['valor']] ?? ''), $dec);
            }
            if ($data === null || $valor === null || abs($valor) < 0.005) {
                $ignoradas++;
                continue;
            }
            $out[] = ['data' => $data, 'valor' => $valor, 'descricao' => self::limpar((string) ($r[$mapa['descricao']] ?? '')), 'fitid' => '',
                      'categoria' => $mapa['categoria'] >= 0 ? self::limpar((string) ($r[$mapa['categoria']] ?? '')) : ''] + ($duvida ? ['duvida' => true] : []);
        }
        return ['linhas' => $out, 'ignoradas' => $ignoradas];
    }
}
