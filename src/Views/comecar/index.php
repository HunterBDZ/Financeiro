<?php
/**
 * O começo guiado, sem o layout (auth/_casca): $passo (1 a 4), $total, $hoje, $inicio, $contas, $usos, $erro, $previa e
 * $campos (o valor de cada campo: o que foi digitado antes de um erro ou o que já está salvo).
 * O app se monta na tela: à esquerda, a pergunta do passo; à direita, a prévia do mês (comecar/_previa), que se
 * preenche a cada resposta. Só o 1º passo é obrigatório; o 3º vem antes das contas para a lista mostrar só o que se
 * aplica. A regra do início no meio do mês não vira texto: acontece na prévia ("já no saldo").
 * As perguntas da parcela e da sobra só aparecem com o empréstimo marcado (CSS, sem JS).
 */
use Financeiro\Site;

$moeda = (string) ($moeda ?? Site::moeda());
$fuso = (string) ($fuso ?? Site::fuso());
$fusoEscolhido = (bool) ($fusoEscolhido ?? true);
$campos = $campos ?? [];
$moeda = is_string($campos['moeda'] ?? null) ? $campos['moeda'] : $moeda;
$fuso = is_string($campos['fuso'] ?? null) ? $campos['fuso'] : $fuso;
// o valor de um campo; em listas (valor[id]), o da chave pedida
$val = static function (string $nome, int|string|null $chave = null) use ($campos): string {
    $v = $campos[$nome] ?? '';
    if ($chave !== null) {
        $v = is_array($v) ? ($v[$chave] ?? '') : '';
    }
    return is_string($v) ? $v : '';
};
$previa = $previa ?? ['mes' => substr($inicio, 0, 7) . '-01', 'diaIni' => (int) substr($inicio, 8, 2), 'saldo' => null, 'renda' => null, 'contas' => [], 'usos' => []];
$titulos = [1 => t('A partir de que dia quer controlar?'), 2 => t('Quanto entra por mês?'), 3 => t('O que você usa?'), 4 => t('Quais contas são fixas?')];
$nomes = [1 => t('Saldo'), 2 => t('Renda'), 3 => t('O que usa'), 4 => t('Contas')];
$erroId = $erro !== null ? ' aria-describedby="com-erro"' : '';
$erroP = $erro !== null ? '<p class="pub-erro" id="com-erro" role="alert">' . e($erro) . '</p>' : '';
$moedas = Site::opcoesMoeda();
$fusos = Site::opcoesFuso();
$casca = ['titulo' => t('Primeira configuração') . ' · MyMonetto', 'classe' => 'com', 'voltaIdioma' => '/comecar?passo=' . $passo,
    'js' => ['/assets/js/fuso.js', '/assets/js/entrar.js']];
