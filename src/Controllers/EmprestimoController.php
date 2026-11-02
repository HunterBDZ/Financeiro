<?php
namespace Financeiro\Controllers;

use Financeiro\Auth;
use Financeiro\Models\AjusteModel;
use Financeiro\Models\LancamentoModel;
use Financeiro\Models\MesModel;
use Financeiro\Relogio;
use Financeiro\Services\EmprestimoSimulacao;
use Financeiro\Services\MotorFinanceiro;
use Financeiro\View;

/**
 * Aba Empréstimo: quanto falta, a simulação de um adiantamento e o lançamento do que foi pago.
 * A simulação vem na URL (GET, ?valor=…&mes=…), então dá para voltar, recarregar ou mandar o link;
 * nada é gravado. Com parcial=1 devolve só o resultado (emprestimo.js atualiza enquanto se digita).
 * POST /emprestimo/adiantamento grava o pagamento como lançamento da Amortização extra: ele entra no
 * Extrato, sai da conta e abate a dívida, com desfazer. POST /emprestimo/dados grava o contrato.
 */
final class EmprestimoController
{
    private const DESCRICAO = 'Adiantamento do empréstimo';

    public function index(): void
    {
        Auth::requireLogin();
        $hoje  = Relogio::hoje();
        $dados = MotorFinanceiro::dados();
        if ($dados['meses'] === []) {
            View::render('dashboard/vazio', ['pageTitle' => t('empréstimo') . ' · financeiro'], 'emprestimo');
            return;
        }
        $ref = MesModel::referencia($hoje);
        $sit = EmprestimoSimulacao::situacao($dados, $ref, $hoje);

        $mes = mesValido(is_string($_GET['mes'] ?? null) ? $_GET['mes'] : null, $sit['meses'], $sit['meses'][0] ?? $ref);
        $campo = trim(is_string($_GET['valor'] ?? null) ? $_GET['valor'] : '');
        $erro = null;
        $valor = 0.0;
        if (($_GET['quitar'] ?? '') === '1') {
            $valor = valorMaximo();     // simular() limita à dívida do mês
        } elseif ($campo !== '') {
            $v = self::valorDe($campo);
            if ($v === null) {
                $erro = self::erroValor();
            } else {
                $valor = $v;
            }
        }
        $sim = $valor > 0 && !$sit['quitado'] ? EmprestimoSimulacao::simular($dados, $ref, $mes, $valor, $hoje) : null;
        if ($sim !== null && ($_GET['quitar'] ?? '') === '1') {
            $sim['ajustado'] = false;
            $sim['valor'] = $sim['aplicado'];
            $campo = numInput($sim['aplicado']);
        }

        $data = [
            'pageTitle' => t('empréstimo') . ' · financeiro', 'sit' => $sit, 'sim' => $sim, 'erro' => $erro,
            'mes' => $mes, 'campo' => $campo, 'hoje' => $hoje, 'catAmort' => $dados['esp']['amort'],
            'pago' => trim(is_string($_GET['pago'] ?? null) ? $_GET['pago'] : ''),   // só o "Já paguei" manda o valor
        ];
        if (($_GET['parcial'] ?? '') === '1') {
            View::partial('emprestimo/_resultado', $data);
            return;
        }
        $catAmort = $dados['esp']['amort'];
        $data['feitos'] = $catAmort !== null ? LancamentoModel::porCategoria((int) $catAmort) : [];
        $data['form'] = $_SESSION['emprestimo_form'] ?? null;
        unset($_SESSION['emprestimo_form']);
        $data['dadosErro'] = $_SESSION['emprestimo_dados_erro'] ?? null;
        unset($_SESSION['emprestimo_dados_erro']);
        $data['dadosAberto'] = ($_GET['dados'] ?? '') === '1' || $data['dadosErro'] !== null;
        $data['aj'] = [];
        foreach (AjusteModel::todos() as $a) {
            $data['aj'][$a['chave']] = ['valor' => (float) $a['valor'], 'confirmado' => (bool) $a['confirmado']];
        }
        $data['taxaCredito'] = AjusteModel::taxaPeloCredito();
        View::render('emprestimo/index', $data, 'emprestimo');
    }

