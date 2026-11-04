<?php
namespace Financeiro\Controllers;

use Financeiro\Auth;
use Financeiro\Models\AjusteModel;
use Financeiro\Models\CartaoModel;
use Financeiro\Models\ContaModel;
use Financeiro\Models\ImportacaoModel;
use Financeiro\Relogio;
use Financeiro\Services\ExtratoArquivo;
use Financeiro\Services\Importacao;
use Financeiro\Services\Planilha;
use Financeiro\View;

/**
 * Importar extrato: escolher o destino e o arquivo (/importar), ligar as colunas do CSV (/importar/colunas), revisar e
 * lançar (/importar/revisar), e desfazer um lote. Entre um passo e outro, o que foi lido do arquivo fica só na sessão
 * (o arquivo não é guardado em lugar nenhum) e sai dela ao lançar ou cancelar.
 * Todo POST: requireLogin + csrfCheck; a revisão confere o token do arquivo, para um formulário velho não gravar outro.
 */
final class ImportacaoController
{
    private const SESSAO = 'importar';

    /** GET /importar */
    public function index(): void
    {
        Auth::requireLogin();
        $destinos = self::destinos();
        $pedido = is_string($_GET['destino'] ?? null) ? $_GET['destino'] : (string) ($_SESSION['importar_destino'] ?? '');
        View::render('importar/index', [
            'pageTitle' => t('importar extrato · MyMonetto'),
            'destinos'  => $destinos,
            'escolhido' => self::destino($pedido) !== null ? $pedido : 'conta:' . ContaModel::padrao(),
            'recentes'  => ImportacaoModel::recentes(),
            'regras'    => ImportacaoModel::regras(),
            'pendente'  => isset($_SESSION[self::SESSAO]) ? (string) $_SESSION[self::SESSAO]['nome'] : null,
            'nomeDestino' => static fn(array $i): string => self::nomeDoLote($i),
        ], 'extrato');
    }

