<?php
namespace Financeiro;

use Financeiro\Models\UsuarioModel;

/**
 * O idioma das telas. O texto em português é a própria chave: o que não tem tradução aparece em português.
 * Vale nesta ordem: o da conta, o cookie (quem não entrou), o do navegador, português.
 */
final class Idioma
{
    public const COOKIE = 'financeiro_idioma';
    public const PADRAO = 'pt';

    /** id => nome no próprio idioma e o código do atributo lang. */
    public const LISTA = [
        'pt' => ['nome' => 'Português', 'html' => 'pt-BR'],
        'en' => ['nome' => 'English', 'html' => 'en'],
        'es' => ['nome' => 'Español', 'html' => 'es'],
    ];

    private const MESES = [
        'pt' => [['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'],
                 ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro']],
        'en' => [['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                 ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']],
        'es' => [['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'],
                 ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre']],
    ];

    private const SEMANA = [
        'pt' => ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'],
        'en' => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
        'es' => ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'],
    ];

    private static ?string $atual = null;
    /** @var array<string, array<string, string>> */
    private static array $dicionarios = [];

    public static function existe(string $id): bool
    {
        return isset(self::LISTA[$id]);
    }

    public static function atual(): string
    {
        return self::$atual ??= self::resolver();
    }

    /** Troca o idioma só desta requisição. */
    public static function usar(?string $id): void
    {
        self::$atual = $id !== null && self::existe($id) ? $id : null;
    }

    public static function html(): string
    {
        return self::LISTA[self::atual()]['html'];
    }

    public static function traduzir(string $texto): string
    {
        $id = self::atual();
        if ($id === self::PADRAO) {
            return $texto;
        }
        self::$dicionarios[$id] ??= require __DIR__ . '/idiomas/' . $id . '.php';
        $traducao = self::$dicionarios[$id][$texto] ?? '';
        return $traducao !== '' ? $traducao : $texto;
    }

    public static function mesCurto(int $n): string
    {
        return self::MESES[self::atual()][0][$n - 1];
    }

    public static function mesExtenso(int $n): string
    {
        return self::MESES[self::atual()][1][$n - 1];
    }

    /** 0 = domingo. */
    public static function diaSemana(int $n): string
    {
        return self::SEMANA[self::atual()][$n];
    }

    /** O idioma guardado na conta; null = ainda não escolheu. */
    public static function daConta(): ?string
    {
        $id = Auth::id();
        if ($id === null) {
            return null;
        }
        $idioma = UsuarioModel::idioma($id);
        return $idioma !== null && self::existe($idioma) ? $idioma : null;
    }

    private static function resolver(): string
    {
        $daConta = self::daConta();
        if ($daConta !== null) {
            return $daConta;
        }
        $cookie = (string) ($_COOKIE[self::COOKIE] ?? '');
        if (self::existe($cookie)) {
            return $cookie;
        }
        return self::doNavegador((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    }

    /** `en-US,en;q=0.9,pt;q=0.8` → en. O primeiro da lista que o app tem. */
    public static function doNavegador(string $cabecalho): string
    {
        foreach (explode(',', $cabecalho) as $parte) {
            $id = strtolower(substr(trim($parte), 0, 2));
            if (self::existe($id)) {
                return $id;
            }
        }
        return self::PADRAO;
    }
}
