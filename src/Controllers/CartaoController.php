<?php
namespace Financeiro\Controllers;

use Financeiro\Auth;
use Financeiro\Database;
use Financeiro\Models\CartaoModel;
use Financeiro\Models\CategoriaModel;
use Financeiro\Models\ContaModel;
use Financeiro\Models\LancamentoModel;
use Financeiro\Models\MesModel;
use Financeiro\Relogio;
use Financeiro\Services\CartaoFatura;
use Financeiro\Services\CartaoVisual;
use Financeiro\Services\MotorFinanceiro;
use Financeiro\View;

/**
 * Aba Cartão: os cartões (com mais de um, um seletor no topo; "Novo cartão" cadastra outro), e de cada um a fatura
 * aberta, a escada das próximas faturas (que também escolhe a fatura mostrada), a fatura com "Pagar fatura" e os
 * parcelamentos. A compra entra pelo "+" (Pagou com: o cartão): LancamentosController::store chama criarCompraDe().
 * Com um cartão ligado ao plano, toda mudança reescreve a previsão da Fatura do cartão (CartaoFatura::sincronizarPlano).
 */
final class CartaoController
{
    public function index(): void
    {
        Auth::requireLogin();
        $hoje    = Relogio::hoje();
        $cartoes = CartaoModel::ativos();
        $erro    = $_SESSION['cartao_erro'] ?? null;
        unset($_SESSION['cartao_erro']);
        $contas  = ContaModel::varias() ? ContaModel::ativas() : [];
        if ($cartoes === [] || isset($_GET['novo'])) {
            View::render('cartao/index', ['pageTitle' => t('cartão · MyMonetto'), 'cartao' => null, 'erro' => $erro, 'hoje' => $hoje,
                'cartoes' => $cartoes, 'contas' => $contas], 'cartao');
            return;
        }
        $cartao  = self::escolhido($cartoes, $_GET['cartao'] ?? null);
        $cat     = CartaoModel::categoriaFatura();
        $compras = CartaoModel::compras($cartao['id']);
        $aberta  = CartaoFatura::faturaDe($hoje, $cartao['fecha'], $cartao['vence']);
        $previsto = $cat !== null ? CartaoFatura::previsaoAtual($cat, CartaoFatura::somaMeses($aberta, -1)) : [];
        // com vários cartões, a previsão da Fatura do cartão é a de todos: a conferência compara com a soma deles
        $lancadoTodos = [];
        if (count($cartoes) > 1) {
            foreach ($cartoes as $c) {
                foreach (CartaoFatura::totais(CartaoModel::compras($c['id'])) as $m => $v) {
                    $lancadoTodos[$m] = round(($lancadoTodos[$m] ?? 0.0) + $v, 2);
                }
            }
        }
        // cada pagamento na fatura que pagou; a aba mostra isso mesmo com o cartão desligado em Ajustes
        $pagas   = CartaoFatura::faturasPagas(MotorFinanceiro::dados()['prev'], true)['porCartao'][$cartao['id']] ?? [];
        $painel  = CartaoFatura::painel($cartao, $compras, $hoje, array_map(static fn(array $f): float => $f['pago'], $pagas), $previsto,
                                        array_map(static fn(array $f): string => $f['ultimo'], $pagas));
        $sel     = is_string($_GET['fatura'] ?? null) && preg_match('/^\d{4}-\d{2}-01$/D', $_GET['fatura']) ? $_GET['fatura'] : $aberta;
        View::render('cartao/index', [
            'pageTitle' => t('cartão · MyMonetto'), 'cartao' => $cartao, 'painel' => $painel, 'hoje' => $hoje,
            'sel' => $sel, 'erro' => $erro, 'temCompras' => $compras !== [], 'cartoes' => $cartoes, 'contas' => $contas,
            'lancadoTodos' => $lancadoTodos, 'temFatura' => $cat !== null,
            // como ordenar a lista da fatura e a dos parcelamentos (como no Extrato); '' = o padrão de cada uma
            'ordem' => in_array($_GET['ordem'] ?? '', ['valor-asc', 'alfa', 'parcelas-desc', 'parcelas-asc'], true) ? (string) $_GET['ordem'] : '',
            'ordemp' => in_array($_GET['ordemp'] ?? '', ['valor-desc', 'valor-asc', 'alfa', 'faltam-desc'], true) ? (string) $_GET['ordemp'] : '',
        ], 'cartao');
    }

    /** O cartão pedido na URL (?cartao=id), se for um dos ativos; senão o primeiro. */
    private static function escolhido(array $cartoes, mixed $pedido): array
    {
        foreach ($cartoes as $c) {
            if (is_string($pedido) && (int) $pedido === $c['id']) {
                return $c;
            }
        }
        return $cartoes[0];
    }

