<?php
/**
 * Formulário de um item: o link preenche o resto quando a loja deixa; tudo pode ser escrito à mão.
 * Sem JS, o servidor tenta ler o link ao salvar. O valor fica na moeda escolhida; a conversão mostrada é só
 * uma prévia. Variáveis: $item (null = novo), $form (o que voltou com erro), $taxas (Cotacao::guardadas).
 */
use Financeiro\Services\Cotacao;
use Financeiro\Site;

$novo = $item === null;
$vUrl = (string) ($form['url'] ?? ($item['url'] ?? ''));
$vNome = (string) ($form['nome'] ?? ($item['nome'] ?? ''));
$vValor = (string) ($form['valor'] ?? (isset($item['valor']) ? numInput((float) $item['valor']) : ''));
$vMoeda = (string) ($form['moeda'] ?? ($item['moeda'] ?? Site::moeda()));
// quanto vale cada moeda na do site, para a prévia; o real também entra quando o site usa outra
$taxasJs = [];
foreach (array_keys(['BRL' => 1] + $taxas) as $m) {
    $taxa = $m !== Site::moeda() ? Cotacao::taxaParaSite($m, $taxas) : null;
    if ($taxa !== null) {
        $taxasJs[$m] = ['v' => $taxa, 'd' => diaMes(Cotacao::dataDe($m, $taxas))];
    }
}
$acao = $novo ? '/wishlist' : '/wishlist/' . (int) $item['id'];
?>
<form class="wl-form" method="post" action="<?= e($acao) ?>" enctype="multipart/form-data" data-wl-form data-wl-taxas="<?= e((string) json_encode($taxasJs)) ?>">
    <?= csrfField() ?>
    <input type="hidden" name="lido" value="" data-wl-lido>
    <input type="hidden" name="foto_base64" value="" data-wl-foto64>
    <?php if (!empty($form['erro'])): ?><p class="aj-erro" role="alert"><?= e((string) $form['erro']) ?></p><?php endif; ?>
    <div class="campo">
        <label for="wl-url-<?= $novo ? 'n' : (int) $item['id'] ?>"><?= e(t('Link do produto')) ?></label>
        <input id="wl-url-<?= $novo ? 'n' : (int) $item['id'] ?>" name="url" type="text" inputmode="url" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="<?= e(t('Cole aqui o link da loja')) ?>" value="<?= e($vUrl) ?>" data-wl-url aria-describedby="wl-url-st-<?= $novo ? 'n' : (int) $item['id'] ?>">
        <p class="wl-url-st" id="wl-url-st-<?= $novo ? 'n' : (int) $item['id'] ?>" role="status" data-wl-status><?= e(t('O nome, o valor e a foto vêm sozinhos quando a loja permite.')) ?></p>
    </div>
    <div class="wl-form-cols">
        <div class="wl-form-foto">
            <div class="wl-foto-prev" data-wl-prev>
                <?php if (!$novo && $item['foto_hash'] !== null): ?>
                    <img src="/wishlist/<?= (int) $item['id'] ?>/foto?v=<?= e(substr((string) $item['foto_hash'], 0, 12)) ?>" alt="" data-wl-prev-img>
                <?php else: ?>
                    <?= svgIcon('gift', 28) ?>
                <?php endif; ?>
            </div>
            <label class="btn btn-contorno btn-sm wl-foto-btn">
                <input type="file" name="foto" accept="image/jpeg,image/png,image/webp" data-wl-arquivo>
                <span><?= e(!$novo && $item['foto_hash'] !== null ? t('Trocar foto') : t('Escolher foto')) ?></span>
            </label>
            <?php if (!$novo && $item['foto_hash'] !== null): ?>
                <label class="wl-tirar-foto"><input type="checkbox" name="remover_foto" value="1"> <?= e(t('Tirar a foto')) ?></label>
            <?php endif; ?>
        </div>
        <div class="wl-form-dados">
            <div class="campo">
                <label for="wl-nome-<?= $novo ? 'n' : (int) $item['id'] ?>"><?= e(t('Nome')) ?></label>
                <input id="wl-nome-<?= $novo ? 'n' : (int) $item['id'] ?>" name="nome" type="text" maxlength="160" autocomplete="off" value="<?= e($vNome) ?>" data-wl-nome>
            </div>
            <div class="campo">
                <label for="wl-valor-<?= $novo ? 'n' : (int) $item['id'] ?>"><?= e(t('Valor')) ?></label>
                <span class="emp-valor wl-valor">
                    <select name="moeda" aria-label="<?= e(t('Moeda')) ?>" data-wl-moeda>
                        <?php // i18n: t('Dólar') t('Euro') t('Libra') t('Dólar canadense') t('Dólar australiano') t('Franco suíço') t('Iene') t('Yuan') t('Sol peruano') ?>
                        <?php foreach (Cotacao::MOEDAS as $cod => [$nomeMoeda, $simbolo]): ?>
                            <option value="<?= e($cod) ?>"<?= $cod === $vMoeda ? ' selected' : '' ?>><?= e($simbolo === $cod ? $cod : $simbolo . ' ' . ($cod === 'BRL' ? $nomeMoeda : t($nomeMoeda))) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input id="wl-valor-<?= $novo ? 'n' : (int) $item['id'] ?>" name="valor" type="text" inputmode="decimal" autocomplete="off" placeholder="<?= e(numInput(0)) ?>" value="<?= e($vValor) ?>" data-wl-valor>
                </span>
                <p class="wl-conv num" role="status" data-wl-conv hidden></p>
            </div>
        </div>
    </div>
    <button class="btn btn-principal wl-salvar" type="submit" data-wl-salvar><?= e($novo ? t('Adicionar à lista') : t('Salvar')) ?></button>
</form>
