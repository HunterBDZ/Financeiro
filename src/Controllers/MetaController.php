<?php
namespace Financeiro\Controllers;

use Financeiro\Auth;
use Financeiro\Database;
use Financeiro\Models\CategoriaModel;
use Financeiro\Models\MetaModel;
use Financeiro\Services\GastosMensais;
use Financeiro\Services\MotorFinanceiro;
use Financeiro\Services\PrevisaoEdicao;
use Financeiro\View;

/**
 * Meta (aba entre extrato e mês). Define uma meta mensal de gasto por categoria.
 * Só categorias de gasto (as de CategoriaModel::gastos()) podem ter meta; o mês usa a meta
 * para pintar a barra de verde (dentro) ou vermelho (fora). Campo vazio = sem meta.
 * A meta também é a previsão padrão de todo mês (view previsao_efetiva): mudar a meta muda a
 * projeção — o aviso depois de salvar mostra o efeito no fim do último mês.
 */
final class MetaController
{
    public function index(): void
    {
        Auth::requireLogin();

        $gastos = CategoriaModel::gastos();
        $metas  = MetaModel::todas();

        // Mês corrente (aberto) para a renda e o gasto já realizado de cada categoria.
        $matriz   = GastosMensais::matriz();
        $mesAtual = null;
        foreach ($matriz as $k => $linha) {
            if (($linha['estado'] ?? '') === 'aberto') {
                $mesAtual = $k;                              // o último aberto é o mês de hoje
            }
        }
        if ($mesAtual === null) {
            $hojeMes  = date('Y-m-01');
            $mesAtual = isset($matriz[$hojeMes]) ? $hojeMes : (array_key_last($matriz) ?: $hojeMes);
        }
        $linhaMes = $matriz[$mesAtual] ?? ['renda' => 0.0, 'cats' => []];
        $entradas = (float) ($linhaMes['renda'] ?? 0.0);
        $cats     = $linhaMes['cats'] ?? [];

        // renda do mês = entradas do mês + o que sobrou do mês anterior (saldo em conta que veio)
        $linhaPlano = MotorFinanceiro::linhaDoMes($mesAtual);
        $sobra = $linhaPlano !== null ? (float) ($linhaPlano['sobrou_mes_anterior'] ?? 0.0) : 0.0;
        $renda = $entradas + $sobra;

        // Só mostra categorias que o usuário escolheu: as que já têm meta, mais uma recém-
        // adicionada via ?add. As demais vão para o seletor "adicionar categoria".
        $add = (int) ($_GET['add'] ?? 0);
        $mostrar = $metas;                         // ids com meta
        $temGasto = [];
        foreach ($gastos as $c) {
            $temGasto[(int) $c['id']] = true;
        }
        if ($add > 0 && isset($temGasto[$add])) {
            $mostrar[$add] = $mostrar[$add] ?? 0.0;
        }

        $ess = $naoEss = $addEss = $addNao = [];
        $totalMeta = $metaEss = $metaNaoEss = $gastoEss = $gastoNaoEss = 0.0;
        $gastoTotal = $gastoComMeta = 0.0;
        foreach ($gastos as $c) {
            $id = (int) $c['id'];
            $c['meta']  = $metas[$id] ?? null;
            $c['gasto'] = (float) ($cats[$id]['real'] ?? 0.0);
            $gastoTotal += $c['gasto'];
            if ($c['meta'] !== null) {
                $totalMeta    += $c['meta'];
                $gastoComMeta += $c['gasto'];
            }
            $ehEss = (int) $c['essencial'] === 1;
            if (isset($mostrar[$id])) {
                if ($ehEss) {
                    $ess[] = $c;
                    $metaEss  += $c['meta'] ?? 0.0;
                    $gastoEss += $c['gasto'];
                } else {
                    $naoEss[] = $c;
                    $metaNaoEss  += $c['meta'] ?? 0.0;
                    $gastoNaoEss += $c['gasto'];
                }
            } else {
                $ehEss ? ($addEss[] = $c) : ($addNao[] = $c);
            }
        }

        View::render('meta/index', [
            'pageTitle'     => t('meta · financeiro'),
            'essenciais'    => $ess,
            'naoEssenciais' => $naoEss,
            'addEss'        => $addEss,
            'addNao'        => $addNao,
            'comMeta'       => count($metas),
            'mesAtual'      => $mesAtual,
            'renda'         => round($renda, 2),
            'entradas'      => round($entradas, 2),
            'sobra'         => round($sobra, 2),
            'totalMeta'     => round($totalMeta, 2),
            'gastoTotal'    => round($gastoTotal, 2),
            'gastoComMeta'  => round($gastoComMeta, 2),
            'gastoSemMeta'  => round($gastoTotal - $gastoComMeta, 2),
            'metaEss'       => round($metaEss, 2),
            'metaNaoEss'    => round($metaNaoEss, 2),
            'gastoEss'      => round($gastoEss, 2),
            'gastoNaoEss'   => round($gastoNaoEss, 2),
        ], 'meta');
    }

