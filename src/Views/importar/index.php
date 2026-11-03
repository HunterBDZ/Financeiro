<?php
/**
 * Importar extrato, passo 1: para onde vão os lançamentos (uma conta ou um cartão) e o arquivo (OFX, QFX ou CSV).
 * Embaixo: o modelo de planilha para quem vem do caderno, as últimas importações (com "Desfazer importação") e as
 * regras de categoria. Variáveis: $destinos (contas e cartoes: valor => nome), $escolhido, $recentes, $regras,
 * $pendente (o arquivo de uma revisão que ficou aberta, ou null), $nomeDestino (callable: o nome do destino de um lote).
 * Na grade, com os passos à vista no topo; o erro do envio aparece junto do campo (destino ou arquivo), não no aviso.
 */
$limite = '2 MB';
// o erro que voltou do envio: tirado do aviso para ficar junto do campo certo
$erroEnvio = null;
if (is_array($_SESSION['flash'] ?? null) && ($_SESSION['flash']['type'] ?? '') === 'error') {
    $erroEnvio = (string) $_SESSION['flash']['message'];
    unset($_SESSION['flash']);
}
$erroDestino = $erroEnvio === t('Escolha para onde vão os lançamentos.') ? $erroEnvio : null;
$erroArquivo = $erroDestino === null ? $erroEnvio : null;
?>
<div class="grade imp">
    <?php \Financeiro\View::partial('ajustes/_sub_topo', ['titulo' => t('Importar extrato'), 'apoio' => t('Traga os lançamentos do arquivo que a instituição oferece para baixar. Nada é gravado antes de você revisar.'), 'volta' => '/lancamentos', 'voltaRotulo' => t('Extrato')]); ?>

    <section class="faixa-grade" aria-labelledby="imp-envio-t">
        <?php \Financeiro\View::partial('importar/_passos', ['atual' => 1, 'comColunas' => false]); ?>
        <h2 class="sr-only" id="imp-envio-t"><?= e(t('Arquivo')) ?></h2>
        <?php if ($pendente !== null): ?>
            <p class="imp-pendente"><?= e(t('A revisão de {arquivo} ficou aberta.', ['arquivo' => $pendente])) ?> <a class="link" href="/importar/revisar"><?= e(t('Continuar a revisão')) ?></a></p>
        <?php endif; ?>

        <form class="imp-form" method="post" action="/importar" enctype="multipart/form-data" data-espera="<?= e(t('Lendo o extrato…')) ?>">
            <?= csrfField() ?>
            <label class="campo"><span><?= e(t('Para onde vão os lançamentos')) ?></span>
                <select name="destino" required<?= $erroDestino !== null ? ' aria-invalid="true" aria-describedby="imp-erro-destino"' : '' ?>>
                    <optgroup label="<?= e(t('Contas')) ?>">
                        <?php foreach ($destinos['contas'] as $v => $nome): ?><option value="<?= e($v) ?>"<?= $v === $escolhido ? ' selected' : '' ?>><?= e($nome) ?></option><?php endforeach; ?>
                    </optgroup>
                    <?php if ($destinos['cartoes'] !== []): ?>
                    <optgroup label="<?= e(t('Cartões de crédito')) ?>">
                        <?php foreach ($destinos['cartoes'] as $v => $nome): ?><option value="<?= e($v) ?>"<?= $v === $escolhido ? ' selected' : '' ?>><?= e($nome) ?></option><?php endforeach; ?>
                    </optgroup>
                    <?php endif; ?>
                </select>
            </label>
            <?php if ($erroDestino !== null): ?><p class="aj-erro" id="imp-erro-destino" role="alert"><?= e($erroDestino) ?></p><?php endif; ?>
            <div class="campo">
                <span id="imp-arquivo-rot"><?= e(t('Arquivo do extrato')) ?></span>
                <label class="btn btn-contorno aj-arquivo imp-arquivo<?= $erroArquivo !== null ? ' is-erro' : '' ?>">
                    <input type="file" name="arquivo" accept=".ofx,.qfx,.csv,.txt,text/csv,application/x-ofx" required data-arquivo aria-labelledby="imp-arquivo-rot"<?= $erroArquivo !== null ? ' aria-invalid="true" aria-describedby="imp-erro-arquivo"' : '' ?>>
                    <?= svgIcon('arrow-up', 16) ?><span><?= e(t('Escolher arquivo')) ?></span>
                </label>
                <p class="imp-ajuda" data-arquivo-nome role="status"></p>
                <?php if ($erroArquivo !== null): ?><p class="aj-erro" id="imp-erro-arquivo" role="alert"><?= e($erroArquivo) ?></p><?php endif; ?>
                <p class="imp-ajuda"><?= e(t('OFX, QFX ou CSV, até {limite}. No site ou no app da instituição, procure por “exportar extrato”.', ['limite' => $limite])) ?></p>
            </div>
            <button type="submit" class="btn btn-principal imp-continuar"><?= e(t('Continuar')) ?></button>
        </form>
    </section>

    <section class="faixa-grade imp-baixo" aria-label="<?= e(t('Modelo, últimas importações e regras')) ?>">
    <div class="imp-blocos">
    <section class="imp-bloco imp-modelo" aria-labelledby="imp-modelo-t">
        <h2 id="imp-modelo-t"><?= e(t('Anota num caderno ou numa planilha?')) ?></h2>
        <p><?= e(t('Baixe o modelo, escreva uma linha para cada gasto ou entrada e envie aqui. Gasto leva o sinal de menos: {exemplo}.', ['exemplo' => '-' . numInput(42.5)])) ?></p>
        <a class="btn btn-contorno btn-sm" href="/importar/modelo"><?= svgIcon('download', 16) ?><?= e(t('Baixar o modelo (CSV)')) ?></a>
    </section>

    <section class="imp-bloco" aria-labelledby="imp-hist-t" id="imp-hist">
        <h2 id="imp-hist-t"><?= e(t('Últimas importações')) ?></h2>
        <?php if ($recentes === []): ?>
            <p class="imp-vazio"><?= e(t('Nenhuma ainda.')) ?></p>
        <?php else: ?>
            <ul class="imp-hist">
                <?php foreach ($recentes as $i): $desfeita = $i['desfeita_em'] !== null; ?>
                    <li class="imp-hist-i<?= $desfeita ? ' is-desfeita' : '' ?>">
                        <span class="imp-hist-n">
                            <b><?= e((string) $i['nome']) ?></b>
                            <small><?= e(t('{data}, em {destino}, por {quem}', ['data' => diaMesHora((string) $i['criado_em']), 'destino' => $nomeDestino($i), 'quem' => (string) $i['usuario_nome']])) ?></small>
                        </span>
                        <span class="imp-hist-v">
                            <?php if ($desfeita): ?>
                                <small><?= e(t('Desfeita em {data}', ['data' => diaMes((string) $i['desfeita_em'])])) ?></small>
                            <?php else: ?>
                                <small class="num"><?= e(tn((int) $i['linhas'], '1 linha', '{n} linhas')) ?></small>
                                <form method="post" action="/importar/<?= (int) $i['id'] ?>/desfazer" data-confirm="<?= e(t('Desfazer a importação? As {n} linhas dela serão apagadas.', ['n' => (int) $i['existentes']])) ?>">
                                    <?= csrfField() ?><input type="hidden" name="volta" value="/importar#imp-hist">
                                    <button type="submit" class="btn-link"><?= e(t('Desfazer importação')) ?></button>
                                </form>
                            <?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="imp-bloco" aria-labelledby="imp-regras-t" id="imp-regras">
        <h2 id="imp-regras-t"><?= e(t('Regras de categoria')) ?></h2>
        <?php if ($regras === []): ?>
            <p class="imp-vazio"><?= e(t('Na revisão, crie regras como “sempre que tiver MERCADO, usar Mercado”. Elas aparecem aqui.')) ?></p>
        <?php else: ?>
            <ul class="imp-hist">
                <?php foreach ($regras as $r): ?>
                    <li class="imp-hist-i">
                        <span class="imp-hist-n"><b><?= e(t('Tem “{texto}”', ['texto' => $r['contem']])) ?></b><small><?= e(t('vai para {categoria}', ['categoria' => nomeCurto(['nome' => $r['categoria_nome'], 'apelido' => $r['apelido']])])) ?></small></span>
                        <form method="post" action="/importar/regras/<?= (int) $r['id'] ?>/remover" class="imp-hist-v">
                            <?= csrfField() ?>
                            <button type="submit" class="btn-link"><?= e(t('Apagar')) ?></button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    </div>
    </section>
</div>
