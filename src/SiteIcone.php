<?php
namespace Financeiro;

/** O ícone do site: o que foi enviado em Ajustes ou, sem nenhum, a imagem padrão. Vale para todas as contas. */
final class SiteIcone
{
    public const PADRAO = '/assets/img/marca.svg';

    private static ?array $atual = null;
    private static bool $lido = false;

    /** @return ?array{tipo:string, hash:string} null = imagem padrão, inclusive se o banco falhar */
    public static function personalizado(): ?array
    {
        if (!self::$lido) {
            self::$lido = true;
            try {
                $l = Database::connection()->query('SELECT tipo, hash FROM site_icone WHERE id = 1')->fetch(\PDO::FETCH_ASSOC);
                self::$atual = $l === false ? null : ['tipo' => (string) $l['tipo'], 'hash' => (string) $l['hash']];
            } catch (\Throwable) {
                self::$atual = null;
            }
        }
        return self::$atual;
    }

    public static function url(): string
    {
        $i = self::personalizado();
        return $i !== null ? '/icone?v=' . $i['hash'] : asset(self::PADRAO);
    }

    /** @return ?array{tipo:string, hash:string, dados:string} */
    public static function arquivo(): ?array
    {
        $l = Database::connection()->query('SELECT tipo, hash, dados FROM site_icone WHERE id = 1')->fetch(\PDO::FETCH_ASSOC);
        return $l === false ? null : $l;
    }

    public static function salvar(string $tipo, string $dados): void
    {
        $st = Database::connection()->prepare('REPLACE INTO site_icone (id, tipo, hash, dados) VALUES (1, ?, ?, ?)');
        $st->bindValue(1, $tipo);
        $st->bindValue(2, sha1($dados));
        $st->bindValue(3, $dados, \PDO::PARAM_LOB);
        $st->execute();
        self::$lido = false;
    }

    public static function remover(): void
    {
        Database::connection()->exec('DELETE FROM site_icone');
        self::$lido = false;
    }

    /** As tags de ícone do <head>. */
    public static function tags(): string
    {
        if (self::personalizado() !== null) {
            $u = e(self::url());
            return '<link rel="icon" href="' . $u . '">' . "\n" . '<link rel="apple-touch-icon" href="' . $u . '">' . "\n";
        }
        return '<link rel="icon" type="image/png" sizes="32x32" href="' . e(asset('/assets/img/icone-32.png')) . '">' . "\n"
            . '<link rel="icon" type="image/png" sizes="64x64" href="' . e(asset('/assets/img/icone-64.png')) . '">' . "\n"
            . '<link rel="apple-touch-icon" href="' . e(asset('/assets/img/icone-180.png')) . '">' . "\n";
    }
}
