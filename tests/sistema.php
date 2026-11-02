<?php
/**
 * Backup, restauração, migrações e o que protege a atualização, num banco descartável. tests/run.php chama este
 * arquivo em processo próprio, com FIN_DADOS numa pasta temporária: o banco de verdade nem é aberto.
 * Sai com 1 se algo falhar.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('FIN_DADOS') === false) {
    exit("Rode por tests/run.php.\n");
}
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no) || (headers_sent() && str_contains($str, 'headers already sent'))) {
        return true;     // @ continua calando, e a linha de comando do FrankenPHP começa com os cabeçalhos enviados
    }
    throw new \ErrorException($str, 0, $no, $file, $line);
});
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__ . '/../src/bootstrap.php';

use Financeiro\Atualizacao;
use Financeiro\Backup;
use Financeiro\Database;
use Financeiro\Manutencao;
use Financeiro\Migrador;
use Financeiro\Paths;
use Financeiro\Primeira;
use Financeiro\QrCode;
use Financeiro\Rede;
use Financeiro\SqliteConexao;
use Financeiro\Tar;

$ok = 0;
$falhas = 0;
$check = static function (string $nome, bool $cond, string $det = '') use (&$ok, &$falhas): void {
    if ($cond) {
        $ok++;
    } else {
        $falhas++;
        echo "FALHA {$nome}" . ($det !== '' ? " ({$det})" : '') . "\n";
    }
};
$erroDe = static function (callable $f): string {
    try {
        $f();
        return '';
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
};
$pdo = Database::connection();
$dados = (string) Paths::dados();

// ---- backup
$pdo->exec("INSERT INTO usuarios (nome, login, senha_hash) VALUES ('Pessoa', 'pessoa', 'x')");
$pdo->exec("INSERT INTO ajustes (chave, label, valor) VALUES ('teste_backup', 'teste', 111)");
$b1 = Backup::criar('manual');
$check('backup: o arquivo existe e é privado', is_file($b1) && (fileperms($b1) & 0077) === 0);
$check('backup: aparece na lista', Backup::listar()[0]['nome'] === basename($b1));
$check('backup: motivo desconhecido é recusado', $erroDe(static fn() => Backup::criar('outro')) !== '');
$check('backup: nome fora da lista não vira caminho', Backup::caminho('../config.env') === null && Backup::caminho('financeiro-1.sqlite') === null);
Backup::diario();
Backup::diario();
$check('backup do dia: um só', count(array_filter(Backup::listar(), static fn(array $b): bool => $b['motivo'] === 'diario')) === 1);
for ($i = 0; $i < 22; $i++) {
    Backup::criar('manual');
}
$check('rotação: guarda 20 manuais', count(array_filter(Backup::listar(), static fn(array $b): bool => $b['motivo'] === 'manual')) === 20);
sleep(1);     // no mesmo segundo, a rotação não sabe qual é a mais nova
$b1 = Backup::criar('manual');

// ---- restaurar
$pdo->exec("UPDATE ajustes SET valor = 222 WHERE chave = 'teste_backup'");
$pdo->exec("INSERT INTO usuarios (nome, login, senha_hash) VALUES ('Depois', 'depois', 'x')");
$antes = Backup::restaurar($b1);
$pdo = Database::connection();
$check('restaurar: os dados voltam', (float) $pdo->query("SELECT valor FROM ajustes WHERE chave = 'teste_backup'")->fetchColumn() === 111.0);
$check('restaurar: o que veio depois sai', (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE login = 'depois'")->fetchColumn() === 0);
$check('restaurar: o que havia antes fica numa cópia', is_file($antes) && (new SqliteConexao($antes))->query("SELECT COUNT(*) FROM usuarios WHERE login = 'depois'")->fetchColumn() == 1);
$check('restaurar: as chaves estrangeiras voltam a valer', (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() === 1);
$check('restaurar: novo id continua depois do maior', (int) $pdo->query("SELECT seq FROM sqlite_sequence WHERE name = 'usuarios'")->fetchColumn() >= 1);

$lixo = $dados . '/lixo.sqlite';
file_put_contents($lixo, 'isto não é um banco');
$check('restaurar: arquivo qualquer é recusado', str_contains($erroDe(static fn() => Backup::restaurar($lixo)), 'não é um backup'));
$vazio = $dados . '/vazio.sqlite';
(new PDO('sqlite:' . $vazio))->exec('CREATE TABLE x (a)');
$check('restaurar: outro banco SQLite é recusado', str_contains($erroDe(static fn() => Backup::restaurar($vazio)), 'não é um backup'));
$cortado = $dados . '/cortado.sqlite';
file_put_contents($cortado, substr((string) file_get_contents($b1), 0, 8192));
$check('restaurar: arquivo cortado é recusado', $erroDe(static fn() => Backup::restaurar($cortado)) !== '');
$check('restaurar: depois da recusa, os dados seguem', (float) $pdo->query("SELECT valor FROM ajustes WHERE chave = 'teste_backup'")->fetchColumn() === 111.0);

$novo = $dados . '/novo.sqlite';
copy($b1, $novo);
$p = new SqliteConexao($novo);
$p->exec("INSERT INTO migracoes (arquivo) VALUES ('s9999_futura.sql')");
$p = null;
$check('restaurar: backup de versão mais nova é recusado', str_contains($erroDe(static fn() => Backup::restaurar($novo)), 'mais nova'));
copy($b1, $novo);
$p = new SqliteConexao($novo);
$p->exec("INSERT INTO migracoes (arquivo) VALUES ('20260101_antiga_do_mysql.sql')");
$p = null;
$check('restaurar: migração antiga do MySQL não atrapalha', $erroDe(static fn() => Backup::restaurar($novo)) === '');
$pdo = Database::connection();

// ---- migrações
$pasta = $dados . '/mig';
mkdir($pasta);
$check('migrações: pasta vazia não faz nada', Migrador::aplicar($pdo, $pasta) === [] && Migrador::pendentes($pdo, $pasta) === []);
file_put_contents($pasta . '/s0001_tabela.sql', 'CREATE TABLE teste_mig (a INTEGER);');
file_put_contents($pasta . '/s0001_tabela.php', '<?php return static function (PDO $pdo): void { $pdo->exec("INSERT INTO teste_mig VALUES (1)"); };');
file_put_contents($pasta . '/s0002_erro.sql', 'INSERT INTO teste_mig VALUES (2); INSERT INTO nao_existe VALUES (1);');
file_put_contents($pasta . '/ignorado.sql', 'DROP TABLE usuarios;');
$check('migrações: o .sql antes do .php e nome estranho fora', Migrador::arquivos($pasta) === ['s0001_tabela.sql', 's0001_tabela.php', 's0002_erro.sql']);
$check('migrações: a que falha para tudo', str_contains($erroDe(static fn() => Migrador::aplicar($pdo, $pasta)), 's0002_erro.sql'));
$check('migrações: as anteriores ficam, a que falhou não fica pela metade', (int) $pdo->query('SELECT COUNT(*) FROM teste_mig')->fetchColumn() === 1);
unlink($pasta . '/s0002_erro.sql');
$check('migrações: rodar de novo não repete', Migrador::aplicar($pdo, $pasta) === []);
$check('migrações: o que já rodou e não está na pasta é de versão mais nova', Migrador::desconhecidas($pdo, $dados . '/nao-existe') !== []);
$pdo->exec("DELETE FROM migracoes WHERE arquivo LIKE 's0001%'");
$pdo->exec('DROP TABLE teste_mig');

// ---- pacote .tar
$tar = static function (array $itens): string {
    $saida = '';
    foreach ($itens as [$nome, $conteudo, $tipo]) {
        $c = str_pad($nome, 100, "\0") . "0000644\0" . "0000000\0" . "0000000\0" . sprintf("%011o\0", strlen($conteudo)) . "00000000000\0" . '        ' . $tipo . str_repeat("\0", 100) . "ustar\00000" . str_repeat("\0", 247);
        $soma = 0;
        foreach (str_split($c) as $l) {
            $soma += ord($l);
        }
        $c = substr_replace($c, sprintf("%06o\0 ", $soma), 148, 8);
        $saida .= $c . $conteudo . str_repeat("\0", (512 - strlen($conteudo) % 512) % 512);
    }
    return gzencode($saida . str_repeat("\0", 1024));
};
$d = $dados . '/tar';
mkdir($d);
file_put_contents($d . '/bom.tar.gz', $tar([['app/', '', '5'], ['app/a/b.txt', 'oi', '0']]));
$check('tar: extrai só o de dentro do prefixo', Tar::extrair($d . '/bom.tar.gz', $d . '/x', 'app/') === 1 && file_get_contents($d . '/x/a/b.txt') === 'oi');
foreach (['app/../../fora.txt', '../fora.txt', '/tmp/fora.txt'] as $i => $nome) {
    file_put_contents($d . "/mau{$i}.tar.gz", $tar([[$nome, 'x', '0']]));
    $check('tar: caminho para fora para tudo: ' . $nome, $erroDe(static fn() => Tar::extrair($d . "/mau{$i}.tar.gz", $d . "/y{$i}", 'app/')) !== '' && !is_file($dados . '/fora.txt'));
}
file_put_contents($d . '/link.tar.gz', $tar([['app/l', '', '2']]));
$check('tar: link é recusado', $erroDe(static fn() => Tar::extrair($d . '/link.tar.gz', $d . '/z', 'app/')) !== '');
$bytes = gzdecode((string) file_get_contents($d . '/bom.tar.gz'));
$bytes[3] = 'X';
file_put_contents($d . '/soma.tar.gz', gzencode($bytes));
$check('tar: soma errada é recusada', $erroDe(static fn() => Tar::extrair($d . '/soma.tar.gz', $d . '/w', 'app/')) !== '');
$check('tar: pacote de verdade não tem pasta extra', Tar::relativo('./app/x', 'app/') === 'x');

// ---- assinatura
if (function_exists('sodium_crypto_sign_keypair')) {
    $par = sodium_crypto_sign_keypair();
    $pub = base64_encode(sodium_crypto_sign_publickey($par));
    $json = json_encode(['versao' => '9.9.9', 'pacote' => 'financeiro-app-9.9.9.tar.gz', 'sha256' => str_repeat('a', 64), 'tamanho' => 10, 'notas' => 'x']);
    $sig = base64_encode(sodium_crypto_sign_detached($json, sodium_crypto_sign_secretkey($par)));
    $check('assinatura: a certa passa', Atualizacao::lerManifesto($json, $sig, $pub)['versao'] === '9.9.9');
    $check('assinatura: texto mudado para tudo', str_contains($erroDe(static fn() => Atualizacao::lerManifesto($json . ' ', $sig, $pub)), 'assinatura'));
    $check('assinatura: outra chave para tudo', str_contains($erroDe(static fn() => Atualizacao::lerManifesto($json, $sig, Atualizacao::CHAVE_PUBLICA)), 'assinatura'));
    $check('assinatura: lixo para tudo', str_contains($erroDe(static fn() => Atualizacao::lerManifesto($json, 'lixo', $pub)), 'assinatura'));
    $ruim = json_encode(['versao' => '9.9.9', 'pacote' => '../../fora.tar.gz', 'sha256' => str_repeat('a', 64), 'tamanho' => 10]);
    $sigRuim = base64_encode(sodium_crypto_sign_detached($ruim, sodium_crypto_sign_secretkey($par)));
    $check('assinatura: nome de pacote com caminho é recusado', $erroDe(static fn() => Atualizacao::lerManifesto($ruim, $sigRuim, $pub)) !== '');
}
$check('versão mais velha ou igual não é novidade', !Atualizacao::maisNova('0.0.1') && !Atualizacao::maisNova(Atualizacao::versaoAtual()) && Atualizacao::maisNova('999.0.0'));

// ---- manutenção e configuração
Manutencao::ligar();
$check('manutenção: ligada', Manutencao::ativa());
touch((string) Manutencao::arquivo(), time() - 5000);
$check('manutenção: esquecida não prende o site', !Manutencao::ativa());
Manutencao::desligar();
Primeira::gravarConfig('ACESSO_REDE', '1');
Primeira::gravarConfig('CHAVE_NOVA', 'x');
$conf = (string) file_get_contents(Paths::config());
$check('configuração: troca uma linha e acrescenta outra', str_contains($conf, "ACESSO_REDE=1\n") && !str_contains($conf, 'ACESSO_REDE=0') && str_contains($conf, "CHAVE_NOVA=x\n") && str_contains($conf, 'DB_DRIVER=sqlite'));
$check('configuração: continua privada', (fileperms(Paths::config()) & 0077) === 0);

// ---- rede
$ip = Rede::ipLocal();
$check('IP local: privado ou nenhum', $ip === null || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false);
$qr = QrCode::matriz('http://192.168.0.10:8484');
$check('QR: 25 módulos na versão 2, com localizadores', count($qr) === 25 && $qr[0][0] && $qr[0][6] && !$qr[0][7] && $qr[24][0] && $qr[0][24] && !$qr[7][7]);
$check('QR: SVG com rótulo escapado', str_contains(QrCode::svg('http://x', 'a"b'), 'aria-label="a&quot;b"'));
$check('QR: texto grande demais é recusado', $erroDe(static fn() => QrCode::matriz(str_repeat('x', 300))) !== '');

echo "{$ok} ok, {$falhas} falha(s)\n";
exit($falhas > 0 ? 1 : 0);
