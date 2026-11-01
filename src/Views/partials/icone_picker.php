<?php
/**
 * Escolher o ícone de uma categoria: os ícones em pixel art, por grupo, como opções de rádio
 * (name="icone"). Funciona sem JS; com JS, em [data-ic-sugerir] o ícone acompanha o nome que
 * se digita até a pessoa escolher um (app.js).
 * Variáveis: $selecionado (?string, a chave marcada), $sugerir (bool), $legenda (string).
 */
use Financeiro\Icones;

$selecionado = $selecionado ?? null;
$sugerir     = !empty($sugerir);
$legenda     = (string) ($legenda ?? t('Ícone'));
?>
<fieldset class="ic-picker"<?= $sugerir ? ' data-ic-sugerir data-ic-regras="' . e(Icones::regrasJson()) . '"' : '' ?>>
    <legend><?= e($legenda) ?></legend>
    <?php if ($sugerir): ?><p class="ic-dica"><?= e(t('Sem escolher, o ícone vem do nome.')) ?></p><?php endif; ?>
    <?php foreach (Icones::porGrupo() as $g => $grupo): if ($grupo['chaves'] === []) { continue; } ?>
        <p class="ic-grupo"><?= e($grupo['rotulo']) ?></p>
        <div class="ic-grade">
            <?php foreach ($grupo['chaves'] as $k): ?>
                <label class="ic-opcao" title="<?= e(Icones::rotulo($k)) ?>">
                    <input type="radio" name="icone" value="<?= e($k) ?>"<?= $k === $selecionado ? ' checked' : '' ?>>
                    <?= Icones::html($k, 40) ?>
                    <span class="sr-only"><?= e(Icones::rotulo($k)) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</fieldset>
