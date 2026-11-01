<?php
namespace Financeiro;

use Financeiro\Models\UsuarioModel;

/** Os temas do app (lista gerada em temas_lista.php) e o tema de quem está logado. */
final class Temas
{
    private static ?array $lista = null;

    /** @return array<string, array> id => dados do tema, na ordem de exibição */
    public static function todos(): array
    {
        return self::$lista ??= require __DIR__ . '/temas_lista.php';
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
