<?php
// Funções globais pequenas, usadas nas views e nos controllers. Ficam fora de
// classes de propósito: são formatação de saída e utilidades de requisição, sem
// regra de negócio. Dono: P1 (SPEC §11). Código novo só usa as 6 funções mb_*
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

/** `R$ 1.234,56` (NBSP depois do R$); negativo `−R$ 1.234,56` (U+2212). */
function money(float $v): string
{
    [$neg, $abs] = _centavos($v);
    return ($neg ? MENOS : '') . 'R$' . NBSP . number_format($abs, 2, ',', '.');
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
    return ($neg ? MENOS : '') . number_format($abs, $dec, ',', '.');
}

/** `1.234,56` / `-8.233,11`: hífen ASCII, sem NBSP. Obrigatório em todo `<input value>` de dinheiro. */
function numInput(float $v, int $dec = 2): string
{
    [$neg, $abs] = _centavos($v, $dec);
    return ($neg ? '-' : '') . number_format($abs, $dec, ',', '.');
}

/** `940` · `10 mil` · `16,8 mil` · `1,5 mi` (NBSP antes de mil/mi, sem `,0`). */
function moneyCompact(float $v): string
{
    $sinal = round($v) < 0 ? MENOS : '';
    $a = abs($v);
    $fmt = static function (float $x): string {
        $s = number_format($x, 1, ',', '.');
        return str_ends_with($s, ',0') ? substr($s, 0, -2) : $s;
    };
    if (round($a) < 1000) {
        return $sinal . number_format(round($a), 0, ',', '.');
    }
    $mil = round($a / 1000, 1);
    if ($mil < 1000) {
        return $sinal . $fmt($mil) . NBSP . 'mil';
    }
    return $sinal . $fmt(round($a / 1000000, 1)) . NBSP . 'mi';
}

/**
 * Lê um valor em reais digitado no formato brasileiro. Aceita "1500", "1.500,00",
 * "1500,00", "20.000,0000" (Ajustes usa 4 casas), "R$ 1.500,00" e o texto de money().
 * Sinal de menos no início: "-" (hífen), "−" (U+2212) ou "–" (U+2013); NBSP é ignorado.
 * NÃO aceita "1.500" sozinho com outro agrupamento (ex.: "1.5") — isso é ambíguo e
 * era exatamente o bug que fazia "1.500" virar "R$ 1,50" silenciosamente.
 * Retorna null se o formato for inválido, pra quem chama decidir a mensagem de erro.
 */
function parseMoedaBr(?string $input): ?float
{
    $s = (string) $input;
    // espaços de todo tipo (inclusive NBSP e o fino U+202F) e o "R$" não contam
    $s = str_replace([NBSP, "\u{202F}", "\u{2009}", 'R$', 'r$'], '', $s);
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

    if (str_contains($s, ',')) {
        // vírgula é o separador decimal; qualquer ponto antes dela é separador de milhar
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } elseif (str_contains($s, '.')) {
        // sem vírgula, só aceita ponto como separador de milhar em grupos de 3 dígitos
        // (ex.: "1.500" = 1500). Qualquer outro uso de ponto é ambíguo — recusa.
        if (!preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) {
            return null;
        }
        $s = str_replace('.', '', $s);
    }

    if (!is_numeric($s)) {
        return null;
    }

    $v = (float) $s;
    return ($neg && $v != 0.0) ? -$v : $v;
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
    static $meses = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
    $ts = strtotime($mes);
    return $ts ? $meses[(int) date('n', $ts) - 1] : '';
}

/** `outubro` (minúsculo; o título usa mb_convert_case). */
function mesExtenso(string $mes): string
{
    static $meses = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    $ts = strtotime($mes);
    return $ts ? $meses[(int) date('n', $ts) - 1] : '';
}

/** `24/09`. */
function diaMes(string $data): string
{
    $ts = strtotime($data);
    return $ts ? date('d/m', $ts) : '';
}

/** `29/09 14:02`. */
function diaMesHora(string $datetime): string
{
    $ts = strtotime($datetime);
    return $ts ? date('d/m H:i', $ts) : '';
}

function diasNoMes(string $mes): int
{
    $ts = strtotime($mes);
    return $ts ? (int) date('t', $ts) : 30;
}

/** real | aberto | previsto (SPEC §3.1). Mês PREVISAO até o mês de hoje (inclusive) é "aberto". */
function estadoMes(string $mes, string $situacao, ?string $hoje = null): string
{
    if (strtoupper($situacao) === 'REAL') {
        return 'real';
    }
    $hoje ??= date('Y-m-d');
    return substr($mes, 0, 7) <= substr($hoje, 0, 7) ? 'aberto' : 'previsto';
}

