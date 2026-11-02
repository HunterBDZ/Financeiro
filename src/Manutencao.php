<?php
namespace Financeiro;

/** Enquanto uma atualização mexe no banco e no programa, as páginas esperam. */
final class Manutencao
{
    /** Passado disso, o processo que ligou morreu: o site não fica preso. */
    private const VALIDADE = 1200;

    public static function arquivo(): ?string
    {
        return Paths::dados() === null ? null : Paths::dados() . '/manutencao';
    }

    public static function ligar(): void
    {
        file_put_contents((string) self::arquivo(), (string) time());
    }

    public static function desligar(): void
    {
        @unlink((string) self::arquivo());
    }

    public static function ativa(): bool
    {
        $a = self::arquivo();
        return $a !== null && is_file($a) && time() - (int) filemtime($a) < self::VALIDADE;
    }

    public static function exigir(): void
    {
        if (self::ativa()) {
            header('Retry-After: 5');
            header('Refresh: 5');
            Preflight::tela(503, t('Atualizando o Financeiro'), [t('Isso costuma levar menos de um minuto. Esta página abre sozinha quando terminar.')]);
        }
    }
}
