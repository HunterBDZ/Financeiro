<?php
namespace Financeiro;

/**
 * Imita em PHP o que o SQLite não tem do MySQL: a colação utf8mb4_0900_ai_ci, sem acento e sem caixa, para comparar,
 * ordenar e procurar igual, e o arredondamento de uma coluna DECIMAL. As tabelas vieram de WEIGHT_STRING no MySQL 8.4; tools/comparar-bancos.php confere a ordem.
 * Alfabetos sem tabela aqui (grego, cirílico, CJK) só perdem a caixa e ficam depois do latino, por código.
 */
final class Colacao
{
    /** Letra acentuada => letras-base. Maiúsculas entram porque nem toda tem par em strtolower. */
    private const DOBRA = [
        'ª' => 'a', 'º' => 'o', 'À' => 'a', 'Á' => 'a', 'Â' => 'a', 'Ã' => 'a', 'Ä' => 'a', 'Å' => 'a', 'Æ' => 'ae',
        'Ç' => 'c', 'È' => 'e', 'É' => 'e', 'Ê' => 'e', 'Ë' => 'e', 'Ì' => 'i', 'Í' => 'i', 'Î' => 'i', 'Ï' => 'i',
        'Ð' => 'd', 'Ñ' => 'n', 'Ò' => 'o', 'Ó' => 'o', 'Ô' => 'o', 'Õ' => 'o', 'Ö' => 'o', 'Ø' => 'o', 'Ù' => 'u',
        'Ú' => 'u', 'Û' => 'u', 'Ü' => 'u', 'Ý' => 'y', 'ß' => 'ss', 'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a',
        'ä' => 'a', 'å' => 'a', 'æ' => 'ae', 'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i',
        'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ð' => 'd', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ö' => 'o', 'ø' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'Ā' => 'a',
        'ā' => 'a', 'Ă' => 'a', 'ă' => 'a', 'Ą' => 'a', 'ą' => 'a', 'Ć' => 'c', 'ć' => 'c', 'Ĉ' => 'c', 'ĉ' => 'c',
        'Ċ' => 'c', 'ċ' => 'c', 'Č' => 'c', 'č' => 'c', 'Ď' => 'd', 'ď' => 'd', 'Đ' => 'd', 'đ' => 'd', 'Ē' => 'e',
        'ē' => 'e', 'Ĕ' => 'e', 'ĕ' => 'e', 'Ė' => 'e', 'ė' => 'e', 'Ę' => 'e', 'ę' => 'e', 'Ě' => 'e', 'ě' => 'e',
        'Ĝ' => 'g', 'ĝ' => 'g', 'Ğ' => 'g', 'ğ' => 'g', 'Ġ' => 'g', 'ġ' => 'g', 'Ģ' => 'g', 'ģ' => 'g', 'Ĥ' => 'h',
        'ĥ' => 'h', 'Ħ' => 'h', 'ħ' => 'h', 'Ĩ' => 'i', 'ĩ' => 'i', 'Ī' => 'i', 'ī' => 'i', 'Ĭ' => 'i', 'ĭ' => 'i',
        'Į' => 'i', 'į' => 'i', 'İ' => 'i', 'Ĳ' => 'ij', 'ĳ' => 'ij', 'Ĵ' => 'j', 'ĵ' => 'j', 'Ķ' => 'k', 'ķ' => 'k',
        'Ĺ' => 'l', 'ĺ' => 'l', 'Ļ' => 'l', 'ļ' => 'l', 'Ľ' => 'l', 'ľ' => 'l', 'Ŀ' => 'l', 'ŀ' => 'l', 'Ł' => 'l',
        'ł' => 'l', 'Ń' => 'n', 'ń' => 'n', 'Ņ' => 'n', 'ņ' => 'n', 'Ň' => 'n', 'ň' => 'n', 'Ō' => 'o', 'ō' => 'o',
        'Ŏ' => 'o', 'ŏ' => 'o', 'Ő' => 'o', 'ő' => 'o', 'Œ' => 'oe', 'œ' => 'oe', 'Ŕ' => 'r', 'ŕ' => 'r', 'Ŗ' => 'r',
        'ŗ' => 'r', 'Ř' => 'r', 'ř' => 'r', 'Ś' => 's', 'ś' => 's', 'Ŝ' => 's', 'ŝ' => 's', 'Ş' => 's', 'ş' => 's',
        'Š' => 's', 'š' => 's', 'Ţ' => 't', 'ţ' => 't', 'Ť' => 't', 'ť' => 't', 'Ũ' => 'u', 'ũ' => 'u', 'Ū' => 'u',
        'ū' => 'u', 'Ŭ' => 'u', 'ŭ' => 'u', 'Ů' => 'u', 'ů' => 'u', 'Ű' => 'u', 'ű' => 'u', 'Ų' => 'u', 'ų' => 'u',
        'Ŵ' => 'w', 'ŵ' => 'w', 'Ŷ' => 'y', 'ŷ' => 'y', 'Ÿ' => 'y', 'Ź' => 'z', 'ź' => 'z', 'Ż' => 'z', 'ż' => 'z',
        'Ž' => 'z', 'ž' => 'z', 'ſ' => 's', 'ƍ' => 'zw', 'Ơ' => 'o', 'ơ' => 'o', 'Ư' => 'u', 'ư' => 'u', 'ƾ' => 'ts',
        'Ǆ' => 'dz', 'ǅ' => 'dz', 'ǆ' => 'dz', 'Ǉ' => 'lj', 'ǈ' => 'lj', 'ǉ' => 'lj', 'Ǌ' => 'nj', 'ǋ' => 'nj',
        'ǌ' => 'nj', 'Ǎ' => 'a', 'ǎ' => 'a', 'Ǐ' => 'i', 'ǐ' => 'i', 'Ǒ' => 'o', 'ǒ' => 'o', 'Ǔ' => 'u', 'ǔ' => 'u',
        'Ǖ' => 'u', 'ǖ' => 'u', 'Ǘ' => 'u', 'ǘ' => 'u', 'Ǚ' => 'u', 'ǚ' => 'u', 'Ǜ' => 'u', 'ǜ' => 'u', 'Ǟ' => 'a',
        'ǟ' => 'a', 'Ǡ' => 'a', 'ǡ' => 'a', 'Ǣ' => 'ae', 'ǣ' => 'ae', 'Ǧ' => 'g', 'ǧ' => 'g', 'Ǩ' => 'k', 'ǩ' => 'k',
        'Ǫ' => 'o', 'ǫ' => 'o', 'Ǭ' => 'o', 'ǭ' => 'o', 'ǰ' => 'j', 'Ǳ' => 'dz', 'ǲ' => 'dz', 'ǳ' => 'dz',
        'Ǵ' => 'g', 'ǵ' => 'g', 'Ǹ' => 'n', 'ǹ' => 'n', 'Ǻ' => 'a', 'ǻ' => 'a', 'Ǽ' => 'ae', 'ǽ' => 'ae', 'Ǿ' => 'o',
        'ǿ' => 'o', 'Ȁ' => 'a', 'ȁ' => 'a', 'Ȃ' => 'a', 'ȃ' => 'a', 'Ȅ' => 'e', 'ȅ' => 'e', 'Ȇ' => 'e', 'ȇ' => 'e',
        'Ȉ' => 'i', 'ȉ' => 'i', 'Ȋ' => 'i', 'ȋ' => 'i', 'Ȍ' => 'o', 'ȍ' => 'o', 'Ȏ' => 'o', 'ȏ' => 'o', 'Ȑ' => 'r',
        'ȑ' => 'r', 'Ȓ' => 'r', 'ȓ' => 'r', 'Ȕ' => 'u', 'ȕ' => 'u', 'Ȗ' => 'u', 'ȗ' => 'u', 'Ș' => 's', 'ș' => 's',
        'Ț' => 't', 'ț' => 't', 'Ȟ' => 'h', 'ȟ' => 'h', 'Ȧ' => 'a', 'ȧ' => 'a', 'Ȩ' => 'e', 'ȩ' => 'e', 'Ȫ' => 'o',
        'ȫ' => 'o', 'Ȭ' => 'o', 'ȭ' => 'o', 'Ȯ' => 'o', 'ȯ' => 'o', 'Ȱ' => 'o', 'ȱ' => 'o', 'Ȳ' => 'y', 'ȳ' => 'y',
        'ȸ' => 'db', 'ȹ' => 'qp', 'Ḁ' => 'a', 'ḁ' => 'a', 'Ḃ' => 'b', 'ḃ' => 'b', 'Ḅ' => 'b', 'ḅ' => 'b', 'Ḇ' => 'b',
        'ḇ' => 'b', 'Ḉ' => 'c', 'ḉ' => 'c', 'Ḋ' => 'd', 'ḋ' => 'd', 'Ḍ' => 'd', 'ḍ' => 'd', 'Ḏ' => 'd', 'ḏ' => 'd',
        'Ḑ' => 'd', 'ḑ' => 'd', 'Ḓ' => 'd', 'ḓ' => 'd', 'Ḕ' => 'e', 'ḕ' => 'e', 'Ḗ' => 'e', 'ḗ' => 'e', 'Ḙ' => 'e',
        'ḙ' => 'e', 'Ḛ' => 'e', 'ḛ' => 'e', 'Ḝ' => 'e', 'ḝ' => 'e', 'Ḟ' => 'f', 'ḟ' => 'f', 'Ḡ' => 'g', 'ḡ' => 'g',
        'Ḣ' => 'h', 'ḣ' => 'h', 'Ḥ' => 'h', 'ḥ' => 'h', 'Ḧ' => 'h', 'ḧ' => 'h', 'Ḩ' => 'h', 'ḩ' => 'h', 'Ḫ' => 'h',
        'ḫ' => 'h', 'Ḭ' => 'i', 'ḭ' => 'i', 'Ḯ' => 'i', 'ḯ' => 'i', 'Ḱ' => 'k', 'ḱ' => 'k', 'Ḳ' => 'k', 'ḳ' => 'k',
        'Ḵ' => 'k', 'ḵ' => 'k', 'Ḷ' => 'l', 'ḷ' => 'l', 'Ḹ' => 'l', 'ḹ' => 'l', 'Ḻ' => 'l', 'ḻ' => 'l', 'Ḽ' => 'l',
        'ḽ' => 'l', 'Ḿ' => 'm', 'ḿ' => 'm', 'Ṁ' => 'm', 'ṁ' => 'm', 'Ṃ' => 'm', 'ṃ' => 'm', 'Ṅ' => 'n', 'ṅ' => 'n',
        'Ṇ' => 'n', 'ṇ' => 'n', 'Ṉ' => 'n', 'ṉ' => 'n', 'Ṋ' => 'n', 'ṋ' => 'n', 'Ṍ' => 'o', 'ṍ' => 'o', 'Ṏ' => 'o',
        'ṏ' => 'o', 'Ṑ' => 'o', 'ṑ' => 'o', 'Ṓ' => 'o', 'ṓ' => 'o', 'Ṕ' => 'p', 'ṕ' => 'p', 'Ṗ' => 'p', 'ṗ' => 'p',
        'Ṙ' => 'r', 'ṙ' => 'r', 'Ṛ' => 'r', 'ṛ' => 'r', 'Ṝ' => 'r', 'ṝ' => 'r', 'Ṟ' => 'r', 'ṟ' => 'r', 'Ṡ' => 's',
        'ṡ' => 's', 'Ṣ' => 's', 'ṣ' => 's', 'Ṥ' => 's', 'ṥ' => 's', 'Ṧ' => 's', 'ṧ' => 's', 'Ṩ' => 's', 'ṩ' => 's',
        'Ṫ' => 't', 'ṫ' => 't', 'Ṭ' => 't', 'ṭ' => 't', 'Ṯ' => 't', 'ṯ' => 't', 'Ṱ' => 't', 'ṱ' => 't', 'Ṳ' => 'u',
        'ṳ' => 'u', 'Ṵ' => 'u', 'ṵ' => 'u', 'Ṷ' => 'u', 'ṷ' => 'u', 'Ṹ' => 'u', 'ṹ' => 'u', 'Ṻ' => 'u', 'ṻ' => 'u',
        'Ṽ' => 'v', 'ṽ' => 'v', 'Ṿ' => 'v', 'ṿ' => 'v', 'Ẁ' => 'w', 'ẁ' => 'w', 'Ẃ' => 'w', 'ẃ' => 'w', 'Ẅ' => 'w',
        'ẅ' => 'w', 'Ẇ' => 'w', 'ẇ' => 'w', 'Ẉ' => 'w', 'ẉ' => 'w', 'Ẋ' => 'x', 'ẋ' => 'x', 'Ẍ' => 'x', 'ẍ' => 'x',
        'Ẏ' => 'y', 'ẏ' => 'y', 'Ẑ' => 'z', 'ẑ' => 'z', 'Ẓ' => 'z', 'ẓ' => 'z', 'Ẕ' => 'z', 'ẕ' => 'z', 'ẖ' => 'h',
        'ẗ' => 't', 'ẘ' => 'w', 'ẙ' => 'y', 'ẛ' => 's', 'ẞ' => 'ss', 'Ạ' => 'a', 'ạ' => 'a', 'Ả' => 'a', 'ả' => 'a',
        'Ấ' => 'a', 'ấ' => 'a', 'Ầ' => 'a', 'ầ' => 'a', 'Ẩ' => 'a', 'ẩ' => 'a', 'Ẫ' => 'a', 'ẫ' => 'a', 'Ậ' => 'a',
        'ậ' => 'a', 'Ắ' => 'a', 'ắ' => 'a', 'Ằ' => 'a', 'ằ' => 'a', 'Ẳ' => 'a', 'ẳ' => 'a', 'Ẵ' => 'a', 'ẵ' => 'a',
        'Ặ' => 'a', 'ặ' => 'a', 'Ẹ' => 'e', 'ẹ' => 'e', 'Ẻ' => 'e', 'ẻ' => 'e', 'Ẽ' => 'e', 'ẽ' => 'e', 'Ế' => 'e',
        'ế' => 'e', 'Ề' => 'e', 'ề' => 'e', 'Ể' => 'e', 'ể' => 'e', 'Ễ' => 'e', 'ễ' => 'e', 'Ệ' => 'e', 'ệ' => 'e',
        'Ỉ' => 'i', 'ỉ' => 'i', 'Ị' => 'i', 'ị' => 'i', 'Ọ' => 'o', 'ọ' => 'o', 'Ỏ' => 'o', 'ỏ' => 'o', 'Ố' => 'o',
        'ố' => 'o', 'Ồ' => 'o', 'ồ' => 'o', 'Ổ' => 'o', 'ổ' => 'o', 'Ỗ' => 'o', 'ỗ' => 'o', 'Ộ' => 'o', 'ộ' => 'o',
        'Ớ' => 'o', 'ớ' => 'o', 'Ờ' => 'o', 'ờ' => 'o', 'Ở' => 'o', 'ở' => 'o', 'Ỡ' => 'o', 'ỡ' => 'o', 'Ợ' => 'o',
        'ợ' => 'o', 'Ụ' => 'u', 'ụ' => 'u', 'Ủ' => 'u', 'ủ' => 'u', 'Ứ' => 'u', 'ứ' => 'u', 'Ừ' => 'u', 'ừ' => 'u',
        'Ử' => 'u', 'ử' => 'u', 'Ữ' => 'u', 'ữ' => 'u', 'Ự' => 'u', 'ự' => 'u', 'Ỳ' => 'y', 'ỳ' => 'y', 'Ỵ' => 'y',
        'ỵ' => 'y', 'Ỷ' => 'y', 'ỷ' => 'y', 'Ỹ' => 'y', 'ỹ' => 'y', 'Ỻ' => 'll', 'ỻ' => 'll', 'ⱼ' => 'j', 'ⱽ' => 'v',
        '²' => '2', '³' => '3', '¹' => '1', '¼' => '1/4', '½' => '1/2', '¾' => '3/4',
    ];

