<?php
namespace Financeiro;

/**
 * A primeira execução, sem perguntar nada: cria a pasta de dados, a configuração e o banco. Depois disso, cada
 * página só confere que os arquivos estão lá. Quem cria a conta é a pessoa, no navegador.
 */
final class Primeira
{
    private const CONFIG = <<<'ENV'
        # Criado pelo MyMonetto na primeira execução.
        APP_ENV=production
        DB_DRIVER=sqlite
        DB_FILE=financeiro.sqlite
        FIN_MODO=instalador
        SESSION_LIFETIME=28800
        MOTOR_V2=1
        # 1 = aceita acesso de outros aparelhos da rede; o programa também precisa ouvir na rede
        ACESSO_REDE=0
        # 1 = só atrás de um proxy seu: confia em X-Forwarded-Proto
        CONFIAR_PROXY=0
        # nomes extras aceitos no endereço, separados por vírgula
        HOSTS_PERMITIDOS=
        # arquivo de certificados raiz para o curl; vazio = o do pacote ou o do sistema
        CA_ARQUIVO=

        ENV;

    /** instalador = no computador da pessoa; servidor = o site antigo, com instalar.sh. */
    public static function modo(): string
    {
        return Config::get('FIN_MODO', Paths::portatil() ? 'instalador' : 'servidor') === 'instalador' ? 'instalador' : 'servidor';
    }

    /** No modo instalador, a primeira conta só nasce no próprio computador: a rede pode não ser de confiança. */
    public static function contaPermitida(): bool
    {
        return self::modo() !== 'instalador' || Rede::requisicaoLocal();
    }

    /**
     * No modo servidor (instalar.sh, Docker), a primeira conta pede um código que só quem instalou vê: no fim da
     * instalação ou no log. Sem ele, quem chegasse primeiro à página, ou uma página maliciosa com DNS rebinding,
     * ficaria com a conta de administrador.
     */
    public static function codigoExigido(): bool
    {
        return self::modo() === 'servidor';
    }

    /** O código da primeira conta: criado na primeira vez que alguém o pede, e escrito também no log. */
    public static function codigo(): string
    {
        $arquivo = self::arquivoCodigo();
        $f = @fopen($arquivo, 'x');     // só um pedido cria; os outros leem o que ele gravou
        if ($f !== false) {
            $letras = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';      // sem 0/O e 1/I, que se confundem
            $codigo = '';
            for ($i = 0; $i < 12; $i++) {
                $codigo .= $letras[random_int(0, strlen($letras) - 1)];
            }
            $codigo = implode('-', str_split($codigo, 4));
            @chmod($arquivo, 0600);
            fwrite($f, $codigo . "\n");
            fclose($f);
            $linha = 'MyMonetto: código para criar a primeira conta: ' . $codigo;
            error_log($linha);
            if (PHP_SAPI !== 'cli') {
                // o log do app fica na pasta de dados; a saída de erro é o que o Docker e o Apache mostram
                @file_put_contents('php://stderr', $linha . "\n");
            }
            return $codigo;
        }
        for ($i = 0; $i < 20; $i++) {
            $codigo = trim((string) @file_get_contents($arquivo));
            if ($codigo !== '') {
                return $codigo;
            }
            usleep(50000);
        }
        throw new \RuntimeException('Não deu para ler o código da primeira conta em ' . $arquivo);
    }

