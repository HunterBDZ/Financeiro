<?php
/**
 * Seed inicial — importa os dados reais da planilha (database/data/dados_planilha.json)
 * pro banco "financeiro" e cria os dois usuários de acesso.
 *
 * Uso: php database/seed.php
 * Idempotente o bastante pra rodar de novo sem duplicar categorias/meses/ajustes/usuários
 * (mas lançamentos e previsão são inseridos direto — não rode duas vezes num banco com dados
 * já editados manualmente, ou vai duplicar lançamentos).
 */
declare(strict_types=1);

require __DIR__ . '/../src/Config.php';
require __DIR__ . '/../src/Database.php';

use Financeiro\Config;
use Financeiro\Database;

Config::load(__DIR__ . '/../config/.env');
$pdo = Database::connection();

$json = json_decode(file_get_contents(__DIR__ . '/data/dados_planilha.json'), true, flags: JSON_THROW_ON_ERROR);

$grupoMap = [
    'Entradas'   => 'Entradas',
    'Fixos'      => 'Fixos',
    'Dia a dia'  => 'Dia a dia',
    'Cartão'     => 'Cartao',
    'Ano'        => 'Ano',
    'Empréstimo' => 'Emprestimo',
    'Poupança'   => 'Poupanca',
];

echo "== 1/8 categorias ==\n";
$stmt = $pdo->prepare('INSERT INTO categorias (nome, grupo, sinal, ordem) VALUES (:nome, :grupo, :sinal, :ordem)
                        ON DUPLICATE KEY UPDATE grupo = VALUES(grupo), sinal = VALUES(sinal)');
foreach ($json['categorias'] as $i => $cat) {
    $stmt->execute([
        'nome'  => $cat['categoria'],
        'grupo' => $grupoMap[$cat['grupo']] ?? $cat['grupo'],
        'sinal' => (int) $cat['sinal'],
        'ordem' => $i,
    ]);
}
echo count($json['categorias']) . " categorias ok\n";

echo "== 2/8 ajustes ==\n";
$ajusteKeyMap = [
    'saldo_ini_31_08'     => 'saldo_ini',
    'poupanca_23_09'      => 'poupanca_ini',
    'recebido_emprestimo' => 'recebido_emprestimo',
    'parcela_mensal'      => 'parcela_mensal',
    'n_parcelas'          => 'n_parcelas',
    'juros_mes'           => 'juros_mes',
    'reserva_minima'      => 'reserva_minima',
    'meta_final'          => 'meta_final',
];
$stmt = $pdo->prepare('INSERT INTO ajustes (chave, label, valor, confirmado) VALUES (:chave, :label, :valor, :confirmado)
                        ON DUPLICATE KEY UPDATE label = VALUES(label)');
foreach ($ajusteKeyMap as $chaveOriginal => $chaveNova) {
    $aj = $json['ajustes'][$chaveOriginal] ?? null;
    if ($aj === null) {
        continue;
    }
    $stmt->execute([
        'chave'      => $chaveNova,
        'label'      => $aj['label'],
        'valor'      => (float) $aj['valor'],
        'confirmado' => ($aj['confirmado'] ?? '') === '☑' ? 1 : 0,
    ]);
}
echo "ajustes ok (devedor_hoje é calculado, não armazenado)\n";

echo "== 3/8 meses ==\n";
$stmt = $pdo->prepare('INSERT INTO meses (mes, situacao) VALUES (:mes, :situacao) ON DUPLICATE KEY UPDATE situacao = situacao');
foreach ($json['meses'] as $i => $m) {
    $mes = date('Y-m-01', strtotime($m['data']));
    $situacao = $i === 0 ? 'REAL' : 'PREVISAO'; // set/26 já fechado (livro-caixa importado do extrato real)
    $stmt->execute(['mes' => $mes, 'situacao' => $situacao]);
}
echo count($json['meses']) . " meses ok\n";

echo "== 4/8 usuários ==\n";
$stmt = $pdo->prepare('INSERT IGNORE INTO usuarios (nome, login, senha_hash) VALUES (:nome, :login, :hash)');
foreach ([['Matheus', 'hunter'], ['Natalia', 'esposa']] as [$nome, $login]) {
    $stmt->execute(['nome' => $nome, 'login' => $login, 'hash' => password_hash('123456', PASSWORD_BCRYPT)]);
}
echo "usuários hunter/esposa ok (senha inicial: 123456 — troque depois)\n";
$usuarioId = (int) $pdo->query("SELECT id FROM usuarios WHERE login = 'hunter'")->fetchColumn();

echo "== 5/8 lançamentos ==\n";
$categoriaIdPorNome = [];
foreach ($pdo->query('SELECT id, nome FROM categorias') as $row) {
    $categoriaIdPorNome[$row['nome']] = (int) $row['id'];
}
$jaTemLancamentos = (int) $pdo->query('SELECT COUNT(*) FROM lancamentos')->fetchColumn();
if ($jaTemLancamentos > 0) {
    echo "já existem {$jaTemLancamentos} lançamentos — pulando importação pra não duplicar\n";
} else {
    $stmt = $pdo->prepare('INSERT INTO lancamentos (data, descricao, categoria_id, valor, usuario_id)
                            VALUES (:data, :descricao, :categoria_id, :valor, :usuario_id)');
    $n = 0;
    foreach ($json['lancamentos'] as $l) {
        $categoriaId = $categoriaIdPorNome[$l['categoria']] ?? null;
        if ($categoriaId === null) {
            fwrite(STDERR, "categoria desconhecida, pulando: {$l['categoria']}\n");
            continue;
        }
        $stmt->execute([
            'data'         => date('Y-m-d', strtotime($l['data'])),
            'descricao'    => $l['descricao'],
            'categoria_id' => $categoriaId,
            'valor'        => (float) $l['valor'],
            'usuario_id'   => $usuarioId,
        ]);
        $n++;
    }
    echo "{$n} lançamentos importados\n";
}

echo "== 6/8 previsão ==\n";
$categoriaDireta = [
    'Salário (antes da parcela do consignado)' => 'Salário',
    'Outras entradas (13º, Pix, reembolsos)'   => 'Outras entradas (13º, Pix, reembolsos)',
    'Crédito do empréstimo'                    => 'Crédito do empréstimo',
    'Aluguel e condomínio' => 'Aluguel e condomínio', 'Luz' => 'Luz', 'Internet' => 'Internet',
    'Celular' => 'Celular', 'Faxineira' => 'Faxineira', 'Barbeiro' => 'Barbeiro',
    'Seguro do carro' => 'Seguro do carro', 'Assessoria de treino' => 'Assessoria de treino', 'Spotify' => 'Spotify',
    'Mercado' => 'Mercado', 'Combustível' => 'Combustível', 'Delivery / comer fora' => 'Delivery / comer fora',
    'Lanches no quartel / café' => 'Lanches no quartel / café', 'Farmácia e saúde' => 'Farmácia e saúde',
    'Lazer e outros' => 'Lazer e outros',
    'Juros do cheque especial' => 'Juros do cheque especial', 'IPVA' => 'IPVA',
    'Licenciamento do carro' => 'Licenciamento do carro', 'Revisão do carro' => 'Revisão do carro',
    'Imposto de renda' => 'Imposto de renda', 'Outros gastos pontuais' => 'Outros gastos pontuais',
];
$rotulosCartao = ['Compras parceladas', 'Academia', 'Claude', 'HackTheBox Academy', 'HackTheBox VIP+', 'Obsidian Sync', 'YouTube Premium', 'Outras compras no cartão'];

$mesesLista = array_map(fn($m) => date('Y-m-01', strtotime($m['data'])), $json['meses']);
$porRotulo = [];
foreach ($json['previsao_bruta'] as $row) {
    $porRotulo[$row['rotulo']] = $row['valores_por_mes'];
}

$acumulado = []; // [mes][categoria_nome] => valor, pra somar as linhas do cartão numa única categoria
foreach ($categoriaDireta as $rotulo => $catNome) {
    if (!isset($porRotulo[$rotulo])) {
        continue;
    }
    foreach ($mesesLista as $i => $mes) {
        $v = (float) ($porRotulo[$rotulo][$i] ?? 0);
        if ($v != 0) {
            $acumulado[$mes][$catNome] = ($acumulado[$mes][$catNome] ?? 0) + $v;
        }
    }
}
foreach ($rotulosCartao as $rotulo) {
    if (!isset($porRotulo[$rotulo])) {
        continue;
    }
    foreach ($mesesLista as $i => $mes) {
        $v = (float) ($porRotulo[$rotulo][$i] ?? 0);
        if ($v != 0) {
            $acumulado[$mes]['Fatura do cartão'] = ($acumulado[$mes]['Fatura do cartão'] ?? 0) + $v;
        }
    }
}

$stmt = $pdo->prepare('INSERT INTO previsao (categoria_id, mes, valor) VALUES (:categoria_id, :mes, :valor)
                        ON DUPLICATE KEY UPDATE valor = VALUES(valor)');
$n = 0;
foreach ($acumulado as $mes => $categorias) {
    foreach ($categorias as $catNome => $valor) {
        $categoriaId = $categoriaIdPorNome[$catNome] ?? null;
        if ($categoriaId === null) {
            continue;
        }
        $stmt->execute(['categoria_id' => $categoriaId, 'mes' => $mes, 'valor' => round($valor, 2)]);
        $n++;
    }
}
echo "{$n} linhas de previsão importadas\n";

echo "== 7/8 contas recorrentes ==\n";
// dia de vencimento inferido da data real em que cada uma foi paga em setembro/26
// (só o Spotify tinha isso confirmado na planilha original — o resto é dado real, não chute)
$vencimentos = [
    'Spotify'              => 27,
    'Aluguel e condomínio' => 8,
    'Luz'                  => 8,
    'Internet'             => 8,
    'Celular'              => 8,
    'Faxineira'            => 8,
    'Barbeiro'             => 14,
    'Fatura do cartão'     => 3,
];
$stmt = $pdo->prepare('INSERT INTO contas_recorrentes (categoria_id, dia_vencimento) VALUES (:id, :dia)
                        ON DUPLICATE KEY UPDATE dia_vencimento = VALUES(dia_vencimento)');
foreach ($vencimentos as $catNome => $dia) {
    if (isset($categoriaIdPorNome[$catNome])) {
        $stmt->execute(['id' => $categoriaIdPorNome[$catNome], 'dia' => $dia]);
    }
}
echo count($vencimentos) . " contas recorrentes ok\n";

echo "== 8/8 conferência inicial de saldo ==\n";
$jaTemConferencia = (int) $pdo->query('SELECT COUNT(*) FROM saldo_conferencias')->fetchColumn();
if ($jaTemConferencia === 0) {
    $pdo->prepare('INSERT INTO saldo_conferencias (data, saldo_informado, usuario_id) VALUES (:data, :saldo, :usuario_id)')
        ->execute(['data' => '2026-09-24', 'saldo' => 4117.63, 'usuario_id' => $usuarioId]);
    echo "conferência de 24/09/2026 (R$ 4.117,63) ok\n";
} else {
    echo "já existe conferência registrada — pulando\n";
}

echo "\nSeed concluído.\n";
