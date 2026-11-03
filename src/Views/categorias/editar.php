<?php
/**
 * Editar uma categoria (GET/POST /categorias/{id}): nome, apelido, tipo, fixa ou varia, vencimento, essencial,
 * ativa e o ícone. O valor e a meta ficam em Metas. Sem JS, todos os campos aparecem; com :has(), só os do tipo.
 * Variáveis: $cat (linha de categorias), $icone (chave em vigor), $dia (?int, vencimento), $doApp (o app depende
 * do tipo e do grupo), $historico (tem lançamentos), $erros (campo => mensagem), $valores (?array, o que foi digitado), $volta.
 */
use Financeiro\Icones;
use Financeiro\Models\CategoriaModel;
use Financeiro\View;

$id = (int) $cat['id'];
$v = $valores ?? [];
$val = static fn(string $k, string $atual): string => is_string($v[$k] ?? null) ? $v[$k] : $atual;
$entrada = $val('tipo', (int) $cat['sinal'] === 1 ? 'entrada' : 'saida') === 'entrada';
$valorTipo = $val('valor_tipo', (string) (array_search((string) $cat['grupo'], CategoriaModel::GRUPO_DO_VALOR, true) ?: 'varia'));
$essencial = isset($v['essencial']) ? (bool) $v['essencial'] : (int) $cat['essencial'] === 1;
$ativa = isset($v['ativo']) ? (bool) $v['ativo'] : (int) $cat['ativo'] === 1;
$tipoTravado = $doApp || $historico;
$movimentacao = in_array((string) $cat['grupo'], ['Emprestimo', 'Poupanca', 'Investimento'], true);
$escolheValor = !$doApp;                                     // a fatura e as movimentações têm grupo próprio
$temVencimento = $doApp ? in_array((string) $cat['grupo'], \Financeiro\Models\ContaRecorrenteModel::GRUPOS, true) : true;
$temMeta = (int) $cat['sinal'] === -1 && !$movimentacao;

