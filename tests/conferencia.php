<?php
/**
 * A conferência do site: só o que vale para qualquer casa, em qualquer data, idioma, moeda e fuso. Roda sobre os
 * dados de quem usa e não supõe nada sobre eles: mês sem lançamento, sem cartão, sem empréstimo, sem wishlist, mês
 * atual já fechado, primeiro mês pela metade. Não grava nada. O que precisa de dados conhecidos fica em tests/regras.php.
 * tests/run.php chama conferencia() no banco configurado; tests/regras.php, no banco de demonstração, na borda do mês.
 */
declare(strict_types=1);

use Financeiro\Controllers\AjudaController;
use Financeiro\Controllers\AjustesController;
use Financeiro\Controllers\CartaoController;
use Financeiro\Controllers\DashboardController;
use Financeiro\Controllers\EmprestimoController;
use Financeiro\Controllers\FuturoController;
use Financeiro\Controllers\InvestimentosController;
use Financeiro\Controllers\LancamentosController;
use Financeiro\Controllers\MesController;
use Financeiro\Controllers\MetaController;
use Financeiro\Controllers\RelatorioController;
use Financeiro\Controllers\TemaController;
use Financeiro\Controllers\WishlistController;
use Financeiro\Database;
use Financeiro\Icones;
use Financeiro\Idioma;
use Financeiro\Models\AjusteModel;
use Financeiro\Models\CartaoModel;
use Financeiro\Models\LancamentoModel;
use Financeiro\Models\MesModel;
use Financeiro\Models\UsuarioModel;
use Financeiro\Pdf\RelatorioPdf;
use Financeiro\Relogio;
use Financeiro\Services\GastosMensais;
use Financeiro\Services\LancarForm;
use Financeiro\Services\MotorFinanceiro;
use Financeiro\Services\RelatorioDados;
use Financeiro\Sql;