    /** POST /importar: lê o arquivo e vai para as colunas (CSV sem colunas lembradas) ou para a revisão. */
    public function enviar(): void
    {
        // arquivo maior que o limite do PHP: o formulário inteiro chega vazio, e o CSRF reclamaria de outra coisa
        if ($_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            Auth::requireLogin();
            flash('error', t('O arquivo passa de {limite}. Baixe um período menor.', ['limite' => '2 MB']));
            redirecionar('/importar', 303);
        }
        Auth::requireLogin();
        csrfCheck();
        $pedido = is_string($_POST['destino'] ?? null) ? $_POST['destino'] : '';
        $destino = self::destino($pedido);
        if ($destino === null) {
            flash('error', t('Escolha para onde vão os lançamentos.'));
            redirecionar('/importar', 303);
        }
        $_SESSION['importar_destino'] = $pedido;
        $f = $_FILES['arquivo'] ?? null;
        $erro = is_array($f) ? (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if ($erro === UPLOAD_ERR_INI_SIZE || $erro === UPLOAD_ERR_FORM_SIZE || ($erro === UPLOAD_ERR_OK && (int) $f['size'] > ExtratoArquivo::MAX_BYTES)) {
            flash('error', t('O arquivo passa de {limite}. Baixe um período menor.', ['limite' => '2 MB']));
            redirecionar('/importar?destino=' . rawurlencode($pedido), 303);
        }
        if ($erro !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
            flash('error', t('Escolha o arquivo do extrato.'));
            redirecionar('/importar?destino=' . rawurlencode($pedido), 303);
        }
        // lido uma vez, até 1 byte além do limite; o PHP apaga o arquivo temporário no fim do pedido
        $bruto = (string) file_get_contents((string) $f['tmp_name'], false, null, 0, ExtratoArquivo::MAX_BYTES + 1);
        $nome = ExtratoArquivo::limpar(basename(str_replace('\\', '/', (string) ($f['name'] ?? ''))));
        $r = self::lerArquivo($bruto, $nome !== '' ? $nome : t('extrato'), $destino);
        if ($r !== null) {
            flash('error', $r);
            redirecionar('/importar?destino=' . rawurlencode($pedido), 303);
        }
        $estado = $_SESSION[self::SESSAO];
        if (($quando = ImportacaoModel::jaImportado($estado['hash'])) !== null) {
            flash('warn', t('Esse arquivo já foi importado em {data}. O que já entrou vem desmarcado.', ['data' => diaMesAno($quando)]));
        }
        redirecionar($estado['linhas'] === null ? '/importar/colunas' : '/importar/revisar', 303);
    }

    /**
     * Lê o arquivo para a sessão. Também serve aos testes, sem o envio. null = deu certo; senão a mensagem.
     * @param array{tipo:string, id:int} $destino
     */
    public static function lerArquivo(string $bruto, string $nome, array $destino): ?string
    {
        try {
            $lido = ExtratoArquivo::ler($bruto);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }
        $estado = ['token' => bin2hex(random_bytes(12)), 'destino' => $destino, 'nome' => mb_substr($nome, 0, 255), 'hash' => hash('sha256', $bruto),
                   'formato' => $lido['formato'], 'linhas' => null, 'ignoradas' => 0, 'escolhas' => [], 'csv' => null];
        if ($lido['formato'] === 'ofx') {
            [$estado['linhas'], $estado['ignoradas']] = [$lido['linhas'], $lido['ignoradas']];
        } else {
            $cab = ExtratoArquivo::temCabecalho($lido['linhas']);
            $estado['csv'] = ['sep' => $lido['sep'], 'linhas' => $lido['linhas'], 'mapa' => null];
            $lembrado = ImportacaoModel::layout(ExtratoArquivo::layout($lido['linhas'], $lido['sep'], $cab));
            if ($lembrado !== null && self::mapaValido($lembrado, count($lido['linhas'][0])) && ($ap = ExtratoArquivo::aplicarMapa($lido['linhas'], $lembrado, $lido['sep']))['linhas'] !== []) {
                $estado['csv']['mapa'] = $lembrado;
                [$estado['linhas'], $estado['ignoradas']] = [$ap['linhas'], $ap['ignoradas']];
            }
        }
        if ($estado['linhas'] !== null && count($estado['linhas']) > ExtratoArquivo::MAX_LINHAS) {
            return t('O arquivo tem mais de {n} lançamentos. Baixe um período menor.', ['n' => num(ExtratoArquivo::MAX_LINHAS, 0)]);
        }
        $_SESSION[self::SESSAO] = $estado;
        return null;
    }

    /** GET /importar/colunas: a prévia do CSV e as colunas de data, descrição, valor (ou débito e crédito) e categoria. */
    public function colunasForm(): void
    {
        Auth::requireLogin();
        $estado = self::estado();
        if ($estado['csv'] === null) {
            redirecionar('/importar/revisar', 303);
        }
        $linhas = $estado['csv']['linhas'];
        $mapa = $_SESSION['importar_mapa_erro'] ?? $estado['csv']['mapa'] ?? ExtratoArquivo::mapaSugerido($linhas);
        unset($_SESSION['importar_mapa_erro']);
        View::render('importar/colunas', [
            'pageTitle' => t('importar extrato · MyMonetto'),
            'estado'    => $estado,
            'mapa'      => $mapa,
            'cabecalho' => ExtratoArquivo::temCabecalho($linhas) ? $linhas[0] : null,
            'previa'    => array_slice($linhas, 0, 7),
            'n'         => count($linhas[0]),
        ], 'extrato');
    }

    /** POST /importar/colunas: aplica as colunas, lembra delas para esse cabeçalho e vai para a revisão. */
    public function colunas(): void
    {
        Auth::requireLogin();
        csrfCheck();
        $estado = self::estado();
        if ($estado['csv'] === null) {
            redirecionar('/importar/revisar', 303);
        }
        $linhas = $estado['csv']['linhas'];
        $col = static fn(string $k): int => is_string($_POST[$k] ?? null) && preg_match('/^-?\d{1,2}$/D', $_POST[$k]) ? (int) $_POST[$k] : -1;
        $mapa = ['data' => $col('data'), 'descricao' => $col('descricao'), 'valor' => $col('valor'), 'debito' => $col('debito'),
                 'credito' => $col('credito'), 'categoria' => $col('categoria'), 'cabecalho' => ($_POST['cabecalho'] ?? '') === '1'];
        if ($mapa['debito'] >= 0 || $mapa['credito'] >= 0) {
            $mapa['valor'] = -1;                     // saídas e entradas em colunas separadas: a de valor fica de fora
        }
        $erro = self::erroDoMapa($mapa, count($linhas[0]));
        $ap = $erro === null ? ExtratoArquivo::aplicarMapa($linhas, $mapa, $estado['csv']['sep']) : null;
        if ($erro === null && $ap['linhas'] === []) {
            $erro = t('Nenhuma linha tem data e valor que dê para ler. Confira as colunas.');
        }
        if ($erro === null && count($ap['linhas']) > ExtratoArquivo::MAX_LINHAS) {
            $erro = t('O arquivo tem mais de {n} lançamentos. Baixe um período menor.', ['n' => num(ExtratoArquivo::MAX_LINHAS, 0)]);
        }
        if ($erro !== null) {
            $_SESSION['importar_mapa_erro'] = $mapa;
            flash('error', $erro);
            redirecionar('/importar/colunas', 303);
        }
        ImportacaoModel::salvarLayout(ExtratoArquivo::layout($linhas, $estado['csv']['sep'], $mapa['cabecalho']), $mapa);
        $_SESSION[self::SESSAO]['csv']['mapa'] = $mapa;
        $_SESSION[self::SESSAO]['linhas'] = $ap['linhas'];
        $_SESSION[self::SESSAO]['ignoradas'] = $ap['ignoradas'];
        $_SESSION[self::SESSAO]['escolhas'] = [];
        redirecionar('/importar/revisar', 303);
    }

    /** GET /importar/revisar */
    public function revisarForm(): void
    {
        Auth::requireLogin();
        $estado = self::estado();
        if ($estado['linhas'] === null) {
            redirecionar('/importar/colunas', 303);
        }
        $rev = Importacao::revisao($estado['linhas'], $estado['destino'], $estado['formato'], $estado['escolhas']);
        $tipos = array_unique(array_column($rev, 'tipo'));
        $categorias = [];
        foreach ($tipos as $tp) {
            $categorias[$tp] = Importacao::permitidas($tp);
        }
        View::render('importar/revisar', [
            'pageTitle'  => t('revisar importação · MyMonetto'),
            'estado'     => $estado,
            'linhas'     => $rev,
            'categorias' => $categorias,
            // a regra pode levar a qualquer categoria que sirva ao destino: gastos e entradas na conta, gastos no cartão
            'categoriasRegra' => $estado['destino']['tipo'] === 'cartao' ? Importacao::permitidas('compra') : Importacao::permitidas('gasto') + Importacao::permitidas('entrada'),
            'nomeDestino' => self::nomeDestino($estado['destino']),
            'hoje'       => Relogio::hoje(),
        ], 'extrato');
    }

    /** POST /importar/revisar: acao = lancar (grava o lote), regra (cria a regra e volta) ou cancelar. */
    public function revisar(): void
    {
        Auth::requireLogin();
        csrfCheck();
        $estado = self::estado();
        if (!is_string($_POST['token'] ?? null) || !hash_equals($estado['token'], $_POST['token'])) {
            flash('warn', t('Essa revisão era de outro arquivo. Confira as linhas de novo.'));
            redirecionar('/importar/revisar', 303);
        }
        $acao = (string) ($_POST['acao'] ?? '');
        if ($acao === 'cancelar') {
            unset($_SESSION[self::SESSAO]);
            flash('success', t('Importação cancelada. Nada foi gravado.'));
            redirecionar('/importar', 303);
        }
        if ($estado['linhas'] === null) {
            redirecionar('/importar/colunas', 303);
        }
        $escolhas = self::escolhasDoPost(count($estado['linhas']));
        if ($acao === 'regra') {
            $r = self::criarRegra($_POST, $estado, $escolhas);
            $_SESSION[self::SESSAO]['escolhas'] = $r['escolhas'];
            flash($r['erro'] === null ? 'success' : 'error', $r['erro'] ?? $r['aviso']);
            redirecionar('/importar/revisar#imp-regra', 303);
        }
        $rev = Importacao::revisao($estado['linhas'], $estado['destino'], $estado['formato'], $escolhas);
        $g = Importacao::gravar($rev, $estado['destino'], $estado, (int) Auth::id());
        if ($g['erro'] !== null) {
            $_SESSION[self::SESSAO]['escolhas'] = $escolhas;
            flash('error', $g['erro']);
            redirecionar('/importar/revisar', 303);
        }
        unset($_SESSION[self::SESSAO]);
        $destino = self::depoisDeLancar($estado, $rev);
        flash('success', tn($g['n'], '1 lançamento importado', '{n} lançamentos importados'), [
            'url' => '/importar/' . $g['id'] . '/desfazer', 'label' => t('Desfazer importação'),
            'campos' => ['volta' => $destino], 'confirmar' => t('Desfazer a importação? As {n} linhas dela serão apagadas.', ['n' => $g['n']]),
        ]);
        redirecionar($destino, 303);
    }

    /**
     * A regra "contém → categoria" que a pessoa criou na revisão: grava e passa a categoria para as linhas que
     * casam (do tipo certo). As outras escolhas da tela ficam.
     * @return array{erro:?string, aviso:string, escolhas:array}
     */
    public static function criarRegra(array $in, array $estado, array $escolhas): array
    {
        $texto = ExtratoArquivo::limpar(is_string($in['regra_texto'] ?? null) ? $in['regra_texto'] : '');
        $catId = (int) (is_string($in['regra_categoria'] ?? null) ? $in['regra_categoria'] : 0);
        $norm = Importacao::normalizar($texto);
        $cat = $catId > 0 ? \Financeiro\Models\CategoriaModel::find($catId) : null;
        if (mb_strlen($norm) < 2 || mb_strlen($texto) > 60) {
            return ['erro' => t('Escreva o texto da regra, com 2 a 60 letras.'), 'aviso' => '', 'escolhas' => $escolhas];
        }
        if ($cat === null || (int) $cat['ativo'] !== 1) {
            return ['erro' => t('Escolha a categoria da regra.'), 'aviso' => '', 'escolhas' => $escolhas];
        }
        ImportacaoModel::salvarRegra($texto, $catId, (int) Auth::id());
        $n = 0;
        foreach (Importacao::revisao($estado['linhas'], $estado['destino'], $estado['formato'], $escolhas) as $r) {
            if ($r['pode'] && isset(Importacao::permitidas($r['tipo'])[$catId]) && str_contains(' ' . Importacao::normalizar($r['gravar']) . ' ', $norm)) {
                $escolhas[$r['i']] = ['m' => $escolhas[$r['i']]['m'] ?? ($r['marcada'] ? 1 : 0), 'c' => $catId];
                $n++;
            }
        }
        return ['erro' => null, 'escolhas' => $escolhas, 'aviso' => t('Regra criada: “{texto}” vai para {categoria}', ['texto' => $texto, 'categoria' => nomeCurto($cat)])
            . ' · ' . tn($n, '1 linha mudou', '{n} linhas mudaram')];
    }

    /** POST /importar/{id}/desfazer: apaga o lote inteiro (a tela pede confirmação), salvo se alguma linha cai num mês fechado. */
    public function desfazer(string $id): void
    {
        Auth::requireLogin();
        csrfCheck();
        $volta = voltaSegura(is_string($_POST['volta'] ?? null) ? $_POST['volta'] : null, '/importar');
        $imp = ImportacaoModel::achar((int) $id);
        if ($imp === null || $imp['desfeita_em'] !== null) {
            flash('warn', t('Essa importação já tinha sido desfeita.'));
            redirecionar($volta, 303);
        }
        if (($fechado = Importacao::erroDesfazer((int) $id)) !== null) {
            flash('error', $fechado);
            redirecionar($volta, 303);
        }
        $n = Importacao::desfazer((int) $id, (int) Auth::id());
        flash('success', t('Importação desfeita · {linhas}', ['linhas' => tn($n, '1 linha apagada', '{n} linhas apagadas')]));
        redirecionar($volta, 303);
    }

    /** POST /importar/regras/{id}/remover */
    public function removerRegra(string $id): void
    {
        Auth::requireLogin();
        csrfCheck();
        ImportacaoModel::removerRegra((int) $id);
        flash('success', t('Regra apagada.'));
        redirecionar('/importar#imp-regras', 303);
    }

    /**
     * GET /importar/modelo: o CSV para quem vem do caderno ou de uma planilha. Mesmo formato da planilha do Extrato
     * (UTF-8 com BOM; ";" e vírgula decimal, ou "," e ponto em inglês), com três linhas de exemplo deste mês.
     */
    public function modelo(): void
    {
        Auth::requireLogin();
        [$sep, $dec] = Planilha::separadores();
        $mes = substr(Relogio::hoje(), 0, 8);
        $v = static fn(float $x): string => ($x < 0 ? '-' : '') . number_format(abs($x), 2, $dec, '');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="modelo-financeiro.csv"');
        header('Cache-Control: private, no-store');
        $saida = fopen('php://output', 'wb');
        fwrite($saida, "\u{FEFF}");
        foreach ([[t('Data'), t('Descrição'), t('Valor'), t('Categoria')],
                  [$mes . '05', t('Salário'), $v(3000.0), t('Salário')],
                  [$mes . '06', t('Mercado'), $v(-235.9), t('Mercado')],
                  [$mes . '08', t('Farmácia'), $v(-42.5), '']] as $linha) {
            fputcsv($saida, $linha, $sep, '"', '', "\r\n");
        }
        fclose($saida);
    }

    /* ================================================================== apoio */

    /** O estado da importação na sessão; sem ele, volta ao começo. */
    private static function estado(): array
    {
        $e = $_SESSION[self::SESSAO] ?? null;
        if (!is_array($e) || !isset($e['token'], $e['destino']) || self::destino($e['destino']['tipo'] . ':' . $e['destino']['id']) === null) {
            unset($_SESSION[self::SESSAO]);
            flash('warn', t('Escolha o arquivo do extrato.'));
            redirecionar('/importar', 303);
        }
        return $e;
    }

    /** marcar[i] e cat[i] do formulário da revisão. @return array<int, array{m:int, c:int}> */
    private static function escolhasDoPost(int $n): array
    {
        $marcar = is_array($_POST['marcar'] ?? null) ? $_POST['marcar'] : [];
        $cat = is_array($_POST['cat'] ?? null) ? $_POST['cat'] : [];
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[$i] = ['m' => ($marcar[$i] ?? '') === '1' ? 1 : 0, 'c' => is_string($cat[$i] ?? null) ? (int) $cat[$i] : 0];
        }
        return $out;
    }

