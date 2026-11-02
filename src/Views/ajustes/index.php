<?php
use Financeiro\Site;

/**
 * Ajustes. Na ordem do que mais se usa: a conta, o idioma, a moeda e o fuso, o que usa, o dinheiro, as contas
 * (onde o dinheiro está), as contas fixas e as categorias, as pessoas e, no fim, o Avançado. Cada grupo tem o seu SALVAR.
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

    <!-- 1: a conta e a senha -->
    <details class="card dobra aj-grupo" id="senha"<?= $aberto('senha') ?>>
        <summary><span class="titulo"><?= e(t('Minha conta e senha')) ?></span></summary>
        <p class="aj-quem"><b><?= e((string) (\Financeiro\Auth::nome() ?? '')) ?></b> <span class="num"><?= e((string) ($login ?? '')) ?></span></p>
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

    <section class="card aj-idioma" aria-labelledby="aj-idioma-t">
        <h2 class="titulo" id="aj-idioma-t"><?= e(t('Idioma')) ?></h2>
        <?php require __DIR__ . '/../partials/idioma_escolha.php'; ?>
    </section>

    <section class="card aj-regional" id="regional" aria-labelledby="aj-regional-t">
        <h2 class="titulo" id="aj-regional-t"><?= e(t('Moeda e fuso horário')) ?></h2>
        <form class="aj-form" method="post" action="/ajustes/regional">
            <?= $csrf ?>
            <label class="campo"><span><?= e(t('Moeda')) ?></span>
                <select name="moeda"><?php foreach (Site::opcoesMoeda() as $cod => $rotulo): ?><option value="<?= e($cod) ?>"<?= $cod === Site::moeda() ? ' selected' : '' ?>><?= e($rotulo) ?></option><?php endforeach; ?></select></label>
            <p class="aj-ajuda"><?= e(t('Muda só o símbolo: os valores já lançados não são convertidos.')) ?></p>
            <label class="campo"><span><?= e(t('Fuso horário')) ?></span>
                <select name="fuso" data-fuso><?php foreach (Site::opcoesFuso() as $nome => $rotulo): ?><option value="<?= e($nome) ?>"<?= $nome === Site::fuso() ? ' selected' : '' ?>><?= e($rotulo) ?></option><?php endforeach; ?></select></label>
            <p class="aj-ajuda" data-fuso-dica hidden><?= e(t('Seu navegador está em outro fuso:')) ?> <b data-fuso-nome></b> <button type="button" class="btn btn-ghost btn-sm" data-fuso-usar><?= e(t('Usar este')) ?></button></p>
            <button type="submit" class="btn btn-primary aj-salvar"><?= e(t('Salvar')) ?></button>
        </form>
    </section>

    <a class="card aj-temas" href="/temas"><?= svgIcon('paleta', 20) ?><span><?= e(t('Temas')) ?></span><?= svgIcon('chevron', 16) ?></a>
    <section class="card aj-recursos" id="recursos" aria-labelledby="aj-recursos-t">
        <h2 class="titulo" id="aj-recursos-t"><?= e(t('O que você usa')) ?></h2>
        <form class="aj-recursos-form" method="post" action="/ajustes/recursos">
            <?= $csrf ?>
            <label class="aj-marcar"><input type="checkbox" name="usa_cartao" value="1"<?= (float) ($aj['usa_cartao']['valor'] ?? 1) >= 1 ? ' checked' : '' ?>><span><?= e(t('Cartão de crédito')) ?></span></label>
            <label class="aj-marcar"><input type="checkbox" name="usa_emprestimo" value="1"<?= (float) ($aj['usa_emprestimo']['valor'] ?? 1) >= 1 ? ' checked' : '' ?>><span><?= e(t('Empréstimo ou financiamento')) ?></span></label>
            <label class="aj-marcar"><input type="checkbox" name="tem_carro" value="1"<?= (float) ($aj['usa_carro']['valor'] ?? 1) >= 1 ? ' checked' : '' ?>><span><?= e(t('Carro')) ?></span></label>
            <?php $aParte = (int) round((float) ($aj['parcela_modo']['valor'] ?? 1)) === 2; ?>
            <fieldset class="aj-grupo-modo aj-so-emp">
                <legend><?= e(t('Parcela do empréstimo')) ?></legend>
                <label class="aj-marcar"><input type="radio" name="parcela_modo" value="1"<?= $aParte ? '' : ' checked' ?>><span><?= e(t('A parcela já sai direto da renda, antes de cair na conta')) ?></span></label>
                <label class="aj-marcar"><input type="radio" name="parcela_modo" value="2"<?= $aParte ? ' checked' : '' ?>><span><?= e(t('Pago à parte, com o dinheiro da conta')) ?></span></label>
            </fieldset>
            <?php $naConta = (int) round((float) ($aj['objetivo']['valor'] ?? 1)) === 3; ?>
            <fieldset class="aj-grupo-modo aj-so-emp">
                <legend><?= e(t('O que fazer com o que sobrar?')) ?></legend>
                <label class="aj-marcar"><input type="radio" name="objetivo" value="3"<?= $naConta ? ' checked' : '' ?>><span><?= e(t('Deixar na conta')) ?></span></label>
                <label class="aj-marcar"><input type="radio" name="objetivo" value="1"<?= $naConta ? '' : ' checked' ?>><span><?= e(t('Usar para adiantar o empréstimo')) ?></span></label>
            </fieldset>
            <button type="submit" class="btn btn-primary aj-salvar"><?= e(t('Salvar')) ?></button>
        </form>
    </section>

    <!-- 2: o dinheiro -->
    <details class="card dobra aj-grupo" id="parametros"<?= $aberto('parametros') ?>>
        <summary><span class="titulo"><?= e(t('Dinheiro')) ?></span></summary>
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

    <!-- onde o dinheiro está: banco, banco digital, dinheiro vivo -->
    <?php
    $ativasD = array_values(array_filter($contasDinheiro, static fn(array $c): bool => $c['ativa']));
    $arquivadasD = array_values(array_filter($contasDinheiro, static fn(array $c): bool => !$c['ativa']));
    $erroConta = $erro !== null && ($erro['grupo'] ?? '') === 'carteira' ? $erro : null;
    $inicioD = \Financeiro\Models\AjusteModel::dataInicio();
    $tiposD = ['corrente' => t('Banco'), 'digital' => t('Banco digital'), 'dinheiro' => t('Dinheiro'), 'outra' => t('Outra')];
    $formConta = static function (?array $c) use ($csrf, $erroConta, $tiposD, $inicioD): void {
        $id = (int) ($c['id'] ?? 0);
        $volta = $erroConta !== null && (int) ($erroConta['conta'] ?? -1) === $id ? (array) ($erroConta['valores'] ?? []) : [];
        $nome = (string) ($volta['nome'] ?? ($c !== null ? nomeConta($c) : ''));
        $tipo = (string) ($volta['tipo'] ?? ($c['tipo'] ?? 'corrente'));
        $saldo = (string) ($volta['saldo_inicial'] ?? ($c !== null ? numInput((float) $c['saldo_inicial']) : ''));
        $pre = 'cd' . $id; ?>
        <form class="aj-form aj-conta-form" method="post" action="/contas">
            <?= $csrf ?><?php if ($c !== null): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
            <label class="campo"><span><?= e(t('Nome')) ?></span><input type="text" name="nome" maxlength="60" required autocomplete="off" value="<?= e($nome) ?>" placeholder="<?= e(t('ex.: Carteira')) ?>"></label>
            <fieldset class="aj-conta-tipo">
                <legend><?= e(t('Tipo')) ?></legend>
                <?php foreach (\Financeiro\Models\ContaModel::TIPOS as $k => $icone): ?>
                    <label class="chip-r"><input type="radio" name="tipo" value="<?= e($k) ?>"<?= $k === $tipo ? ' checked' : '' ?>><span><span aria-hidden="true"><?= e($icone) ?></span> <?= e($tiposD[$k]) ?></span></label>
                <?php endforeach; ?>
            </fieldset>
            <div class="aj-campo">
                <label for="<?= e($pre) ?>-saldo"><?= $inicioD !== null ? e(t('Saldo em {data}, no início do controle', ['data' => diaMesAno($inicioD)])) : e(t('Saldo no início do controle')) ?></label>
                <span class="aj-input"><input id="<?= e($pre) ?>-saldo" type="text" name="saldo_inicial" inputmode="decimal" autocomplete="off" value="<?= e($saldo) ?>" placeholder="<?= e(numInput(0)) ?>"></span>
            </div>
            <button type="submit" class="btn btn-primary aj-salvar"><?= $c !== null ? e(t('Salvar')) : e(t('Criar conta')) ?></button>
        </form>
    <?php };
    ?>
    <details class="card dobra aj-grupo" id="carteira"<?= $aberto('carteira') ?>>
        <summary><span class="titulo"><?= e(t('Contas')) ?></span></summary>
        <p class="aj-ajuda"><?= e(t('Onde o dinheiro está: banco, banco digital ou dinheiro vivo. Toda conta ativa entra no saldo do Início.')) ?></p>
        <ul class="aj-cd">
            <?php foreach ($ativasD as $i => $c): $aberta = $erroConta !== null && (int) ($erroConta['conta'] ?? 0) === $c['id']; ?>
                <li>
                    <details class="aj-cd-i"<?= $aberta ? ' open' : '' ?>>
                        <summary>
                            <span class="aj-cd-ic" aria-hidden="true"><?= e(\Financeiro\Models\ContaModel::TIPOS[$c['tipo']] ?? '') ?></span>
                            <span class="aj-cd-nome"><?= e(nomeConta($c)) ?></span>
                            <span class="aj-cd-saldo num"><?= e(money((float) ($saldosContas[$c['id']] ?? 0.0))) ?></span>
                        </summary>
                        <?php $formConta($c); ?>
                        <?php if (count($ativasD) > 1): ?>
                            <div class="aj-cd-acoes">
                                <?php if ($i > 0): ?><form method="post" action="/contas/<?= (int) $c['id'] ?>/mover"><?= $csrf ?><input type="hidden" name="direcao" value="-1"><button type="submit" class="btn btn-ghost btn-sm"><?= e(t('Subir')) ?></button></form><?php endif; ?>
                                <?php if ($i < count($ativasD) - 1): ?><form method="post" action="/contas/<?= (int) $c['id'] ?>/mover"><?= $csrf ?><input type="hidden" name="direcao" value="1"><button type="submit" class="btn btn-ghost btn-sm"><?= e(t('Descer')) ?></button></form><?php endif; ?>
                                <form method="post" action="/contas/<?= (int) $c['id'] ?>/arquivar" data-confirm="<?= e(t('Arquivar {nome}? Ela sai do lançar e do saldo; o que foi lançado nela continua no Extrato.', ['nome' => nomeConta($c)])) ?>"><?= $csrf ?><button type="submit" class="btn-link"><?= e(t('Arquivar')) ?></button></form>
                            </div>
                        <?php endif; ?>
                    </details>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($arquivadasD !== []): ?>
            <p class="aj-ess-grupo"><?= e(t('Arquivadas')) ?></p>
            <ul class="aj-cd aj-cd-arq">
                <?php foreach ($arquivadasD as $c): ?>
                    <li><span class="aj-cd-ic" aria-hidden="true"><?= e(\Financeiro\Models\ContaModel::TIPOS[$c['tipo']] ?? '') ?></span><span class="aj-cd-nome"><?= e(nomeConta($c)) ?></span>
                        <form method="post" action="/contas/<?= (int) $c['id'] ?>/reativar"><?= $csrf ?><button type="submit" class="btn-link"><?= e(t('Trazer de volta')) ?></button></form></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <details class="aj-cd-nova"<?= $erroConta !== null && (int) ($erroConta['conta'] ?? -1) === 0 ? ' open' : '' ?>>
            <summary class="btn btn-ghost"><?= svgIcon('plus', 16) ?><?= e(t('Nova conta')) ?></summary>
            <?php $formConta(null); ?>
        </details>
        <?php $erroDe('carteira'); ?>
    </details>

    <!-- 3: contas fixas -->
    <details class="card dobra aj-grupo" id="contas"<?= $aberto('contas') ?>>
        <summary><span class="titulo"><?= e(t('Contas fixas')) ?></span></summary>
        <?php if ($recorrentes !== []): ?>
            <ul class="aj-contas">
                <?php foreach ($recorrentes as $r): ?>
                    <li>
                        <span class="aj-conta-nome"><?= e(nomeCategoria((string) $r['categoria_nome'])) ?></span>
                        <span class="aj-conta-dia num"><?= e(t('dia {dia}', ['dia' => (int) $r['dia_vencimento']])) ?></span>
                        <form method="post" action="/ajustes/contas/<?= e((string) (int) $r['id']) ?>/remover">
                            <?= $csrf ?>
                            <button type="submit" class="aj-x" aria-label="<?= e(t('remover {nome}', ['nome' => nomeCategoria((string) $r['categoria_nome'])])) ?>"><?= svgIcon('x', 16) ?></button>
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
                    <option value="<?= e((string) $c['id']) ?>"><?= e(nomeCategoria($c['nome'])) ?></option>
                <?php endforeach; ?>
                <?= $grupoAtual !== null ? '</optgroup>' : '' ?>
            </select>
            <label class="aj-dia"><span><?= e(t('dia')) ?></span><input type="text" name="dia_vencimento" inputmode="numeric" pattern="[0-9]*" maxlength="2" autocomplete="off" required aria-label="<?= e(t('dia do vencimento')) ?>"></label>
            <button type="submit" class="btn btn-ghost"><?= e(t('Adicionar')) ?></button>
        </form>
        <?php endif; ?>
        <?php $erroDe('contas'); ?>
        <p class="aj-ajuda aj-ajuda-fim"><?= t('Não achou a conta? {link}', ['link' => '<a class="aj-link" href="/ajustes?aberto=categorias#categorias">' . e(t('Criar categoria')) . '</a>']) ?></p>
    </details>

    <!-- 4: categorias -->
    <?php \Financeiro\View::partial('ajustes/_categorias', ['categorias' => $categorias, 'aberto' => $aberto('categorias')]); ?>

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

    <!-- pessoas com conta -->
    <details class="card dobra aj-grupo" id="pessoas"<?= $aberto('pessoas') ?>>
        <summary><span class="titulo"><?= e(t('Pessoas')) ?></span></summary>
        <?php $todas = \Financeiro\Models\UsuarioModel::todos(); ?>
        <p class="muted"><?= e(implode(', ', array_column($todas, 'nome'))) ?></p>
        <form class="aj-form" method="post" action="/ajustes/pessoas">
            <?= $csrf ?>
            <label class="campo"><span><?= e(t('Nome')) ?></span><input type="text" name="nome" maxlength="100" autocomplete="off" required></label>
            <label class="campo"><span><?= e(t('Nome de usuário')) ?></span><input type="text" name="login" maxlength="100" autocomplete="off" autocapitalize="none" spellcheck="false" required></label>
            <label class="campo"><span><?= e(t('Senha')) ?></span><input type="password" name="senha" autocomplete="new-password" minlength="8" required></label>
            <?php $erroDe('pessoas'); ?>
            <button type="submit" class="btn btn-primary aj-salvar"><?= e(t('Adicionar pessoa')) ?></button>
        </form>
        <?php foreach ($todas as $pessoa): if ((int) $pessoa['id'] === (int) \Financeiro\Auth::id()) { continue; } ?>
            <details class="aj-pessoa">
                <summary><?= e(t('Definir nova senha para {nome}', ['nome' => $pessoa['nome']])) ?></summary>
                <form class="aj-form" method="post" action="/ajustes/pessoas/<?= (int) $pessoa['id'] ?>/senha">
                    <?= $csrf ?>
                    <label class="campo"><span><?= e(t('A sua senha')) ?></span><input type="password" name="senha_atual" autocomplete="current-password" required></label>
                    <label class="campo"><span><?= e(t('Nova senha de {nome}', ['nome' => $pessoa['nome']])) ?></span><input type="password" name="senha" autocomplete="new-password" minlength="8" required></label>
                    <button type="submit" class="btn btn-primary aj-salvar"><?= e(t('Definir senha')) ?></button>
                </form>
            </details>
        <?php endforeach; ?>
    </details>

    <section class="aj-avancado" aria-labelledby="aj-avancado-t">
        <h2 class="aj-avancado-t" id="aj-avancado-t"><?= e(t('Avançado')) ?></h2>
    <!-- ponto de partida -->
    <details class="card dobra aj-grupo" id="partida"<?= $aberto('partida') ?>>
        <summary><span class="titulo"><?= e(t('Ponto de partida')) ?></span></summary>
        <form class="aj-form aj-inicio" method="post" action="/ajustes/inicio">
            <?= $csrf ?>
            <?php $inicioPlano = \Financeiro\Models\AjusteModel::dataInicio(); ?>
            <div class="aj-campo">
                <label for="aj-data_inicio"><?= e(t('Início do controle')) ?></label>
                <span class="aj-input aj-input-mes"><input id="aj-data_inicio" type="date" name="data_inicio" value="<?= e((string) $inicioPlano) ?>" max="<?= e(\Financeiro\Relogio::hoje()) ?>" required></span>
            </div>
            <p class="aj-ajuda"><?= e(t('Os valores abaixo são o que você tinha no início desse dia. Lançamentos valem a partir dele.')) ?></p>
            <button type="submit" class="btn btn-ghost aj-salvar"><?= e(t('Salvar início')) ?></button>
        </form>
        <form class="aj-form" method="post" action="/ajustes/salvar">
            <?= $csrf ?><input type="hidden" name="grupo" value="partida">
            <?php if (count($contasDinheiro) === 1): ?>
                <?php $campo('saldo_ini', t('Saldo da conta no início')); ?>
            <?php else: ?>
                <p class="aj-ajuda"><?= t('O saldo de cada conta no início fica em {link}.', ['link' => '<a class="aj-link" href="/ajustes?aberto=carteira#carteira">' . e(t('Contas')) . '</a>']) ?></p>
            <?php endif; ?>
            <?php $campo('poupanca_ini', t('Poupança no início')); ?>
            <?php $campo('investimentos_ini', t('Investimentos no início')); ?>
            <?php $erroDe('partida'); ?>
            <button type="submit" class="btn btn-primary aj-salvar"><?= e(t('Salvar')) ?></button>
        </form>
    </details>

    <!-- ícone do site -->
    <details class="card dobra aj-grupo aj-icone" id="icone"<?= $aberto('icone') ?>>
        <summary><span class="titulo"><?= e(t('Ícone do site')) ?></span></summary>
        <div class="aj-icone-l">
            <img class="aj-icone-img" src="<?= e(\Financeiro\SiteIcone::url()) ?>" alt="<?= e(t('Ícone atual')) ?>" width="64" height="64">
            <p class="aj-ajuda"><?= e(\Financeiro\SiteIcone::personalizado() !== null ? t('Vale para todas as contas deste site.') : t('Este é o ícone padrão. Envie uma imagem para trocar; vale para todas as contas deste site.')) ?></p>
        </div>
        <form class="aj-icone-form" method="post" action="/ajustes/icone" enctype="multipart/form-data">
            <?= $csrf ?>
            <p class="aj-ajuda"><?= e(t('Imagem (JPG, PNG ou WebP, até 3 MB)')) ?></p>
            <label class="btn btn-ghost aj-arquivo">
                <input type="file" name="icone" accept="image/jpeg,image/png,image/webp" required data-arquivo>
                <span><?= e(t('Escolher imagem')) ?></span>
            </label>
            <p class="aj-ajuda" data-arquivo-nome role="status"></p>
            <button type="submit" class="btn btn-primary aj-salvar"><?= e(t('Salvar ícone')) ?></button>
        </form>
        <?php if (\Financeiro\SiteIcone::personalizado() !== null): ?>
            <form method="post" action="/ajustes/icone/remover"><?= $csrf ?><button type="submit" class="btn-link"><?= e(t('Voltar ao ícone padrão')) ?></button></form>
        <?php endif; ?>
    </details>

    <!-- aparelhos -->
    <details class="card dobra aj-grupo" id="aparelhos"<?= $aberto('aparelhos') ?>>
        <summary><span class="titulo"><?= e(t('Aparelhos')) ?></span></summary>
        <form method="post" action="/ajustes/aparelhos/sair" class="aj-form" data-confirm="<?= e(t('Sair de todos os aparelhos? Cada um vai pedir login de novo.')) ?>">
            <?= $csrf ?>
            <button type="submit" class="btn btn-ghost aj-sair"><?= svgIcon('logout', 16) ?><?= e(t('Sair de todos os aparelhos')) ?></button>
        </form>
    </details>

    <a class="card aj-grupo aj-atalho" href="/sistema"><span class="titulo"><?= e(t('Backup e atualização')) ?></span><?= svgIcon('chevron', 16) ?></a>
    <a class="card aj-grupo aj-atalho" href="/rede"><span class="titulo"><?= e(t('Usar no celular')) ?></span><?= svgIcon('chevron', 16) ?></a>
    </section>

    <?php $arqVersao = __DIR__ . '/../../../VERSION'; ?>
    <p class="aj-versao">
        <?php if (is_file($arqVersao)): ?><span class="num"><?= e(t('Versão {versao}', ['versao' => trim((string) file_get_contents($arqVersao))])) ?></span><?php endif; ?>
        <?php // a AGPL pede que quem usa pela rede ache o código: quem modificar o app troca o arquivo REPOSITORIO ?>
        <a href="<?= e('https://github.com/' . \Financeiro\Atualizacao::repositorio()) ?>" rel="noopener"><?= e(t('Código-fonte')) ?></a>
    </p>
</div>
