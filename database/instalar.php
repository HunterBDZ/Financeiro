<?php
/**
 * Prepara um banco recém-criado com database/schema.sql: categorias, 16 meses a partir do mês atual,
 * ajustes e, na demonstração, os usuários. Não mexe num banco já preparado.
 *
 * Uso: php database/instalar.php          sem conta: a primeira é criada no site
 *      php database/instalar.php --demo   dados fictícios para conhecer o app, com logins ana e bruno
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Rode pela linha de comando.\n");
}

require __DIR__ . '/../src/bootstrap.php';

use Financeiro\Database;
use Financeiro\Models\WishlistModel;
use Financeiro\Services\CartaoFatura;
use Financeiro\Services\LinkProduto;

$pdo = Database::connection();
$demo = in_array('--demo', $argv, true);

if (!$pdo->query("SHOW TABLES LIKE 'usuarios'")->fetch()) {
    exit("O banco está sem as tabelas: importe database/schema.sql antes.\n");
}
if ((int) $pdo->query('SELECT COUNT(*) FROM categorias')->fetchColumn() > 0) {
    exit("Este banco já foi preparado: nada foi alterado.\n");
}

if ($demo) {
    $usuarios = [['nome' => 'Ana', 'login' => 'ana', 'senha' => 'demo1234'], ['nome' => 'Bruno', 'login' => 'bruno', 'senha' => 'demo1234']];
} else {
    $usuarios = []; // a primeira conta nasce no site, em /criar-conta
}

// nome, apelido, grupo, sinal, essencial. O motor e o Cartão acham pelo nome as de Empréstimo,
// Poupança, Investimentos, "Salário" e "Fatura do cartão": não renomeie essas.
$categorias = [
    ['Salário', null, 'Entradas', 1, 0],
    ['Outras entradas (13º, Pix, reembolsos)', 'Outras entradas', 'Entradas', 1, 0],
    ['Crédito do empréstimo', null, 'Emprestimo', 1, 0],
    ['Resgate da poupança', null, 'Poupanca', 1, 0],
    ['Aluguel e condomínio', 'Aluguel', 'Fixos', -1, 1],
    ['Luz', null, 'Fixos', -1, 1],
    ['Internet', null, 'Fixos', -1, 1],
    ['Celular', null, 'Fixos', -1, 1],
    ['Seguro do carro', null, 'Fixos', -1, 1],
    ['Mercado', null, 'Dia a dia', -1, 1],
    ['Combustível', null, 'Dia a dia', -1, 1],
    ['Farmácia e saúde', 'Farmácia', 'Dia a dia', -1, 1],
    ['Delivery / comer fora', 'Delivery', 'Dia a dia', -1, 0],
    ['Lazer e outros', 'Lazer', 'Dia a dia', -1, 0],
    ['Fatura do cartão', 'Fatura', 'Cartao', -1, 1],
    ['Juros do cheque especial', 'Juros', 'Ano', -1, 1],
    ['IPVA', null, 'Ano', -1, 0],
    ['Licenciamento do carro', 'Licenciamento', 'Ano', -1, 0],
    ['Revisão do carro', 'Revisão', 'Ano', -1, 0],
    ['Imposto de renda', null, 'Ano', -1, 0],
    ['Outros gastos pontuais', null, 'Ano', -1, 0],
    ['Amortização extra do empréstimo', 'Amortização extra', 'Emprestimo', -1, 0],
    ['Parcela do empréstimo', null, 'Emprestimo', -1, 0],
    ['Guardar na poupança', null, 'Poupanca', -1, 0],
    ['Resgate de investimentos', null, 'Investimento', 1, 0],
    ['Aplicar em investimentos', null, 'Investimento', -1, 0],
];

$mesAtual = date('Y-m-01');
$somaMes = static fn(string $mes, int $n): string => date('Y-m-01', strtotime($mes . ' ' . ($n >= 0 ? '+' : '') . $n . ' month'));
$inicio = $somaMes($mesAtual, $demo ? -2 : 0);
// sem demonstração, os meses nascem na primeira configuração, no site, a partir da data que a pessoa escolher
$meses = [];
for ($i = 0; $demo && $i <= 2 + Financeiro\Services\Horizonte::MESES_A_FRENTE; $i++) {
    $meses[] = $somaMes($inicio, $i);
}
$ajustes = [   // chave, rótulo, valor; o saldo do empréstimo e os parâmetros se ajustam nas telas depois
    ['saldo_ini', 'Saldo da conta no início', $demo ? 4200.0 : 0.0],
    ['poupanca_ini', 'Poupança no início', $demo ? 3000.0 : 0.0],
    ['investimentos_ini', 'Investimentos no início', $demo ? 5000.0 : 0.0],
    ['recebido_emprestimo', 'Crédito do empréstimo recebido', $demo ? 12000.0 : 0.0],
    ['parcela_mensal', 'Parcela mensal', $demo ? 289.90 : 0.0],
    ['n_parcelas', 'Número de parcelas', $demo ? 72.0 : 0.0],
    ['juros_mes', 'Juros ao mês', $demo ? 0.0165 : 0.0],
    ['consignado_inicio', 'Mês da 1ª parcela do empréstimo (AAAAMM)', $demo ? (float) date('Ym', strtotime($inicio)) : 0.0],
    ['reserva_minima', 'Reserva mínima na conta', $demo ? 1500.0 : 0.0],
    ['meta_final', 'Meta de conta e poupança', $demo ? 25000.0 : 0.0],
    ['meta_mes', 'Mês da meta (AAAAMM)', $demo ? (float) date('Ym', strtotime($somaMes($inicio, 17))) : 0.0],
    ['data_inicio', 'Início do plano (AAAAMMDD)', $demo ? (float) date('Ymd', strtotime($inicio)) : 0.0],
    ['configurado', 'Primeira configuração feita', $demo ? 1.0 : 0.0],
    ['usa_emprestimo', 'Usa a aba Empréstimo', 1.0],
    ['usa_cartao', 'Usa a aba Cartão', 1.0],
    ['parcela_modo', 'Parcela do empréstimo: 1 descontada do salário, 2 paga à parte', 1.0],
    ['objetivo', 'Objetivo da sobra', $demo ? 1.0 : 3.0],
];

$pdo->beginTransaction();
// o schema.sql já contém todas as migrações desta versão
$st = $pdo->prepare('INSERT INTO migracoes (arquivo) VALUES (?)');
foreach (array_merge(glob(__DIR__ . '/migrations/*.sql') ?: [], glob(__DIR__ . '/migrations/*.php') ?: []) as $m) {
    $st->execute([basename($m)]);
}
$st = $pdo->prepare('INSERT INTO categorias (nome, apelido, grupo, sinal, ordem, essencial) VALUES (?, ?, ?, ?, ?, ?)');
foreach ($categorias as $i => [$nome, $apelido, $grupo, $sinal, $essencial]) {
    $st->execute([$nome, $apelido, $grupo, $sinal, $i, $essencial]);
}
$cat = [];
foreach ($pdo->query('SELECT id, nome FROM categorias') as $c) {
    $cat[$c['nome']] = (int) $c['id'];
}
$st = $pdo->prepare('INSERT INTO meses (mes, situacao) VALUES (?, ?)');
foreach ($meses as $m) {
    $st->execute([$m, $m < $mesAtual ? 'REAL' : 'PREVISAO']);
}
$st = $pdo->prepare('INSERT INTO ajustes (chave, label, valor, confirmado) VALUES (?, ?, ?, 1)');
foreach ($ajustes as [$chave, $rotulo, $valor]) {
    $st->execute([$chave, $rotulo, $valor]);
}
$st = $pdo->prepare('INSERT INTO usuarios (nome, login, senha_hash) VALUES (?, ?, ?)');
foreach ($usuarios as $u) {
    $st->execute([$u['nome'], $u['login'], password_hash($u['senha'], PASSWORD_BCRYPT)]);
}

if ($demo) {
    $ids = array_map('intval', $pdo->query('SELECT id FROM usuarios ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    $hoje = date('Y-m-d');
    mt_srand(2026);

    $st = $pdo->prepare('INSERT INTO metas (categoria_id, valor) VALUES (?, ?)');
    foreach (['Mercado' => 1500, 'Combustível' => 450, 'Farmácia e saúde' => 180, 'Delivery / comer fora' => 350, 'Lazer e outros' => 300] as $nome => $v) {
        $st->execute([$cat[$nome], $v]);
    }
    $fixos = ['Aluguel e condomínio' => [2350.00, 5], 'Luz' => [238.40, 10], 'Internet' => [119.90, 12], 'Celular' => [89.90, 15], 'Seguro do carro' => [210.00, 20]];
    $st = $pdo->prepare('INSERT INTO contas_recorrentes (categoria_id, dia_vencimento) VALUES (?, ?)');
    foreach ($fixos as $nome => [, $dia]) {
        $st->execute([$cat[$nome], $dia]);
    }
    $st->execute([$cat['Fatura do cartão'], 5]);

    $prev = $pdo->prepare('INSERT INTO previsao (categoria_id, mes, valor) VALUES (?, ?, ?)');
    foreach ($meses as $i => $m) {
        $prev->execute([$cat['Salário'], $m, 8000.00]);
        foreach ($fixos as $nome => [$v]) {
            $prev->execute([$cat[$nome], $m, $v]);
        }
        if ($m <= $mesAtual) {
            $prev->execute([$cat['Fatura do cartão'], $m, 1900.00]);    // daí em diante vem das compras do cartão
        }
        if (substr($m, 5, 2) === '12') {
            $prev->execute([$cat['Outras entradas (13º, Pix, reembolsos)'], $m, 4600.00]);
        }
        if (substr($m, 5, 2) === '01') {
            $prev->execute([$cat['IPVA'], $m, 1450.00]);
        }
    }
    // meses encerrados guardam a meta como previsão própria, como o fechamento de verdade faz
    $congela = $pdo->prepare('INSERT IGNORE INTO previsao (categoria_id, mes, valor) SELECT categoria_id, ?, valor FROM metas');
    foreach ($meses as $m) {
        if ($m < $mesAtual) {
            $congela->execute([$m]);
        }
    }

    $lanc = $pdo->prepare('INSERT INTO lancamentos (data, descricao, categoria_id, valor, usuario_id) VALUES (?, ?, ?, ?, ?)');
    $lancar = static function (string $mes, int $dia, string $desc, string $nome, float $v, int $quem) use ($lanc, $cat, $ids, $hoje): void {
        $data = substr($mes, 0, 8) . sprintf('%02d', min($dia, (int) date('t', strtotime($mes))));
        if ($data <= $hoje) {
            $lanc->execute([$data, $desc, $cat[$nome], $v, $ids[$quem]]);
        }
    };
    $variaveis = [
        'Mercado' => [['Supermercado', 'Feira', 'Mercadinho'], 6, 90, 340],
        'Combustível' => [['Posto'], 2, 180, 240],
        'Farmácia e saúde' => [['Farmácia'], 2, 35, 95],
        'Delivery / comer fora' => [['Pizza', 'Restaurante', 'Lanche'], 4, 45, 110],
        'Lazer e outros' => [['Cinema', 'Passeio', 'Livro'], 2, 40, 130],
    ];
    foreach ($meses as $m) {
        if ($m > $mesAtual) {
            break;
        }
        $lancar($m, 1, 'Salário', 'Salário', round(8000.00 - 289.90, 2), 0);
        foreach ($fixos as $nome => [$v, $dia]) {
            $lancar($m, $dia, $nome, $nome, $v, 0);
        }
        $lancar($m, 3, 'Guardar na poupança', 'Guardar na poupança', 200.00, 0);
        $lancar($m, 3, 'Tesouro Selic', 'Aplicar em investimentos', 300.00, 1);
        $lancar($m, 5, 'Fatura do cartão', 'Fatura do cartão', round(1700.00 + mt_rand(0, 30000) / 100, 2), 1);
        foreach ($variaveis as $nome => [$descs, $n, $min, $max]) {
            for ($k = 0; $k < $n; $k++) {
                $lancar($m, mt_rand(2, 27), $descs[$k % count($descs)], $nome, mt_rand($min * 100, $max * 100) / 100, $k % 2);
            }
        }
    }
    $pdo->prepare('UPDATE meses SET fechado_em = NOW() WHERE situacao = ?')->execute(['REAL']);

    $pdo->prepare('INSERT INTO cartoes (nome, dia_fechamento, dia_vencimento, limite) VALUES (?, ?, ?, ?)')->execute(['Cartão Exemplo', 25, 5, 12000.00]);
    $idCartao = (int) $pdo->lastInsertId();
    $pdo->exec('UPDATE cartoes SET alimenta_plano = 1');
    $compra = $pdo->prepare('INSERT INTO cartao_compras (cartao_id, data, descricao, categoria_id, valor_total, parcelas, primeira_fatura, usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ([[-70, 'Geladeira', 'Lazer e outros', 3600.00, 10], [-40, 'Notebook', 'Lazer e outros', 4200.00, 12], [-28, 'Sofá', 'Lazer e outros', 2800.00, 10], [-12, 'Passagens', 'Lazer e outros', 1560.00, 6], [-4, 'Farmácia', 'Farmácia e saúde', 84.30, 1], [-2, 'Mercado', 'Mercado', 312.40, 1]] as [$dias, $desc, $nome, $total, $parcelas]) {
        $data = date('Y-m-d', strtotime($hoje . ' ' . $dias . ' day'));
        $compra->execute([$idCartao, $data, $desc, $cat[$nome], $total, $parcelas, CartaoFatura::faturaDe($data, 25, 5), $ids[0]]);
    }

    CartaoFatura::sincronizarPlano($ids[0], $hoje);

    $pos = 0;
    $item = $pdo->prepare('INSERT INTO wishlist_itens (nome, url, valor, moeda, posicao, usuario_id) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ([['Sofá retrátil 3 lugares', 'sofa', 3290.00, 'BRL'], ['Bicicleta aro 29', 'bicicleta', 2450.00, 'BRL'], ['Cafeteira espresso', 'cafeteira', 179.00, 'USD'], ['Aspirador robô', 'aspirador', 1799.90, 'BRL']] as [$nome, $img, $v, $moedaItem]) {
        $item->execute([$nome, 'https://loja.example/produto/' . $img, $v, $moedaItem, ++$pos, $ids[$pos % 2]]);
        $arq = __DIR__ . '/demo/' . $img . '.jpg';
        $foto = is_file($arq) ? LinkProduto::fotoValida((string) file_get_contents($arq)) : null;
        if ($foto !== null) {
            WishlistModel::salvarFoto((int) $pdo->lastInsertId(), $foto);
        }
    }

    $saldo = (float) $pdo->query("SELECT COALESCE(SUM(l.valor * c.sinal), 0) FROM lancamentos l JOIN categorias c ON c.id = l.categoria_id WHERE l.data <= " . $pdo->quote($hoje))->fetchColumn() + 4200.00;
    $pdo->prepare('INSERT INTO saldo_conferencias (data, saldo_informado, usuario_id) VALUES (?, ?, ?)')->execute([$hoje, round($saldo, 2), $ids[0]]);
}
$pdo->commit();

echo $demo
    ? "Pronto, com dados fictícios. Entre com ana / demo1234 ou bruno / demo1234. Não use a demonstração para dados reais.\n"
    : 'Pronto. ' . count($categorias) . " categorias. Abra o site para criar a sua conta.\n";
