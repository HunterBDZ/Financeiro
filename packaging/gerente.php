<?php
/**
 * O Financeiro no computador da pessoa, em Windows e Mac: mesmo papel de packaging/linux/financeiro.
 *   abrir            abre no navegador, iniciando se preciso
 *   iniciar, parar   liga ou desliga
 *   status           diz se está no ar e onde
 *   senha LOGIN      define uma senha nova para essa conta
 *   servir           roda em primeiro plano e reinicia o programa se ele cair: é o que o início automático chama
 *
 * Fica dentro de app, para acompanhar as atualizações, e só usa o que vem no PHP: no Windows, roda sem php.ini.
 */
declare(strict_types=1);

const GERENTE_ROTULO = 'io.github.hunterbdz.financeiro';
const GERENTE_PORTA = 8484;
const GERENTE_LOG_MAXIMO = 5242880;

/** @return array{programa:string, dados:string, porta:int} */
function gerenteLugares(): array
{
    require_once __DIR__ . '/../src/Config.php';
    require_once __DIR__ . '/../src/Paths.php';
    $dados = (string) Financeiro\Paths::dados();
    if ($dados === '') {
        fwrite(STDERR, "Não achei uma pasta para guardar os dados.\n");
        exit(1);
    }
    $programa = dirname(__DIR__, 2);
    return ['programa' => $programa, 'dados' => $dados, 'porta' => gerentePorta($dados)];
}

/** A porta fica com a pasta de dados: uma instalação nova mantém o endereço que o celular já conhece. */
function gerentePorta(string $dados): int
{
    $salva = (int) @file_get_contents($dados . '/porta');
    if ($salva > 0) {
        return $salva;
    }
    for ($p = GERENTE_PORTA; $p < GERENTE_PORTA + 100; $p++) {
        if (!gerenteNoAr($p)) {
            @mkdir($dados, 0700, true);
            file_put_contents($dados . '/porta', (string) $p);
            return $p;
        }
    }
    fwrite(STDERR, "Não achei uma porta livre.\n");
    exit(1);
}

function gerenteNoAr(int $porta): bool
{
    $s = @fsockopen('127.0.0.1', $porta, $erro, $texto, 1);
    if ($s === false) {
        return false;
    }
    fclose($s);
    return true;
}

/** @return array{servidor:string, php:list<string>} os programas, conforme o sistema */
function gerenteBinarios(string $programa, string $sistema = PHP_OS_FAMILY): array
{
    if ($sistema === 'Windows') {
        return ['servidor' => $programa . '/bin/frankenphp.exe', 'php' => [$programa . '/bin/php.exe']];
    }
    $bin = getenv('FIN_BIN');
    $bin = is_string($bin) && $bin !== '' ? $bin : PHP_BINARY;
    return ['servidor' => $bin, 'php' => [$bin, 'php-cli']];
}

/** @return array<string, string> o ambiente de tudo que o gerente inicia */
function gerenteAmbiente(array $lugar, string $sistema = PHP_OS_FAMILY): array
{
    $bin = gerenteBinarios($lugar['programa'], $sistema);
    $env = array_filter(getenv(), 'is_string');
    unset($env['FIN_SEMENTE'], $env['PHPRC'], $env['PHP_INI_SCAN_DIR']);
    $env['FIN_DADOS'] = $lugar['dados'];
    $env['FIN_PORTA'] = (string) $lugar['porta'];
    $env['FIN_APP'] = str_replace('\\', '/', $lugar['programa'] . '/app');
    $env['FIN_GERENCIADO'] = '1';
    $env['FIN_PHP'] = implode("\t", $bin['php']) . "\t";    // com tabulação: o caminho pode ter espaços
    $env['XDG_CONFIG_HOME'] = $lugar['dados'] . '/caddy';
    $env['XDG_DATA_HOME'] = $lugar['dados'] . '/caddy';
    if ($sistema === 'Windows') {
        $env['PHPRC'] = '..\\conf';     // relativo à pasta bin: ver gerentePastaAtual
    } else {
        $env['PHP_INI_SCAN_DIR'] = $lugar['programa'] . '/conf';
    }
    return $env;
}

