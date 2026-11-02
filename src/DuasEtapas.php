<?php
namespace Financeiro;

use PDO;

/**
 * Verificação em duas etapas: além da senha, um código de 6 dígitos do app autenticador da pessoa (TOTP, RFC 6238,
 * HMAC-SHA1 e passo de 30 s), sem biblioteca.
 *
 * - O segredo fica em usuarios.duas_etapas_segredo, cifrado com sodium secretbox. A chave é CHAVE_DUAS_ETAPAS, no
 *   config.env, criada na primeira vez que alguém liga. Sem a chave (config.env perdido), o código do app deixa de
 *   valer, e só os códigos de recuperação ou o comando que redefine a senha abrem a conta.
 * - Vale o passo de agora e um de cada lado, para relógios um pouco fora. usuarios.duas_etapas_passo guarda o último
 *   passo aceito: o mesmo código, ou um mais velho, não vale de novo.
 * - Os 10 códigos de recuperação ficam só como hash, em duas_etapas_recuperacao, e cada um some ao ser usado.
 * - O relógio é o da máquina, e não Relogio: o código tem de bater com o do celular, que anda de verdade.
 */
final class DuasEtapas
{
    public const DIGITOS = 6;
    public const PASSO = 30;
    public const QUANTOS_CODIGOS = 10;
    private const JANELA = 1;
    private const CHAVE_CONFIG = 'CHAVE_DUAS_ETAPAS';
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    /** Os códigos de recuperação, sem 0/O e 1/I, que se confundem no papel. */
    private const LETRAS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /* -------------------------------------------------------------- Base32 (RFC 4648), sem "=" */

    public static function base32(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach ($bytes === '' ? [] : str_split($bits, 5) as $grupo) {
            $out .= self::BASE32[bindec(str_pad($grupo, 5, '0'))];
        }
        return $out;
    }