    /** POST /emprestimo/dados: o contrato do empréstimo (juros, parcela, nº de parcelas, 1ª parcela, crédito). */
    public function salvarDados(): void
    {
        Auth::requireLogin();
        csrfCheck();
        $hoje = Relogio::hoje();
        $ref  = MesModel::referencia($hoje);
        $brutos = (array) ($_POST['valores'] ?? []);
        ['valores' => $valores, 'erro' => $errado] = AjustesController::lerValores($brutos, AjusteModel::DO_EMPRESTIMO);
        $msg = $errado !== null ? t('Confira o campo "{campo}": o valor não é válido.', ['campo' => AjustesController::rotulo($errado)]) : null;
        if ($errado === null) {
            [$errado, $msg] = self::conferirSaldo($valores, $hoje, (string) MesModel::primeiro(), (string) MesModel::ultimo());
        }
        if ($errado !== null) {
            $_SESSION['emprestimo_dados_erro'] = ['campo' => $errado, 'valores' => $brutos, 'msg' => $msg];
            redirecionar('/emprestimo?dados=1#emp-dados', 303);
        }
        $antes = EmprestimoSimulacao::situacao(MotorFinanceiro::dados(), $ref, $hoje);
        foreach ($valores as $chave => $v) {
            AjusteModel::setValor($chave, $v);                  // preserva `confirmado`
        }
        if (array_key_exists('juros_mes', $valores)) {
            AjusteModel::setConfirmado('juros_mes', ($_POST['confirmado']['juros_mes'] ?? '') === '1');   // só o juros tem "confirmado"
        }
        MotorFinanceiro::limparCache();
        $depois = EmprestimoSimulacao::situacao(MotorFinanceiro::dados(), $ref, $hoje);
        $fim = static fn(array $s): string => $s['quita'] !== null ? monthLabel($s['quita']) : t('sem data');
        flash('success', t('Dados salvos. Falta {antes} → {depois}; termina em {fim_antes} → {fim_depois}.', [
            'antes' => money((float) $antes['falta']), 'depois' => money((float) $depois['falta']), 'fim_antes' => $fim($antes), 'fim_depois' => $fim($depois),
        ]));
        redirecionar('/emprestimo', 303);
    }

    /**
     * O saldo informado precisa do valor e do mês juntos, num mês do plano que já começou.
     * @return array{0:?string, 1:?string} [campo errado, mensagem]
     */
    public static function conferirSaldo(array $valores, string $hoje, string $primeiro, string $ultimo): array
    {
        $saldo = (float) ($valores['saldo_devedor'] ?? 0);
        $mes   = (int) round((float) ($valores['saldo_devedor_mes'] ?? 0));
        if ($saldo <= 0 && $mes === 0) {
            return [null, null];
        }
        if ($saldo <= 0) {
            return ['saldo_devedor', t('Informe o saldo devedor, ou deixe o saldo e o mês em branco.')];
        }
        if ($mes === 0) {
            return ['saldo_devedor_mes', t('Informe o mês do saldo, ou deixe o saldo e o mês em branco.')];
        }
        $iso = sprintf('%04d-%02d-01', intdiv($mes, 100), $mes % 100);
        if ($iso > substr($hoje, 0, 8) . '01') {
            return ['saldo_devedor_mes', t('O mês do saldo não pode ser futuro: use o mês do extrato do empréstimo.')];
        }
        if ($iso < $primeiro || $iso > $ultimo) {
            return ['saldo_devedor_mes', t('O mês do saldo precisa estar dentro do plano (a partir de {mes}).', ['mes' => monthLabel($primeiro)])];
        }
        return [null, null];
    }

