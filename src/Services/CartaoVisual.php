<?php
namespace Financeiro\Services;

/**
 * O visual do cartão desenhado na aba Cartão (coluna cartoes.visual). NULL é o "Padrão", que segue o tema do app.
 * Um estilo é uma chave desta lista; a paleta dele mora em cartao.css, em [data-visual="chave"] (--ct-a, --ct-m,
 * --ct-b: o gradiente; --ct-txt e --ct-txt-2: o texto, com contraste AA nas três cores e no brilho; tests/regras.php
 * confere). Os estilos lembram os cartões mais comuns no Brasil, mas têm só nome de cor e acabamento, sem marca.
 * A "Cor própria" grava #rrggbb; o texto, branco ou preto, e a segunda cor do gradiente saem daqui (e de cartao.js,
 * na prévia), e a cor é ajustada só o bastante para o texto ficar legível.
 */
final class CartaoVisual
{
    /** As chaves, na ordem do seletor. */
    public const ESTILOS = ['roxo', 'laranja', 'vermelho', 'amarelo-azul', 'azul', 'azul-marinho', 'azul-claro', 'verde',
                            'verde-escuro', 'preto-fosco', 'grafite-dourado', 'prata', 'rosa', 'branco'];

    /** A cor que o seletor de cor própria mostra antes da escolha. */
    public const COR_INICIAL = '#2f6fdf';

    /** O contraste mínimo do texto: AA (4,5), com uma folga pequena. */
    public const MINIMO = 4.6;

    /** A força do brilho branco que atravessa o cartão (rgb(255 255 255 / .10) em cartao.css). */
    public const BRILHO = 0.10;

    /** @return array<string,string> chave => nome na tela, na ordem do seletor ('' = Padrão) */
    public static function nomes(): array
    {
        return ['' => t('Padrão'), 'roxo' => t('Roxo'), 'laranja' => t('Laranja'), 'vermelho' => t('Vermelho'),
                'amarelo-azul' => t('Amarelo e azul'), 'azul' => t('Azul'), 'azul-marinho' => t('Azul-marinho'),
                'azul-claro' => t('Azul-claro'), 'verde' => t('Verde'), 'verde-escuro' => t('Verde-escuro'),
                'preto-fosco' => t('Preto fosco'), 'grafite-dourado' => t('Grafite e dourado'), 'prata' => t('Prata'),
                'rosa' => t('Rosa'), 'branco' => t('Branco')];
    }

    /**
     * O que veio do formulário (visual = '' | chave | 'propria'; visual_cor = #rrggbb) para a coluna.
     * @return array{0:?string, 1:?string} [erro, valor]; valor null = Padrão
     */
    public static function validar(mixed $escolha, mixed $cor = ''): array
    {
        $escolha = is_string($escolha) ? trim($escolha) : null;
        if ($escolha === '') {
            return [null, null];
        }
        if ($escolha !== null && in_array($escolha, self::ESTILOS, true)) {
            return [null, $escolha];
        }
        if ($escolha === 'propria') {
            return is_string($cor) && preg_match('/^#[0-9a-f]{6}$/iD', trim($cor)) ? [null, strtolower(trim($cor))]
                : [t('Escolha uma cor para o cartão.'), null];
        }
        return [t('Escolha um dos estilos do cartão.'), null];
    }

    /** O valor guardado, conferido de novo: um estilo que deixou de existir volta ao Padrão. */
    public static function normalizar(?string $visual): ?string
    {
        return $visual === null ? null : self::validar(str_starts_with($visual, '#') ? 'propria' : $visual, $visual)[1];
    }

    /** A chave do radio marcado no seletor: '', uma chave ou 'propria'. */
    public static function escolha(?string $visual): string
    {
        $v = self::normalizar($visual);
        return $v === null ? '' : (str_starts_with($v, '#') ? 'propria' : $v);
    }

    /**
     * Os atributos do desenho do cartão (e da miniatura da cor própria): data-visual e, na cor própria, as cores.
     * Padrão: nada, e o cartão fica com as cores do tema.
     */
    public static function atributos(?string $visual): string
    {
        $v = self::normalizar($visual);
        if ($v === null) {
            return '';
        }
        if (!str_starts_with($v, '#')) {
            return ' data-visual="' . e($v) . '"';
        }
        $c = self::corPropria($v);
        return ' data-visual="propria" style="--ct-a:' . $c['a'] . ';--ct-b:' . $c['b'] . ';--ct-txt:' . $c['txt']
            . ';--ct-txt-2:' . $c['txt'] . ';--ct-pill:' . $c['pill'] . '"';
    }

    /**
     * Da cor escolhida, o desenho: texto branco ou preto (o que contrasta mais), a cor puxada para longe do texto
     * só até o contraste chegar a MINIMO também debaixo do brilho, e a segunda cor do gradiente, ainda mais longe.
     * @return array{a:string, b:string, txt:string, pill:string}
     */
    public static function corPropria(string $hex): array
    {
        $rgb = self::rgb($hex);
        $branco = self::contraste('#ffffff', $hex) >= self::contraste('#000000', $hex);
        $alvo = $branco ? [0, 0, 0] : [255, 255, 255];
        $txt = $branco ? '#ffffff' : '#000000';
        for ($i = 0; $i < 30 && self::piorContraste($txt, $rgb) < self::MINIMO; $i++) {
            $rgb = self::misturar($rgb, $alvo, 0.06);
        }
        return ['a' => self::hex($rgb), 'b' => self::hex(self::misturar($rgb, $alvo, 0.28)), 'txt' => $txt,
                'pill' => $branco ? 'rgb(0 0 0 / .22)' : 'rgb(255 255 255 / .45)'];
    }

    /** O contraste do texto com a cor do fundo, sozinha e debaixo do brilho: o menor dos dois. */
    public static function piorContraste(string $txt, array $fundo): float
    {
        return min(self::contraste($txt, self::hex($fundo)), self::contraste($txt, self::hex(self::misturar($fundo, [255, 255, 255], self::BRILHO))));
    }

    /** Contraste WCAG entre duas cores #rrggbb (1 a 21). */
    public static function contraste(string $a, string $b): float
    {
        [$la, $lb] = [self::luminancia(self::rgb($a)), self::luminancia(self::rgb($b))];
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** @return array{0:float,1:float,2:float} */
    public static function rgb(string $hex): array
    {
        return [(float) hexdec(substr($hex, 1, 2)), (float) hexdec(substr($hex, 3, 2)), (float) hexdec(substr($hex, 5, 2))];
    }

    public static function hex(array $rgb): string
    {
        return sprintf('#%02x%02x%02x', ...array_map(static fn(float $c): int => (int) round(max(0.0, min(255.0, $c))), $rgb));
    }

    /** @return array{0:float,1:float,2:float} a cor a, `quanto` (0 a 1) do caminho até b */
    public static function misturar(array $a, array $b, float $quanto): array
    {
        return [$a[0] + ($b[0] - $a[0]) * $quanto, $a[1] + ($b[1] - $a[1]) * $quanto, $a[2] + ($b[2] - $a[2]) * $quanto];
    }

    private static function luminancia(array $rgb): float
    {
        $l = array_map(static function (float $c): float {
            $c /= 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $rgb);
        return 0.2126 * $l[0] + 0.7152 * $l[1] + 0.0722 * $l[2];
    }
}
