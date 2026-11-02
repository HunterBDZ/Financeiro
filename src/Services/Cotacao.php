<?php
namespace Financeiro\Services;

use Financeiro\Sql;
use Financeiro\Database;
use Financeiro\Site;

/**
 * Moedas da Wishlist e a conversão para a moeda do site. A fonte dá tudo em reais, então as outras moedas
 * se cruzam pelo real (USD => EUR = USD => BRL => EUR). A cotação fica guardada na tabela `cotacoes` e é renovada no
 * máximo a cada 2 horas; se as fontes falharem, vale a última guardada, sempre com a data à vista. Em fim de
 * semana e feriado, a data é a do último fechamento. É a cotação comercial, sem IOF nem taxa do cartão.
 */
final class Cotacao
{
    /** Código => [nome, símbolo]. Todas têm cotação na fonte principal. */
    public const MOEDAS = [
        'BRL' => ['Real', 'R$'], 'USD' => ['Dólar', 'US$'], 'EUR' => ['Euro', '€'], 'GBP' => ['Libra', '£'],
        'CAD' => ['Dólar canadense', 'C$'], 'AUD' => ['Dólar australiano', 'A$'], 'CHF' => ['Franco suíço', 'CHF'],
        'JPY' => ['Iene', '¥'], 'CNY' => ['Yuan', 'CN¥'], 'ARS' => ['Peso argentino', 'ARS'], 'CLP' => ['Peso chileno', 'CLP'],
        'UYU' => ['Peso uruguaio', 'UYU'], 'MXN' => ['Peso mexicano', 'MXN'], 'COP' => ['Peso colombiano', 'COP'], 'PEN' => ['Sol peruano', 'S/'],
    ];
    private const HORAS = 2;
    private const MAX_RESPOSTA = 262144;

    public static function valida(string $moeda): bool
    {
        return isset(self::MOEDAS[$moeda]);
    }

    /** "US$ 1.299,00"; no formato do idioma. */
    public static function formatar(float $valor, string $moeda): string
    {
        return (self::MOEDAS[$moeda][1] ?? $moeda) . NBSP . num($valor);
    }

    /**
     * O valor na moeda do site, ou null sem cotação.
     * @param array<string, array{valor:float, data:string}> $taxas
     */
    public static function naMoedaDoSite(float $valor, string $moeda, array $taxas): ?float
    {
        if ($moeda === Site::moeda()) {
            return $valor;
        }
        $taxa = self::taxaParaSite($moeda, $taxas);
        return $taxa !== null ? round($valor * $taxa, 2) : null;
    }

    /**
     * Quanto vale 1 unidade de $moeda na moeda do site, ou null sem cotação de uma das duas.
     * @param array<string, array{valor:float, data:string}> $taxas moeda => reais por unidade
     */
    public static function taxaParaSite(string $moeda, array $taxas): ?float
    {
        $reais = static fn(string $m): ?float => $m === 'BRL' ? 1.0 : ($taxas[$m]['valor'] ?? null);
        $de = $reais($moeda);
        $para = $reais(Site::moeda());
        return $de !== null && $para !== null ? $de / $para : null;
    }

    /** A data da cotação usada em taxaParaSite(): a da moeda, ou a da moeda do site quando a outra ponta é o real. */
    public static function dataDe(string $moeda, array $taxas): string
    {
        return (string) ($taxas[$moeda]['data'] ?? $taxas[Site::moeda()]['data'] ?? '');
    }

    /** @return array<string, array{valor:float, data:string}> moeda => reais por unidade, com a data */
    public static function guardadas(): array
    {
        $out = [];
        foreach (Database::connection()->query("SELECT moeda, valor, data FROM cotacoes WHERE moeda <> 'BRL'") as $r) {
            $out[(string) $r['moeda']] = ['valor' => (float) $r['valor'], 'data' => (string) $r['data']];
        }
        return $out;
    }

    /** As cotações, renovadas se a última tentativa foi há mais de 2 horas. A linha BRL marca essa tentativa. */
    public static function atuais(): array
    {
        $pdo = Database::connection();
        $velha = (int) $pdo->query("SELECT COUNT(*) FROM cotacoes WHERE moeda = 'BRL' AND buscado_em > " . Sql::agoraMais(-self::HORAS, 'HOUR'))->fetchColumn() === 0;
        if ($velha) {
            $pdo->exec("REPLACE INTO cotacoes (moeda, valor, data, buscado_em) VALUES ('BRL', 1, " . Sql::hoje() . ', ' . Sql::agora() . ')');
            $st = $pdo->prepare('REPLACE INTO cotacoes (moeda, valor, data, buscado_em) VALUES (?, ?, ?, ' . Sql::agora() . ')');
            foreach (self::buscar() as $moeda => $t) {
                $st->execute([$moeda, $t['valor'], $t['data']]);
            }
        }
        return self::guardadas();
    }

    /** @return array<string, array{valor:float, data:string}> vazio se as duas fontes falharem */
    private static function buscar(): array
    {
        $pares = [];
        foreach (array_keys(self::MOEDAS) as $m) {
            if ($m !== 'BRL') {
                $pares[] = $m . '-BRL';
            }
        }
        $r = LinkProduto::buscar('https://economia.awesomeapi.com.br/json/last/' . implode(',', $pares), self::MAX_RESPOSTA, 4);
        $t = $r !== null && $r['status'] === 200 ? self::lerPrincipal($r['corpo']) : [];
        if ($t !== []) {
            return $t;
        }
        $r = LinkProduto::buscar('https://api.frankfurter.dev/v1/latest?base=BRL', self::MAX_RESPOSTA, 4);
        return $r !== null && $r['status'] === 200 ? self::lerReserva($r['corpo']) : [];
    }

    /** Fonte principal: {"USDBRL":{"bid":"5.43","create_date":"2026-10-30 19:00:08"}}. */
    public static function lerPrincipal(string $json): array
    {
        $d = json_decode($json, true);
        $out = [];
        foreach (is_array($d) ? $d : [] as $linha) {
            if (!is_array($linha)) {
                continue;
            }
            $m = (string) ($linha['code'] ?? '');
            $v = is_numeric($linha['bid'] ?? null) ? (float) $linha['bid'] : 0.0;
            $data = substr((string) ($linha['create_date'] ?? ''), 0, 10);
            if ($m !== 'BRL' && self::valida($m) && ($linha['codein'] ?? '') === 'BRL' && $v > 0 && self::dataValida($data)) {
                $out[$m] = ['valor' => $v, 'data' => $data];
            }
        }
        return $out;
    }

    /** Fonte reserva, com base em reais: {"date":"2026-10-30","rates":{"USD":0.184}}. Só as moedas que ela tem. */
    public static function lerReserva(string $json): array
    {
        $d = json_decode($json, true);
        $data = is_array($d) ? (string) ($d['date'] ?? '') : '';
        if (!is_array($d) || ($d['base'] ?? '') !== 'BRL' || !self::dataValida($data) || !is_array($d['rates'] ?? null)) {
            return [];
        }
        $out = [];
        foreach ($d['rates'] as $m => $v) {
            if ($m !== 'BRL' && self::valida((string) $m) && is_numeric($v) && (float) $v > 0) {
                $out[(string) $m] = ['valor' => round(1 / (float) $v, 8), 'data' => $data];
            }
        }
        return $out;
    }

    private static function dataValida(string $data): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $data) === 1 && checkdate((int) substr($data, 5, 2), (int) substr($data, 8, 2), (int) substr($data, 0, 4));
    }
}
