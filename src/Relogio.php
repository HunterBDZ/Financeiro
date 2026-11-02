<?php
namespace Financeiro;

use DateTimeImmutable;

/**
 * Que dia é hoje para o app, no fuso do site. Toda regra que depende da data pergunta aqui, e não a date() ou
 * time(): assim os testes fixam o dia e o resultado não muda com a data em que rodam.
 *
 * FIN_RELOGIO='2026-11-22 15:30:00' para o relógio nessa hora, no fuso do site, e ele não anda. Só vale na linha
 * de comando (os testes e o php -S dos testes no navegador); nas páginas servidas pelo Apache ou pelo FrankenPHP,
 * a variável é ignorada. Backups, logs, cookies, travas e esperas continuam na hora da máquina, porque lá importa
 * o tempo que passou de verdade. O banco acompanha: agora_local() do SQLite vem daqui, e no MySQL a conexão recebe
 * SET timestamp (Sql::fusoDaConexao).
 */
final class Relogio
{
    private static ?string $fixo = null;
    private static bool $lido = false;

    /** A hora parada, em 'AAAA-MM-DD HH:MM:SS'; null = o relógio de verdade. */
    public static function fixo(): ?string
    {
        if (!self::$lido) {
            self::$lido = true;
            $valor = getenv('FIN_RELOGIO');
            if (in_array(PHP_SAPI, ['cli', 'cli-server'], true) && is_string($valor) && trim($valor) !== '') {
                self::$fixo = self::ler(trim($valor));
            }
        }
        return self::$fixo;
    }

    /** Agora, no fuso do site. */
    public static function agora(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::fixo() ?? 'now');
    }

    /** Hoje, em 'AAAA-MM-DD'. */
    public static function hoje(): string
    {
        return self::agora()->format('Y-m-d');
    }

    /** O mês de hoje, em 'AAAA-MM-01'. */
    public static function mes(): string
    {
        return self::agora()->format('Y-m-01');
    }

    private static function ler(string $valor): string
    {
        foreach (['!Y-m-d H:i:s', '!Y-m-d H:i', '!Y-m-d'] as $formato) {
            $d = DateTimeImmutable::createFromFormat($formato, $valor);
            if ($d !== false && $d->format(substr($formato, 1)) === $valor) {
                return $d->format('Y-m-d H:i:s');
            }
        }
        throw new \RuntimeException('FIN_RELOGIO inválido: use AAAA-MM-DD HH:MM:SS, como 2026-11-22 15:30:00.');
    }
}
