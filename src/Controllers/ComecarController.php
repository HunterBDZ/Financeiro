<?php
namespace Financeiro\Controllers;

use Financeiro\Auth;
use Financeiro\Database;
use Financeiro\Models\AjusteModel;
use Financeiro\Models\CategoriaModel;
use Financeiro\Models\ContaRecorrenteModel;
use Financeiro\Models\MesModel;
use Financeiro\Models\PrevisaoModel;
use Financeiro\Services\Horizonte;
use Financeiro\View;

/**
 * A primeira configuração, no site: início e saldos, renda, contas fixas e o que a pessoa usa. Só o 1º
 * passo é obrigatório. Num início no meio do mês, o que venceu antes dele não entra na previsão daquele mês.
 */
final class ComecarController
{
    private const PASSOS = 4;

    public function index(): void
    {
        Auth::requireLogin();
        if (AjusteModel::configurado()) {
            redirecionar('/', 302);
        }
        $passo = max(1, min(self::PASSOS, (int) ($_GET['passo'] ?? 1)));
        if ($passo > 1 && MesModel::primeiro() === null) {
            redirecionar('/comecar', 302);
        }
        View::renderAuth('comecar/index', [
            'passo' => $passo, 'total' => self::PASSOS, 'hoje' => date('Y-m-d'),
            'inicio' => AjusteModel::dataInicio() ?? date('Y-m-d'),
            'contas' => $passo === 3 ? self::contasFixas() : [],
            'erro' => self::erro(),
        ]);
    }

    public function salvar(): void
    {
        Auth::requireLogin();
        csrfCheck();
        if (AjusteModel::configurado()) {
            redirecionar('/', 303);
        }
        $passo = (int) ($_POST['passo'] ?? 1);
        match ($passo) {
            2 => self::salvarRenda(),
            3 => self::salvarContas(),
            4 => self::concluir(),
            default => self::salvarInicio(),
        };
        redirecionar('/comecar?passo=' . ($passo + 1), 303);
    }

    private static function salvarInicio(): void
    {
        $data = is_string($_POST['data_inicio'] ?? null) ? $_POST['data_inicio'] : '';
        $ok = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $data, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
        $problema = $ok ? Horizonte::problemaNaData($data) : t('data?');
        $valores = [];
        foreach (['saldo_ini', 'poupanca_ini', 'investimentos_ini'] as $chave) {
            $bruto = trim((string) ($_POST[$chave] ?? ''));
            $v = $bruto === '' ? 0.0 : parseMoedaBr($bruto);
            if ($v === null || ($chave !== 'saldo_ini' && $v < 0)) {
                $problema = t('Confira os valores: use números como 1.200,50.');
            }
            $valores[$chave] = (float) $v;
        }
        if ($problema !== null) {
            self::voltarComErro(1, $problema);
        }
        foreach ($valores as $chave => $v) {
            AjusteModel::setValor($chave, round($v, 4));
        }
        Horizonte::mudarInicio($data);
    }

    private static function salvarRenda(): void
    {
        $bruto = trim((string) ($_POST['renda'] ?? ''));
        if ($bruto === '') {
            return;
        }
        $v = parseMoedaBr($bruto);
        $dia = self::dia($_POST['dia'] ?? '');
        if ($v === null || $v < 0 || $dia === false) {
            self::voltarComErro(2, t('Confira os valores: use números como 1.200,50.'));
        }
        $cat = CategoriaModel::porNome('Salário');
        if ($cat !== null) {
            self::preverEmTodos((int) $cat['id'], $v, $dia);
        }
    }

    private static function salvarContas(): void
    {
        $valores = (array) ($_POST['valor'] ?? []);
        $dias = (array) ($_POST['dia'] ?? []);
        $validas = array_column(self::contasFixas(), null, 'id');
        foreach ($valores as $id => $bruto) {
            $id = (int) $id;
            $bruto = trim(is_string($bruto) ? $bruto : '');
            if ($bruto === '' || !isset($validas[$id])) {
                continue;
            }
            $v = parseMoedaBr($bruto);
            $dia = self::dia($dias[$id] ?? '');
            if ($v === null || $v < 0 || $dia === false) {
                self::voltarComErro(3, t('Confira os valores: use números como 1.200,50.'));
            }
            self::preverEmTodos($id, $v, $dia);
            if ($dia !== null) {
                ContaRecorrenteModel::upsert($id, $dia);
            }
        }
    }

    private static function concluir(): void
    {
        $emprestimo = ($_POST['usa_emprestimo'] ?? '') === '1';
        AjusteModel::setValor('usa_emprestimo', $emprestimo ? 1.0 : 0.0);
        AjusteModel::setValor('usa_cartao', ($_POST['usa_cartao'] ?? '') === '1' ? 1.0 : 0.0);
        AjusteModel::setValor('parcela_modo', ($_POST['parcela_modo'] ?? '') === '2' ? 2.0 : 1.0);
        AjusteModel::setValor('configurado', 1.0);
        flash('success', t('Tudo pronto. O resto você ajusta quando quiser, em Ajustes.'));
        redirecionar($emprestimo ? '/emprestimo?dados=1#emp-dados' : '/', 303);
    }

    /** Mesmo valor em todos os meses; no 1º, zero se a data dele já passou quando o plano começou. */
    private static function preverEmTodos(int $cat, float $valor, ?int $dia): void
    {
        $inicio = AjusteModel::dataInicio();
        $mesIni = $inicio !== null ? substr($inicio, 0, 8) . '01' : '';
        foreach (MesModel::todos() as $m) {
            $jaPassou = (string) $m['mes'] === $mesIni && $dia !== null && $inicio !== null && $dia < (int) substr($inicio, 8, 2);
            PrevisaoModel::setValor($cat, (string) $m['mes'], $jaPassou ? 0.0 : round($valor, 2));
        }
    }

    /** @return int|null|false o dia, null se vazio, false se inválido */
    private static function dia(mixed $bruto): int|null|false
    {
        $s = trim(is_string($bruto) ? $bruto : '');
        if ($s === '') {
            return null;
        }
        return ctype_digit($s) && (int) $s >= 1 && (int) $s <= 31 ? (int) $s : false;
    }

    /** @return list<array{id:int, nome:string}> */
    private static function contasFixas(): array
    {
        $out = [];
        $st = Database::connection()->query("SELECT id, nome, apelido FROM categorias WHERE grupo = 'Fixos' AND ativo = 1 ORDER BY ordem, nome");
        foreach ($st as $c) {
            $out[] = ['id' => (int) $c['id'], 'nome' => nomeCurto($c)];
        }
        return $out;
    }

    private static function voltarComErro(int $passo, string $msg): never
    {
        $_SESSION['comecar_erro'] = $msg;
        redirecionar('/comecar?passo=' . $passo, 303);
    }

    private static function erro(): ?string
    {
        $m = $_SESSION['comecar_erro'] ?? null;
        unset($_SESSION['comecar_erro']);
        return is_string($m) ? $m : null;
    }
}
