<?php
namespace Financeiro;

/**
 * Atualiza o programa pela tela, no pacote: mesmo desenho do financeiro-atualizar. Baixa e confere a assinatura ->
 * ensaia numa cópia do banco, com o site intacto -> backup -> migrações -> troca a pasta -> confere. Falhou no ensaio:
 * nada mudou. Falhou depois do backup: o programa e o banco voltam sozinhos.
 *
 * O que a versão diz de si vem em atualizacao.json, assinado com uma chave que fica fora do repositório
 * (tools/assinar-atualizacao.php); a chave pública é a de baixo. Só consulta quando a pessoa pede.
 */
final class Atualizacao
{
    public const CHAVE_PUBLICA = 'A2DTdReIkziJxTO9vEh7CVpfRYZY55wDBxhQsspo4Kc=';
    private const MAXIMO = 104857600;
    private const TEMPO_ENSAIO = 420;

    /** O repositório, num lugar só: o arquivo REPOSITORIO. */
    public static function repositorio(): string
    {
        return trim((string) @file_get_contents(Paths::raiz() . '/REPOSITORIO'));
    }

    public static function versaoAtual(): string
    {
        return trim((string) @file_get_contents(Paths::raiz() . '/VERSION'));
    }

    /** pacote = atualiza pela tela; docker e servidor têm o próprio comando; dev = pasta de quem desenvolve. */
    public static function tipo(): string
    {
        $marca = dirname(Paths::raiz()) . '/PACOTE';
        if (is_file($marca) && basename(Paths::raiz()) === 'app') {
            return 'pacote';
        }
        if (is_file(Paths::raiz() . '/DOCKER')) {
            return 'docker';
        }
        return Primeira::modo() === 'servidor' ? 'servidor' : 'dev';
    }

    public static function pasta(): string
    {
        return Paths::dados() . '/atualizacao';
    }

    private static function base(): string
    {
        $outra = trim((string) Config::get('ATUALIZACAO_URL', ''));
        return $outra !== '' ? rtrim($outra, '/') : 'https://github.com/' . self::repositorio() . '/releases/latest/download';
    }

    /**
     * O que o repositório oferece agora, com a assinatura conferida.
     * @return array{versao:string, pacote:string, sha256:string, tamanho:int, notas:string}
     * @throws \RuntimeException com o motivo, para a tela
     */
    public static function consultar(): array
    {
        $json = self::baixarTexto(self::base() . '/atualizacao.json');
        $assinatura = self::baixarTexto(self::base() . '/atualizacao.json.sig');
        return self::lerManifesto($json, $assinatura, self::CHAVE_PUBLICA);
    }

