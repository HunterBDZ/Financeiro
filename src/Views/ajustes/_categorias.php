<?php
/**
 * Ajustes > Categorias: criar uma categoria e, na lista, trocar o ícone ou remover. A lista do Lançar tem um
 * atalho para cá. Variáveis: $categorias (linhas ativas, com grupo, sinal, essencial, operacional), $aberto (' open' ou '').
 */
use Financeiro\View;

$volta = '/ajustes?aberto=categorias#categorias';
$classes = [
    'entradas'   => ['rotulo' => t('Entradas'), 'cats' => []],
    'essenciais' => ['rotulo' => t('Essenciais'), 'cats' => []],
    'naoess'     => ['rotulo' => t('Não essenciais'), 'cats' => []],
    'mov'        => ['rotulo' => t('⇄ Movimentações'), 'cats' => []],
];
foreach ($categorias as $c) {
    $classe = (int) $c['operacional'] === 0 ? 'mov' : ((int) $c['sinal'] === 1 ? 'entradas' : ((int) $c['essencial'] === 1 ? 'essenciais' : 'naoess'));
    $classes[$classe]['cats'][] = $c;
}
?>
<details class="card dobra aj-grupo" id="categorias"<?= $aberto ?>>
    <summary><span class="titulo"><?= e(t('Categorias')) ?></span></summary>

    <form class="ex-nova-form ex-nova-criar" method="post" action="/categorias">
        <?= csrfField() ?>
        <input type="hidden" name="volta" value="<?= e($volta) ?>">
        <label class="campo ex-nova-nome"><span><?= e(t('Nome da categoria')) ?></span>
            <input type="text" name="nome" maxlength="100" required autocomplete="off" placeholder="<?= e(t('ex.: Pet, Farmácia')) ?>">
        </label>
        <fieldset class="ex-nova-opts">
            <legend><?= e(t('Tipo')) ?></legend>
            <div class="chip-row">
                <label class="chip-r"><input type="radio" name="tipo" value="saida" checked><span><?= e(t('Saída')) ?></span></label>
                <label class="chip-r"><input type="radio" name="tipo" value="entrada"><span><?= e(t('Entrada')) ?></span></label>
            </div>
        </fieldset>
        <fieldset class="ex-nova-opts ex-nova-ess">
            <legend><?= e(t('Classe')) ?></legend>
            <div class="chip-row">
                <label class="chip-r"><input type="radio" name="essencial" value="1" checked><span><?= e(t('Essencial')) ?></span></label>
                <label class="chip-r"><input type="radio" name="essencial" value="0"><span><?= e(t('Não essencial')) ?></span></label>
            </div>
        </fieldset>
        <?php // só para saída; o CSS mostra os campos da escolha (fixo ou varia) com :has(), sem JS ?>
        <fieldset class="ex-nova-opts ex-nova-valor">
            <legend><?= e(t('O valor é')) ?></legend>
            <div class="chip-row">
                <label class="chip-r"><input type="radio" name="valor_tipo" value="varia" checked><span><?= e(t('Varia (gasto do dia a dia)')) ?></span></label>
                <label class="chip-r"><input type="radio" name="valor_tipo" value="fixo"><span><?= e(t('Fixo todo mês (conta)')) ?></span></label>
            </div>
            <div class="ex-nova-varia">
                <label class="campo"><span><?= e(t('Meta de gasto por mês (opcional)')) ?></span>
                    <input type="text" name="meta" inputmode="decimal" autocomplete="off" placeholder="<?= e(t('ex.: 400')) ?>">
                </label>
            </div>
            <div class="ex-nova-fixo">
                <label class="campo"><span><?= e(t('Valor por mês (opcional)')) ?></span>
                    <input type="text" name="valor_fixo" inputmode="decimal" autocomplete="off" placeholder="<?= e(t('ex.: 159,90')) ?>">
                </label>
                <label class="campo"><span><?= e(t('Vence no dia (opcional)')) ?></span>
                    <input type="number" name="dia_vencimento" min="1" max="31" inputmode="numeric" placeholder="<?= e(t('ex.: 10')) ?>">
                </label>
            </div>
        </fieldset>
        <details class="ic-escolher">
            <summary><span><?= e(t('Escolher ícone')) ?></span> <small><?= e(t('Sem escolher, o ícone vem do nome.')) ?></small></summary>
            <?php View::partial('partials/icone_picker', ['sugerir' => true, 'semDica' => true, 'legenda' => t('Ícone')]); ?>
        </details>
        <button type="submit" class="btn btn-primary ex-nova-btn"><?= e(t('Criar categoria')) ?></button>
    </form>

    <div class="ex-cats">
        <?php foreach ($classes as $cl): if ($cl['cats'] === []) { continue; } ?>
            <p class="ex-cats-grupo"><?= e($cl['rotulo']) ?></p>
            <ul class="ex-cats-lista">
                <?php foreach ($cl['cats'] as $c): ?>
                    <li class="ex-cat-item">
                        <a class="ex-cat-ic" href="/categorias/<?= (int) $c['id'] ?>/icone?volta=<?= e(rawurlencode($volta)) ?>" title="<?= e(t('Trocar o ícone')) ?>" aria-label="<?= e(t('trocar o ícone de {nome}', ['nome' => nomeCurto($c)])) ?>"><?= iconeCategoria((int) $c['id'], (string) $c['nome'], (string) ($c['grupo'] ?? ''), 40) ?></a>
                        <span class="ex-cat-nome"><?= e(nomeCurto($c)) ?></span>
                        <form method="post" action="/categorias/<?= (int) $c['id'] ?>/remover" class="ex-cat-rm"
                              data-confirm="<?= e(t('Remover a categoria {nome}? Se ela tiver lançamentos, só sai das listas.', ['nome' => nomeCurto($c)])) ?>">
                            <?= csrfField() ?>
                            <input type="hidden" name="volta" value="<?= e($volta) ?>">
                            <button type="submit" class="ex-cat-x" aria-label="<?= e(t('remover {nome}', ['nome' => nomeCurto($c)])) ?>"><?= svgIcon('x', 16) ?></button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
    </div>
</details>
