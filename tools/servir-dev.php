<?php
/**
 * Só para desenvolver: atende uma página por vez e não serve para uso real.
 * Uso: php -S 127.0.0.1:8484 -t public tools/servir-dev.php
 * Faz o que o Apache faz com o .htaccess: arquivo real sai direto, com ponto no nome nunca, o resto vai para o index.php.
 */
if (PHP_SAPI !== 'cli-server') {
    exit("Rode com php -S.\n");
}
$caminho = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
if (preg_match('#(^|/)\.#', $caminho)) {
    http_response_code(404);
    return;
}
$arquivo = dirname(__DIR__) . '/public' . $caminho;
if ($caminho !== '/' && is_file($arquivo) && !str_ends_with($arquivo, '.php')) {
    return false;
}
require dirname(__DIR__) . '/public/index.php';