/** @param callable(string, bool, string=): void $check */
function conferencia(callable $check): void
{
    $pdo = Database::connection();
    // site instalado e ainda sem conta ou sem a primeira configuração: não há dado a conferir, e isso não pode barrar a atualização
    if (UsuarioModel::nenhum() || !AjusteModel::configurado()) {
        $check('site ainda sem conta ou sem configuração: nada a conferir', true);
        return;
    }

    // ---- sessão simulada (primeiro usuário); os textos conferidos são os do português, seja qual for o idioma da conta
    $uid = (int) $pdo->query('SELECT MIN(id) FROM usuarios')->fetchColumn();
    $_SESSION['usuario_id']    = $uid;
    $_SESSION['usuario_nome']  = 'teste';
    $_SESSION['sessao_versao'] = (int) UsuarioModel::sessaoVersao($uid);
    Idioma::usar('pt');
    MotorFinanceiro::limparCache();
    $hoje = Relogio::hoje();

    // abre uma tela como o navegador; devolve o HTML e o erro (exceção, aviso do PHP), se houver
    $abrir = static function (string $cls, string $metodo, string $caminho, array $get = []): array {
        $_SERVER['REQUEST_URI'] = $caminho . ($get === [] ? '' : '?' . http_build_query($get));
        $_GET = $get;
        ob_start();
        try {
            (new $cls())->$metodo();
            $html = (string) ob_get_clean();
            return [$html, preg_match('/\b(Warning|Fatal error|Deprecated|Notice):/', $html) === 1 ? 'aviso PHP no HTML' : null];
        } catch (\Throwable $e) {
            ob_end_clean();
            return ['', get_class($e) . ': ' . $e->getMessage() . ' em ' . basename($e->getFile()) . ':' . $e->getLine()];
        } finally {
            $_GET = [];
        }
    };

    // ---- 1. motor: todo mês fechado fecha com o extrato
    $plano    = MotorFinanceiro::calcularPlano();
    $saldoIni = AjusteModel::valor('saldo_ini');
    $check('plano tem pelo menos 12 meses', count($plano) >= 12, (string) count($plano));
    foreach ($plano as $mes => $l) {
        if ($l['situacao'] !== 'REAL') {
            continue;
        }
        $fim  = date('Y-m-t', strtotime($mes));
        $calc = LancamentoModel::saldoCalculado($saldoIni, $fim);
        $check("fica_na_conta {$mes} = saldo calculado em {$fim}", abs($l['fica_na_conta'] - $calc) < 0.01, sprintf('%.2f vs %.2f', $l['fica_na_conta'], $calc));

        $st = $pdo->prepare('SELECT COALESCE(SUM(l.valor * c.sinal), 0) FROM lancamentos l JOIN categorias c ON c.id = l.categoria_id WHERE l.data >= :a AND l.data <= :b');
        $st->execute(['a' => $mes, 'b' => $fim]);
        $fluxo = (float) $st->fetchColumn();
        [, , $bal] = MotorFinanceiro::balancoTotal($l);
        $check("balanço total {$mes} = Σ sinal·valor", abs($bal - $fluxo) < 0.01, sprintf('%.2f vs %.2f', $bal, $fluxo));
    }
    foreach ($plano as $mes => $l) {
        $check("dívida nunca negativa em {$mes}", $l['falta_pagar'] >= -0.005, (string) $l['falta_pagar']);
    }

    // ---- 2. Início: as entradas e as saídas do mês mostrado batem com o banco (o mesmo mês que DashboardController escolhe)
    $mesCal = Relogio::mes();
    $mesRef = ($plano[$mesCal]['situacao'] ?? '') === 'REAL' ? $mesCal : MesModel::referencia($hoje);
    $fimRef = date('Y-m-t', strtotime($mesRef));
    $somaEnt = 0.0;
    foreach (LancamentoModel::entradasDoMes($mesRef) as $c) {
        $somaEnt += $c['real'];
    }
    $st = $pdo->prepare('SELECT COALESCE(SUM(l.valor), 0) FROM lancamentos l JOIN categorias c ON c.id = l.categoria_id WHERE c.sinal = 1 AND l.data >= :a AND l.data <= :b');
    $st->execute(['a' => $mesRef, 'b' => $fimRef]);
    $check("entradasDoMes({$mesRef}) = Σ entradas", abs($somaEnt - (float) $st->fetchColumn()) < 0.01);
    $somaSai = 0.0;
    foreach ((GastosMensais::matriz()[$mesRef]['cats'] ?? []) as $c) {
        $somaSai += (float) $c['real'];
    }
    $st = $pdo->prepare('SELECT COALESCE(SUM(l.valor), 0) FROM lancamentos l JOIN categorias c ON c.id = l.categoria_id WHERE c.sinal = -1 AND c.operacional = 1 AND l.data >= :a AND l.data <= :b');
    $st->execute(['a' => $mesRef, 'b' => $fimRef]);
    $check("gastos do Início em {$mesRef} = Σ saídas", abs($somaSai - (float) $st->fetchColumn()) < 0.01);

    // ---- 3. as telas abrem sem erro nem aviso do PHP, com o bloco principal, em português, inglês e espanhol
    $telas = [
        ['/',              DashboardController::class,     'index',      [],                  'saldo-v'],
        ['/lancamentos',   LancamentosController::class,   'index',      [],                  'ex-main'],
        ['/lancar',        LancamentosController::class,   'lancarForm', [],                  'data-lf'],
        ['/meta',          MetaController::class,          'index',      [],                  'meta-form'],
        ['/mes',           MesController::class,           'index',      [],                  'mes-resumo'],
        ['/mes/fechar',    MesController::class,           'fecharForm', [],                  'fe-topo'],
        ['/futuro',        FuturoController::class,        'index',      [],                  'fu-meses'],
        ['/ajustes',       AjustesController::class,       'index',      [],                  'aj-titulo'],
        ['/investimentos', InvestimentosController::class, 'index',      [],                  'inv-total'],
        ['/cartao',        CartaoController::class,        'index',      [],                  'ct-topo'],
        ['/emprestimo',    EmprestimoController::class,    'index',      [],                  'emp-topo'],
        ['/wishlist',      WishlistController::class,      'index',      [],                  'wl-topo'],
        ['/relatorio',     RelatorioController::class,     'index',      [],                  'rl-topo'],
        ['/relatorio',     RelatorioController::class,     'index',      ['tipo' => 'anual'], 'rl-topo'],
        ['/temas',         TemaController::class,          'index',      [],                  'tms-topo'],
        ['/ajuda',         AjudaController::class,         'index',      [],                  'aj-titulo'],
    ];
    foreach ($telas as [$caminho, $cls, $metodo, $get, $marcador]) {
        $ruim = [];
        foreach (['pt', 'en', 'es'] as $id) {
            Idioma::usar($id);
            LancarForm::limparCache();
            // a Wishlist renova a cotação depois de 2 horas: com a marca de agora, a tela abre sem ir à rede
            $comCotacao = $cls === WishlistController::class;
            if ($comCotacao) {
                $pdo->beginTransaction();
                $pdo->exec("REPLACE INTO cotacoes (moeda, valor, data, buscado_em) VALUES ('BRL', 1, " . Sql::hoje() . ', ' . Sql::agora() . ')');
            }
            try {
                [$html, $erro] = $abrir($cls, $metodo, $caminho, $get);
            } finally {
                if ($comCotacao) {
                    $pdo->rollBack();
                }
            }
            if ($erro !== null || !str_contains($html, $marcador) || strlen($html) < 2000) {
                $ruim[] = $id . ': ' . ($erro ?? (str_contains($html, $marcador) ? 'HTML curto' : "sem '{$marcador}'"));
            }
        }
        $check('GET ' . $caminho . ($get === [] ? '' : '?' . http_build_query($get)) . ' renderiza', $ruim === [], implode(' | ', $ruim));
    }
    Idioma::usar('pt');
    LancarForm::limparCache();
    // o Mês e o Extrato de todo mês do plano, do primeiro ao último
    foreach ([[MesController::class, '/mes', 'Mês'], [LancamentosController::class, '/lancamentos', 'Extrato']] as [$cls, $caminho, $nome]) {
        $ruim = [];
        foreach (array_keys($plano) as $m) {
            [$html, $erro] = $abrir($cls, 'index', $caminho, ['mes' => $cls === MesController::class ? (string) $m : substr((string) $m, 0, 7)]);
            if ($erro !== null || strlen($html) < 2000) {
                $ruim[] = $m . ': ' . ($erro ?? 'HTML curto');
            }
        }
        $check("{$nome}: todo mês do plano abre", $ruim === [], implode(' | ', array_slice($ruim, 0, 3)));
    }
    $check('cartão: sem cartão cadastrado, a aba pede o cadastro', CartaoModel::ativo() !== null
        || str_contains($abrir(CartaoController::class, 'index', '/cartao')[0], 'Cadastre o cartão'));

    // ---- 4. a previsão do fim do mês é a mesma no Início, no Mês e no Simular
    [$htmlIni] = $abrir(DashboardController::class, 'index', '/');
    [$htmlMes] = $abrir(MesController::class, 'index', '/mes');
    [$htmlAno] = $abrir(FuturoController::class, 'index', '/futuro');
    $mesPrev = MesModel::referencia($hoje);
    $linhaPrev = MotorFinanceiro::linhaDoMes($mesPrev);
    if ($linhaPrev !== null && $linhaPrev['situacao'] !== 'REAL') {
        $valPrev = num((float) $linhaPrev['fica_na_conta'], 0);
        $tagPrev = 'data-previsao-fim>Previsão para o fim de ' . mesExtenso($mesPrev) . ': <b>' . $valPrev . '</b>';
        $check('Início: previsão do fim do mês vem do mesmo plano do Anual', str_contains($htmlIni, $tagPrev) && str_contains($htmlAno, $valPrev));
        // com o mês atual já fechado, o Início olha para o próximo, e o Mês continua no atual
        if ($mesPrev === $mesCal) {
            $check('Mês: previsão do fim do mês igual à do Início', str_contains($htmlMes, $tagPrev));
        }
    }

    // ---- 5. idiomas: tudo traduzido, com os mesmos marcadores, e o Início sem sobra em português
    $saidaGerador = (static function (): string {
        $argv = ['gerar.php', '--json'];
        ob_start();
        require __DIR__ . '/../tools/idiomas/gerar.php';
        return (string) ob_get_clean();
    })();
    $falta = json_decode($saidaGerador, true);
    $check('idiomas: todo texto marcado tem tradução', is_array($falta) && $falta['en'] === [] && $falta['es'] === [], substr($saidaGerador, 0, 300));
    foreach (['en', 'es'] as $id) {
        $dic = require __DIR__ . '/../src/idiomas/' . $id . '.php';
        $marcas = static function (string $s): array { preg_match_all('/\{\w+\}/', $s, $m); sort($m[0]); return $m[0]; };
        $ruins = array_filter(array_keys($dic), static fn($k) => $marcas((string) $k) !== $marcas((string) $dic[$k]));
        $check("idiomas: marcadores iguais em {$id}", $ruins === [], implode(' | ', array_slice($ruins, 0, 3)));
        Idioma::usar($id);
        $check("idiomas: Início em {$id} sem o texto em português", !str_contains($abrir(DashboardController::class, 'index', '/')[0], 'Em conta hoje'));
        $check("idiomas: meses em {$id}", mesExtenso('2026-12-01') !== 'dezembro');
    }
    Idioma::usar('pt');

    // ---- 6. relatório: a escada de todo mês fecha, os gastos batem, nada de veredito no mês em andamento, PDF em todo idioma
    $ponteOk = true;
    $comVeredito = [];
    foreach ($plano as $m => $l) {
        if ($m > $mesCal) {
            break;
        }
        $rel = RelatorioDados::mensal((string) $m);
        $soma = 0.0;
        foreach ($rel['ponte'] as $d) {
            if ($d['tipo'] !== 'fim') {
                $soma += $d['valor'];
            }
        }
        $ponteOk = $ponteOk && abs($soma - (float) $l['fica_na_conta']) < 0.01;
        if (!$rel['encerrado'] && array_merge($rel['achados']['bom'], $rel['achados']['ruim'], $rel['achados']['melhorar']) !== []) {
            $comVeredito[] = (string) $m;
        }
        if ($rel['encerrado']) {
            $check("relatório: gastos de {$m} batem com o que saiu", abs($rel['categorias']['totalReal'] - (float) $l['sai']) < 0.01, json_encode([$rel['categorias']['totalReal'], $l['sai']]));
        }
    }
    $check('relatório: a escada de todo mês fecha no centavo', $ponteOk);
    $check('relatório: mês em andamento não dá veredito', $comVeredito === [], implode(', ', $comVeredito));
    foreach (['pt', 'en', 'es'] as $id) {
        Idioma::usar($id);
        $periodos = RelatorioDados::periodos();
        $mensal = $periodos['mesPadrao'] !== null ? RelatorioDados::mensal((string) $periodos['mesPadrao']) : null;
        $anual = $periodos['anoPadrao'] !== null ? RelatorioDados::anual((int) $periodos['anoPadrao']) : null;
        $pdfM = $mensal !== null ? (new RelatorioPdf($mensal))->gerar() : '';
        $pdfA = $anual !== null ? (new RelatorioPdf($anual))->gerar() : '';
        $check("relatório: PDF mensal e anual em {$id}", str_starts_with($pdfM, '%PDF') && str_starts_with($pdfA, '%PDF') && strlen($pdfM) > 10000);
    }
    Idioma::usar('pt');

    // ---- 7. categorias: toda uma com ícone, e o plano igual em qualquer idioma (o motor compara os nomes do banco)
    $semIcone = [];
    foreach ($pdo->query('SELECT id, nome, grupo FROM categorias')->fetchAll() as $c) {
        if (!Icones::existe(Icones::daCategoria((int) $c['id'], (string) $c['nome'], (string) $c['grupo']))) {
            $semIcone[] = (string) $c['nome'];
        }
    }
    $check('ícones: toda categoria do banco tem ícone', $semIcone === [], implode(', ', $semIcone));
    $planos = [];
    foreach (['en', 'es', 'pt'] as $id) {
        Idioma::usar($id);
        MotorFinanceiro::limparCache();
        $planos[$id] = MotorFinanceiro::calcularPlano();
    }
    $check('o plano é o mesmo em qualquer idioma', $planos['en'] === $planos['pt'] && $planos['es'] === $planos['pt']);
    Idioma::usar('pt');
    MotorFinanceiro::limparCache();
}
