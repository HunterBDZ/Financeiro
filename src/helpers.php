<?php
// Funções globais pequenas, usadas nas views e nos controllers. Ficam fora de
// classes de propósito: são formatação de saída e utilidades de requisição, sem
// regra de negócio. Código novo só usa as 6 funções mb_*
// cobertas pelo polyfill local (mb_strlen, mb_substr, mb_strtoupper,
// mb_strtolower, mb_str_pad, mb_convert_case).

const NBSP  = "\u{00A0}";   // espaço que não quebra: "R$ 1,00" nunca quebra de linha
const MENOS = "\u{2212}";   // sinal de menos tipográfico, só em TEXTO (nunca em value de input)

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Junta a versão (mtime do arquivo) na URL do asset, pra nunca servir CSS/JS velho do cache do navegador. */
function asset(string $path): string
{
    $fs = __DIR__ . '/../public' . $path;
    $v = is_file($fs) ? filemtime($fs) : time();
    return $path . '?v=' . $v;
}

/* ------------------------------------------------------------------ dinheiro */

/** Arredonda pra centavos e devolve [negativo?, valor absoluto]; −0,004 vira 0 (sem sinal). */
function _centavos(float $v, int $dec = 2): array
{
    $r = round($v, $dec);
    if ($r == 0.0) {
        return [false, 0.0];
    }
    return [$r < 0, abs($r)];
}

/** O separador decimal do idioma: `,` em pt e es, `.` em en. */
function sepDecimal(): string
{
    return \Financeiro\Idioma::separadores()[0];
}

/** Número no formato do idioma: pt e es `1.234,56`; en `1,234.56`. Toda saída de número passa por aqui. */
function _numero(float $abs, int $dec): string
{
    [$decimal, $milhar] = \Financeiro\Idioma::separadores();
    return number_format($abs, $dec, $decimal, $milhar);
}

/** O símbolo da moeda do site: `R$`, `US$`, `€`. Sem conversão: o valor gravado é o mesmo. */
function moedaSimbolo(): string
{
    return \Financeiro\Services\Cotacao::MOEDAS[\Financeiro\Site::moeda()][1];
}

/** A moeda do site no plural, para as frases: `reais`, `dólares`. */
function moedaPlural(): string
{
    return match (\Financeiro\Site::moeda()) {
        'USD' => t('dólares'),
        'EUR' => t('euros'),
        'GBP' => t('libras'),
        'PEN' => t('soles'),
        'ARS', 'MXN', 'CLP', 'COP', 'UYU' => t('pesos'),
        default => t('reais'),
    };
}

/** O maior valor aceito num campo: moedas de valor unitário baixo precisam de mais espaço (um salário em pesos passa de 1 milhão). */
function valorMaximo(): float
{
    return in_array(\Financeiro\Site::moeda(), ['BRL', 'USD', 'EUR', 'GBP'], true) ? 1000000.0 : 1000000000.0;
}

/** `R$ 1.234,56` (NBSP depois do símbolo); negativo `−R$ 1.234,56` (U+2212). */
function money(float $v): string
{
    [$neg, $abs] = _centavos($v);
    return ($neg ? MENOS : '') . moedaSimbolo() . NBSP . _numero($abs, 2);
}

/** `+R$ 1,00` / `−R$ 1,00` / `R$ 0,00`. */
function moneySigned(float $v): string
{
    [$neg, $abs] = _centavos($v);
    if ($abs === 0.0) {
        return money(0);
    }
    return $neg ? money($v) : '+' . money($v);
}

/** `1.234,56` / `−1.234,56`. Só para TEXTO; em `<input value>` use numInput(). */
function num(float $v, int $dec = 2): string
{
    [$neg, $abs] = _centavos($v, $dec);
    return ($neg ? MENOS : '') . _numero($abs, $dec);
}

/** `1.234,56` / `-1.234,56`: hífen ASCII, sem NBSP. Obrigatório em todo `<input value>` de dinheiro. parseMoedaBr() lê de volta em qualquer idioma. */
function numInput(float $v, int $dec = 2): string
{
    [$neg, $abs] = _centavos($v, $dec);
    return ($neg ? '-' : '') . _numero($abs, $dec);
}