/** A pasta atual do que o gerente inicia. No Windows, é a bin: o php.ini e as extensões são relativos a ela, por causa dos acentos. */
function gerentePastaAtual(array $lugar): string
{
    return PHP_OS_FAMILY === 'Windows' ? $lugar['programa'] . '/bin' : $lugar['dados'];
}

/** Só a tela de Usar no celular liga isto. */
function gerenteEndereco(string $dados): string
{
    $config = (string) @file_get_contents($dados . '/config.env');
    return preg_match('/^ACESSO_REDE=1\s*$/m', $config) === 1 ? '0.0.0.0' : '127.0.0.1';
}

function gerenteUrl(int $porta): string
{
    return 'http://localhost:' . $porta;
}

function gerenteMarcar(string $arquivo): void
{
    @touch($arquivo);
}

/** Em primeiro plano, até pedirem para parar. Um só por pasta de dados: o segundo sai sem erro. */
function gerenteServir(array $lugar): int
{
    $dados = $lugar['dados'];
    @mkdir($dados . '/log', 0700, true);
    chdir($dados);     // esta pasta não muda; a do programa é trocada numa atualização
    $trava = fopen($dados . '/programa.trava', 'c');
    if ($trava === false || !flock($trava, LOCK_EX | LOCK_NB)) {
        return 0;
    }
    @unlink($dados . '/parar');
    $log = $dados . '/log/programa.log';
    if (is_file($log) && filesize($log) > GERENTE_LOG_MAXIMO) {
        @unlink($log);
    }
    $bin = gerenteBinarios($lugar['programa']);
    $config = $lugar['programa'] . '/app/packaging/Caddyfile';
    $nulo = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $ligado = true;
    gerenteOuvirSinais($ligado);
    while (true) {
        @unlink($dados . '/reiniciar');
        $env = gerenteAmbiente($lugar);
        $env['FIN_BIND'] = gerenteEndereco($dados);
        $proc = proc_open([$bin['servidor'], 'run', '--config', $config], [0 => ['file', $nulo, 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $tubos, gerentePastaAtual($lugar), $env);
        if (!is_resource($proc)) {
            gerenteDormir($dados, 3);
            if (is_file($dados . '/parar')) {
                break;
            }
            continue;
        }
        $st = proc_get_status($proc);
        file_put_contents($dados . '/programa.pid', getmypid() . ' ' . (int) $st['pid']);
        while (proc_get_status($proc)['running']) {
            clearstatcache();
            if (!$ligado || is_file($dados . '/parar') || is_file($dados . '/reiniciar')) {
                proc_terminate($proc);
                break;
            }
            sleep(1);
        }
        proc_close($proc);
        if (!$ligado || is_file($dados . '/parar')) {
            break;
        }
        if (!is_file($dados . '/reiniciar')) {
            gerenteDormir($dados, 3);      // caiu sozinho (a memória acabou, por exemplo): volta; porta ocupada: tenta devagar
            if (is_file($dados . '/parar')) {
                break;
            }
        }
    }
    @unlink($dados . '/parar');
    @unlink($dados . '/programa.pid');
    flock($trava, LOCK_UN);
    fclose($trava);
    return 0;
}

/** O launchd e o terminal pedem para sair com um sinal; o Windows não tem isso, e lá vale o arquivo parar. */
function gerenteOuvirSinais(bool &$ligado): void
{
    if (function_exists('pcntl_async_signals') && defined('SIGTERM')) {
        pcntl_async_signals(true);
        $sair = static function () use (&$ligado): void {
            $ligado = false;
        };
        pcntl_signal(SIGTERM, $sair);
        pcntl_signal(SIGINT, $sair);
    }
}

function gerenteDormir(string $dados, int $segundos): void
{
    for ($i = 0; $i < $segundos && !is_file($dados . '/parar'); $i++) {
        sleep(1);
    }
}

/** Liga em segundo plano e espera abrir. */
function gerenteIniciar(array $lugar): bool
{
    if (gerenteNoAr($lugar['porta'])) {
        return true;
    }
    @mkdir($lugar['dados'] . '/log', 0700, true);
    @unlink($lugar['dados'] . '/parar');
    gerenteLancar($lugar);
    for ($i = 0; $i < 60; $i++) {
        if (gerenteNoAr($lugar['porta'])) {
            return true;
        }
        usleep(500000);
    }
    return false;
}

function gerenteLancar(array $lugar): void
{
    $script = $lugar['programa'] . '/gerente.php';
    $env = gerenteAmbiente($lugar);
    if (PHP_OS_FAMILY === 'Windows') {
        // ele inicia o gerente com um console próprio, invisível, que continua depois que este processo sair
        $proc = proc_open([$lugar['programa'] . '/financeiro.exe', 'servir'], [], $tubos, gerentePastaAtual($lugar), $env);
        if (is_resource($proc)) {
            proc_close($proc);
        }
        return;
    }
    if (PHP_OS_FAMILY === 'Darwin' && gerenteAgenteLigar($lugar)) {
        return;
    }
    $bin = gerenteBinarios($lugar['programa']);
    $partes = [...$bin['php'], $script, 'servir'];
    $log = $lugar['dados'] . '/log/programa.log';
    $proc = proc_open('nohup ' . implode(' ', array_map('escapeshellarg', $partes)) . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null &', [], $tubos, $lugar['dados'], $env);
    if (is_resource($proc)) {
        proc_close($proc);
    }
}

function gerenteParar(array $lugar): void
{
    $dados = $lugar['dados'];
    $pids = array_filter(array_map('intval', explode(' ', trim((string) @file_get_contents($dados . '/programa.pid')))));
    if (!gerenteNoAr($lugar['porta']) && $pids === []) {
        return;
    }
    gerenteMarcar($dados . '/parar');
    for ($i = 0; $i < 40; $i++) {
        clearstatcache();
        if (!is_file($dados . '/programa.pid') && !gerenteNoAr($lugar['porta'])) {
            @unlink($dados . '/parar');
            return;
        }
        usleep(500000);
    }
    // quem rodava morreu sem limpar a marca, ou não responde: só os processos deste programa
    foreach ($pids as $pid) {
        gerenteMatar($pid);
    }
    @unlink($dados . '/parar');
    @unlink($dados . '/programa.pid');
}

function gerenteMatar(int $pid): void
{
    $cmd = PHP_OS_FAMILY === 'Windows' ? ['taskkill', '/F', '/T', '/PID', (string) $pid] : ['kill', (string) $pid];
    $nulo = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $proc = proc_open($cmd, [0 => ['file', $nulo, 'r'], 1 => ['file', $nulo, 'w'], 2 => ['file', $nulo, 'w']], $tubos);
    if (is_resource($proc)) {
        proc_close($proc);
    }
}

function gerenteNavegador(string $url): void
{
    if (getenv('FIN_SEM_NAVEGADOR') === '1') {
        return;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        // em lista, o PHP põe cada parte entre aspas, e o rundll32 não abre "url.dll,FileProtocolHandler" com aspas;
        // a URL não tem espaço nem aspas: a porta é número, e o caminho do arquivo vai codificado
        $proc = @proc_open('rundll32 url.dll,FileProtocolHandler ' . $url, [], $tubos, null, null, ['bypass_shell' => true]);
    } else {
        $proc = @proc_open([PHP_OS_FAMILY === 'Darwin' ? 'open' : 'xdg-open', $url], [], $tubos);
    }
    if (is_resource($proc)) {
        proc_close($proc);
    }
}

/** Sem janela para mostrar erro: a explicação abre no navegador, nos três idiomas do app. */
function gerenteFalhou(array $lugar): void
{
    $log = str_replace('\\', '/', $lugar['dados'] . '/log/programa.log');
    $html = '<!doctype html><meta charset="utf-8"><title>MyMonetto</title><body style="font:16px/1.5 system-ui;max-width:40em;margin:3em auto;padding:0 1em">'
        . '<h1>O MyMonetto não abriu</h1><p>Reinicie o computador e tente de novo. Se continuar, os detalhes estão em:</p>'
        . '<h1 lang="en">MyMonetto did not open</h1><p lang="en">Restart the computer and try again. If it keeps happening, the details are in:</p>'
        . '<h1 lang="es">MyMonetto no se abrió</h1><p lang="es">Reinicie el equipo e inténtelo de nuevo. Si continúa, los detalles están en:</p>'
        . '<p><code>' . htmlspecialchars($log) . '</code></p>';
    $arquivo = str_replace('\\', '/', $lugar['dados']) . '/nao-abriu.html';
    file_put_contents($arquivo, $html);
    gerenteNavegador((PHP_OS_FAMILY === 'Windows' ? 'file:///' : 'file://') . str_replace(['%2F', '%3A'], ['/', ':'], rawurlencode($arquivo)));
}

// ---- só no Mac: o programa sai do .app para poder ser atualizado pela tela sem quebrar a assinatura dele

/** O caminho do .plist do início automático. */
function gerenteAgenteArquivo(): string
{
    return getenv('HOME') . '/Library/LaunchAgents/' . GERENTE_ROTULO . '.plist';
}

/** @param list<string> $argumentos */
function gerenteAgenteXml(array $argumentos, string $bin, string $dados): string
{
    $x = static fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $args = implode('', array_map(static fn(string $a): string => "\n        <string>" . $x($a) . '</string>', $argumentos));
    return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
        <plist version="1.0">
        <dict>
            <key>Label</key>
            <string>{$x(GERENTE_ROTULO)}</string>
            <key>ProgramArguments</key>
            <array>{$args}
            </array>
            <key>EnvironmentVariables</key>
            <dict>
                <key>FIN_BIN</key>
                <string>{$x($bin)}</string>
            </dict>
            <key>WorkingDirectory</key>
            <string>{$x($dados)}</string>
            <key>RunAtLoad</key>
            <true/>
            <key>KeepAlive</key>
            <dict>
                <key>SuccessfulExit</key>
                <false/>
            </dict>
        </dict>
        </plist>

        XML;
}

function gerenteLaunchctl(string ...$args): bool
{
    $proc = proc_open(['launchctl', ...$args], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $tubos);
    return is_resource($proc) && proc_close($proc) === 0;
}

function gerenteAgenteLigar(array $lugar): bool
{
    if (getenv('HOME') === false) {
        return false;
    }
    $bin = gerenteBinarios($lugar['programa']);
    $xml = gerenteAgenteXml([...$bin['php'], $lugar['programa'] . '/gerente.php', 'servir'], $bin['servidor'], $lugar['dados']);
    $arquivo = gerenteAgenteArquivo();
    $alvo = 'gui/' . trim((string) shell_exec('id -u'));
    @mkdir(dirname($arquivo), 0755, true);
    if (@file_get_contents($arquivo) !== $xml) {
        gerenteLaunchctl('bootout', $alvo . '/' . GERENTE_ROTULO);
        file_put_contents($arquivo, $xml);
    }
    gerenteLaunchctl('bootstrap', $alvo, $arquivo);
    return gerenteLaunchctl('kickstart', $alvo . '/' . GERENTE_ROTULO);
}

function gerenteCopiar(string $de, string $para): void
{
    if (!is_dir($para) && !mkdir($para, 0755, true) && !is_dir($para)) {
        throw new RuntimeException('não deu para criar ' . $para);
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($de, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $i) {
        $alvo = $para . '/' . substr($i->getPathname(), strlen($de) + 1);
        if ($i->isDir()) {
            if (!is_dir($alvo) && !mkdir($alvo, 0755, true) && !is_dir($alvo)) {
                throw new RuntimeException('não deu para criar ' . $alvo);
            }
        } elseif (!copy($i->getPathname(), $alvo)) {
            throw new RuntimeException('não deu para copiar ' . $alvo);
        }
    }
}

function gerenteApagar(string $pasta): void
{
    if (!is_dir($pasta) || is_link($pasta)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pasta, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $i) {
        $i->isDir() && !$i->isLink() ? @rmdir($i->getPathname()) : @unlink($i->getPathname());
    }
    @rmdir($pasta);
}

/**
 * Leva o programa do .app para a pasta de dados, se ele for mais novo do que o que já está lá.
 * @param array{programa:string, dados:string, porta:int} $lugar  programa = onde o .app guarda a cópia
 * @return array{programa:string, dados:string, porta:int} o mesmo, apontando para a cópia em uso
 */
function gerenteInstalarDoApp(array $lugar): array
{
    $semente = $lugar['programa'];
    $destino = $lugar['dados'] . '/programa';
    $nova = trim((string) file_get_contents($semente . '/app/VERSION'));
    $atual = trim((string) @file_get_contents($destino . '/app/VERSION'));
    $usar = ['programa' => $destino] + $lugar;
    if ($atual !== '' && version_compare($nova, $atual, '<=')) {
        return $usar;
    }
    gerenteParar($usar);
    gerenteApagar($destino . '.novo');
    gerenteCopiar($semente, $destino . '.novo');
    gerenteApagar($destino . '.antes');
    if (is_dir($destino) && !rename($destino, $destino . '.antes')) {
        throw new RuntimeException('não deu para trocar o programa');
    }
    if (!rename($destino . '.novo', $destino)) {
        throw new RuntimeException('não deu para trocar o programa');
    }
    gerenteApagar($destino . '.antes');
    return $usar;
}

/** O ponto de entrada. @param list<string> $argv */
function gerente(array $argv): int
{
    $comando = $argv[1] ?? 'abrir';
    $lugar = gerenteLugares();
    if (getenv('FIN_SEMENTE') === '1') {
        $lugar = gerenteInstalarDoApp($lugar);
        // o .app pode ter mudado de lugar: o início automático passa a apontar para onde ele está agora
        if (PHP_OS_FAMILY === 'Darwin' && in_array($comando, ['abrir', 'iniciar'], true)) {
            gerenteAgenteLigar($lugar);
        }
    }
    switch ($comando) {
        case 'abrir':
            if (!gerenteIniciar($lugar)) {
                gerenteFalhou($lugar);
                return 1;
            }
            gerenteNavegador(gerenteUrl($lugar['porta']));
            return 0;
        case 'servir':
            return gerenteServir($lugar);
        case 'iniciar':
            $ok = gerenteIniciar($lugar);
            echo $ok ? 'O MyMonetto está em ' . gerenteUrl($lugar['porta']) . "\n" : "O MyMonetto não abriu.\n";
            return $ok ? 0 : 1;
        case 'parar':
            gerenteParar($lugar);
            echo "O MyMonetto parou.\n";
            return 0;
        case 'status':
            echo gerenteNoAr($lugar['porta']) ? 'No ar em ' . gerenteUrl($lugar['porta']) . "\n" : "Parado.\n";
            echo 'Dados em ' . $lugar['dados'] . "\n";
            return 0;
        case 'senha':
            if (!isset($argv[2])) {
                echo "Use: financeiro senha LOGIN\n";
                return 2;
            }
            $bin = gerenteBinarios($lugar['programa']);
            $proc = proc_open([...$bin['php'], $lugar['programa'] . '/app/tools/redefinir-senha.php', $argv[2]], [], $tubos, gerentePastaAtual($lugar), gerenteAmbiente($lugar));
            return is_resource($proc) ? proc_close($proc) : 1;
        default:
            echo "Use: financeiro abrir, iniciar, parar, status ou senha LOGIN\n";
            return 2;
    }
}

if (!defined('FIN_GERENTE_SO_FUNCOES')) {
    exit(gerente($argv));
}
