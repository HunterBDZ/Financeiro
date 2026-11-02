<?php
namespace Financeiro;

/** Extrai .tar.gz só com zlib, igual em todo sistema. Aceita arquivos e pastas: link ou caminho para fora para tudo. */
final class Tar
{
    /** @throws \RuntimeException */
    public static function extrair(string $arquivo, string $destino, string $prefixo = ''): int
    {
        $gz = gzopen($arquivo, 'rb');
        if ($gz === false) {
            throw new \RuntimeException('não abriu o pacote');
        }
        $n = 0;
        try {
            $nomeLongo = null;
            while (true) {
                $cab = self::ler($gz, 512);
                if ($cab === '' || trim($cab, "\0") === '') {
                    break;
                }
                if (strlen($cab) !== 512) {
                    throw new \RuntimeException('pacote cortado');
                }
                self::conferirSoma($cab);
                $tipo = $cab[156];
                $tamanho = (int) octdec(trim(substr($cab, 124, 12), " \0"));
                $nome = rtrim(substr($cab, 0, 100), "\0");
                $antes = rtrim(substr($cab, 345, 155), "\0");
                if ($antes !== '' && substr($cab, 257, 5) === 'ustar') {
                    $nome = $antes . '/' . $nome;
                }
                $resto = (512 - $tamanho % 512) % 512;
                if ($tipo === 'L' || $tipo === 'x' || $tipo === 'g') {
                    $dados = self::ler($gz, $tamanho);
                    self::ler($gz, $resto);
                    if ($tipo === 'L') {
                        $nomeLongo = rtrim($dados, "\0");
                    } elseif ($tipo === 'x' && preg_match('/^\d+ path=(.*)$/m', $dados, $m)) {
                        $nomeLongo = $m[1];
                    }
                    continue;
                }
                $nome = $nomeLongo ?? $nome;
                $nomeLongo = null;
                $relativo = self::relativo($nome, $prefixo);
                if ($tipo === '5') {
                    if ($relativo !== '') {
                        self::criarPasta($destino . '/' . $relativo);
                    }
                    continue;
                }
                if ($tipo !== '0' && $tipo !== "\0") {
                    throw new \RuntimeException('o pacote tem um tipo de arquivo não aceito');
                }
                if ($relativo === '') {
                    throw new \RuntimeException('caminho inválido no pacote');
                }
                $alvo = $destino . '/' . $relativo;
                self::criarPasta(dirname($alvo));
                $saida = fopen($alvo, 'xb');
                if ($saida === false) {
                    throw new \RuntimeException('não deu para gravar o programa');
                }
                $falta = $tamanho;
                while ($falta > 0) {
                    $pedaco = self::ler($gz, min(65536, $falta));
                    if ($pedaco === '') {
                        fclose($saida);
                        throw new \RuntimeException('pacote cortado');
                    }
                    fwrite($saida, $pedaco);
                    $falta -= strlen($pedaco);
                }
                fclose($saida);
                @chmod($alvo, (octdec(trim(substr($cab, 100, 8), " \0")) & 0111) !== 0 ? 0755 : 0644);
                self::ler($gz, $resto);
                $n++;
            }
        } finally {
            gzclose($gz);
        }
        return $n;
    }

    /** O caminho dentro do destino, sem o prefixo; '' se for o próprio prefixo. */
    public static function relativo(string $nome, string $prefixo): string
    {
        $nome = preg_replace('#^(\./)+#', '', str_replace('\\', '/', $nome));
        if ($prefixo !== '') {
            if (rtrim($nome, '/') === rtrim($prefixo, '/')) {
                return '';
            }
            if (!str_starts_with($nome, $prefixo)) {
                throw new \RuntimeException('caminho inválido no pacote');
            }
            $nome = substr($nome, strlen($prefixo));
        }
        $nome = rtrim($nome, '/');
        if (str_starts_with($nome, '/') || preg_match('#(^|/)\.\.(/|$)#', $nome) || preg_match('/^[A-Za-z]:/', $nome) || str_contains($nome, "\0")) {
            throw new \RuntimeException('caminho inválido no pacote');
        }
        return $nome;
    }

    /** @param resource $gz */
    private static function ler($gz, int $tamanho): string
    {
        $out = '';
        while (strlen($out) < $tamanho) {
            $p = gzread($gz, $tamanho - strlen($out));
            if ($p === false || $p === '') {
                break;
            }
            $out .= $p;
        }
        return $out;
    }

    private static function conferirSoma(string $cab): void
    {
        $esperada = (int) octdec(trim(substr($cab, 148, 8), " \0"));
        $soma = 256;   // os 8 bytes da própria soma contam como espaços
        for ($i = 0; $i < 512; $i++) {
            if ($i < 148 || $i >= 156) {
                $soma += ord($cab[$i]);
            }
        }
        if ($soma !== $esperada) {
            throw new \RuntimeException('pacote danificado');
        }
    }

    private static function criarPasta(string $pasta): void
    {
        if (!is_dir($pasta) && !mkdir($pasta, 0755, true) && !is_dir($pasta)) {
            throw new \RuntimeException('não deu para gravar o programa');
        }
    }
}
