<?php
/**
 * Testes de fumaça e invariantes do motor, contra o banco real. O que grava (meta, previsão)
 * roda dentro de uma transação desfeita no fim do teste: o banco não muda.
 * Uso: cd /var/www/financeiro && php tests/run.php   (deploy.sh roda isto depois de publicar)
 * Sai com 1 se algo falhar.
 */
declare(strict_types=1);

error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    throw new \ErrorException($str, 0, $no, $file, $line);
});

$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['HTTPS']          = 'on';

require __DIR__ . '/../src/bootstrap.php';

use Financeiro\Auth;
use Financeiro\Controllers\AjustesController;
use Financeiro\Controllers\CartaoController;
use Financeiro\Controllers\CategoriaController;
use Financeiro\Controllers\DashboardController;
use Financeiro\Controllers\FuturoController;
use Financeiro\Controllers\LancamentosController;
use Financeiro\Controllers\MesController;
use Financeiro\Controllers\MetaController;
use Financeiro\Database;
use Financeiro\Icones;
use Financeiro\Models\AjusteModel;
use Financeiro\Models\CartaoModel;
use Financeiro\Models\CategoriaModel;
use Financeiro\Models\LancamentoModel;
use Financeiro\Models\MesModel;
use Financeiro\Models\MetaModel;
use Financeiro\Models\PrevisaoModel;
use Financeiro\Models\UsuarioModel;
use Financeiro\Services\AnualResumo;
use Financeiro\Services\CartaoFatura;
use Financeiro\Services\LancarForm;
use Financeiro\Services\MotorFinanceiro;
use Financeiro\Services\PrevisaoEdicao;
use Financeiro\Services\PrevisaoLote;

$ok = 0;
$falhas = 0;
$check = static function (string $nome, bool $cond, string $detalhe = '') use (&$ok, &$falhas): void {
    if ($cond) {
        $ok++;
        echo "ok    {$nome}\n";
    } else {
        $falhas++;
        echo "FALHA {$nome}" . ($detalhe !== '' ? " — {$detalhe}" : '') . "\n";
    }
};

$pdo = Database::connection();

// ---- sessão simulada (primeiro usuário)
$uid = (int) $pdo->query('SELECT MIN(id) FROM usuarios')->fetchColumn();
$_SESSION['usuario_id']    = $uid;
$_SESSION['usuario_nome']  = 'teste';
$_SESSION['sessao_versao'] = (int) UsuarioModel::sessaoVersao($uid);

// ---- 1. motor: todo mês REAL fecha com o extrato
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

// ---- 2. Início: entradas e saídas do mês de referência batem com o banco
$mesRef = MesModel::referencia();
$fim    = date('Y-m-t', strtotime($mesRef));
$somaEnt = 0.0;
foreach (LancamentoModel::entradasDoMes($mesRef) as $c) {
    $somaEnt += $c['real'];
}
$st = $pdo->prepare('SELECT COALESCE(SUM(l.valor), 0) FROM lancamentos l JOIN categorias c ON c.id = l.categoria_id WHERE c.sinal = 1 AND l.data >= :a AND l.data <= :b');
$st->execute(['a' => $mesRef, 'b' => $fim]);
$check("entradasDoMes({$mesRef}) = Σ entradas", abs($somaEnt - (float) $st->fetchColumn()) < 0.01);