/** `940` · `10 mil` · `16,8 mil` · `1,5 mi` (NBSP antes de mil/mi, sem `,0`). */
function moneyCompact(float $v): string
{
    $sinal = round($v) < 0 ? MENOS : '';
    $a = abs($v);
    $fmt = static function (float $x): string {
        $s = _numero($x, 1);
        return str_ends_with($s, sepDecimal() . '0') ? substr($s, 0, -2) : $s;
    };
    if (round($a) < 1000) {
        return $sinal . _numero(round($a), 0);
    }
    $mil = round($a / 1000, 1);
    if ($mil < 1000) {
        return $sinal . $fmt($mil) . NBSP . t('mil');
    }
    return $sinal . $fmt(round($a / 1000000, 1)) . NBSP . t('mi');
}

/**
 * Lê um valor em reais digitado no formato brasileiro. Aceita "1500", "1.500,00",
 * "1500,00", "20.000,0000" (Ajustes usa 4 casas), "R$ 1.500,00" e o texto de money().
 * Sinal de menos no início: "-" (hífen), "−" (U+2212) ou "–" (U+2013); NBSP é ignorado.
 * NÃO aceita "1.500" sozinho com outro agrupamento (ex.: "1.5") — isso é ambíguo e
 * era exatamente o bug que fazia "1.500" virar "R$ 1,50" silenciosamente.
 * Fora do português, "12.50" é decimal. Com $dinheiro = false (taxa de juros), "1,655" é decimal.
 * Retorna null se o formato for inválido, pra quem chama decidir a mensagem de erro.
 */
function parseMoedaBr(?string $input, bool $dinheiro = true): ?float
{
    $s = (string) $input;
    // espaços de todo tipo (inclusive NBSP e o fino U+202F) e o "R$" não contam
    $s = str_replace([NBSP, "\u{202F}", "\u{2009}", 'R$', 'r$', moedaSimbolo()], '', $s);
    $s = trim($s);
    if ($s === '') {
        return null;
    }
    // unifica os três traços de menos no hífen ASCII
    $s = str_replace([MENOS, "\u{2013}"], '-', $s);

    $neg = false;
    while ($s !== '' && ($s[0] === '-' || $s[0] === '+' || $s[0] === ' ')) {
        if ($s[0] === '-') {
            $neg = true;
        }
        $s = substr($s, 1);
    }
    $s = preg_replace('/[^0-9.,]/', '', $s);
    if ($s === '' || $s === null) {
        return null;
    }

    $idioma = \Financeiro\Idioma::atual();
    $virgula = strrpos($s, ',');
    $ponto = strrpos($s, '.');
    if ($virgula !== false && $ponto !== false) {
        // os dois: o último é o decimal ("1.500,50" ou "1,500.50")
        $s = $virgula > $ponto
            ? str_replace(',', '.', str_replace('.', '', $s))
            : str_replace(',', '', $s);
    } elseif ($virgula !== false) {
        // "1,500" é mil e quinhentos em inglês e, nos outros idiomas, é ambíguo: recusa em vez de gravar 1,50
        if ($dinheiro && preg_match('/^[1-9]\d{0,2}(,\d{3})+$/', $s)) {
            if ($idioma !== 'en') {
                return null;
            }
            $s = str_replace(',', '', $s);
        } else {
            $s = str_replace(',', '.', $s);
        }
    } elseif ($ponto !== false) {
        if (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) {
            // "1.500" em inglês pode ser 1,5: recusa o dinheiro; numa taxa, é decimal. Nos outros idiomas, é milhar
            if ($idioma === 'en') {
                if ($dinheiro || substr_count($s, '.') > 1) {
                    return null;
                }
            } else {
                $s = str_replace('.', '', $s);
            }
        } elseif ($idioma === 'pt' || !preg_match($idioma === 'en' ? '/^\d+\.\d+$/' : '/^\d+\.\d{1,2}$/', $s)) {
            return null;
        }
    }

    if (!is_numeric($s)) {
        return null;
    }

    $v = (float) $s;
    return ($neg && $v != 0.0) ? -$v : $v;
}

