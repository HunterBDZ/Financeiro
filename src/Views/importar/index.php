<?php
/**
 * Importar extrato, passo 1: para onde vão os lançamentos (uma conta ou um cartão) e o arquivo (OFX, QFX ou CSV).
 * Embaixo: o modelo de planilha para quem vem do caderno, as últimas importações (com "Desfazer importação") e as
 * regras de categoria. Variáveis: $destinos (contas e cartoes: valor => nome), $escolhido, $recentes, $regras,
 * $pendente (o arquivo de uma revisão que ficou aberta, ou null), $nomeDestino (callable: o nome do destino de um lote).
 */
$limite = '2 MB';
?>
<div class="imp">
    <header class="imp-topo">
        <a class="lf-x lf-voltar" href="/lancamentos" aria-label="<?= e(t('voltar')) ?>"><?= svgIcon('chevron', 20, 'lf-seta') ?></a>
        <h1 class="pg-titulo"><?= e(t('Importar extrato')) ?></h1>
    </header>
    <p class="imp-sub"><?= e(t('Traga os lançamentos do arquivo que a instituição oferece para baixar. Nada é gravado antes de você revisar.')) ?></p>

    <?php if ($pendente !== null): ?>
        <p class="aviso imp-pendente"><?= e(t('A revisão de {arquivo} ficou aberta.', ['arquivo' => $pendente])) ?> <a href="/importar/revisar"><?= e(t('Continuar a revisão')) ?></a></p>
    <?php endif; ?>

    <form class="card imp-form" method="post" action="/importar" enctype="multipart/form-data">
        <?= csrfField() ?>
        <label class="imp-campo"><span><?= e(t('Para onde vão os lançamentos')) ?></span>
            <select name="destino" required>
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
        <div class="imp-campo">
            <span id="imp-arquivo-rot"><?= e(t('Arquivo do extrato')) ?></span>
            <label class="btn btn-ghost aj-arquivo imp-arquivo">
                <input type="file" name="arquivo" accept=".ofx,.qfx,.csv,.txt,text/csv,application/x-ofx" required data-arquivo aria-labelledby="imp-arquivo-rot">
                <?= svgIcon('arrow-up', 16) ?><span><?= e(t('Escolher arquivo')) ?></span>
            </label>
            <p class="imp-ajuda" data-arquivo-nome role="status"></p>
            <p class="imp-ajuda"><?= e(t('OFX, QFX ou CSV, até {limite}. No site ou no app da instituição, procure por “exportar extrato”.', ['limite' => $limite])) ?></p>
        </div>
        <button type="submit" class="btn btn-primary imp-continuar"><?= e(t('Continuar')) ?></button>
    </form>

    <section class="card imp-bloco imp-modelo" aria-labelledby="imp-modelo-t">
        <h2 id="imp-modelo-t"><?= e(t('Anota num caderno ou numa planilha?')) ?></h2>
        <p><?= e(t('Baixe o modelo, escreva uma linha para cada gasto ou entrada e envie aqui. Gasto leva o sinal de menos: {exemplo}.', ['exemplo' => '-' . numInput(42.5)])) ?></p>
        <a class="btn btn-ghost btn-sm" href="/importar/modelo"><?= svgIcon('download', 16) ?><?= e(t('Baixar o modelo (CSV)')) ?></a>
    </section>

    <section class="card imp-bloco" aria-labelledby="imp-hist-t" id="imp-hist">
        <h2 id="imp-hist-t"><?= e(t('Últimas importações')) ?></h2>
        <?php if ($recentes === []): ?>
            <p class="imp-vazio"><?= e(t('Nenhuma ainda.')) ?></p>
        <?php else: ?>
            <ul class="imp-hist">
                <?php foreach ($recentes as $i): $desfeita = $i['desfeita_em'] !== null; ?>
                    <li class="imp-hist-i<?= $desfeita ? ' is-desfeita' : '' ?>">
                        <span class="imp-hist-n">
                            <b><?= e((string) $i['nome']) ?></b>
                            <small><?= e(t('{data} · {destino} · {quem}', ['data' => diaMesHora((string) $i['criado_em']), 'destino' => $nomeDestino($i), 'quem' => (string) $i['usuario_nome']])) ?></small>
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

    <section class="card imp-bloco" aria-labelledby="imp-regras-t" id="imp-regras">
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
