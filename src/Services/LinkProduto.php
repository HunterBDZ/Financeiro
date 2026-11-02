<?php
namespace Financeiro\Services;

use Financeiro\Config;
use Financeiro\Paths;
use Financeiro\Site;

/**
 * Lê o nome, o preço e a foto da página de um produto. Quem manda o endereço é a pessoa, então o
 * servidor só fala com endereços públicos: esquema e porta permitidos, todo IP resolvido é conferido, a
 * conexão vai para o IP conferido (sem nova consulta de DNS) e cada redirecionamento passa pela mesma
 * checagem. A foto é validada pelo conteúdo, não pelo que a loja diz que ela é.
 * Sem as extensões curl ou dom, ou se a loja não responder, o item é preenchido à mão.
 */
final class LinkProduto
{
    public const MAX_FOTO = 3145728;     // 3 MB
    private const MAX_HTML = 3145728;
    private const REDIRECTS = 3;
    private const AGENTE = 'Mozilla/5.0 (compatible; FinanceiroWishlist/1.0)';
    private const TIPOS_FOTO = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp'];

    /**
     * O endereço limpo, ou null. Aceita o texto do "compartilhar" do celular, que traz nome e link juntos,
     * e endereço sem https://.
     */
    public static function normalizar(string $texto): ?string
    {
        $texto = trim($texto);
        if (preg_match('~https?://[^\s<>"\']+~i', $texto, $m)) {
            $url = $m[0];
        } elseif (preg_match('~^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}(/[^\s<>"\']*)?$~i', $texto)) {
            $url = 'https://' . $texto;
        } else {
            return null;
        }
        if (strlen($url) > 2048 || str_contains($url, '\\') || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return null;
        }
        $p = parse_url($url);
        if (!is_array($p) || !isset($p['scheme'], $p['host']) || isset($p['user']) || isset($p['pass'])) {
            return null;
        }
        if (!in_array(strtolower($p['scheme']), ['http', 'https'], true) || !in_array($p['port'] ?? 0, [0, 80, 443], true)) {
            return null;
        }
        return $url;
    }

    /** O host do endereço, sem www, para mostrar de onde é o item. */
    public static function loja(?string $url): string
    {
        $host = is_string($url) ? (string) parse_url($url, PHP_URL_HOST) : '';
        return (string) preg_replace('/^www\./i', '', $host);
    }

    /** True só para IPv4 público. */
    public static function ipPublico(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        $n = ip2long($ip);
        foreach ([['100.64.0.0', 10], ['192.0.0.0', 24], ['198.18.0.0', 15], ['224.0.0.0', 3]] as [$rede, $bits]) {
            $mascara = -1 << (32 - $bits);
            if (($n & $mascara) === (ip2long($rede) & $mascara)) {
                return false;
            }
        }
        return true;
    }

    /** O IP para conectar, se todos os resolvidos forem públicos; senão null. */
    private static function resolver(string $host): ?string
    {
        $ips = gethostbynamel($host);
        if ($ips === false || $ips === []) {
            return null;
        }
        foreach ($ips as $ip) {
            if (!self::ipPublico($ip)) {
                return null;
            }
        }
        return $ips[0];
    }