/* ------------------------------------------------------------------ idioma */

/** O texto em português traduzido para o idioma atual, com {nome} trocado por $vars. Sempre com literal: tools/idiomas/gerar.php lê daqui. */
function t(string $texto, array $vars = []): string
{
    $saida = \Financeiro\Idioma::traduzir($texto);
    foreach ($vars as $k => $v) {
        $saida = str_replace('{' . $k . '}', (string) $v, $saida);
    }
    return $saida;
}

/** Singular ou plural pela quantidade; {n} é a quantidade. */
function tn(int $n, string $um, string $varios, array $vars = []): string
{
    return t(abs($n) === 1 ? $um : $varios, ['n' => $n] + $vars);
}

/* ------------------------------------------------------------------ datas e meses */

/** `set/26`. */
function monthLabel(string $isoDate): string
{
    $ts = strtotime($isoDate);
    if (!$ts) {
        return '';
    }
    return mesCurto($isoDate) . '/' . date('y', $ts);
}

/** `set`. */
function mesCurto(string $mes): string
{
    $ts = strtotime($mes);
    return $ts ? \Financeiro\Idioma::mesCurto((int) date('n', $ts)) : '';
}

/** `outubro` (minúsculo; o título usa mb_convert_case). */
function mesExtenso(string $mes): string
{
    $ts = strtotime($mes);
    return $ts ? \Financeiro\Idioma::mesExtenso((int) date('n', $ts)) : '';
}

/** `outubro de 2026`, para as frases: a forma curta (`out/26`) fica para tabelas e gráficos. */
function mesAno(string $mes): string
{
    $ts = strtotime($mes);
    return $ts ? t('{mes} de {ano}', ['mes' => mesExtenso($mes), 'ano' => date('Y', $ts)]) : '';
}

/** `24/09`; em inglês, `Sep 24`. A data numérica curta do app inteiro (no JS: FIN.diaMes). */
function diaMes(string $data): string
{
    $ts = strtotime($data);
    if (!$ts) {
        return '';
    }
    return \Financeiro\Idioma::atual() === 'en' ? mesCurto($data) . ' ' . date('j', $ts) : date('d/m', $ts);
}

/** `29/09 14:02`; em inglês, `Sep 29 14:02`. */
function diaMesHora(string $datetime): string
{
    $ts = strtotime($datetime);
    return $ts ? diaMes($datetime) . ' ' . date('H:i', $ts) : '';
}

/** `24/09/2026`; em inglês, `Sep 24, 2026`. */
function diaMesAno(string $data): string
{
    $ts = strtotime($data);
    if (!$ts) {
        return '';
    }
    return \Financeiro\Idioma::atual() === 'en' ? diaMes($data) . ', ' . date('Y', $ts) : date('d/m/Y', $ts);
}

/** `20 nov`; em inglês, `Nov 20`. */
function diaMesCurto(string $data): string
{
    $ts = strtotime($data);
    if (!$ts) {
        return '';
    }
    return \Financeiro\Idioma::atual() === 'en' ? diaMes($data) : (int) date('j', $ts) . ' ' . mesCurto($data);
}

function diasNoMes(string $mes): int
{
    $ts = strtotime($mes);
    return $ts ? (int) date('t', $ts) : 30;
}

/** real | aberto | previsto. Mês PREVISAO até o mês de hoje (inclusive) é "aberto". */
function estadoMes(string $mes, string $situacao, ?string $hoje = null): string
{
    if (strtoupper($situacao) === 'REAL') {
        return 'real';
    }
    $hoje ??= \Financeiro\Relogio::hoje();
    return substr($mes, 0, 7) <= substr($hoje, 0, 7) ? 'aberto' : 'previsto';
}

/** `fechado` · `aberto` · `previsto` (o ponto de cada estado é desenhado pelo CSS: .estado-*::before). */
function estadoLabel(string $estado): string
{
    return match ($estado) {
        'real'   => t('Fechado'),
        'aberto' => t('Aberto'),
        default  => t('Previsto'),
    };
}