    /** As colunas lembradas ainda servem a este arquivo? */
    private static function mapaValido(array $mapa, int $n): bool
    {
        foreach (['data', 'descricao', 'valor', 'debito', 'credito', 'categoria'] as $k) {
            if (!is_int($mapa[$k] ?? null)) {
                return false;
            }
        }
        return is_bool($mapa['cabecalho'] ?? null) && self::erroDoMapa($mapa, $n) === null;
    }

    private static function erroDoMapa(array $mapa, int $n): ?string
    {
        $usadas = array_filter([$mapa['data'], $mapa['descricao'], $mapa['valor'], $mapa['debito'], $mapa['credito'], $mapa['categoria']], static fn(int $i): bool => $i >= 0);
        return match (true) {
            $mapa['data'] < 0 => t('Escolha a coluna da data.'),
            $mapa['descricao'] < 0 => t('Escolha a coluna da descrição.'),
            $mapa['valor'] < 0 && $mapa['debito'] < 0 && $mapa['credito'] < 0 => t('Escolha a coluna do valor.'),
            max($usadas) >= $n => t('Escolha as colunas de novo.'),
            count($usadas) !== count(array_unique($usadas)) => t('Cada coluna serve a uma coisa só.'),
            default => null,
        };
    }

    /**
     * As contas ativas e, com a aba Cartão, os cartões ativos: "conta:id" ou "cartao:id" => nome.
     * @return array{contas: array<string, string>, cartoes: array<string, string>}
     */
    public static function destinos(): array
    {
        $contas = $cartoes = [];
        foreach (ContaModel::ativas() as $c) {
            $contas['conta:' . $c['id']] = nomeConta($c);
        }
        foreach (AjusteModel::usa('usa_cartao') ? CartaoModel::ativos() : [] as $c) {
            $cartoes['cartao:' . $c['id']] = $c['nome'];
        }
        return ['contas' => $contas, 'cartoes' => $cartoes];
    }

