<?php
/**
 * Prepara o que o app baixa para se atualizar: atualizacao.json e a assinatura dele.
 * Uso: php tools/assinar-atualizacao.php --chave=ARQUIVO --pacote=financeiro-app-1.2.3.tar.gz --versao=1.2.3 [--notas=CHANGELOG.md]
 *        [--frankenphp=1.13.4 --php=8.5.12]
 * --frankenphp e --php: o programa que vai nos pacotes desta versão (packaging/programa.env). Entram em "programa", e o
 * site de um pacote mais velho avisa que há um instalador novo. Sem eles, o campo fica de fora, como antes.
 * A chave privada é um base64 de 64 bytes e nunca entra no repositório. FIN_CHAVE_ATUALIZACAO vale no lugar de --chave.
 * Gere o par com --gerar=ARQUIVO: o privado vai para o arquivo; o público sai na tela e entra em Atualizacao::CHAVE_PUBLICA.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Rode pela linha de comando.\n");
}
$op = getopt('', ['chave:', 'pacote:', 'versao:', 'notas:', 'gerar:', 'frankenphp:', 'php:']);
if (isset($op['gerar'])) {
    if (file_exists((string) $op['gerar'])) {
        fwrite(STDERR, "Esse arquivo já existe.\n");
        exit(1);
    }
    $par = sodium_crypto_sign_keypair();
    touch((string) $op['gerar']);
    chmod((string) $op['gerar'], 0600);
    file_put_contents((string) $op['gerar'], base64_encode(sodium_crypto_sign_secretkey($par)) . "\n");
    echo base64_encode(sodium_crypto_sign_publickey($par)), "\n";
    exit(0);
}
$chave = getenv('FIN_CHAVE_ATUALIZACAO') ?: (isset($op['chave']) ? (string) file_get_contents((string) $op['chave']) : '');
$privada = base64_decode(trim($chave), true);
if ($privada === false || strlen($privada) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES || empty($op['pacote']) || empty($op['versao'])) {
    fwrite(STDERR, "Faltou a chave, o pacote ou a versão.\n");
    exit(2);
}
$programa = null;
if (isset($op['frankenphp']) || isset($op['php'])) {
    $programa = ['frankenphp' => (string) ($op['frankenphp'] ?? ''), 'php' => (string) ($op['php'] ?? '')];
    if (!preg_match('/^\d+\.\d+\.\d+$/', $programa['frankenphp']) || !preg_match('/^\d+\.\d+\.\d+$/', $programa['php'])) {
        fwrite(STDERR, "--frankenphp e --php vão juntos, no formato 1.2.3.\n");
        exit(2);
    }
}
$pacote = (string) $op['pacote'];
$versao = (string) $op['versao'];
$notas = '';
if (isset($op['notas'])) {
    $grupo = false;
    foreach ((array) file((string) $op['notas'], FILE_IGNORE_NEW_LINES) as $l) {
        if (str_starts_with((string) $l, '## ')) {
            $grupo = trim(substr((string) $l, 3)) === $versao;
            continue;
        }
        if ($grupo) {
            $notas .= $l . "\n";
        }
    }
}
$json = json_encode([
    'versao' => $versao, 'pacote' => basename($pacote), 'sha256' => hash_file('sha256', $pacote),
    'tamanho' => filesize($pacote), 'notas' => trim($notas),
] + ($programa !== null ? ['programa' => $programa] : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
$pasta = dirname($pacote);
file_put_contents($pasta . '/atualizacao.json', $json);
file_put_contents($pasta . '/atualizacao.json.sig', base64_encode(sodium_crypto_sign_detached($json, $privada)) . "\n");
echo "atualizacao.json assinado: {$versao}\n";