/**
 * `?mes=` validado: devolve $mes se ele for um dos meses conhecidos, senão $padrao.
 * $mesesKeys pode ser a lista de 'AAAA-MM-01' ou as linhas de MesModel::todos().
 */
function mesValido(?string $mes, array $mesesKeys, string $padrao): string
{
    if ($mes === null || !preg_match('/^\d{4}-\d{2}-01$/', $mes)) {
        return $padrao;
    }
    foreach ($mesesKeys as $k) {
        $valor = is_array($k) ? ($k['mes'] ?? null) : $k;
        if ($valor === $mes) {
            return $mes;
        }
    }
    return $padrao;
}

/* ------------------------------------------------------------------ grupos e categorias */

/** Entradas · Fixos · Dia a dia · Cartão · Pontuais · Empréstimo · Poupança · Investimentos. */
function grupoLabel(string $g): string
{
    static $labels = [
        'Entradas'   => 'Entradas',
        'Fixos'      => 'Fixos',
        'Dia a dia'  => 'Dia a dia',
        'Cartao'     => 'Cartão',
        'Ano'        => 'Pontuais',
        'Emprestimo' => 'Empréstimo',
        'Poupanca'   => 'Poupança',
        'Investimento' => 'Investimentos',
    ];
    // i18n: t('Entradas') t('Fixos') t('Dia a dia') t('Cartão') t('Pontuais') t('Empréstimo') t('Poupança') t('Investimentos')
    return isset($labels[$g]) ? t($labels[$g]) : $g;
}

/** Classe de cor (define `--cor`) de um lançamento ou categoria: g-entradas · g-ess · g-naoess · g-mov (⇄). */
function corClasse(array $c): string
{
    if ((int) ($c['operacional'] ?? 1) !== 1) {
        return 'g-mov';
    }
    if ((int) ($c['sinal'] ?? -1) > 0) {
        return 'g-entradas';
    }
    return (int) ($c['essencial'] ?? 1) === 1 ? 'g-ess' : 'g-naoess';
}

/** Ícone da categoria (pixel art do sprite), em $px; decorativo. Ver Financeiro\Icones. */
function iconeCategoria(int $id, string $nome = '', string $grupo = '', int $px = 40, string $classe = ''): string
{
    return \Financeiro\Icones::html(\Financeiro\Icones::daCategoria($id, $nome, $grupo), $px, $classe);
}

const CATEGORIAS_FABRICA = [
    'Salário', 'Outras entradas (13º, Pix, reembolsos)', 'Outras entradas', 'Crédito do empréstimo', 'Resgate da poupança', 'Aluguel e condomínio', 'Aluguel', 'Luz', 'Internet', 'Celular', 'Seguro do carro', 'Mercado', 'Combustível', 'Farmácia e saúde', 'Farmácia', 'Delivery / comer fora', 'Delivery', 'Lazer e outros', 'Lazer', 'Fatura do cartão', 'Fatura', 'Juros do cheque especial', 'Juros', 'IPVA', 'Licenciamento do carro', 'Licenciamento', 'Revisão do carro', 'Revisão', 'Imposto de renda', 'Outros gastos pontuais', 'Amortização extra do empréstimo', 'Amortização extra', 'Parcela do empréstimo', 'Guardar na poupança', 'Resgate de investimentos', 'Aplicar em investimentos',
];

/**
 * O nome de categoria para mostrar: as de fábrica (database/instalar.php) e os apelidos delas vêm dos dicionários;
 * o que a pessoa criou ou renomeou aparece como está. O banco e as comparações do motor continuam em português.
 */
function nomeCategoria(string $nome): string
{
    // i18n: t('Salário') t('Outras entradas (13º, Pix, reembolsos)') t('Outras entradas') t('Crédito do empréstimo') t('Resgate da poupança') t('Aluguel e condomínio') t('Aluguel') t('Luz') t('Internet') t('Celular') t('Seguro do carro') t('Mercado') t('Combustível') t('Farmácia e saúde') t('Farmácia') t('Delivery / comer fora') t('Delivery') t('Lazer e outros') t('Lazer') t('Fatura do cartão') t('Fatura') t('Juros do cheque especial') t('Juros') t('IPVA') t('Licenciamento do carro') t('Licenciamento') t('Revisão do carro') t('Revisão') t('Imposto de renda') t('Outros gastos pontuais') t('Amortização extra do empréstimo') t('Amortização extra') t('Parcela do empréstimo') t('Guardar na poupança') t('Resgate de investimentos') t('Aplicar em investimentos')
    return in_array($nome, CATEGORIAS_FABRICA, true) ? t($nome) : $nome;
}

