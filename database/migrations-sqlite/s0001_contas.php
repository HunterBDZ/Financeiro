<?php
/**
 * 2.0, a parte de dados (o .sql de mesmo nome já criou as tabelas e as colunas):
 * - a chave das categorias especiais, achadas pelo nome que elas têm desde a instalação (o motor e o Cartão da 1.10
 *   procuravam por esses nomes; a Fatura era a primeira ativa do grupo Cartão);
 * - de que fatura é cada pagamento antigo, pela regra da 1.10 (CartaoFatura::atribuir), para os números não mudarem.
 * Idempotente: só preenche o que está vazio.
 */
declare(strict_types=1);

use Financeiro\Services\CartaoFatura;

return static function (PDO $pdo): void {
    $chaves = [
        'salario' => 'Salário', 'credito_emprestimo' => 'Crédito do empréstimo', 'amortizacao' => 'Amortização extra do empréstimo',
        'guardar' => 'Guardar na poupança', 'resgate_poupanca' => 'Resgate da poupança', 'parcela' => 'Parcela do empréstimo',
        'aplicar' => 'Aplicar em investimentos', 'resgate_investimento' => 'Resgate de investimentos',
    ];
    $st = $pdo->prepare('UPDATE categorias SET chave = :k WHERE nome = :n AND chave IS NULL AND NOT EXISTS (SELECT 1 FROM categorias c2 WHERE c2.chave = :k2)');
    foreach ($chaves as $k => $nome) {
        $st->execute(['k' => $k, 'n' => $nome, 'k2' => $k]);
    }
    // a da fatura: a mesma que CartaoModel::categoriaFatura() achava (a primeira ativa de gasto do grupo Cartão);
    // sem nenhuma ativa, a primeira do grupo, para continuar fora do plano como estava
    $fat = $pdo->query("SELECT id FROM categorias WHERE grupo = 'Cartao' AND sinal = -1 ORDER BY ativo DESC, ordem, id LIMIT 1")->fetchColumn();
    if ($fat !== false && $pdo->query("SELECT 1 FROM categorias WHERE chave = 'fatura'")->fetchColumn() === false) {
        $pdo->prepare("UPDATE categorias SET chave = 'fatura' WHERE id = :id")->execute(['id' => (int) $fat]);
    }

    CartaoFatura::preencherPagamentos($pdo);
};
