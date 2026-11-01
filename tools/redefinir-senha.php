<?php
/**
 * Quem está sozinho no site e esqueceu a senha: gera uma senha nova para o login informado.
 * Uso: sudo -u www-data php tools/redefinir-senha.php LOGIN
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Rode pela linha de comando.\n");
}

require __DIR__ . '/../src/bootstrap.php';

use Financeiro\Auth;
use Financeiro\Database;
use Financeiro\Models\UsuarioModel;

$login = $argv[1] ?? '';
$stmt = Database::connection()->prepare('SELECT id FROM usuarios WHERE login = ?');
$stmt->execute([$login]);
$id = (int) $stmt->fetchColumn();
if ($id === 0) {
    exit("Login não encontrado.\n");
}
$senha = bin2hex(random_bytes(6));
UsuarioModel::trocarSenha($id, $senha);
Auth::sairDeTodos($id);
echo "Senha nova de {$login}: {$senha}\nTroque em Ajustes depois de entrar.\n";