/** Os nomes de fábrica cuja tradução contém o texto buscado: a busca do Extrato acha "Groceries". @return list<string> */
function categoriasPorTraducao(string $busca): array
{
    if (\Financeiro\Idioma::atual() === \Financeiro\Idioma::PADRAO || $busca === '') {
        return [];
    }
    $out = [];
    foreach (CATEGORIAS_FABRICA as $nome) {
        if (mb_stripos(t($nome), $busca) !== false) {
            $out[] = $nome;
        }
    }
    return $out;
}

/** O apelido como está no banco, senão o nome: para gravar. Na tela, use nomeCurto(). */
function nomeCurtoBanco(array $cat): string
{
    $apelido = trim((string) ($cat['apelido'] ?? ''));
    return $apelido !== '' ? $apelido : (string) ($cat['nome'] ?? '');
}

/** Apelido da categoria quando existe (`Delivery`), senão o nome completo; no idioma de quem vê. */
function nomeCurto(array $cat): string
{
    return nomeCategoria(nomeCurtoBanco($cat));
}

/** A descrição de um lançamento: a que foi gravada sozinha com o nome da categoria acompanha o idioma; a escrita pela pessoa fica como está. */
function descricaoTela(string $descricao, array $cat): string
{
    if ($descricao === 'Adiantamento do empréstimo') {
        return t('Adiantamento do empréstimo');
    }
    return $descricao === (string) ($cat['nome'] ?? '') || $descricao === (string) ($cat['apelido'] ?? '') ? nomeCategoria($descricao) : $descricao;
}

/** O nome de uma conta para mostrar: a "Conta principal" criada pelo app no idioma de quem vê; as outras como a pessoa escreveu. */
function nomeConta(array $conta): string
{
    $nome = (string) ($conta['nome'] ?? '');
    return $nome === \Financeiro\Models\ContaModel::NOME_PRINCIPAL ? t('Conta principal') : $nome;
}

/* ------------------------------------------------------------------ usuário */

/** `M`, `N`… (`?` sem nome). */
function inicialUsuario(?string $nome): string
{
    $nome = trim((string) $nome);
    return $nome === '' ? '?' : mb_strtoupper(mb_substr($nome, 0, 1));
}

/* ------------------------------------------------------------------ requisição */

/** Mesma regra do bootstrap.php: HTTPS direto ou atrás do vhost :443. */
function requisicaoHttps(): bool
{
    return \Financeiro\Rede::https();
}

/**
 * Destino de volta seguro (invariante 14): só caminho local, começando com UMA barra,
 * sem contrabarra e sem caractere de controle (o navegador apaga TAB/CR/LF, e
 * "/\t/evil.com" viraria "//evil.com"). Qualquer outra coisa devolve $padrao.
 */
function voltaSegura(?string $url, string $padrao = '/'): string
{
    if ($url === null || $url === '') {
        return $padrao;
    }
    return preg_match('~^/(?![/\\\\])[^\x00-\x1f\x7f\\\\]*\z~', $url) === 1 ? $url : $padrao;
}

