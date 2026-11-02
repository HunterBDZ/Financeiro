<?php
namespace Financeiro\Controllers;

use Financeiro\Auth;
use Financeiro\Models\MesModel;
use Financeiro\Models\WishlistModel;
use Financeiro\Services\Cotacao;
use Financeiro\Services\LinkProduto;
use Financeiro\Services\MotorFinanceiro;
use Financeiro\Services\WishlistPrevisao;
use Financeiro\Site;
use Financeiro\View;

/**
 * Aba Wishlist: a lista do que o casal quer comprar, em ordem, e em que mês o 1º item cabe no plano.
 * A previsão vem na URL (GET, ?mes=…&parcelas=…) e nada é gravado; com parcial=1 devolve só a previsão.
 * O valor fica na moeda escolhida e é convertido para reais pela cotação do dia ao mostrar (Cotacao).
 * "Comprei" tira o item da lista e abre o lançamento preenchido, em reais. Excluir tem desfazer.
 */
final class WishlistController
{
    private const VOLTA = '/wishlist';
    private const MAX_VALOR = 1000000.0;
    private const MAX_VALOR_FORA = 1000000000.0;   // iene e peso têm valores altos

    public function index(): void
    {
        Auth::requireLogin();
        $taxas = Cotacao::atuais();
        $itens = self::comReais(WishlistModel::ativos(), $taxas);
        $hoje  = date('Y-m-d');
        $dados = MotorFinanceiro::dados();
        $prev  = null;
        $parcelas = in_array((int) ($_GET['parcelas'] ?? 1), WishlistPrevisao::PARCELAS, true) ? (int) ($_GET['parcelas'] ?? 1) : 1;
        if ($itens !== [] && $itens[0]['valor_site'] !== null && $dados['meses'] !== []) {
            $prev = WishlistPrevisao::calcular($dados, MesModel::referencia($hoje), (float) $itens[0]['valor_site'], $parcelas, $hoje);
        }
        $pedido = is_string($_GET['mes'] ?? null) ? $_GET['mes'] : '';
        $mes = $prev === null ? '' : (isset($prev['meses'][$pedido]) ? $pedido : ($prev['melhor'] ?? (string) array_key_first($prev['meses'])));

        $data = ['pageTitle' => t('wishlist') . ' · financeiro', 'itens' => $itens, 'prev' => $prev, 'mes' => $mes, 'parcelas' => $parcelas];
        if (($_GET['parcial'] ?? '') === '1') {
            View::partial('wishlist/_previsao', $data);
            return;
        }
        $data['comprados'] = WishlistModel::comprados();
        $data['taxas'] = $taxas;
        $data['form'] = $_SESSION['wishlist_form'] ?? null;
        unset($_SESSION['wishlist_form']);
        View::render('wishlist/index', $data, 'wishlist');
    }

    /** Acrescenta `valor_site` (null sem valor ou sem cotação). */
    private static function comReais(array $itens, array $taxas): array
    {
        foreach ($itens as $k => $i) {
            $itens[$k]['valor_site'] = $i['valor'] === null ? null : Cotacao::naMoedaDoSite((float) $i['valor'], (string) $i['moeda'], $taxas);
        }
        return $itens;
    }

