<?php
/**
 * A chegada do começo guiado: o mês montado e o primeiro "Dá para gastar", com a conta do Início. É o fim da
 * experiência (o pico-fim): a resposta grande no painel do mês, a prévia embaixo e uma ação só.
 * Variáveis: $chegada (ComecarController::chegada, ou null sem plano), $emprestimo (bool: falta informar
 * os dados do empréstimo, então a ação é essa).
 */
$casca = ['titulo' => t('Tudo pronto') . ' · MyMonetto', 'classe' => 'com com-pronto', 'idiomas' => false];
require __DIR__ . '/../auth/_casca.php';
$ultimoDia = $chegada !== null ? (int) date('t', strtotime($chegada['mes'])) : 0;
?>
    <div class="com-grade">
        <div class="com-pergunta">
            <h1 class="com-titulo"><?= e(t('Seu mês está montado')) ?></h1>
            <p class="com-ajuda"><?= e(t('Daqui em diante, lance o que entra e o que sai. A cada lançamento, o MyMonetto refaz a conta.')) ?></p>
            <div class="com-acoes">
                <?php if ($emprestimo): ?>
                    <a class="btn btn-principal" href="/emprestimo?dados=1#emp-dados"><?= e(t('Informar os dados do empréstimo')) ?></a>
                    <a class="btn-link" href="/"><?= e(t('Ir para o Início')) ?></a>
                <?php else: ?>
                    <a class="btn btn-principal" href="/" data-autofocus><?= e(t('Abrir o Início')) ?></a>
                <?php endif; ?>
            </div>
        </div>

        <section class="com-chegada painel" aria-labelledby="com-chegada-t">
            <?php if ($chegada === null): ?>
                <p class="rot-resposta" id="com-chegada-t"><?= e(t('Prévia do seu mês')) ?></p>
            <?php elseif (in_array($chegada['tipo'], ['gastar', 'justo'], true)): ?>
                <h2 class="rot-resposta" id="com-chegada-t"><?= e(t('Dá para gastar até dia {dia}', ['dia' => $ultimoDia])) ?></h2>
                <p class="resposta-num com-chegada-num"><?= valor((float) $chegada['valor'], 'neutro', ['moeda' => true]) ?></p>
                <p class="por-dia"><?= $chegada['reserva'] > 0
                    ? e(t('sem passar da reserva de {valor}', ['valor' => money((float) $chegada['reserva'])]))
                    : e(t('até o fim de {mes}, com as contas e as metas já descontadas', ['mes' => mesExtenso($chegada['mes'])])) ?></p>
            <?php else: ?>
                <h2 class="rot-resposta" id="com-chegada-t"><?= e($chegada['tipo'] === 'vermelho' ? t('Pelo previsto, a conta fica no vermelho') : t('Pelo previsto, o mês fecha abaixo da reserva')) ?></h2>
                <p class="resposta-num com-chegada-num"><?= valor((float) $chegada['valor'], 'neutro', ['moeda' => true]) ?></p>
                <p class="por-dia"><?= e($chegada['tipo'] === 'vermelho' ? t('é quanto falta para zerar. Confira os valores no Mês.') : t('é quanto falta para a reserva. Confira os valores no Mês.')) ?></p>
            <?php endif; ?>
        </section>
    </div>
<?php require __DIR__ . '/../auth/_casca_fim.php'; ?>