    /** /cartao, ou /cartao?cartao=id quando há mais de um (com o resto da consulta). */
    public static function url(int $cartaoId, string $resto = ''): string
    {
        $q = count(CartaoModel::ativos()) > 1 ? 'cartao=' . $cartaoId : '';
        $q .= $resto !== '' ? ($q !== '' ? '&' : '') . $resto : '';
        return '/cartao' . ($q !== '' ? '?' . $q : '');
    }

    /** POST /cartao/config: cadastra (novo=1, ou o primeiro) ou atualiza o cartão `id`. */
    public function salvarConfig(): void
    {
        Auth::requireLogin();
        csrfCheck();
        $nome   = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) ($_POST['nome'] ?? '')));
        $fecha  = (int) ($_POST['dia_fechamento'] ?? 0);
        $vence  = (int) ($_POST['dia_vencimento'] ?? 0);
        $limTxt = trim((string) ($_POST['limite'] ?? ''));
        $limite = $limTxt === '' ? null : parseMoedaBr($limTxt);
        $cartoes = CartaoModel::ativos();
        $novo   = $cartoes === [] || ($_POST['novo'] ?? '') === '1';
        $atual  = $novo ? null : self::escolhido($cartoes, $_POST['id'] ?? null);
        $contaId = (int) ($_POST['conta_id'] ?? 0);
        $conta  = $contaId > 0 ? ContaModel::achar($contaId) : null;
        [$erroVisual, $visual] = CartaoVisual::validar($_POST['visual'] ?? '', $_POST['visual_cor'] ?? '');
        $erro = match (true) {
            $nome === '' || mb_strlen($nome) > 60 => t('Dê um nome ao cartão, com até 60 letras.'),
            CartaoModel::nomeUsado($nome, $atual['id'] ?? null) => t('Já existe um cartão com esse nome.'),
            $fecha < 1 || $fecha > 31             => t('O dia do fechamento vai de 1 a 31.'),
            $vence < 1 || $vence > 31             => t('O dia do vencimento vai de 1 a 31.'),
            $limTxt !== '' && ($limite === null || $limite <= 0 || $limite > min(valorMaximo() * 10, 9999999999.0)) => t('Limite inválido: use um número como 15.000.'),
            $erroVisual !== null                  => $erroVisual,
            default => null,
        };
        if ($erro !== null) {
            $_SESSION['cartao_erro'] = ['msg' => $erro, 'nome' => $nome, 'fecha' => $_POST['dia_fechamento'] ?? '',
                                        'vence' => $_POST['dia_vencimento'] ?? '', 'limite' => $limTxt, 'visual' => $visual];
            redirecionar($novo && $cartoes !== [] ? '/cartao?novo=1' : self::url((int) ($atual['id'] ?? 0)) . '#cartao-config', 303);
        }
        $contaId = $conta !== null && $conta['ativa'] ? $conta['id'] : ($atual['conta_id'] ?? ContaModel::padrao());
        $id = CartaoModel::salvar($atual['id'] ?? null, $nome, $fecha, $vence, $limite !== null ? round($limite, 2) : null, $contaId, $visual);
        $vieram = 0;
        if ($cartoes === []) {
            $vieram = CartaoModel::importarAntigas($id);              // compras da versão antiga do app
            CartaoFatura::preencherPagamentos(Database::connection());  // os pagamentos de fatura que já havia vão para ele
            MotorFinanceiro::limparCache();
        }
        CartaoFatura::sincronizarPlano((int) Auth::id());
        flash('success', ($atual === null ? t('Cartão cadastrado · {nome}', ['nome' => $nome]) : t('Cartão salvo · {nome}', ['nome' => $nome]))
            . ($vieram > 0 ? ' · ' . tn($vieram, '{n} compra antiga veio junto', '{n} compras antigas vieram junto') : ''));
        redirecionar(self::url($id), 303);
    }

    /** POST /cartao/plano: liga (ligar=1) ou desliga o cartão `cartao` do plano. */
    public function plano(): void
    {
        Auth::requireLogin();
        csrfCheck();
        $cartoes = CartaoModel::ativos();
        if ($cartoes === []) {
            redirecionar('/cartao', 303);
        }
        $cartao = self::escolhido($cartoes, $_POST['cartao'] ?? null);
        $ligar = ($_POST['ligar'] ?? '') === '1';
        CartaoModel::setAlimentaPlano($cartao['id'], $ligar);
        if ($ligar) {
            CartaoFatura::sincronizarPlano((int) Auth::id());
            flash('success', t('O cartão alimenta o plano · a Fatura do cartão de cada mês vem das parcelas lançadas aqui'));
        } else {
            CartaoFatura::sincronizarPlano((int) Auth::id());     // os outros cartões ligados continuam
            flash('success', t('O cartão saiu do plano · a Fatura do cartão volta a ser editável no Mês'));
        }
        redirecionar(self::url($cartao['id']), 303);
    }

    /** POST /cartao/arquivar: o cartão sai da aba e do plano; as compras e os pagamentos ficam. */
    public function arquivar(): void
    {
        Auth::requireLogin();
        csrfCheck();
        $cartoes = CartaoModel::ativos();
        if ($cartoes === []) {
            redirecionar('/cartao', 303);
        }
        $cartao = self::escolhido($cartoes, $_POST['cartao'] ?? null);
        CartaoModel::arquivar($cartao['id']);
        CartaoFatura::sincronizarPlano((int) Auth::id());
        flash('success', t('Cartão arquivado · {nome}', ['nome' => $cartao['nome']]));
        redirecionar('/cartao', 303);
    }

    /**
     * POST /cartao/pagar: o pagamento de uma fatura, já ligado a ela (cartao_id e fatura), na conta escolhida ou na
     * do cartão. É um lançamento da categoria da fatura, como os outros: aparece no Extrato e pode ser desfeito.
     */
    public function pagar(): void
    {
        Auth::requireLogin();
        csrfCheck();
        $cartoes = CartaoModel::ativos();
        $cat = CartaoModel::categoriaFatura();
        if ($cartoes === [] || $cat === null) {
            redirecionar('/cartao', 303);
        }
        $cartao = self::escolhido($cartoes, $_POST['cartao'] ?? null);
        $fatura = is_string($_POST['fatura'] ?? null) && preg_match('/^\d{4}-\d{2}-01$/D', $_POST['fatura']) ? $_POST['fatura'] : null;
        $volta = self::url($cartao['id'], $fatura !== null ? 'fatura=' . $fatura : '') . '#fatura';
        $valor = parseMoedaBr(is_string($_POST['valor'] ?? null) ? $_POST['valor'] : null);
        $valor = $valor === null ? null : round(abs($valor), 2);
        $data = LancamentosController::dataDoQuando($_POST);
        $contaId = (int) ($_POST['conta_id'] ?? 0);
        $conta = $contaId > 0 ? ContaModel::achar($contaId) : ContaModel::achar($cartao['conta_id']);
        $conta = $conta !== null && $conta['ativa'] ? $conta : ContaModel::achar(ContaModel::padrao());
        $erro = match (true) {
            $fatura === null => t('Escolha a fatura.'),
            $valor === null || $valor <= 0 || $valor > valorMaximo() => t('Digite um valor.'),
            default => LancamentosController::validarData($data),
        };
        if ($erro !== null) {
            flash('error', $erro);
            redirecionar($volta, 303);
        }
        $catRow = CategoriaModel::find($cat);
        MesModel::ensureExists(substr($data, 0, 8) . '01');
        $id = LancamentoModel::criar($data, (string) $catRow['nome'], $cat, $valor, (int) Auth::id(), (int) $conta['id'],
                                     ['cartao_id' => $cartao['id'], 'fatura' => (string) $fatura]);
        MotorFinanceiro::limparCache();
        flash('success', t('Pagamento da fatura de {mes} lançado · {valor}', ['mes' => mesExtenso((string) $fatura), 'valor' => num($valor)]), [
            'url' => '/lancamentos/' . $id . '/excluir', 'label' => t('desfazer'), 'campos' => ['volta' => $volta],
        ]);
        redirecionar($volta);
    }

    /**
     * A compra vinda do "+" (POST /lancamentos com pagou=cartao, ou cartao:id): valida, grava e reescreve o plano.
     * @return array{erro:?string, aviso:string, id:int, cartao:int}
     */
    public static function criarCompraDe(array $in, ?string $hoje = null, ?int $cartaoId = null): array
    {
        $hoje ??= Relogio::hoje();
        $cartoes = CartaoModel::ativos();
        if ($cartoes === []) {
            return ['erro' => t('Cadastre o cartão primeiro, na aba Cartão.'), 'aviso' => '', 'id' => 0, 'cartao' => 0];
        }
        $cartao = self::escolhido($cartoes, $cartaoId !== null ? (string) $cartaoId : null);
        [$erro, $d] = self::validarCompra($in, $cartao, $hoje);
        if ($erro !== null) {
            return ['erro' => $erro, 'aviso' => '', 'id' => 0, 'cartao' => $cartao['id']];
        }
        $id = CartaoModel::criarCompra($cartao['id'], $d['data'], $d['descricao'], $d['categoria_id'], $d['valor'],
                                       $d['parcelas'], $d['primeira'], (int) Auth::id());
        CartaoFatura::sincronizarPlano((int) Auth::id(), $hoje);
        return ['erro' => null, 'aviso' => self::aviso($d), 'id' => $id, 'cartao' => $cartao['id']];
    }

    /** GET /cartao/compras/{id}/editar */
    public function editarForm(string $id): void
    {
        Auth::requireLogin();
        $c = CartaoModel::acharCompra((int) $id);
        $cartao = $c !== null ? CartaoModel::achar($c['cartao_id']) : null;
        if ($c === null || $cartao === null) {
            renderErro(404, t('Compra não encontrada'), t('Pode ter sido excluída.'));
        }
        $erro = $_SESSION['cartao_erro'] ?? null;
        unset($_SESSION['cartao_erro']);
        $aberta = CartaoFatura::faturaDe(Relogio::hoje(), $cartao['fecha'], $cartao['vence']);
        $meses = [];
        for ($i = -36; $i <= 12; $i++) {
            $meses[] = CartaoFatura::somaMeses($aberta, $i);
        }
        View::render('cartao/editar', [
            'pageTitle' => t('compra no cartão · MyMonetto'), 'compra' => $c, 'cartao' => $cartao, 'meses' => $meses,
            'categorias' => self::categoriasDeGasto(), 'erro' => $erro, 'aberta' => $aberta, 'volta' => self::url($cartao['id'], 'fatura=' . $c['primeira_fatura']),
        ], 'cartao');
    }

    /** POST /cartao/compras/{id} */
    public function atualizar(string $id): void
    {
        Auth::requireLogin();
        csrfCheck();
        $c = CartaoModel::acharCompra((int) $id);
        $cartao = $c !== null ? CartaoModel::achar($c['cartao_id']) : null;
        if ($c === null || $cartao === null) {
            renderErro(404, t('Compra não encontrada'), t('Pode ter sido excluída.'));
        }
        $in = $_POST;
        $prim = is_string($in['primeira_fatura'] ?? null) && preg_match('/^\d{4}-\d{2}-01$/D', $in['primeira_fatura']) ? $in['primeira_fatura'] : null;
        [$erro, $d] = self::validarCompra($in + ['parcela_atual' => ''], $cartao, Relogio::hoje());
        if ($erro === null && $prim === null) {
            $erro = t('Escolha a fatura da 1ª parcela.');
        }
        if ($erro !== null) {
            $_SESSION['cartao_erro'] = ['msg' => $erro];
            redirecionar('/cartao/compras/' . (int) $id . '/editar', 303);
        }
        CartaoModel::atualizarCompra((int) $id, $d['data'], $d['descricao'], $d['categoria_id'], $d['valor'], $d['parcelas'], (string) $prim);
        CartaoFatura::sincronizarPlano((int) Auth::id());
        flash('success', t('Compra salva · {descricao}', ['descricao' => nomeCategoria((string) $d['descricao'])]));
        redirecionar(self::url($cartao['id'], 'fatura=' . $prim), 303);
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
            flash('warn', t('Essa compra já tinha sido excluída.'));
            redirecionar($volta);
        }
        CartaoModel::excluirCompra($c['id']);
        CartaoFatura::sincronizarPlano((int) Auth::id());
        $token = bin2hex(random_bytes(12));
        $_SESSION['cartao_desfazer'] = ['token' => $token, 'compra' => $c];
        flash('success', t('Compra excluída · {descricao}', ['descricao' => nomeCategoria((string) $c['descricao'])]), [
            'url' => '/cartao/compras/desfazer', 'label' => t('desfazer'), 'campos' => ['volta' => $volta, 'token' => $token],
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
            flash('warn', t('Não há nada para desfazer.'));
            redirecionar($volta);
        }
        unset($_SESSION['cartao_desfazer']);
        CartaoModel::restaurarCompra($d['compra']);
        CartaoFatura::sincronizarPlano((int) Auth::id());
        flash('success', t('Compra de volta · {descricao}', ['descricao' => nomeCategoria((string) $d['compra']['descricao'])]));
        redirecionar($volta);
    }

    /* ================================================================== validação */

    /**
     * Campos do lançar (valor, categoria_id, data, descricao, parcelas ou parcelas_outro, parcela_atual, estorno).
     * O estorno (estorno=1) é o valor que volta para o cartão: negativo, em 1 parcela, na fatura da data dele.
     * @return array{0:?string, 1:?array{valor:float,categoria_id:int,data:string,descricao:string,parcelas:int,primeira:string,cat:array,estorno:bool}}
     */
    public static function validarCompra(array $in, array $cartao, string $hoje): array
    {
        $s = static fn(string $k): string => is_string($in[$k] ?? null) ? trim($in[$k]) : '';
        $estorno = $s('estorno') === '1';
        $valor = parseMoedaBr($s('valor'));
        $valor = $valor === null ? null : round(abs($valor), 2);
        if ($valor === null || $valor <= 0 || $valor > valorMaximo()) {
            return [t('Digite um valor.'), null];
        }
        $cat = (int) $s('categoria_id') > 0 ? CategoriaModel::find((int) $s('categoria_id')) : null;
        $catFatura = CartaoModel::categoriaFatura();
        if ($cat === null) {
            return [t('Escolha uma categoria.'), null];
        }
        if ((int) $cat['sinal'] !== -1 || (int) $cat['operacional'] !== 1 || (int) $cat['id'] === $catFatura) {
            return [t('Escolha a categoria do que foi comprado (um gasto).'), null];
        }
        $data = LancamentosController::dataDoQuando($in, $hoje);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $data, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || $data < '2015-01-01') {
            return [t('Escolha uma data válida.'), null];
        }
        $ultimo = MesModel::ultimo();
        if ($ultimo !== null && $data > date('Y-m-t', strtotime($ultimo))) {
            return [t('A data é depois de {mes}. Escolha outra.', ['mes' => mesAno($ultimo)]), null];
        }
        $n = $estorno ? 1 : (int) ($s('parcelas_outro') !== '' ? $s('parcelas_outro') : $s('parcelas'));
        if ($n < 1 || $n > 48) {
            return [t('Em quantas vezes? De 1 a 48.'), null];
        }
        $atual = $estorno ? '' : $s('parcela_atual');
        if ($atual !== '' && ((int) $atual < 1 || (int) $atual > $n)) {
            return [t('A parcela atual vai de 1 a {n}.', ['n' => $n]), null];
        }
        $primeira = $atual !== '' && (int) $atual > 1
            ? CartaoFatura::primeiraDaAntiga(CartaoFatura::faturaDe($hoje, $cartao['fecha'], $cartao['vence']), (int) $atual)
            : CartaoFatura::faturaDe($data, $cartao['fecha'], $cartao['vence']);
        $descricao = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s('descricao')));
        if (mb_strlen($descricao) > 255) {
            return [t('Descrição muito longa. Use até 255 letras.'), null];
        }
        if ($descricao === '') {
            $descricao = nomeCurtoBanco($cat);
        }
        return [null, ['valor' => $estorno ? -$valor : $valor, 'categoria_id' => (int) $cat['id'], 'data' => $data, 'descricao' => $descricao,
                       'parcelas' => $n, 'primeira' => $primeira, 'cat' => $cat, 'estorno' => $estorno]];
    }

    /** "Compra no cartão · Máquina de lavar · 10 × 320,00 · nov/26 a ago/27"; o estorno diz em que fatura volta. */
    private static function aviso(array $d): string
    {
        if ($d['estorno']) {
            return t('Estorno no cartão · {descricao} · {valor} · fatura de {mes}', ['descricao' => nomeCategoria((string) $d['descricao']),
                'valor' => num(-$d['valor']), 'mes' => monthLabel($d['primeira'])]);
        }
        $ps = CartaoFatura::parcelas($d['valor'], $d['parcelas'], $d['primeira']);
        $quanto = $d['parcelas'] === 1 ? t('{valor} · fatura de {mes}', ['valor' => num($d['valor']), 'mes' => monthLabel($d['primeira'])])
            : t('{n} × {valor} · {de} a {ate}', ['n' => $d['parcelas'], 'valor' => num($ps[count($ps) - 1][2]), 'de' => monthLabel($d['primeira']), 'ate' => monthLabel($ps[count($ps) - 1][0])]);
        return t('Compra no cartão · {descricao} · {quanto}', ['quanto' => $quanto, 'descricao' => nomeCategoria((string) $d['descricao'])]);
    }

    /** @return list<array> categorias de gasto ativas (sem a da fatura), para o editar */
    public static function categoriasDeGasto(): array
    {
        $fat = CartaoModel::categoriaFatura();
        return array_values(array_filter(CategoriaModel::ativas(), static fn($c) => (int) $c['sinal'] === -1
            && (int) $c['operacional'] === 1 && (int) $c['id'] !== $fat));
    }
}