    /** Símbolos na ordem do MySQL: todos vêm antes dos dígitos. */
    private const SIMBOLOS = [
        "\t", "\n", "\r", ' ', ' ', '‾', '_', '‗', '-', '‐', '‑', '‒', '–', '—', '―', '⁓', ',', ';', '⁏', ':', '!',
        '‼', '⁉', '¡', '?', '⁈', '⁇', '¿', '‽', '.', '․', '‥', '…', '·', '⁕', '⁖', '⁘', '⁙', '⁚', '⁛', '⁜', '⁝', '⁞',
        '\'', '‘', '’', '‚', '‛', '‹', '›', '"', '“', '”', '„', '‟', '«', '»', '(', ')', '[', ']', '{', '}', '⁅',
        '⁆', '‖', '§', '¶', '⁋', '@', '*', '⁎', '⁑', '/', '\\', '&', '⁊', '#', '%', '‰', '‱', '†', '‡', '•', '‣',
        '‧', '⁃', '⁌', '⁍', '′', '″', '‴', '⁗', '‵', '‶', '‷', '‸', '※', '‿', '⁔', '⁀', '⁐', '⁁', '⁂', '`', '´', '^',
        '¯', '¨', '¸', '°', '©', '®', '+', '±', '÷', '×', '<', '=', '>', '¬', '|', '¦', '~', '⁒', '⁄', '¤', '¢', '$',
        '£', '¥', '₠', '₡', '₢', '₣', '₤', '₥', '₦', '₧', '₩', '₪', '₫', '€', '₭', '₮', '₯', '₰', '₱', '₲', '₳', '₴',
        '₵', '₶', '₷', '₸', '₹', '₺', '₻', '₼', '₽', '₾',
    ];