    public function salvar(): void
    {
        Auth::requireLogin();
        csrfCheck();

        $validas = [];
        foreach (CategoriaModel::gastos() as $c) {
            $validas[(int) $c['id']] = true;                 // só gasto recebe meta
        }

        $erroCampo = null;
        $parsed = [];
        foreach ((array) ($_POST['meta'] ?? []) as $id => $bruto) {
            $id = (int) $id;
            if (!isset($validas[$id])) {
                continue;
            }
            $bruto = is_string($bruto) ? trim($bruto) : '';
            if ($bruto === '') {
                $parsed[$id] = null;                         // vazio = remove a meta
                continue;
            }
            $v = parseMoedaBr($bruto);
            if ($v === null || $v < 0 || $v > 1000000) {
                $erroCampo = $id;
                break;
            }
            $parsed[$id] = round($v, 2) <= 0 ? null : round($v, 2);
        }

        if ($erroCampo !== null) {
            flash('error', t('meta?'));
            redirecionar('/meta#c' . $erroCampo, 303);
        }

        $antes = MotorFinanceiro::calcularPlano();
        $pdo = Database::connection();
        $n = 0;
        try {
            $pdo->beginTransaction();                        // tudo ou nada: a meta mexe na projeção
            foreach ($parsed as $id => $v) {
                if ($v === null) {
                    MetaModel::remover($id);
                } else {
                    MetaModel::definir($id, $v);
                    $n++;
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('meta/salvar: ' . $e->getMessage());
            flash('error', t('não salvou · tente de novo'));
            redirecionar('/meta', 303);
        }
        MotorFinanceiro::limparCache();
        $depois = MotorFinanceiro::calcularPlano();

        $segs = [$n === 0 ? t('metas limpas') : t('metas salvas'), $n === 0 ? null : tn($n, '1 categoria', '{n} categorias')];
        $ult  = array_key_last($depois);
        if ($ult !== null && isset($antes[$ult])
            && abs((float) $antes[$ult]['fica_na_conta'] - (float) $depois[$ult]['fica_na_conta']) >= 0.5) {
            $segs[] = t('fim de {mes} {antes} → {depois}', ['mes' => monthLabel((string) $ult), 'antes' => num((float) $antes[$ult]['fica_na_conta'], 0), 'depois' => num((float) $depois[$ult]['fica_na_conta'], 0)]);
        }
        $reserva = (float) (MotorFinanceiro::dados()['aj']['reserva_minima'] ?? 0.0);
        $alerta  = PrevisaoEdicao::piorou($antes, $depois, date('Y-m-01'), $reserva);
        if ($alerta !== null) {
            $segs[] = $alerta['tipo'] === 'vermelho' ? t('atenção: {mes} no vermelho', ['mes' => monthLabel($alerta['mes'])]) : t('atenção: {mes} abaixo da reserva', ['mes' => monthLabel($alerta['mes'])]);
        }
        flash($alerta !== null ? 'warn' : 'success', implode(' · ', array_filter($segs, static fn($s) => $s !== null)));
        redirecionar('/meta', 303);
    }
}