    /** A foto só sai para quem está logado; o endereço muda quando a foto muda. */
    public function foto(string $id): void
    {
        Auth::requireLogin();
        $f = WishlistModel::foto((int) $id);
        if ($f === null) {
            renderErro(404, t('Foto não encontrada'), t('este item não tem foto'));
        }
        $etag = '"' . $f['hash'] . '"';
        header('Cache-Control: private, max-age=31536000, immutable');
        header('ETag: ' . $etag);
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            return;
        }
        header('Content-Type: ' . $f['tipo']);
        header('Content-Length: ' . strlen($f['dados']));
        echo $f['dados'];
    }

    /** POST /wishlist/ler-link: nome, preço e foto do link, em JSON, para preencher o formulário. */
    public function lerLink(): void
    {
        Auth::requireLogin();
        csrfCheck();
        session_write_close();                                  // a loja pode demorar: não trava as outras abas
        header('Content-Type: application/json; charset=utf-8');
        $url = LinkProduto::normalizar((string) ($_POST['url'] ?? ''));
        if ($url === null) {
            echo json_encode(['ok' => false, 'motivo' => 'link']);
            return;
        }
        $r = LinkProduto::ler($url);
        echo json_encode([
            'ok' => $r['lido'], 'url' => $url, 'nome' => $r['nome'],
            'valor' => $r['valor'] !== null ? numInput($r['valor']) : null, 'moeda' => $r['moeda'],
            'foto' => $r['foto'] !== null ? 'data:' . $r['foto']['tipo'] . ';base64,' . base64_encode($r['foto']['dados']) : null,
        ], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function salvar(): void
    {
        Auth::requireLogin();
        csrfCheck();
        $this->gravar(null);
    }

    public function editar(string $id): void
    {
        Auth::requireLogin();
        $item = WishlistModel::find((int) $id);
        if ($item === null) {
            renderErro(404, t('Item não encontrado'), t('pode ter sido excluído'));
        }
        $form = $_SESSION['wishlist_form'] ?? null;
        unset($_SESSION['wishlist_form']);
        View::render('wishlist/editar', ['pageTitle' => t('editar · wishlist'), 'item' => $item, 'form' => $form, 'taxas' => Cotacao::atuais()], 'wishlist');
    }

    public function atualizar(string $id): void
    {
        Auth::requireLogin();
        csrfCheck();
        $item = WishlistModel::find((int) $id);
        if ($item === null) {
            renderErro(404, t('Item não encontrado'), t('pode ter sido excluído'));
        }
        $this->gravar($item);
    }

    /** Lê o formulário, completa com o link o que faltou e grava. */
    private function gravar(?array $item): void
    {
        $voltaErro = $item === null ? self::VOLTA . '#wl-novo' : self::VOLTA . '/' . (int) $item['id'] . '/editar';
        $campos = self::lerCampos($_POST);
        $foto = null;
        $erro = $campos['erro'];
        if ($erro === null) {
            [$foto, $erro] = self::fotoEnviada();
        }
        $form = ['nome' => $campos['nome'], 'url' => (string) ($_POST['url'] ?? ''), 'valor' => (string) ($_POST['valor'] ?? ''), 'moeda' => $campos['moeda']];

        // sem JS, ou se o formulário não leu o link: tenta aqui completar o que está em branco
        $tirarFoto = $item !== null && ($_POST['remover_foto'] ?? '') === '1';
        $semFoto = $foto === null && !$tirarFoto && ($item === null || $item['foto_hash'] === null);
        if ($erro === null && $campos['url'] !== null && ($_POST['lido'] ?? '') !== '1' && ($campos['nome'] === '' || $campos['valor'] === null || $semFoto)) {
            $lido = LinkProduto::ler($campos['url']);
            $campos['nome'] = $campos['nome'] !== '' ? $campos['nome'] : (string) $lido['nome'];
            if ($campos['valor'] === null && $lido['valor'] !== null) {
                $campos['valor'] = $lido['valor'];
                $campos['moeda'] = $lido['moeda'];
            }
            $foto ??= $semFoto ? $lido['foto'] : null;
        }
        if ($erro === null && $campos['nome'] === '') {
            $erro = $campos['url'] !== null
                ? t('Não consegui ler o nome nesse link: escreva o nome do item.')
                : t('Escreva o nome do item ou cole o link.');
        }
        if ($erro !== null) {
            $_SESSION['wishlist_form'] = $form + ['erro' => $erro];
            redirecionar($voltaErro, 303);
        }

        if ($item === null) {
            $id = WishlistModel::criar($campos['nome'], $campos['url'], $campos['valor'], (int) Auth::id(), $campos['moeda']);
            $aviso = t('{nome} entrou na lista', ['nome' => $campos['nome']]);
            $acao = ['url' => self::VOLTA . '/' . $id . '/excluir', 'label' => t('desfazer')];
        } else {
            $id = (int) $item['id'];
            WishlistModel::atualizar($id, $campos['nome'], $campos['url'], $campos['valor'], $campos['moeda']);
            $aviso = t('Item salvo');
            $acao = null;
        }
        if ($foto !== null) {
            WishlistModel::salvarFoto($id, $foto);
        } elseif ($tirarFoto) {
            WishlistModel::removerFoto($id);
        }
        flash('success', $aviso, $acao);
        redirecionar(self::VOLTA, 303);
    }

    /**
     * Valida nome, link, valor e moeda.
     * @return array{nome:string, url:?string, valor:?float, moeda:string, erro:?string}
     */
    public static function lerCampos(array $in): array
    {
        $nome = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) ($in['nome'] ?? ''))), 0, 160);
        $brutoUrl = trim((string) ($in['url'] ?? ''));
        $url = $brutoUrl === '' ? null : LinkProduto::normalizar($brutoUrl);
        $brutoValor = trim((string) ($in['valor'] ?? ''));
        $valor = null;
        $erro = null;
        $moeda = (string) ($in['moeda'] ?? Site::moeda());
        $moeda = Cotacao::valida($moeda) ? $moeda : Site::moeda();
        $teto = $moeda === 'BRL' ? self::MAX_VALOR : self::MAX_VALOR_FORA;
        if ($brutoUrl !== '' && $url === null) {
            $erro = t('Esse link não é válido: copie o endereço completo da página do produto.');
        } elseif ($brutoValor !== '') {
            $v = preg_match('/^(R\$)?[\s\d.,]+$/u', $brutoValor) ? parseMoedaBr($brutoValor) : null;
            if ($v === null || $v <= 0 || $v > $teto) {
                $erro = t('Valor inválido: use um número maior que zero, como 500 ou 1.250,00.');
            } else {
                $valor = round($v, 2);
            }
        }
        return ['nome' => $nome, 'url' => $url, 'valor' => $valor, 'moeda' => $moeda, 'erro' => $erro];
    }

    /** @return array{0:?array, 1:?string} [foto, erro] */
    private static function fotoEnviada(): array
    {
        $msg = t('Essa foto não serve: use JPG, PNG ou WebP de até 3 MB.');
        $f = $_FILES['foto'] ?? null;
        if (is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
                return [null, $msg];
            }
            $foto = LinkProduto::fotoValida((string) file_get_contents((string) $f['tmp_name']));
            return $foto !== null ? [$foto, null] : [null, $msg];
        }
        $b64 = (string) ($_POST['foto_base64'] ?? '');
        if ($b64 !== '') {
            $bruto = base64_decode((string) preg_replace('~^data:image/[a-z]+;base64,~', '', $b64), true);
            $foto = $bruto !== false ? LinkProduto::fotoValida($bruto) : null;
            return $foto !== null ? [$foto, null] : [null, $msg];
        }
        return [null, null];
    }

    public function mover(string $id): void
    {
        Auth::requireLogin();
        csrfCheck();
        WishlistModel::mover((int) $id, (string) ($_POST['para'] ?? ''));
        redirecionar(self::VOLTA . '#wl-item-' . (int) $id, 303);
    }

    /** Tira da lista e abre o lançamento preenchido: pagou na conta ou no cartão, a pessoa escolhe lá. */
    public function comprei(string $id): void
    {
        Auth::requireLogin();
        csrfCheck();
        $item = WishlistModel::find((int) $id);
        if ($item === null) {
            redirecionar(self::VOLTA, 303);
        }
        WishlistModel::marcarComprado((int) $id, date('Y-m-d'));
        flash('success', t('{nome} saiu da lista · Lance o gasto para ele entrar no Extrato', ['nome' => $item['nome']]),
            ['url' => self::VOLTA . '/' . (int) $id . '/devolver', 'label' => t('desfazer')]);
        $q = ['descricao' => (string) $item['nome'], 'volta' => self::VOLTA];
        $reais = $item['valor'] === null ? null : Cotacao::naMoedaDoSite((float) $item['valor'], (string) $item['moeda'], Cotacao::atuais());
        if ($reais !== null) {
            $q['valor'] = numInput($reais);
        }
        redirecionar('/lancar?' . http_build_query($q), 303);
    }

    public function devolver(string $id): void
    {
        Auth::requireLogin();
        csrfCheck();
        WishlistModel::devolver((int) $id);
        flash('success', t('Voltou para a lista'));
        redirecionar(self::VOLTA, 303);
    }

    public function excluir(string $id): void
    {
        Auth::requireLogin();
        csrfCheck();
        $item = WishlistModel::find((int) $id);
        if ($item !== null) {
            WishlistModel::excluir((int) $id);
            flash('success', t('{nome} excluído', ['nome' => $item['nome']]), ['url' => self::VOLTA . '/' . (int) $id . '/restaurar', 'label' => t('desfazer')]);
        }
        redirecionar(self::VOLTA, 303);
    }

    public function restaurar(string $id): void
    {
        Auth::requireLogin();
        csrfCheck();
        WishlistModel::restaurar((int) $id);
        flash('success', t('Voltou para a lista'));
        redirecionar(self::VOLTA, 303);
    }
}
