<?php
namespace Financeiro\Controllers;

use Financeiro\Auth;
use Financeiro\Models\ContaModel;
use Financeiro\Models\MesModel;
use Financeiro\Models\TransferenciaModel;
use Financeiro\View;

/**
 * Transferências entre contas: o modo Transferir do lançar (LancamentosController::store chama criarDe), e editar,
 * excluir e desfazer, a partir do Extrato. Não são renda nem gasto: o plano e o total em conta não mudam.
 * Mês fechado não muda: nenhuma delas entra, sai ou muda num mês fechado (LancamentosController::erroMesFechado).
 */
final class TransferenciaController
{
    /**
     * Valida e grava (campos do lançar: valor, data ou quando, de_conta, para_conta, descricao). Se a conta de onde sai
     * fica negativa (na data ou depois, com o que já está lançado), não grava sem confirmar_negativo=1: devolve
     * `negativo` (a conta e com quanto fica), e o lançar pergunta. Não é proibido: a pessoa sabe se o banco deixa.
     * @return array{erro:?string, id:int, aviso:string, data:string, negativo?:array{conta:string, valor:float}}
     */
    public static function criarDe(array $in): array
    {
        [$erro, $d] = self::validar($in);
        if ($erro !== null) {
            return ['erro' => $erro, 'id' => 0, 'aviso' => '', 'data' => ''];
        }
        if (($in['confirmar_negativo'] ?? '') !== '1') {
            $fica = round(min(ContaModel::saldo($d['de']['id'], $d['data']), ContaModel::saldo($d['de']['id'], '9999-12-31')) - $d['valor'], 2);
            if ($fica < -0.004) {
                return ['erro' => null, 'id' => 0, 'aviso' => '', 'data' => $d['data'], 'negativo' => ['conta' => nomeConta($d['de']), 'valor' => $fica]];
            }
        }
        MesModel::ensureExists(substr($d['data'], 0, 8) . '01');
        $id = TransferenciaModel::criar($d['data'], $d['de']['id'], $d['para']['id'], $d['valor'], $d['descricao'], (int) Auth::id());
        return ['erro' => null, 'id' => $id, 'aviso' => self::aviso($d), 'data' => $d['data']];
    }

    /** GET /transferencias/{id}/editar */
    public function editarForm(string $id): void
    {
        Auth::requireLogin();
        $t = TransferenciaModel::find((int) $id);
        if ($t === null) {
            renderErro(404, t('Transferência não encontrada'), t('Pode ter sido excluída.'));
        }
        $erro = $_SESSION['transf_erro'] ?? null;
        unset($_SESSION['transf_erro']);
        View::render('transferencias/editar', [
            'pageTitle' => t('transferência · MyMonetto'), 'transf' => $t, 'erro' => is_string($erro) ? $erro : null,
            'fechado' => LancamentosController::erroMesFechado((string) $t['data']),
            'contas' => self::contasPara($t), 'volta' => voltaSegura(is_string($_GET['volta'] ?? null) ? $_GET['volta'] : null, '/lancamentos?mes=' . substr((string) $t['data'], 0, 8) . '01'),
        ], 'extrato');
    }

    /** POST /transferencias/{id} */
    public function atualizar(string $id): void
    {
        Auth::requireLogin();
        csrfCheck();
        $t = TransferenciaModel::find((int) $id);
        if ($t === null) {
            renderErro(404, t('Transferência não encontrada'), t('Pode ter sido excluída.'));
        }
        $volta = voltaSegura(is_string($_POST['volta'] ?? null) ? $_POST['volta'] : null, '/lancamentos');
        [$erro, $d] = self::validar($_POST, $t);
        $erro = LancamentosController::erroMesFechado((string) $t['data']) ?? $erro;     // nem sai de um mês fechado
        if ($erro !== null) {
            $_SESSION['transf_erro'] = $erro;
            redirecionar('/transferencias/' . (int) $id . '/editar?volta=' . rawurlencode($volta), 303);
        }
        MesModel::ensureExists(substr($d['data'], 0, 8) . '01');
        TransferenciaModel::atualizar((int) $id, $d['data'], $d['de']['id'], $d['para']['id'], $d['valor'], $d['descricao']);
        flash('success', t('{texto} · salvo', ['texto' => self::aviso($d)]));
        redirecionar($volta, 303);
    }

