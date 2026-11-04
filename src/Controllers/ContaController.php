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

    /**
     * POST /contas: cria (sem id) ou salva a conta `id`. Duas pessoas em Ajustes, como em Reserva e meta: o formulário
     * de uma conta manda também o que mostrou ao abrir (orig[...]), e só o campo que a pessoa mudou é gravado
     * (AjustesController::mudancas). Se outra pessoa mudou o mesmo campo depois, nada é gravado, e a conta abre com o
     * valor de agora e "Outra pessoa mudou isto agora. Confira e salve de novo."
     */
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
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            if ($atual !== null && $saldo !== null) {
                // o que a página mostrava contra o que está gravado agora: grava só o que a pessoa mudou
                ContaModel::limparCache();
                $atual = ContaModel::achar($id) ?? $atual;
                $orig = self::originais($_POST['orig'] ?? null);
                ['gravar' => $gravar, 'conflito' => $conflito] = AjustesController::mudancas(
                    ['nome' => $nome, 'tipo' => $tipo, 'saldo_inicial' => (float) $saldo], $orig,
                    ['nome' => nomeConta($atual), 'tipo' => (string) $atual['tipo'], 'saldo_inicial' => (float) $atual['saldo_inicial']]
                );
                if ($conflito !== []) {
                    $pdo->rollBack();
                    $_SESSION['aj_erro'] = ['grupo' => 'carteira', 'msg' => t('Outra pessoa mudou isto agora. Confira e salve de novo.'), 'conta' => $id];
                    redirecionar(self::VOLTA, 303);
                }
                $nome = (string) ($gravar['nome'] ?? nomeConta($atual));
                $tipo = (string) ($gravar['tipo'] ?? $atual['tipo']);
                $saldo = (float) ($gravar['saldo_inicial'] ?? $atual['saldo_inicial']);
            }
            self::gravar($atual, $id, $nome, $tipo, $saldo, $bruto);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        MotorFinanceiro::limparCache();
        flash('success', $atual === null ? t('Conta criada · {nome}', ['nome' => $nome]) : t('Conta salva · {nome}', ['nome' => nomeConta(['nome' => $nome])]));
        redirecionar(self::VOLTA, 303);
    }

    /** orig[...] do formulário de uma conta: nome, tipo e saldo inicial; o que não veio ou não se lê fica de fora. */
    private static function originais(mixed $orig): array
    {
        $orig = is_array($orig) ? array_filter($orig, 'is_string') : [];
        $out = array_intersect_key($orig, ['nome' => 1, 'tipo' => 1]);
        if (isset($out['nome'])) {
            $out['nome'] = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $out['nome']));
        }
        $s = isset($orig['saldo_inicial']) ? (trim($orig['saldo_inicial']) === '' ? 0.0 : parseMoedaBr(trim($orig['saldo_inicial']))) : null;
        if ($s !== null) {
            $out['saldo_inicial'] = (float) $s;
        }
        return $out;
    }

    /** Confere e grava; com erro, volta à conta com o que foi digitado (a transação é desfeita por quem chama). */
    private static function gravar(?array $atual, int $id, string $nome, string $tipo, float|null $saldo, string $bruto): void
    {
        $erro = match (true) {
            $nome === '' || mb_strlen($nome) > 60 => t('Dê um nome à conta, com até 60 letras.'),
            ContaModel::nomeUsado($nome, $atual['id'] ?? null) => t('Já existe uma conta com esse nome.'),
            !isset(ContaModel::TIPOS[$tipo]) => t('Escolha o tipo da conta.'),
            $saldo === null || abs($saldo) > valorMaximo() * 10 => t('Digite o saldo como 1.200,50 (pode ser negativo).'),
            default => null,
        };
        if ($erro !== null) {
            Database::connection()->rollBack();
            $_SESSION['aj_erro'] = ['grupo' => 'carteira', 'msg' => $erro, 'conta' => $id, 'valores' => ['nome' => $nome, 'tipo' => $tipo, 'saldo_inicial' => $bruto]];
            redirecionar(self::VOLTA, 303);
        }
        // no nome da Conta principal, o que a pessoa vê é a tradução: mantém o nome do banco se ela não mudou
        if ($atual !== null && $atual['nome'] === ContaModel::NOME_PRINCIPAL && $nome === nomeConta($atual)) {
            $nome = ContaModel::NOME_PRINCIPAL;
        }
        ContaModel::salvar($atual['id'] ?? null, $nome, $tipo, round((float) $saldo, 2));
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