    /** POST /emprestimo/adiantamento: lança no Extrato um adiantamento já pago. */
    public function registrar(): void
    {
        Auth::requireLogin();
        csrfCheck();
        $hoje  = Relogio::hoje();
        $dados = MotorFinanceiro::dados();
        $cat   = $dados['esp']['amort'];
        $campoValor = trim(is_string($_POST['valor'] ?? null) ? $_POST['valor'] : '');
        $campoData  = trim(is_string($_POST['data'] ?? null) ? $_POST['data'] : '');
        $volta = '/emprestimo#emp-consolidar';
        if ($cat === null) {
            flash('error', t('A categoria Amortização extra não existe.'));
            redirecionar($volta, 303);
        }
        [$erro, $valor] = self::validarAdiantamento($campoValor, $campoData, $hoje);
        $form = ['valor' => $campoValor, 'data' => $campoData];
        if ($erro !== null) {
            $_SESSION['emprestimo_form'] = $form + ['erro' => $erro];
            redirecionar($volta, 303);
        }
        if (($_POST['confirmar_dup'] ?? '') !== '1' && LancamentoModel::existeIgual($campoData, (int) $cat, $valor) !== null) {
            $_SESSION['emprestimo_form'] = $form + ['duplicado' => true];
            redirecionar($volta, 303);
        }

        $mes = substr($campoData, 0, 8) . '01';
        $divida = EmprestimoSimulacao::dividaNoMes($dados, $mes, $hoje);
        MesModel::ensureExists($mes);   // dentro de [primeiro, último]: nunca cria mês novo
        $id = LancamentoModel::criar($campoData, self::DESCRICAO, (int) $cat, $valor, (int) Auth::id());
        MotorFinanceiro::limparCache();

        $sit = EmprestimoSimulacao::situacao(MotorFinanceiro::dados(), MesModel::referencia($hoje), $hoje);
        $passou = $valor > $divida + 0.004;
        $aviso = t('Adiantamento de {valor} lançado no Extrato.', ['valor' => money($valor)]) . ' '
            . ($passou ? t('Ele passa da dívida estimada ({divida}): confira o saldo com a instituição.', ['divida' => money($divida)])
                       : ($sit['quitado'] ? t('Empréstimo quitado.') : t('Falta cerca de {valor}.', ['valor' => money((float) $sit['falta'])])));
        flash($passou ? 'warn' : 'success', $aviso, ['url' => '/lancamentos/' . $id . '/excluir', 'label' => t('desfazer'), 'campos' => ['volta' => '/emprestimo']]);
        redirecionar('/emprestimo', 303);
    }

    /**
     * Confere o valor e a data de um pagamento. A data não pode ser futura: é o registro de algo já
     * pago, e o motor só conta adiantamento lançado em mês aberto ou fechado.
     * @return array{0:?string, 1:float} [erro, valor]
     */
    public static function validarAdiantamento(string $campoValor, string $data, string $hoje): array
    {
        $valor = self::valorDe($campoValor);
        if ($valor === null) {
            return [self::erroValor(), 0.0];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $data) && $data > $hoje) {
            return [t('A data não pode ser depois de hoje: lance o adiantamento depois de pagar.'), 0.0];
        }
        $erroData = LancamentosController::validarData($data);
        if ($erroData !== null) {
            return [t('Data inválida ({erro}): escolha o dia em que pagou.', ['erro' => $erroData]), 0.0];
        }
        return [null, $valor];
    }

    private static function erroValor(): string
    {
        return t('Valor inválido: use um número maior que zero, como 500 ou 1.250,00.');
    }

    /** O valor digitado, ou null. parseMoedaBr descarta letras ("12a" viraria 12): aqui o campo é só número. */
    private static function valorDe(string $campo): ?float
    {
        $v = preg_match('/^(R\$)?[\s\d.,]+$/u', $campo) ? parseMoedaBr($campo) : null;
        return $v === null || $v <= 0 || $v > valorMaximo() ? null : round($v, 2);
    }
}
