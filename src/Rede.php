<?php
namespace Financeiro;

/** Quem está pedindo e por onde: endereço local, HTTPS atrás de proxy e o cabeçalho Host. */
final class Rede
{
    /** O endereço é o próprio computador? */
    public static function ehLocal(string $ip): bool
    {
        $ip = strtolower(trim($ip));
        if (str_starts_with($ip, '::ffff:')) {
            $ip = substr($ip, 7);
        }
        return $ip === '::1' || str_starts_with($ip, '127.');
    }

    public static function requisicaoLocal(): bool
    {
        return PHP_SAPI === 'cli' || self::ehLocal((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    /** HTTPS direto ou, só se a configuração permitir, informado pelo proxy. */
    public static function https(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (Config::get('CONFIAR_PROXY', '0') === '1' && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    /** O nome do Host, sem a porta e em minúsculas; null se malformado. */
    public static function nomeDoHost(string $host): ?string
    {
        $host = strtolower(trim($host));
        if (preg_match('/^\[([0-9a-f:.]+)\](?::\d+)?$/', $host, $m)) {
            return $m[1];
        }
        if (preg_match('/^([a-z0-9._-]+)(?::\d+)?$/', $host, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Protege o programa no computador pessoal contra DNS rebinding: uma página de fora não consegue se passar por
     * um endereço local. Sem HOSTS_PERMITIDOS, o modo servidor aceita qualquer Host, como sempre aceitou.
     */
    public static function hostAceito(string $host, string $modo, bool $acessoRede, string $permitidos, string $nomeDaMaquina): bool
    {
        $nome = self::nomeDoHost($host);
        if ($nome === null) {
            return false;
        }
        $lista = array_values(array_filter(array_map(static fn(string $h): string => strtolower(trim($h)), explode(',', $permitidos))));
        if ($modo !== 'instalador' && $lista === []) {
            return true;
        }
        if ($nome === 'localhost' || self::ehLocal($nome) || in_array($nome, $lista, true)) {
            return true;
        }
        if ($modo !== 'instalador') {
            return false;
        }
        if (!$acessoRede) {
            return false;
        }
        $maquina = strtolower($nomeDaMaquina);
        if ($maquina !== '' && ($nome === $maquina || $nome === $maquina . '.local')) {
            return true;
        }
        return self::ipPrivado($nome);
    }

    /** O programa foi iniciado pelo pacote, que sabe ligar e desligar a rede e reiniciar? */
    public static function gerenciada(): bool
    {
        return getenv('FIN_GERENCIADO') === '1';
    }

    public static function acessoLigado(): bool
    {
        return Config::get('ACESSO_REDE', '0') === '1';
    }

    /** Pede ao programa que reinicie: quem o iniciou olha este arquivo. */
    public static function pedirReinicio(): void
    {
        touch(Paths::dados() . '/reiniciar');
    }

    public static function porta(): int
    {
        $p = (int) getenv('FIN_PORTA');
        return $p > 0 ? $p : (int) ($_SERVER['SERVER_PORT'] ?? 8484);
    }

    /**
     * O IP deste computador na rede de casa; null se não houver. Um "connect" em UDP só escolhe a saída: nada é
     * enviado para fora. Endereço fora das faixas privadas não serve, porque hostAceito() o recusaria.
     */
    public static function ipLocal(): ?string
    {
        $s = @stream_socket_client('udp://192.0.2.1:9', $erro, $texto, 1);
        if ($s === false) {
            return null;
        }
        $nome = (string) stream_socket_get_name($s, false);
        fclose($s);
        $ip = (string) substr($nome, 0, (int) strrpos($nome, ':'));
        return self::ipPrivado($ip) && !self::ehLocal($ip) ? $ip : null;
    }

    public static function exigirHost(): void
    {
        $ok = self::hostAceito(
            (string) ($_SERVER['HTTP_HOST'] ?? ''),
            Primeira::modo(),
            Config::get('ACESSO_REDE', '0') === '1',
            (string) Config::get('HOSTS_PERMITIDOS', ''),
            (string) gethostname()
        );
        if (!$ok) {
            Preflight::tela(421, t('Endereço não reconhecido'), [t('Abra o Financeiro pelo endereço mostrado no computador onde ele está instalado.')]);
        }
    }

    private static function ipPrivado(string $nome): bool
    {
        return filter_var($nome, FILTER_VALIDATE_IP) !== false
            && filter_var($nome, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