// a mensagem de erro fica junto do campo, ligada a ele por aria-describedby
$erroDe = static function (string $campo) use ($erros): string {
    return isset($erros[$campo]) ? '<p class="cat-erro" id="cat-erro-' . e($campo) . '">' . e($erros[$campo]) . '</p>' : '';
};
$invalido = static fn(string $campo): string => isset($erros[$campo]) ? ' aria-invalid="true" aria-describedby="cat-erro-' . e($campo) . '"' : '';
?>
<div class="ic-pagina cat-pagina">
    <header class="lf-topo">
        <a class="lf-x lf-voltar" href="<?= e($volta) ?>" aria-label="<?= e(t('voltar')) ?>"><?= svgIcon('chevron', 20, 'lf-seta') ?></a>
        <h1 class="lf-titulo"><?= e(t('Editar categoria')) ?></h1>
    </header>

    <?php if ($erros !== []): ?>
        <p class="aviso aviso-error" role="alert"><?= e(t('Confira os campos marcados.')) ?></p>
    <?php endif; ?>

    <form class="cat-form" method="post" action="/categorias/<?= $id ?>" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="volta" value="<?= e($volta) ?>">

        <div class="cat-sec">
            <details class="cat-ic"<?= isset($erros['icone']) ? ' open' : '' ?>>
                <summary><?= Icones::html($icone, 56) ?><span><?= e(t('Trocar o ícone')) ?></span></summary>
                <?php View::partial('partials/icone_picker', ['selecionado' => $val('icone', $icone), 'legenda' => t('Ícone')]); ?>
            </details>
            <?= $erroDe('icone') ?>

            <label class="campo" for="cat-nome"><span><?= e(t('Nome')) ?></span>
                <input id="cat-nome" type="text" name="nome" maxlength="100" required autocomplete="off" value="<?= e($val('nome', (string) $cat['nome'])) ?>"<?= $invalido('nome') ?>>
            </label>
            <?= $erroDe('nome') ?>

            <label class="campo" for="cat-apelido"><span><?= e(t('Apelido (opcional)')) ?></span>
                <input id="cat-apelido" type="text" name="apelido" maxlength="24" autocomplete="off" value="<?= e($val('apelido', (string) ($cat['apelido'] ?? ''))) ?>" aria-describedby="cat-apelido-dica<?= isset($erros['apelido']) ? ' cat-erro-apelido' : '' ?>"<?= isset($erros['apelido']) ? ' aria-invalid="true"' : '' ?>>
            </label>
            <p class="cat-dica" id="cat-apelido-dica"><?= e(t('Nome curto para as listas e os botões.')) ?></p>
            <?= $erroDe('apelido') ?>
        </div>

        <div class="cat-sec">
            <?php $descTipo = trim(($tipoTravado ? 'cat-tipo-dica' : '') . (isset($erros['tipo']) ? ' cat-erro-tipo' : '')); ?>
            <fieldset class="cat-opts"<?= $descTipo !== '' ? ' aria-describedby="' . $descTipo . '"' : '' ?>>
                <legend><?= e(t('Tipo')) ?></legend>
                <div class="chip-row">
                    <label class="chip-r"><input type="radio" name="tipo" value="saida"<?= !$entrada ? ' checked' : '' ?><?= $tipoTravado ? ' disabled' : '' ?>><span><?= e(t('Saída')) ?></span></label>
                    <label class="chip-r"><input type="radio" name="tipo" value="entrada"<?= $entrada ? ' checked' : '' ?><?= $tipoTravado ? ' disabled' : '' ?>><span><?= e(t('Entrada')) ?></span></label>
                </div>
                <?php if ($tipoTravado): ?>
                    <input type="hidden" name="tipo" value="<?= (int) $cat['sinal'] === 1 ? 'entrada' : 'saida' ?>">
                    <p class="cat-dica" id="cat-tipo-dica"><?= e($doApp ? t('O app usa esta categoria nos cálculos, então o tipo fica como está.') : t('Já tem lançamentos: trocar o tipo inverteria o histórico.')) ?></p>
                <?php endif; ?>
                <?= $erroDe('tipo') ?>
            </fieldset>

            <?php if ($escolheValor): ?>
                <fieldset class="cat-opts cat-so-gasto">
                    <legend><?= e(t('O valor é')) ?></legend>
                    <div class="chip-row">
                        <label class="chip-r"><input type="radio" name="valor_tipo" value="varia"<?= $valorTipo === 'varia' ? ' checked' : '' ?>><span><?= e(t('Varia (gasto do dia a dia)')) ?></span></label>
                        <label class="chip-r"><input type="radio" name="valor_tipo" value="fixo"<?= $valorTipo === 'fixo' ? ' checked' : '' ?>><span><?= e(t('Fixo todo mês (conta)')) ?></span></label>
                        <label class="chip-r"><input type="radio" name="valor_tipo" value="pontual"<?= $valorTipo === 'pontual' ? ' checked' : '' ?>><span><?= e(t('De vez em quando (pontual)')) ?></span></label>
                    </div>
                    <?= $erroDe('valor_tipo') ?>
                </fieldset>
            <?php endif; ?>

            <?php if ($temVencimento): ?>
                <div class="cat-dia cat-so-gasto<?= $escolheValor ? ' cat-so-conta' : '' ?>">
                    <label class="campo" for="cat-dia"><span><?= e(t('Vence no dia (opcional)')) ?></span>
                        <input id="cat-dia" type="text" name="dia_vencimento" inputmode="numeric" pattern="[0-9]*" maxlength="2" autocomplete="off" placeholder="<?= e(t('ex.: 10')) ?>"
                               value="<?= e($val('dia', $dia !== null ? (string) $dia : '')) ?>"<?= $invalido('dia') ?>>
                    </label>
                    <?= $erroDe('dia') ?>
                </div>
            <?php endif; ?>

            <?php if (!$movimentacao): ?>
                <fieldset class="cat-opts cat-so-gasto">
                    <legend><?= e(t('Classe')) ?></legend>
                    <div class="chip-row">
                        <label class="chip-r"><input type="radio" name="essencial" value="1"<?= $essencial ? ' checked' : '' ?>><span><?= e(t('Essencial')) ?></span></label>
                        <label class="chip-r"><input type="radio" name="essencial" value="0"<?= $essencial ? '' : ' checked' ?>><span><?= e(t('Não essencial')) ?></span></label>
                    </div>
                </fieldset>
            <?php endif; ?>

            <fieldset class="cat-opts" aria-describedby="cat-ativa-dica">
                <legend><?= e(t('Situação')) ?></legend>
                <div class="chip-row">
                    <label class="chip-r"><input type="radio" name="ativo" value="1"<?= $ativa ? ' checked' : '' ?>><span><?= e(t('Ativa')) ?></span></label>
                    <label class="chip-r"><input type="radio" name="ativo" value="0"<?= $ativa ? '' : ' checked' ?>><span><?= e(t('Arquivada')) ?></span></label>
                </div>
                <p class="cat-dica" id="cat-ativa-dica"><?= e(t('Arquivada sai das listas; os lançamentos ficam.')) ?></p>
            </fieldset>
        </div>

        <?php if ($temMeta): ?>
            <a class="cat-meta" href="/meta?add=<?= $id ?>#c<?= $id ?>"><?= svgIcon('target', 20) ?><span><?= e(t('Valor e recorrência')) ?><small><?= e(t('A meta de cada mês fica em Metas.')) ?></small></span><?= svgIcon('chevron', 16) ?></a>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary btn-block ic-salvar"><?= e(t('Salvar')) ?></button>
    </form>

    <form class="cat-remover" method="post" action="/categorias/<?= $id ?>/remover"
          data-confirm="<?= e(t('Remover a categoria {nome}? Se ela tiver lançamentos, só sai das listas.', ['nome' => nomeCurto($cat)])) ?>">
        <?= csrfField() ?>
        <input type="hidden" name="volta" value="<?= e($volta) ?>">
        <button type="submit" class="btn btn-ghost cat-remover-btn"><?= svgIcon('x', 16) ?><?= e(t('Remover categoria')) ?></button>
    </form>
</div>
