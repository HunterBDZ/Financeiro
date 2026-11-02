<?php
namespace Financeiro;

/**
 * A primeira execução, sem perguntar nada: cria a pasta de dados, a configuração e o banco. Depois disso, cada
 * página só confere que os arquivos estão lá. Quem cria a conta é a pessoa, no navegador.
 */
final class Primeira
{
    private const CONFIG = <<<'ENV'
        # Criado pelo Financeiro na primeira execução.
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
            Preflight::tela(500, t('O Financeiro não pôde abrir'), [t('Não consigo gravar na pasta de dados.')]);
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

    /** Monta o banco num arquivo à parte e só então o põe no lugar: uma falha no meio não deixa banco pela metade. */
    private static function criarBanco(string $destino): void
    {
        $novo = $destino . '.novo';
        @unlink($novo);
        $pdo = new SqliteConexao($novo);
        $pdo->exec((string) file_get_contents(Paths::raiz() . '/database/schema.sqlite.sql'));
        if (!defined('FIN_SEM_DADOS_INICIAIS')) {
            Preparo::preparar($pdo, false);
        }
        // tudo no arquivo principal antes de movê-lo; a primeira conexão de verdade volta ao WAL
        $pdo->exec('PRAGMA journal_mode = DELETE');
        $pdo = null;
        @chmod($novo, 0600);
        if (!rename($novo, $destino)) {
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
        rename($tmp, $arquivo);
    }
}