// ---- 3. telas renderizam sem erro e com o bloco principal
$telas = [
    ['/',           DashboardController::class,   'index',      'saldo-v'],
    ['/lancamentos', LancamentosController::class, 'index',      'ex-main'],
    ['/lancar',     LancamentosController::class, 'lancarForm', 'data-lf'],
    ['/meta',       MetaController::class,         'index',      'meta-form'],
    ['/mes',        MesController::class,         'index',      'mes-resumo'],
    ['/mes/fechar', MesController::class,         'fecharForm', 'fe-topo'],
    ['/futuro',     FuturoController::class,      'index',      'fu-meses'],
    ['/ajustes',    AjustesController::class,     'index',      'aj-titulo'],
];
foreach ($telas as [$path, $cls, $metodo, $marcador]) {
    $_SERVER['REQUEST_URI'] = $path;
    $_GET = [];
    ob_start();
    try {
        (new $cls())->$metodo();
        $html = (string) ob_get_clean();
        $ruim = preg_match('/\b(Warning|Fatal error|Deprecated|Notice):/', $html) === 1;
        $check("GET {$path} renderiza", str_contains($html, $marcador) && !$ruim && strlen($html) > 2000,
            $ruim ? 'aviso PHP no HTML' : (str_contains($html, $marcador) ? 'HTML curto' : "sem '{$marcador}'"));
    } catch (\Throwable $e) {
        ob_end_clean();
        $check("GET {$path} renderiza", false, get_class($e) . ': ' . $e->getMessage() . ' em ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

// ---- 4. meta: definir uma meta pinta a barra no mês (verde/vermelho), sem sujar o banco
$mesMeta = null;
$catMeta = null;
$gastoMeta = 0.0;
foreach (MesModel::todos() as $m) {
    if ($m['situacao'] !== 'PREVISAO') {
        continue;
    }
    $fimM = date('Y-m-t', strtotime((string) $m['mes']));
    $st = $pdo->prepare(
        "SELECT l.categoria_id, SUM(l.valor) tot FROM lancamentos l JOIN categorias c ON c.id = l.categoria_id
         WHERE c.grupo = 'Dia a dia' AND c.sinal = -1 AND l.data >= :a AND l.data <= :b
         GROUP BY l.categoria_id ORDER BY tot DESC LIMIT 1"
    );
    $st->execute(['a' => (string) $m['mes'], 'b' => $fimM]);
    $row = $st->fetch();
    if ($row) {
        $mesMeta = (string) $m['mes'];
        $catMeta = (int) $row['categoria_id'];
        $gastoMeta = (float) $row['tot'];
        break;
    }
}
if ($mesMeta === null) {
    $check('meta pinta a barra no mês', true, 'sem gasto de dia a dia para testar');
} else {
    $pdo->beginTransaction();
    MetaModel::definir($catMeta, round($gastoMeta / 2, 2));        // meta abaixo do gasto → fora da meta
    MotorFinanceiro::limparCache();
    $_SERVER['REQUEST_URI'] = '/mes?mes=' . $mesMeta;
    $_GET = ['mes' => $mesMeta];
    ob_start();
    try {
        (new MesController())->index();
        $html = (string) ob_get_clean();
        $check('meta pinta a barra no mês', str_contains($html, 'meta-barra') && str_contains($html, 'fora-meta'),
            'sem meta-barra/fora-meta no HTML');
    } catch (\Throwable $e) {
        ob_end_clean();
        $check('meta pinta a barra no mês', false, get_class($e) . ': ' . $e->getMessage());
    }
    $pdo->rollBack();
    MotorFinanceiro::limparCache();
}

// ---- 5-9: previsão — a meta é a previsão padrão de todo mês; o valor do mês é exceção
$prevMeses = array_values(array_map(static fn($m) => (string) $m['mes'],
    array_filter(MesModel::todos(), static fn($m) => $m['situacao'] === 'PREVISAO')));
$realMes = null;
foreach (MesModel::todos() as $m) {
    if ($m['situacao'] === 'REAL') {
        $realMes = (string) $m['mes'];
        break;
    }
}
$catDia = (int) $pdo->query(
    "SELECT id FROM categorias WHERE grupo = 'Dia a dia' AND ativo = 1 AND operacional = 1 AND sinal = -1 ORDER BY id LIMIT 1"
)->fetchColumn();
$catMov = (int) $pdo->query('SELECT id FROM categorias WHERE operacional = 0 ORDER BY id LIMIT 1')->fetchColumn();
$linhaCrua = static function (int $c, string $m) use ($pdo): ?float {
    $st = $pdo->prepare('SELECT valor FROM previsao WHERE categoria_id = :c AND mes = :m');
    $st->execute(['c' => $c, 'm' => $m]);
    $v = $st->fetchColumn();
    return $v === false ? null : (float) $v;
};
$emTransacao = static function (string $nome, callable $corpo) use ($pdo, $check): void {
    $pdo->beginTransaction();
    MotorFinanceiro::limparCache();
    try {
        $corpo();
    } catch (\Throwable $e) {
        $check($nome, false, get_class($e) . ': ' . $e->getMessage() . ' em ' . basename($e->getFile()) . ':' . $e->getLine());
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        MotorFinanceiro::limparCache();
    }
};

if (count($prevMeses) < 2 || $catDia === 0) {
    $check('previsão efetiva', true, 'sem 2 meses em PREVISAO ou sem categoria de dia a dia para testar');
} else {
    [$m1, $m2] = [$prevMeses[count($prevMeses) - 2], $prevMeses[count($prevMeses) - 1]];

    // 5. a meta vale onde o mês não tem linha; a linha do mês vence; categoria arquivada não herda meta
    $emTransacao('previsão efetiva', static function () use ($pdo, $check, $catDia, $m1, $m2): void {
        $pdo->prepare('DELETE FROM previsao WHERE categoria_id = :c AND mes IN (:a, :b)')->execute(['c' => $catDia, 'a' => $m1, 'b' => $m2]);
        MetaModel::definir($catDia, 123.45);
        MotorFinanceiro::limparCache();
        $d = MotorFinanceiro::dados();
        $check('meta vira a previsão do mês sem linha', abs(PrevisaoModel::valor($catDia, $m1) - 123.45) < 0.001
            && abs(($d['prev'][$m1][$catDia] ?? 0.0) - 123.45) < 0.001 && empty($d['excecao'][$m1][$catDia])
            && abs(($d['metas'][$catDia] ?? 0.0) - 123.45) < 0.001);
        PrevisaoModel::setValor($catDia, $m1, 50.0);
        MotorFinanceiro::limparCache();
        $d = MotorFinanceiro::dados();
        $check('valor do mês vence a meta (exceção)', abs(PrevisaoModel::valor($catDia, $m1) - 50.0) < 0.001
            && !empty($d['excecao'][$m1][$catDia]) && abs(PrevisaoModel::valor($catDia, $m2) - 123.45) < 0.001);
        $pdo->prepare('UPDATE categorias SET ativo = 0 WHERE id = :c')->execute(['c' => $catDia]);
        MotorFinanceiro::limparCache();
        $check('categoria arquivada não herda a meta', PrevisaoModel::valor($catDia, $m2) == 0.0
            && !isset(MotorFinanceiro::dados()['metas'][$catDia]));
    });

    // 6. PrevisaoLote: célula null apaga a linha (volta à meta) e o desfazer recria; nada muda → null; REAL recusa
    $emTransacao('PrevisaoLote com célula null', static function () use ($pdo, $check, $catDia, $m1, $m2, $uid, $realMes, $linhaCrua): void {
        $pdo->prepare('DELETE FROM previsao WHERE categoria_id = :c AND mes IN (:a, :b)')->execute(['c' => $catDia, 'a' => $m1, 'b' => $m2]);
        MetaModel::definir($catDia, 200.0);
        PrevisaoModel::setValor($catDia, $m1, 80.0);
        $lote = PrevisaoLote::gravar('manual', [[$catDia, $m1, null]], $uid);
        $st = $pdo->prepare('SELECT antes, depois FROM previsao_log WHERE lote = :l');
        $st->execute(['l' => (string) $lote]);
        $log = $st->fetch();
        $check('voltar à meta apaga a linha e loga depois NULL', $lote !== null && $linhaCrua($catDia, $m1) === null
            && abs(PrevisaoModel::valor($catDia, $m1) - 200.0) < 0.001 && $log && $log['depois'] === null && abs((float) $log['antes'] - 80.0) < 0.001);
        $r = PrevisaoLote::desfazer((string) $lote, $uid);
        $check('desfazer recria a linha do mês', $r['restauradas'] === 1 && abs((float) $linhaCrua($catDia, $m1) - 80.0) < 0.001);
        $check('mesmo valor: nada a gravar', PrevisaoLote::gravar('manual', [[$catDia, $m1, 80.0]], $uid) === null);
        $l2 = PrevisaoLote::gravar('manual', [[$catDia, $m2, 33.0]], $uid);
        $r2 = PrevisaoLote::desfazer((string) $l2, $uid);
        $check('desfazer de linha nova apaga a linha (volta à meta)', $r2['restauradas'] === 1 && $linhaCrua($catDia, $m2) === null);
        $check('sem linha e null: nada a gravar', PrevisaoLote::gravar('manual', [[$catDia, $m2, null]], $uid) === null);
        if ($realMes !== null) {
            $recusou = false;
            try {
                PrevisaoLote::gravar('manual', [[$catDia, $realMes, 1.0]], $uid);
            } catch (\InvalidArgumentException) {
                $recusou = true;
            }
            $check('PrevisaoLote recusa mês fechado', $recusou);
        }
    });

    // 7. PrevisaoEdicao::celulas (aritmética pura)
    $d = MotorFinanceiro::dados();
    $todos = PrevisaoEdicao::celulas($d, $catDia, $m1, 'salvar', 10.0, true);
    $mesesCel = array_map(static fn($c) => $c[1], $todos);
    $check('propagar: este mês e os PREVISAO seguintes, nunca fechado', $mesesCel === array_values(array_filter($prevMeses, static fn($m) => $m >= $m1))
        && array_sum(array_map(static fn($c) => (float) $c[2], $todos)) == 10.0 * count($todos));
    $check('sem propagar: só o mês', PrevisaoEdicao::celulas($d, $catDia, $m1, 'salvar', 10.0, false) === [[$catDia, $m1, 10.0]]);
    $dMeta = $d;
    $dMeta['metas'][$catDia] = 77.0;
    $check('salvar o valor da meta = seguir a meta (null)', PrevisaoEdicao::celulas($dMeta, $catDia, $m1, 'salvar', 77.0, false) === [[$catDia, $m1, null]]);
    $check('tirar = 0 no mês', PrevisaoEdicao::celulas($d, $catDia, $m1, 'tirar', null, false) === [[$catDia, $m1, 0.0]]);
    $erroDe = static function (callable $f): string {
        try {
            $f();
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }
        return '';
    };
    $dSemMeta = $d;
    unset($dSemMeta['metas'][$catDia]);
    $check('voltar à meta sem meta: recusa', $erroDe(static fn() => PrevisaoEdicao::celulas($dSemMeta, $catDia, $m1, 'meta', null, false)) === 'sem meta');
    $check('valor negativo ou vazio: recusa', $erroDe(static fn() => PrevisaoEdicao::celulas($d, $catDia, $m1, 'salvar', -1.0, false)) === PrevisaoEdicao::ERRO_VALOR
        && $erroDe(static fn() => PrevisaoEdicao::celulas($d, $catDia, $m1, 'salvar', null, false)) === PrevisaoEdicao::ERRO_VALOR);
    if ($realMes !== null) {
        $check('mês fechado: recusa', str_contains($erroDe(static fn() => PrevisaoEdicao::celulas($d, $catDia, $realMes, 'salvar', 1.0, false)), 'fechado'));
    }
    if ($catMov > 0) {
        $check('empréstimo/poupança não se edita na Mês', $erroDe(static fn() => PrevisaoEdicao::celulas($d, $catMov, $m1, 'salvar', 1.0, false)) === 'categoria?');
    }

    // 8. alerta de reserva/vermelho depois de uma mudança
    $pa = [$m1 => ['estado' => 'previsto', 'fica_na_conta' => 3500.0], $m2 => ['estado' => 'previsto', 'fica_na_conta' => 100.0]];
    $pb = [$m1 => ['estado' => 'previsto', 'fica_na_conta' => 2900.0], $m2 => ['estado' => 'previsto', 'fica_na_conta' => -5.0]];
    $check('piorou: primeiro mês que furou a reserva', PrevisaoEdicao::piorou($pa, $pb, $m1, 3000.0) === ['mes' => $m1, 'tipo' => 'reserva']
        && PrevisaoEdicao::piorou($pa, $pb, $m2, 3000.0) === ['mes' => $m2, 'tipo' => 'vermelho']
        && PrevisaoEdicao::piorou($pa, $pa, $m1, 3000.0) === null);
}

// ---- 9. Mês: editável em PREVISAO (form, fita, incluir); só leitura quando fechado; anual leva ao mês
$render = static function (string $cls, string $metodo, array $get) use ($check): string {
    $_SERVER['REQUEST_URI'] = '/x?' . http_build_query($get);
    $_GET = $get;
    ob_start();
    try {
        (new $cls())->$metodo();
        return (string) ob_get_clean();
    } catch (\Throwable $e) {
        ob_end_clean();
        $check("render {$cls}::{$metodo}", false, get_class($e) . ': ' . $e->getMessage());
        return '';
    }
};
if ($prevMeses !== []) {
    $html = $render(MesController::class, 'index', ['mes' => end($prevMeses)]);
    $check('último mês: linhas editáveis + seletor de meses, sem "meses seguintes"', str_contains($html, 'action="/mes/previsao"')
        && str_contains($html, 'class="mes-linha mes-ed"') && str_contains($html, 'data-mes-picker') && !str_contains($html, 'name="propagar"'));
    $html = $render(MesController::class, 'index', ['mes' => $prevMeses[0]]);
    $check('Mês aberto: editável com "também nos meses seguintes"', str_contains($html, 'action="/mes/previsao"') && str_contains($html, 'name="propagar"'));
}
if ($realMes !== null) {
    $html = $render(MesController::class, 'index', ['mes' => $realMes]);
    $check('Mês fechado: só leitura', str_contains($html, 'mes-ros') && !str_contains($html, 'action="/mes/previsao"'));
}
$check('anual: cada mês leva à aba Mês', str_contains($render(FuturoController::class, 'index', []), 'class="btn-link fu-abrir" href="/mes?mes='));
$check('meta: explica que a meta é a previsão de todo mês', str_contains($render(MetaController::class, 'index', []), 'meta-previsao'));

// ---- 10. fechar congela as metas do mês; mês fechado não segue a meta; reabrir descongela
if ($prevMeses !== [] && $catDia > 0) {
    $mC = $prevMeses[0];
    $emTransacao('congelar ao fechar', static function () use ($pdo, $check, $catDia, $mC, $uid, $linhaCrua): void {
        $pdo->prepare('DELETE FROM previsao WHERE categoria_id = :c AND mes = :m')->execute(['c' => $catDia, 'm' => $mC]);
        MetaModel::definir($catDia, 222.0);
        MotorFinanceiro::limparCache();
        $lote = PrevisaoLote::congelar($mC, $uid);
        $check('congelar grava a meta como linha do mês', $lote !== null && abs((float) $linhaCrua($catDia, $mC) - 222.0) < 0.001);
        $ult = PrevisaoLote::ultimoDesfazivel();
        $check('congelamento fica fora do "desfazer última alteração"', $ult === null || $ult['lote'] !== $lote);
        MesModel::setSituacao($mC, 'REAL');
        MetaModel::definir($catDia, 333.0);
        MotorFinanceiro::limparCache();
        $check('mês fechado não muda quando a meta muda', abs(PrevisaoModel::valor($catDia, $mC) - 222.0) < 0.001);
        MesModel::setSituacao($mC, 'PREVISAO');
        PrevisaoLote::descongelar($mC, $uid);
        MotorFinanceiro::limparCache();
        $check('reabrir descongela: o mês volta a seguir a meta', $linhaCrua($catDia, $mC) === null
            && abs(PrevisaoModel::valor($catDia, $mC) - 333.0) < 0.001);
        MesModel::setSituacao($mC, 'REAL');
        MotorFinanceiro::limparCache();
        $check('mês fechado sem linha: a meta não vale', PrevisaoModel::valor($catDia, $mC) == 0.0);
    });
}

// ---- 11. ícones de categoria: catálogo, sugestão pelo nome, escolher e trocar
$todosExistem = true;
foreach (Icones::REGRAS as [$k]) {
    $todosExistem = $todosExistem && Icones::existe($k);
}
foreach (Icones::POR_GRUPO as $k) {
    $todosExistem = $todosExistem && Icones::existe($k);
}
$check('ícones: toda regra e todo grupo apontam para um ícone do catálogo', $todosExistem);
$lic = Icones::lista();
$dim = @getimagesize(__DIR__ . '/../public/assets/img/icones.webp');
$check('ícones: o sprite tem o tamanho do catálogo (célula + respiro)', is_array($dim)
    && $dim[0] === $lic['colunas'] * $lic['passo'] && $dim[1] === $lic['linhas'] * $lic['passo']
    && $lic['passo'] > $lic['tamanho'] && count($lic['icones']) <= $lic['colunas'] * $lic['linhas']);
$esperado = ['Farmácia e saúde' => 'pilula', 'Mercado' => 'prato', 'Combustível' => 'combustivel',
             'Imposto de renda' => 'leao', 'Gastos mesada Matheus' => 'nota', 'Seguro do carro' => 'carro',
             'Licenciamento do carro' => 'documento', 'Ração do Bob' => 'cachorro', 'Barbeiro' => 'barbearia'];
$erradas = [];
foreach ($esperado as $nomeCat => $k) {
    if (Icones::sugerir($nomeCat, 'Dia a dia') !== $k) {
        $erradas[] = $nomeCat . ' → ' . Icones::sugerir($nomeCat, 'Dia a dia');
    }
}
$check('ícones: sugestão pelo nome', $erradas === [], implode('; ', $erradas));
$check('ícones: sem palavra conhecida, vale o ícone do grupo', Icones::sugerir('Xyzw', 'Entradas') === 'moeda'
    && Icones::sugerir('Xyzw', 'Dia a dia') === 'sacola' && Icones::sugerir('Xyzw', '') === 'sacola');
$semIcone = [];
foreach ($pdo->query('SELECT id, nome, grupo FROM categorias')->fetchAll() as $c) {
    if (!Icones::existe(Icones::daCategoria((int) $c['id'], (string) $c['nome'], (string) $c['grupo']))) {
        $semIcone[] = (string) $c['nome'];
    }
}
$check('ícones: toda categoria do banco tem ícone', $semIcone === [], implode(', ', $semIcone));
$check('ícones: o HTML põe o emoji do sprite dentro do círculo', str_contains(Icones::html('pilula', 40), '<i style="background-size:')
    && str_contains(Icones::html('pilula', 40), 'background-position:') && str_contains(Icones::html('chave-que-nao-existe', 20), 'width:20px'));
$emTransacao('categoria com ícone', static function () use ($check): void {
    $id = CategoriaModel::criar('Teste ícone ' . bin2hex(random_bytes(3)), 'saida', false, 'gato');
    Icones::limpar();
    $check('categoria nova guarda o ícone escolhido', $id !== null && Icones::daCategoria((int) $id) === 'gato');
    CategoriaModel::setIcone((int) $id, 'cachorro');
    Icones::limpar();
    $check('trocar o ícone grava', Icones::daCategoria((int) $id) === 'cachorro');
    $id2 = CategoriaModel::criar('Farmácia teste ' . bin2hex(random_bytes(3)), 'saida', true);
    Icones::limpar();
    $check('sem escolha, a categoria nova usa a sugestão pelo nome', $id2 !== null
        && Icones::daCategoria((int) $id2, 'Farmácia teste', 'Dia a dia') === 'pilula');
});
Icones::limpar();
$htmlEx = $render(LancamentosController::class, 'index', []);
$check('extrato: ícone em cada lançamento e escolha de ícone na categoria nova', str_contains($htmlEx, 'class="ci"')
    && str_contains($htmlEx, 'data-ic-sugerir') && str_contains($htmlEx, 'name="icone"') && str_contains($htmlEx, '/icone?volta='));
$check('lançar: só o select de categoria, sem atalhos', !str_contains($render(LancamentosController::class, 'lancarForm', []), 'lf-atalho'));
if ($catDia > 0) {
    $_SERVER['REQUEST_URI'] = '/categorias/' . $catDia . '/icone';
    $_GET = [];
    ob_start();
    try {
        (new CategoriaController())->iconeForm((string) $catDia);
        $htmlIc = (string) ob_get_clean();
    } catch (\Throwable $e) {
        ob_end_clean();
        $htmlIc = '';
    }
    $check('trocar ícone: a página abre com o atual marcado', str_contains($htmlIc, 'ic-picker') && str_contains($htmlIc, ' checked>'));
}

// ---- 12. barra do celular: o "+" no meio de 4 abas; o Anual abre pelo seletor de meses do Mês
$htmlMes = $render(MesController::class, 'index', []);
preg_match('#<nav class="tabbar".*?</nav>#s', $htmlMes, $m);
$barra = $m[0] ?? '';
preg_match_all('#<a class="(tab[^" ]*)#', $barra, $itens);
$check('barra do celular: 2 abas, o +, 2 abas (sem o Anual)', $itens[1] === ['tab', 'tab', 'tab-add', 'tab', 'tab']
    && !str_contains($barra, 'href="/futuro"') && str_contains($htmlMes, 'class="lat-i" href="/futuro"'), implode(',', $itens[1]));
$check('seletor de meses do Mês leva ao Anual; o do Extrato não', str_contains($htmlMes, 'class="mes-picker-anual" href="/futuro"')
    && !str_contains($htmlEx, 'mes-picker-anual'));
$check('menu do avatar (celular) também leva ao Anual', str_contains($htmlMes, 'class="menu-item" href="/futuro"'));
$check('no Anual, a aba Mês da barra fica acesa', str_contains($render(FuturoController::class, 'index', []), 'class="tab is-active" href="/mes"'));

// ---- 13. Anual: marcos do plano e o que mudou em cada mês (AnualResumo, dados de mentira)
$pl = static fn(string $m, float $fica, bool $quit = false, float $parc = 0.0): array => ['mes' => $m, 'estado' => 'previsto',
    'fica_na_conta' => $fica, 'quitado' => $quit, 'bateu_meta' => false, 'parcela_consignado' => $parc];
$planoT = [];
foreach ([['2027-01-01', 5000], ['2027-02-01', 2000], ['2027-03-01', -100], ['2027-04-01', -50], ['2027-05-01', 2500],
          ['2027-06-01', 3500, true], ['2027-07-01', 4000, true]] as $r) {
    $planoT[$r[0]] = $pl($r[0], (float) $r[1], $r[2] ?? false);
}
$mk = AnualResumo::marcos($planoT, 3000.0, '2027-02-01');
$check('anual: mês mais apertado e faixas no vermelho / abaixo da reserva', ($mk['apertado']['mes'] ?? '') === '2027-03-01'
    && array_map(static fn($f) => $f['tipo'] . ' ' . substr($f['ini'], 0, 7) . '..' . substr($f['fim'], 0, 7), $mk['faixas'])
       === ['warn 2027-02..2027-02', 'neg 2027-03..2027-04', 'warn 2027-05..2027-05'], json_encode($mk['faixas']));
$check('anual: volta à reserva, quitação e fim contados da referência', $mk['volta'] === '2027-06-01' && $mk['quita'] === '2027-06-01'
    && $mk['quitaAntes'] === false && ($mk['fim']['mes'] ?? '') === '2027-07-01' && abs($mk['fim']['desde'] - 2000.0) < 0.01);
$dadosT = ['real' => [], 'cats' => [
    1 => ['id' => 1, 'nome' => 'Salário', 'apelido' => null, 'grupo' => 'Entradas', 'sinal' => 1, 'operacional' => 1],
    2 => ['id' => 2, 'nome' => 'IPVA (carro)', 'apelido' => null, 'grupo' => 'Ano', 'sinal' => -1, 'operacional' => 1],
], 'prev' => ['2027-01-01' => [1 => 10000.0], '2027-02-01' => [1 => 10000.0, 2 => 1200.0], '2027-03-01' => [1 => 15000.0, 2 => 100.0]]];
$planoM = ['2027-01-01' => $pl('2027-01-01', 0.0), '2027-02-01' => $pl('2027-02-01', 0.0), '2027-03-01' => $pl('2027-03-01', 0.0, false, 300.0)];
$mu = AnualResumo::mudancas($planoM, $dadosT);
$lin = static fn(string $m, float $div, float $j, float $am, bool $q): array => ['mes' => $m, 'estado' => 'previsto',
    'falta_pagar' => $div, 'juros' => $j, 'amortizacao_extra' => $am, 'quitado' => $q];
$comT = ['2027-01-01' => $lin('2027-01-01', 600.0, 10.0, 400.0, false), '2027-02-01' => $lin('2027-02-01', 0.0, 6.0, 606.0, true)];
$semT = ['2027-01-01' => $lin('2027-01-01', 1000.0, 10.0, 0.0, false), '2027-02-01' => $lin('2027-02-01', 910.0, 10.0, 0.0, false)];
$gT = AnualResumo::ganho($comT, $semT, 100.0, 0.01);
$check('anual: adiantar o empréstimo — quando acabaria só com as parcelas e os juros que se evitam', $gT !== null
    && $gT['quitaCom'] === '2027-02-01' && $gT['quitaSem'] === '2027-12-01' && $gT['meses'] === 10 && $gT['juros'] > 40 && $gT['juros'] < 60,
    json_encode($gT));
$check('anual: sem adiantamento no plano, não há ganho a mostrar', AnualResumo::ganho($semT, $semT, 100.0, 0.01) === null);
// o "o que mudou" sai da previsão gravada no banco a cada requisição: mudar um mês aparece nele e no seguinte
if ($catDia > 0 && count($prevMeses) >= 3) {
    $mA = $prevMeses[count($prevMeses) - 3];
    $mB = date('Y-m-01', strtotime($mA . ' +1 month'));
    $emTransacao('o que mudou acompanha a previsão', static function () use ($check, $catDia, $mA, $mB): void {
        MotorFinanceiro::limparCache();
        PrevisaoModel::setValor($catDia, $mA, PrevisaoModel::valor($catDia, $mA) + 6543.0);
        MotorFinanceiro::limparCache();
        $mu = AnualResumo::mudancas(MotorFinanceiro::calcularPlano(), MotorFinanceiro::dados());
        $acha = static fn(array $l): int => count(array_filter($l, static fn($x) => abs(abs($x['valor'] - $x['antes']) - 6543.0) < 0.01));
        $check('anual: "o que mudou" acompanha a previsão gravada (sobe no mês, volta no seguinte)',
            $acha($mu[$mA] ?? []) === 1 && $acha($mu[$mB] ?? []) === 1 && ($mu[$mA][0]['efeito'] ?? 0) < 0 && ($mu[$mB][0]['efeito'] ?? 0) > 0,
            json_encode([$mu[$mA] ?? null, $mu[$mB] ?? null], JSON_UNESCAPED_UNICODE));
    });
    MotorFinanceiro::limparCache();
}
$htmlFu = $render(FuturoController::class, 'index', []);
$check('anual: resumo, conclusão no título do gráfico, previsão tracejada e total por ano', str_contains($htmlFu, 'class="card fu-res"')
    && str_contains($htmlFu, 'id="fu-graf-t">A conta fica') && str_contains($htmlFu, 'class="fu-linha is-prev"')
    && str_contains($htmlFu, 'class="fu-cols fu-total num"') && !str_contains($htmlFu, 'fu-area'));
$check('anual: o que mudou no mês, do maior efeito para o menor', $mu['2027-01-01'] === []
    && ($mu['2027-02-01'][0]['nome'] ?? '') === 'IPVA' && abs($mu['2027-02-01'][0]['efeito'] + 1200.0) < 0.01
    && array_map(static fn($x) => $x['nome'] . ' ' . (int) $x['efeito'], $mu['2027-03-01'])
       === ['Salário 5000', 'IPVA 1100', 'Desconto do consignado -300'], json_encode($mu['2027-03-01']));

// ---- 14. "e se" do Anual: o motor soma o extra fora de mês fechado; a URL vira simulação; nada é gravado
MotorFinanceiro::limparCache();
$planoSemAd = MotorFinanceiro::calcular(MotorFinanceiro::dados(), ['objetivo' => 3]);
$mesesPrevE = array_values(array_filter(array_keys($planoSemAd), static fn($m) => $planoSemAd[$m]['estado'] === 'previsto'));
$mesesReal = array_values(array_filter(array_keys($planoSemAd), static fn($m) => $planoSemAd[$m]['estado'] === 'real'));
if (count($mesesPrevE) >= 2) {
    [$e1, $e2] = [$mesesPrevE[0], $mesesPrevE[1]];
    $extraT = [$e1 => 500.0] + ($mesesReal !== [] ? [$mesesReal[0] => 9999.0] : []);
    $comExtra = MotorFinanceiro::calcular(MotorFinanceiro::dados(), ['objetivo' => 3, 'extra' => $extraT]);
    $check('e se: +500 num mês sobe a conta 500 nele e nos seguintes (sem adiantar)',
        abs($comExtra[$e1]['fica_na_conta'] - $planoSemAd[$e1]['fica_na_conta'] - 500.0) < 0.01
        && abs($comExtra[$e2]['fica_na_conta'] - $planoSemAd[$e2]['fica_na_conta'] - 500.0) < 0.01);
    if ($mesesReal !== []) {
        $check('e se: mês fechado não muda', abs($comExtra[$mesesReal[0]]['fica_na_conta'] - $planoSemAd[$mesesReal[0]]['fica_na_conta']) < 0.01);
    }
    $refE = MesModel::referencia(date('Y-m-d'));
    $_GET = ['se' => '1', 'mensal' => '500', 'mensal_tipo' => 'economizar', 'mensal_de' => $e1,
             'uma' => '2.000,00', 'uma_tipo' => 'gasto', 'uma_mes' => $e2, 'adiantar' => '1'];
    $simT = FuturoController::simulacao(MotorFinanceiro::calcularPlano(), $refE, true);
    $check('e se: a URL vira o extra de cada mês (500 por mês, gasto de 2.000 num deles)', $simT['ativo']
        && abs(($simT['extra'][$e1] ?? 0.0) - 500.0) < 0.01 && abs(($simT['extra'][$e2] ?? 0.0) + 1500.0) < 0.01 && $simT['erros'] === []);
    $_GET = ['se' => '1', 'mensal' => 'abc', 'adiantar' => '1'];
    $simT2 = FuturoController::simulacao(MotorFinanceiro::calcularPlano(), $refE, true);
    $check('e se: valor inválido é avisado e ignorado', !$simT2['ativo'] && count($simT2['erros']) === 1);
    $htmlSe = $render(FuturoController::class, 'index', ['se' => '1', 'mensal' => '500', 'mensal_de' => $e1, 'adiantar' => '1']);
    $check('e se: a página mostra o aviso, a comparação com o plano e a linha do plano', str_contains($htmlSe, 'class="fu-sim"')
        && str_contains($htmlSe, 'class="fu-cmp num"') && str_contains($htmlSe, 'class="fu-base-l"') && str_contains($htmlSe, 'E se: +500'));
    $check('e se: sem simulação, nada disso aparece', !str_contains($htmlFu, 'class="fu-sim"') && !str_contains($htmlFu, 'class="fu-cmp num"')
        && str_contains($htmlFu, 'id="e-se"'));
    $_GET = [];
}

// ---- 15. cartão: fatura de uma compra, parcelas, compra antiga, plano, Mês e a barra
$check('cartão: compra antes, no dia e depois do fechamento (fecha 25, vence 5)',
    CartaoFatura::faturaDe('2026-10-16', 25, 5) === '2026-11-01' && CartaoFatura::faturaDe('2026-10-25', 25, 5) === '2026-12-01'
    && CartaoFatura::faturaDe('2026-10-26', 25, 5) === '2026-12-01' && CartaoFatura::faturaDe('2026-12-31', 25, 5) === '2027-02-01');
$check('cartão: vencimento depois do fechamento no mesmo mês (fecha 3, vence 10) e mês curto',
    CartaoFatura::faturaDe('2026-10-02', 3, 10) === '2026-10-01' && CartaoFatura::faturaDe('2026-10-03', 3, 10) === '2026-11-01'
    && CartaoFatura::faturaDe('2027-02-28', 31, 8) === '2027-04-01'
    && CartaoFatura::fechamento('2026-11-01', 25, 5) === '2026-10-25' && CartaoFatura::vencimento('2026-11-01', 5) === '2026-11-05');
$pT = CartaoFatura::parcelas(100.0, 3, '2026-11-01');
$check('cartão: os centavos que sobram vão na 1ª parcela', array_map(static fn($x) => $x[0] . ' ' . $x[1] . ' ' . number_format($x[2], 2), $pT)
    === ['2026-11-01 1 33.34', '2026-12-01 2 33.33', '2027-01-01 3 33.33'], json_encode($pT));
$check('cartão: compra antiga (parcela 4 na fatura aberta de nov/26) começou em ago/26', CartaoFatura::primeiraDaAntiga('2026-11-01', 4) === '2026-08-01'
    && CartaoFatura::primeiraDaAntiga('2026-11-01', 1) === '2026-11-01');
$compT = [
    ['id' => 1, 'valor_total' => 3200.0, 'parcelas' => 10, 'primeira_fatura' => '2026-11-01', 'descricao' => 'Máquina', 'data' => '2026-10-16'],
    ['id' => 2, 'valor_total' => 600.0, 'parcelas' => 6, 'primeira_fatura' => '2026-08-01', 'descricao' => 'Fone', 'data' => '2026-07-20'],
    ['id' => 3, 'valor_total' => 50.0, 'parcelas' => 1, 'primeira_fatura' => '2026-11-01', 'descricao' => 'Farmácia', 'data' => '2026-10-10'],
];
$pnT = CartaoFatura::painel(['fecha' => 25, 'vence' => 5, 'limite' => 10000.0], $compT, '2026-10-16', ['2026-10-01' => 5751.52]);
$fNov = $pnT['faturas'][1];
$check('cartão: painel — fatura aberta, a anterior paga, o total e o limite livre', $pnT['aberta'] === '2026-11-01'
    && $pnT['faturas'][0]['status'] === 'paga' && $fNov['status'] === 'aberta' && abs($fNov['total'] - 470.0) < 0.01
    && abs($pnT['comprometido'] - (3200.0 + 300.0 + 50.0)) < 0.01 && abs($pnT['livre'] - (10000.0 - 3550.0)) < 0.01, json_encode([$fNov['total'], $pnT['comprometido']]));
$check('cartão: parcelamentos do que acaba antes para o que acaba depois', array_map(static fn($x) => $x['compra']['id'] . ':' . $x['agora'] . '/' . $x['n'], $pnT['parcelamentos'])
    === ['2:4/6', '1:1/10'], json_encode(array_map(static fn($x) => [$x['compra']['id'], $x['agora']], $pnT['parcelamentos'])));
$catFat = CartaoModel::categoriaFatura();
$catGasto = (int) $pdo->query("SELECT id FROM categorias WHERE grupo = 'Dia a dia' AND ativo = 1 AND sinal = -1 ORDER BY id LIMIT 1")->fetchColumn();
if ($catFat !== null && $catGasto > 0 && count($prevMeses) >= 3) {
    $emTransacao('cartão alimentando o plano', static function () use ($pdo, $check, $catFat, $catGasto, $render): void {
        $hojeT = date('Y-m-d');
        $pdo->exec('UPDATE cartoes SET ativo = 0');    // o cartão de verdade (se já houver) sai de cena até o rollback
        $idC = CartaoModel::salvar(null, 'Cartão teste', 25, 5, null);
        $aberta = CartaoFatura::faturaDe($hojeT, 25, 5);
        $antes = PrevisaoModel::valor($catFat, $aberta);
        $r = CartaoController::criarCompraDe(['valor' => '1.000,00', 'categoria_id' => (string) $catGasto, 'data' => $hojeT,
                                              'parcelas' => '4', 'descricao' => 'TV teste'], $hojeT);
        MotorFinanceiro::limparCache();
        $check('cartão: a compra entra no cartão e, desligado do plano, a previsão da fatura não muda', $r['erro'] === null && $r['id'] > 0
            && abs(PrevisaoModel::valor($catFat, $aberta) - $antes) < 0.01 && str_contains($r['aviso'], '4 × 250,00'), json_encode($r));
        $erroCat = CartaoController::criarCompraDe(['valor' => '10', 'categoria_id' => (string) $catFat, 'data' => $hojeT, 'parcelas' => '1'], $hojeT);
        $check('cartão: a categoria da compra não pode ser a própria fatura', $erroCat['erro'] !== null);
        CartaoModel::setAlimentaPlano($idC, true);
        $lote = CartaoFatura::sincronizarPlano((int) Auth::id(), $hojeT);
        MotorFinanceiro::limparCache();
        $mesSeg = CartaoFatura::somaMeses($aberta, 3);
        $mesFim = CartaoFatura::somaMeses($aberta, 4);
        $sit = static fn(string $m): ?string => ($v = $pdo->query("SELECT situacao FROM meses WHERE mes = '" . $m . "'")->fetchColumn()) === false ? null : (string) $v;
        $okMeses = ($sit($aberta) !== 'PREVISAO' || abs(PrevisaoModel::valor($catFat, $aberta) - 250.0) < 0.01)
            && ($sit($mesSeg) !== 'PREVISAO' || abs(PrevisaoModel::valor($catFat, $mesSeg) - 250.0) < 0.01)
            && ($sit($mesFim) !== 'PREVISAO' || abs(PrevisaoModel::valor($catFat, $mesFim)) < 0.01);
        $origem = $lote !== null ? $pdo->query("SELECT origem FROM previsao_lotes WHERE lote = '" . $lote . "'")->fetchColumn() : null;
        $check('cartão: ligado ao plano, a Fatura do cartão de cada mês vira a soma das parcelas (lote cartao, fora do desfazer do Mês)',
            $lote !== null && $origem === 'cartao' && $okMeses && (PrevisaoLote::ultimoDesfazivel()['lote'] ?? '') !== $lote,
            json_encode([$lote, $origem, PrevisaoModel::valor($catFat, $aberta)]));
        $htmlMesC = $sit($mesSeg) === 'PREVISAO' ? $render(MesController::class, 'index', ['mes' => $mesSeg]) : 'mes-do-cartao';
        $check('cartão: no Mês, a Fatura do cartão vem do cartão (só leitura, leva à fatura)', str_contains($htmlMesC, 'mes-do-cartao'));
        $htmlCt = $render(CartaoController::class, 'index', []);
        $check('cartão: a aba mostra o cartão, a escada, a fatura e o parcelamento', str_contains($htmlCt, 'class="ct-plastico"')
            && str_contains($htmlCt, 'class="ct-escada"') && str_contains($htmlCt, 'TV teste') && str_contains($htmlCt, 'class="ct-parc"')
            && !str_contains($htmlCt, 'ct-conf-tab'));
        $antigas = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'cartao_compras_v1'")->fetchColumn() > 0
            ? (int) $pdo->query('SELECT COUNT(*) FROM cartao_compras_v1 v WHERE NOT EXISTS (SELECT 1 FROM cartao_compras n
                                 WHERE n.descricao = v.descricao AND n.criado_em = v.criado_em)')->fetchColumn() : 0;   // as que ainda não vieram
        $vieram = CartaoModel::importarAntigas($idC);
        $deNovo = CartaoModel::importarAntigas($idC);
        $check('cartão: as compras da versão antiga do app vêm junto, uma vez só', $vieram === $antigas && $deNovo === 0, json_encode([$antigas, $vieram, $deNovo]));
        LancarForm::limparCache();    // os dados do + ficam em cache na requisição; o cartão de teste acabou de nascer
        $htmlLanc = $render(LancamentosController::class, 'lancarForm', ['pagou' => 'cartao']);
        $check('cartão: o + tem "Pagou com", as vezes e a prévia, já em Cartão', str_contains($htmlLanc, 'name="pagou" value="cartao" checked')
            && str_contains($htmlLanc, 'data-ct-bloco') && str_contains($htmlLanc, 'name="parcelas"'));
    });
    MotorFinanceiro::limparCache();
    LancarForm::limparCache();
}
$check('cartão: sem cartão cadastrado, a aba pede o cadastro', CartaoModel::ativo() !== null
    || str_contains($render(CartaoController::class, 'index', []), 'Cadastre o cartão'));
$htmlBar = $render(MesController::class, 'index', []);
preg_match('#<nav class="tabbar".*?</nav>#s', $htmlBar, $mB);
$check('barra do celular: Início, Extrato, +, Mês, Cartão; Metas e Anual no menu do M', str_contains($mB[0] ?? '', 'href="/cartao"')
    && !str_contains($mB[0] ?? '', 'href="/meta"') && str_contains($htmlBar, 'class="menu-item" href="/meta"') && str_contains($htmlBar, 'class="lat-i" href="/cartao"'));

echo "\n{$ok} ok, {$falhas} falha(s)\n";
exit($falhas > 0 ? 1 : 0);
