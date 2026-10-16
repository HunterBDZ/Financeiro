<?php
namespace Financeiro\Controllers;

use Financeiro\Auth;
use Financeiro\Models\CartaoModel;
use Financeiro\Models\CategoriaModel;
use Financeiro\Models\MesModel;
use Financeiro\Services\CartaoFatura;
use Financeiro\View;

/**
 * Aba Cartão: o cartão (fechamento, vencimento, limite), a fatura aberta, a escada das próximas
 * faturas (que também escolhe a fatura mostrada), a fatura e os parcelamentos. A compra entra pelo
 * "+" (Pagou com: Cartão): LancamentosController::store chama criarCompraDe(). Com o cartão ligado
 * ao plano, toda mudança reescreve a previsão da Fatura do cartão (CartaoFatura::sincronizarPlano).
 */
final class CartaoController
{
    public function index(): void
    {
        Auth::requireLogin();
        $hoje   = date('Y-m-d');
        $cartao = CartaoModel::ativo();
        $erro   = $_SESSION['cartao_erro'] ?? null;
        unset($_SESSION['cartao_erro']);
        if ($cartao === null) {
            View::render('cartao/index', ['pageTitle' => 'cartão · financeiro', 'cartao' => null, 'erro' => $erro, 'hoje' => $hoje], 'cartao');
            return;
        }
        $cat     = CartaoModel::categoriaFatura();
        $compras = CartaoModel::compras($cartao['id']);
        $aberta  = CartaoFatura::faturaDe($hoje, $cartao['fecha'], $cartao['vence']);
        $previsto = $cat !== null ? CartaoFatura::previsaoAtual($cat, CartaoFatura::somaMeses($aberta, -1)) : [];
        $painel  = CartaoFatura::painel($cartao, $compras, $hoje, $cat !== null ? CartaoModel::pagamentos($cat) : [], $previsto);
        $sel     = is_string($_GET['fatura'] ?? null) && preg_match('/^\d{4}-\d{2}-01$/D', $_GET['fatura']) ? $_GET['fatura'] : $aberta;
        View::render('cartao/index', [
            'pageTitle' => 'cartão · financeiro', 'cartao' => $cartao, 'painel' => $painel, 'hoje' => $hoje,
            'sel' => $sel, 'erro' => $erro, 'temCompras' => $compras !== [],
        ], 'cartao');
    }