    /** Sem diferença entre maiúscula e minúscula, e ignorando espaços e traços. */
    public static function codigoConfere(string $digitado): bool
    {
        $limpar = static fn(string $s): string => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $s));
        $digitado = $limpar($digitado);
        return $digitado !== '' && hash_equals($limpar(self::codigo()), $digitado);
    }

    /** Depois da primeira conta, o código não vale mais nada. */
    public static function esquecerCodigo(): void
    {
        @unlink(self::arquivoCodigo());
    }

    public static function arquivoCodigo(): string
    {
        return (Paths::dados() ?? dirname(Paths::config())) . '/primeira-conta.txt';
    }

    public static function garantir(): void
    {
        if (!Paths::portatil()) {
            self::criarPastas();                                // site antigo com PASTA_DADOS, depois de migrar
            return;
        }
        if (self::tudoPronto()) {
            Config::load(Paths::config());
            return;
        }
        self::criarPastas();
        $dados = (string) Paths::dados();
        // duas primeiras páginas ao mesmo tempo: uma cria, a outra espera
        $trava = fopen($dados . '/primeira.trava', 'c');
        if ($trava === false || !flock($trava, LOCK_EX)) {
            Preflight::tela(500, t('O MyMonetto não pôde abrir'), [t('Não consigo gravar na pasta de dados.')]);
        }
        try {
            if (!is_file(Paths::config())) {
                self::gravarSeguro(Paths::config(), self::CONFIG);
            }
            Config::load(Paths::config());
            if (Sql::sqlite() && !is_file(Paths::banco())) {
                self::criarBanco(Paths::banco());
            }
        } finally {
            flock($trava, LOCK_UN);
            fclose($trava);
        }
    }

    private static function criarPastas(): void
    {
        if (Paths::dados() === null) {
            return;
        }
        foreach ([Paths::sessoes(), dirname(Paths::log())] as $pasta) {
            if (!is_dir($pasta)) {
                @mkdir($pasta, 0700, true);
            }
        }
    }

    private static function tudoPronto(): bool
    {
        if (!is_file(Paths::config()) || !is_dir(Paths::sessoes())) {
            return false;
        }
        Config::load(Paths::config());
        return !Sql::sqlite() || is_file(Paths::banco());
    }

    /** No modo servidor (instalar.sh), quem cria o banco SQLite é database/instalar.php; no computador, a primeira execução. */
    public static function criarBancoSeFaltar(): bool
    {
        if (!Sql::sqlite() || is_file(Paths::banco())) {
            return false;
        }
        self::criarPastas();
        self::criarBanco(Paths::banco());
        return true;
    }

    /** Monta o banco num arquivo à parte e só então o põe no lugar: uma falha no meio não deixa banco pela metade. */
    private static function criarBanco(string $destino): void
    {
        $novo = $destino . '.novo';
        @unlink($novo);
        $pdo = new SqliteConexao($novo);
        $pdo->exec((string) file_get_contents(Paths::raiz() . '/database/schema.sqlite.sql'));
        Migrador::marcarComoFeitas($pdo, Migrador::pasta());      // o schema já é o da versão de agora
        if (!defined('FIN_SEM_DADOS_INICIAIS')) {
            Preparo::preparar($pdo, false);
        }
        // tudo no arquivo principal antes de movê-lo; a primeira conexão de verdade volta ao WAL
        $pdo->exec('PRAGMA journal_mode = DELETE');
        $pdo = null;
        @chmod($novo, 0600);
        if (!Paths::renomear($novo, $destino)) {
            throw new \RuntimeException('Não deu para criar o banco.');
        }
    }

    /** Troca uma linha da configuração, ou acrescenta, sem mexer nas outras. */
    public static function gravarConfig(string $chave, string $valor): void
    {
        $linhas = is_file(Paths::config()) ? (array) file(Paths::config(), FILE_IGNORE_NEW_LINES) : [];
        $achou = false;
        foreach ($linhas as $i => $l) {
            if (str_starts_with((string) $l, $chave . '=')) {
                $linhas[$i] = $chave . '=' . $valor;
                $achou = true;
            }
        }
        if (!$achou) {
            $linhas[] = $chave . '=' . $valor;
        }
        self::gravarSeguro(Paths::config(), implode("\n", $linhas) . "\n");
    }

    private static function gravarSeguro(string $arquivo, string $conteudo): void
    {
        $tmp = $arquivo . '.novo';
        file_put_contents($tmp, $conteudo);
        @chmod($tmp, 0600);
        Paths::renomear($tmp, $arquivo, 5);
    }
}