require __DIR__ . '/../auth/_casca.php';
?>
    <ol class="com-passos" aria-label="<?= e(t('Passos')) ?>">
        <?php foreach ($nomes as $n => $nome): ?>
            <li class="<?= $n < $passo ? 'is-feito' : ($n === $passo ? 'is-agora' : '') ?>"<?= $n === $passo ? ' aria-current="step"' : '' ?>><span class="com-passo-n num"><?= $n ?></span><?= e($nome) ?></li>
        <?php endforeach; ?>
    </ol>

    <div class="com-grade">
        <form class="com-pergunta" method="post" action="/comecar">
            <?= csrfField() ?>
            <input type="hidden" name="passo" value="<?= (int) $passo ?>">
            <h1 class="com-titulo"><?= e($titulos[$passo]) ?></h1>

            <?php if ($passo === 1): ?>
                <p class="com-ajuda"><?= e(t('Escolha o dia em que começa a controlar e informe quanto você tinha no início desse dia. O que aconteceu antes não precisa ser lançado.')) ?></p>
                <div class="com-campos">
                    <label class="campo"><span><?= e(t('Data de início')) ?></span><input type="date" name="data_inicio" value="<?= e($val('data_inicio') !== '' ? $val('data_inicio') : $inicio) ?>" max="<?= e($hoje) ?>" required></label>
                    <label class="campo"><span><?= e(t('Saldo da conta')) ?></span><input type="text" inputmode="decimal" name="saldo_ini" value="<?= e($val('saldo_ini')) ?>" placeholder="<?= e(t('ex.: {valor}', ['valor' => numInput(1250)])) ?>" autocomplete="off" data-previa-campo="saldo"<?= $erroId ?>></label>
                </div>
                <?= $erroP ?>
                <details class="dobra com-dobra">
                    <summary><span class="com-dobra-n"><?= e(t('Poupança e investimentos')) ?></span> <span class="com-dobra-v"><?= e(t('se tiver')) ?></span></summary>
                    <div class="com-campos">
                        <label class="campo"><span><?= e(t('Poupança')) ?></span><input type="text" inputmode="decimal" name="poupanca_ini" value="<?= e($val('poupanca_ini')) ?>" placeholder="<?= e(numInput(0)) ?>" autocomplete="off"></label>
                        <label class="campo"><span><?= e(t('Outros investimentos')) ?></span><input type="text" inputmode="decimal" name="investimentos_ini" value="<?= e($val('investimentos_ini')) ?>" placeholder="<?= e(numInput(0)) ?>" autocomplete="off"></label>
                    </div>
                </details>
                <details class="dobra com-dobra">
                    <summary><span class="com-dobra-n"><?= e(t('Moeda e fuso horário')) ?></span> <span class="com-dobra-v" data-com-regional><?= e(($moedas[$moeda] ?? $moeda) . ', ' . ($fusos[$fuso] ?? $fuso)) ?></span></summary>
                    <div class="com-campos">
                        <label class="campo"><span><?= e(t('Moeda')) ?></span><select name="moeda"><?php foreach ($moedas as $cod => $rotulo): ?><option value="<?= e($cod) ?>"<?= $cod === $moeda ? ' selected' : '' ?>><?= e($rotulo) ?></option><?php endforeach; ?></select></label>
                        <label class="campo"><span><?= e(t('Fuso horário')) ?></span><select name="fuso" data-fuso<?= $fusoEscolhido ? '' : ' data-fuso-auto' ?>><?php foreach ($fusos as $nome => $rotulo): ?><option value="<?= e($nome) ?>"<?= $nome === $fuso ? ' selected' : '' ?>><?= e($rotulo) ?></option><?php endforeach; ?></select></label>
                    </div>
                </details>

            <?php elseif ($passo === 2): ?>
                <p class="com-ajuda"><?= e(t('Usamos esse valor para estimar os próximos meses. Você pode mudar qualquer mês depois.')) ?></p>
                <div class="com-campos com-campos-2">
                    <label class="campo"><span><?= e(t('Quanto entra por mês, em média')) ?></span><input type="text" inputmode="decimal" name="renda" value="<?= e($val('renda')) ?>" placeholder="<?= e(t('ex.: {valor}', ['valor' => numInput(4500)])) ?>" autocomplete="off" data-previa-campo="renda" data-autofocus<?= $erroId ?>></label>
                    <label class="campo"><span><?= e(t('Dia em que recebe')) ?></span><input type="text" inputmode="numeric" name="dia" maxlength="2" value="<?= e($val('dia')) ?>" placeholder="<?= e(t('ex.: {dia}', ['dia' => 5])) ?>" autocomplete="off" data-previa-dia="renda"></label>
                </div>
                <?= $erroP ?>

            <?php elseif ($passo === 3): ?>
                <p class="com-ajuda"><?= e(t('As abas do que você não usa ficam escondidas. Dá para mudar depois em Ajustes.')) ?></p>
                <div class="com-opcoes">
                    <label class="com-opcao"><span><b><?= e(t('Cartão de crédito')) ?></b><small><?= e(t('Mostra a aba Cartão, com a fatura do mês e as próximas.')) ?></small></span><input type="checkbox" name="usa_cartao" value="1" data-previa-uso="cartao"<?= !empty($usos['cartao']) ? ' checked' : '' ?>></label>
                    <label class="com-opcao"><span><b><?= e(t('Empréstimo ou financiamento')) ?></b><small><?= e(t('Mostra a aba Empréstimo, com quanto falta pagar e quando termina.')) ?></small></span><input type="checkbox" name="usa_emprestimo" value="1" data-previa-uso="emprestimo"<?= !empty($usos['emprestimo']) ? ' checked' : '' ?>></label>
                    <label class="com-opcao"><span><b><?= e(t('Carro')) ?></b><small><?= e(t('Traz as categorias de carro, como combustível e manutenção.')) ?></small></span><input type="checkbox" name="tem_carro" value="1"<?= !empty($usos['carro']) ? ' checked' : '' ?>></label>
                </div>
                <fieldset class="com-grupo com-so-emp">
                    <legend><?= e(t('Como a parcela é paga?')) ?></legend>
                    <label class="com-radio"><input type="radio" name="parcela_modo" value="2"<?= ($usos['parcela'] ?? 2) === 2 ? ' checked' : '' ?>><span><?= e(t('Pago à parte, com o dinheiro da conta')) ?></span></label>
                    <label class="com-radio"><input type="radio" name="parcela_modo" value="1"<?= ($usos['parcela'] ?? 2) === 1 ? ' checked' : '' ?>><span><?= e(t('A parcela já sai direto da renda, antes de cair na conta')) ?></span></label>
                </fieldset>
                <fieldset class="com-grupo com-so-emp">
                    <legend><?= e(t('O que fazer com o que sobrar?')) ?></legend>
                    <label class="com-radio"><input type="radio" name="objetivo" value="3"<?= ($usos['objetivo'] ?? 3) === 3 ? ' checked' : '' ?>><span><?= e(t('Deixar na conta')) ?></span></label>
                    <label class="com-radio"><input type="radio" name="objetivo" value="1"<?= ($usos['objetivo'] ?? 3) === 1 ? ' checked' : '' ?>><span><?= e(t('Usar para adiantar o empréstimo')) ?></span></label>
                </fieldset>

            <?php else: ?>
                <p class="com-ajuda"><?= e(t('Preencha só as que você tem. O dia do vencimento é opcional e faz o app avisar quando estiver perto.')) ?></p>
                <div class="com-contas" role="group" aria-label="<?= e(t('Contas fixas')) ?>">
                    <span class="com-contas-c" aria-hidden="true"><?= e(t('Conta')) ?></span><span class="com-contas-c" aria-hidden="true"><?= e(t('Valor')) ?></span><span class="com-contas-c" aria-hidden="true"><?= e(t('Dia')) ?></span>
                    <?php foreach ($contas as $c): ?>
                        <span class="com-conta-n"><?= e($c['nome']) ?></span>
                        <input type="text" inputmode="decimal" name="valor[<?= (int) $c['id'] ?>]" value="<?= e($val('valor', (int) $c['id'])) ?>" placeholder="<?= e(numInput(0)) ?>" autocomplete="off" aria-label="<?= e(t('Valor de {nome}', ['nome' => $c['nome']])) ?>" data-previa-campo="conta-<?= (int) $c['id'] ?>"<?= $erroId ?>>
                        <input type="text" inputmode="numeric" maxlength="2" name="dia[<?= (int) $c['id'] ?>]" value="<?= e($val('dia', (int) $c['id'])) ?>" placeholder="<?= e(t('ex.: {dia}', ['dia' => 10])) ?>" autocomplete="off" aria-label="<?= e(t('Dia de vencimento de {nome}', ['nome' => $c['nome']])) ?>" data-previa-dia="conta-<?= (int) $c['id'] ?>">
                    <?php endforeach; ?>
                    <?php
                    // as contas novas digitadas antes de um erro voltam todas; sem nenhuma, uma linha vazia
                    $novas = array_keys(array_filter(is_array($campos['nova_nome'] ?? null) ? $campos['nova_nome'] : [], static fn($n): bool => is_string($n) && trim($n) !== '')) ?: [0];
                    foreach ($novas as $n): ?>
                    <div class="com-nova" data-com-nova>
                        <input type="text" name="nova_nome[]" maxlength="100" value="<?= e($val('nova_nome', $n)) ?>" placeholder="<?= e(t('Outra conta, ex.: Água')) ?>" autocomplete="off" aria-label="<?= e(t('Nome da outra conta')) ?>" data-previa-nome>
                        <input type="text" inputmode="decimal" name="nova_valor[]" value="<?= e($val('nova_valor', $n)) ?>" placeholder="<?= e(numInput(0)) ?>" autocomplete="off" aria-label="<?= e(t('Valor da outra conta')) ?>" data-previa-campo="nova">
                        <input type="text" inputmode="numeric" maxlength="2" name="nova_dia[]" value="<?= e($val('nova_dia', $n)) ?>" placeholder="<?= e(t('ex.: {dia}', ['dia' => 10])) ?>" autocomplete="off" aria-label="<?= e(t('Dia de vencimento da outra conta')) ?>" data-previa-dia="nova">
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn-link com-mais" data-com-mais hidden><?= svgIcon('plus', 16) ?><?= e(t('Adicionar outra conta fixa')) ?></button>
                <?= $erroP ?>
            <?php endif; ?>

            <div class="com-acoes">
                <button type="submit" class="btn btn-principal"><?= e($passo === $total ? t('Concluir') : t('Continuar')) ?></button>
                <?php if ($passo > 1): ?><a class="btn-link com-voltar" href="/comecar?passo=<?= (int) $passo - 1 ?>"><?= e(t('Voltar')) ?></a><?php endif; ?>
                <?php if ($passo === 2): ?><a class="btn-link com-pular" href="/comecar?passo=<?= (int) $passo + 1 ?>"><?= e(t('Pular este passo')) ?></a><?php endif; ?>
                <?php // no 3º, pular grava o que está na tela: sem isso, valeria o padrão, que a tela não mostrava ?>
                <?php if ($passo === 3 || $passo === 4): ?><button type="submit" name="pular" value="1" class="btn-link com-pular"><?= e(t('Pular este passo')) ?></button><?php endif; ?>
            </div>
        </form>

        <?php \Financeiro\View::partial('comecar/_previa', ['previa' => $previa, 'comNovas' => $passo === 4, 'comUsos' => $passo >= 3]); ?>
    </div>
<?php require __DIR__ . '/../auth/_casca_fim.php'; ?>
