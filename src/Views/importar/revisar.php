<?php
/**
 * Importar extrato, passo 3: a revisão. Uma linha por lançamento do arquivo, com a marca de lançar, a data, a
 * descrição (e, no cartão, a fatura e a parcela), a categoria sugerida e o valor. Repetidas e linhas que não podem
 * entrar vêm desmarcadas, com o motivo. Embaixo, "Criar uma regra" e "Lançar todos".
 * A revisão é uma tabela (o livro-razão): no celular, cada linha vira um bloco com fio (importar.css). O erro da
 * regra aparece junto dela, e o de lançar, junto do botão; o resto continua no aviso.
 * Variáveis: $estado, $linhas (Importacao::revisao), $categorias (tipo => id => categoria), $categoriasRegra,
 * $nomeDestino, $hoje.
 * O "Criar regra" vem antes do "Lançar todos" no formulário: Enter num campo cria a regra, nunca grava o lote.
 */
$cartao = $estado['destino']['tipo'] === 'cartao';
$total = count($linhas);
$marcadas = count(array_filter($linhas, static fn(array $r): bool => $r['marcada']));
$repetidas = count(array_filter($linhas, static fn(array $r): bool => $r['dup'] !== null));
$semCat = count(array_filter($linhas, static fn(array $r): bool => $r['marcada'] && $r['categoria_id'] === null));
$dataCurta = static fn(string $d): string => substr($d, 0, 4) === substr($hoje, 0, 4) ? diaMes($d) : diaMesAno($d);
// o erro que voltou (criar a regra ou lançar): perto do que a pessoa fez
$erroRev = null;
if (is_array($_SESSION['flash'] ?? null) && ($_SESSION['flash']['type'] ?? '') === 'error') {
    $erroRev = (string) $_SESSION['flash']['message'];
    unset($_SESSION['flash']);
}
$erroRegra = $erroRev !== null && in_array($erroRev, [t('Escreva o texto da regra, com 2 a 60 letras.'), t('Escolha a categoria da regra.')], true) ? $erroRev : null;
$erroLancar = $erroRegra === null ? $erroRev : null;
?>
<div class="grade imp imp--revisar" data-imp-revisar>
    <?php \Financeiro\View::partial('ajustes/_sub_topo', ['titulo' => t('Revisar importação'), 'apoio' => t('{arquivo}, para {destino}', ['arquivo' => $estado['nome'], 'destino' => $nomeDestino]), 'volta' => '/importar', 'voltaRotulo' => t('Importar extrato')]); ?>

    <section class="faixa-grade" aria-labelledby="imp-rev-t">
    <?php \Financeiro\View::partial('importar/_passos', ['atual' => 3, 'comColunas' => $estado['csv'] !== null]); ?>
    <h2 class="sr-only" id="imp-rev-t"><?= e(t('As linhas do arquivo')) ?></h2>
    <div class="imp-resumo">
        <span class="num"><?= e(tn($total, '1 linha', '{n} linhas')) ?></span>
        <b class="num" data-imp-contador role="status"><?= e(tn($marcadas, '1 para lançar', '{n} para lançar')) ?></b>
        <?php if ($repetidas > 0): ?><span class="atencao num"><?= e(tn($repetidas, '1 repetida, desmarcada', '{n} repetidas, desmarcadas')) ?></span><?php endif; ?>
        <?php if ($semCat > 0): ?><span class="atencao num"><?= e(tn($semCat, '1 sem categoria', '{n} sem categoria')) ?></span><?php endif; ?>
        <?php if ($estado['csv'] !== null): ?><a class="link" href="/importar/colunas"><?= e(t('Mudar as colunas')) ?></a><?php endif; ?>
        <label class="imp-todas" hidden data-imp-todas-rot><input type="checkbox" data-imp-todas> <span><?= e(t('Marcar todas')) ?></span></label>
    </div>
    <?php if ((int) $estado['ignoradas'] > 0): ?>
        <p class="imp-ajuda atencao"><?= e(tn((int) $estado['ignoradas'], '1 linha do arquivo ficou de fora: a data ou o valor não deu para ler.', '{n} linhas do arquivo ficaram de fora: a data ou o valor não deu para ler.')) ?></p>
    <?php endif; ?>

    <form method="post" action="/importar/revisar" class="imp-rev-form">
        <?= csrfField() ?>
        <input type="hidden" name="token" value="<?= e($estado['token']) ?>">
        <div class="imp-lista">
            <table class="imp-tabela">
                <thead>
                    <tr>
                        <th scope="col" class="imp-c-marca"><span class="sr-only"><?= e(t('Lançar')) ?></span></th>
                        <th scope="col" class="imp-c-data"><?= e(t('Data')) ?></th>
                        <th scope="col" class="imp-c-desc"><?= e(t('Descrição')) ?></th>
                        <th scope="col" class="imp-c-cat"><?= e(t('Categoria')) ?></th>
                        <th scope="col" class="imp-c-valor"><?= e(t('Valor')) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($linhas as $r):
                    $i = (int) $r['i'];
                    $v = (float) $r['valor'];
                    [$txtValor, $clsValor] = match ($r['tipo']) {
                        'entrada'  => ['+' . num($v), 'v-entrada'],
                        'gasto'    => [MENOS . num(abs($v)), 'v-saida'],
                        'estorno'  => ['+' . num(abs($v)), 'v-entrada'],
                        'pagamento' => [num(abs($v)), ''],
                        default    => [num(abs($v)), ''],
                    };
                    $notas = [];
                    if ($r['tipo'] === 'parcela') {
                        $notas[] = t('Parcela {k} de {n}, a 1ª na fatura de {mes}', ['k' => $r['k'], 'n' => $r['n'], 'mes' => monthLabel((string) $r['primeira'])]);
                    } elseif ($r['tipo'] === 'compra') {
                        $notas[] = t('Fatura de {mes}', ['mes' => monthLabel((string) $r['fatura'])]);
                    } elseif ($r['tipo'] === 'estorno') {
                        $notas[] = t('Estorno, volta na fatura de {mes}', ['mes' => monthLabel((string) $r['fatura'])]);
                    }
                    $aviso = $r['motivo'] ?? match ($r['dup']) {
                        'importada' => t('Já foi importada antes.'),
                        'parecida'  => t('Parece já lançada: {descricao}', ['descricao' => nomeCategoria((string) $r['parecida'])]),
                        default     => null,
                    };
                    $origem = match ($r['origem'] ?? null) {
                        'regra'   => t('pela regra “{texto}”', ['texto' => (string) $r['regra']]),
                        'memoria' => t('como da última vez'),
                        'csv'     => t('da planilha'),
                        default   => null,
                    };
                    $opcoes = $categorias[$r['tipo']] ?? [];
                    $rot = $r['descricao'] !== '' ? $r['descricao'] : t('(sem descrição)');
                ?>
                    <tr class="imp-l is-<?= e($r['tipo']) ?><?= $r['marcada'] ? ' is-marcada' : '' ?><?= !$r['pode'] ? ' is-fora' : '' ?><?= $aviso !== null ? ' is-aviso' : '' ?>" data-imp-linha>
                        <td class="imp-c-marca">
                            <input type="checkbox" name="marcar[<?= $i ?>]" value="1" id="imp-m<?= $i ?>"<?= $r['marcada'] ? ' checked' : '' ?><?= !$r['pode'] ? ' disabled' : '' ?> data-imp-marca>
                        </td>
                        <td class="imp-c-data num"><label for="imp-m<?= $i ?>"><?= e($dataCurta((string) $r['data'])) ?></label></td>
                        <td class="imp-c-desc">
                            <label for="imp-m<?= $i ?>" class="imp-desc"><?= e($rot) ?></label>
                            <?php foreach ($notas as $nota): ?><small class="imp-nota"><?= e($nota) ?></small><?php endforeach; ?>
                            <?php if ($aviso !== null): ?><small class="imp-aviso"><?= svgIcon('alert', 14) ?><?= e($aviso) ?></small><?php endif; ?>
                        </td>
                        <td class="imp-c-cat">
                            <?php if ($opcoes !== []): ?>
                                <select name="cat[<?= $i ?>]" aria-label="<?= e(t('Categoria de {descricao}', ['descricao' => $rot])) ?>"<?= !$r['pode'] ? ' disabled' : '' ?> data-imp-cat>
                                    <option value=""><?= e(t('Escolha a categoria')) ?></option>
                                    <?php foreach ($opcoes as $cid => $c): ?><option value="<?= (int) $cid ?>"<?= $r['categoria_id'] === $cid ? ' selected' : '' ?>><?= e(nomeCurto($c)) ?></option><?php endforeach; ?>
                                </select>
                                <span class="imp-cat-pe">
                                    <?php if ($origem !== null): ?><small class="imp-origem"><?= e($origem) ?></small><?php endif; ?>
                                    <?php if ($r['pode']): ?><button type="button" class="btn-link imp-sempre" data-imp-regra="<?= e(mb_substr(trim((string) preg_replace('/[\d\/\-.,]+/', ' ', (string) $r['gravar'])), 0, 60)) ?>" hidden><?= e(t('Sempre assim')) ?></button><?php endif; ?>
                                </span>
                            <?php else: ?>
                                <small class="imp-origem"><?= e(t('Fica de fora')) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="imp-c-valor"><b class="num <?= $clsValor ?>"><?= e($txtValor) ?></b></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <section class="imp-regra" id="imp-regra" aria-labelledby="imp-regra-t">
            <h2 id="imp-regra-t"><?= e(t('Criar uma regra')) ?></h2>
            <p class="imp-ajuda"><?= e(t('Sempre que a descrição tiver o texto, a categoria vem escolhida. Vale agora e nas próximas importações.')) ?></p>
            <div class="imp-regra-campos">
                <label class="campo"><span><?= e(t('Sempre que tiver')) ?></span><input type="text" name="regra_texto" id="imp-regra-texto" maxlength="60" autocomplete="off" placeholder="<?= e(t('ex.: {texto}', ['texto' => 'MERCADO'])) ?>"<?= $erroRegra !== null ? ' aria-invalid="true" aria-describedby="imp-erro-regra"' : '' ?>></label>
                <label class="campo"><span><?= e(t('usar a categoria')) ?></span>
                    <select name="regra_categoria" id="imp-regra-cat">
                        <option value=""><?= e(t('Escolha a categoria')) ?></option>
                        <?php foreach ($categoriasRegra as $cid => $c): ?><option value="<?= (int) $cid ?>"><?= e(nomeCurto($c)) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" name="acao" value="regra" class="btn btn-contorno"><?= e(t('Criar regra')) ?></button>
            </div>
            <?php if ($erroRegra !== null): ?><p class="aj-erro" id="imp-erro-regra" role="alert"><?= e($erroRegra) ?></p><?php endif; ?>
        </section>

        <?php if ($erroLancar !== null): ?><p class="aj-erro" role="alert"><?= e($erroLancar) ?></p><?php endif; ?>
        <div class="imp-acoes">
            <button type="submit" name="acao" value="lancar" class="btn btn-principal imp-lancar"><?= e(t('Lançar todos')) ?></button>
            <button type="submit" name="acao" value="cancelar" class="btn btn-contorno" formnovalidate><?= e(t('Cancelar')) ?></button>
        </div>
        <p class="imp-ajuda imp-acoes-dica"><?= e(t('Só as linhas marcadas entram. Depois, dá para desfazer a importação inteira.')) ?></p>
    </form>
    </section>
</div>