    /** @return array{versao:string, pacote:string, sha256:string, tamanho:int, notas:string} */
    public static function lerManifesto(string $json, string $assinatura, string $chave): array
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new \RuntimeException('Este PHP não consegue conferir a assinatura da atualização.');
        }
        $sig = base64_decode(trim($assinatura), true);
        $pub = base64_decode($chave, true);
        if ($sig === false || $pub === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !sodium_crypto_sign_verify_detached($sig, $json, $pub)) {
            throw new \RuntimeException('A assinatura da atualização não confere. Nada foi baixado.');
        }
        $m = json_decode($json, true);
        if (!is_array($m) || !preg_match('/^\d+\.\d+\.\d+$/', (string) ($m['versao'] ?? '')) || !preg_match('/^[A-Za-z0-9._-]+\.tar\.gz$/', (string) ($m['pacote'] ?? ''))
            || !preg_match('/^[0-9a-f]{64}$/', (string) ($m['sha256'] ?? '')) || (int) ($m['tamanho'] ?? 0) <= 0 || (int) $m['tamanho'] > self::MAXIMO) {
            throw new \RuntimeException('A resposta sobre a versão nova veio incompleta.');
        }
        return ['versao' => $m['versao'], 'pacote' => $m['pacote'], 'sha256' => $m['sha256'], 'tamanho' => (int) $m['tamanho'], 'notas' => (string) ($m['notas'] ?? '')];
    }

    public static function maisNova(string $versao): bool
    {
        return version_compare($versao, self::versaoAtual(), '>');
    }

    // ---- o trabalho em segundo plano

    /** @return array{fase:string, etapa:string, mensagem:string, versao:string, de:string, intacto:bool, quando:int}|null */
    public static function estado(): ?array
    {
        $e = json_decode((string) @file_get_contents(self::pasta() . '/estado.json'), true);
        if (!is_array($e) || !isset($e['fase'], $e['quando'])) {
            return null;
        }
        if ($e['fase'] === 'rodando' && time() - (int) $e['quando'] > 900) {
            $e['fase'] = 'falhou';
            $e['mensagem'] = 'A atualização parou no meio. Se o site estranhar algo, restaure o backup mais recente.';
        }
        return $e + ['etapa' => '', 'mensagem' => '', 'versao' => '', 'de' => '', 'intacto' => false];
    }

    public static function limparEstado(): void
    {
        @unlink(self::pasta() . '/estado.json');
    }

    /** O comando do PHP para os processos: FIN_PHP vem de quem iniciou o programa. @return list<string> */
    public static function comandoPhp(): array
    {
        return self::lerComandoPhp((string) getenv('FIN_PHP')) ?? (stripos(basename(PHP_BINARY), 'frankenphp') !== false ? [PHP_BINARY, 'php-cli'] : [PHP_BINARY]);
    }

    /**
     * As partes vêm separadas por tabulação, porque o caminho pode ter espaços. Sem tabulação, vale o que os pacotes
     * de Linux já instalados mandam: um arquivo só, ou programa e argumento separados por espaço.
     * @return list<string>|null
     */
    public static function lerComandoPhp(string $env): ?array
    {
        if (str_contains($env, "\t")) {
            return array_values(array_filter(explode("\t", $env), static fn(string $p): bool => $p !== ''));
        }
        $env = trim($env);
        if ($env === '') {
            return null;
        }
        return is_file($env) ? [$env] : (array) preg_split('/\s+/', $env);
    }

    /** Sem esperar: a página volta na hora e acompanha pelo estado. */
    public static function iniciar(string $versao): void
    {
        self::prepararPasta();
        self::marcar('rodando', 'Começando', $versao);
        // o primeiro arquivo de um processo fica aberto até ele sair, e o Windows não troca uma pasta com arquivo aberto
        $lancador = self::pasta() . '/lancar.php';
        file_put_contents($lancador, '<?php require ' . var_export(Paths::raiz() . '/tools/atualizar.php', true) . ";\n");
        $partes = [...self::comandoPhp(), $lancador, '--versao=' . $versao];
        $env = array_filter(getenv(), 'is_string');
        if (PHP_OS_FAMILY === 'Windows') {
            // escapeshellarg troca % e ! por espaços, e esses caracteres podem estar no nome da pessoa
            $aspas = static fn(string $p): string => '"' . str_replace('"', '', $p) . '"';
            $proc = proc_open('start /B "" ' . implode(' ', array_map($aspas, $partes)) . ' > ' . $aspas(self::pasta() . '/ultima.log') . ' 2>&1', [], $p, Paths::dados(), $env);
        } else {
            $cmd = implode(' ', array_map('escapeshellarg', $partes));
            $proc = proc_open('nohup ' . $cmd . ' > ' . escapeshellarg(self::pasta() . '/ultima.log') . ' 2>&1 < /dev/null &', [], $p, Paths::dados(), $env);
        }
        if (!is_resource($proc)) {
            self::marcar('falhou', '', $versao, 'Não deu para iniciar a atualização.', '', true);
            return;
        }
        proc_close($proc);
    }

    /** O processo de tools/atualizar.php. @throws nada: o resultado fica em estado.json */
    public static function executar(string $versao): bool
    {
        self::prepararPasta();
        $trava = fopen(self::pasta() . '/trava', 'c');
        if ($trava === false || !flock($trava, LOCK_EX | LOCK_NB)) {
            self::marcar('falhou', '', $versao, 'Outra atualização está rodando.', '', true);
            return false;
        }
        $programa = dirname(Paths::raiz());
        chdir((string) Paths::dados());      // este processo não pode morar numa pasta que vai ser trocada
        try {
            if (self::tipo() !== 'pacote' || !is_writable($programa)) {
                throw new \RuntimeException('Esta instalação não atualiza pela tela.');
            }
            self::marcar('rodando', 'Baixando a versão nova', $versao);
            $m = self::consultar();
            if ($m['versao'] !== $versao || !self::maisNova($versao)) {
                throw new \RuntimeException('Esta versão não está mais disponível. Consulte de novo.');
            }
            $novo = self::baixarENovaPasta($m, $programa);

            self::marcar('rodando', 'Ensaiando numa cópia dos seus dados', $versao);
            self::ensaiar($novo);
        } catch (\Throwable $e) {
            self::removerPasta($programa . '/app.novo');
            self::marcar('falhou', '', $versao, $e->getMessage(), '', true);
            error_log('atualização: ' . $e->getMessage());
            return false;
        } finally {
            self::removerPasta(self::pasta() . '/ensaio');
            @unlink(self::pasta() . '/novo.tar.gz');
        }

        $de = self::versaoAtual();
        try {
            self::marcar('rodando', 'Fazendo backup', $versao);
            $backup = Backup::criar('antes-atualizacao');
        } catch (\Throwable $e) {
            self::removerPasta($programa . '/app.novo');
            self::marcar('falhou', '', $versao, 'Não deu para fazer o backup.', '', true);
            error_log('atualização: ' . $e->getMessage());
            return false;
        }

        $trocou = false;
        try {
            self::marcar('rodando', 'Aplicando', $versao);
            Manutencao::ligar();
            Database::fechar();
            self::rodar([$programa . '/app.novo/tools/migrar.php'], null, 120, 'Uma mudança no banco falhou.');
            self::removerPasta($programa . '/app.antes');
            if (!Paths::renomear($programa . '/app', $programa . '/app.antes')) {
                throw new \RuntimeException('Não deu para trocar o programa.');
            }
            $trocou = true;
            if (!Paths::renomear($programa . '/app.novo', $programa . '/app')) {
                throw new \RuntimeException('Não deu para trocar o programa.');
            }
            self::marcar('rodando', 'Conferindo o site', $versao);
            self::rodar([$programa . '/app/tests/run.php'], null, self::TEMPO_ENSAIO, 'A conferência da versão nova falhou.');
            Manutencao::desligar();
            self::conferirPagina();
        } catch (\Throwable $e) {
            error_log('atualização: ' . $e->getMessage());
            Manutencao::ligar();
            $voltou = self::voltar($programa, $backup, $trocou);
            self::removerPasta($programa . '/app.novo');
            Manutencao::desligar();
            self::marcar('falhou', '', $versao, $voltou
                ? 'A atualização falhou e o site voltou para a versão anterior.'
                : 'A atualização falhou e não consegui voltar sozinho. Restaure o backup mais recente e instale o pacote de novo.');
            return false;
        }
        self::marcar('ok', '', $versao, '', $de);
        return true;
    }

    /** @param array{versao:string, pacote:string, sha256:string, tamanho:int} $m */
    private static function baixarENovaPasta(array $m, string $programa): string
    {
        $arq = self::pasta() . '/novo.tar.gz';
        self::baixarArquivo(self::base() . '/' . $m['pacote'], $arq);
        if (filesize($arq) !== $m['tamanho'] || !hash_equals($m['sha256'], (string) hash_file('sha256', $arq))) {
            throw new \RuntimeException('O arquivo baixado não confere com a versão assinada.');
        }
        $novo = $programa . '/app.novo';
        self::removerPasta($novo);
        mkdir($novo, 0755);
        try {
            Tar::extrair($arq, $novo, 'app/');
        } catch (\RuntimeException $e) {
            error_log('atualização: ' . $e->getMessage());
            throw new \RuntimeException('O pacote baixado está danificado.');
        }
        if (trim((string) @file_get_contents($novo . '/VERSION')) !== $m['versao'] || !is_file($novo . '/public/index.php')) {
            throw new \RuntimeException('O pacote baixado não é o da versão anunciada.');
        }
        return $novo;
    }

    private static function ensaiar(string $novo): void
    {
        $dir = self::pasta() . '/ensaio';
        self::removerPasta($dir);
        mkdir($dir, 0700);
        Backup::copiarPara($dir . '/financeiro.sqlite');
        $linhas = array_filter((array) file(Paths::config(), FILE_IGNORE_NEW_LINES), static fn($l): bool => !str_starts_with((string) $l, 'DB_FILE=') && !str_starts_with((string) $l, 'PASTA_DADOS='));
        file_put_contents($dir . '/config.env', implode("\n", $linhas) . "\nDB_FILE=financeiro.sqlite\n");
        chmod($dir . '/config.env', 0600);
        self::rodar([$novo . '/tools/migrar.php'], $dir, 120, 'Uma mudança no banco falhou no ensaio.');
        self::rodar([$novo . '/tests/run.php'], $dir, self::TEMPO_ENSAIO, 'Os testes da versão nova falharam no ensaio.');
    }

    /** @param list<string> $args  @param ?string $dados outra pasta de dados, para o ensaio */
    private static function rodar(array $args, ?string $dados, int $tempo, string $erro): void
    {
        $env = array_filter(getenv(), 'is_string');
        $env['FIN_DADOS'] = $dados ?? (string) Paths::dados();
        $saidaLog = self::pasta() . '/processo.log';
        $proc = proc_open([...self::comandoPhp(), ...$args], [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', $saidaLog, 'w'], 2 => ['file', $saidaLog, 'a']], $p, dirname($args[0], 2), $env);
        if (!is_resource($proc)) {
            throw new \RuntimeException($erro);
        }
        $fim = time() + $tempo;
        do {
            usleep(200000);
            $st = proc_get_status($proc);
            if (!$st['running']) {
                proc_close($proc);
                if ((int) $st['exitcode'] !== 0) {
                    throw new \RuntimeException($erro);
                }
                return;
            }
        } while (time() < $fim);
        proc_terminate($proc);
        proc_close($proc);
        throw new \RuntimeException($erro);
    }

    /** Com a manutenção desligada, o site de verdade tem de abrir. Sem saber a porta, os testes já conferiram. */
    private static function conferirPagina(): void
    {
        $porta = (int) getenv('FIN_PORTA');
        if ($porta <= 0) {
            return;
        }
        $contexto = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true, 'follow_location' => 0]]);
        $resposta = @file_get_contents('http://127.0.0.1:' . $porta . '/login', false, $contexto);
        $linha = (string) ((http_get_last_response_headers() ?? [''])[0] ?? '');
        if ($resposta === false || !preg_match('#^HTTP/\S+ (200|302)#', $linha)) {
            throw new \RuntimeException('o site não abriu depois de trocar a versão');
        }
    }

    private static function voltar(string $programa, string $backup, bool $trocou): bool
    {
        try {
            if ($trocou) {
                if (is_dir($programa . '/app') && is_dir($programa . '/app.antes')) {
                    self::removerPasta($programa . '/app.novo');
                    Paths::renomear($programa . '/app', $programa . '/app.novo');
                }
                if (!is_dir($programa . '/app') && !Paths::renomear($programa . '/app.antes', $programa . '/app')) {
                    return false;
                }
            }
            Backup::voltarArquivo($backup);
            return true;
        } catch (\Throwable $e) {
            error_log('atualização, ao voltar: ' . $e->getMessage());
            return false;
        }
    }

    private static function marcar(string $fase, string $etapa, string $versao, string $mensagem = '', string $de = '', bool $intacto = false): void
    {
        $e = ['fase' => $fase, 'etapa' => $etapa, 'versao' => $versao, 'mensagem' => $mensagem, 'de' => $de, 'intacto' => $intacto, 'quando' => time()];
        $tmp = self::pasta() . '/estado.novo';
        file_put_contents($tmp, json_encode($e, JSON_UNESCAPED_UNICODE));
        Paths::renomear($tmp, self::pasta() . '/estado.json', 5);
    }

    private static function prepararPasta(): void
    {
        if (!is_dir(self::pasta()) && !@mkdir(self::pasta(), 0700, true) && !is_dir(self::pasta())) {
            throw new \RuntimeException('Não consigo gravar na pasta de dados.');
        }
    }

    public static function removerPasta(string $pasta): void
    {
        if (!is_dir($pasta) || is_link($pasta)) {
            return;
        }
        $itens = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($pasta, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($itens as $i) {
            $i->isDir() && !$i->isLink() ? @rmdir($i->getPathname()) : @unlink($i->getPathname());
        }
        @rmdir($pasta);
    }

    // ---- rede

    private static function curl(string $url): \CurlHandle
    {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('Falta a extensão curl do PHP para consultar a versão nova.');
        }
        $partes = parse_url($url);
        $local = in_array($partes['host'] ?? '', ['127.0.0.1', 'localhost'], true);
        if (($partes['scheme'] ?? '') !== 'https' && !$local) {
            throw new \RuntimeException('O endereço de atualização precisa ser HTTPS.');
        }
        $c = curl_init($url);
        curl_setopt_array($c, [
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 300,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | ($local ? CURLPROTO_HTTP : 0), CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS | ($local ? CURLPROTO_HTTP : 0),
            CURLOPT_USERAGENT => 'Financeiro/' . self::versaoAtual(), CURLOPT_FAILONERROR => true,
        ]);
        $ca = Services\LinkProduto::arquivoCa();
        if ($ca !== null) {
            curl_setopt($c, CURLOPT_CAINFO, $ca);
        }
        return $c;
    }

    private static function baixarTexto(string $url): string
    {
        $c = self::curl($url);
        curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
        $r = curl_exec($c);
        if (!is_string($r) || strlen($r) > 1048576) {
            $codigo = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
            throw new \RuntimeException($codigo === 404 ? 'O repositório ainda não tem versão publicada.' : 'Não consegui falar com o repositório. Confira a internet e tente de novo.');
        }
        return $r;
    }

    private static function baixarArquivo(string $url, string $destino): void
    {
        $f = fopen($destino, 'wb');
        if ($f === false) {
            throw new \RuntimeException('Não consigo gravar na pasta de dados.');
        }
        $c = self::curl($url);
        curl_setopt($c, CURLOPT_FILE, $f);
        curl_setopt($c, CURLOPT_MAXFILESIZE_LARGE, self::MAXIMO);
        $ok = curl_exec($c);
        fclose($f);
        if ($ok !== true) {
            throw new \RuntimeException('Não consegui baixar a versão nova. Confira a internet e tente de novo.');
        }
    }
}