    /**
     * Baixa um endereço público, seguindo redirecionamentos com a mesma checagem.
     * @return ?array{status:int, tipo:string, corpo:string, url:string, cortado:bool}
     */
    public static function buscar(string $url, int $maximo, int $segundos = 8): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        for ($salto = 0; $salto <= self::REDIRECTS; $salto++) {
            $limpa = self::normalizar($url);
            if ($limpa === null || !preg_match('~^https?://~i', $url)) {
                return null;
            }
            $host = (string) parse_url($limpa, PHP_URL_HOST);
            $esquema = strtolower((string) parse_url($limpa, PHP_URL_SCHEME));
            $porta = (int) (parse_url($limpa, PHP_URL_PORT) ?: ($esquema === 'https' ? 443 : 80));
            $ip = self::resolver($host);
            if ($ip === null) {
                return null;
            }
            $corpo = '';
            $cortado = false;
            $c = curl_init($limpa);
            curl_setopt_array($c, [
                CURLOPT_RESOLVE => [$host . ':' . $porta . ':' . $ip],
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_PROTOCOLS_STR => 'http,https',
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => $segundos,
                CURLOPT_USERAGENT => self::AGENTE,
                CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,image/avif,image/webp,image/*;q=0.8,*/*;q=0.5', 'Accept-Language: pt-BR,pt;q=0.9'],
                CURLOPT_ENCODING => '',
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_WRITEFUNCTION => static function ($h, string $parte) use (&$corpo, &$cortado, $maximo): int {
                    if (strlen($corpo) + strlen($parte) > $maximo) {
                        $cortado = true;
                        return 0;
                    }
                    $corpo .= $parte;
                    return strlen($parte);
                },
            ]);
            $ca = self::arquivoCa();
            if ($ca !== null) {
                curl_setopt($c, CURLOPT_CAINFO, $ca);
            }
            $ok = curl_exec($c);
            $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
            $tipo = (string) curl_getinfo($c, CURLINFO_CONTENT_TYPE);
            $proximo = (string) curl_getinfo($c, CURLINFO_REDIRECT_URL);
            if ($ok === false && !$cortado) {
                return null;
            }
            if ($status >= 300 && $status < 400 && $proximo !== '') {
                $url = $proximo;
                continue;
            }
            return ['status' => $status, 'tipo' => strtolower($tipo), 'corpo' => $corpo, 'url' => $limpa, 'cortado' => $cortado];
        }
        return null;
    }

    /**
     * Lê o que der da página.
     * @return array{nome:?string, valor:?float, moeda:string, foto:?array, lido:bool}
     */
    public static function ler(string $url): array
    {
        $vazio = ['nome' => null, 'valor' => null, 'moeda' => Site::moeda(), 'foto' => null, 'lido' => false];
        $r = self::buscar($url, self::MAX_HTML);
        if ($r === null || $r['status'] !== 200 || !str_contains($r['tipo'] . 'html', 'html') || !class_exists(\DOMDocument::class)) {
            return $vazio;
        }
        $m = self::metadados($r['corpo'], $r['url']);
        $foto = null;
        if ($m['imagem'] !== null) {
            $f = self::buscar($m['imagem'], self::MAX_FOTO);
            if ($f !== null && $f['status'] === 200 && !$f['cortado']) {
                $foto = self::fotoValida($f['corpo']);
            }
        }
        return ['nome' => $m['nome'], 'valor' => $m['valor'], 'moeda' => $m['moeda'], 'foto' => $foto, 'lido' => $m['nome'] !== null || $m['valor'] !== null || $foto !== null];
    }

    /**
     * Nome, preço e imagem de um HTML: dados de produto (JSON-LD), depois as etiquetas de compartilhamento.
     * Preço em moeda que o app não tem fica de fora.
     * @return array{nome:?string, valor:?float, moeda:string, imagem:?string}
     */
    public static function metadados(string $html, string $base): array
    {
        $doc = new \DOMDocument();
        $antes = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($antes);
        $xp = new \DOMXPath($doc);

        $meta = [];
        foreach ($xp->query('//meta') as $el) {
            /** @var \DOMElement $el */
            $chave = strtolower($el->getAttribute('property') ?: $el->getAttribute('name') ?: $el->getAttribute('itemprop'));
            if ($chave !== '' && !isset($meta[$chave])) {
                $meta[$chave] = trim($el->getAttribute('content'));
            }
        }
        $produto = null;
        foreach ($xp->query('//script[@type="application/ld+json"]') as $s) {
            $j = json_decode(trim((string) $s->textContent), true);
            if (is_array($j)) {
                $produto = self::acharProduto($j);
                if ($produto !== null) {
                    break;
                }
            }
        }

        $nome = null;
        foreach ([$produto['name'] ?? null, $meta['og:title'] ?? null, $meta['twitter:title'] ?? null, $xp->evaluate('string(//title)')] as $n) {
            if (is_string($n) && trim($n) !== '') {
                $nome = mb_substr(trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($n, ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 0, 160);
                break;
            }
        }

        $valor = null;
        $moedaLida = Site::moeda();
        // devolve a moeda, ou null se o app não a tem; sem moeda declarada, vale a do site
        $moedaDe = static function (mixed $m): ?string {
            $c = strtoupper(trim((string) $m));
            $c = $c === '' ? Site::moeda() : $c;
            return Cotacao::valida($c) ? $c : null;
        };
        if ($produto !== null) {
            // ProductGroup: o preço fica na 1ª variação
            $variante = is_array($produto['hasVariant'] ?? null) ? ($produto['hasVariant'][0] ?? null) : null;
            $oferta = $produto['offers'] ?? (is_array($variante) ? ($variante['offers'] ?? null) : null);
            if (is_array($oferta) && array_is_list($oferta)) {
                $oferta = $oferta[0] ?? null;
            }
            if (is_array($oferta) && $moedaDe($oferta['priceCurrency'] ?? '') !== null) {
                $moedaLida = (string) $moedaDe($oferta['priceCurrency'] ?? '');
                $especificacao = is_array($oferta['priceSpecification'] ?? null) ? ($oferta['priceSpecification']['price'] ?? null) : null;
                $valor = self::preco($oferta['price'] ?? $oferta['lowPrice'] ?? $especificacao);
            }
        }
        foreach ([['product:price:amount', 'product:price:currency'], ['og:price:amount', 'og:price:currency'], ['price', 'pricecurrency']] as [$p, $moeda]) {
            if ($valor === null && isset($meta[$p]) && $moedaDe($meta[$moeda] ?? '') !== null) {
                $valor = self::preco($meta[$p]);
                $moedaLida = $valor !== null ? (string) $moedaDe($meta[$moeda] ?? '') : $moedaLida;
            }
        }

        // sem dados estruturados: a marcação usual das páginas de produto grandes
        if ($valor === null) {
            $moedaLida = Site::moeda();
            $valor = self::preco(trim((string) $xp->evaluate('string((//*[starts-with(@id,"corePrice")]//span[contains(concat(" ",@class," ")," a-offscreen ")])[1])')));
        }
        if ($valor === null) {
            $valor = self::preco(trim((string) $xp->evaluate('string((//*[@itemprop="price"]/@content)[1])')));
        }

        $imagem = null;
        $jsonImg = $produto['image'] ?? null;
        if (is_array($jsonImg)) {
            $jsonImg = $jsonImg['url'] ?? ($jsonImg[0] ?? null);
            $jsonImg = is_array($jsonImg) ? ($jsonImg['url'] ?? null) : $jsonImg;
        }
        foreach ([$jsonImg, $meta['og:image:secure_url'] ?? null, $meta['og:image'] ?? null, $meta['twitter:image'] ?? null, $xp->evaluate('string(//link[@rel="image_src"]/@href)'), $xp->evaluate('string(//img[@data-old-hires]/@data-old-hires)'), $xp->evaluate('string(//img[@itemprop="image"]/@src)')] as $i) {
            if (is_string($i) && trim($i) !== '') {
                $imagem = self::absoluta(trim($i), $base);
                if ($imagem !== null) {
                    break;
                }
            }
        }
        return ['nome' => $nome, 'valor' => $valor, 'moeda' => $valor !== null ? $moedaLida : Site::moeda(), 'imagem' => $imagem];
    }

    /** Procura um Product em qualquer nível do JSON-LD (listas e @graph). */
    private static function acharProduto(array $j): ?array
    {
        $tipo = $j['@type'] ?? null;
        if (in_array($tipo, ['Product', 'ProductGroup'], true) || (is_array($tipo) && array_intersect(['Product', 'ProductGroup'], $tipo) !== [])) {
            return $j;
        }
        foreach (array_is_list($j) ? $j : ($j['@graph'] ?? []) as $filho) {
            if (is_array($filho) && ($p = self::acharProduto($filho)) !== null) {
                return $p;
            }
        }
        return null;
    }

    /** "1299.90", 1299.9, "1.299,90" ou "R$ 1.299,90" => 1299.90; o resto, null. */
    public static function preco(mixed $bruto): ?float
    {
        if (is_int($bruto) || is_float($bruto)) {
            $v = (float) $bruto;
        } elseif (is_string($bruto) && preg_match('/^\d+(\.\d{1,2})?$/', trim($bruto))) {
            $v = (float) trim($bruto);
        } elseif (is_string($bruto) && preg_match('/^(R\$)?[\s\d.,]+$/u', trim($bruto))) {
            $v = parseMoedaBr($bruto);
        } else {
            return null;
        }
        return $v !== null && $v > 0 && $v <= valorMaximo() ? round($v, 2) : null;
    }

    private static function absoluta(string $url, string $base): ?string
    {
        if (preg_match('~^https?://~i', $url)) {
            return $url;
        }
        $p = parse_url($base);
        if (!is_array($p) || !isset($p['scheme'], $p['host'])) {
            return null;
        }
        if (str_starts_with($url, '//')) {
            return $p['scheme'] . ':' . $url;
        }
        if (str_starts_with($url, 'data:') || preg_match('~^[a-z][a-z0-9+.-]*:~i', $url)) {
            return null;
        }
        $pasta = str_starts_with($url, '/') ? '' : rtrim(dirname($p['path'] ?? '/'), '/') . '/';
        return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . $pasta . $url;
    }

    /**
     * Confere uma imagem pelo conteúdo: JPEG, PNG ou WebP, até 3 MB.
     * @return ?array{tipo:string, largura:int, altura:int, dados:string}
     */
    public static function fotoValida(string $dados): ?array
    {
        if ($dados === '' || strlen($dados) > self::MAX_FOTO) {
            return null;
        }
        $i = @getimagesizefromstring($dados);
        if ($i === false || !isset(self::TIPOS_FOTO[$i[2]]) || $i[0] < 1 || $i[1] < 1) {
            return null;
        }
        return ['tipo' => self::TIPOS_FOTO[$i[2]], 'largura' => (int) $i[0], 'altura' => (int) $i[1], 'dados' => $dados];
    }

    /** Certificados raiz para o curl: o da configuração ou o que vai junto no pacote. Sem nenhum, vale o do sistema. */
    private static function arquivoCa(): ?string
    {
        foreach ([(string) Config::get('CA_ARQUIVO', ''), Paths::raiz() . '/packaging/cacert.pem'] as $arquivo) {
            if ($arquivo !== '' && is_readable($arquivo)) {
                return $arquivo;
            }
        }
        return null;
    }
}
