<?php
/**
 * A prévia do mês do começo guiado: o saldo do início, o que entra e as contas fixas do primeiro mês, e a sobra antes
 * do dia a dia, sob o fio duplo do livro-caixa. O saldo é fato (tinta cheia); o resto é previsão (tinta clara).
 * Linha ainda sem resposta diz "a responder"; linha que venceu antes do início diz "já no saldo" e não entra na conta.
 * O entrar.js refaz a conta enquanto a pessoa digita (data-previa-*) e acende a linha do campo em foco.
 * Variáveis: $previa (ComecarController::previa), $comNovas (bool: lugar para contas novas), $comUsos (bool: a linha
 * "Também no app", depois que a pessoa disse o que usa).
 */
$diaIni = (int) $previa['diaIni'];
$renda = $previa['renda'];
$saldo = $previa['saldo'];
$soma = (float) ($saldo ?? 0) + ($renda !== null && !$renda['antes'] ? (float) $renda['valor'] : 0.0);
foreach ($previa['contas'] as $c) {
    $soma -= $c['antes'] ? 0.0 : (float) $c['valor'];
}
$algum = $saldo !== null;
$falta = '<span class="com-falta">' . e(t('a responder')) . '</span>';
$antes = '<span class="com-antes">' . e(t('já no saldo')) . '</span>';
$tituloMes = t('{mes} de {ano}', ['mes' => mb_convert_case(mesExtenso($previa['mes']), MB_CASE_TITLE), 'ano' => substr($previa['mes'], 0, 4)]);
?>
<aside class="com-previa painel" aria-labelledby="com-previa-t" data-previa data-dec="<?= e(sepDecimal()) ?>" data-mil="<?= e(\Financeiro\Idioma::separadores()[1]) ?>" data-dia-ini="<?= $diaIni ?>" data-antes="<?= e(t('já no saldo')) ?>" data-moeda="<?= e(moedaSimbolo()) ?>">
    <p class="rot-resposta"><?= e(t('Prévia do seu mês')) ?></p>
    <h2 class="com-previa-t" id="com-previa-t"><?= e($tituloMes) ?></h2>

    <ul class="com-linhas">
        <li class="com-grupo-l"><?= e(t('Entradas')) ?></li>
        <li class="com-l" data-previa-linha="saldo" data-valor="<?= $saldo !== null ? e((string) $saldo) : '' ?>">
            <span class="com-l-n"><?= e(t('Saldo no início')) ?></span>
            <span class="com-l-v" data-previa-v><?= $saldo !== null ? valor((float) $saldo) : $falta ?></span>
        </li>
        <li class="com-l<?= $renda !== null && $renda['antes'] ? ' is-antes' : '' ?>" data-previa-linha="renda" data-valor="<?= $renda !== null && $renda['valor'] > 0 ? e((string) $renda['valor']) : '' ?>">
            <span class="com-l-n"><?= e(t('O que entra por mês')) ?><?= $renda !== null && $renda['antes'] ? ' ' . $antes : '' ?></span>
            <span class="com-l-v" data-previa-v><?= $renda !== null && $renda['valor'] > 0 ? valor((float) $renda['valor'], 'previsto') : $falta ?></span>
        </li>

        <li class="com-grupo-l"><?= e(t('Contas fixas')) ?></li>
        <?php if ($previa['contas'] === []): ?>
            <li class="com-l is-vazia"><span class="com-l-n com-falta"><?= e(t('Entram no último passo.')) ?></span></li>
        <?php endif; ?>
        <?php foreach ($previa['contas'] as $c): ?>
            <li class="com-l<?= $c['antes'] ? ' is-antes' : '' ?>" data-previa-linha="conta-<?= (int) $c['id'] ?>" data-valor="<?= $c['valor'] > 0 ? e((string) $c['valor']) : '' ?>" data-saida>
                <span class="com-l-n"><?= e($c['nome']) ?><?= $c['antes'] ? ' ' . $antes : '' ?></span>
                <span class="com-l-v" data-previa-v><?= $c['valor'] > 0 ? valor(-(float) $c['valor'], 'previsto') : $falta ?></span>
            </li>
        <?php endforeach; ?>
        <?php if (!empty($comNovas)): ?><li hidden data-previa-novas></li><?php endif; ?>
    </ul>

    <div class="com-total">
        <span class="com-total-n"><?= e(t('Sobra antes do dia a dia')) ?></span>
        <span class="com-total-v" data-previa-total><?= $algum ? valor($soma, 'neutro', ['moeda' => true]) : $falta ?></span>
    </div>
    <?php if (!empty($comUsos)): ?>
        <p class="com-usos" data-previa-usos<?= empty($previa['usos']['cartao']) && empty($previa['usos']['emprestimo']) ? ' hidden' : '' ?>><?= e(t('Também no app:')) ?> <span data-uso="cartao"<?= empty($previa['usos']['cartao']) ? ' hidden' : '' ?>><?= e(t('a aba Cartão')) ?></span><span data-uso="emprestimo"<?= empty($previa['usos']['emprestimo']) ? ' hidden' : '' ?>><?= e(t('a aba Empréstimo')) ?></span></p>
    <?php endif; ?>
</aside>
