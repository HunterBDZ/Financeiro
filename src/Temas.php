<?php
namespace Financeiro;

use Financeiro\Models\UsuarioModel;

/**
 * Os temas do app (lista gerada em temas_lista.php, por tools/temas/gerar.py) e o tema de quem está logado.
 * Cada paleta (a padrão, 5 da casa e as de acessibilidade) tem três temas: claro, escuro e automático, que segue o
 * aparelho. O automático da padrão não tem id: é a conta sem tema (null).
 */
final class Temas
{
    /** O fundo da paleta padrão, para a barra do navegador quando não há tema escolhido. */
    public const FUNDO_CLARO = '#F6F8FB';
    public const FUNDO_ESCURO = '#0A1020';

    private static ?array $lista = null;

    /** @return array<string, array> id => dados do tema, na ordem de exibição */
    public static function todos(): array
    {
        return self::$lista ??= require __DIR__ . '/temas_lista.php';
    }

    /**
     * As paletas, na ordem de exibição, com os ids dos três modos ('light', 'dark', 'auto'; o auto da padrão é null).
     * @return array<string, array{nome:string, grupo:string, ids:array<string, ?string>, claro:array, escuro:array}>
     */
    public static function paletas(): array
    {
        $saida = [];
        foreach (self::todos() as $id => $t) {
            $p = (string) $t['paleta'];
            $saida[$p] ??= ['nome' => (string) $t['nome'], 'grupo' => (string) $t['grupo'], 'ids' => ['light' => null, 'dark' => null, 'auto' => null], 'claro' => $t['claro'], 'escuro' => $t['escuro']];
            $saida[$p]['ids'][(string) $t['modo']] = $id;
        }
        return $saida;
    }

    /**
     * Os dois lados (claro, escuro) da paleta do tema; null = a padrão. Null quando o id não existe.
     * @return ?array{0:string, 1:string}
     */
    public static function lados(?string $id): ?array
    {
        if ($id === null) {
            return ['claro', 'escuro'];
        }
        $t = self::todos()[$id] ?? null;
        if ($t === null) {
            return null;
        }
        $ids = self::paletas()[(string) $t['paleta']]['ids'];
        return [(string) $ids['light'], (string) $ids['dark']];
    }

    /** O nome para a pessoa: "Calçadão, escuro"; a padrão é só "Claro", "Escuro" ou "Automático" (null). */
    public static function nome(?string $id): string
    {
        $t = $id !== null ? (self::todos()[$id] ?? null) : null;
        if ($t === null) {
            return t('Automático');
        }
        $modo = match ((string) $t['modo']) {
            'dark' => t('escuro'),
            'auto' => t('automático'),
            default => t('claro'),
        };
        if ($t['paleta'] === 'padrao') {
            return mb_strtoupper(mb_substr($modo, 0, 1)) . mb_substr($modo, 1);
        }
        // i18n: t('Padrão') t('Calçadão') t('Azulejo') t('Papel') t('Ameixa') t('Petróleo') t('Alto contraste') t('Daltônico')
        return t('{tema}, {modo}', ['tema' => t((string) $t['nome']), 'modo' => $modo]);
    }

    public static function existe(string $id): bool
    {
        return isset(self::todos()[$id]);
    }

    /** O tema da conta, ou null quando é automático ou não há ninguém logado. Um id que saiu da lista vira automático. */
    public static function doUsuario(): ?string
    {
        $id = Auth::id();
        if ($id === null) {
            return null;
        }
        $tema = UsuarioModel::tema($id);
        return $tema !== null && self::existe($tema) ? $tema : null;
    }
}