    /** @var array<string, string> */
    private static array $chaves = [];
    /** @var ?array<string, string> */
    private static ?array $pesos = null;

    /** Texto em minúsculas, sem acento e sem o que o MySQL ignora. Serve para LIKE. */
    public static function dobrar(string $s): string
    {
        if ($s === '') {
            return '';
        }
        $s = strtr($s, self::DOBRA);
        if (preg_match('/[\x00-\x08\x0E-\x1F\x7F-\xFF]/', $s)) {
            $limpo = preg_replace('/[\x{0}-\x{8}\x{E}-\x{1F}\x{7F}-\x{84}\x{86}-\x{9F}\p{Cf}\p{Mn}]+/u', '', $s);
            $s = mb_strtolower($limpo ?? $s, 'UTF-8');
        } else {
            $s = strtolower($s);
        }
        return $s;
    }

    /** Chave que, comparada byte a byte, dá a ordem do MySQL: símbolos, dígitos, letras, o resto. */
    public static function chave(string $s): string
    {
        if (isset(self::$chaves[$s])) {
            return self::$chaves[$s];
        }
        if (self::$pesos === null) {
            self::$pesos = [];
            foreach (self::SIMBOLOS as $i => $c) {
                self::$pesos[$c] = chr(1) . chr(1 + intdiv($i, 100)) . chr(1 + $i % 100);
            }
            foreach (str_split('0123456789abcdefghijklmnopqrstuvwxyz') as $i => $c) {
                self::$pesos[$c] = chr(2) . chr(1 + $i);
            }
        }
        $k = strtr(self::dobrar($s), self::$pesos);
        // emojis e demais símbolos sem peso na tabela vêm logo antes dos símbolos de moeda, em ordem de código
        $antesDaMoeda = self::$pesos['¤'][0] . self::$pesos['¤'][1] . chr(ord(self::$pesos['¤'][2]) - 1) . chr(100);
        $k = preg_replace('/[\x{2000}-\x{2BFF}\x{1F000}-\x{1FAFF}]/u', $antesDaMoeda . '$0', $k) ?? $k;
        if (count(self::$chaves) > 5000) {
            self::$chaves = [];
        }
        return self::$chaves[$s] = $k;
    }

