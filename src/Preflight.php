<?php
namespace Financeiro;

/** O que o Financeiro precisa para abrir. Quando falta algo, mostra uma tela clara em vez de um erro de PHP. */
final class Preflight
{
    private const EXTENSOES = ['mbstring', 'session', 'zlib', 'json', 'ctype', 'pdo'];

    /**
     * Com as extensões e a versão de PHP do momento: facilita testar a tela.
     * @param array<string, bool> $tem extensão => carregada
     * @return list<string>
     */
    public static function faltando(int $versaoPhp, array $tem, string $driver): array
    {
        $out = [];
        if ($versaoPhp < 80400) {
            $out[] = t('O PHP é antigo: o MyMonetto precisa do PHP 8.4 ou mais novo.');
        }
        foreach (self::EXTENSOES as $ext) {
            if (!($tem[$ext] ?? false)) {
                $out[] = t('Falta a extensão {nome} do PHP.', ['nome' => $ext]);
            }
        }
        if ($driver !== 'mysql' && $driver !== 'sqlite') {
            $out[] = t('O banco configurado em DB_DRIVER não existe. Use sqlite ou mysql.');
        } elseif (!($tem['pdo_' . $driver] ?? false)) {
            $out[] = t('Falta a extensão {nome} do PHP.', ['nome' => 'pdo_' . $driver]);
        }
        return $out;
    }

    /** @return list<string> o que impede o site de abrir */
    public static function problemas(): array
    {
        $tem = [];
        foreach ([...self::EXTENSOES, 'pdo_mysql', 'pdo_sqlite'] as $ext) {
            $tem[$ext] = extension_loaded($ext);
        }
        $portatil = Paths::portatil();
        $driver = (string) Config::get('DB_DRIVER', $portatil ? 'sqlite' : 'mysql');
        $out = self::faltando(PHP_VERSION_ID, $tem, $driver);

        $local = Rede::requisicaoLocal();
        $config = Paths::dados() !== null || !$portatil ? Paths::config() : null;
        if ($config !== null && is_file($config) && !is_readable($config)) {
            $out[] = $local ? t('Não consegui ler a configuração em {caminho}.', ['caminho' => Paths::paraMostrar($config)]) : t('Não consegui ler a configuração.');
        }
        if ($portatil || $driver === 'sqlite') {
            $dados = Paths::dados();
            if ($dados === null) {
                if ($portatil || !Paths::absoluto((string) Config::get('DB_FILE', ''))) {
                    $out[] = t('Não achei uma pasta para guardar os dados. Defina a variável FIN_DADOS.');
                }
            } elseif (!self::pastaGravavel($dados)) {
                $out[] = $local ? t('Não consigo gravar na pasta de dados {caminho}.', ['caminho' => Paths::paraMostrar($dados)]) : t('Não consigo gravar na pasta de dados.');
            }
        }
        // só antes de o banco existir: a cada página, seria uma conexão a mais à toa
        if ($driver === 'sqlite' && ($tem['pdo_sqlite'] ?? false) && Paths::dados() !== null && !is_file(Paths::banco())) {
            $versao = (string) (new \PDO('sqlite::memory:'))->query('SELECT sqlite_version()')->fetchColumn();
            if (version_compare($versao, SqliteConexao::VERSAO_MINIMA, '<')) {
                $out[] = t('O SQLite é antigo: o MyMonetto precisa do {minima} ou mais novo, e este é o {versao}.', ['minima' => SqliteConexao::VERSAO_MINIMA, 'versao' => $versao]);
            }
        }
        return $out;
    }

    /** Para na tela de problemas, se houver algum. */
    public static function exigir(): void
    {
        $problemas = self::problemas();
        if ($problemas !== []) {
            self::tela(500, t('O MyMonetto não pôde abrir'), $problemas);
        }
    }

    /** O que falta só atrapalha uma parte do app: nada aqui impede de abrir. */
    public static function avisos(): array
    {
        $out = [];
        if (!extension_loaded('curl')) {
            $out[] = t('Sem a extensão curl, as cotações e a leitura de links ficam desligadas.');
        }
        if (!extension_loaded('dom')) {
            $out[] = t('Sem a extensão dom, a leitura de links fica mais simples.');
        }
        return $out;
    }

    /** @param list<string> $linhas */
    public static function tela(int $codigo, string $titulo, array $linhas): never
    {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $titulo . "\n- " . implode("\n- ", $linhas) . "\n");
            exit(1);
        }
        http_response_code($codigo);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-Robots-Tag: noindex, nofollow');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
        $itens = implode('', array_map(static fn(string $l): string => '<li>' . e($l) . '</li>', $linhas));
        echo '<!doctype html><html lang="', e(Idioma::html()), '"><head><meta charset="utf-8">',
            '<meta name="viewport" content="width=device-width, initial-scale=1"><title>MyMonetto</title>',
            '<style>body{font:16px/1.5 system-ui,sans-serif;margin:0;padding:3rem 1.25rem;color:#1f2328;background:#f4f5f7}',
            'main{max-width:36rem;margin:auto}h1{font-size:1.4rem}li{margin:.5rem 0;overflow-wrap:anywhere}',
            '@media(prefers-color-scheme:dark){body{color:#e8eaed;background:#0a0b0f}}</style></head><body><main><h1>',
            e($titulo), '</h1><ul>', $itens, '</ul></main></body></html>';
        exit;
    }

    private static function pastaGravavel(string $pasta): bool
    {
        if (!is_dir($pasta) && !@mkdir($pasta, 0700, true) && !is_dir($pasta)) {
            return false;
        }
        return is_writable($pasta);
    }
}
