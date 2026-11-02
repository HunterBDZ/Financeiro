<?php
namespace Financeiro\Services;

/**
 * A planilha (CSV) do Extrato: o resultado filtrado, para abrir direto no Excel ou no LibreOffice.
 * UTF-8 com BOM; em português e espanhol, separador ";" e vírgula decimal; em inglês, "," e ponto (o que
 * cada planilha espera no idioma). Colunas: data (AAAA-MM-DD, que toda planilha lê como data), descrição,
 * categoria, valor com sinal (− saiu, + entrou, sem separador de milhar) e quem lançou.
 * Texto que começa com = + − @ ganha um apóstrofo na frente, para a planilha não ler como fórmula (OWASP).
 */
final class Planilha
{
    /** Mais que isso, a tela pede um período menor: a planilha é para consultar, não para backup. */
    public const LIMITE = 20000;

    /** @return array{0:string, 1:string} [separador de campos, separador decimal] */
    public static function separadores(): array
    {
        return sepDecimal() === ',' ? [';', ','] : [',', '.'];
    }

    /** O valor com sinal, como a planilha lê: `-45,90`, `7710,10`. */
    public static function valor(float $valor, int $sinal): string
    {
        $v = round($valor, 2) * ($sinal < 0 ? -1 : 1);
        return ($v < 0 ? '-' : '') . number_format(abs($v), 2, self::separadores()[1], '');
    }

    /** Texto da pessoa sem virar fórmula. */
    public static function texto(string $s): string
    {
        $s = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s));
        return $s !== '' && strpbrk($s[0], "=+-@\t\r") !== false ? "'" . $s : $s;
    }

    /** Uma linha de LancamentoModel::emLotes() pronta para a planilha. @return list<string> */
    public static function linha(array $r): array
    {
        $cat = ['nome' => (string) $r['categoria_nome'], 'apelido' => $r['apelido'] ?? null];
        $desc = descricaoTela(trim((string) $r['descricao']), $cat);
        return [(string) $r['data'], self::texto($desc), self::texto(nomeCurto($cat)), self::valor((float) $r['valor'], (int) $r['sinal']), self::texto((string) $r['usuario_nome'])];
    }

    /**
     * Escreve a planilha inteira em $saida (um recurso de arquivo, como php://output). Devolve quantas linhas de
     * lançamento escreveu.
     * @param iterable<array> $linhas
     * @param resource $saida
     */
    public static function escrever(iterable $linhas, $saida): int
    {
        [$sep] = self::separadores();
        fwrite($saida, "\u{FEFF}");
        fputcsv($saida, [t('Data'), t('Descrição'), t('Categoria'), t('Valor'), t('Quem lançou')], $sep, '"', '', "\r\n");
        $n = 0;
        foreach ($linhas as $r) {
            fputcsv($saida, self::linha($r), $sep, '"', '', "\r\n");
            $n++;
        }
        return $n;
    }

    /**
     * O nome do arquivo diz o período: `lancamentos-2026-11.csv`, `lancamentos-2026.csv`,
     * `lancamentos-2026-01-01-a-2026-03-31.csv`, `lancamentos-todos-os-meses.csv`. Só letras ASCII.
     */
    public static function nomeArquivo(string $modo, string $mes, ?string $de, ?string $ate): string
    {
        $periodo = match ($modo) {
            'ano'   => substr($mes, 0, 4),
            'tudo'  => t('todos-os-meses'),
            'datas' => ($de ?? t('inicio')) . '-' . t('a') . '-' . ($ate ?? t('fim')),
            default => substr($mes, 0, 7),
        };
        $nome = t('lancamentos') . '-' . $periodo . '.csv';
        return (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $nome);
    }
}