    /** POST /cartao/config: cadastra ou atualiza o cartão. */
    public function salvarConfig(): void
    {
        Auth::requireLogin();
        csrfCheck();
        $nome   = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) ($_POST['nome'] ?? '')));
        $fecha  = (int) ($_POST['dia_fechamento'] ?? 0);
        $vence  = (int) ($_POST['dia_vencimento'] ?? 0);
        $limTxt = trim((string) ($_POST['limite'] ?? ''));
        $limite = $limTxt === '' ? null : parseMoedaBr($limTxt);
        $erro = match (true) {
            $nome === '' || mb_strlen($nome) > 60 => 'Dê um nome ao cartão, com até 60 letras.',
            $fecha < 1 || $fecha > 31             => 'O dia do fechamento vai de 1 a 31.',
            $vence < 1 || $vence > 31             => 'O dia do vencimento vai de 1 a 31.',
            $limTxt !== '' && ($limite === null || $limite <= 0 || $limite > 10000000) => 'Limite inválido: use um número como 15.000.',
            default => null,
        };
        if ($erro !== null) {
            $_SESSION['cartao_erro'] = ['msg' => $erro, 'nome' => $nome, 'fecha' => $_POST['dia_fechamento'] ?? '',
                                        'vence' => $_POST['dia_vencimento'] ?? '', 'limite' => $limTxt];
            redirecionar('/cartao#cartao-config', 303);
        }
        $atual = CartaoModel::ativo();
        CartaoModel::salvar($atual['id'] ?? null, $nome, $fecha, $vence, $limite !== null ? round($limite, 2) : null);
        CartaoFatura::sincronizarPlano((int) Auth::id());
        flash('success', ($atual === null ? 'Cartão cadastrado · ' : 'Cartão salvo · ') . $nome);
        redirecionar('/cartao', 303);
    }

    /** POST /cartao/plano: liga (ligar=1) ou desliga o cartão do plano. */
    public function plano(): void
    {
        Auth::requireLogin();
        csrfCheck();
        $cartao = CartaoModel::ativo();
        if ($cartao === null) {
            redirecionar('/cartao', 303);
        }
        $ligar = ($_POST['ligar'] ?? '') === '1';
        CartaoModel::setAlimentaPlano($cartao['id'], $ligar);
        if ($ligar) {
            CartaoFatura::sincronizarPlano((int) Auth::id());
            flash('success', 'O cartão alimenta o plano · a Fatura do cartão de cada mês vem das parcelas lançadas aqui');
        } else {
            flash('success', 'O cartão saiu do plano · a Fatura do cartão volta a ser editável no Mês');
        }
        redirecionar('/cartao', 303);
    }

    /**
     * A compra vinda do "+" (POST /lancamentos com pagou=cartao): valida, grava e reescreve o plano.
     * @return array{erro:?string, aviso:string, id:int}
     */
    public static function criarCompraDe(array $in, ?string $hoje = null): array
    {
        $hoje ??= date('Y-m-d');
        $cartao = CartaoModel::ativo();
        if ($cartao === null) {
            return ['erro' => 'Cadastre o cartão primeiro, na aba Cartão.', 'aviso' => '', 'id' => 0];
        }
        [$erro, $d] = self::validarCompra($in, $cartao, $hoje);
        if ($erro !== null) {
            return ['erro' => $erro, 'aviso' => '', 'id' => 0];
        }
        $id = CartaoModel::criarCompra($cartao['id'], $d['data'], $d['descricao'], $d['categoria_id'], $d['valor'],
                                       $d['parcelas'], $d['primeira'], (int) Auth::id());
        CartaoFatura::sincronizarPlano((int) Auth::id(), $hoje);
        return ['erro' => null, 'aviso' => self::aviso($d), 'id' => $id];
    }

    /** GET /cartao/compras/{id}/editar */
    public function editarForm(string $id): void
    {
        Auth::requireLogin();
        $c = CartaoModel::acharCompra((int) $id);
        $cartao = CartaoModel::ativo();
        if ($c === null || $cartao === null) {
            renderErro(404, 'compra não encontrada', 'pode ter sido excluída');
        }
        $erro = $_SESSION['cartao_erro'] ?? null;
        unset($_SESSION['cartao_erro']);
        $aberta = CartaoFatura::faturaDe(date('Y-m-d'), $cartao['fecha'], $cartao['vence']);
        $meses = [];
        for ($i = -36; $i <= 12; $i++) {
            $meses[] = CartaoFatura::somaMeses($aberta, $i);
        }
        View::render('cartao/editar', [
            'pageTitle' => 'compra no cartão · financeiro', 'compra' => $c, 'cartao' => $cartao, 'meses' => $meses,
            'categorias' => self::categoriasDeGasto(), 'erro' => $erro, 'aberta' => $aberta,
        ], 'cartao');
    }

    /** POST /cartao/compras/{id} */
    public function atualizar(string $id): void
    {
        Auth::requireLogin();
        csrfCheck();
        $c = CartaoModel::acharCompra((int) $id);
        $cartao = CartaoModel::ativo();
        if ($c === null || $cartao === null) {
            renderErro(404, 'compra não encontrada', 'pode ter sido excluída');
        }
        $in = $_POST;
        $prim = is_string($in['primeira_fatura'] ?? null) && preg_match('/^\d{4}-\d{2}-01$/D', $in['primeira_fatura']) ? $in['primeira_fatura'] : null;
        [$erro, $d] = self::validarCompra($in + ['parcela_atual' => ''], $cartao, date('Y-m-d'));
        if ($erro === null && $prim === null) {
            $erro = 'fatura da 1ª parcela?';
        }
        if ($erro !== null) {
            $_SESSION['cartao_erro'] = ['msg' => $erro];
            redirecionar('/cartao/compras/' . (int) $id . '/editar', 303);
        }
        CartaoModel::atualizarCompra((int) $id, $d['data'], $d['descricao'], $d['categoria_id'], $d['valor'], $d['parcelas'], (string) $prim);
        CartaoFatura::sincronizarPlano((int) Auth::id());
        flash('success', 'Compra salva · ' . $d['descricao']);
        redirecionar('/cartao?fatura=' . $prim, 303);
    }

    /** POST /cartao/compras/{id}/excluir (também o "desfazer" do aviso de compra lançada) */
    public function excluir(string $id): void
    {
        Auth::requireLogin();
        csrfCheck();
        $volta = voltaSegura(is_string($_POST['volta'] ?? null) ? $_POST['volta'] : null, '/cartao');
        if (preg_match('#^/cartao/compras/' . (int) $id . '/#', $volta)) {
            $volta = '/cartao';
        }
        $c = CartaoModel::acharCompra((int) $id);
        if ($c === null) {
            flash('warn', 'já tinha sido excluída');
            redirecionar($volta);
        }
        CartaoModel::excluirCompra($c['id']);
        CartaoFatura::sincronizarPlano((int) Auth::id());
        $token = bin2hex(random_bytes(12));
        $_SESSION['cartao_desfazer'] = ['token' => $token, 'compra' => $c];
        flash('success', 'Compra excluída · ' . $c['descricao'], [
            'url' => '/cartao/compras/desfazer', 'label' => 'desfazer', 'campos' => ['volta' => $volta, 'token' => $token],
        ]);
        redirecionar($volta);
    }

    /** POST /cartao/compras/desfazer: a compra excluída volta (com o mesmo id). */
    public function desfazer(): void
    {
        Auth::requireLogin();
        csrfCheck();
        $volta = voltaSegura(is_string($_POST['volta'] ?? null) ? $_POST['volta'] : null, '/cartao');
        $d = $_SESSION['cartao_desfazer'] ?? null;
        $token = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
        if (!is_array($d) || !is_string($d['token'] ?? null) || !hash_equals($d['token'], $token)) {
            flash('warn', 'nada para desfazer');
            redirecionar($volta);
        }
        unset($_SESSION['cartao_desfazer']);
        CartaoModel::restaurarCompra($d['compra']);
        CartaoFatura::sincronizarPlano((int) Auth::id());
        flash('success', 'Compra de volta · ' . $d['compra']['descricao']);
        redirecionar($volta);
    }

    /* ================================================================== validação */

    /**
     * Campos do lançar (valor, categoria_id, data, descricao, parcelas ou parcelas_outro, parcela_atual).
     * @return array{0:?string, 1:?array{valor:float,categoria_id:int,data:string,descricao:string,parcelas:int,primeira:string,cat:array}}
     */
    public static function validarCompra(array $in, array $cartao, string $hoje): array
    {
        $s = static fn(string $k): string => is_string($in[$k] ?? null) ? trim($in[$k]) : '';
        $valor = parseMoedaBr($s('valor'));
        $valor = $valor === null ? null : round(abs($valor), 2);
        if ($valor === null || $valor <= 0 || $valor > 1000000) {
            return ['valor?', null];
        }
        $cat = (int) $s('categoria_id') > 0 ? CategoriaModel::find((int) $s('categoria_id')) : null;
        $catFatura = CartaoModel::categoriaFatura();
        if ($cat === null) {
            return ['categoria?', null];
        }
        if ((int) $cat['sinal'] !== -1 || (int) $cat['operacional'] !== 1 || (int) $cat['id'] === $catFatura) {
            return ['Escolha a categoria do que foi comprado (um gasto).', null];
        }
        $data = LancamentosController::dataDoQuando($in, $hoje);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $data, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || $data < '2015-01-01') {
            return ['data?', null];
        }
        $ultimo = MesModel::ultimo();
        if ($ultimo !== null && $data > date('Y-m-t', strtotime($ultimo))) {
            return ['data depois de ' . monthLabel($ultimo), null];
        }
        $n = (int) ($s('parcelas_outro') !== '' ? $s('parcelas_outro') : $s('parcelas'));
        if ($n < 1 || $n > 48) {
            return ['Em quantas vezes? De 1 a 48.', null];
        }
        $atual = $s('parcela_atual');
        if ($atual !== '' && ((int) $atual < 1 || (int) $atual > $n)) {
            return ['A parcela atual vai de 1 a ' . $n . '.', null];
        }
        $primeira = $atual !== '' && (int) $atual > 1
            ? CartaoFatura::primeiraDaAntiga(CartaoFatura::faturaDe($hoje, $cartao['fecha'], $cartao['vence']), (int) $atual)
            : CartaoFatura::faturaDe($data, $cartao['fecha'], $cartao['vence']);
        $descricao = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s('descricao')));
        if (mb_strlen($descricao) > 255) {
            return ['descrição longa', null];
        }
        if ($descricao === '') {
            $descricao = nomeCurto($cat);
        }
        return [null, ['valor' => $valor, 'categoria_id' => (int) $cat['id'], 'data' => $data, 'descricao' => $descricao,
                       'parcelas' => $n, 'primeira' => $primeira, 'cat' => $cat]];
    }

    /** "Compra no cartão · Máquina de lavar · 10 × 320,00 · nov/26 a ago/27" */
    private static function aviso(array $d): string
    {
        $ps = CartaoFatura::parcelas($d['valor'], $d['parcelas'], $d['primeira']);
        $quanto = $d['parcelas'] === 1 ? num($d['valor']) . ' · fatura de ' . monthLabel($d['primeira'])
            : $d['parcelas'] . ' × ' . num($ps[count($ps) - 1][2]) . ' · ' . monthLabel($d['primeira']) . ' a ' . monthLabel($ps[count($ps) - 1][0]);
        return 'Compra no cartão · ' . $d['descricao'] . ' · ' . $quanto;
    }

    /** @return list<array> categorias de gasto ativas (sem a da fatura), para o editar */
    public static function categoriasDeGasto(): array
    {
        $fat = CartaoModel::categoriaFatura();
        return array_values(array_filter(CategoriaModel::ativas(), static fn($c) => (int) $c['sinal'] === -1
            && (int) $c['operacional'] === 1 && (int) $c['id'] !== $fat));
    }
}