    /** "conta:3" ou "cartao:2" de um destino que existe e está ativo. @return ?array{tipo:string, id:int} */
    public static function destino(string $valor): ?array
    {
        $d = self::destinos();
        if (!isset($d['contas'][$valor]) && !isset($d['cartoes'][$valor])) {
            return null;
        }
        [$tipo, $id] = explode(':', $valor);
        return ['tipo' => $tipo, 'id' => (int) $id];
    }

    private static function nomeDestino(array $destino): string
    {
        $d = self::destinos();
        return $d[$destino['tipo'] === 'cartao' ? 'cartoes' : 'contas'][$destino['tipo'] . ':' . $destino['id']] ?? '';
    }

    /** O nome da conta ou do cartão de um lote gravado, ativo ou não. */
    private static function nomeDoLote(array $i): string
    {
        if ($i['cartao_id'] !== null) {
            return (string) (CartaoModel::achar((int) $i['cartao_id'])['nome'] ?? '');
        }
        $c = ContaModel::achar((int) $i['conta_id']);
        return $c !== null ? nomeConta($c) : '';
    }

    /** Depois de lançar: o cartão, ou o Extrato no mês da linha mais nova (na conta, quando há mais de uma). */
    private static function depoisDeLancar(array $estado, array $rev): string
    {
        if ($estado['destino']['tipo'] === 'cartao') {
            return CartaoController::url($estado['destino']['id']);
        }
        $datas = array_column(array_filter($rev, static fn(array $r): bool => $r['marcada'] && $r['pode']), 'data');
        $q = ['mes' => substr((string) max($datas), 0, 8) . '01'];
        if (ContaModel::varias()) {
            $q['conta'] = $estado['destino']['id'];
        }
        return '/lancamentos?' . http_build_query($q);
    }
}