function redirecionar(string $url, int $code = 302): never
{
    header('Location: ' . $url, true, $code);
    exit;
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

function csrfCheck(): bool
{
    $sent = $_POST['csrf_token'] ?? '';
    $valid = !empty($_SESSION['csrf_token']) && is_string($sent) && hash_equals($_SESSION['csrf_token'], $sent);
    if (!$valid) {
        if (\Financeiro\Auth::restaurado()) {
            // a sessão de 8 h venceu e o cookie de lembrar acabou de recriá-la: o token da
            // página já aberta é o da sessão velha. Nada é gravado; o que foi digitado volta.
            $caminho = (string) strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
            if ($caminho === '/lancamentos') {
                \Financeiro\Controllers\LancamentosController::sessaoRenovada();
            }
            $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
            $refCaminho = parse_url($ref, PHP_URL_PATH);
            $refQuery = parse_url($ref, PHP_URL_QUERY);
            $meuHost = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
            $volta = is_string($refCaminho) && parse_url($ref, PHP_URL_HOST) === $meuHost
                ? $refCaminho . (is_string($refQuery) && $refQuery !== '' ? '?' . $refQuery : '')
                : null;
            flash('warn', t('sessão renovada — refaça'));
            redirecionar(voltaSegura($volta, '/'), 303);
        }
        renderErro(403, t('sessão expirada'), t('volte e tente de novo'));
    }
    return true;
}

/**
 * Aviso depois de redirecionar (toast no layout). Tipos: success | warn | error.
 * $acao = ['url'=>'/lancamentos/12/excluir', 'label'=>'desfazer', 'campos'=>['volta'=>'/']]
 * vira um form POST com CSRF e os campos ocultos (ex.: DESFAZER). Com 'confirmar' => frase, pede confirmação antes.
 */
function flash(string $type, string $message, ?array $acao = null): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message, 'acao' => $acao];
}

/* ------------------------------------------------------------------ ícones */

/** Ícone SVG de traço (24×24, currentColor). Decorativo: aria-hidden. */
/** O "?" ao lado de um termo: leva direto à resposta na Ajuda. */
function dica(string $id, string $termo): string
{
    return '<a class="dica" href="/ajuda#' . e($id) . '" aria-label="' . e(t('O que é {termo}?', ['termo' => $termo])) . '">?</a>';
}

