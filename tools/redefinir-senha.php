<?php
/**
 * Quem está sozinho no site e esqueceu a senha: gera uma senha nova para o login informado e desliga a verificação
 * em duas etapas dessa conta, se estiver ligada.
 * Uso: sudo -u www-data php tools/redefinir-senha.php LOGIN
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Rode pela linha de comando.\n");
}

require __DIR__ . '/../src/bootstrap.php';

use Financeiro\Auth;
use Financeiro\Database;
use Financeiro\DuasEtapas;
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
// quem esqueceu a senha pode ter perdido também o celular: o comando abre a conta de vez
try {
    $tinhaDuasEtapas = DuasEtapas::ligada($id);
} catch (\PDOException) {
    $tinhaDuasEtapas = false;           // banco de antes da verificação, que o app ainda não atualizou
}
if ($tinhaDuasEtapas) {
    DuasEtapas::desligar($id);
}
echo "Senha nova de {$login}: {$senha}\nTroque em Ajustes depois de entrar.\n";
if ($tinhaDuasEtapas) {
    echo "Aviso: a verificação em duas etapas desta conta foi desligada. Ligue de novo em Ajustes, com o app autenticador.\n";
}