/** `fechado` · `aberto` · `previsto` (o ponto de cada estado é desenhado pelo CSS: .estado-*::before). */
function estadoLabel(string $estado): string
{
    return match ($estado) {
        'real'   => 'Fechado',
        'aberto' => 'Aberto',
        default  => 'Previsto',
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

/** Entradas · Fixos · Dia a dia · Cartão · Pontuais · Empréstimo · Poupança. */
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
    ];
    return $labels[$g] ?? $g;
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

/** Apelido da categoria quando existe (`Delivery`), senão o nome completo. */
function nomeCurto(array $cat): string
{
    $apelido = trim((string) ($cat['apelido'] ?? ''));
    return $apelido !== '' ? $apelido : (string) ($cat['nome'] ?? '');
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
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
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
            flash('warn', 'sessão renovada — refaça');
            redirecionar(voltaSegura($volta, '/'), 303);
        }
        renderErro(403, 'sessão expirada', 'volte e tente de novo');
    }
    return true;
}

/**
 * Aviso depois de redirecionar (toast no layout). Tipos: success | warn | error.
 * $acao = ['url'=>'/lancamentos/12/excluir', 'label'=>'desfazer', 'campos'=>['volta'=>'/']]
 * vira um form POST com CSRF e os campos ocultos (ex.: DESFAZER).
 */
function flash(string $type, string $message, ?array $acao = null): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message, 'acao' => $acao];
}

/* ------------------------------------------------------------------ ícones */

/** Ícone SVG de traço (24×24, currentColor). Decorativo: aria-hidden. */
function svgIcon(string $name, int $size = 14, string $class = ''): string
{
    static $icons = [
        // navegação
        'home'     => '<path d="M3.5 10.2 12 3.5l8.5 6.7"/><path d="M5.5 8.8V19a1.5 1.5 0 0 0 1.5 1.5h3.2v-5.2h3.6v5.2H17a1.5 1.5 0 0 0 1.5-1.5V8.8"/>',
        'list'     => '<path d="M9 6.5h11"/><path d="M9 12h11"/><path d="M9 17.5h11"/><circle cx="4.5" cy="6.5" r=".9" fill="currentColor"/><circle cx="4.5" cy="12" r=".9" fill="currentColor"/><circle cx="4.5" cy="17.5" r=".9" fill="currentColor"/>',
        'calendar' => '<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17"/><path d="M8 3v4"/><path d="M16 3v4"/><path d="M7.5 14h2"/><path d="M11 14h2"/>',
        'trend'    => '<path d="M3.5 17.5 9 12l3.5 3.5 8-8"/><path d="M15 7.5h5.5V13"/>',
        'emprestimo' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 6.8v10.4"/><path d="M14.9 9.4c-.5-.9-1.6-1.4-2.9-1.4-1.6 0-2.9.8-2.9 2s1.2 1.8 2.9 2 3 .8 3 2-1.3 2-3 2c-1.4 0-2.5-.5-3-1.4"/>',
        'cartao'   => '<rect x="3" y="5.5" width="18" height="13" rx="2.5"/><path d="M3 10h18"/><path d="M7 15h3"/>',
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
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#f4f5f7" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0a0b0f" media="(prefers-color-scheme: dark)">
<title><?= e((string) $codigo) ?> · financeiro</title>
<link rel="icon" type="image/png" sizes="32x32" href="/assets/img/icone-32.png">
<link rel="preload" href="/assets/fonts/manrope-var-latin.woff2" as="font" type="font/woff2" crossorigin>
<script src="<?= e(asset('/assets/js/theme.js')) ?>"></script>
<link rel="stylesheet" href="<?= e(asset($css)) ?>">
</head>
<body class="tela-erro">
<main class="erro">
    <img class="erro-spitz" src="/assets/img/spitz.png" alt="" width="108" height="91">
    <p class="erro-codigo num" aria-hidden="true"><?= e((string) $codigo) ?></p>
    <h1 class="erro-titulo"><?= e(mb_strtoupper(mb_substr($titulo, 0, 1)) . mb_substr($titulo, 1)) ?></h1>
    <p class="erro-msg"><?= e(mb_strtoupper(mb_substr($mensagem, 0, 1)) . mb_substr($mensagem, 1)) ?>.</p>
    <a class="btn btn-primary" href="/">Voltar ao início</a>
</main>
</body>
</html>
    <?php
    exit;
}