function svgIcon(string $name, int $size = 14, string $class = ''): string
{
    static $icons = [
        // navegação
        'ajuda'    => '<circle cx="12" cy="12" r="8.5"/><path d="M9.6 9.4a2.5 2.5 0 1 1 3.7 2.2c-.8.5-1.3 1-1.3 1.9"/><path d="M12 16.6h.01"/>',
        'home'     => '<path d="M3.5 10.2 12 3.5l8.5 6.7"/><path d="M5.5 8.8V19a1.5 1.5 0 0 0 1.5 1.5h3.2v-5.2h3.6v5.2H17a1.5 1.5 0 0 0 1.5-1.5V8.8"/>',
        'list'     => '<path d="M9 6.5h11"/><path d="M9 12h11"/><path d="M9 17.5h11"/><circle cx="4.5" cy="6.5" r=".9" fill="currentColor"/><circle cx="4.5" cy="12" r=".9" fill="currentColor"/><circle cx="4.5" cy="17.5" r=".9" fill="currentColor"/>',
        'calendar' => '<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17"/><path d="M8 3v4"/><path d="M16 3v4"/><path d="M7.5 14h2"/><path d="M11 14h2"/>',
        'invest'   => '<path d="M4 20.5h16"/><path d="M6.5 20.5v-6h3v6"/><path d="M10.5 20.5v-10h3v10"/><path d="M14.5 20.5V6h3v14.5"/>',
        'relatorio' => '<path d="M6.5 3.5h7.8L18.5 7.7V19a1.5 1.5 0 0 1-1.5 1.5H6.5A1.5 1.5 0 0 1 5 19V5a1.5 1.5 0 0 1 1.5-1.5z"/><path d="M14 3.8V8h4.2"/><path d="M8.7 16.8v-3"/><path d="M12 16.8v-5.5"/><path d="M15.3 16.8v-2"/>',
        'download' => '<path d="M12 4v11"/><path d="m7.5 11 4.5 4.5 4.5-4.5"/><path d="M4.5 19.5h15"/>',
        'trend'    => '<path d="M3.5 17.5 9 12l3.5 3.5 8-8"/><path d="M15 7.5h5.5V13"/>',
        'emprestimo' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 6.8v10.4"/><path d="M14.9 9.4c-.5-.9-1.6-1.4-2.9-1.4-1.6 0-2.9.8-2.9 2s1.2 1.8 2.9 2 3 .8 3 2-1.3 2-3 2c-1.4 0-2.5-.5-3-1.4"/>',
        'gift'     => '<rect x="3.5" y="8" width="17" height="4" rx="1"/><path d="M5 12v8.5h14V12"/><path d="M12 8v12.5"/><path d="M12 8c-1-2.5-2.6-4.5-4.6-4.5a2.25 2.25 0 0 0 0 4.5H12Z"/><path d="M12 8c1-2.5 2.6-4.5 4.6-4.5a2.25 2.25 0 0 1 0 4.5H12Z"/>',
        'externo'  => '<path d="M9 5H5.5A1.5 1.5 0 0 0 4 6.5v12A1.5 1.5 0 0 0 5.5 20h12a1.5 1.5 0 0 0 1.5-1.5V15"/><path d="M13.5 4H20v6.5"/><path d="M20 4l-9 9"/>',
        'topo'     => '<path d="M5 4.5h14"/><path d="M12 20V9"/><path d="M6.5 14.5 12 9l5.5 5.5"/>',
        'paleta'   => '<path d="M12 3.5a8.5 8.5 0 1 0 0 17c1.4 0 2-1 2-2 0-1.4-1-1.8-1-3 0-1.1.9-2 2-2h2.2c1.7 0 3.3-1.2 3.3-3.3 0-3.8-3.8-6.7-8.5-6.7Z"/><circle cx="7.8" cy="11" r="1.1" fill="currentColor"/><circle cx="10" cy="7.5" r="1.1" fill="currentColor"/><circle cx="14.4" cy="7.5" r="1.1" fill="currentColor"/>',
        'cartao'   => '<rect x="3" y="5.5" width="18" height="13" rx="2.5"/><path d="M3 10h18"/><path d="M7 15h3"/>',
        'menu'     => '<path d="M4 6.5h16"/><path d="M4 12h16"/><path d="M4 17.5h16"/>',
        'relogio'  => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'more'     => '<circle cx="5.5" cy="12" r="1.2" fill="currentColor"/><circle cx="12" cy="12" r="1.2" fill="currentColor"/><circle cx="18.5" cy="12" r="1.2" fill="currentColor"/>',
        'user'     => '<circle cx="12" cy="8.5" r="4"/><path d="M4.5 20.5a7.5 7.5 0 0 1 15 0"/>',
        'x'        => '<path d="M18 6 6 18"/><path d="M6 6l12 12"/>',
        'check'    => '<path d="M20 6.5 9.5 17 4 11.5"/>',
        'alert'    => '<path d="M12 9.5v4"/><path d="M12 17h.01"/><path d="M10.3 4.2 2.6 17.6A2 2 0 0 0 4.3 20.5h15.4a2 2 0 0 0 1.7-2.9L13.7 4.2a2 2 0 0 0-3.4 0Z"/>',
        'chevron'  => '<path d="M9.5 6l6 6-6 6"/>',
        'plus'     => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'settings' => '<path d="M4 7h10"/><path d="M18 7h2"/><circle cx="16" cy="7" r="2"/><path d="M4 17h2"/><path d="M10 17h10"/><circle cx="8" cy="17" r="2"/>',
        'sun'      => '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M4.6 4.6l1.4 1.4M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4 6 18M18 6l1.4-1.4"/>',
        'moon'     => '<path d="M20.5 13.2A8.5 8.5 0 1 1 10.8 3.5a6.6 6.6 0 0 0 9.7 9.7Z"/>',
        'logout'   => '<path d="M9.5 20.5H6A2.5 2.5 0 0 1 3.5 18V6A2.5 2.5 0 0 1 6 3.5h3.5"/><path d="M15.5 16.5 20 12l-4.5-4.5"/><path d="M20 12H9.5"/>',
        'search'   => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
        'target'   => '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3.4"/><path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3"/>',
        'eye'      => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off'  => '<path d="M3.5 3.5l17 17"/><path d="M10.6 5.6c.5-.1.9-.1 1.4-.1 6 0 9.5 6.5 9.5 6.5a16.7 16.7 0 0 1-3.2 3.9"/><path d="M6.6 6.6C3.9 8.4 2.5 12 2.5 12S6 18.5 12 18.5c1.6 0 3-.4 4.3-1"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
        'arrow-up'   => '<path d="M12 19V5"/><path d="M6 11l6-6 6 6"/>',
        'arrow-down' => '<path d="M12 5v14"/><path d="M6 13l6 6 6-6"/>',
        'lock'     => '<rect x="5" y="11" width="14" height="9.5" rx="2.5"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
        'tag'      => '<path d="M3.5 12V4.5h7.5l9 9-7.5 7.5-9-9Z"/><circle cx="7.8" cy="8.8" r="1.1"/>',
        'pen'      => '<path d="M4 20h4L19 9l-4-4L4 16v4Z"/>',
        // linhas de Ajustes
        'globo'    => '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17"/><path d="M12 3.5c2.3 2.4 3.4 5.2 3.4 8.5s-1.1 6.1-3.4 8.5c-2.3-2.4-3.4-5.2-3.4-8.5s1.1-6.1 3.4-8.5Z"/>',
        'imagem'   => '<rect x="3.5" y="4.5" width="17" height="15" rx="2.5"/><circle cx="9" cy="9.5" r="1.6"/><path d="m20.5 15.5-4.5-4.5-8.5 8.5"/>',
        'escudo'   => '<path d="M12 3.2 4.8 6v5.6c0 4.4 3 7.9 7.2 9.2 4.2-1.3 7.2-4.8 7.2-9.2V6L12 3.2Z"/><path d="m9 12 2 2 4-4"/>',
        'pessoas'  => '<circle cx="9" cy="8.5" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 5.2a3.5 3.5 0 0 1 0 6.6"/><path d="M18 14.2a6.5 6.5 0 0 1 3.5 5.8"/>',
        'celular'  => '<rect x="6.5" y="2.5" width="11" height="19" rx="2.5"/><path d="M11 18.5h2"/>',
        'codigo'   => '<path d="m8.5 7.5-5 4.5 5 4.5"/><path d="m15.5 7.5 5 4.5-5 4.5"/>',
        'info'     => '<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5.5"/><path d="M12 7.7h.01"/>',
        'carteira' => '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
    ];
    $inner = $icons[$name] ?? '';
    $cls = $class !== '' ? ' class="' . e($class) . '"' : '';
    return '<svg' . $cls . ' width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $inner . '</svg>';
}

