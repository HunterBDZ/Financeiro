<?php
namespace Financeiro;

/**
 * Onde ficam a configuração, o banco, a sessão e o log. Um site com config/.env fica como sempre foi; sem ele
 * (ou com FIN_DADOS), tudo mora numa pasta de dados do usuário, fora da pasta do programa.
 */
final class Paths
{
    public static function raiz(): string
    {
        return dirname(__DIR__);
    }

    /** true = pasta de dados própria; false = site antigo, com config/.env. */
    public static function portatil(): bool
    {
        return self::ambiente('FIN_DADOS') !== null || !is_file(self::raiz() . '/config/.env');
    }

    /** A pasta de dados; null se não houver nenhuma (site antigo sem PASTA_DADOS, ou sistema sem pasta de usuário). */
    public static function dados(): ?string
    {
        $escolhida = self::ambiente('FIN_DADOS');
        if ($escolhida === null && !self::portatil()) {
            $escolhida = Config::get('PASTA_DADOS');
        } elseif ($escolhida === null) {
            $escolhida = self::padraoDoSistema();
        }
        return $escolhida === null || $escolhida === '' ? null : rtrim($escolhida, '/\\');
    }

    public static function config(): string
    {
        return self::portatil() ? self::dados() . '/config.env' : self::raiz() . '/config/.env';
    }

    /** O arquivo do banco SQLite. DB_FILE pode ser relativo à pasta de dados. */
    public static function banco(): string
    {
        $arquivo = (string) Config::get('DB_FILE', 'financeiro.sqlite');
        return self::absoluto($arquivo) ? $arquivo : self::dados() . '/' . $arquivo;
    }

    public static function sessoes(): string
    {
        return self::dados() . '/sessoes';
    }

    public static function log(): string
    {
        return self::dados() . '/log/financeiro.log';
    }

    public static function absoluto(string $caminho): bool
    {
        return (bool) preg_match('~^(/|\\\\|[A-Za-z]:[/\\\\])~', $caminho);
    }

    /** Fora de Documentos, para serviços de nuvem não mexerem no banco enquanto ele está aberto. */
    private static function padraoDoSistema(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $base = self::ambiente('LOCALAPPDATA');
            return $base === null ? null : $base . '/Financeiro';
        }
        $casa = self::ambiente('HOME');
        if (PHP_OS_FAMILY === 'Darwin') {
            return $casa === null ? null : $casa . '/Library/Application Support/Financeiro';
        }
        $xdg = self::ambiente('XDG_DATA_HOME');
        if ($xdg !== null) {
            return $xdg . '/financeiro';
        }
        return $casa === null ? null : $casa . '/.local/share/financeiro';
    }

    private static function ambiente(string $nome): ?string
    {
        $valor = getenv($nome);
        if ($valor === false || $valor === '') {
            $valor = $_SERVER[$nome] ?? null;
        }
        return is_string($valor) && $valor !== '' ? $valor : null;
    }
}
