<?php
/**
 * Ajustes > Categorias: a lista, por classe (entradas, essenciais, não essenciais, movimentações), com o tipo de cada
 * uma; tocar numa categoria abre o editar (/categorias/{id}). Em cima, criar uma; embaixo, as arquivadas. A lista do
 * Lançar tem um atalho para cá. Variáveis: $categorias (ativas, com grupo, sinal, essencial, operacional),
 * $arquivadas, $dias (categoria => dia do vencimento), $aberto (' open' ou ''), $linha (o miolo da linha de Ajustes).
 */
use Financeiro\View;

$volta = '/ajustes?aberto=categorias#categorias';
$classes = [
    'entradas'   => ['rotulo' => t('Entradas'), 'cats' => []],
    'essenciais' => ['rotulo' => t('Essenciais'), 'cats' => []],
    'naoess'     => ['rotulo' => t('Não essenciais'), 'cats' => []],
    'mov'        => ['rotulo' => t('Movimentações'), 'cats' => []],
];
foreach ($categorias as $c) {
    $classe = (int) $c['operacional'] === 0 ? 'mov' : ((int) $c['sinal'] === 1 ? 'entradas' : ((int) $c['essencial'] === 1 ? 'essenciais' : 'naoess'));
    $classes[$classe]['cats'][] = $c;
}
// o tipo em poucas palavras: o grupo e, se tiver, o dia do vencimento
$tipo = static function (array $c) use ($dias): string {
    if ((string) $c['grupo'] === 'Entradas') {
        return '';                                          // o título do bloco já diz
    }
    $g = grupoLabel((string) $c['grupo']);
    return isset($dias[(int) $c['id']]) ? t('{grupo}, dia {dia}', ['grupo' => $g, 'dia' => $dias[(int) $c['id']]]) : $g;
};
$item = static function (array $c) use ($tipo): void { ?>
    <li><a class="aj-cat" href="/categorias/<?= (int) $c['id'] ?>">
        <?= iconeCategoria((int) $c['id'], (string) $c['nome'], (string) ($c['grupo'] ?? ''), 20) ?>
        <span class="aj-cat-txt"><span class="aj-cat-nome"><?= e(nomeCurto($c)) ?></span><?php if ($tipo($c) !== ''): ?><span class="aj-cat-tipo"><?= e($tipo($c)) ?></span><?php endif; ?></span>
        <?= svgIcon('chevron', 16, 'ajs-seta') ?>
    </a></li>
<?php };
?>
<details class="ajs-item" id="categorias"<?= $aberto ?>>
    <summary class="ajs-linha"><?= $linha('tag', t('Categorias'), tn(count($categorias), '1 ativa', '{n} ativas')) ?></summary>
    <div class="ajs-corpo">
        <details class="aj-cat-nova">
            <summary class="btn btn-contorno"><?= svgIcon('plus', 16) ?><?= e(t('Criar categoria')) ?></summary>
            <form class="aj-cn" method="post" action="/categorias">
                <?= csrfField() ?>
                <input type="hidden" name="volta" value="<?= e($volta) ?>">
                <label class="campo"><span><?= e(t('Nome da categoria')) ?></span>
                    <input type="text" name="nome" maxlength="100" required autocomplete="off" placeholder="<?= e(t('ex.: Pet, Farmácia')) ?>">
                </label>
                <fieldset class="aj-cn-opts">
                    <legend><?= e(t('Tipo')) ?></legend>
                    <div class="seg">
                        <label class="chip-r"><input type="radio" name="tipo" value="saida" checked><span><?= e(t('Saída')) ?></span></label>
                        <label class="chip-r"><input type="radio" name="tipo" value="entrada"><span><?= e(t('Entrada')) ?></span></label>
                    </div>
                </fieldset>
                <fieldset class="aj-cn-opts aj-cn-ess">
                    <legend><?= e(t('Classe')) ?></legend>
                    <div class="seg">
                        <label class="chip-r"><input type="radio" name="essencial" value="1" checked><span><?= e(t('Essencial')) ?></span></label>
                        <label class="chip-r"><input type="radio" name="essencial" value="0"><span><?= e(t('Não essencial')) ?></span></label>
                    </div>
                </fieldset>
                <?php // só para gasto; o CSS mostra os campos da escolha (fixo ou varia) com :has(), sem JS ?>
                <fieldset class="aj-cn-opts aj-cn-valor">
                    <legend><?= e(t('O valor é')) ?></legend>
                    <div class="seg">
                        <label class="chip-r"><input type="radio" name="valor_tipo" value="varia" checked><span><?= e(t('Varia')) ?></span></label>
                        <label class="chip-r"><input type="radio" name="valor_tipo" value="fixo"><span><?= e(t('Fixo todo mês')) ?></span></label>
                    </div>
                    <p class="aj-ajuda"><?= e(t('Varia: gasto do dia a dia, com meta. Fixo: uma conta, com valor e vencimento.')) ?></p>
                    <div class="aj-cn-varia">
                        <label class="campo"><span><?= e(t('Meta de gasto por mês (opcional)')) ?></span>
                            <input type="text" name="meta" inputmode="decimal" autocomplete="off" placeholder="<?= e(t('ex.: 400')) ?>">
                        </label>
                    </div>
                    <div class="aj-cn-fixo">
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
                <button type="submit" class="btn btn-principal aj-salvar"><?= e(t('Criar categoria')) ?></button>
            </form>
        </details>

        <p class="aj-ajuda"><?= e(t('Escolha uma categoria para mudar o nome, o tipo, o vencimento, se é essencial ou o ícone.')) ?></p>
        <?php foreach ($classes as $cl): if ($cl['cats'] === []) { continue; } ?>
            <p class="aj-cats-grupo"><?= e($cl['rotulo']) ?></p>
            <ul class="aj-cats"><?php foreach ($cl['cats'] as $c) { $item($c); } ?></ul>
        <?php endforeach; ?>

        <?php if ($arquivadas !== []): ?>
            <details class="aj-cat-arq">
                <summary><?= e(tn(count($arquivadas), '1 arquivada', '{n} arquivadas')) ?></summary>
                <ul class="aj-cats"><?php foreach ($arquivadas as $c) { $item($c); } ?></ul>
            </details>
        <?php endif; ?>
    </div>
</details>
