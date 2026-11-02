<?php
/**
 * Importar extrato, passo 2 (só CSV, na primeira vez de cada cabeçalho): as primeiras linhas do arquivo e, para cada
 * coisa, a coluna dela. O app já vem com o palpite (pelo nome da coluna ou pelo conteúdo) e lembra da escolha.
 * Variáveis: $estado, $mapa (data, descricao, valor, debito, credito, categoria: o nº da coluna ou -1; cabecalho),
 * $cabecalho (a 1ª linha, se parece cabeçalho, ou null), $previa (as primeiras linhas), $n (colunas).
 */
$nomeCol = static function (int $i) use ($cabecalho, $previa): string {
    $titulo = $cabecalho !== null ? trim((string) ($cabecalho[$i] ?? '')) : '';
    $exemplo = $titulo !== '' ? $titulo : trim((string) ($previa[$cabecalho !== null ? 1 : 0][$i] ?? ''));
    return t('Coluna {n}', ['n' => $i + 1]) . ($exemplo !== '' ? ' · ' . mb_strimwidth($exemplo, 0, 28, '…') : '');
};
$select = static function (string $nome, string $rotulo, bool $opcional) use ($mapa, $n, $nomeCol): void { ?>
    <label class="imp-campo"><span><?= e($rotulo) ?></span>
        <select name="<?= e($nome) ?>">
            <option value="-1"><?= e($opcional ? t('Nenhuma') : t('Escolha a coluna')) ?></option>
            <?php for ($i = 0; $i < $n; $i++): ?><option value="<?= $i ?>"<?= (int) $mapa[$nome] === $i ? ' selected' : '' ?>><?= e($nomeCol($i)) ?></option><?php endfor; ?>
        </select>
    </label>
<?php };
$duas = (int) $mapa['valor'] < 0 && ((int) $mapa['debito'] >= 0 || (int) $mapa['credito'] >= 0);
?>
<div class="imp">
    <header class="imp-topo">
        <a class="lf-x lf-voltar" href="/importar" aria-label="<?= e(t('voltar')) ?>"><?= svgIcon('chevron', 20, 'lf-seta') ?></a>
        <h1 class="pg-titulo"><?= e(t('Ligar as colunas')) ?></h1>
    </header>
    <p class="imp-sub"><?= e(t('Diga em que coluna está cada coisa. Na próxima vez, um arquivo com as mesmas colunas pula este passo.')) ?></p>

    <section class="card imp-previa" aria-labelledby="imp-previa-t">
        <h2 id="imp-previa-t" class="imp-previa-t"><?= e(t('As primeiras linhas de {arquivo}', ['arquivo' => $estado['nome']])) ?></h2>
        <div class="imp-rolar" tabindex="0" role="region" aria-labelledby="imp-previa-t">
            <table class="imp-tabela-previa">
                <thead><tr><?php for ($i = 0; $i < $n; $i++): ?><th scope="col"><?= e(t('Coluna {n}', ['n' => $i + 1])) ?></th><?php endfor; ?></tr></thead>
                <tbody>
                    <?php foreach ($previa as $k => $linha): ?>
                        <tr<?= $k === 0 && $cabecalho !== null ? ' class="is-cab"' : '' ?>><?php foreach ($linha as $c): ?><td><?= e(mb_strimwidth((string) $c, 0, 40, '…')) ?></td><?php endforeach; ?></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <form class="card imp-form" method="post" action="/importar/colunas">
        <?= csrfField() ?>
        <label class="imp-check"><input type="checkbox" name="cabecalho" value="1"<?= $mapa['cabecalho'] ? ' checked' : '' ?>> <span><?= e(t('A 1ª linha tem os nomes das colunas')) ?></span></label>
        <div class="imp-colunas">
            <?php $select('data', t('Data'), false); ?>
            <?php $select('descricao', t('Descrição'), false); ?>
            <?php $select('valor', t('Valor (com sinal de menos nos gastos)'), $duas); ?>
            <?php $select('categoria', t('Categoria (se tiver)'), true); ?>
        </div>
        <details class="imp-duas"<?= $duas ? ' open' : '' ?>>
            <summary><?= e(t('O valor vem em duas colunas, uma de saídas e outra de entradas?')) ?></summary>
            <div class="imp-colunas">
                <?php $select('debito', t('Saídas (débito)'), true); ?>
                <?php $select('credito', t('Entradas (crédito)'), true); ?>
            </div>
            <p class="imp-ajuda"><?= e(t('Com essas duas escolhidas, a coluna de valor fica de fora.')) ?></p>
        </details>
        <button type="submit" class="btn btn-primary imp-continuar"><?= e(t('Continuar')) ?></button>
    </form>
</div>
