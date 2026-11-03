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

    /**
     * Na hora da máquina, de propósito: quem liga e quem confere podem ser processos diferentes. A hora vai escrita
     * dentro do arquivo; o financeiro-atualizar só cria o arquivo vazio, e aí vale a data dele.
     */
    public static function ativa(): bool
    {
        $a = self::arquivo();
        if ($a === null || !is_file($a)) {
            return false;
        }
        $escrita = trim((string) @file_get_contents($a));
        $desde = ctype_digit($escrita) ? (int) $escrita : (int) @filemtime($a);
        return time() - $desde < self::VALIDADE;
    }

    public static function exigir(): void
    {
        if (self::ativa()) {
            header('Retry-After: 5');
            header('Refresh: 5');
            Preflight::tela(503, t('Atualizando o MyMonetto'), [t('Isso costuma levar menos de um minuto. Esta página abre sozinha quando terminar.')]);
        }
    }
}
