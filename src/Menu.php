<?php
namespace Financeiro;

use Financeiro\Models\UsuarioModel;

/**
 * As abas do menu e a ordem de cada conta. A pessoa reorganiza o menu lateral (e a lista do botão Menu, no celular)
 * como os ícones do celular; a ordem fica em usuarios.menu_ordem, um JSON com as chaves, e NULL é a ordem padrão.
 *
 * - Chave que saiu do app é ignorada. Aba nova, ou que não está na lista, entra no fim, na ordem padrão entre elas.
 * - Aba escondida por Ajustes (cartão, empréstimo) continua na lista e guarda o lugar: ao voltar, volta onde estava.
 * - O "+ Lançar" fica sempre no topo e não entra aqui. A barra de baixo do celular é fixa.
 * - Temas e Ajuda não são abas: Temas abre por Ajustes, e a Ajuda pelo "?" das telas e por Ajustes.
 */
final class Menu
{
    /** A ordem padrão. A chave é a mesma de $active no layout. */
    public const CHAVES = ['inicio', 'extrato', 'mes', 'cartao', 'meta', 'relatorio', 'simular', 'emprestimo', 'investimentos', 'wishlist', 'ajustes'];

    /**
     * O grupo de cada aba no menu lateral: o dia a dia, planejar e o resto. Os grupos se separam por espaço, sem
     * título; na ordem da pessoa, o espaço aparece onde o grupo muda.
     */
    public const GRUPOS = [
        'inicio' => 0, 'extrato' => 0, 'mes' => 0, 'cartao' => 0,
        'meta' => 1, 'relatorio' => 1, 'simular' => 1,
        'emprestimo' => 2, 'investimentos' => 2, 'wishlist' => 2, 'ajustes' => 2,
    ];

    /** @return array<string, array{0:string, 1:string, 2:string}> chave => [href, rótulo, ícone], na ordem padrão */
    public static function abas(): array
    {
        return [
            'inicio'  => ['/', t('Início'), 'home'],
            'extrato' => ['/lancamentos', t('Extrato'), 'list'],
            'mes'     => ['/mes', t('Mês'), 'calendar'],
            'cartao'  => ['/cartao', t('Cartão'), 'cartao'],
            'meta'    => ['/meta', t('Metas'), 'target'],
            'relatorio' => ['/relatorio', t('Relatório'), 'relatorio'],
            'simular' => ['/futuro', t('Simular'), 'trend'],
            'emprestimo' => ['/emprestimo', t('Empréstimo'), 'emprestimo'],
            'investimentos' => ['/investimentos', t('Investimentos'), 'invest'],
            'wishlist' => ['/wishlist', t('Wishlist'), 'gift'],
            'ajustes' => ['/ajustes', t('Ajustes'), 'settings'],
        ];
    }

    /**
     * A ordem completa a partir da guardada: as chaves conhecidas na ordem dela, sem repetir; as que faltam no fim,
     * na ordem padrão.
     * @param list<mixed>|null $guardada
     * @return list<string>
     */
    public static function ordenar(?array $guardada): array
    {
        $saida = [];
        foreach ($guardada ?? [] as $chave) {
            if (is_string($chave) && in_array($chave, self::CHAVES, true) && !in_array($chave, $saida, true)) {
                $saida[] = $chave;
            }
        }
        foreach (self::CHAVES as $chave) {
            if (!in_array($chave, $saida, true)) {
                $saida[] = $chave;
            }
        }
        return $saida;
    }

    /** O JSON do banco; o que não é uma lista vale como ordem padrão. @return list<mixed>|null */
    public static function ler(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }
        $lista = json_decode($json, true);
        return is_array($lista) && array_is_list($lista) ? $lista : null;
    }

    /**
     * A lista que veio do navegador: só chaves conhecidas, sem repetir e pelo menos uma. Senão, null (recusa).
     * @return list<string>|null
     */
    public static function validar(mixed $pedido): ?array
    {
        if (!is_array($pedido) || !array_is_list($pedido) || $pedido === [] || count($pedido) > count(self::CHAVES)) {
            return null;
        }
        foreach ($pedido as $chave) {
            if (!is_string($chave) || !in_array($chave, self::CHAVES, true)) {
                return null;
            }
        }
        return count(array_unique($pedido)) === count($pedido) ? $pedido : null;
    }

    /**
     * A ordem nova quando a pessoa reorganiza só parte das abas (as que estão à vista naquela lista): as que ela
     * mexeu ocupam, na ordem nova, os lugares que já eram delas; as outras ficam onde estavam.
     * @param list<string> $atual a ordem completa de agora
     * @param list<string> $nova as chaves à vista, na ordem nova
     * @return list<string>
     */
    public static function mesclar(array $atual, array $nova): array
    {
        $fila = $nova;
        $saida = [];
        foreach ($atual as $chave) {
            $saida[] = in_array($chave, $nova, true) ? array_shift($fila) : $chave;
        }
        return $saida;
    }

    /** A ordem da conta; sem conta, ou se o banco não responder, a padrão. @return list<string> */
    public static function daConta(?int $usuarioId): array
    {
        if ($usuarioId === null) {
            return self::CHAVES;
        }
        try {
            return self::ordenar(self::ler(UsuarioModel::menuOrdem($usuarioId)));
        } catch (\Throwable) {
            return self::CHAVES;
        }
    }

    /** Grava a ordem da conta; igual à padrão, ou null, volta a ser NULL. @param list<string>|null $ordem */
    public static function salvar(int $usuarioId, ?array $ordem): void
    {
        $ordem = $ordem === null ? null : self::ordenar($ordem);
        UsuarioModel::salvarMenuOrdem($usuarioId, $ordem === null || $ordem === self::CHAVES ? null : json_encode($ordem));
    }
}