    /** null se tiver letra que não é de Base32. Aceita minúsculas, espaços e o "=" do fim. */
    public static function deBase32(string $texto): ?string
    {
        $texto = strtoupper((string) preg_replace('/[\s=]+/', '', $texto));
        $bits = '';
        foreach ($texto === '' ? [] : str_split($texto) as $c) {
            $v = strpos(self::BASE32, $c);
            if ($v === false) {
                return null;
            }
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach ($bits === '' ? [] : str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    /* -------------------------------------------------------------- TOTP */

    /** 20 bytes, como recomenda a RFC 4226 para o SHA1. */
    public static function novoSegredo(): string
    {
        return random_bytes(20);
    }

    /** O passo de 30 s de um instante (agora, se null). */
    public static function passo(?int $instante = null): int
    {
        return intdiv($instante ?? time(), self::PASSO);
    }

    /** HOTP (RFC 4226) no contador $passo: o código de um passo, com zeros à esquerda. */
    public static function codigo(string $segredo, int $passo, int $digitos = self::DIGITOS, string $algoritmo = 'sha1'): string
    {
        $mac = hash_hmac($algoritmo, pack('J', $passo), $segredo, true);
        $i = ord($mac[strlen($mac) - 1]) & 0x0f;
        $n = ((ord($mac[$i]) & 0x7f) << 24) | (ord($mac[$i + 1]) << 16) | (ord($mac[$i + 2]) << 8) | ord($mac[$i + 3]);
        return str_pad((string) ($n % (10 ** $digitos)), $digitos, '0', STR_PAD_LEFT);
    }

    /**
     * Confere o código no passo $agora e num de cada lado. Passo já usado ($ultimo ou antes) não vale.
     * Compara todos os passos, sem parar no primeiro, e em tempo constante.
     * @return ?int o passo que bateu
     */
    public static function conferirCodigo(string $segredo, string $codigo, int $agora, ?int $ultimo): ?int
    {
        if (!preg_match('/^\d{' . self::DIGITOS . '}$/D', $codigo)) {
            return null;
        }
        $achou = null;
        for ($p = $agora - self::JANELA; $p <= $agora + self::JANELA; $p++) {
            $bate = hash_equals(self::codigo($segredo, $p), $codigo);
            if ($bate && ($ultimo === null || $p > $ultimo)) {
                $achou = $p;
            }
        }
        return $achou;
    }

    /** O endereço do QR, que o app autenticador lê. Login longo demais para o QR: só o nome do app. */
    public static function enderecoQr(string $segredo, string $login): string
    {
        $base = 'otpauth://totp/Financeiro:' . rawurlencode($login) . '?secret=' . self::base32($segredo) . '&issuer=Financeiro';
        return strlen($base) <= 200 ? $base : 'otpauth://totp/Financeiro?secret=' . self::base32($segredo) . '&issuer=Financeiro';
    }

    /** A chave para digitar no app, em grupos de 4. */
    public static function chaveParaDigitar(string $segredo): string
    {
        return implode(' ', str_split(self::base32($segredo), 4));
    }

    /* -------------------------------------------------------------- códigos de recuperação */

    /** @return list<string> no formato XXXXX-XXXXX */
    public static function novosCodigosRecuperacao(): array
    {
        $out = [];
        while (count($out) < self::QUANTOS_CODIGOS) {
            $c = '';
            for ($i = 0; $i < 10; $i++) {
                $c .= self::LETRAS[random_int(0, strlen(self::LETRAS) - 1)];
            }
            $out[substr($c, 0, 5) . '-' . substr($c, 5)] = true;
        }
        return array_keys($out);
    }

    /** Sem espaço, traço nem diferença entre maiúscula e minúscula. */
    public static function limpar(string $digitado): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', mb_substr($digitado, 0, 40)));
    }

    /* -------------------------------------------------------------- cifra do segredo */

    /** Sem a extensão sodium, a verificação não pode ser ligada. */
    public static function disponivel(): bool
    {
        return function_exists('sodium_crypto_secretbox');
    }

    /** A chave do config.env; com $criar, gera e grava uma se ainda não há. */
    private static function chave(bool $criar): ?string
    {
        $lida = self::lerChave((string) Config::get(self::CHAVE_CONFIG, ''));
        if ($lida !== null || !$criar) {
            return $lida;
        }
        // duas pessoas ligando ao mesmo tempo, pela primeira vez: uma cria, a outra usa a mesma
        $trava = @fopen(Paths::config() . '.trava', 'c');
        if ($trava === false || !flock($trava, LOCK_EX)) {
            throw new \RuntimeException('Não deu para gravar a chave da verificação em duas etapas.');
        }
        try {
            $noArquivo = self::lerChave(self::valorNoArquivo());
            if ($noArquivo === null) {
                Primeira::gravarConfig(self::CHAVE_CONFIG, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
                $noArquivo = self::lerChave(self::valorNoArquivo());
                if ($noArquivo === null) {
                    throw new \RuntimeException('Não deu para gravar a chave da verificação em duas etapas em ' . Paths::config());
                }
            }
            Config::definir(self::CHAVE_CONFIG, base64_encode($noArquivo));
            return $noArquivo;
        } finally {
            flock($trava, LOCK_UN);
            fclose($trava);
        }
    }

    private static function lerChave(string $base64): ?string
    {
        $k = base64_decode($base64, true);
        return is_string($k) && strlen($k) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES ? $k : null;
    }

    /** O valor gravado agora no arquivo, e não o da memória: outra página pode ter acabado de criar a chave. */
    private static function valorNoArquivo(): string
    {
        foreach (is_file(Paths::config()) ? (array) file(Paths::config(), FILE_IGNORE_NEW_LINES) : [] as $l) {
            if (str_starts_with(trim((string) $l), self::CHAVE_CONFIG . '=')) {
                return trim(substr(trim((string) $l), strlen(self::CHAVE_CONFIG) + 1));
            }
        }
        return '';
    }

    public static function cifrar(string $segredo): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($segredo, $nonce, (string) self::chave(true)));
    }

    /** null = sem sodium, sem a chave ou com outra chave. */
    public static function decifrar(string $guardado): ?string
    {
        $chave = self::disponivel() ? self::chave(false) : null;
        $bruto = str_starts_with($guardado, 'v1:') ? base64_decode(substr($guardado, 3), true) : false;
        if ($chave === null || !is_string($bruto) || strlen($bruto) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $aberto = sodium_crypto_secretbox_open(substr($bruto, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bruto, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $chave);
        return is_string($aberto) ? $aberto : null;
    }

    /* -------------------------------------------------------------- a conta */

    public static function ligada(int $usuarioId): bool
    {
        $st = Database::connection()->prepare('SELECT duas_etapas_segredo FROM usuarios WHERE id = ?');
        $st->execute([$usuarioId]);
        $s = $st->fetchColumn();
        return is_string($s) && $s !== '';
    }

    /**
     * Liga com o segredo que a pessoa acabou de confirmar no passo $passo, que já conta como usado.
     * @return list<string> os códigos de recuperação, para mostrar uma vez
     */
    public static function ligar(int $usuarioId, string $segredo, int $passo): array
    {
        $codigos = self::novosCodigosRecuperacao();
        $hashes = array_map(static fn(string $c): string => password_hash(self::limpar($c), PASSWORD_DEFAULT), $codigos);
        $cifrado = self::cifrar($segredo);
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE usuarios SET duas_etapas_segredo = ?, duas_etapas_passo = ? WHERE id = ?')->execute([$cifrado, $passo, $usuarioId]);
            $pdo->prepare('DELETE FROM duas_etapas_recuperacao WHERE usuario_id = ?')->execute([$usuarioId]);
            $ins = $pdo->prepare('INSERT INTO duas_etapas_recuperacao (usuario_id, codigo_hash) VALUES (?, ?)');
            foreach ($hashes as $h) {
                $ins->execute([$usuarioId, $h]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $codigos;
    }

    public static function desligar(int $usuarioId): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE usuarios SET duas_etapas_segredo = NULL, duas_etapas_passo = NULL WHERE id = ?')->execute([$usuarioId]);
            $pdo->prepare('DELETE FROM duas_etapas_recuperacao WHERE usuario_id = ?')->execute([$usuarioId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function codigosRestantes(int $usuarioId): int
    {
        $st = Database::connection()->prepare('SELECT COUNT(*) FROM duas_etapas_recuperacao WHERE usuario_id = ?');
        $st->execute([$usuarioId]);
        return (int) $st->fetchColumn();
    }

    /**
     * O código do app (6 dígitos) ou um de recuperação. Quem aceita marca o uso: o passo do app passa a ser o
     * último, e o código de recuperação some. Duas páginas com o mesmo código ao mesmo tempo: só uma passa.
     * Não conta tentativas: quem chama usa Auth, que conta.
     */
    public static function conferirDaConta(int $usuarioId, string $digitado, ?int $instante = null): bool
    {
        $codigo = self::limpar($digitado);
        $pdo = Database::connection();
        if (preg_match('/^\d{' . self::DIGITOS . '}$/D', $codigo)) {
            $st = $pdo->prepare('SELECT duas_etapas_segredo, duas_etapas_passo FROM usuarios WHERE id = ?');
            $st->execute([$usuarioId]);
            $l = $st->fetch(PDO::FETCH_ASSOC);
            $segredo = is_array($l) && is_string($l['duas_etapas_segredo']) ? self::decifrar($l['duas_etapas_segredo']) : null;
            if ($segredo === null) {
                return false;
            }
            $ultimo = $l['duas_etapas_passo'] === null ? null : (int) $l['duas_etapas_passo'];
            $passo = self::conferirCodigo($segredo, $codigo, self::passo($instante), $ultimo);
            if ($passo === null) {
                return false;
            }
            $up = $pdo->prepare('UPDATE usuarios SET duas_etapas_passo = ? WHERE id = ? AND (duas_etapas_passo IS NULL OR duas_etapas_passo < ?)');
            $up->execute([$passo, $usuarioId, $passo]);
            return $up->rowCount() === 1;
        }
        if (strlen($codigo) !== 10) {
            return false;
        }
        $st = $pdo->prepare('SELECT id, codigo_hash FROM duas_etapas_recuperacao WHERE usuario_id = ?');
        $st->execute([$usuarioId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (password_verify($codigo, (string) $r['codigo_hash'])) {
                $del = $pdo->prepare('DELETE FROM duas_etapas_recuperacao WHERE id = ?');
                $del->execute([(int) $r['id']]);
                return $del->rowCount() === 1;
            }
        }
        return false;
    }
}