    /** POST /transferencias/{id}/excluir (também o "desfazer" do aviso de transferência feita) */
    public function excluir(string $id): void
    {
        Auth::requireLogin();
        csrfCheck();
        $volta = voltaSegura(is_string($_POST['volta'] ?? null) ? $_POST['volta'] : null, '/lancamentos');
        if (preg_match('#^/transferencias/' . (int) $id . '/#', $volta)) {
            $volta = '/lancamentos';
        }
        $t = TransferenciaModel::find((int) $id);
        if ($t === null) {
            flash('warn', t('Essa transferência já tinha sido excluída.'));
            redirecionar($volta);
        }
        if (($fechado = LancamentosController::erroMesFechado((string) $t['data'])) !== null) {
            flash('error', $fechado);
            redirecionar($volta);
        }
        TransferenciaModel::excluir((int) $t['id']);
        $token = bin2hex(random_bytes(12));
        $_SESSION['transf_desfazer'] = ['token' => $token, 'linha' => $t];
        flash('success', t('Transferência excluída · {valor}', ['valor' => num((float) $t['valor'])]), [
            'url' => '/transferencias/desfazer', 'label' => t('desfazer'), 'campos' => ['volta' => $volta, 'token' => $token],
        ]);
        redirecionar($volta);
    }

    /** POST /transferencias/desfazer: a transferência excluída volta, com o mesmo id. */
    public function desfazer(): void
    {
        Auth::requireLogin();
        csrfCheck();
        $volta = voltaSegura(is_string($_POST['volta'] ?? null) ? $_POST['volta'] : null, '/lancamentos');
        $d = $_SESSION['transf_desfazer'] ?? null;
        $token = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
        if (!is_array($d) || !is_string($d['token'] ?? null) || !hash_equals($d['token'], $token)) {
            flash('warn', t('Não há nada para desfazer.'));
            redirecionar($volta);
        }
        unset($_SESSION['transf_desfazer']);
        if (($fechado = LancamentosController::erroMesFechado((string) $d['linha']['data'])) !== null) {
            flash('error', $fechado);
            redirecionar($volta);
        }
        TransferenciaModel::restaurar($d['linha']);
        flash('success', t('Transferência de volta · {valor}', ['valor' => num((float) $d['linha']['valor'])]));
        redirecionar($volta);
    }

    /**
     * @param ?array $atual a transferência que está sendo editada: as contas dela valem mesmo se arquivadas
     * @return array{0:?string, 1:?array{data:string, de:array, para:array, valor:float, descricao:string}}
     */
    public static function validar(array $in, ?array $atual = null): array
    {
        $s = static fn(string $k): string => is_string($in[$k] ?? null) ? trim($in[$k]) : '';
        $valor = parseMoedaBr($s('valor'));
        $valor = $valor === null ? null : round(abs($valor), 2);
        if ($valor === null || $valor <= 0 || $valor > valorMaximo()) {
            return [motivoValor($s('valor')) ?? t('Digite um valor.'), null];
        }
        $pode = static function (int $id) use ($atual): ?array {
            $c = ContaModel::achar($id);
            return $c !== null && ($c['ativa'] || ($atual !== null && in_array($id, [(int) $atual['de_conta_id'], (int) $atual['para_conta_id']], true))) ? $c : null;
        };
        $de = $pode((int) $s('de_conta'));
        $para = $pode((int) $s('para_conta'));
        if ($de === null || $para === null) {
            return [t('Escolha de que conta sai e para qual vai.'), null];
        }
        if ($de['id'] === $para['id']) {
            return [t('Escolha duas contas diferentes.'), null];
        }
        $data = LancamentosController::dataDoQuando($in);
        if (($erro = LancamentosController::validarData($data) ?? LancamentosController::erroMesFechado($data)) !== null) {
            return [$erro, null];
        }
        $descricao = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s('descricao')));
        if (mb_strlen($descricao) > 255) {
            return [t('Descrição muito longa. Use até 255 letras.'), null];
        }
        return [null, ['data' => $data, 'de' => $de, 'para' => $para, 'valor' => $valor, 'descricao' => $descricao]];
    }

    /** "Transferência · 500,00 de Conta principal para Carteira" */
    private static function aviso(array $d): string
    {
        return t('Transferência · {valor} de {de} para {para}', ['valor' => num($d['valor']), 'de' => nomeConta($d['de']), 'para' => nomeConta($d['para'])]);
    }

    /** As contas que a edição oferece: as ativas e as da própria transferência. @return list<array> */
    private static function contasPara(array $t): array
    {
        return array_values(array_filter(ContaModel::todas(), static fn(array $c): bool => $c['ativa']
            || in_array($c['id'], [(int) $t['de_conta_id'], (int) $t['para_conta_id']], true)));
    }
}
