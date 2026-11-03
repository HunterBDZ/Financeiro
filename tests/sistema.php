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

$check('banco novo: o schema já traz as migrações da pasta', Migrador::pendentes($pdo, Migrador::pasta()) === []);

// ---- backup
$pdo->exec("INSERT INTO usuarios (nome, login, senha_hash) VALUES ('Pessoa', 'pessoa', 'x')");
$pdo->exec("INSERT INTO ajustes (chave, label, valor) VALUES ('teste_backup', 'teste', 111)");
$b1 = Backup::criar('manual');
// no Windows, quem protege a pasta de dados são as permissões do perfil da pessoa, não os bits do arquivo
$privado = static fn(string $arq): bool => PHP_OS_FAMILY === 'Windows' || (fileperms($arq) & 0077) === 0;
$check('backup: o arquivo existe e é privado', is_file($b1) && $privado($b1));
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
file_put_contents($pasta . '/s9001_tabela.sql', 'CREATE TABLE teste_mig (a INTEGER);');
file_put_contents($pasta . '/s9001_tabela.php', '<?php return static function (PDO $pdo): void { $pdo->exec("INSERT INTO teste_mig VALUES (1)"); };');
file_put_contents($pasta . '/s9002_erro.sql', 'INSERT INTO teste_mig VALUES (2); INSERT INTO nao_existe VALUES (1);');
file_put_contents($pasta . '/ignorado.sql', 'DROP TABLE usuarios;');
$check('migrações: o .sql antes do .php e nome estranho fora', Migrador::arquivos($pasta) === ['s9001_tabela.sql', 's9001_tabela.php', 's9002_erro.sql']);
$check('migrações: a que falha para tudo', str_contains($erroDe(static fn() => Migrador::aplicar($pdo, $pasta)), 's9002_erro.sql'));
$check('migrações: as anteriores ficam, a que falhou não fica pela metade', (int) $pdo->query('SELECT COUNT(*) FROM teste_mig')->fetchColumn() === 1);
unlink($pasta . '/s9002_erro.sql');
$check('migrações: rodar de novo não repete', Migrador::aplicar($pdo, $pasta) === []);
$check('migrações: o que já rodou e não está na pasta é de versão mais nova', Migrador::desconhecidas($pdo, $dados . '/nao-existe') !== []);
$pdo->exec("DELETE FROM migracoes WHERE arquivo LIKE 's9001%'");
$pdo->exec('DROP TABLE teste_mig');
// backup anterior a uma migração: chega ao banco na versão de agora, mesmo com o gatilho que usa função própria
sleep(1);
$velho = Backup::criar('manual');
$pasta2 = $dados . '/mig2';
mkdir($pasta2);
foreach (Migrador::arquivos(Migrador::pasta()) as $a) {
    copy(Migrador::pasta() . '/' . $a, $pasta2 . '/' . $a);
}
file_put_contents($pasta2 . '/s9003_valor.sql', "UPDATE ajustes SET label = 'migrado' WHERE chave = 'teste_backup';");
$check('restaurar: leva o backup antigo à versão de agora', $erroDe(static fn() => Backup::restaurar($velho, $pasta2)) === '');
// a tabela de migrações veio do backup levado à versão de agora: tem de dizer o que já passou
$pdo = Database::connection();
$check('restaurar: a migração vale nos dados restaurados', $pdo->query("SELECT label FROM ajustes WHERE chave = 'teste_backup'")->fetchColumn() === 'migrado' && in_array('s9003_valor.sql', Migrador::feitas($pdo), true));
$pdo->exec("DELETE FROM migracoes WHERE arquivo = 's9003_valor.sql'");
// backup de antes da ordem do menu (s0003): restaurar traz a coluna, e as contas seguem iguais
$semMenu = $dados . '/sem-menu.sqlite';
copy(Backup::criar('manual'), $semMenu);
$p = new SqliteConexao($semMenu);
$p->exec('ALTER TABLE usuarios DROP COLUMN menu_ordem');
$p->exec("DELETE FROM migracoes WHERE arquivo = 's0003_menu_ordem.sql'");
$p = null;
$check('restaurar: backup de antes da ordem do menu é aceito', $erroDe(static fn() => Backup::restaurar($semMenu)) === '');
$pdo = Database::connection();
$check('restaurar: a coluna da ordem do menu volta pela migração, e as contas seguem', in_array('menu_ordem', array_column($pdo->query('PRAGMA table_info(usuarios)')->fetchAll(PDO::FETCH_ASSOC), 'name'), true)
    && in_array('s0003_menu_ordem.sql', Migrador::feitas($pdo), true) && (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE login = 'pessoa'")->fetchColumn() === 1);
$pdo->exec("UPDATE usuarios SET menu_ordem = '[\"meta\"]' WHERE login = 'pessoa'");
sleep(1);
$comMenu = Backup::criar('manual');
$pdo->exec("UPDATE usuarios SET menu_ordem = NULL WHERE login = 'pessoa'");
Backup::restaurar($comMenu);
$pdo = Database::connection();
$check('backup: a ordem do menu vai junto e volta', $pdo->query("SELECT menu_ordem FROM usuarios WHERE login = 'pessoa'")->fetchColumn() === '["meta"]');
// backup de antes do visual do cartão (s0004): volta com a coluna nova, e o cartão com o Padrão
sleep(1);
$pdo->exec("INSERT INTO cartoes (nome, dia_fechamento, dia_vencimento) VALUES ('Cartão do backup', 25, 5)");
$semVisual = Backup::criar('manual');
$p = new SqliteConexao($semVisual);
$p->exec('DROP TRIGGER upd_cartoes');
$p->exec('ALTER TABLE cartoes DROP COLUMN visual');
$p->exec('CREATE TRIGGER upd_cartoes AFTER UPDATE ON cartoes WHEN NEW.atualizado_em IS OLD.atualizado_em AND (NEW.nome IS NOT OLD.nome) BEGIN UPDATE cartoes SET atualizado_em = agora_local() WHERE rowid = NEW.rowid; END');
$p->exec("DELETE FROM migracoes WHERE arquivo = 's0004_cartao_visual.sql'");
$p = null;
$erroVisual = $erroDe(static fn() => Backup::restaurar($semVisual));
$check('restaurar: backup de antes do visual do cartão volta com a coluna nova', $erroVisual === '', $erroVisual);
$pdo = Database::connection();
$check('restaurar: o cartão do backup fica com o visual Padrão e o gatilho volta', $pdo->query("SELECT visual FROM cartoes WHERE nome = 'Cartão do backup'")->fetch() === ['visual' => null]
    && in_array('s0004_cartao_visual.sql', Migrador::feitas($pdo), true)
    && $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'trigger' AND name = 'upd_cartoes' AND sql LIKE '%NEW.visual%'")->fetchColumn() == 1);

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
// a hora vem de dentro do arquivo, não da data dele: um relógio falso (libfaketime) distorce a data dos arquivos
file_put_contents((string) Manutencao::arquivo(), (string) (time() - 5000));
$check('manutenção: esquecida não prende o site', !Manutencao::ativa());
file_put_contents((string) Manutencao::arquivo(), '');
$check('manutenção: o arquivo vazio do financeiro-atualizar também vale', Manutencao::ativa());
Manutencao::desligar();
Primeira::gravarConfig('ACESSO_REDE', '1');
Primeira::gravarConfig('CHAVE_NOVA', 'x');
$conf = (string) file_get_contents(Paths::config());
$check('configuração: troca uma linha e acrescenta outra', str_contains($conf, "ACESSO_REDE=1\n") && !str_contains($conf, 'ACESSO_REDE=0') && str_contains($conf, "CHAVE_NOVA=x\n") && str_contains($conf, 'DB_DRIVER=sqlite'));
$check('configuração: continua privada', $privado(Paths::config()));

// ---- código da primeira conta: só o modo servidor pede
$mudarConfig = \Closure::bind(static function (string $chave, string $valor): void {
    self::$values[$chave] = $valor;
}, null, \Financeiro\Config::class);
$check('código da conta: o computador pessoal não pede', !Primeira::codigoExigido());
$mudarConfig('FIN_MODO', 'servidor');
$check('código da conta: o servidor pede', Primeira::codigoExigido());
$mudarConfig('FIN_MODO', 'instalador');
@unlink(Primeira::arquivoCodigo());
$logAntes = ini_set('error_log', $dados . '/teste-codigo.log');     // o código vai para o log: aqui, para um arquivo
$codigoConta = Primeira::codigo();
$check('código da conta: escrito no log, para quem instalou achar', str_contains((string) @file_get_contents($dados . '/teste-codigo.log'), $codigoConta));
ini_set('error_log', (string) $logAntes);
$check('código da conta: 12 letras sem as que se confundem, em 3 grupos', (bool) preg_match('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $codigoConta), $codigoConta);
$check('código da conta: o mesmo enquanto não há conta', Primeira::codigo() === $codigoConta);
$check('código da conta: guardado em arquivo privado', $privado(Primeira::arquivoCodigo()));
$check('código da conta: confere sem traço e em minúsculas', Primeira::codigoConfere(strtolower(str_replace('-', ' ', $codigoConta))));
$check('código da conta: errado ou vazio não passa', !Primeira::codigoConfere('AAAA-AAAA-AAAA') && !Primeira::codigoConfere(''));
Primeira::esquecerCodigo();
$check('código da conta: some depois da primeira conta', !is_file(Primeira::arquivoCodigo()));

// ---- rede
$ip = Rede::ipLocal();
$check('IP local: privado ou nenhum', $ip === null || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false);
$qr = QrCode::matriz('http://192.168.0.10:8484');
$check('QR: 25 módulos na versão 2, com localizadores', count($qr) === 25 && $qr[0][0] && $qr[0][6] && !$qr[0][7] && $qr[24][0] && $qr[0][24] && !$qr[7][7]);
$check('QR: SVG com rótulo escapado', str_contains(QrCode::svg('http://x', 'a"b'), 'aria-label="a&quot;b"'));
$check('QR: texto grande demais é recusado', $erroDe(static fn() => QrCode::matriz(str_repeat('x', 300))) !== '');

// ---- comando do PHP e arquivos em uso
$check('comando do PHP: caminho com espaço e argumento', Atualizacao::lerComandoPhp("C:/Pessoa com espaço/frankenphp\tphp-cli") === ['C:/Pessoa com espaço/frankenphp', 'php-cli']);
$check('comando do PHP: como os pacotes antigos de Linux mandam', Atualizacao::lerComandoPhp('/opt/bin/frankenphp php-cli') === ['/opt/bin/frankenphp', 'php-cli']);
$check('comando do PHP: um arquivo só, mesmo com espaço', Atualizacao::lerComandoPhp(__FILE__) === [__FILE__]);
$check('comando do PHP: vazio usa o PHP atual', Atualizacao::lerComandoPhp('') === null);
$deArq = $dados . '/renomear-a';
$paraArq = $dados . '/renomear-b';
file_put_contents($deArq, 'x');
$check('renomear: troca o arquivo', Paths::renomear($deArq, $paraArq, 1) && is_file($paraArq) && !is_file($deArq));
$check('renomear: arquivo que não existe falha, sem esperar à toa', !Paths::renomear($deArq, $paraArq, 0));
unlink($paraArq);

// ---- gerente: Windows e Mac
define('FIN_GERENTE_SO_FUNCOES', true);
require __DIR__ . '/../packaging/gerente.php';
$lugarT = ['programa' => 'C:/Users/Pessoa com espaço/Programs/Financeiro', 'dados' => 'C:/Users/Pessoa com espaço/Financeiro', 'porta' => 8484];
$envWin = gerenteAmbiente($lugarT, 'Windows');
$check('gerente: Windows usa os programas da pasta bin', gerenteBinarios($lugarT['programa'], 'Windows') === ['servidor' => $lugarT['programa'] . '/bin/frankenphp.exe', 'php' => [$lugarT['programa'] . '/bin/php.exe']]);
$check('gerente: Windows acha o php.ini com caminho relativo, sem acentos', $envWin['PHPRC'] === '..\\conf' && $envWin['FIN_GERENCIADO'] === '1');
$check('gerente: o comando do PHP chega inteiro', Atualizacao::lerComandoPhp($envWin['FIN_PHP']) === [$lugarT['programa'] . '/bin/php.exe']);
$check('gerente: o Caddyfile recebe a barra normal', !str_contains(gerenteAmbiente(['programa' => 'C:\\P\\F'] + $lugarT, 'Windows')['FIN_APP'], '\\'));
$envMac = gerenteAmbiente(['programa' => '/U/a/Library/Application Support/Financeiro/programa'] + $lugarT, 'Darwin');
$check('gerente: Mac acha o php.ini pela pasta', str_ends_with($envMac['PHP_INI_SCAN_DIR'], 'programa/conf') && !isset($envMac['PHPRC']));
$check('gerente: Mac tem o programa e o php-cli em partes', count(Atualizacao::lerComandoPhp($envMac['FIN_PHP'])) === 2 && Atualizacao::lerComandoPhp($envMac['FIN_PHP'])[1] === 'php-cli');
$xmlAgente = gerenteAgenteXml(['/Applications/A&B.app/frankenphp', 'php-cli', '/x/gerente.php', 'servir'], '/Applications/A&B.app/frankenphp', '/x');
$docAgente = new DOMDocument();
$check('gerente: o início automático do Mac é um plist válido', @$docAgente->loadXML($xmlAgente) && str_contains($xmlAgente, 'A&amp;B') && str_contains($xmlAgente, '<key>RunAtLoad</key>') && str_contains($xmlAgente, GERENTE_ROTULO));
$pastaG = $dados . '/gerente-teste';
mkdir($pastaG);
file_put_contents($pastaG . '/config.env', "ACESSO_REDE=0\n");
$check('gerente: fora da rede por padrão', gerenteEndereco($pastaG) === '127.0.0.1');
file_put_contents($pastaG . '/config.env', "ACESSO_REDE=1\n");
$check('gerente: na rede só quando a tela liga', gerenteEndereco($pastaG) === '0.0.0.0');
file_put_contents($pastaG . '/porta', '9191');
$check('gerente: a porta salva continua valendo', gerentePorta($pastaG) === 9191);
// o .app traz o programa; a pasta de dados fica com o mais novo
$sem = $pastaG . '/semente';
mkdir($sem . '/app', 0755, true);
file_put_contents($sem . '/app/VERSION', "1.2.0\n");
file_put_contents($sem . '/gerente.php', '<?php');
$lugarM = ['programa' => $sem, 'dados' => $pastaG, 'porta' => 1];
$usarM = gerenteInstalarDoApp($lugarM);
$check('gerente: a primeira abertura leva o programa para a pasta de dados', $usarM['programa'] === $pastaG . '/programa' && trim((string) file_get_contents($pastaG . '/programa/app/VERSION')) === '1.2.0');
file_put_contents($pastaG . '/programa/app/VERSION', "1.3.0\n");
gerenteInstalarDoApp($lugarM);
$check('gerente: um .app mais velho não desfaz a atualização feita pela tela', trim((string) file_get_contents($pastaG . '/programa/app/VERSION')) === '1.3.0');
file_put_contents($sem . '/app/VERSION', "1.4.0\n");
gerenteInstalarDoApp($lugarM);
$check('gerente: um .app mais novo substitui', trim((string) file_get_contents($pastaG . '/programa/app/VERSION')) === '1.4.0' && !is_dir($pastaG . '/programa.antes'));
gerenteApagar($pastaG);
$check('gerente: apagar limpa a pasta inteira', !is_dir($pastaG));

echo "{$ok} ok, {$falhas} falha(s)\n";
exit($falhas > 0 ? 1 : 0);
