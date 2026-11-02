<?php
namespace Financeiro\Controllers;

use Financeiro\Auth;
use Financeiro\Database;
use Financeiro\Models\ContaModel;
use Financeiro\Services\MotorFinanceiro;

/**
 * Ajustes → Contas: onde o dinheiro está (banco, banco digital, dinheiro vivo). Criar, editar, mudar a ordem,
 * arquivar e trazer de volta. Toda conta ativa entra no "Em conta hoje"; o saldo inicial de todas soma o do plano.
 */
final class ContaController
{
    private const VOLTA = '/ajustes?aberto=carteira#carteira';

    /** POST /contas: cria (sem id) ou salva a conta `id`. */
    public function salvar(): void
    {
        Auth::requireLogin();
        csrfCheck();
        $id = (int) ($_POST['id'] ?? 0);
        $atual = $id > 0 ? ContaModel::achar($id) : null;
        if ($id > 0 && $atual === null) {
            redirecionar(self::VOLTA, 303);
        }
        $nome = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) ($_POST['nome'] ?? '')));
        $tipo = (string) ($_POST['tipo'] ?? '');
        $bruto = trim((string) ($_POST['saldo_inicial'] ?? ''));
        $saldo = $bruto === '' ? 0.0 : parseMoedaBr($bruto);
        $erro = match (true) {
            $nome === '' || mb_strlen($nome) > 60 => t('Dê um nome à conta, com até 60 letras.'),
            ContaModel::nomeUsado($nome, $atual['id'] ?? null) => t('Já existe uma conta com esse nome.'),
            !isset(ContaModel::TIPOS[$tipo]) => t('Escolha o tipo da conta.'),
            $saldo === null || abs($saldo) > valorMaximo() * 10 => t('Digite o saldo como 1.200,50 (pode ser negativo).'),
            default => null,
        };
        if ($erro !== null) {
            $_SESSION['aj_erro'] = ['grupo' => 'carteira', 'msg' => $erro, 'conta' => $id, 'valores' => ['nome' => $nome, 'tipo' => $tipo, 'saldo_inicial' => $bruto]];
            redirecionar(self::VOLTA, 303);
        }
        // no nome da Conta principal, o que a pessoa vê é a tradução: mantém o nome do banco se ela não mudou
        if ($atual !== null && $atual['nome'] === ContaModel::NOME_PRINCIPAL && $nome === nomeConta($atual)) {
            $nome = ContaModel::NOME_PRINCIPAL;
        }
        ContaModel::salvar($atual['id'] ?? null, $nome, $tipo, round((float) $saldo, 2));
        MotorFinanceiro::limparCache();
        flash('success', $atual === null ? t('Conta criada · {nome}', ['nome' => $nome]) : t('Conta salva · {nome}', ['nome' => nomeConta(['nome' => $nome])]));
        redirecionar(self::VOLTA, 303);
    }

    /**
     * POST /contas/{id}/arquivar: só zerada (contando os lançamentos agendados) e com outra ativa. Os cartões que
     * eram pagos por ela passam para a primeira das outras.
     */
    public function arquivar(string $id): void
    {
        Auth::requireLogin();
        csrfCheck();
        $conta = ContaModel::achar((int) $id);
        if ($conta === null || !$conta['ativa']) {
            redirecionar(self::VOLTA, 303);
        }
        $outras = array_values(array_filter(ContaModel::ativas(), static fn(array $c): bool => $c['id'] !== $conta['id']));
        $saldo = ContaModel::saldo($conta['id'], '9999-12-31');
        if ($outras === []) {
            flash('error', t('É a única conta ativa. Crie outra antes de arquivar esta.'));
            redirecionar(self::VOLTA, 303);
        }
        if (abs($saldo) >= 0.005) {
            flash('error', t('{nome} ainda tem {valor}. Transfira para outra conta antes de arquivar.', ['nome' => nomeConta($conta), 'valor' => money($saldo)]));
            redirecionar(self::VOLTA, 303);
        }
        Database::connection()->prepare('UPDATE cartoes SET conta_id = :nova WHERE conta_id = :velha')->execute(['nova' => $outras[0]['id'], 'velha' => $conta['id']]);
        ContaModel::setAtiva($conta['id'], false);
        MotorFinanceiro::limparCache();
        flash('success', t('Conta arquivada · {nome}', ['nome' => nomeConta($conta)]));
        redirecionar(self::VOLTA, 303);
    }

    /** POST /contas/{id}/reativar */
    public function reativar(string $id): void
    {
        Auth::requireLogin();
        csrfCheck();
        $conta = ContaModel::achar((int) $id);
        if ($conta !== null && !$conta['ativa']) {
            ContaModel::setAtiva($conta['id'], true);
            MotorFinanceiro::limparCache();
            flash('success', t('Conta de volta · {nome}', ['nome' => nomeConta($conta)]));
        }
        redirecionar(self::VOLTA, 303);
    }

    /** POST /contas/{id}/mover (direcao=-1 sobe, 1 desce): a primeira é a padrão no lançar. */
    public function mover(string $id): void
    {
        Auth::requireLogin();
        csrfCheck();
        ContaModel::mover((int) $id, (int) ($_POST['direcao'] ?? 0));
        redirecionar(self::VOLTA, 303);
    }
}
