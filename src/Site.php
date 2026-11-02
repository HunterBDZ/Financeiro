<?php
namespace Financeiro;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Financeiro\Models\AjusteModel;
use PDO;
use PDOException;

/**
 * A moeda e o fuso do site. A tabela `ajustes` só guarda números: a moeda vai pelo código numérico da ISO 4217
 * e o fuso pelo crc32 do nome. Sem o ajuste, valem real e Brasília, então quem já usava o app não muda nada.
 * Os dois são lidos juntos, numa consulta só, logo que o banco conecta.
 */
final class Site
{
    public const MOEDA_PADRAO = 'BRL';
    public const FUSO_PADRAO = 'America/Sao_Paulo';

    /** Código => número da ISO 4217. */
    public const MOEDAS = ['BRL' => 986, 'USD' => 840, 'EUR' => 978, 'GBP' => 826, 'ARS' => 32, 'MXN' => 484, 'CLP' => 152, 'COP' => 170, 'PEN' => 604, 'UYU' => 858];

    private static ?string $moeda = null;
    private static ?string $fuso = null;

    /** Chamado por Database ao conectar. Banco ainda sem as tabelas (na instalação): ficam os padrões. */
    public static function carregar(PDO $pdo): void
    {
        $ajustes = [];
        try {
            foreach ($pdo->query("SELECT chave, valor FROM ajustes WHERE chave IN ('moeda', 'fuso')") as $r) {
                $ajustes[$r['chave']] = (int) round((float) $r['valor']);
            }
        } catch (PDOException) {
            $ajustes = [];
        }
        self::$moeda = array_search($ajustes['moeda'] ?? 0, self::MOEDAS, true) ?: self::MOEDA_PADRAO;
        self::aplicarFuso($pdo, self::fusoDoNumero($ajustes['fuso'] ?? null));
    }

    public static function moeda(): string
    {
        if (self::$moeda === null) {
            Database::connection();
        }
        return self::$moeda ?? self::MOEDA_PADRAO;
    }

    public static function fuso(): string
    {
        if (self::$fuso === null) {
            Database::connection();
        }
        return self::$fuso ?? self::FUSO_PADRAO;
    }

    public static function moedaValida(string $moeda): bool
    {
        return isset(self::MOEDAS[$moeda]);
    }

    public static function fusoValido(string $fuso): bool
    {
        return in_array($fuso, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);
    }

    /** Vale para o resto desta requisição, sem gravar. */
    public static function usarMoeda(string $moeda): void
    {
        self::$moeda = self::moedaValida($moeda) ? $moeda : self::MOEDA_PADRAO;
    }

    /** Vale para o resto desta requisição, sem gravar: no PHP e na conexão com o banco. */
    public static function usarFuso(string $fuso): void
    {
        self::aplicarFuso(Database::connection(), self::fusoValido($fuso) ? $fuso : self::FUSO_PADRAO);
    }

    public static function salvarMoeda(string $moeda): void
    {
        AjusteModel::setValor('moeda', (float) self::MOEDAS[$moeda]);
        self::usarMoeda($moeda);
    }

    public static function salvarFuso(string $fuso): void
    {
        AjusteModel::setValor('fuso', (float) crc32($fuso));
        self::usarFuso($fuso);
    }

    /** O fuso já foi escolhido alguma vez? Serve para o navegador sugerir só na primeira configuração. */
    public static function fusoGravado(): bool
    {
        return AjusteModel::valor('fuso') > 0;
    }

    /** O nome do fuso guardado; o que não existe mais (ou nunca foi gravado) vale Brasília. */
    public static function fusoDoNumero(?int $numero): string
    {
        if ($numero === null || $numero === crc32(self::FUSO_PADRAO)) {
            return self::FUSO_PADRAO;
        }
        foreach (DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC) as $nome) {
            if (crc32($nome) === $numero) {
                return $nome;
            }
        }
        return self::FUSO_PADRAO;
    }

    /** `-03:00`, `+05:30`: o deslocamento em vigor no instante pedido (agora, por padrão), com o horário de verão. */
    public static function deslocamento(string $fuso, ?DateTimeInterface $quando = null): string
    {
        $seg = (new DateTimeZone($fuso))->getOffset($quando ?? new DateTimeImmutable());
        return ($seg < 0 ? '-' : '+') . sprintf('%02d:%02d', intdiv(abs($seg), 3600), intdiv(abs($seg) % 3600, 60));
    }

    /**
     * Para o <select>: nome => "America/Sao Paulo (UTC-03:00)". O fuso atual entra mesmo sendo um nome antigo.
     * @return array<string, string>
     */
    public static function opcoesFuso(): array
    {
        $nomes = DateTimeZone::listIdentifiers();
        if (!in_array(self::fuso(), $nomes, true)) {
            $nomes[] = self::fuso();
        }
        $agora = new DateTimeImmutable();
        $out = [];
        foreach ($nomes as $nome) {
            $out[$nome] = str_replace('_', ' ', $nome) . ' (UTC' . self::deslocamento($nome, $agora) . ')';
        }
        return $out;
    }

    /**
     * Para o <select>: código => "Real brasileiro (R$)".
     * @return array<string, string>
     */
    public static function opcoesMoeda(): array
    {
        $nomes = [
            'BRL' => t('Real brasileiro'), 'USD' => t('Dólar americano'), 'EUR' => t('Euro'), 'GBP' => t('Libra esterlina'),
            'ARS' => t('Peso argentino'), 'MXN' => t('Peso mexicano'), 'CLP' => t('Peso chileno'), 'COP' => t('Peso colombiano'),
            'PEN' => t('Sol peruano'), 'UYU' => t('Peso uruguaio'),
        ];
        $out = [];
        foreach ($nomes as $cod => $nome) {
            $out[$cod] = $nome . ' (' . Services\Cotacao::MOEDAS[$cod][1] . ')';
        }
        return $out;
    }

    private static function aplicarFuso(PDO $pdo, string $fuso): void
    {
        self::$fuso = $fuso;
        date_default_timezone_set($fuso);
        // o relógio do banco acompanha o do PHP; pelo deslocamento, porque as tabelas de fuso do MySQL podem estar vazias
        Sql::fusoDaConexao($pdo, self::deslocamento($fuso));
    }
}