/* ------------------------------------------------------------------ erro */

/** Página de erro mínima, com o mesmo visual do resto do app (nunca texto puro). */
function renderErro(int $codigo, string $titulo, string $mensagem): never
{
    http_response_code($codigo);
    $css = '/assets/css/style.css';
    ?>
<!doctype html>
<html lang="<?= e(\Financeiro\Idioma::html()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#f4f5f7" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0a0b0f" media="(prefers-color-scheme: dark)">
<title><?= e((string) $codigo) ?> · financeiro</title>
<?= \Financeiro\SiteIcone::tags() ?><link rel="preload" href="/assets/fonts/manrope-var-latin.woff2" as="font" type="font/woff2" crossorigin>
<script src="<?= e(asset('/assets/js/theme.js')) ?>"></script>
<link rel="stylesheet" href="<?= e(asset($css)) ?>">
<link rel="stylesheet" href="<?= e(asset('/assets/css/temas.css')) ?>">
</head>
<body class="tela-erro">
<main class="erro">
    <img class="erro-marca" src="<?= e(\Financeiro\SiteIcone::url()) ?>" alt="" width="96" height="96">
    <p class="erro-codigo num" aria-hidden="true"><?= e((string) $codigo) ?></p>
    <h1 class="erro-titulo"><?= e(mb_strtoupper(mb_substr($titulo, 0, 1)) . mb_substr($titulo, 1)) ?></h1>
    <p class="erro-msg"><?= e(mb_strtoupper(mb_substr($mensagem, 0, 1)) . mb_substr($mensagem, 1)) ?>.</p>
    <a class="btn btn-primary" href="/"><?= e(t('Voltar ao início')) ?></a>
</main>
</body>
</html>
    <?php
    exit;
}
