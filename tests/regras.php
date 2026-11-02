<?php
/**
 * As regras do app, num banco de demonstração descartável e com o relógio parado (FIN_RELOGIO): o resultado não
 * depende dos dados nem do dia de quem roda. tests/run.php roda este arquivo duas vezes: num dia comum e, com --borda,
 * às 23:50 do último dia do mês, só com a primeira parte (o que olha a data de hoje) e a conferência do site.
 *
 * Este processo cria a pasta temporária e, com FIN_REGRAS_DRIVER=mysql, um banco MySQL vazio; as regras rodam num
 * processo filho, com FIN_DADOS nessa pasta e FIN_RELOGIO. No fim, a pasta e o banco somem, mesmo se o filho morrer.
 * O MySQL é o de FIN_REGRAS_DB_HOST, FIN_REGRAS_DB_USER e FIN_REGRAS_DB_PASS, que precisa poder criar e apagar bancos.
 *
 * Uso: php tests/regras.php [--borda]. Sai com 1 se algo falhar.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Rode pela linha de comando.\n");
}
// um dia comum, no meio do mês; e o último dia do mês, quando faltam minutos para o seguinte
const RELOGIO = '2026-11-22 15:30:00';
const RELOGIO_BORDA = '2026-11-30 23:50:00';
$borda = in_array('--borda', $argv, true);

// ---- este processo: prepara o banco descartável, roda as regras num filho e apaga tudo no fim
if (getenv('FIN_REGRAS_PASTA') === false) {
    require __DIR__ . '/../src/autoload.php';
    $pasta = sys_get_temp_dir() . '/financeiro-regras-' . bin2hex(random_bytes(6));
    if (!@mkdir($pasta, 0700)) {
        echo "FALHA não deu para criar a pasta temporária das regras em " . sys_get_temp_dir() . "\n\n0 ok, 1 falha(s)\n";
        exit(1);
    }
    $admin = null;
    $banco = '';
    $saida = '';
    $fim = 1;
    try {
        if (getenv('FIN_REGRAS_DRIVER') === 'mysql') {
            $host = getenv('FIN_REGRAS_DB_HOST') ?: '127.0.0.1';
            $usuario = getenv('FIN_REGRAS_DB_USER') ?: 'root';
            $senha = (string) getenv('FIN_REGRAS_DB_PASS');
            $admin = new PDO('mysql:host=' . $host . ';charset=utf8mb4', $usuario, $senha, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $banco = 'financeiro_regras_' . bin2hex(random_bytes(4));
            $admin->exec("CREATE DATABASE `{$banco}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
            $admin->exec("USE `{$banco}`");
            // o schema.sql, um comando por vez: ele não tem gatilho nem procedimento, então não usa DELIMITER
            foreach (preg_split('/;\s*\n/', (string) file_get_contents(__DIR__ . '/../database/schema.sql')) ?: [] as $comando) {
                if (trim($comando) !== '') {
                    $admin->exec($comando);
                }
            }
            file_put_contents($pasta . '/config.env', implode("\n", ['APP_ENV=production', 'DB_DRIVER=mysql', 'DB_HOST=' . $host, 'DB_NAME=' . $banco,
                'DB_USER=' . $usuario, 'DB_PASS=' . $senha, 'FIN_MODO=instalador', 'SESSION_LIFETIME=28800', 'MOTOR_V2=1', 'ACESSO_REDE=0', 'CONFIAR_PROXY=0']) . "\n");
        }
        $env = array_filter(getenv(), 'is_string');
        $env['FIN_DADOS'] = $env['FIN_REGRAS_PASTA'] = $pasta;
        $env['FIN_RELOGIO'] = $borda ? RELOGIO_BORDA : RELOGIO;
        $proc = proc_open([...\Financeiro\Atualizacao::comandoPhp(), __FILE__, ...($borda ? ['--borda'] : [])],
            [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $tubos, \Financeiro\Atualizacao::pastaDoProcesso(dirname(__DIR__)), $env);
        if (is_resource($proc)) {
            while (($linha = fgets($tubos[1])) !== false) {
                echo $linha;
                $saida .= $linha;
            }
            fclose($tubos[1]);
            $fim = proc_close($proc);
        }
    } catch (\Throwable $e) {
        $saida .= 'FALHA não deu para preparar o banco das regras — ' . $e->getMessage() . "\n";
        echo $saida;
    } finally {
        if ($admin !== null && $banco !== '') {
            $admin->exec("DROP DATABASE IF EXISTS `{$banco}`");
        }
        \Financeiro\Atualizacao::removerPasta($pasta);
    }
    // o filho morreu antes do resumo: conta o que deu para ver e mais uma falha
    if (!preg_match('/^\d+ ok, \d+ falha\(s\)$/', trim((string) strrchr("\n" . rtrim($saida), "\n")))) {
        $okVistos = preg_match_all('/^ok /m', $saida);
        $falhasVistas = preg_match_all('/^FALHA /m', $saida);
        echo "FALHA as regras pararam antes do fim (saída {$fim})\n\n{$okVistos} ok, " . ($falhasVistas + 1) . " falha(s)\n";
        exit(1);
    }
    exit($fim === 0 ? 0 : 1);
}

// ---- o filho: o banco de demonstração nasce aqui, com o relógio já parado
if (getenv('FIN_DADOS') !== getenv('FIN_REGRAS_PASTA') || !str_starts_with(basename((string) getenv('FIN_REGRAS_PASTA')), 'financeiro-regras-')) {
    echo "Rode por tests/regras.php.\n";
    exit(1);
}
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    // @ continua calando, e a linha de comando do FrankenPHP começa com os cabeçalhos enviados
    if (!(error_reporting() & $no) || (headers_sent() && str_contains($str, 'headers already sent'))) {
        return true;
    }
    throw new \ErrorException($str, 0, $no, $file, $line);
});

$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['HTTPS']          = 'on';

define('FIN_SEM_DADOS_INICIAIS', true);   // a primeira execução só cria as tabelas: a demonstração vem logo abaixo
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/conferencia.php';

use Financeiro\Auth;
use Financeiro\Config;
use Financeiro\Controllers\AjustesController;
use Financeiro\Controllers\CartaoController;
use Financeiro\Controllers\CategoriaController;
use Financeiro\Controllers\DashboardController;
use Financeiro\Controllers\EmprestimoController;
use Financeiro\Controllers\WishlistController;
use Financeiro\Controllers\TemaController;
use Financeiro\Controllers\InvestimentosController;
use Financeiro\Services\Investimentos;
use Financeiro\Temas;
use Financeiro\Pdf\Documento;
use Financeiro\Services\FotoPlano;
use Financeiro\Services\RelatorioDados;
use Financeiro\Controllers\RelatorioController;
use Financeiro\Services\Horizonte;
use Financeiro\Site;
use Financeiro\SiteIcone;
use Financeiro\Sql;
use Financeiro\Idioma;
use Financeiro\Preparo;
use Financeiro\Relogio;
use Financeiro\Controllers\FuturoController;
use Financeiro\Controllers\LancamentosController;
use Financeiro\Controllers\MesController;
use Financeiro\Controllers\MetaController;
use Financeiro\Database;
use Financeiro\Icones;
use Financeiro\Models\AjusteModel;
use Financeiro\Models\CartaoModel;
use Financeiro\Models\CategoriaModel;
use Financeiro\Models\ContaRecorrenteModel;
use Financeiro\Models\LancamentoModel;
use Financeiro\Models\MesModel;
use Financeiro\Models\MetaModel;
use Financeiro\Models\PrevisaoModel;
use Financeiro\Models\UsuarioModel;
use Financeiro\Services\EmprestimoSimulacao;
use Financeiro\Services\Cotacao;
use Financeiro\Services\LinkProduto;
use Financeiro\Services\WishlistPrevisao;
use Financeiro\Models\WishlistModel;
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

// a demonstração de database/instalar.php --demo, num banco que acabou de nascer (nunca num que já tem dados)
$pdo = Database::connection();
if ((int) $pdo->query('SELECT COUNT(*) FROM categorias')->fetchColumn() > 0) {
    echo "O banco das regras precisa nascer vazio.\n";
    exit(1);
}
Preparo::preparar($pdo, true);
MotorFinanceiro::limparCache();

// ---- sessão simulada (primeiro usuário), em português e em reais
$uid = (int) $pdo->query('SELECT MIN(id) FROM usuarios')->fetchColumn();
$_SESSION['usuario_id']    = $uid;
$_SESSION['usuario_nome']  = 'teste';
$_SESSION['sessao_versao'] = (int) UsuarioModel::sessaoVersao($uid);
Idioma::usar('pt');
Site::usarMoeda('BRL');

// o relógio do app e o do banco estão parados na hora das regras
$hoje = Relogio::hoje();
$mesHoje = Relogio::mes();
$relogio = $borda ? RELOGIO_BORDA : RELOGIO;
$check('relógio: o app e o banco param na hora das regras', Relogio::agora()->format('Y-m-d H:i:s') === $relogio
    && (string) $pdo->query('SELECT ' . Sql::agora())->fetchColumn() === $relogio && (string) $pdo->query('SELECT ' . Sql::hoje())->fetchColumn() === substr($relogio, 0, 10));

$pagina = static function (string $cls, string $metodo, string $path): string {
    $_SERVER['REQUEST_URI'] = $path;
    $_GET = [];
    ob_start();
    (new $cls())->$metodo();
    return (string) ob_get_clean();
};

// ==== primeira parte: o que olha a data de hoje (telas, meses, previsão, fechar, cartão). Roda nas duas passadas.

// ---- 3b. textos da auditoria: rótulos que não enganam
$htmlIni = $pagina(DashboardController::class, 'index', '/');
$htmlAno = $pagina(FuturoController::class, 'index', '/futuro');
$htmlMeta = $pagina(MetaController::class, 'index', '/meta');
$check('Metas não chama o disponível de renda', !str_contains($htmlMeta, 'Renda do mês') && str_contains($htmlMeta, 'Para usar neste mês'));
$check('Anual não diz "acima da reserva em todos os meses"', !str_contains($htmlAno, 'acima da reserva em todos'));
$check('Início sem o selo "Acima da reserva"', !str_contains($htmlIni, 'Acima da reserva'));

// ---- 3d. investimentos: vêm dos lançamentos e não são renda nem gasto
$catAplic = $pdo->query("SELECT id, operacional FROM categorias WHERE nome = 'Aplicar em investimentos'")->fetch(PDO::FETCH_ASSOC);
$check('investimentos: a categoria de aplicar existe e não é renda nem gasto', $catAplic !== false && (int) $catAplic['operacional'] === 0);
MotorFinanceiro::limparCache();
$antesInv = Investimentos::resumo($hoje);
$linhaAntes = MotorFinanceiro::linhaDoMes($mesHoje);
$saldoAntes = LancamentoModel::saldoCalculado(AjusteModel::valor('saldo_ini'), $hoje);
$pdo->beginTransaction();
$pdo->prepare('INSERT INTO lancamentos (data, descricao, categoria_id, valor, usuario_id) VALUES (?, ?, ?, 700, ?)')->execute([$hoje, 'teste', (int) $catAplic['id'], $uid]);
MotorFinanceiro::limparCache();
$depoisInv = Investimentos::resumo($hoje);
$linhaDepois = MotorFinanceiro::linhaDoMes($mesHoje);
$saldoDepois = LancamentoModel::saldoCalculado(AjusteModel::valor('saldo_ini'), $hoje);
$htmlInv = $pagina(InvestimentosController::class, 'index', '/investimentos');
$pdo->rollBack();
MotorFinanceiro::limparCache();
$check('investimentos: o lançamento soma em outros investimentos e no total', abs($depoisInv['outros']['saldo'] - $antesInv['outros']['saldo'] - 700) < 0.01 && abs($depoisInv['total'] - $antesInv['total'] - 700) < 0.01 && abs($depoisInv['poupanca']['saldo'] - $antesInv['poupanca']['saldo']) < 0.01);
$check('investimentos: o dinheiro sai da conta', abs($saldoAntes - $saldoDepois - 700) < 0.01);
$check('investimentos: não muda entradas nem gastos do plano', abs($linhaDepois['entra'] - $linhaAntes['entra']) < 0.01 && abs($linhaDepois['sai'] - $linhaAntes['sai']) < 0.01);
$check('investimentos: o plano registra a aplicação', abs($linhaDepois['invest_liquido'] - $linhaAntes['invest_liquido'] - 700) < 0.01 && abs($linhaDepois['invest_saldo'] - $linhaAntes['invest_saldo'] - 700) < 0.01);
// o plano adianta menos o empréstimo quando há menos dinheiro, então a conferência é direta na fórmula do balanço
[, $saiuSem] = MotorFinanceiro::balancoTotal(['invest_liquido' => 0.0] + $linhaAntes);
[, $saiuCom] = MotorFinanceiro::balancoTotal(['invest_liquido' => 700.0] + $linhaAntes);
$check('investimentos: entra no balanço', abs($saiuCom - $saiuSem - 700) < 0.01);
$check('investimentos: a tela mostra as duas partes', str_contains($htmlInv, 'Outros investimentos') && str_contains($htmlInv, 'Guardado e investido') && str_contains($htmlInv, 'teste'));

// ---- 3f. meses sem fim, início em data real e recursos opcionais
$pdo->beginTransaction();
$longe = date('Y-m-d', strtotime($mesHoje . ' +10 year'));
$ultAntes = (string) MesModel::ultimo();
$catFixo = (int) $pdo->query("SELECT id FROM categorias WHERE grupo = 'Fixos' AND ativo = 1 ORDER BY id LIMIT 1")->fetchColumn();
$catAno = (int) $pdo->query("SELECT id FROM categorias WHERE grupo = 'Ano' AND ativo = 1 ORDER BY id LIMIT 1")->fetchColumn();
PrevisaoModel::setValor($catFixo, $ultAntes, 123.45);
$mesAnoT = date('Y-m-01', strtotime($ultAntes . ' -6 month'));
PrevisaoModel::setValor($catAno, $mesAnoT, 777.00);
$tempoIni = microtime(true);
Horizonte::garantir($longe);
$tempoHorizonte = microtime(true) - $tempoIni;
$alvoT = date('Y-m-01', strtotime(date('Y-m-01', strtotime($longe)) . ' +' . Horizonte::MESES_A_FRENTE . ' month'));
$check('meses: o horizonte anda sozinho e não deixa buracos', MesModel::ultimo() === $alvoT && (int) $pdo->query('SELECT COUNT(*) FROM meses')->fetchColumn() === (static function () use ($pdo): int {
    [$a, $b] = array_values($pdo->query('SELECT MIN(mes) AS a, MAX(mes) AS b FROM meses')->fetch());
    return ((int) substr((string) $b, 0, 4) - (int) substr((string) $a, 0, 4)) * 12 + (int) substr((string) $b, 5, 2) - (int) substr((string) $a, 5, 2) + 1;
})());
$check('meses: conta fixa continua nos meses novos', abs(PrevisaoModel::valor($catFixo, date('Y-m-01', strtotime($ultAntes . ' +1 month'))) - 123.45) < 0.01 && abs(PrevisaoModel::valor($catFixo, $alvoT) - 123.45) < 0.01);
$check('meses: conta pontual repete no mesmo mês do ano seguinte, e só nele', abs(PrevisaoModel::valor($catAno, date('Y-m-01', strtotime($mesAnoT . ' +1 year'))) - 777) < 0.01 && PrevisaoModel::valor($catAno, date('Y-m-01', strtotime($mesAnoT . ' +13 month'))) < 0.01);
Horizonte::garantir($longe);
$check('meses: rodar de novo não muda nada', MesModel::ultimo() === $alvoT);
MotorFinanceiro::limparCache();
$tempoIni = microtime(true);
$planoLongo = MotorFinanceiro::calcularPlano();
$tempoPlano = microtime(true) - $tempoIni;
$check('meses: o plano com mais de 10 anos continua rápido', $planoLongo !== [] && $tempoPlano < 1.0, sprintf('%.3f s em %d meses', $tempoPlano, count($planoLongo)));
$check('meses: lançamento num mês novo é aceito', LancamentosController::validarData(date('Y-m-d', strtotime($longe))) === null);
$pdo->rollBack();
MotorFinanceiro::limparCache();

$iniT = (string) AjusteModel::dataInicio();
$check('início: é uma data de verdade', $iniT !== '' && $iniT <= $hoje && MesModel::primeiroDia() === $iniT);
$check('início: lançamento antes dele é recusado', LancamentosController::validarData(date('Y-m-d', strtotime($iniT . ' -1 day'))) !== null);
$check('início: não pode ser no futuro', Horizonte::problemaNaData(date('Y-m-d', strtotime($hoje . ' +1 day'))) !== null);
$check('início: não passa por cima de lançamentos', Horizonte::problemaNaData($hoje) !== null || (string) $pdo->query('SELECT MIN(data) FROM lancamentos')->fetchColumn() === $hoje);
$dadosT = MotorFinanceiro::dados();
$mesMetaT = null;
foreach ($dadosT['meses'] as $m) {
    if ($m['situacao'] !== 'REAL' && $m['mes'] > $mesHoje) { $mesMetaT = $m['mes']; break; }
}
$pdo->beginTransaction();
$catMetaT = (int) $pdo->query("SELECT id FROM categorias WHERE grupo = 'Dia a dia' AND ativo = 1 ORDER BY id LIMIT 1")->fetchColumn();
$pdo->prepare('DELETE FROM previsao WHERE categoria_id = ?')->execute([$catMetaT]);
$pdo->prepare('REPLACE INTO metas (categoria_id, valor) VALUES (?, 300)')->execute([$catMetaT]);
AjusteModel::setValor('data_inicio', (float) date('Ymd', strtotime($mesMetaT . ' +14 day')));
MotorFinanceiro::limparCache();
$dadosParcial = MotorFinanceiro::dados();
$fracaoT = (diasNoMes($mesMetaT) - 15 + 1) / diasNoMes($mesMetaT);
$check('início no meio do mês: a meta conta só pelos dias que faltam', abs(($dadosParcial['prev'][$mesMetaT][$catMetaT] ?? 0) - round(300 * $fracaoT, 2)) < 0.01);
$check('início no dia 1: nada muda nos outros meses', abs(($dadosParcial['prev'][date('Y-m-01', strtotime($mesMetaT . ' +1 month'))][$catMetaT] ?? 0) - 300) < 0.01);
$pdo->rollBack();
MotorFinanceiro::limparCache();

$comParcela = $dadosT;
$comParcela['aj']['parcela_mensal'] = 200.0; $comParcela['aj']['n_parcelas'] = 12.0; $comParcela['aj']['juros_mes'] = 0.01; $comParcela['aj']['saldo_devedor'] = 0.0;
$comParcela['loanIni'] = AjusteModel::devedorPor(200.0, 12.0, 0.01);
$noSalario = $comParcela; $noSalario['aj']['parcela_modo'] = 1.0;
$aParte = $comParcela; $aParte['aj']['parcela_modo'] = 2.0;
$planoS = MotorFinanceiro::calcular($noSalario, ['objetivo' => 3]);
$planoP = MotorFinanceiro::calcular($aParte, ['objetivo' => 3]);
$difCaixa = 0.0;
foreach ($planoS as $m => $l) {
    $difCaixa = max($difCaixa, abs($l['fica_na_conta'] - $planoP[$m]['fica_na_conta']), abs($l['falta_pagar'] - $planoP[$m]['falta_pagar']));
}
$linhaP = $planoP[$mesMetaT]; $linhaS = $planoS[$mesMetaT];
$check('parcela à parte: a conta e a dívida ficam iguais, sem mexer no salário', $difCaixa < 0.01 && $linhaP['parcela_descontada'] == 0.0 && $linhaP['parcela_parte'] > 0 && abs($linhaP['entra'] - $linhaS['entra_bruto']) < 0.01 && abs(MotorFinanceiro::balancoTotal($linhaP)[2] - MotorFinanceiro::balancoTotal($linhaS)[2]) < 0.01);
$pdo->beginTransaction();
AjusteModel::setValor('usa_emprestimo', 0.0);
MotorFinanceiro::limparCache();
$semEmp = MotorFinanceiro::calcularPlano();
$check('sem empréstimo: nenhuma dívida nem parcela no plano', max(array_map(static fn($l) => abs($l['falta_pagar']) + $l['parcela_descontada'] + $l['parcela_parte'], $semEmp)) < 0.01);
$check('sem empréstimo: a aba sai do menu', !str_contains($pagina(MetaController::class, 'index', '/meta'), 'href="/emprestimo"'));
AjusteModel::setValor('usa_cartao', 0.0);
$check('sem cartão: a aba sai e o Relatório vai para a barra', (static function (string $h): bool { return !str_contains($h, 'href="/cartao"') && str_contains($h, 'class="tab" href="/relatorio"'); })($pagina(MetaController::class, 'index', '/meta')));
$pdo->rollBack();
MotorFinanceiro::limparCache();

// ---- 3g. relatório: cenários, sem empréstimo, foto a tempo, a tela
$relHoje = RelatorioDados::mensal($mesHoje);
[$cBase, $cGasto, $cRenda] = $relHoje['previsao']['cenarios'];
$check('relatório: cenário pior nunca parece melhor', end($cGasto['linha']) <= end($cBase['linha']) + 0.01 && end($cRenda['linha']) <= end($cBase['linha']) + 0.01);
$pdo->beginTransaction();
AjusteModel::setValor('usa_emprestimo', 0.0);
MotorFinanceiro::limparCache();
$semD = RelatorioDados::mensal($mesHoje);
$check('relatório: sem empréstimo, nada de terminar de pagar', $semD !== null && ($semD['previsao']['cenarios'] === [] || $semD['previsao']['cenarios'][0]['quita'] === null) && $semD['previsao']['quita'] === null && $semD['divida'] === null);
$pdo->rollBack();
MotorFinanceiro::limparCache();
$pdo->beginTransaction();
$pdo->exec('DELETE FROM plano_fotos');
FotoPlano::garantir(substr($mesHoje, 0, 8) . '03');
$fotoCedo = FotoPlano::valida($mesHoje);
$check('foto do plano: a do começo do mês vale', $fotoCedo !== null && isset($fotoCedo['meses'][$mesHoje]['fica']));
$qtd = (int) $pdo->query('SELECT COUNT(*) FROM plano_fotos')->fetchColumn();
FotoPlano::garantir(substr($mesHoje, 0, 8) . '04');
$check('foto do plano: uma só por mês', (int) $pdo->query('SELECT COUNT(*) FROM plano_fotos')->fetchColumn() === $qtd);
$pdo->exec('DELETE FROM plano_fotos');
FotoPlano::garantir(substr($mesHoje, 0, 8) . '20');
$check('foto do plano: tirada tarde não entra no placar', FotoPlano::valida($mesHoje) === null);
$pdo->rollBack();
$nomesR = $pdo->query('SELECT nome, login FROM usuarios')->fetchAll(PDO::FETCH_ASSOC);
$jsonR = json_encode(RelatorioDados::mensal($mesHoje), JSON_UNESCAPED_UNICODE);
$vazou = array_filter($nomesR, static fn($u) => str_contains((string) $jsonR, ' ' . $u['nome'] . ' '));
$check('relatório: não traz nome de quem usa', $vazou === []);
$htmlR = $pagina(RelatorioController::class, 'index', '/relatorio');
$check('relatório: a tela mostra a capa e o botão do PDF', str_contains($htmlR, 'rl-capa') && str_contains($htmlR, '/relatorio/pdf?') && str_contains($htmlR, 'Baixar PDF'));
$_GET = ['tipo' => 'anual'];
ob_start();
(new RelatorioController())->index();
$htmlRA = (string) ob_get_clean();
$_GET = [];
$check('relatório: a escolha anual funciona', str_contains($htmlRA, 'aria-current="page">Anual'));

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
    $check('meta pinta a barra no mês', false, 'a demonstração ficou sem gasto de dia a dia num mês aberto');
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
    $check('previsão efetiva', false, 'a demonstração ficou sem 2 meses em PREVISAO ou sem categoria de dia a dia');
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
        $recusou = false;
        try {
            PrevisaoLote::gravar('manual', [[$catDia, $realMes, 1.0]], $uid);
        } catch (\InvalidArgumentException) {
            $recusou = true;
        }
        $check('PrevisaoLote recusa mês fechado', $recusou);
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
    $check('mês fechado: recusa', str_contains($erroDe(static fn() => PrevisaoEdicao::celulas($d, $catDia, $realMes, 'salvar', 1.0, false)), 'fechado'));
    $check('empréstimo/poupança não se edita na Mês', $erroDe(static fn() => PrevisaoEdicao::celulas($d, $catMov, $m1, 'salvar', 1.0, false)) === 'Escolha uma categoria.');

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
$html = $render(MesController::class, 'index', ['mes' => end($prevMeses)]);
$check('último mês: linhas editáveis + seletor de meses, sem "meses seguintes"', str_contains($html, 'action="/mes/previsao"')
    && str_contains($html, 'class="mes-linha mes-ed"') && str_contains($html, 'data-mes-picker') && !str_contains($html, 'name="propagar"'));
$html = $render(MesController::class, 'index', ['mes' => $prevMeses[0]]);
$check('Mês aberto: editável com "também nos meses seguintes"', str_contains($html, 'action="/mes/previsao"') && str_contains($html, 'name="propagar"'));
$html = $render(MesController::class, 'index', ['mes' => $realMes]);
$check('Mês fechado: só leitura', str_contains($html, 'mes-ros') && !str_contains($html, 'action="/mes/previsao"'));
$check('anual: cada mês leva à aba Mês', str_contains($render(FuturoController::class, 'index', []), 'class="btn-link fu-abrir" href="/mes?mes='));
$check('meta: explica que a meta é a previsão de todo mês', str_contains($render(MetaController::class, 'index', []), 'meta-previsao'));

// ---- 10. fechar congela as metas do mês; mês fechado não segue a meta; reabrir descongela
$mC = $prevMeses[0];
$emTransacao('congelar ao fechar', static function () use ($pdo, $check, $catDia, $mC, $uid, $linhaCrua): void {
    $pdo->prepare('DELETE FROM previsao WHERE categoria_id = :c AND mes = :m')->execute(['c' => $catDia, 'm' => $mC]);
    MetaModel::definir($catDia, 222.0);
    MotorFinanceiro::limparCache();
    // numa conta nova, o primeiro mês é parcial: a meta vale proporcional aos dias que restam
    $metaDoMes = (float) (MotorFinanceiro::dados()['prev'][$mC][$catDia] ?? 222.0);
    $lote = PrevisaoLote::congelar($mC, $uid);
    $check('congelar grava a meta como linha do mês', $lote !== null && abs((float) $linhaCrua($catDia, $mC) - $metaDoMes) < 0.001);
    $ult = PrevisaoLote::ultimoDesfazivel();
    $check('congelamento fica fora do "desfazer última alteração"', $ult === null || $ult['lote'] !== $lote);
    MesModel::setSituacao($mC, 'REAL');
    MetaModel::definir($catDia, 333.0);
    MotorFinanceiro::limparCache();
    $check('mês fechado não muda quando a meta muda', abs(PrevisaoModel::valor($catDia, $mC) - $metaDoMes) < 0.001);
    MesModel::setSituacao($mC, 'PREVISAO');
    PrevisaoLote::descongelar($mC, $uid);
    MotorFinanceiro::limparCache();
    $check('reabrir descongela: o mês volta a seguir a meta', $linhaCrua($catDia, $mC) === null
        && abs(PrevisaoModel::valor($catDia, $mC) - 333.0) < 0.001);
    MesModel::setSituacao($mC, 'REAL');
    MotorFinanceiro::limparCache();
    $check('mês fechado sem linha: a meta não vale', PrevisaoModel::valor($catDia, $mC) == 0.0);
});

// ---- 11. extrato, ajustes e lançar
$htmlEx = $render(LancamentosController::class, 'index', []);
$htmlAjCat = $render(AjustesController::class, 'index', ['aberto' => 'categorias']);
// o extrato abre no mês do calendário, que na demonstração já tem lançamentos
$check('extrato: ícone em cada lançamento, sem o painel de categorias', str_contains($htmlEx, 'class="ci"') && !str_contains($htmlEx, 'action="/categorias"'));
$check('ajustes: categorias, com o ícone dobrado em "Escolher ícone"', str_contains($htmlAjCat, 'id="categorias" open') && str_contains($htmlAjCat, 'action="/categorias"')
    && str_contains($htmlAjCat, 'class="ic-escolher"') && str_contains($htmlAjCat, 'data-ic-sugerir') && str_contains($htmlAjCat, '/icone?volta='));
$check('lançar: atalho para criar categoria', str_contains($render(LancamentosController::class, 'lancarForm', []), 'class="lf-criar-cat" href="/ajustes?aberto=categorias#categorias"'));
$check('ajustes: a conta vem primeiro e o Avançado por último', strpos($htmlAjCat, 'id="senha"') < strpos($htmlAjCat, 'aj-idioma')
    && strpos($htmlAjCat, 'aj-idioma') < strpos($htmlAjCat, 'aj-recursos') && strpos($htmlAjCat, 'aj-recursos') < strpos($htmlAjCat, 'id="parametros"')
    && strpos($htmlAjCat, 'id="parametros"') < strpos($htmlAjCat, 'id="contas"') && strpos($htmlAjCat, 'id="contas"') < strpos($htmlAjCat, 'aj-avancado')
    && strpos($htmlAjCat, 'aj-avancado') < strpos($htmlAjCat, 'id="partida"') && strpos($htmlAjCat, 'id="partida"') < strpos($htmlAjCat, 'id="aparelhos"'));
$check('ajustes: escolher a imagem do site num botão próprio', str_contains($htmlAjCat, 'class="btn btn-ghost aj-arquivo"') && str_contains($htmlAjCat, 'data-arquivo'));
$check('lançar: só o select de categoria, sem atalhos', !str_contains($render(LancamentosController::class, 'lancarForm', []), 'lf-atalho'));

// ---- 13. Anual: o que mudou acompanha a previsão; a tela
// o "o que mudou" sai da previsão gravada no banco a cada requisição: mudar um mês aparece nele e no seguinte
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
$htmlFu = $render(FuturoController::class, 'index', []);
$check('anual: resumo, conclusão no título do gráfico, previsão tracejada e total por ano', str_contains($htmlFu, 'class="card fu-res"')
    && str_contains($htmlFu, 'id="fu-graf-t">A conta ') && str_contains($htmlFu, 'class="fu-linha is-prev"')
    && str_contains($htmlFu, 'class="fu-cols fu-total num"') && !str_contains($htmlFu, 'fu-area'));

// ---- 14. "e se" do Anual: o motor soma o extra fora de mês fechado; a URL vira simulação; nada é gravado
MotorFinanceiro::limparCache();
$planoSemAd = MotorFinanceiro::calcular(MotorFinanceiro::dados(), ['objetivo' => 3]);
$mesesPrevE = array_values(array_filter(array_keys($planoSemAd), static fn($m) => $planoSemAd[$m]['estado'] === 'previsto'));
$mesesReal = array_values(array_filter(array_keys($planoSemAd), static fn($m) => $planoSemAd[$m]['estado'] === 'real'));
[$e1, $e2] = [$mesesPrevE[0], $mesesPrevE[1]];
$extraT = [$e1 => 500.0] + ($mesesReal !== [] ? [$mesesReal[0] => 9999.0] : []);
$comExtra = MotorFinanceiro::calcular(MotorFinanceiro::dados(), ['objetivo' => 3, 'extra' => $extraT]);
$check('e se: +500 num mês sobe a conta 500 nele e nos seguintes (sem adiantar)',
    abs($comExtra[$e1]['fica_na_conta'] - $planoSemAd[$e1]['fica_na_conta'] - 500.0) < 0.01
    && abs($comExtra[$e2]['fica_na_conta'] - $planoSemAd[$e2]['fica_na_conta'] - 500.0) < 0.01);
$check('e se: mês fechado não muda', abs($comExtra[$mesesReal[0]]['fica_na_conta'] - $planoSemAd[$mesesReal[0]]['fica_na_conta']) < 0.01);
$refE = MesModel::referencia($hoje);
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

// ---- 15. cartão: compra, plano, Mês e a barra
$catFat = CartaoModel::categoriaFatura();
$catGasto = (int) $pdo->query("SELECT id FROM categorias WHERE grupo = 'Dia a dia' AND ativo = 1 AND sinal = -1 ORDER BY id LIMIT 1")->fetchColumn();
$emTransacao('cartão alimentando o plano', static function () use ($pdo, $check, $catFat, $catGasto, $render, $hoje): void {
    $pdo->exec('UPDATE cartoes SET ativo = 0');    // o cartão da demonstração sai de cena até o rollback
    $idC = CartaoModel::salvar(null, 'Cartão teste', 25, 5, null);
    $aberta = CartaoFatura::faturaDe($hoje, 25, 5);
    $antes = PrevisaoModel::valor($catFat, $aberta);
    $r = CartaoController::criarCompraDe(['valor' => '1.000,00', 'categoria_id' => (string) $catGasto, 'data' => $hoje,
                                          'parcelas' => '4', 'descricao' => 'TV teste'], $hoje);
    MotorFinanceiro::limparCache();
    $check('cartão: a compra entra no cartão e, desligado do plano, a previsão da fatura não muda', $r['erro'] === null && $r['id'] > 0
        && abs(PrevisaoModel::valor($catFat, $aberta) - $antes) < 0.01 && str_contains($r['aviso'], '4 × 250,00'), json_encode($r));
    $erroCat = CartaoController::criarCompraDe(['valor' => '10', 'categoria_id' => (string) $catFat, 'data' => $hoje, 'parcelas' => '1'], $hoje);
    $check('cartão: a categoria da compra não pode ser a própria fatura', $erroCat['erro'] !== null);
    CartaoModel::setAlimentaPlano($idC, true);
    $lote = CartaoFatura::sincronizarPlano((int) Auth::id(), $hoje);
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
    $antigas = Sql::tabelaExiste($pdo, 'cartao_compras_v1')
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
$emTransacao('sem cartão cadastrado', static function () use ($pdo, $render, $check): void {
    $pdo->exec('UPDATE cartoes SET ativo = 0');
    $check('cartão: sem cartão cadastrado, a aba pede o cadastro', CartaoModel::ativo() === null && str_contains($render(CartaoController::class, 'index', []), 'Cadastre o cartão'));
});
$htmlBar = $render(MesController::class, 'index', []);
preg_match('#<nav class="tabbar".*?</nav>#s', $htmlBar, $mB);
$check('barra do celular: Início, Extrato, +, Mês, Cartão; Metas e Relatório no botão Menu', str_contains($mB[0] ?? '', 'href="/cartao"')
    && !str_contains($mB[0] ?? '', 'href="/meta"') && str_contains($htmlBar, 'class="menu-item" href="/meta"') && str_contains($htmlBar, 'class="lat-i" href="/cartao"'));

// ---- 16. aba Empréstimo: a tela, os dados e o adiantamento
$antesEmp = (int) $pdo->query('SELECT COUNT(*) FROM lancamentos')->fetchColumn();
$htmlEmp = $render(EmprestimoController::class, 'index', []);
$check('empréstimo: a aba abre, com o menu do M e fora da barra', str_contains($htmlEmp, '<h1>Empréstimo</h1>') && str_contains($htmlEmp, 'class="menu-item is-active" href="/emprestimo"')
    && !preg_match('#<nav class="tabbar".*?href="/emprestimo".*?</nav>#s', $htmlEmp));
$htmlErro = $render(EmprestimoController::class, 'index', ['valor' => '12a', 'parcial' => '1']);
$check('empréstimo: valor inválido diz como corrigir', str_contains($htmlErro, 'Valor inválido') && !str_contains($htmlEmp, 'Empréstimo quitado'));
$check('empréstimo: o pedaço do resultado vem sem o layout', !str_contains($htmlErro, '<html'));
$htmlSimE = $render(EmprestimoController::class, 'index', ['valor' => '100', 'mes' => '']);
$check('empréstimo: simular não preenche o Valor pago', str_contains($htmlEmp, 'Simular quitar tudo') && !str_contains($htmlEmp, 'Quitar tudo')
    && preg_match('#id="emp-pago"[^>]*value=""#', $htmlSimE) === 1 && preg_match('#id="emp-consolidar"(?! open)#', $htmlSimE) === 1);
$htmlPagoE = $render(EmprestimoController::class, 'index', ['valor' => '100', 'pago' => '100,00']);
$check('empréstimo: só o "Já paguei" leva o valor', preg_match('#id="emp-pago"[^>]*value="100,00"#', $htmlPagoE) === 1 && str_contains($htmlPagoE, 'id="emp-consolidar" open'));
$htmlOk = $render(EmprestimoController::class, 'index', ['valor' => '100,00']);
$check('empréstimo: simular mostra o resultado e não grava nada', str_contains($htmlOk, 'Falta depois')
    && (int) $pdo->query('SELECT COUNT(*) FROM lancamentos')->fetchColumn() === $antesEmp);
$htmlAj = $render(AjustesController::class, 'index', []);
$check('dados do empréstimo: saíram de Ajustes', !str_contains($htmlAj, 'valores[juros_mes]') && !str_contains($htmlAj, 'valores[parcela_mensal]')
    && !str_contains($htmlAj, 'valores[n_parcelas]') && !str_contains($htmlAj, 'valores[consignado_inicio]') && !str_contains($htmlAj, 'valores[recebido_emprestimo]')
    && str_contains($htmlAj, 'valores[reserva_minima]') && str_contains($htmlAj, 'valores[saldo_ini]') && !str_contains($htmlAj, 'agora ficam'));
$check('dados do empréstimo: estão na aba, mesmo com a dívida quitada', str_contains($htmlEmp, 'action="/emprestimo/dados"') && str_contains($htmlEmp, 'valores[juros_mes]') && str_contains($htmlEmp, 'valores[saldo_devedor]') && str_contains($htmlEmp, 'valores[saldo_devedor_mes]')
    && str_contains($htmlEmp, 'valores[parcela_mensal]') && str_contains($htmlEmp, 'valores[n_parcelas]') && str_contains($htmlEmp, 'valores[consignado_inicio]') && str_contains($htmlEmp, 'valores[recebido_emprestimo]'));
$check('consolidar: valor inválido, data futura e data fora do plano são recusados; o resto passa',
    EmprestimoController::validarAdiantamento('12a', $hoje, $hoje)[0] !== null
    && EmprestimoController::validarAdiantamento('0', $hoje, $hoje)[0] !== null
    && str_contains((string) EmprestimoController::validarAdiantamento('100', date('Y-m-d', strtotime($hoje . ' +1 day')), $hoje)[0], 'depois de hoje')
    && str_contains((string) EmprestimoController::validarAdiantamento('100', '2000-01-01', $hoje)[0], 'Data inválida')
    && EmprestimoController::validarAdiantamento('100', 'ontem', $hoje)[0] !== null
    && EmprestimoController::validarAdiantamento('1.250,50', $hoje, $hoje) === [null, 1250.5]);
$catAmortT = MotorFinanceiro::dados()['esp']['amort'];
$refT = MesModel::referencia($hoje);
$faltaAntesT = EmprestimoSimulacao::dividaNoMes(MotorFinanceiro::dados(), $refT, $hoje);
$pdo->beginTransaction();
try {
    $saldoAntesT = (float) MotorFinanceiro::calcular(MotorFinanceiro::dados(), ['v2' => true, 'objetivo' => 3])[$refT]['fica_na_conta'];
    LancamentoModel::criar($hoje, 'Adiantamento de teste', (int) $catAmortT, 100.0, (int) $pdo->query('SELECT MIN(id) FROM usuarios')->fetchColumn());
    MotorFinanceiro::limparCache();
    $dT = MotorFinanceiro::dados();
    $check('consolidar: o lançamento abate a dívida e sai da conta', abs(EmprestimoSimulacao::dividaNoMes($dT, $refT, $hoje) - $faltaAntesT + 100.0) < 0.01
        && abs((float) MotorFinanceiro::calcular($dT, ['v2' => true, 'objetivo' => 3])[$refT]['fica_na_conta'] - $saldoAntesT + 100.0) < 0.01);
    $htmlT = $render(EmprestimoController::class, 'index', []);
    $htmlEx = $render(LancamentosController::class, 'index', ['mes' => $refT, 'cat' => (string) $catAmortT]);
    $check('consolidar: aparece em Adiantamentos feitos e no Extrato', str_contains($htmlT, 'Adiantamentos feitos') && str_contains($htmlEx, 'Adiantamento de teste'));
    $check('consolidar: com adiantamento lançado, não compara o fim com o contrato', !str_contains($htmlT, 'Pelo contrato'));
} finally {
    $pdo->rollBack();
    MotorFinanceiro::limparCache();
}

// ---- 17. wishlist: itens em transação
$pngT = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
$fotoT = LinkProduto::fotoValida($pngT);
$pdo->beginTransaction();
try {
    $uT = (int) $pdo->query('SELECT MIN(id) FROM usuarios')->fetchColumn();
    // cotação fresca e conhecida: os testes não vão à rede
    $pdo->exec("REPLACE INTO cotacoes (moeda, valor, data, buscado_em) VALUES ('BRL', 1, " . Sql::hoje() . ', ' . Sql::agora() . "), ('USD', 5, '2030-01-02', " . Sql::agora() . ')');
    $pdo->exec("DELETE FROM cotacoes WHERE moeda NOT IN ('BRL', 'USD')");
    $ini = count(WishlistModel::ativos());
    $a = WishlistModel::criar('<b>Item A</b>', 'https://loja.exemplo/a', 1000.0, $uT);
    $b = WishlistModel::criar('Item B', null, null, $uT);
    $c = WishlistModel::criar('Item C', null, 50.0, $uT);
    WishlistModel::salvarFoto($a, $fotoT);
    $check('wishlist: foto volta igual do banco', WishlistModel::foto($a)['dados'] === $pngT && WishlistModel::foto($b) === null);
    WishlistModel::mover($c, 'primeiro');
    $check('wishlist: colocar em 1º', (int) WishlistModel::ativos()[0]['id'] === $c);
    $ordemWl = static fn(): array => array_map(static fn(array $i): int => (int) $i['id'], WishlistModel::ativos());
    WishlistModel::mover($c, 'descer');
    $check('wishlist: descer', array_search($c, $ordemWl(), true) === 1, json_encode($ordemWl()));
    $antesB = array_search($b, $ordemWl(), true);
    WishlistModel::mover($b, 'subir');
    $check('wishlist: subir', array_search($b, $ordemWl(), true) === $antesB - 1, json_encode($ordemWl()));
    WishlistModel::mover($a, 'primeiro');
    $htmlW = $render(WishlistController::class, 'index', []);
    $itemUsd = WishlistModel::criar('Item em dolar', null, 100.0, $uT, 'USD');
    $itemEur = WishlistModel::criar('Item em euro', null, 100.0, $uT, 'EUR');
    WishlistModel::mover($itemUsd, 'primeiro');
    $htmlUsd = $render(WishlistController::class, 'index', []);
    $check('wishlist: fora de real mostra o original, a conversão e a data', str_contains($htmlUsd, 'US$' . NBSP . '100,00') && str_contains($htmlUsd, money(500.0) . ', cotação de 02/01'));
    $check('wishlist: sem cotação avisa em vez de inventar', str_contains($htmlUsd, 'Sem cotação agora'));
    $check('wishlist: a previsão usa o valor em reais', str_contains($htmlUsd, 'O valor em reais muda com a cotação'));
    $check('wishlist: o formulário oferece as moedas', str_contains($htmlUsd, 'name="moeda"') && str_contains($htmlUsd, 'value="EUR"'));
    WishlistModel::mover($itemEur, 'primeiro');
    $check('wishlist: 1º sem cotação não tem previsão', str_contains($render(WishlistController::class, 'index', []), 'Sem cotação de EUR'));
    WishlistModel::excluir($itemUsd);
    WishlistModel::excluir($itemEur);
    WishlistModel::mover($a, 'primeiro');
    $check('wishlist: a aba abre, com o 1º em destaque, previsão e fora da barra', str_contains($htmlW, '<h1>Wishlist</h1>') && str_contains($htmlW, 'wl-vitrine') && str_contains($htmlW, 'Quando comprar')
        && str_contains($htmlW, 'class="menu-item is-active" href="/wishlist"') && !preg_match('#<nav class="tabbar".*?href="/wishlist".*?</nav>#s', $htmlW));
    $check('wishlist: texto da loja é escapado', str_contains($htmlW, '&lt;b&gt;Item A&lt;/b&gt;') && !str_contains($htmlW, '<b>Item A</b>'));
    $check('wishlist: foto não vem como link externo', !preg_match('#<img[^>]+src="https?://#', $htmlW));
    $check('wishlist: a previsão parcial vem sem layout', !str_contains($render(WishlistController::class, 'index', ['parcial' => '1', 'parcelas' => '3']), '<html'));
    WishlistModel::marcarComprado($a, $hoje);
    $check('wishlist: comprado sai da lista e vai para Compradas', count(WishlistModel::ativos()) === $ini + 2 && (int) WishlistModel::comprados()[0]['id'] === $a);
    WishlistModel::devolver($a);
    WishlistModel::excluir($b);
    $check('wishlist: excluído some, e desfazer traz de volta', WishlistModel::find($b) === null);
    WishlistModel::restaurar($b);
    $check('wishlist: restaurado volta com a foto preservada', WishlistModel::find($b) !== null && WishlistModel::foto($a) !== null);
    ob_start();
    (new WishlistController())->editar((string) $a);
    $htmlE = (string) ob_get_clean();
    $check('wishlist: editar abre preenchido', str_contains($htmlE, 'action="/wishlist/' . $a . '"') && str_contains($htmlE, 'loja.exemplo/a'));
} finally {
    $pdo->rollBack();
}

// ---- 21. fechar e Mês: confirmar, nada ligado sozinho, botão à vista
$htmlFe = $render(MesController::class, 'fecharForm', []);
$check('fechar: "Aplicar" vem desligado', preg_match('#name="aplicar" value="1"(?! checked)#', $htmlFe) === 1);
// dia 22, faltam 8 dias; no último dia, nenhum
$faltamFe = (int) date('t', strtotime($hoje)) - (int) date('j', strtotime($hoje));
$check('fechar: abre com a frase e confirma, citando os dias se ainda faltam', str_contains($htmlFe, 'fe-intro')
    && str_contains($htmlFe, 'data-confirm="' . match ($faltamFe) { 0 => 'Fechar ' . monthLabel($mesHoje) . '?', 1 => 'Ainda falta 1 dia', default => "Ainda faltam {$faltamFe} dias" }), (string) $faltamFe);
$htmlMesCal = $render(MesController::class, 'index', ['mes' => $mesHoje]);
$check('Mês: fechar à vista, fora do menu "…"', str_contains($htmlMesCal, 'class="mes-fechar-link"') && !str_contains($htmlMesCal, 'class="menu-item" href="/mes/fechar'));
$emTransacao('início com o mês fechado antes do fim', static function () use ($mesHoje, $render, $check): void {
    MesModel::setSituacao($mesHoje, 'REAL');
    MotorFinanceiro::limparCache();
    $h = $render(DashboardController::class, 'index', []);
    $check('Início: o mês do calendário fechado continua sendo o mês mostrado', str_contains($h, mb_convert_case(mesExtenso($mesHoje), MB_CASE_TITLE) . ' até hoje') && !str_contains($h, ' previsto</h2>'));
    MesModel::setSituacao($mesHoje, 'PREVISAO');
    MotorFinanceiro::limparCache();
});

// ---- 22. primeira configuração: o que usa antes das contas; ajuda do aviso sem login
$passoComecar = static function (int $passo) use ($hoje): string {
    ob_start();
    \Financeiro\View::renderAuth('comecar/index', ['passo' => $passo, 'total' => 4, 'hoje' => $hoje, 'inicio' => $hoje, 'contas' => [['id' => 1, 'nome' => 'Luz']], 'usos' => [], 'erro' => null]);
    return (string) ob_get_clean();
};
$check('começar: o 3º passo pergunta o que usa, e a parcela só com empréstimo', str_contains($passoComecar(3), 'name="usa_cartao"') && str_contains($passoComecar(3), 'cm-grupo cm-so-emp') && !str_contains($passoComecar(3), 'salário'));
$check('começar: o 4º passo pede as contas fixas', str_contains($passoComecar(4), 'name="valor[1]"') && !str_contains($passoComecar(4), 'name="usa_cartao"'));
ob_start();
(new \Financeiro\Controllers\AjudaController())->conexao();
$htmlCon = (string) ob_get_clean();
$check('ajuda: o aviso de conexão abre sem o layout', str_contains($htmlCon, 'href="/login"') && !str_contains($htmlCon, 'class="tabbar"'));

// ---- 24. fuso: a conexão anda junto
$moedaAntes = Site::moeda();
$fusoAntes = Site::fuso();
// o relógio do banco, em minutos em relação ao UTC: a hora que ele mostra, contra o instante do relógio do app
$minutosDoFuso = static fn(): int => (int) round(((new DateTimeImmutable((string) $pdo->query('SELECT ' . Sql::agora())->fetchColumn(), new DateTimeZone('UTC')))->getTimestamp()
    - Relogio::agora()->getTimestamp()) / 60);
$pdo->beginTransaction();
$pdo->exec("DELETE FROM ajustes WHERE chave IN ('moeda', 'fuso')");
Site::carregar($pdo);
$check('sem os ajustes novos: real e Brasília', Site::moeda() === 'BRL' && Site::fuso() === 'America/Sao_Paulo' && date_default_timezone_get() === 'America/Sao_Paulo');
$check('sem os ajustes novos: a conexão fica em Brasília', $minutosDoFuso() === -180);
$check('sem os ajustes novos: o dinheiro sai igual', money(1234.56) === 'R$' . NBSP . '1.234,56');
foreach (['en', 'es'] as $idT) {
    Idioma::usar($idT);
    $check("conta em {$idT} sem os ajustes novos: real e Brasília", moedaSimbolo() === 'R$' && Site::fuso() === 'America/Sao_Paulo');
}
Idioma::usar('pt');
Site::salvarMoeda('EUR');
Site::salvarFuso('Asia/Kolkata');
Site::carregar($pdo);
$check('com os ajustes: moeda e fuso voltam do banco', Site::moeda() === 'EUR' && Site::fuso() === 'Asia/Kolkata' && date_default_timezone_get() === 'Asia/Kolkata');
$check('com os ajustes: a conexão anda junto', $minutosDoFuso() === 330);
Site::salvarFuso('America/New_York');
Site::carregar($pdo);
$check('com os ajustes: horário de verão na conexão', $minutosDoFuso() === (int) ((new DateTimeZone('America/New_York'))->getOffset(Relogio::agora()) / 60));
$pdo->rollBack();
Site::carregar($pdo);
Site::usarMoeda('BRL');
$check('a moeda e o fuso voltam ao que eram', Site::fuso() === $fusoAntes && $moedaAntes !== '');

// a tela em en e es não mostra categoria de fábrica em português (o formulário de lançar fica guardado desde o 1º uso nesta requisição; ele é conferido no navegador)
foreach (['en', 'es'] as $idT) {
    Idioma::usar($idT);
    foreach ([[DashboardController::class, '/'], [MesController::class, '/mes'], [MetaController::class, '/meta'], [AjustesController::class, '/ajustes']] as [$clsT, $pathT]) {
        $tela = strip_tags((string) preg_replace('/<(script|style|template|datalist|dialog)\b.*?<\/\1>/s', '', $pagina($clsT, 'index', $pathT)));
        $vistas = array_filter(['Aluguel e condomínio', 'Seguro do carro', 'Licenciamento do carro', 'Imposto de renda', 'Combustível', 'Mercado'], static fn(string $n): bool => str_contains($tela, $n));
        $check("telas em {$idT}: categorias traduzidas em {$pathT}", $vistas === [], $vistas === [] ? '' : mb_substr($tela, max(0, (int) mb_strpos($tela, (string) reset($vistas)) - 80), 200));
    }
}
Idioma::usar('pt');

// "Tenho carro" e a fatura fora das contas fixas
$pdo->beginTransaction();
$ativoDe = static fn(string $n): int => (int) (CategoriaModel::porNome($n)['ativo'] ?? -1);
$usoId = (int) $pdo->query("SELECT id FROM categorias WHERE nome = 'Combustível'")->fetchColumn();
$pdo->prepare('UPDATE categorias SET ativo = 1 WHERE id = ?')->execute([$usoId]);    // conta nova sem carro: está desligada
$pdo->prepare('INSERT INTO previsao (categoria_id, mes, valor) VALUES (?, ?, 1) ' . Sql::aoRepetir('categoria_id, mes', ['valor' => '1']))->execute([$usoId, $mesHoje]);
$idRev = (int) $pdo->query("SELECT id FROM categorias WHERE nome = 'Revisão do carro'")->fetchColumn();
$pdo->prepare('DELETE FROM previsao WHERE categoria_id = ?')->execute([$idRev]);
$pdo->prepare('DELETE FROM contas_recorrentes WHERE categoria_id = ?')->execute([$idRev]);
CategoriaModel::marcarCarro(false);
$check('sem carro: a categoria em uso fica', $ativoDe('Combustível') === 1);
$check('sem carro: a categoria sem uso sai das listas, sem apagar', $ativoDe('Revisão do carro') === 0);
CategoriaModel::marcarCarro(true);
$check('com carro: as categorias voltam', $ativoDe('Revisão do carro') === 1 && $ativoDe('Combustível') === 1);
AjusteModel::setValor('usa_cartao', 0.0);
$check('sem cartão: a fatura não é oferecida nas contas fixas', array_filter(ContaRecorrenteModel::categoriasElegiveis(), static fn(array $c): bool => $c['grupo'] === 'Cartao') === []);
$check('sem cartão: a fatura sai das contas fixas', array_filter(ContaRecorrenteModel::ativas(), static fn(array $c): bool => $c['grupo'] === 'Cartao') === []);
AjusteModel::setValor('usa_cartao', 1.0);
$check('com cartão: a fatura é oferecida', array_filter(ContaRecorrenteModel::categoriasElegiveis(), static fn(array $c): bool => $c['grupo'] === 'Cartao') !== []);
$pdo->rollBack();

// ---- os dois bancos se comportam igual: acento, data, dinheiro e chaves
$pdo->beginTransaction();
try {
    $catB = (int) $pdo->query("SELECT id FROM categorias WHERE grupo = 'Dia a dia' AND ativo = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $insB = $pdo->prepare('INSERT INTO lancamentos (data, descricao, categoria_id, valor, usuario_id) VALUES (?, ?, ?, ?, ?)');
    $recusa = static function (array $linha) use ($insB): bool {
        try {
            $insB->execute($linha);
            return false;
        } catch (\PDOException) {
            return true;
        }
    };
    $insB->execute([$hoje, 'Café da manhã 100%', $catB, 12.30, $uid]);
    $idB = (int) $pdo->lastInsertId();
    $achou = static fn(string $q): bool => in_array($idB, array_map(static fn(array $l): int => (int) $l['id'], LancamentoModel::listar($mesHoje, ['q' => $q])), true);
    $check('busca: sem acento acha com acento', $achou('cafe'));
    $check('busca: sem caixa', $achou('CAFÉ DA'));
    $check('busca: com acento acha igual', $achou('manha'));
    $check('busca: % é literal', $achou('100%') && !$achou('10%0'));
    $check('busca: palavra que não existe', !$achou('cafeteria'));
    $st = $pdo->prepare('SELECT COUNT(*) FROM lancamentos WHERE id = ? AND descricao = ?');
    $st->execute([$idB, 'CAFE DA MANHA 100%']);
    $check('coluna de texto: igual sem acento e sem caixa', (int) $st->fetchColumn() === 1);
    $check('data inválida é recusada', $recusa(['2026-02-31', 'x', $catB, 1, $uid]) && $recusa(['nao-data', 'x', $catB, 1, $uid]));
    $check('valor que não é número é recusado', $recusa([$hoje, 'x', $catB, 'abc', $uid]));
    $check('valor zero ou negativo é recusado', $recusa([$hoje, 'x', $catB, 0, $uid]) && $recusa([$hoje, 'x', $catB, -1, $uid]));
    $check('texto maior que a coluna é recusado', $recusa([$hoje, str_repeat('x', 256), $catB, 1, $uid]));
    $check('chave estrangeira vale', $recusa([$hoje, 'x', 99999999, 1, $uid]));
    $lido = $pdo->prepare('SELECT valor FROM lancamentos WHERE id = ?');
    $centavos = static function (float|string $v) use ($insB, $pdo, $hoje, $catB, $uid, $lido): string {
        $insB->execute([$hoje, 'arredonda', $catB, $v, $uid]);
        $lido->execute([(int) $pdo->lastInsertId()]);
        return number_format((float) $lido->fetchColumn(), 2, '.', '');
    };
    $check('dinheiro: arredonda como DECIMAL', $centavos('1.005') === '1.01' && $centavos('2.675') === '2.68' && $centavos('0.125') === '0.13' && $centavos('10.994') === '10.99' && $centavos('999.995') === '1000.00', $centavos('1.005'));
    $check('dinheiro: valor em float', $centavos(1.005) === '1.01' && $centavos(33.335) === '33.34');
    $check('dinheiro: valor muito pequeno vira zero e é recusado', $recusa([$hoje, 'x', $catB, '0.001', $uid]));
    $pdo->prepare('DELETE FROM lancamentos WHERE data = ? AND categoria_id = ?')->execute(['2031-05-10', $catB]);
    foreach (['0.10', '0.20', '0.70', '1000000.01', '0.02'] as $vB) {
        $insB->execute(['2031-05-10', 'soma', $catB, $vB, $uid]);
    }
    $somaB = $pdo->prepare('SELECT SUM(valor) FROM lancamentos WHERE data = ? AND categoria_id = ?');
    $somaB->execute(['2031-05-10', $catB]);
    $check('dinheiro: soma exata no centavo', (float) $somaB->fetchColumn() === 1000001.03);
    $mesFmt = $pdo->prepare('SELECT ' . Sql::inicioMes('data') . ' AS m, ' . Sql::diaDoMes('data') . ' AS d, ' . Sql::mesSeguinte('data') . ' AS s FROM lancamentos WHERE data = ? LIMIT 1');
    $mesFmt->execute(['2031-05-10']);
    $linhaFmt = $mesFmt->fetch();
    $check('datas no SQL: início do mês, dia e mês seguinte', $linhaFmt['m'] === '2031-05-01' && (int) $linhaFmt['d'] === 10 && $linhaFmt['s'] === '2031-06-10');
    $check('ORDER BY posição na lista', (int) $pdo->query('SELECT ' . Sql::posicao("'b'", ['a', 'b']) . ' AS p')->fetchColumn() === 2);
    $check('agora e agora mais um intervalo', (int) $pdo->query('SELECT ' . Sql::agoraMais(1, 'DAY') . ' > ' . Sql::agora())->fetchColumn() === 1 && (int) $pdo->query('SELECT ' . Sql::agoraMais(-1, 'DAY') . ' < ' . Sql::agora())->fetchColumn() === 1);
    $check('segundos até uma hora marcada', abs((int) $pdo->query('SELECT ' . Sql::segundosAte(Sql::agoraMais(90, 'SECOND')))->fetchColumn() - 90) <= 1);
    $check('maior e menor de dois', (int) $pdo->query('SELECT ' . Sql::maior('2', '5'))->fetchColumn() === 5 && (int) $pdo->query('SELECT ' . Sql::menor('2', '5'))->fetchColumn() === 2);
    $pdo->exec("UPDATE ajustes SET atualizado_em = '2000-01-01 00:00:00' WHERE chave = 'saldo_ini'");
    $pdo->exec("UPDATE ajustes SET label = 'Outro rótulo' WHERE chave = 'saldo_ini'");
    $check('atualizado_em anda quando a linha muda', (string) $pdo->query("SELECT atualizado_em FROM ajustes WHERE chave = 'saldo_ini'")->fetchColumn() !== '2000-01-01 00:00:00');
    $pdo->exec("UPDATE ajustes SET atualizado_em = '2000-01-01 00:00:00' WHERE chave = 'saldo_ini'");
    $pdo->exec("UPDATE ajustes SET label = 'Outro rótulo' WHERE chave = 'saldo_ini'");
    $check('atualizado_em fica quando nada muda', (string) $pdo->query("SELECT atualizado_em FROM ajustes WHERE chave = 'saldo_ini'")->fetchColumn() === '2000-01-01 00:00:00');
    $binario = "\xFF\xD8\x00\x01'\"\\ \xC3\x28" . random_bytes(32);
    SiteIcone::salvar('image/png', $binario);
    $check('imagem: os bytes voltam iguais, com NUL e tudo', SiteIcone::arquivo()['dados'] === $binario);
    $check('trava nomeada: pega e solta', Sql::travar($pdo, 'financeiro_teste', 1));
    Sql::soltar($pdo, 'financeiro_teste');
    $check('tabela existe', Sql::tabelaExiste($pdo, 'lancamentos') && !Sql::tabelaExiste($pdo, 'nao_existe'));
    foreach ($pdo->query('SELECT COUNT(*) AS n FROM categorias') as $linhaB) {
        $pdo->exec("UPDATE ajustes SET confirmado = confirmado WHERE chave = 'nenhuma'");
        $check('ler e gravar na mesma volta do laço', (int) $linhaB['n'] > 0);
    }
} finally {
    $pdo->rollBack();
}

// ---- mês sem ?mes= depois de fechar antes da hora, e reabrir com o ajuste do mês seguinte
$mesesT = array_column(MesModel::todos(), 'situacao', 'mes');
$proxT = date('Y-m-01', strtotime($mesHoje . ' +1 month'));
$pdo->beginTransaction();
try {
    MesModel::setSituacao($mesHoje, 'REAL');
    MotorFinanceiro::limparCache();
    $htmlFechadoCedo = $render(MesController::class, 'index', []);
    preg_match('#<title>(.*?)</title>#s', $htmlFechadoCedo, $tituloT);
    $check('Mês sem ?mes=: fica no mês do calendário, mesmo fechado', str_contains(html_entity_decode($tituloT[1] ?? ''), monthLabel($mesHoje)), $tituloT[1] ?? '');
    $catT = (int) $pdo->query("SELECT id FROM categorias WHERE grupo = 'Dia a dia' ORDER BY id LIMIT 1")->fetchColumn();
    $uidT = (int) $pdo->query('SELECT MIN(id) FROM usuarios')->fetchColumn();
    $stValorT = $pdo->prepare('SELECT valor FROM previsao WHERE categoria_id = :c AND mes = :m');
    $stValorT->execute(['c' => $catT, 'm' => $proxT]);
    $antesT = $stValorT->fetchColumn();
    PrevisaoLote::gravar('fechar', [[$catT, $proxT, 1234.56]], $uidT);
    MesModel::setSituacao($mesHoje, 'PREVISAO');
    $rT = PrevisaoLote::desfazerAjusteDoFechamento($mesHoje, $uidT);
    $stValorT->execute(['c' => $catT, 'm' => $proxT]);
    $check('reabrir: desfaz o ajuste do mês seguinte', $rT !== null && $rT['mes'] === $proxT && $rT['restauradas'] === 1 && $stValorT->fetchColumn() === $antesT);
    $check('reabrir de novo: nada mais a desfazer', PrevisaoLote::desfazerAjusteDoFechamento($mesHoje, $uidT) === null);
} finally {
    $pdo->rollBack();
    MotorFinanceiro::limparCache();
}

// ==== no último dia do mês, só a primeira parte, mais a conferência do site sobre a demonstração
if ($borda) {
    conferencia($check);
    echo "\n{$ok} ok, {$falhas} falha(s)\n";
    exit($falhas > 0 ? 1 : 0);
}

// ==== segunda parte: o que não muda com o dia (contas com dados de mentira, números, textos e o próprio código)

// ---- 3c. idiomas: o português é a chave
Idioma::usar('pt');
$check('idiomas: português é o texto original', t('Em conta hoje') === 'Em conta hoje' && t('Ver {n}', ['n' => 2]) === 'Ver 2' && tn(1, '1 mês', '{n} meses') === '1 mês' && tn(3, '1 mês', '{n} meses') === '3 meses');
$check('idiomas: texto sem tradução continua em português', (static function (): bool { Idioma::usar('en'); $r = t('texto que não existe'); Idioma::usar('pt'); return $r === 'texto que não existe'; })());
$check('idiomas: o navegador escolhe só o que existe', Idioma::doNavegador('en-US,en;q=0.9') === 'en' && Idioma::doNavegador('fr-FR,es;q=0.8') === 'es' && Idioma::doNavegador('fr') === 'pt' && Idioma::doNavegador('') === 'pt');

// ---- 3e. ícone do site: padrão neutro, opção em Ajustes, nada individual no código
$pdo->beginTransaction();
SiteIcone::remover();
$check('ícone: sem escolha, vale a imagem padrão', str_contains(SiteIcone::url(), SiteIcone::PADRAO) && is_file(__DIR__ . '/../public' . SiteIcone::PADRAO));
$htmlAjI = $pagina(AjustesController::class, 'index', '/ajustes');
$check('ícone: Ajustes oferece a troca', str_contains($htmlAjI, 'action="/ajustes/icone"') && str_contains($htmlAjI, 'type="file" name="icone"') && !str_contains($htmlAjI, '/ajustes/icone/remover'));
$pngT = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M/wHwAEBgIApD5fRAAAAABJRU5ErkJggg==');
SiteIcone::salvar('image/png', $pngT);
$check('ícone: o enviado passa a valer no endereço e pode ser desfeito', SiteIcone::url() === '/icone?v=' . sha1($pngT) && (SiteIcone::arquivo()['dados'] ?? '') === $pngT
    && str_contains($pagina(AjustesController::class, 'index', '/ajustes'), '/ajustes/icone/remover'));
SiteIcone::remover();
$pdo->rollBack();
$check('ícone: arquivo que não é imagem é recusado', LinkProduto::fotoValida('<?php echo 1;') === null);
$imagensApp = array_values(array_diff(scandir(__DIR__ . '/../public/assets/img') ?: [], ['.', '..']));
$check('ícone: nenhuma imagem individual no código, só as do app', $imagensApp === ['icone-180.png', 'icone-32.png', 'icone-64.png', 'icones.webp', 'linhas-cartao.svg', 'linhas-fundo.svg', 'marca.svg'], implode(', ', $imagensApp));

// ---- 3g. relatório: o PDF
$docT = new Documento('Título ação', 'pt-BR', '20261122153000');
$docT->novaPagina();
$docT->texto(40, 60, 'Ação ñ € −1,00 🍕 日本', 'manrope-500', 12);
$docT->texto(40, 80, 'Sora', 'sora-600', 12, [0, 0, 0], 'd');
$docT->retangulo(40, 90, 100, 20, [0.5, 0.5, 0.5], [0, 0, 0], 4);
$docT->hachura(40, 120, 50, 20, [0, 0, 0]);
$pngA = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M/wHwAEBgIApD5fRAAAAABJRU5ErkJggg==');
$check('pdf: PNG com transparência entra sem GD', $docT->imagem($pngA, 40, 150, 10, 10));
$check('pdf: formato que não dá para embutir fica de fora', !$docT->imagem('nao e imagem', 40, 150, 10, 10));
$bytesT = $docT->saida();
$xrefOk = false;
if (preg_match('/startxref\n(\d+)\n%%EOF\n$/', $bytesT, $mx) && substr($bytesT, (int) $mx[1], 4) === 'xref') {
    $xrefOk = true;
    $linhasX = explode("\n", substr($bytesT, (int) $mx[1]));
    [, $totalX] = explode(' ', $linhasX[1]);
    for ($iX = 1; $iX < (int) $totalX; $iX++) {
        $xrefOk = $xrefOk && substr($bytesT, (int) substr($linhasX[2 + $iX], 0, 10), strlen($iX . ' 0 obj')) === $iX . ' 0 obj';
    }
}
$check('pdf: cabeçalho, xref e objetos nos lugares certos', str_starts_with($bytesT, '%PDF-1.5') && $xrefOk);
$check('pdf: título e idioma declarados', str_contains($bytesT, '/Title <FEFF') && str_contains($bytesT, '/Lang <FEFF') && str_contains($bytesT, '/DisplayDocTitle true'));
$check('pdf: fontes embutidas com ToUnicode', substr_count($bytesT, '/FontFile2') === 2 && substr_count($bytesT, '/ToUnicode') === 2);
$check('pdf: quebra de linha respeita a largura', max(array_map(static fn($l) => $docT->medir($l, 'manrope-500', 10), $docT->quebrar('um texto bem longo com umapalavraenormequenaocabedeformanenhumanalinha e mais', 'manrope-500', 10, 80))) <= 80.01);

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
             'Imposto de renda' => 'leao', 'Gastos mesada filho' => 'nota', 'Seguro do carro' => 'carro',
             'Licenciamento do carro' => 'documento', 'Ração do pet' => 'cachorro', 'Barbeiro' => 'barbearia'];
$erradas = [];
foreach ($esperado as $nomeCat => $k) {
    if (Icones::sugerir($nomeCat, 'Dia a dia') !== $k) {
        $erradas[] = $nomeCat . ' → ' . Icones::sugerir($nomeCat, 'Dia a dia');
    }
}
$check('ícones: sugestão pelo nome', $erradas === [], implode('; ', $erradas));
$check('ícones: sem palavra conhecida, vale o ícone do grupo', Icones::sugerir('Xyzw', 'Entradas') === 'moeda'
    && Icones::sugerir('Xyzw', 'Dia a dia') === 'sacola' && Icones::sugerir('Xyzw', '') === 'sacola');
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

// ---- 12. barra do celular: o "+" no meio de 4 abas; o Anual abre pelo seletor de meses do Mês
$htmlMes = $render(MesController::class, 'index', []);
preg_match('#<nav class="tabbar".*?</nav>#s', $htmlMes, $m);
$barra = $m[0] ?? '';
preg_match_all('#<a class="(tab[^" ]*)#', $barra, $itens);
$check('barra do celular: 2 abas, o +, 2 abas (sem o Relatório)', $itens[1] === ['tab', 'tab', 'tab-add', 'tab', 'tab']
    && !str_contains($barra, 'href="/relatorio"') && str_contains($htmlMes, 'class="lat-i" href="/relatorio"'), implode(',', $itens[1]));
$check('seletor de meses do Mês leva ao Relatório e ao simulador; o do Extrato não', str_contains($htmlMes, 'class="mes-picker-anual" href="/relatorio"') && str_contains($htmlMes, 'class="mes-picker-anual" href="/futuro"')
    && !str_contains($htmlEx, 'mes-picker-anual'));
$check('botão Menu (celular), sem o círculo da inicial, leva ao Relatório e ao Simular; a aba Anual saiu', str_contains($htmlMes, 'class="menu-botao"') && str_contains($htmlMes, 'class="menu-item" href="/relatorio"')
    && str_contains($htmlMes, 'class="menu-item" href="/futuro"') && !str_contains($htmlMes, 'menu-avatar') && !str_contains($htmlMes, '>Anual<'));
$htmlFuAtivo = $render(FuturoController::class, 'index', []);
$check('Simular: fica aceso ele mesmo, no menu lateral e no botão Menu', str_contains($htmlFuAtivo, 'class="lat-i is-active" href="/futuro"') && str_contains($htmlFuAtivo, 'class="menu-item is-active" href="/futuro"')
    && str_contains($htmlFuAtivo, 'class="menu-botao is-active"') && !str_contains($htmlFuAtivo, 'class="tab is-active"') && !str_contains($htmlFuAtivo, 'class="lat-i is-active" href="/relatorio"'));

// ---- 13. Anual: marcos do plano e o que mudou em cada mês (AnualResumo, dados de mentira)
$pl = static fn(string $m, float $fica, bool $quit = false, float $parc = 0.0): array => ['mes' => $m, 'estado' => 'previsto',
    'fica_na_conta' => $fica, 'quitado' => $quit, 'bateu_meta' => false, 'parcela_descontada' => $parc];
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
$check('anual: o que mudou no mês, do maior efeito para o menor', $mu['2027-01-01'] === []
    && ($mu['2027-02-01'][0]['nome'] ?? '') === 'IPVA' && abs($mu['2027-02-01'][0]['efeito'] + 1200.0) < 0.01
    && array_map(static fn($x) => $x['nome'] . ' ' . (int) $x['efeito'], $mu['2027-03-01'])
       === ['Salário 5000', 'IPVA 1100', 'Parcela do empréstimo -300'], json_encode($mu['2027-03-01']));

// ---- 15. cartão: fatura de uma compra, parcelas, compra antiga e o painel
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

// ---- 16. aba Empréstimo: dados de mentira, para não depender da dívida de verdade
$dadosEmp = [
    'meses' => [['mes' => '2030-01-01', 'situacao' => 'PREVISAO'], ['mes' => '2030-02-01', 'situacao' => 'PREVISAO'], ['mes' => '2030-03-01', 'situacao' => 'PREVISAO']],
    'aj' => ['parcela_mensal' => 100.0, 'juros_mes' => 0.01, 'n_parcelas' => 12.0, 'reserva_minima' => 500.0, 'saldo_ini' => 2000.0, 'poupanca_ini' => 0.0, 'objetivo' => 1.0, 'meta_final' => 0.0],
    'loanIni' => 1000.0, 'cats' => [], 'prev' => [], 'excecao' => [], 'metas' => [], 'real' => [], 'n' => [], 'ult' => [],
    'esp' => ['cred' => null, 'amort' => 99, 'guard' => null, 'resg' => null],
];
$hojeEmp = '2029-12-15';
$sitEmp = EmprestimoSimulacao::situacao($dadosEmp, '2030-01-01', $hojeEmp);
$check('empréstimo: falta = saldo + juros − parcela no fim do mês', abs($sitEmp['falta'] - 910.0) < 0.005, (string) $sitEmp['falta']);
$check('empréstimo: sem o mês da 1ª parcela, não há fim pelo contrato', $sitEmp['contrato'] === null);
$simEmp = EmprestimoSimulacao::simular($dadosEmp, '2030-01-01', '2030-01-01', 200.0, $hojeEmp);
$check('empréstimo: o adiantamento abate a dívida e sai da conta', abs($simEmp['falta'] - 710.0) < 0.005 && abs($simEmp['contaAntes'] - $simEmp['contaDepois'] - 200.0) < 0.005, json_encode($simEmp));
$saldoT = 710.0;
$k = 0;
$jurosT = 0.0;
while ($saldoT > 0.01) {
    $jT = $saldoT * 0.01;
    $jurosT += $jT;
    $saldoT = max(0.0, $saldoT + $jT - 100.0);
    $k++;
}
$saldoS = 910.0;
$kS = 0;
$jurosS = 0.0;
while ($saldoS > 0.01) {
    $jS = $saldoS * 0.01;
    $jurosS += $jS;
    $saldoS = max(0.0, $saldoS + $jS - 100.0);
    $kS++;
}
$check('empréstimo: meses e juros a menos batem com a conta à mão', $simEmp['mesesAntes'] === $kS - $k && abs($simEmp['jurosMenos'] - ($jurosS - $jurosT)) < 0.01, json_encode([$simEmp['mesesAntes'], $kS - $k, $simEmp['jurosMenos'], $jurosS - $jurosT]));
$demais = EmprestimoSimulacao::simular($dadosEmp, '2030-01-01', '2030-02-01', 50000.0, $hojeEmp);
$check('empréstimo: valor acima da dívida quita e só tira da conta o que devia', $demais['ajustado'] && $demais['falta'] <= 0.01 && abs($demais['aplicado'] - $demais['teto']) < 0.005
    && abs($demais['contaAntes'] - $demais['contaDepois'] - $demais['aplicado']) < 0.005 && $demais['quita'] === '2030-02-01', json_encode($demais['aplicado']));
$alerta = EmprestimoSimulacao::simular($dadosEmp, '2030-01-01', '2030-01-01', 900.0, $hojeEmp);
$check('empréstimo: avisa quando a conta cai abaixo da reserva, e sem aviso se não cai', $alerta['alerta'] === null && $simEmp['alerta'] === null
    && EmprestimoSimulacao::simular(array_replace_recursive($dadosEmp, ['aj' => ['saldo_ini' => 900.0]]), '2030-01-01', '2030-01-01', 350.0, $hojeEmp)['alerta'] === 'warn'
    && EmprestimoSimulacao::simular(array_replace_recursive($dadosEmp, ['aj' => ['saldo_ini' => 600.0]]), '2030-01-01', '2030-01-01', 500.0, $hojeEmp)['alerta'] === 'neg');
$check('empréstimo: com a parcela menor, o prazo fica e os juros caem menos que encurtando', $simEmp['menorParcela']['quita'] === $simEmp['quitaAntes'] && $simEmp['menorParcela']['parcela'] < 100.0
    && $simEmp['menorParcela']['jurosMenos'] < $simEmp['jurosMenos'], json_encode($simEmp['menorParcela']));
$check('empréstimo: mês real não recebe adiantamento simulado', abs(MotorFinanceiro::calcular(array_replace($dadosEmp, ['meses' => [['mes' => '2030-01-01', 'situacao' => 'REAL']]]),
    ['adiantar' => ['2030-01-01' => 200.0], 'v2' => true, 'objetivo' => 3, 'hoje' => $hojeEmp])['2030-01-01']['amortizacao_extra']) < 0.005);
$check('empréstimo: sem a opção, o motor segue igual', json_encode(MotorFinanceiro::calcular($dadosEmp, ['hoje' => $hojeEmp])) === json_encode(MotorFinanceiro::calcular($dadosEmp, ['hoje' => $hojeEmp, 'adiantar' => []])));
$lido = AjustesController::lerValores(['juros_mes' => '1,5000', 'parcela_mensal' => '300,00', 'n_parcelas' => '60', 'consignado_inicio' => '2026-11', 'reserva_minima' => '1,00'], AjusteModel::DO_EMPRESTIMO);
$check('dados do empréstimo: lê os campos e ignora o que não é do empréstimo', $lido['erro'] === null && abs($lido['valores']['juros_mes'] - 0.015) < 1e-9
    && $lido['valores']['consignado_inicio'] === 202611.0 && !isset($lido['valores']['reserva_minima']), json_encode($lido));
$check('dados do empréstimo: recusa parcela zero, número de parcelas quebrado e mês inválido',
    AjustesController::lerValores(['parcela_mensal' => '0'], AjusteModel::DO_EMPRESTIMO)['erro'] === 'parcela_mensal'
    && AjustesController::lerValores(['n_parcelas' => '1,5'], AjusteModel::DO_EMPRESTIMO)['erro'] === 'n_parcelas'
    && AjustesController::lerValores(['consignado_inicio' => '2026-13'], AjusteModel::DO_EMPRESTIMO)['erro'] === 'consignado_inicio');
$check('dados do empréstimo: Ajustes não grava mais esses campos', array_diff(AjusteModel::DO_EMPRESTIMO, AjusteModel::EDITAVEIS) === []
    && AjustesController::lerValores(['juros_mes' => '5'], array_diff(AjusteModel::EDITAVEIS, AjusteModel::DO_EMPRESTIMO))['valores'] === []);
$dadosSaldo = array_replace_recursive($dadosEmp, ['aj' => ['saldo_devedor' => 500.0, 'saldo_devedor_mes' => 203002.0]]);
$pSaldo = MotorFinanceiro::calcular($dadosSaldo, ['v2' => true, 'objetivo' => 3, 'hoje' => $hojeEmp]);
$check('saldo informado: a dívida parte dele no mês informado, antes da parcela', abs($pSaldo['2030-01-01']['falta_pagar'] - 910.0) < 0.005
    && abs($pSaldo['2030-02-01']['falta_pagar'] - 405.0) < 0.005 && abs($pSaldo['2030-03-01']['falta_pagar'] - 309.05) < 0.005, json_encode(array_column($pSaldo, 'falta_pagar')));
$pSaldoAuto = MotorFinanceiro::calcular($dadosSaldo, ['v2' => true, 'objetivo' => 1, 'hoje' => $hojeEmp]);
$check('saldo informado: sem adiantamento automático antes dele, e com adiantamento depois', abs($pSaldoAuto['2030-01-01']['amortizacao_extra']) < 0.005 && $pSaldoAuto['2030-02-01']['amortizacao_extra'] > 0.0
    && MotorFinanceiro::calcular($dadosEmp, ['v2' => true, 'objetivo' => 1, 'hoje' => $hojeEmp])['2030-01-01']['amortizacao_extra'] > 0.0);
$check('saldo informado: juros menores não aumentam a dívida', MotorFinanceiro::calcular(array_replace_recursive($dadosSaldo, ['aj' => ['juros_mes' => 0.005]]), ['v2' => true, 'objetivo' => 3, 'hoje' => $hojeEmp])['2030-02-01']['falta_pagar'] < $pSaldo['2030-02-01']['falta_pagar']);
$check('saldo informado: em branco ou sem mês, vale o cálculo de antes', MotorFinanceiro::saldoInformado($dadosEmp['aj']) === null
    && MotorFinanceiro::saldoInformado(['saldo_devedor' => 500.0, 'saldo_devedor_mes' => 0.0]) === null && MotorFinanceiro::saldoInformado(['saldo_devedor' => 0.0, 'saldo_devedor_mes' => 203002.0]) === null);
$simSaldo = EmprestimoSimulacao::simular($dadosSaldo, '2030-02-01', '2030-02-01', 100.0, $hojeEmp);
$check('saldo informado: a simulação parte dele', abs($simSaldo['faltaAntes'] - 405.0) < 0.005 && abs($simSaldo['falta'] - 305.0) < 0.005, json_encode([$simSaldo['faltaAntes'], $simSaldo['falta']]));
$confere = static fn(array $v): ?string => EmprestimoController::conferirSaldo($v, '2026-11-15', '2026-09-01', '2027-12-01')[0];
$check('saldo informado: valor e mês juntos, em mês do plano que já começou', $confere(['saldo_devedor' => 0.0, 'saldo_devedor_mes' => 0.0]) === null
    && $confere(['saldo_devedor' => 100.0, 'saldo_devedor_mes' => 202611.0]) === null && $confere(['saldo_devedor' => 100.0, 'saldo_devedor_mes' => 0.0]) === 'saldo_devedor_mes'
    && $confere(['saldo_devedor' => 0.0, 'saldo_devedor_mes' => 202611.0]) === 'saldo_devedor' && $confere(['saldo_devedor' => 100.0, 'saldo_devedor_mes' => 202612.0]) === 'saldo_devedor_mes'
    && $confere(['saldo_devedor' => 100.0, 'saldo_devedor_mes' => 202608.0]) === 'saldo_devedor_mes');
$check('saldo informado: o campo em branco lê como zero', AjustesController::lerValores(['saldo_devedor' => ' ', 'saldo_devedor_mes' => ''], AjusteModel::DO_EMPRESTIMO) === ['valores' => ['saldo_devedor' => 0.0, 'saldo_devedor_mes' => 0.0], 'erro' => null]);

// ---- 17. wishlist: previsão com dados de mentira, leitura de link sem rede
$mesesWl = array_map(static fn(string $m): array => ['mes' => $m, 'situacao' => 'PREVISAO'], ['2030-01-01', '2030-02-01', '2030-03-01', '2030-04-01']);
$catSal = ['id' => 1, 'nome' => 'Renda', 'apelido' => null, 'grupo' => 'Entradas', 'sinal' => 1, 'operacional' => 1, 'essencial' => 0, 'ativo' => 1, 'ordem' => 0];
$dadosWl = ['meses' => $mesesWl, 'aj' => ['parcela_mensal' => 0.0, 'juros_mes' => 0.0, 'n_parcelas' => 0.0, 'reserva_minima' => 500.0, 'saldo_ini' => 1000.0, 'poupanca_ini' => 0.0, 'objetivo' => 1.0, 'meta_final' => 0.0],
    'loanIni' => 0.0, 'cats' => [1 => $catSal], 'prev' => array_fill_keys(['2030-01-01', '2030-02-01', '2030-03-01', '2030-04-01'], [1 => 500.0]),
    'excecao' => [], 'metas' => [], 'real' => [], 'n' => [], 'ult' => [], 'esp' => ['cred' => null, 'amort' => null, 'guard' => null, 'resg' => null]];
$hojeWl = '2029-12-20';
$pW = WishlistPrevisao::calcular($dadosWl, '2030-01-01', 1800.0, 1, $hojeWl);   // conta: 1500, 2000, 2500, 3000
$check('wishlist: à vista, cabe no 1º mês em que nada fica abaixo da reserva', $pW['cedo'] === '2030-03-01' && $pW['meses']['2030-01-01']['estado'] === 'neg'
    && $pW['meses']['2030-02-01']['estado'] === 'warn' && $pW['meses']['2030-03-01']['estado'] === 'ok' && !$pW['temDivida'], json_encode($pW['meses']));
$check('wishlist: mês bom mostra o ponto mais apertado depois da compra', abs($pW['meses']['2030-03-01']['pior']['fica'] - 700.0) < 0.005 && $pW['meses']['2030-03-01']['pior']['mes'] === '2030-03-01');
$pP = WishlistPrevisao::calcular($dadosWl, '2030-01-01', 1800.0, 2, $hojeWl);   // 900 em fev e 900 em mar
$check('wishlist: parcelado sai da fatura do mês seguinte e pode caber antes', $pP['cedo'] === '2030-01-01' && abs($pP['meses']['2030-01-01']['pior']['fica'] - 700.0) < 0.005, json_encode($pP['meses']['2030-01-01']));
$pF = WishlistPrevisao::calcular($dadosWl, '2030-01-01', 1200.0, 3, $hojeWl);
$check('wishlist: parcela depois do fim do plano não é avaliada', $pF['meses']['2030-02-01']['estado'] === 'fora' && $pF['meses']['2030-01-01']['estado'] !== 'fora');
$pN = WishlistPrevisao::calcular($dadosWl, '2030-01-01', 9000.0, 1, $hojeWl);
$check('wishlist: não cabe em mês nenhum e diz quanto falta no mais perto', $pN['cedo'] === null && $pN['menorFalta']['mes'] === '2030-04-01' && abs($pN['menorFalta']['valor'] - 6500.0) < 0.01, json_encode($pN['menorFalta']));
$pV = WishlistPrevisao::calcular(array_replace_recursive($dadosWl, ['aj' => ['saldo_ini' => -200.0]]), '2030-01-01', 50.0, 1, $hojeWl);   // sem a compra, jan fica em 300
$check('wishlist: mês já abaixo da reserva sem a compra só não pode piorar', $pV['meses']['2030-01-01']['estado'] === 'warn' && $pV['meses']['2030-02-01']['estado'] === 'ok' && $pV['cedo'] === '2030-02-01', json_encode(array_column($pV['meses'], 'estado')));
$mesesWl8 = [];
for ($i = 1; $i <= 8; $i++) {
    $mesesWl8[] = sprintf('2030-%02d-01', $i);
}
$dadosWlD = array_replace_recursive($dadosWl, ['aj' => ['saldo_ini' => 600.0, 'parcela_mensal' => 100.0, 'juros_mes' => 0.02, 'n_parcelas' => 12.0]]);
$dadosWlD['loanIni'] = 1000.0;
$dadosWlD['meses'] = array_map(static fn(string $m): array => ['mes' => $m, 'situacao' => 'PREVISAO'], $mesesWl8);
$dadosWlD['prev'] = array_fill_keys($mesesWl8, [1 => 400.0]);
$pD = WishlistPrevisao::calcular($dadosWlD, '2030-01-01', 300.0, 1, $hojeWl);
$check('wishlist: comprar antes de quitar custa juros; depois, não', $pD['temDivida'] && $pD['cedo'] === '2030-01-01' && $pD['meses']['2030-01-01']['custo'] > 0.0
    && $pD['semCusto'] !== null && $pD['semCusto'] > $pD['cedo'] && $pD['meses'][$pD['semCusto']]['custo'] < 1.0 && $pD['melhor'] === $pD['semCusto'], json_encode([$pD['cedo'], $pD['semCusto'], array_column($pD['meses'], 'custo')]));
$check('wishlist: a simulação não mexe nos dados', MotorFinanceiro::calcular($dadosWl, ['hoje' => $hojeWl])['2030-04-01']['fica_na_conta'] == 3000.0);

$check('link: aceita o texto do compartilhar e endereço sem https', LinkProduto::normalizar('Olha isso: https://loja.exemplo/p/1?a=b') === 'https://loja.exemplo/p/1?a=b' && LinkProduto::normalizar('loja.exemplo/p/1') === 'https://loja.exemplo/p/1');
$check('link: recusa esquema, porta, usuário e formatos ambíguos', LinkProduto::normalizar('ftp://loja.exemplo/a') === null && LinkProduto::normalizar('file:///etc/passwd') === null && LinkProduto::normalizar('javascript:alert(1)') === null
    && LinkProduto::normalizar('http://loja.exemplo:8080/a') === null && LinkProduto::normalizar('http://x:y@loja.exemplo/') === null && LinkProduto::normalizar('http://loja.exemplo\\@interno/') === null);
foreach (['127.0.0.1', '10.0.0.1', '172.16.0.1', '192.168.1.10', '169.254.169.254', '100.64.0.1', '0.0.0.0', '224.0.0.1', '198.18.0.1', '::1', 'fc00::1'] as $ipRuim) {
    $check("link: IP fora da internet recusado ({$ipRuim})", !LinkProduto::ipPublico($ipRuim));
}
$check('link: IP público aceito', LinkProduto::ipPublico('8.8.8.8'));
foreach (['http://127.0.0.1/', 'http://169.254.169.254/latest/meta-data/', 'http://192.168.1.10/', 'http://2130706433/', 'http://0x7f.0.0.1/', 'http://localhost/'] as $urlRuim) {
    $check("link: não conecta em endereço interno ({$urlRuim})", LinkProduto::buscar($urlRuim, 1024) === null);
}
$htmlP = '<html><head><title>Título ruim</title><meta property="og:title" content="Nome do OG"><meta property="og:image" content="/img/og.jpg"><script type="application/ld+json">{"@graph":[{"@type":"WebSite"},{"@type":"Product","name":"Sofá &amp; Cia","image":["https://cdn.exemplo/a.jpg"],"offers":{"@type":"Offer","price":"1299.90","priceCurrency":"BRL"}}]}</script></head></html>';
$mP = LinkProduto::metadados($htmlP, 'https://loja.exemplo/p/1');
$check('link: lê nome, preço e foto dos dados do produto', $mP['nome'] === 'Sofá & Cia' && $mP['valor'] === 1299.9 && $mP['imagem'] === 'https://cdn.exemplo/a.jpg', json_encode($mP));
$mO = LinkProduto::metadados('<head><title>T</title><meta property="og:title" content="Só OG"><meta property="og:image" content="/img/og.jpg"><meta property="product:price:amount" content="89,90"></head>', 'https://loja.exemplo/p/1');
$check('link: sem dados de produto, usa as etiquetas de compartilhamento', $mO['nome'] === 'Só OG' && $mO['valor'] === 89.9 && $mO['imagem'] === 'https://loja.exemplo/img/og.jpg', json_encode($mO));
$mG = LinkProduto::metadados('<script type="application/ld+json">[{"@type":"ProductGroup","name":"Grupo","hasVariant":[{"@type":"Product","offers":{"price":899,"priceCurrency":"BRL"}}]}]</script>', 'https://loja.exemplo/');
$check('link: grupo de produtos usa o preço da 1ª variação', $mG['nome'] === 'Grupo' && $mG['valor'] === 899.0, json_encode($mG));
$mC = LinkProduto::metadados('<div id="corePrice_feature_div"><span class="a-price"><span class="a-offscreen">R$455,05</span></span></div><img id="x" data-old-hires="https://cdn.exemplo/g.jpg">', 'https://loja.exemplo/');
$check('link: sem dados estruturados, tenta a marcação usual', $mC['valor'] === 455.05 && $mC['imagem'] === 'https://cdn.exemplo/g.jpg', json_encode($mC));
$mU = LinkProduto::metadados('<script type="application/ld+json">{"@type":"Product","name":"X","offers":{"price":"10","priceCurrency":"USD"}}</script>', 'https://loja.exemplo/');
$check('link: preço em dólar vem com a moeda', $mU['valor'] === 10.0 && $mU['moeda'] === 'USD');
$mX = LinkProduto::metadados('<script type="application/ld+json">{"@type":"Product","name":"X","offers":{"price":"10","priceCurrency":"KPW"}}</script>', 'https://loja.exemplo/');
$check('link: moeda que o app não tem não entra', $mX['valor'] === null && $mX['moeda'] === 'BRL' && $mX['nome'] === 'X');
$check('cotação: lê a fonte principal e ignora o resto', Cotacao::lerPrincipal('{"USDBRL":{"code":"USD","codein":"BRL","bid":"5.4397","create_date":"2026-10-30 19:00:08"},"XXXBRL":{"code":"XXX","codein":"BRL","bid":"1","create_date":"2026-10-30 19:00:08"},"EURBRL":{"code":"EUR","codein":"BRL","bid":"zero","create_date":"2026-10-30 19:00:08"}}') === ['USD' => ['valor' => 5.4397, 'data' => '2026-10-30']]);
$check('cotação: a fonte reserva vem invertida', Cotacao::lerReserva('{"base":"BRL","date":"2026-10-30","rates":{"USD":0.2,"XXX":1}}') === ['USD' => ['valor' => 5.0, 'data' => '2026-10-30']]);
$check('cotação: resposta inválida não vira cotação', Cotacao::lerPrincipal('<html>erro</html>') === [] && Cotacao::lerReserva('{"base":"USD","date":"2026-10-30","rates":{"BRL":5}}') === [] && Cotacao::lerPrincipal('{"USDBRL":{"code":"USD","codein":"BRL","bid":"5","create_date":"ontem"}}') === []);
$taxasT = ['USD' => ['valor' => 5.4397, 'data' => '2026-10-30']];
$check('cotação: converte para a moeda do site; a mesma moeda fica igual; sem cotação é null', Cotacao::naMoedaDoSite(100.0, 'USD', $taxasT) === 543.97 && Cotacao::naMoedaDoSite(100.0, 'BRL', []) === 100.0 && Cotacao::naMoedaDoSite(100.0, 'EUR', $taxasT) === null);
$check('cotação: formata na moeda', Cotacao::formatar(1299.0, 'USD') === 'US$' . NBSP . '1.299,00' && Cotacao::formatar(10.0, 'BRL') === money(10.0));
$check('link: imagem data: não é baixada', LinkProduto::metadados('<meta property="og:image" content="data:image/png;base64,AAAA">', 'https://loja.exemplo/')['imagem'] === null);
$check('link: leitura de preços', LinkProduto::preco('1299.90') === 1299.9 && LinkProduto::preco('R$ 1.299,90') === 1299.9 && LinkProduto::preco(50) === 50.0 && LinkProduto::preco('grátis') === null && LinkProduto::preco('0') === null);
$pngT = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
$fotoT = LinkProduto::fotoValida($pngT);
$check('foto: vale pelo conteúdo, não pelo nome', $fotoT !== null && $fotoT['tipo'] === 'image/png' && LinkProduto::fotoValida('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>') === null
    && LinkProduto::fotoValida('<?php echo 1;') === null && LinkProduto::fotoValida(str_repeat('a', LinkProduto::MAX_FOTO + 1)) === null);
$lc = WishlistController::lerCampos(['nome' => "  Sofá\n novo ", 'url' => 'loja.exemplo/a', 'valor' => '1.250,50']);
$check('wishlist: lê o formulário', $lc['nome'] === 'Sofá  novo' || $lc['nome'] === 'Sofá novo', $lc['nome']);
$check('wishlist: moeda inválida vira real; iene aceita valor alto', WishlistController::lerCampos(['moeda' => 'XXX'])['moeda'] === 'BRL' && WishlistController::lerCampos(['moeda' => 'JPY', 'valor' => '2000000'])['valor'] === 2000000.0
    && WishlistController::lerCampos(['moeda' => 'BRL', 'valor' => '2000000'])['erro'] !== null);
$check('wishlist: recusa link e valor inválidos', WishlistController::lerCampos(['url' => 'javascript:alert(1)'])['erro'] !== null && WishlistController::lerCampos(['valor' => '12a'])['erro'] !== null && WishlistController::lerCampos(['valor' => '0'])['erro'] !== null
    && WishlistController::lerCampos(['valor' => ''])['valor'] === null);

// ---- 18. temas: a lista gerada, o contraste de cada um e o tema da conta
$lumT = static function (string $h): float {
    $f = static function (string $x): float {
        $c = hexdec($x) / 255;
        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    };
    return 0.2126 * $f(substr($h, 1, 2)) + 0.7152 * $f(substr($h, 3, 2)) + 0.0722 * $f(substr($h, 5, 2));
};
$contrasteT = static function (string $a, string $b) use ($lumT): float {
    [$x, $y] = [$lumT($a), $lumT($b)];
    return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
};
$cssTemas = (string) file_get_contents(__DIR__ . '/../public/assets/css/temas.css');
$ruins = [];
$semCss = [];
foreach (Temas::todos() as $idT => $t) {
    foreach ([['text', 'bg'], ['text', 'card'], ['text2', 'card'], ['on', 'acc'], ['pos', 'card'], ['neg', 'card'], ['warn', 'card'], ['ct', 'cofre']] as [$fg, $fundo]) {
        if ($contrasteT((string) $t[$fg], (string) $t[$fundo]) < 4.5) {
            $ruins[] = "{$idT}: {$fg}/{$fundo}";
        }
    }
    if (!in_array($idT, ['claro', 'escuro'], true) && !str_contains($cssTemas, ':root[data-tema="' . $idT . '"]')) {
        $semCss[] = $idT;
    }
}
$paresErrados = [];
foreach (Temas::todos() as $idT => $dT) {
    $lados = Temas::lados($idT);
    if ($lados !== null && (Temas::todos()[$lados[0]]['modo'] !== 'light' || Temas::todos()[$lados[1]]['modo'] !== 'dark' || !in_array($idT, $lados, true) && $idT !== 'oled')) {
        $paresErrados[] = $idT;
    }
}
$check('temas: o atalho claro/escuro sempre leva a um tema de cada modo, e o tema atual é um deles', $paresErrados === [] && Temas::lados(null) === ['claro', 'escuro'], implode(', ', $paresErrados));
$check('temas: tema de um modo só não tem atalho', Temas::lados('nord') === null && Temas::lados('caramelo') === null);
$check('temas: há os 32, e cada um tem CSS', count(Temas::todos()) === 32 && $semCss === [], implode(', ', $semCss));
$check('temas: todo tema passa no contraste', $ruins === [], implode(', ', $ruins));
$pdo->beginTransaction();
try {
    $uTema = (int) Auth::id();
    UsuarioModel::salvarTema($uTema, null);
    $htmlT0 = $render(TemaController::class, 'index', []);
    $check('temas: a tela mostra os cartões e o automático marcado', substr_count($htmlT0, 'class="tm') >= 33 && str_contains($htmlT0, 'data-tm-auto') && !preg_match('/<html[^>]*data-tema=/', $htmlT0));
    UsuarioModel::salvarTema($uTema, 'caramelo');
    $htmlT1 = $render(TemaController::class, 'index', []);
    $check('temas: o tema da conta vem no HTML, sem piscar', str_contains($htmlT1, 'data-theme="light" data-tema="caramelo"') && str_contains($htmlT1, 'temas.css'));
    $check('temas: tema de um modo só mostra o nome em vez de claro/escuro', !str_contains($htmlT1, 'data-theme-toggle') && !str_contains($htmlT1, 'class="tema-seg"') && str_contains($htmlT1, 'class="tema-atual" href="/temas"'));
    $check('temas: fora da barra do celular e no menu', str_contains($htmlT1, 'href="/temas"') && !preg_match('#<nav class="tabbar".*?href="/temas".*?</nav>#s', $htmlT1));
    UsuarioModel::salvarTema($uTema, 'tema-que-saiu');
    $check('temas: um tema que saiu da lista vira automático', Temas::doUsuario() === null);
} finally {
    $pdo->rollBack();
}

// ---- 18a. valor digitado: número sem separador é inteiro; o que é ambíguo é recusado, nunca adivinhado
$valoresPt = ['45' => 45.0, '45,9' => 45.9, '45,90' => 45.9, '1.500' => 1500.0, '1.500,50' => 1500.5, '1,500' => null, '12.50' => null, 'R$ 7' => 7.0];
foreach ($valoresPt as $txt => $esperado) {
    $check("valor em pt: {$txt}", parseMoedaBr((string) $txt) === $esperado);
}
Financeiro\Idioma::usar('en');
foreach (['45' => 45.0, '12.50' => 12.5, '1,500' => 1500.0, '1,500.50' => 1500.5, '1.500' => null] as $txt => $esperado) {
    $check("valor em en: {$txt}", parseMoedaBr((string) $txt) === $esperado);
}
Financeiro\Idioma::usar('es');
$check('valor em es: 1,500 é recusado', parseMoedaBr('1,500') === null);
$check('valor em es: 12.50 é decimal', parseMoedaBr('12.50') === 12.5);
Financeiro\Idioma::usar('pt');
$check('juros: 1,655 continua decimal', parseMoedaBr('1,655', false) === 1.655);

// ---- 18a2. Ajuda: respostas únicas e os "?" das telas apontam para uma pergunta que existe
$idsAjuda = array_column(array_merge(...array_column(Financeiro\Ajuda::grupos(), 'itens')), 'id');
$check('ajuda: cada pergunta tem um id só', count($idsAjuda) === count(array_unique($idsAjuda)));
$usoDica = '';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../src/Views', FilesystemIterator::SKIP_DOTS)) as $fDica) {
    $usoDica .= (string) file_get_contents($fDica->getPathname());
}
preg_match_all("/dica\\('([a-z-]+)'/", $usoDica, $mDica);
$check('ajuda: todo "?" tem resposta', array_diff($mDica[1], $idsAjuda) === [], implode(',', array_diff($mDica[1], $idsAjuda)));

// ---- 18b. conta nova: as regras da primeira conta e de Ajustes
$check('conta nova: nome vazio não passa', UsuarioModel::erroNovaConta('', 'alguem', 'senha-longa') !== null);
$check('conta nova: login vazio não passa', UsuarioModel::erroNovaConta('Alguém', '', 'senha-longa') !== null);
$check('conta nova: senha curta não passa', UsuarioModel::erroNovaConta('Alguém', 'alguem', 'curta') !== null);
$loginT = (string) $pdo->query('SELECT login FROM usuarios ORDER BY id LIMIT 1')->fetchColumn();
$check('conta nova: login repetido não passa', UsuarioModel::erroNovaConta('Alguém', $loginT, 'senha-longa') !== null);
$check('conta nova: dados certos passam', UsuarioModel::erroNovaConta('Alguém', 'login-livre-' . bin2hex(random_bytes(3)), 'senha-longa') === null);
$check('conta nova: com conta no banco, a tela de criar some', !UsuarioModel::nenhum());

// ---- 19. nome de instituição financeira não entra no projeto. Só os SHA-256 ficam aqui, para este arquivo não
// citar nenhum nome; para acrescentar um: printf %s 'palavra em minúsculas' | sha256sum
$proibidas = [
    'de5ce798a166af199765b83b7e6fe89d3f251093e2d1721a4ab38a99b92c12e9',
    'd7412e52cbe5b676ecdc0383c60539c604d0a4089209ab508edbc8d008de1a5b',
];
$temProibida = static function (string $texto, array $resumos): bool {
    foreach (array_unique(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($texto), -1, PREG_SPLIT_NO_EMPTY) ?: []) as $palavra) {
        if (in_array(hash('sha256', $palavra), $resumos, true)) {
            return true;
        }
    }
    return false;
};
$check('nomes proibidos: compara palavra a palavra, sem diferenciar maiúsculas', $temProibida('Pago com a EXEMPLO, hoje.', [hash('sha256', 'exemplo')])
    && !$temProibida('sem exemplos aqui', [hash('sha256', 'exemplo')]));
$raiz = dirname(__DIR__);
$achados = [];
$arquivos = array_merge(glob($raiz . '/*.md') ?: [], [$raiz . '/instalar.sh']);
foreach (['src', 'public/assets/js', 'public/assets/css', 'docs', 'database', 'tools', 'tests', '.github'] as $pasta) {
    if (is_dir($raiz . '/' . $pasta)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz . '/' . $pasta, FilesystemIterator::SKIP_DOTS)) as $f) {
            // database/data é do site, fora do repositório: o atualizador preserva e o teste não confere. Os pacotes
            // do npm e os resultados dos testes de navegador também ficam fora do repositório
            $foraDoRepo = '#/(database/data|tests/navegador/(node_modules|test-results|playwright-report|blob-report))/#';
            if (preg_match('/\.(php|md|sql|js|css|sh|py|json|yml)$/', $f->getFilename()) && !preg_match($foraDoRepo, str_replace('\\', '/', $f->getPathname()))) {
                $arquivos[] = $f->getPathname();
            }
        }
    }
}
foreach ($arquivos as $arq) {
    if (is_file($arq) && $temProibida((string) file_get_contents($arq), $proibidas)) {
        $achados[] = substr($arq, strlen($raiz) + 1);
    }
}
$check('nenhum arquivo cita instituição financeira', $achados === [], implode(', ', $achados));

// ---- 20. o selo do README acompanha o VERSION
$arqVersao = __DIR__ . '/../VERSION';
$arqReadme = __DIR__ . '/../README.md';
if (is_file($arqVersao) && is_file($arqReadme)) {
    $versao = trim((string) file_get_contents($arqVersao));
    $readme = (string) file_get_contents($arqReadme);
    $check('versão: o selo do README diz o mesmo que o VERSION', str_contains($readme, "[![Versão {$versao}](") && str_contains($readme, "-{$versao}-blue)"), $versao);
}

// ---- 23. mensagens em frase, tamanho mínimo e nada de texto escrito no CSS
$telegrafico = [];
foreach (array_merge(glob(__DIR__ . '/../src/Controllers/*.php'), glob(__DIR__ . '/../src/Services/*.php')) as $arq) {
    if (preg_match_all("/\\bt\\('[^' ]{1,20}\\?'/u", (string) file_get_contents($arq), $mT) > 0) {
        array_push($telegrafico, ...$mT[0]);
    }
}
$check('mensagens: nenhuma do tipo "valor?"', $telegrafico === [], implode(', ', $telegrafico));
$fonteMiuda = [];
$textoCss = [];
foreach (glob(__DIR__ . '/../public/assets/css/*.css') as $arq) {
    $css = (string) file_get_contents($arq);
    if (preg_match_all('/font-size:\s*(\d+(?:\.\d+)?)px/', $css, $mF) > 0) {
        foreach ($mF[1] as $tam) {
            if ((float) $tam < 12) {
                $fonteMiuda[] = basename($arq) . ':' . $tam;
            }
        }
    }
    if (preg_match('/content:\s*"[^"]*\p{L}{3}/u', $css) === 1) {
        $textoCss[] = basename($arq);
    }
}
$check('CSS: nenhum texto menor que 12px', $fonteMiuda === [], implode(', ', $fonteMiuda));
$check('CSS: nenhuma palavra escrita no CSS, fora do alcance da tradução', $textoCss === [], implode(', ', $textoCss));

// ---- 24. moeda, número e fuso por site; categorias de fábrica no idioma de quem vê
$valoresT = [0.01, 0.5, 1.0, 12.5, 45.9, 999.99, 1000.0, 1500.0, 1234.56, 1000000.0, 12345678.9, -8233.11];
foreach (['pt', 'en', 'es'] as $idT) {
    Idioma::usar($idT);
    foreach (['BRL', 'USD', 'EUR', 'CLP', 'PEN'] as $moedaT) {
        Site::usarMoeda($moedaT);
        $ruins = [];
        foreach ($valoresT as $v) {
            foreach (['numInput' => numInput($v), 'num' => num($v), 'money' => money($v)] as $fn => $txt) {
                if (parseMoedaBr($txt) !== $v) {
                    $ruins[] = "{$fn}({$v}) = {$txt}";
                }
            }
        }
        $check("ida e volta do valor em {$idT}, {$moedaT}", $ruins === [], implode('; ', $ruins));
    }
    Site::usarMoeda('BRL');
    $check("ida e volta com 4 casas em {$idT}", parseMoedaBr(numInput(20000.0, 4)) === 20000.0 && parseMoedaBr(numInput(12.5, 4)) === 12.5 && parseMoedaBr(numInput(1234.5678, 4)) === 1234.5678);
    $check("ida e volta sem casas em {$idT}", parseMoedaBr(numInput(1500.0, 0)) === 1500.0 && parseMoedaBr(numInput(72.0, 0)) === 72.0);
    $check("ida e volta da taxa de juros em {$idT}", parseMoedaBr(numInput(1.65), false) === 1.65 && parseMoedaBr(numInput(0.0165, 4), false) === 0.0165);
    $check("o exemplo vazio dos campos é lido em {$idT}", parseMoedaBr(numInput(0)) === 0.0);
}
Idioma::usar('en');
$check('número em en: 1,234.56', num(1234.56) === '1,234.56' && numInput(-1234.56) === '-1,234.56' && money(1234.56) === 'R$' . NBSP . '1,234.56');
$check('número em en: 1.500 continua recusado no dinheiro, e é 1,5 numa taxa', parseMoedaBr('1.500') === null && parseMoedaBr('1.500', false) === 1.5);
$check('número em en: resumido', moneyCompact(16800.0) === '16.8' . NBSP . t('mil') && moneyCompact(10000.0) === '10' . NBSP . t('mil'));
$check('data em en: Nov 22', diaMes('2026-11-22') === 'Nov 22' && diaMes('2026-11-02') === 'Nov 2' && diaMesHora('2026-11-22 14:02:00') === 'Nov 22 14:02' && diaMesAno('2026-11-22') === 'Nov 22, 2026' && diaMesCurto('2026-11-22') === 'Nov 22');
Idioma::usar('es');
$check('número em es: 1.234,56', num(1234.56) === '1.234,56' && money(1234.56) === 'R$' . NBSP . '1.234,56');
$check('data em es: 22/11', diaMes('2026-11-22') === '22/11' && diaMesAno('2026-11-22') === '22/11/2026' && diaMesCurto('2026-11-22') === '22 nov');
Idioma::usar('pt');
$check('número em pt: como sempre', money(-1234.56) === MENOS . 'R$' . NBSP . '1.234,56' && moneySigned(1.0) === '+R$' . NBSP . '1,00' && num(1500.0, 0) === '1.500' && moneyCompact(16800.0) === '16,8' . NBSP . 'mil');
$check('data em pt: como sempre', diaMes('2026-11-22') === '22/11' && diaMesHora('2026-11-22 14:02:00') === '22/11 14:02' && diaMesCurto('2026-11-22') === '22 nov');
$check('moeda: nada de R$ no dinheiro de outra moeda', (static function (): bool { Site::usarMoeda('EUR'); $r = money(10.0) === '€' . NBSP . '10,00' && moedaSimbolo() === '€'; Site::usarMoeda('BRL'); return $r; })());
$check('moeda: toda moeda do site tem símbolo e nome', array_diff_key(Site::MOEDAS, Cotacao::MOEDAS) === [] && count(Site::opcoesMoeda()) === count(Site::MOEDAS));

// a cotação vem em reais: as outras se cruzam pelo real
$taxasC = ['USD' => ['valor' => 5.0, 'data' => '2030-01-02'], 'EUR' => ['valor' => 6.0, 'data' => '2030-01-03']];
Site::usarMoeda('USD');
$check('cotação: real vira a moeda do site', Cotacao::naMoedaDoSite(100.0, 'BRL', $taxasC) === 20.0 && Cotacao::dataDe('BRL', $taxasC) === '2030-01-02');
$check('cotação: cruza pelo real', Cotacao::naMoedaDoSite(100.0, 'EUR', $taxasC) === 120.0 && Cotacao::naMoedaDoSite(100.0, 'USD', $taxasC) === 100.0 && Cotacao::naMoedaDoSite(100.0, 'GBP', $taxasC) === null);
Site::usarMoeda('COP');
$check('cotação: sem cotação da moeda do site não inventa', Cotacao::naMoedaDoSite(100.0, 'USD', $taxasC) === null && Cotacao::naMoedaDoSite(100.0, 'COP', $taxasC) === 100.0);
$check('moeda de valor baixo aceita valores altos', valorMaximo() === 1000000000.0);
Site::usarMoeda('BRL');
$check('real: o limite de sempre', valorMaximo() === 1000000.0);

// fuso: o deslocamento sai do PHP, com o horário de verão, e vale também na conexão
$quandoF = static fn(string $d): DateTimeImmutable => new DateTimeImmutable($d . ' 12:00:00 UTC');
$check('fuso: Brasília', Site::deslocamento('America/Sao_Paulo', $quandoF('2026-01-15')) === '-03:00' && Site::deslocamento('America/Sao_Paulo', $quandoF('2026-07-15')) === '-03:00');
$check('fuso: horário de verão', Site::deslocamento('America/New_York', $quandoF('2026-01-15')) === '-05:00' && Site::deslocamento('America/New_York', $quandoF('2026-07-15')) === '-04:00' && Site::deslocamento('Europe/Madrid', $quandoF('2026-07-15')) === '+02:00');
$check('fuso: minutos e extremos', Site::deslocamento('Asia/Kolkata', $quandoF('2026-01-15')) === '+05:30' && Site::deslocamento('Asia/Kathmandu', $quandoF('2026-01-15')) === '+05:45' && Site::deslocamento('Pacific/Kiritimati', $quandoF('2026-01-15')) === '+14:00' && Site::deslocamento('Pacific/Pago_Pago', $quandoF('2026-01-15')) === '-11:00');
$nomesF = DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC);
$check('fuso: o número guardado identifica um nome só', count(array_unique(array_map('crc32', $nomesF))) === count($nomesF));
$check('fuso: o número volta ao nome; o desconhecido vale Brasília', Site::fusoDoNumero(crc32('America/Mexico_City')) === 'America/Mexico_City' && Site::fusoDoNumero(1) === Site::FUSO_PADRAO && Site::fusoDoNumero(null) === Site::FUSO_PADRAO);
$check('fuso: inválido não passa', !Site::fusoValido('Marte/Olympus') && !Site::fusoValido("x' OR 1") && Site::fusoValido('America/Mexico_City') && !Site::moedaValida('XXX'));

// as categorias de fábrica são as de src/Preparo.php
preg_match_all("/^ +\['([^']+)', (?:'([^']+)'|null), '[A-Za-z ]+', -?1, [01]\],$/mu", (string) file_get_contents(__DIR__ . '/../src/Preparo.php'), $mCat, PREG_SET_ORDER);
$check('categorias: a lista de Preparo.php foi lida', count($mCat) >= 26, (string) count($mCat));
$foraDaLista = [];
foreach ($mCat as $m) {
    foreach (array_filter([$m[1], $m[2] ?? '']) as $nome) {
        if (!in_array($nome, CATEGORIAS_FABRICA, true)) {
            $foraDaLista[] = $nome;
        }
    }
}
$check('categorias: toda categoria de fábrica e apelido é traduzida ao mostrar', $foraDaLista === [], implode(', ', $foraDaLista));
$semTraducao = [];
foreach (['en', 'es'] as $idT) {
    Idioma::usar($idT);
    foreach (CATEGORIAS_FABRICA as $nome) {
        if (t($nome) === $nome && !in_array($nome, ['Internet', 'Delivery', 'Celular'], true)) {
            $semTraducao[] = "{$idT}: {$nome}";
        }
    }
}
$check('categorias: nenhuma fica em português em en/es', $semTraducao === [], implode(', ', $semTraducao));
Idioma::usar('en');
$check('categorias em en', nomeCategoria('Mercado') === 'Groceries' && nomeCategoria('IPVA') === 'Vehicle tax' && nomeCategoria('Licenciamento do carro') === 'Vehicle registration' && nomeCurto(['nome' => 'Aluguel e condomínio', 'apelido' => 'Aluguel']) === 'Rent');
$check('categorias em en: criada ou renomeada fica como está', nomeCategoria('Padaria da esquina') === 'Padaria da esquina' && nomeCurto(['nome' => 'Mercado', 'apelido' => 'Feira']) === 'Feira' && nomeCategoria('Salvar') === 'Salvar');
$check('categorias em en: a descrição automática acompanha; a escrita fica', descricaoTela('Mercado', ['nome' => 'Mercado', 'apelido' => null]) === 'Groceries' && descricaoTela('Pão', ['nome' => 'Mercado', 'apelido' => null]) === 'Pão' && descricaoTela('Mercado', ['nome' => 'Feira', 'apelido' => null]) === 'Mercado');
$check('categorias em en: o nome para gravar segue em português', nomeCurtoBanco(['nome' => 'Mercado', 'apelido' => null]) === 'Mercado');
$check('categorias em en: o motor acha pelo nome do banco', CategoriaModel::porNome('Salário') !== null && CategoriaModel::porNome(t('Salário')) === null);
$check('categorias em en: a busca acha pelo nome traduzido', in_array('Mercado', categoriasPorTraducao('grocer'), true) && categoriasPorTraducao('xyzxyz') === []);
Idioma::usar('es');
$check('categorias em es', nomeCategoria('Mercado') === 'Supermercado' && nomeCategoria('IPVA') === 'Impuesto del vehículo' && nomeCategoria('Licenciamento do carro') === 'Matrícula del vehículo');
$check('categorias em es: criada fica como está', nomeCategoria('Padaria da esquina') === 'Padaria da esquina');
Idioma::usar('pt');
$check('categorias em pt: sem nenhuma troca', array_filter(CATEGORIAS_FABRICA, static fn(string $n): bool => nomeCategoria($n) !== $n) === []);

// ---- comparação de texto, sem banco
$ordenados = ['-x', '1a', 'água', 'Bola', 'çã', 'Élan', 'zebra'];
$embaralhados = ['zebra', 'Élan', '1a', 'çã', 'Bola', '-x', 'água'];
usort($embaralhados, [\Financeiro\Colacao::class, 'comparar']);
$check('colação: símbolos, dígitos, letras', $embaralhados === $ordenados, implode(' ', $embaralhados));
$check('colação: acento e caixa são iguais', \Financeiro\Colacao::comparar('ÁGUA', 'agua') === 0 && \Financeiro\Colacao::comparar('Coração', 'coracao') === 0);
$check('colação: espaço no fim conta', \Financeiro\Colacao::comparar('a ', 'a') !== 0);
$check('like: curinga e escape', \Financeiro\Colacao::like('%CAFÉ%', 'um cafe bom') && \Financeiro\Colacao::like('a_c', 'açc') && !\Financeiro\Colacao::like('a!%', 'ab', '!') && \Financeiro\Colacao::like('a!%', 'a%', '!'));
$check('DECIMAL: meio para longe do zero', \Financeiro\Colacao::decimal(1.005, 2) === 1.01 && \Financeiro\Colacao::decimal('-2.675', 2) === -2.68 && \Financeiro\Colacao::decimal(0.00004, 4) === 0.0 && \Financeiro\Colacao::decimal(0.00005, 4) === 0.0001 && \Financeiro\Colacao::decimal(5.123456785, 8) === 5.12345679 && \Financeiro\Colacao::decimal(null, 2) === null);

// ---- primeira execução, Host e pasta de dados, sem rede
$h = [\Financeiro\Rede::class, 'hostAceito'];
$check('Host: no computador da pessoa, só o local', $h('localhost:8484', 'instalador', false, '', 'pc') && $h('127.0.0.1:8484', 'instalador', false, '', 'pc') && $h('[::1]:8484', 'instalador', false, '', 'pc'));
$check('Host: página de fora é recusada', !$h('atacante.example', 'instalador', false, '', 'pc') && !$h('atacante.example', 'instalador', true, '', 'pc') && !$h('', 'instalador', false, '', 'pc'));
$check('Host: IP da rede só com o acesso ligado', !$h('192.168.0.9:8484', 'instalador', false, '', 'pc') && $h('192.168.0.9:8484', 'instalador', true, '', 'pc') && $h('pc.local', 'instalador', true, '', 'pc'));
$check('Host: IP público é recusado mesmo com a rede ligada', !$h('8.8.8.8', 'instalador', true, '', 'pc'));
$check('Host: nome permitido', $h('financeiro.casa', 'instalador', false, 'financeiro.casa, outro', 'pc'));
$check('Host: modo servidor não muda sem lista', $h('qualquer.example', 'servidor', false, '', 'pc') && !$h('qualquer.example', 'servidor', false, 'financeiro.casa', 'pc') && $h('financeiro.casa', 'servidor', false, 'financeiro.casa', 'pc'));
$check('endereço local', \Financeiro\Rede::ehLocal('127.0.0.1') && \Financeiro\Rede::ehLocal('::1') && \Financeiro\Rede::ehLocal('::ffff:127.0.0.1') && !\Financeiro\Rede::ehLocal('192.168.0.9') && !\Financeiro\Rede::ehLocal(''));
$httpsAntes = $_SERVER['HTTPS'] ?? null;
unset($_SERVER['HTTPS']);
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
$check('X-Forwarded-Proto só vale se permitido', Config::get('CONFIAR_PROXY', '0') === '1' || !\Financeiro\Rede::https());
unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
$_SERVER['HTTPS'] = $httpsAntes;
$tudo = ['mbstring' => true, 'session' => true, 'zlib' => true, 'json' => true, 'ctype' => true, 'pdo' => true, 'pdo_sqlite' => true, 'pdo_mysql' => false];
$check('diagnóstico: com tudo, nada a dizer', \Financeiro\Preflight::faltando(80400, $tudo, 'sqlite') === []);
$check('diagnóstico: PHP antigo, extensão e banco', count(\Financeiro\Preflight::faltando(80300, $tudo, 'sqlite')) === 1 && count(\Financeiro\Preflight::faltando(80400, ['mbstring' => false] + $tudo, 'sqlite')) === 1 && count(\Financeiro\Preflight::faltando(80400, $tudo, 'mysql')) === 1 && count(\Financeiro\Preflight::faltando(80400, $tudo, 'oracle')) === 1);
$check('caminho absoluto', \Financeiro\Paths::absoluto('/a') && \Financeiro\Paths::absoluto('C:\\a') && \Financeiro\Paths::absoluto('C:/a') && !\Financeiro\Paths::absoluto('a/b'));
$remoteAntes = $_SERVER['REMOTE_ADDR'] ?? null;
$_SERVER['REMOTE_ADDR'] = '192.168.0.9';
$check('primeira conta: endereço de fora, mas a linha de comando é local', \Financeiro\Rede::requisicaoLocal());
$_SERVER['REMOTE_ADDR'] = $remoteAntes;

// ---- o endereço do repositório, num lugar só para o código; instalador e documentos têm de bater com ele
$repoT = \Financeiro\Atualizacao::repositorio();
$check('repositório: o arquivo diz dono/nome', (bool) preg_match('#^[\w.-]+/[\w.-]+$#', $repoT));
foreach (['instalar.sh', 'README.md', 'README.en.md', 'docs/INSTALACAO.md', '.github/ISSUE_TEMPLATE/config.yml'] as $arqT) {
    $caminhoT = __DIR__ . '/../' . $arqT;
    if (is_file($caminhoT)) {
        preg_match_all('#github\.com/([\w.-]+/[\w.-]+?)(?:\.git|/|\)|\s|")#', (string) file_get_contents($caminhoT), $mT);
        $outrosT = array_diff(array_unique($mT[1]), [$repoT]);
        $outrosT = array_filter($outrosT, static fn(string $o): bool => !preg_match('#^(actions|shivammathur|dunglas|php)/#', $o));
        $check('repositório: ' . $arqT . ' usa o mesmo endereço', $outrosT === [], implode(', ', $outrosT));
    }
}

// ---- pagamento de fatura na fatura certa e conta que veio mais barata (dados de mentira; o que grava é desfeito)
// cada pagamento vai para a fatura mais recente que já tinha fechado na data dele; se ela está toda paga, para a seguinte
$pgT = static fn(string $data, float $valor): array => ['data' => $data, 'valor' => $valor];
$check('fatura: paga no dia 31, a que vence no dia 2 fica com o pagamento (não a do mês da data)',
    CartaoFatura::atribuir([$pgT('2026-10-31', 1250.0)], 24, 2, ['2026-11-01' => 1250.0]) === ['2026-11-01']);
$check('fatura: paga atrasada continua na mesma fatura; depois do fechamento seguinte, vai para a que acabou de fechar',
    CartaoFatura::atribuir([$pgT('2026-11-05', 1250.0)], 24, 2, []) === ['2026-11-01']
    && CartaoFatura::atribuir([$pgT('2026-11-24', 900.0)], 24, 2, []) === ['2026-12-01']);
$check('fatura: com a fechada toda paga, o pagamento seguinte adianta a aberta',
    CartaoFatura::atribuir([$pgT('2026-10-31', 1250.0), $pgT('2026-11-10', 300.0)], 24, 2, ['2026-11-01' => 1250.0, '2026-12-01' => 900.0])
    === ['2026-11-01', '2026-12-01']);
$check('fatura: dois pagamentos parciais somam na mesma fatura, em ordem de data; completa, o seguinte adianta a aberta',
    CartaoFatura::atribuir(['b' => $pgT('2026-11-01', 550.0), 'a' => $pgT('2026-10-31', 700.0), 'c' => $pgT('2026-11-03', 100.0)], 24, 2, ['2026-11-01' => 1250.0])
    === ['b' => '2026-11-01', 'a' => '2026-11-01', 'c' => '2026-12-01']
    && CartaoFatura::atribuir(['x' => $pgT('2026-10-31', 1000.0), 'y' => $pgT('2026-10-30', 200.0)], 24, 2, ['2026-11-01' => 1000.0])
    === ['x' => '2026-11-01', 'y' => '2026-11-01']);
$check('fatura: centavos de diferença contam como toda paga; dez centavos não',
    CartaoFatura::atribuir([$pgT('2026-10-31', 1249.97), $pgT('2026-11-05', 100.0)], 24, 2, ['2026-11-01' => 1250.0]) === ['2026-11-01', '2026-12-01']
    && CartaoFatura::atribuir([$pgT('2026-10-31', 1249.90), $pgT('2026-11-05', 100.0)], 24, 2, ['2026-11-01' => 1250.0]) === ['2026-11-01', '2026-11-01']);
$check('fatura: fechamento no dia 31 em fevereiro fecha no último dia do mês (28 ou 29)',
    CartaoFatura::atribuir([$pgT('2027-02-27', 1.0), $pgT('2027-02-28', 1.0), $pgT('2028-02-28', 1.0), $pgT('2028-02-29', 1.0)], 31, 8, [])
    === ['2027-02-01', '2027-03-01', '2028-02-01', '2028-03-01']);
$check('fatura: paga no próprio dia do fechamento, vai para a fatura que fechou nesse dia',
    CartaoFatura::atribuir([$pgT('2026-10-24', 500.0)], 24, 2, []) === ['2026-11-01']
    && CartaoFatura::atribuir([$pgT('2026-10-23', 500.0)], 24, 2, []) === ['2026-10-01']);
$pagasT = CartaoFatura::porFaturaPaga([$pgT('2026-10-31', 1250.0)], 24, 2, ['2026-11-01' => 1250.0]);
$compR1 = [['id' => 9, 'valor_total' => 1250.0, 'parcelas' => 1, 'primeira_fatura' => '2026-11-01', 'descricao' => 'TV', 'data' => '2026-10-10']];
$cartR1 = ['fecha' => 24, 'vence' => 2, 'limite' => null];
$pnR1 = CartaoFatura::painel($cartR1, $compR1, '2026-11-03', array_map(static fn($f) => $f['pago'], $pagasT), [], array_map(static fn($f) => $f['ultimo'], $pagasT));
$check('fatura: no Cartão, a paga no dia 31 aparece paga, com a data, e não vencida', $pnR1['aberta'] === '2026-12-01'
    && $pnR1['faturas'][0]['mes'] === '2026-11-01' && $pnR1['faturas'][0]['status'] === 'paga' && $pnR1['faturas'][0]['pagaEm'] === '2026-10-31', json_encode($pnR1['faturas'][0]['status']));
$pnParc = CartaoFatura::painel($cartR1, $compR1, '2026-11-03', ['2026-11-01' => 700.0]);
$check('fatura: paga em parte, diz quanto falta', $pnParc['faturas'][0]['status'] === 'vencida' && abs((float) $pnParc['faturas'][0]['falta'] - 550.0) < 0.001
    && CartaoFatura::painel($cartR1, $compR1, '2026-11-01', ['2026-11-01' => 700.0])['faturas'][0]['status'] === 'fechada');

// o plano: a fatura de fevereiro foi paga em 31/01 e a de março em 28/02; nenhuma conta duas vezes
$catR = ['apelido' => null, 'essencial' => 1, 'ativo' => 1, 'ordem' => 0];
$dadosFat = [
    'meses' => [['mes' => '2030-01-01', 'situacao' => 'REAL'], ['mes' => '2030-02-01', 'situacao' => 'PREVISAO'],
                ['mes' => '2030-03-01', 'situacao' => 'PREVISAO'], ['mes' => '2030-04-01', 'situacao' => 'PREVISAO']],
    'aj' => ['saldo_ini' => 5000.0, 'poupanca_ini' => 0.0, 'reserva_minima' => 0.0, 'objetivo' => 3.0, 'meta_final' => 0.0],
    'loanIni' => 0.0, 'excecao' => [], 'metas' => [], 'n' => [], 'ult' => [],
    'cats' => [70 => ['id' => 70, 'nome' => 'Fatura do cartão', 'grupo' => 'Cartao', 'sinal' => -1, 'operacional' => 1] + $catR],
    'prev' => ['2030-02-01' => [70 => 1000.0], '2030-03-01' => [70 => 1200.0], '2030-04-01' => [70 => 900.0]],
    'real' => ['2030-01-01' => [70 => 1000.0], '2030-02-01' => [70 => 1200.0]],
    'esp' => ['cred' => null, 'amort' => null, 'guard' => null, 'resg' => null],
    'fatura' => ['cat' => 70, 'vence' => 2, 'faturas' => CartaoFatura::porFaturaPaga([$pgT('2030-01-31', 1000.0), $pgT('2030-02-28', 1200.0)], 24, 2,
                 ['2030-02-01' => 1000.0, '2030-03-01' => 1200.0])],
];
$pF = MotorFinanceiro::calcular($dadosFat, ['hoje' => '2030-02-28']);
$pSem = MotorFinanceiro::calcular(array_replace($dadosFat, ['fatura' => ['cat' => null, 'vence' => 0, 'faturas' => []]]), ['hoje' => '2030-02-28']);
$check('fatura: no plano, a paga num mês anterior não entra de novo no mês do vencimento (sem cartão, segue como antes)',
    abs($pF['2030-02-01']['sai'] - 1200.0) < 0.005 && abs($pF['2030-03-01']['sai']) < 0.005 && abs($pF['2030-04-01']['sai'] - 900.0) < 0.005
    && abs($pSem['2030-03-01']['sai'] - 1200.0) < 0.005, json_encode([array_column($pF, 'sai'), array_column($pSem, 'sai')]));
$dadosAtraso = array_replace($dadosFat, ['fatura' => ['cat' => 70, 'vence' => 2, 'faturas' => CartaoFatura::porFaturaPaga(
    [$pgT('2030-01-31', 1000.0), $pgT('2030-02-05', 300.0), $pgT('2030-03-28', 900.0)], 24, 2, ['2030-02-01' => 1000.0, '2030-03-01' => 1200.0])]]);
$pA = MotorFinanceiro::calcular(array_replace_recursive($dadosAtraso, ['real' => ['2030-02-01' => [70 => 300.0]]]), ['hoje' => '2030-02-10']);
$check('fatura: no plano, o adiantamento sai no mês em que foi pago e desconta da fatura seguinte; pagamento com data futura não desconta',
    abs($pA['2030-02-01']['sai'] - 300.0) < 0.005 && abs($pA['2030-03-01']['sai'] - 900.0) < 0.005 && abs($pA['2030-04-01']['sai'] - 900.0) < 0.005,
    json_encode(array_column($pA, 'sai')));

// "veio menos": o valor final de uma conta e de uma entrada (o salário chega sem a parcela); dia a dia e fatura paga não perguntam
$dAm = [
    'cats' => [1 => ['id' => 1, 'nome' => MotorFinanceiro::CAT_SALARIO, 'grupo' => 'Entradas', 'sinal' => 1, 'operacional' => 1] + $catR,
               5 => ['id' => 5, 'nome' => 'Luz', 'grupo' => 'Fixos', 'sinal' => -1, 'operacional' => 1] + $catR,
               10 => ['id' => 10, 'nome' => 'Mercado', 'grupo' => 'Dia a dia', 'sinal' => -1, 'operacional' => 1] + $catR,
               15 => ['id' => 15, 'nome' => 'Fatura do cartão', 'grupo' => 'Cartao', 'sinal' => -1, 'operacional' => 1] + $catR],
    'prev' => ['2030-01-01' => [1 => 8000.0, 5 => 180.0, 10 => 1500.0, 15 => 1250.0]],
    'real' => ['2030-01-01' => [1 => 7310.10, 5 => 150.0, 10 => 600.0, 15 => 2000.0]],
    'fatura' => ['cat' => 15, 'vence' => 2, 'faturas' => ['2030-01-01' => ['valor' => 1250.0, 'pago' => 1250.0, 'primeiro' => '2029-12-31', 'ultimo' => '2029-12-31', 'meses' => []]]],
];
$check('valor final: conta e entrada que vieram menos dão o valor final; dia a dia e fatura paga não perguntam',
    PrevisaoEdicao::aMenos($dAm, 5, '2030-01-01') === ['veio' => 150.0, 'previsto' => 180.0, 'final' => 150.0, 'parcela' => false]
    && PrevisaoEdicao::aMenos($dAm, 1, '2030-01-01', 289.90) === ['veio' => 7310.1, 'previsto' => 7710.1, 'final' => 7600.0, 'parcela' => true]
    && PrevisaoEdicao::aMenos($dAm, 10, '2030-01-01') === null && PrevisaoEdicao::aMenos($dAm, 15, '2030-01-01') === null
    && PrevisaoEdicao::veioMenos(170.0, 180.0) && !PrevisaoEdicao::veioMenos(179.97, 180.0) && !PrevisaoEdicao::veioMenos(0.0, 180.0));

$mesAtualT = date('Y-m-01');
$sitAtualT = array_column(MesModel::todos(), 'situacao', 'mes');
$catFatDb = CartaoModel::categoriaFatura();
if ($catFatDb !== null && ($sitAtualT[$mesAtualT] ?? '') === 'PREVISAO') {
    $emTransacao('fatura paga no fim do mês anterior', static function () use ($pdo, $check, $catFatDb, $mesAtualT, $uid, $render): void {
        $pdo->exec('UPDATE cartoes SET ativo = 0');                  // o cartão de verdade sai de cena até o rollback
        $pdo->prepare('DELETE FROM lancamentos WHERE categoria_id = ?')->execute([$catFatDb]);   // só o pagamento de teste conta
        AjusteModel::setValor('usa_cartao', 1.0);
        CartaoModel::salvar(null, 'Cartão teste', 28, 1, null);       // a fatura de M fecha no dia 28 do mês anterior e vence dia 1
        ContaRecorrenteModel::upsert($catFatDb, 1);
        PrevisaoModel::setValor($catFatDb, $mesAtualT, 800.0);
        $fimAnterior = date('Y-m-d', strtotime($mesAtualT . ' -1 day'));
        LancamentoModel::criar($fimAnterior, 'Fatura teste', $catFatDb, 800.0, $uid);
        MotorFinanceiro::limparCache();
        $st = array_values(array_filter(\Financeiro\Services\ChecklistContas::statusPorMes($mesAtualT), static fn($s) => (int) $s['categoria_id'] === $catFatDb))[0] ?? null;
        $check('fatura: no Mês, a paga no fim do mês anterior está paga, e não atrasada nem pendente', $st !== null
            && in_array($st['status'], ['pago', 'pago_atrasado'], true) && abs($st['pago'] - 800.0) < 0.01 && $st['data_pagamento'] === $fimAnterior, json_encode($st));
        $d = MotorFinanceiro::dados();
        $com = MotorFinanceiro::calcular($d)[$mesAtualT];
        $sem = MotorFinanceiro::calcular(array_replace($d, ['fatura' => ['cat' => null, 'vence' => 0, 'faturas' => []]]))[$mesAtualT];
        $check('fatura: no plano, a paga no mês anterior não entra de novo no mês do vencimento', abs($sem['sai'] - $com['sai'] - 800.0) < 0.01, json_encode([$sem['sai'], $com['sai']]));
        $html = $render(MesController::class, 'index', ['mes' => $mesAtualT]);
        preg_match('#id="cat-' . $catFatDb . '".*?</summary>#s', $html, $mL);
        $check('fatura: a linha do Mês diz quando foi paga e não pesa no mês', str_contains($mL[0] ?? '', 'mes-status-pago')
            && str_contains($mL[0] ?? '', 'Pago em ' . diaMes($fimAnterior)) && !str_contains($mL[0] ?? '', 'Venc'), substr($mL[0] ?? '', -300));
    });

    $emTransacao('valor final', static function () use ($pdo, $check, $mesAtualT, $uid, $render): void {
        $pdo->exec("INSERT INTO categorias (nome, grupo, sinal, ativo, ordem, essencial) VALUES ('Conta teste do valor final', 'Fixos', -1, 1, 999, 1)");
        $catC = (int) $pdo->lastInsertId();
        $catE = (int) CategoriaModel::criar('Entrada teste do valor final', 'entrada', false);
        ContaRecorrenteModel::upsert($catC, 28);
        $seg = null;
        foreach (MesModel::todos() as $m) {
            if ($seg === null && $m['mes'] > $mesAtualT && $m['situacao'] === 'PREVISAO') {
                $seg = (string) $m['mes'];
            }
        }
        foreach (array_filter([$mesAtualT, $seg]) as $m) {
            PrevisaoModel::setValor($catC, $m, 180.0);
            PrevisaoModel::setValor($catE, $m, 1000.0);
        }
        LancamentoModel::criar(\Financeiro\Relogio::hoje(), 'Conta teste', $catC, 150.0, $uid);
        LancamentoModel::criar(\Financeiro\Relogio::hoje(), 'Entrada teste', $catE, 900.0, $uid);
        MotorFinanceiro::limparCache();
        $html = $render(MesController::class, 'index', ['mes' => $mesAtualT]);
        preg_match('#<details class="mes-linha mes-ed" id="cat-' . $catC . '".*?</details>#s', $html, $mC);
        preg_match('#<details class="mes-linha mes-ed" id="cat-' . $catE . '".*?</details>#s', $html, $mE);
        $check('valor final: conta e entrada que vieram menos dizem quanto veio e perguntam se foi o valor final',
            str_contains($mC[0] ?? '', 'Veio 150,00 de 180,00 previstos') && str_contains($mC[0] ?? '', 'value="final"') && str_contains($mC[0] ?? '', 'Ainda falta pagar')
            && str_contains($mE[0] ?? '', 'Veio 900,00 de 1.000,00 previstos') && str_contains($mE[0] ?? '', 'Ainda falta receber'));
        $d = MotorFinanceiro::dados();
        $falta = MesController::valorFinal($d, $catC, $mesAtualT, 'falta', \Financeiro\Relogio::hoje());
        $check('valor final: "ainda falta pagar" não grava nada e diz o que o plano conta', $falta !== null && $falta['celulas'] === [] && str_contains($falta['aviso'], '30'));
        $antes = MotorFinanceiro::calcularPlano()[$mesAtualT];
        foreach ([[$catC, 150.0, 180.0, 'sai', 30.0], [$catE, 900.0, 1000.0, 'entra_bruto', 100.0]] as [$cat, $veio, $prevT, $campo, $dif]) {
            $fin = MesController::valorFinal(MotorFinanceiro::dados(), $cat, $mesAtualT, 'final', \Financeiro\Relogio::hoje());
            $lote = $fin !== null ? PrevisaoLote::gravar('manual', $fin['celulas'], $uid) : null;
            MotorFinanceiro::limparCache();
            $depois = MotorFinanceiro::calcularPlano()[$mesAtualT];
            $check("valor final ({$campo}): a previsão do mês vira o que veio, o plano fica exato e o mês seguinte não muda", $lote !== null
                && abs(PrevisaoModel::valor($cat, $mesAtualT) - $veio) < 0.001 && abs((float) $antes[$campo] - (float) $depois[$campo] - $dif) < 0.01
                && ($seg === null || abs(PrevisaoModel::valor($cat, $seg) - $prevT) < 0.001), json_encode([$antes[$campo], $depois[$campo]]));
            if ($cat === $catC) {
                $st = array_values(array_filter(\Financeiro\Services\ChecklistContas::statusPorMes($mesAtualT), static fn($s) => (int) $s['categoria_id'] === $catC))[0] ?? null;
                $check('valor final: a conta deixa de ser parcial', ($st['status'] ?? '') === 'pago' || ($st['status'] ?? '') === 'pago_atrasado', json_encode($st));
            }
            PrevisaoLote::desfazer((string) $lote, $uid);
            MotorFinanceiro::limparCache();
            $check("valor final ({$campo}): desfazer volta à previsão anterior", abs(PrevisaoModel::valor($cat, $mesAtualT) - $prevT) < 0.001);
        }
    });
}

// ---- sobra com o nome da base, frase do saldo, categoria em um toque, busca em período e planilha (dados de mentira)
$sbA = \Financeiro\Services\Sobra::de(['entra' => 7710.10, 'parcela_descontada' => 289.90, 'parcela_parte' => 0.0, 'sai' => 7688.20]);
$sbB = \Financeiro\Services\Sobra::de(['entra' => 8000.0, 'parcela_descontada' => 0.0, 'parcela_parte' => 289.90, 'sai' => 7688.20]);
$check('sobra: descontada do salário, a renda usada é a líquida e a ponte mostra o bruto',
    $sbA['bruto'] === 8000.0 && $sbA['parcela'] === 289.9 && $sbA['renda'] === 7710.1 && abs($sbA['sobra'] - 21.9) < 0.001
    && \Financeiro\Services\Sobra::ponte($sbA) === "8.000,00 \u{2212} 289,90 de parcela", json_encode($sbA));
$check('sobra: paga à parte, o salário inteiro entrou e a parcela saiu: a mesma sobra, sem ponte', $sbB['renda'] === 8000.0
    && abs($sbB['mov'] + 289.9) < 0.001 && abs($sbB['sobra'] - $sbA['sobra']) < 0.001 && \Financeiro\Services\Sobra::ponte($sbB) === null, json_encode($sbB));
$sbC = \Financeiro\Services\Sobra::de(['entra' => 5000.0, 'sai' => 4000.0, 'credito_emprestimo' => 2000.0, 'poupanca_liquido' => 500.0, 'amortizacao_extra' => 300.0]);
$check('sobra: balanço é balanço — crédito entra; poupança e adiantamento saem', abs($sbC['renda'] - 5000.0) < 0.001 && abs($sbC['mov'] - 1200.0) < 0.001 && abs($sbC['sobra'] - 2200.0) < 0.001, json_encode($sbC));
$check('sobra: sem parcela, sem ponte', \Financeiro\Services\Sobra::ponte(\Financeiro\Services\Sobra::de(['entra' => 100.0, 'sai' => 40.0])) === null);
$check('sobra: um nome para cada base', \Financeiro\Services\Sobra::rotulo('hoje') === 'Sobra até hoje' && \Financeiro\Services\Sobra::rotulo('prevista') === 'Sobra prevista' && \Financeiro\Services\Sobra::rotulo('mes') === 'Sobra do mês');

$mesSb = \Financeiro\Relogio::mes();
$catSb = (int) $pdo->query("SELECT id FROM categorias WHERE operacional = 1 AND sinal = -1 AND ativo = 1 AND grupo = 'Dia a dia' ORDER BY id LIMIT 1")->fetchColumn();
$resgSb = (int) $pdo->query("SELECT id FROM categorias WHERE operacional = 0 AND sinal = 1 ORDER BY id LIMIT 1")->fetchColumn();
if (MotorFinanceiro::linhaDoMes($mesSb) === null || $catSb === 0) {
    $check('sobra: o mesmo nome e o mesmo número nas telas', true, 'mês de hoje fora do plano ou sem categoria do dia a dia');
} else {
    $emTransacao('sobra nas telas', static function () use ($pdo, $check, $render, $catSb, $resgSb, $mesSb, $uid): void {
        $ins = $pdo->prepare('INSERT INTO lancamentos (data, descricao, categoria_id, valor, usuario_id) VALUES (?, ?, ?, ?, ?)');
        $ins->execute([\Financeiro\Relogio::hoje(), 'teste da sobra', $catSb, 123.45, $uid]);
        if ($resgSb > 0) {
            $ins->execute([\Financeiro\Relogio::hoje(), 'teste do resgate', $resgSb, 5000, $uid]);   // movimentação: entra no balanço, como o dono pediu
        }
        MotorFinanceiro::limparCache();
        \Financeiro\Services\GastosMensais::limparCache();
        $hojeSb = \Financeiro\Services\Sobra::ateHoje($mesSb);
        $prevSb = \Financeiro\Services\Sobra::prevista(MotorFinanceiro::linhaDoMes($mesSb));
        $acha = static function (string $html): array {
            preg_match_all('/data-sobra="(\w+)">([^<]+)</u', $html, $m, PREG_SET_ORDER);
            return array_map(static fn(array $x): array => [$x[1], trim(html_entity_decode($x[2]))], $m);
        };
        $ini = $acha($render(DashboardController::class, 'index', []));
        $mesH = $render(MesController::class, 'index', ['mes' => $mesSb]);
        $mesV = $acha($mesH);
        $fe = $acha($render(MesController::class, 'fecharForm', ['mes' => $mesSb]));
        $rl = RelatorioDados::mensal($mesSb);
        $check('sobra: Início mostra a sobra até hoje (com o resgate: balanço é balanço) com o nome dela',
            in_array([$hojeSb['base'], num($hojeSb['sobra'])], $ini, true), json_encode([$ini, $hojeSb]));
        $check('sobra: Fechar mostra a mesma sobra até hoje', in_array([$hojeSb['base'], num($hojeSb['sobra'])], $fe, true), json_encode($fe));
        $check('sobra: Mês mostra a sobra prevista, a mesma do plano', in_array([$prevSb['base'], num($prevSb['sobra'])], $mesV, true), json_encode($mesV));
        $check('sobra: as listas do Mês somam as saídas do resumo', str_contains($mesH, '<span class="card-acao num neg">' . num($prevSb['saidas']) . '</span>'));
        $check('sobra: o Relatório usa as mesmas', $rl !== null && ($rl['encerrado']
            ? abs($rl['sobrou'] - $prevSb['sobra']) < 0.005
            : abs($rl['sobras'][0]['sobra'] - $hojeSb['sobra']) < 0.005 && abs($rl['sobras'][1]['sobra'] - $prevSb['sobra']) < 0.005));
        $check('sobra: o nome antigo "Diferença" saiu do Mês', !str_contains($mesH, '>Diferença<'));
    });
}
$check('ajuda: a resposta dos números diz as bases', str_contains(implode(' ', array_column(array_merge(...array_column(\Financeiro\Ajuda::grupos(), 'itens')), 'resposta')), 'A sobra até hoje é o que entrou menos o que saiu até agora'));
$check('centavos: dinheiro com centavos no Mês e no Início', !preg_match('/class="mes-val num[^"]*">\d{1,3}(\.\d{3})*</', $render(MesController::class, 'index', [])));

// a frase do saldo: (previsão com o plano, sem adiantar, reserva)
$fr = static fn(?float $p, ?float $s, float $r): ?array => DashboardController::resposta($p, $s, $r);
$check('frase: acima da reserva, dá para gastar a folga', $fr(3000.0, 3000.0, 1500.0) === ['tipo' => 'gastar', 'valor' => 1500.0, 'adianta' => 0.0]);
$check('frase: abaixo da reserva, quanto falta', $fr(1200.0, 1200.0, 1500.0) === ['tipo' => 'abaixo', 'valor' => 300.0, 'adianta' => 0.0]);
$check('frase: no vermelho, quanto falta para zerar', $fr(-200.0, -200.0, 1500.0) === ['tipo' => 'vermelho', 'valor' => 200.0, 'adianta' => 0.0]);
$check('frase: o fim do mês igual à reserva porque a sobra adianta o empréstimo', $fr(1500.0, 4398.66, 1500.0) === ['tipo' => 'gastar', 'valor' => 2898.66, 'adianta' => 2898.66]);
$check('frase: o empréstimo leva só parte da folga', $fr(2000.0, 4000.0, 1500.0) === ['tipo' => 'gastar', 'valor' => 2500.0, 'adianta' => 2000.0]);
$check('frase: sem previsão, sem frase', $fr(null, null, 1500.0) === null);
$htmlFr = $render(DashboardController::class, 'index', []);
$check('frase: o cartão do saldo tem uma frase e o resto em "Ver detalhes"', !str_contains($htmlFr, 'data-previsao-fim') || (str_contains($htmlFr, 'data-saldo-frase=') && preg_match('/<details class="saldo-det">\s*<summary>Ver detalhes<\/summary>.*data-previsao-fim/s', $htmlFr) === 1));

// categoria em um toque
LancarForm::limparCache();
$lf = LancarForm::dados();
$tipo = static function (int $id) use ($pdo): int {
    $c = $pdo->query('SELECT sinal, operacional FROM categorias WHERE id = ' . $id)->fetch();
    return (int) $c['operacional'] === 0 ? 2 : ((int) $c['sinal'] === 1 ? 1 : 0);
};
$tiposRap = array_map(static fn(array $c): int => $tipo((int) $c['id']), $lf['rapidas']);
$check('categorias: de 1 a 8 botões, gastos antes de entradas', $lf['rapidas'] !== [] && count($lf['rapidas']) <= LancarForm::RAPIDAS && $tiposRap === (static function (array $a): array { sort($a); return $a; })($tiposRap), json_encode($tiposRap));
$chavesLf = array_keys($lf['outras']);
$check('categorias: a lista completa começa pelos gastos', array_search('entradas', $chavesLf, true) === false || array_search('entradas', $chavesLf, true) > 0);
$htmlLf = $render(LancamentosController::class, 'lancarForm', []);
$check('categorias: botões de rádio com ícone, "Mais…" com a lista e a busca, sem o select',
    substr_count($htmlLf, 'data-lf-rapida') === count($lf['rapidas']) && str_contains($htmlLf, 'data-lf-mais') && str_contains($htmlLf, 'data-lf-busca hidden')
    && str_contains($htmlLf, '<fieldset class="lf-cats" aria-labelledby=') && str_contains($htmlLf, 'class="lf-chip-c"><span class="ci') && !str_contains($htmlLf, '<select name="categoria_id"'));
$poucoUsada = (int) $pdo->query("SELECT id FROM categorias WHERE ativo = 1 AND operacional = 1 AND sinal = -1 ORDER BY ordem DESC, id DESC LIMIT 1")->fetchColumn();
if ($poucoUsada > 0) {
    $emTransacao('categorias: as mais usadas', static function () use ($pdo, $check, $poucoUsada, $uid): void {
        $ins = $pdo->prepare('INSERT INTO lancamentos (data, descricao, categoria_id, valor, usuario_id) VALUES (?, ?, ?, 1, ?)');
        for ($i = 0; $i < 40; $i++) {
            $ins->execute([\Financeiro\Relogio::hoje(), 'teste freq', $poucoUsada, $uid]);
        }
        LancarForm::limparCache();
        $ids = array_map(static fn(array $c): int => (int) $c['id'], LancarForm::dados()['rapidas']);
        $check('categorias: a mais usada vira botão, primeiro entre os gastos', ($ids[0] ?? 0) === $poucoUsada, json_encode($ids));
    });
    LancarForm::limparCache();
}

// busca em período, com total, e a planilha
$catBusca = (int) $pdo->query('SELECT id FROM categorias WHERE operacional = 1 AND sinal = -1 ORDER BY id LIMIT 1')->fetchColumn();
$emTransacao('busca em período', static function () use ($pdo, $check, $render, $catBusca, $uid): void {
    $ins = $pdo->prepare('INSERT INTO lancamentos (data, descricao, categoria_id, valor, usuario_id) VALUES (?, ?, ?, ?, ?)');
    $ins->execute(['2001-03-05', 'zzbusca; "aspas"', $catBusca, 10.50, $uid]);
    $ins->execute(['2001-07-09', 'zzbusca =SOMA(1)', $catBusca, 20.25, $uid]);
    $ins->execute(['2002-01-01', 'zzbusca outra', $catBusca, 30.00, $uid]);
    $ins->execute(['2001-07-09', '=zzbusca fórmula', $catBusca, 1.00, $uid]);
    $mesB = \Financeiro\Relogio::mes();
    $t2001 = LancamentoModel::totais($mesB, ['q' => 'zzbusca', 'periodo' => ['de' => '2001-01-01', 'ate' => '2001-12-31']]);
    $check('busca: num período, com o total', $t2001['n'] === 3 && abs($t2001['gasto'] - 31.75) < 0.001, json_encode($t2001));
    $check('busca: em todos os meses', LancamentoModel::totais($mesB, ['q' => 'zzbusca', 'periodo' => ['de' => null, 'ate' => null]])['n'] === 4);
    $check('busca: o mês continua o padrão', LancamentoModel::totais($mesB, ['q' => 'zzbusca'])['n'] === 0);
    $check('busca: valor mínimo e máximo', LancamentoModel::totais($mesB, ['q' => 'zzbusca', 'periodo' => ['de' => null, 'ate' => null], 'min' => 15.0])['n'] === 2
        && LancamentoModel::totais($mesB, ['q' => 'zzbusca', 'periodo' => ['de' => null, 'ate' => null], 'min' => 10.5, 'max' => 20.25])['n'] === 2);
    $check('busca: os filtros de antes continuam (categoria, quem, ordem)', count(LancamentoModel::listar($mesB, ['q' => 'zzbusca', 'categoria_id' => $catBusca, 'usuario_id' => $uid, 'ordem' => 'valor-desc', 'periodo' => ['de' => null, 'ate' => null], 'limite' => 2])) === 2
        && (float) LancamentoModel::listar($mesB, ['q' => 'zzbusca', 'ordem' => 'valor-desc', 'periodo' => ['de' => null, 'ate' => null], 'limite' => 1])[0]['valor'] === 30.0);
    $lotes = array_map(static fn(array $r): string => $r['data'] . '#' . number_format((float) $r['valor'], 2, '.', ''), iterator_to_array(LancamentoModel::emLotes($mesB, ['q' => 'zzbusca', 'periodo' => ['de' => null, 'ate' => null]], 1), false));
    $check('busca: a planilha lê em lotes, do mais antigo ao mais novo, sem repetir', count($lotes) === 4 && count(array_unique($lotes)) === 4 && $lotes[0] === '2001-03-05#10.50' && $lotes[3] === '2002-01-01#30.00', json_encode($lotes));
    $htmlB = $render(LancamentosController::class, 'index', ['periodo' => 'ano', 'mes' => '2001-01-01', 'q' => 'zzbusca']);
    $htmlT = $render(LancamentosController::class, 'index', ['periodo' => 'tudo', 'q' => 'zzbusca']);
    $check('busca: a tela responde com o total no topo', str_contains($htmlT, 'data-ex-total="gasto">' . e(money(61.75)) . '<') && str_contains($htmlT, 'href="/lancamentos/planilha?'));
    $check('busca: o período pedido vale (datas)', str_contains($render(LancamentosController::class, 'index', ['periodo' => 'datas', 'de' => '2001-07-01', 'ate' => '2001-07-31', 'q' => 'zzbusca']), 'data-ex-total="gasto">' . e(money(21.25)) . '<'));
    $check('busca: valor que não dá para ler é avisado', str_contains($render(LancamentosController::class, 'index', ['periodo' => 'tudo', 'min' => 'abc']), 'id="ex-erro-valor"'));
    unset($htmlB);

    // a planilha: BOM, ";" e vírgula decimal, aspas em descrição com ";", fórmula desarmada
    $h = fopen('php://memory', 'w+b');
    $n = \Financeiro\Services\Planilha::escrever(LancamentoModel::emLotes($mesB, ['q' => 'zzbusca', 'periodo' => ['de' => null, 'ate' => null]]), $h);
    rewind($h);
    $csv = (string) stream_get_contents($h);
    fclose($h);
    $check('planilha: UTF-8 com BOM e cabeçalho com ";"', str_starts_with($csv, "\u{FEFF}Data;Descrição;Categoria;Valor;\"Quem lançou\"\r\n") || str_starts_with($csv, "\u{FEFF}Data;Descrição;Categoria;Valor;Quem lançou\r\n"), substr($csv, 0, 80));
    $check('planilha: valor com sinal e vírgula decimal', $n === 4 && str_contains($csv, '2001-03-05;') && str_contains($csv, ';-10,50;') && str_contains($csv, ';-20,25;'));
    $check('planilha: descrição com ";" vai entre aspas', str_contains($csv, ';"zzbusca; ""aspas""";'));
    $check('planilha: texto que começa com "=" não vira fórmula', str_contains($csv, ";\"'=zzbusca fórmula\";"));
    $check('planilha: o nome do arquivo diz o período', \Financeiro\Services\Planilha::nomeArquivo('ano', '2026-11-01', null, null) === 'lancamentos-2026.csv'
        && \Financeiro\Services\Planilha::nomeArquivo('', '2026-11-01', null, null) === 'lancamentos-2026-11.csv'
        && \Financeiro\Services\Planilha::nomeArquivo('datas', '2026-11-01', '2026-01-01', '2026-03-31') === 'lancamentos-2026-01-01-a-2026-03-31.csv'
        && \Financeiro\Services\Planilha::nomeArquivo('tudo', '2026-11-01', null, null) === 'lancamentos-todos-os-meses.csv');
    $_GET = ['periodo' => 'tudo', 'q' => 'zzbusca'];
    ob_start();
    (new LancamentosController())->planilha();
    $csvRota = (string) ob_get_clean();
    $_GET = [];
    $check('planilha: a rota entrega o resultado filtrado', str_starts_with($csvRota, "\u{FEFF}") && substr_count($csvRota, "\r\n") === 5);
});

echo "\n{$ok} ok, {$falhas} falha(s)\n";
exit($falhas > 0 ? 1 : 0);
