<?php
/**
 * Ajustes (SPEC §2.8) — dono: P5. Tudo em <details class="dobra">, cada grupo com o seu SALVAR.
 * Todo value de dinheiro sai de numInput() (hífen ASCII): parseMoedaBr() lê de volta sem perder o sinal.
 */
$csrf = csrfField();
$ultLbl = $ultimo ? monthLabel($ultimo) : '';
$valorDe = static function (string $chave, int $dec = 2) use ($aj, $erro): string {
    if ($erro !== null && isset($erro['valores'][$chave]) && is_string($erro['valores'][$chave])) {
        return $erro['valores'][$chave];                     // devolve o que foi digitado
    }
    $v = $aj[$chave]['valor'] ?? 0.0;
    return $chave === 'juros_mes' ? numInput($v * 100) : numInput($v, $dec);
};
$aberto = static fn(string $g): string => !empty($abertos[$g]) ? ' open' : '';
$erroDe = static function (string $g) use ($erro): void {
    if ($erro !== null && ($erro['grupo'] ?? '') === $g) {
        echo '<p class="aviso aviso-error">' . e((string) $erro['msg']) . '</p>';
    }
};
$campo = static function (string $chave, string $rotulo, string $un = '', int $dec = 2) use ($valorDe, $erro): void {
    $id = 'aj-' . $chave;
    $inval = $erro !== null && ($erro['campo'] ?? '') === $chave; ?>
    <div class="aj-campo">
        <label for="<?= e($id) ?>"><?= e($rotulo) ?></label>
        <span class="aj-input<?= $un !== '' ? ' tem-un' : '' ?>">
            <input id="<?= e($id) ?>" type="text" name="valores[<?= e($chave) ?>]" inputmode="<?= $dec === 0 ? 'numeric' : 'decimal' ?>" autocomplete="off"
                   value="<?= e($valorDe($chave, $dec)) ?>"<?= $inval ? ' aria-invalid="true" autofocus' : '' ?>>
            <?php if ($un !== ''): ?><span class="aj-un"><?= e($un) ?></span><?php endif; ?>
        </span>
    </div>
<?php };
?>
<div class="aj">
    <h1 class="aj-titulo"><?= e(t('Ajustes')) ?></h1>
    <a class="card aj-temas" href="/temas"><?= svgIcon('paleta', 20) ?><span><?= e(t('Temas')) ?></span><?= svgIcon('chevron', 16) ?></a>
    <section class="card aj-icone" id="icone" aria-labelledby="aj-icone-t">
        <h2 class="titulo" id="aj-icone-t"><?= e(t('Ícone do site')) ?></h2>
        <div class="aj-icone-l">
            <img class="aj-icone-img" src="<?= e(\Financeiro\SiteIcone::url()) ?>" alt="<?= e(t('Ícone atual')) ?>" width="64" height="64">
            <p class="aj-ajuda"><?= e(\Financeiro\SiteIcone::personalizado() !== null ? t('Vale para todas as contas deste site.') : t('Este é o ícone padrão. Envie uma imagem para trocar; vale para todas as contas deste site.')) ?></p>
        </div>
        <form class="aj-icone-form" method="post" action="/ajustes/icone" enctype="multipart/form-data">
            <?= $csrf ?>
            <label class="aj-icone-campo"><span><?= e(t('Imagem (JPG, PNG ou WebP, até 3 MB)')) ?></span><input type="file" name="icone" accept="image/jpeg,image/png,image/webp" required></label>
            <button type="submit" class="btn btn-primary aj-salvar"><?= e(t('Salvar ícone')) ?></button>
        </form>
        <?php if (\Financeiro\SiteIcone::personalizado() !== null): ?>
            <form method="post" action="/ajustes/icone/remover"><?= $csrf ?><button type="submit" class="btn-link"><?= e(t('Voltar ao ícone padrão')) ?></button></form>
        <?php endif; ?>
    </section>
    <section class="card aj-recursos" id="recursos" aria-labelledby="aj-recursos-t">
        <h2 class="titulo" id="aj-recursos-t"><?= e(t('O que você usa')) ?></h2>
        <form class="aj-recursos-form" method="post" action="/ajustes/recursos">
            <?= $csrf ?>
            <label class="aj-marcar"><input type="checkbox" name="usa_cartao" value="1"<?= (float) ($aj['usa_cartao']['valor'] ?? 1) >= 1 ? ' checked' : '' ?>><span><?= e(t('Cartão de crédito')) ?></span></label>
            <label class="aj-marcar"><input type="checkbox" name="usa_emprestimo" value="1"<?= (float) ($aj['usa_emprestimo']['valor'] ?? 1) >= 1 ? ' checked' : '' ?>><span><?= e(t('Empréstimo ou financiamento')) ?></span></label>
            <?php $aParte = (int) round((float) ($aj['parcela_modo']['valor'] ?? 1)) === 2; ?>
            <fieldset class="aj-grupo-modo">
                <legend><?= e(t('Parcela do empréstimo')) ?></legend>
                <label class="aj-marcar"><input type="radio" name="parcela_modo" value="1"<?= $aParte ? '' : ' checked' ?>><span><?= e(t('Já vem descontada do salário')) ?></span></label>
                <label class="aj-marcar"><input type="radio" name="parcela_modo" value="2"<?= $aParte ? ' checked' : '' ?>><span><?= e(t('Pago à parte, com o dinheiro da conta')) ?></span></label>
            </fieldset>
            <button type="submit" class="btn btn-primary aj-salvar"><?= e(t('Salvar')) ?></button>
        </form>
    </section>
    <section class="card aj-idioma" aria-labelledby="aj-idioma-t">
        <h2 class="titulo" id="aj-idioma-t"><?= e(t('Idioma')) ?></h2>
        <?php require __DIR__ . '/../partials/idioma_escolha.php'; ?>
    </section>

    <!-- 1: parâmetros -->
    <details class="card dobra aj-grupo" id="parametros"<?= $aberto('parametros') ?>>
        <summary><span class="titulo"><?= e(t('Parâmetros')) ?></span></summary>
        <form class="aj-form" method="post" action="/ajustes/salvar">
            <?= $csrf ?><input type="hidden" name="grupo" value="parametros">
            <?php $campo('reserva_minima', t('Reserva mínima')); ?>
            <?php $campo('meta_final', t('Meta de conta e poupança')); ?>
            <?php $metaInt = (int) round((float) ($aj['meta_mes']['valor'] ?? 0)); ?>
            <div class="aj-campo">
                <label for="aj-meta_mes"><?= e(t('Até quando (opcional)')) ?></label>
                <span class="aj-input aj-input-mes"><input id="aj-meta_mes" type="month" name="valores[meta_mes]" value="<?= $metaInt > 0 ? e(sprintf('%04d-%02d', intdiv($metaInt, 100), $metaInt % 100)) : '' ?>"></span>
            </div>
            <?php $erroDe('parametros'); ?>
            <button type="submit" class="btn btn-primary aj-salvar"><?= e(t('Salvar')) ?></button>
        </form>
    </details>

    <!-- 2: ponto de partida -->
    <details class="card dobra aj-grupo" id="partida"<?= $aberto('partida') ?>>
        <summary><span class="titulo"><?= e(t('Ponto de partida')) ?></span></summary>
        <form class="aj-form aj-inicio" method="post" action="/ajustes/inicio">
            <?= $csrf ?>
            <?php $inicioPlano = \Financeiro\Models\AjusteModel::dataInicio(); ?>
            <div class="aj-campo">
                <label for="aj-data_inicio"><?= e(t('Início do plano')) ?></label>
                <span class="aj-input aj-input-mes"><input id="aj-data_inicio" type="date" name="data_inicio" value="<?= e((string) $inicioPlano) ?>" max="<?= e(date('Y-m-d')) ?>" required></span>
            </div>
            <p class="aj-ajuda"><?= e(t('Os valores abaixo são o que você tinha no início desse dia. Lançamentos valem a partir dele.')) ?></p>
            <button type="submit" class="btn btn-ghost aj-salvar"><?= e(t('Salvar início')) ?></button>
        </form>
        <form class="aj-form" method="post" action="/ajustes/salvar">
            <?= $csrf ?><input type="hidden" name="grupo" value="partida">
            <?php $campo('saldo_ini', t('Saldo da conta no início')); ?>
            <?php $campo('poupanca_ini', t('Poupança no início')); ?>
            <?php $campo('investimentos_ini', t('Investimentos no início')); ?>
            <?php $erroDe('partida'); ?>
            <button type="submit" class="btn btn-primary aj-salvar"><?= e(t('Salvar')) ?></button>
        </form>
    </details>

    <!-- 3: contas recorrentes -->
    <details class="card dobra aj-grupo" id="contas"<?= $aberto('contas') ?>>
        <summary><span class="titulo"><?= e(t('Contas recorrentes')) ?></span></summary>
        <?php if ($recorrentes !== []): ?>
            <ul class="aj-contas">
                <?php foreach ($recorrentes as $r): ?>
                    <li>
                        <span class="aj-conta-nome"><?= e((string) $r['categoria_nome']) ?></span>
                        <span class="aj-conta-dia num"><?= e(t('dia {dia}', ['dia' => (int) $r['dia_vencimento']])) ?></span>
                        <form method="post" action="/ajustes/contas/<?= e((string) (int) $r['id']) ?>/remover">
                            <?= $csrf ?>
                            <button type="submit" class="aj-x" aria-label="<?= e(t('remover {nome}', ['nome' => (string) $r['categoria_nome']])) ?>"><?= svgIcon('x', 16) ?></button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ($elegiveis !== []): ?>
        <form class="aj-nova" method="post" action="/ajustes/contas">
            <?= $csrf ?>
            <select name="categoria_id" aria-label="<?= e(t('conta')) ?>" required>
                <?php $grupoAtual = null; foreach ($elegiveis as $c): ?>
                    <?php if ($c['grupo'] !== $grupoAtual): ?>
                        <?= $grupoAtual !== null ? '</optgroup>' : '' ?><optgroup label="<?= e(grupoLabel($c['grupo'])) ?>">
                        <?php $grupoAtual = $c['grupo']; ?>
                    <?php endif; ?>
                    <option value="<?= e((string) $c['id']) ?>"><?= e($c['nome']) ?></option>
                <?php endforeach; ?>
                <?= $grupoAtual !== null ? '</optgroup>' : '' ?>
            </select>
            <label class="aj-dia"><span><?= e(t('dia')) ?></span><input type="text" name="dia_vencimento" inputmode="numeric" pattern="[0-9]*" maxlength="2" autocomplete="off" required aria-label="<?= e(t('dia do vencimento')) ?>"></label>
            <button type="submit" class="btn btn-ghost"><?= e(t('Adicionar')) ?></button>
        </form>
        <?php endif; ?>
        <?php $erroDe('contas'); ?>
    </details>

    <!-- 4: essenciais -->
    <details class="card dobra aj-grupo" id="essenciais"<?= $aberto('essenciais') ?>>
        <summary><span class="titulo"><?= e(t('Essenciais')) ?></span></summary>
        <form class="aj-form" method="post" action="/ajustes/essenciais">
            <?= $csrf ?>
            <?php $grupoAtual = null; foreach ($gastos as $c): ?>
                <?php if ($c['grupo'] !== $grupoAtual): ?>
                    <?= $grupoAtual !== null ? '</div>' : '' ?>
                    <p class="aj-ess-grupo"><?= e(grupoLabel((string) $c['grupo'])) ?></p>
                    <div class="aj-ess">
                    <?php $grupoAtual = $c['grupo']; ?>
                <?php endif; ?>
                <label class="chip-r"><input type="checkbox" name="essencial[]" value="<?= e((string) $c['id']) ?>"<?= (int) $c['essencial'] === 1 ? ' checked' : '' ?>><span><?= e(nomeCurto($c)) ?></span></label>
            <?php endforeach; ?>
            <?= $grupoAtual !== null ? '</div>' : '' ?>
            <button type="submit" class="btn btn-primary aj-salvar"><?= e(t('Salvar')) ?></button>
        </form>
    </details>

    <!-- 5: aparelhos -->
    <details class="card dobra aj-grupo" id="aparelhos"<?= $aberto('aparelhos') ?>>
        <summary><span class="titulo"><?= e(t('Aparelhos')) ?></span></summary>
        <form method="post" action="/ajustes/aparelhos/sair" class="aj-form" data-confirm="<?= e(t('Sair de todos os aparelhos? Cada um vai pedir login de novo.')) ?>">
            <?= $csrf ?>
            <button type="submit" class="btn btn-ghost aj-sair"><?= svgIcon('logout', 16) ?><?= e(t('Sair de todos os aparelhos')) ?></button>
        </form>
    </details>

    <!-- 6: senha -->
    <details class="card dobra aj-grupo" id="senha"<?= $aberto('senha') ?>>
        <summary><span class="titulo"><?= e(t('Senha')) ?></span></summary>
        <form class="aj-form" method="post" action="/ajustes/senha">
            <?= $csrf ?>
            <input type="text" name="username" value="<?= e((string) ($login ?? '')) ?>" autocomplete="username" hidden>
            <label class="campo"><span><?= e(t('Senha atual')) ?></span><input type="password" name="senha_atual" autocomplete="current-password" required></label>
            <label class="campo"><span><?= e(t('Nova senha')) ?></span><input type="password" name="senha_nova" autocomplete="new-password" minlength="6" required></label>
            <label class="campo"><span><?= e(t('Repita a nova senha')) ?></span><input type="password" name="senha_repetir" autocomplete="new-password" minlength="6" required></label>
            <?php $erroDe('senha'); ?>
            <button type="submit" class="btn btn-primary aj-salvar"><?= e(t('Trocar senha')) ?></button>
        </form>
    </details>

    <?php $arqVersao = __DIR__ . '/../../../VERSION'; ?>
    <?php if (is_file($arqVersao)): ?><p class="aj-versao num"><?= e(t('Versão {versao}', ['versao' => trim((string) file_get_contents($arqVersao))])) ?></p><?php endif; ?>
</div>