    public static function comparar(string $a, string $b): int
    {
        return strcmp(self::chave($a), self::chave($b));
    }

    /** LIKE do MySQL com essa colação: % e _ contam caracteres, depois de dobrar acento e caixa. */
    public static function like(string $padrao, string $texto, ?string $escape = null): bool
    {
        $re = '';
        $pedaco = '';
        $fim = strlen($padrao);
        for ($i = 0; $i < $fim; $i++) {
            $c = $padrao[$i];
            if ($escape !== null && $c === $escape && $i + 1 < $fim) {
                $pedaco .= $padrao[++$i];
            } elseif ($c === '%' || $c === '_') {
                $re .= preg_quote(self::dobrar($pedaco), '/') . ($c === '%' ? '.*' : '.');
                $pedaco = '';
            } else {
                $pedaco .= $c;
            }
        }
        $re .= preg_quote(self::dobrar($pedaco), '/');
        return preg_match('/^' . $re . '$/su', self::dobrar($texto)) === 1;
    }

    /** Arredonda como uma coluna DECIMAL: meio para longe do zero, sobre o número escrito (1.005 vira 1.01). */
    public static function decimal(int|float|string|null $valor, int $casas): ?float
    {
        if ($valor === null) {
            return null;
        }
        $s = is_string($valor) ? trim($valor) : sprintf('%.15G', (float) $valor);
        if (!preg_match('/^([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/', (string) $s, $m) || ($m[2] === '' && ($m[3] ?? '') === '')) {
            return (float) $valor;
        }
        $int = $m[2];
        $frac = $m[3] ?? '';
        $exp = (int) ($m[4] ?? 0);
        if ($exp > 0) {
            $frac = str_pad($frac, $exp, '0');
            $int .= substr($frac, 0, $exp);
            $frac = substr($frac, $exp);
        } elseif ($exp < 0) {
            $int = str_pad($int, -$exp, '0', STR_PAD_LEFT);
            $frac = substr($int, $exp) . $frac;
            $int = substr($int, 0, $exp);
        }
        $digitos = ltrim($int, '0') . str_pad(substr($frac, 0, $casas), $casas, '0');
        if (($frac[$casas] ?? '0') >= '5') {
            $i = strlen($digitos) - 1;
            while ($i >= 0 && $digitos[$i] === '9') {
                $digitos[$i--] = '0';
            }
            if ($i >= 0) {
                $digitos[$i] = (string) ((int) $digitos[$i] + 1);
            } else {
                $digitos = '1' . $digitos;
            }
        }
        $digitos = str_pad($digitos, $casas + 1, '0', STR_PAD_LEFT);
        return (float) ($m[1] . substr($digitos, 0, -$casas ?: null) . '.' . substr($digitos, -$casas));
    }
}
