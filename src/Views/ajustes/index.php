<?php
use Financeiro\Idioma;
use Financeiro\Site;
use Financeiro\Temas;

/**
 * Ajustes, como uma lista agrupada por assunto: dinheiro e planejamento, aparência e idioma, conta e segurança,
 * sistema e sobre. Cada linha tem ícone, nome e o estado atual à direita; ao tocar, abre ali mesmo (<details>,
 * o id é o mesmo de ?aberto=) ou leva a outra tela (seta para a direita). O perigoso fica no fim de cada grupo.
 * No computador largo, os grupos vão em duas colunas que crescem cada uma por si.
 * Todo value de dinheiro sai de numInput() (hífen ASCII): parseMoedaBr() lê de volta sem perder o sinal.
 * Variáveis: $aj, $contas (quantas contas fixas), $dias (categoria => dia do vencimento), $categorias, $arquivadas,
 * $abertos, $erro, $login.
 */
$csrf = csrfField();
$valorDe = static function (string $chave, int $dec = 2) use ($aj, $erro): string {
    if ($erro !== null && isset($erro['valores'][$chave]) && is_string($erro['valores'][$chave])) {
        return $erro['valores'][$chave];                     // devolve o que foi digitado
    }
    $v = $aj[$chave]['valor'] ?? 0.0;
    return numInput($v, $dec);
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
// o miolo de uma linha: ícone, nome e o estado atual (texto já pronto, ou HTML em $valorHtml)
$linha = static function (string $icone, string $rotulo, string $valor = '', string $valorHtml = ''): string {
    return '<span class="ajs-ic">' . svgIcon($icone, 20) . '</span><span class="ajs-rot">' . e($rotulo) . '</span>'
        . ($valorHtml !== '' ? $valorHtml : ($valor !== '' ? '<span class="ajs-val">' . e($valor) . '</span>' : ''));
};
// uma linha que leva a outra tela
$ir = static function (string $href, string $icone, string $rotulo, string $valor = '', bool $fora = false) use ($linha): void { ?>
    <a class="ajs-linha ajs-ir" href="<?= e($href) ?>"<?= $fora ? ' rel="noopener"' : '' ?>><?= $linha($icone, $rotulo, $valor) ?><?= svgIcon($fora ? 'externo' : 'chevron', 16, 'ajs-seta') ?></a>
<?php };

$duasLigada = \Financeiro\DuasEtapas::ligada((int) \Financeiro\Auth::id());
$pessoas = \Financeiro\Models\UsuarioModel::todos();
$usa = static fn(string $k): bool => (float) ($aj[$k]['valor'] ?? 1) >= 1;
$usos = array_values(array_filter([$usa('usa_cartao') ? t('Cartão') : '', $usa('usa_emprestimo') ? t('Empréstimo') : '', $usa('usa_carro') ? t('Carro') : '']));
$inicioPlano = \Financeiro\Models\AjusteModel::dataInicio();
$temaConta = Temas::doUsuario();
$fuso = Site::fuso();
$cidade = str_replace('_', ' ', substr($fuso, (int) strrpos($fuso, '/') + (str_contains($fuso, '/') ? 1 : 0)));
$arqVersao = __DIR__ . '/../../../VERSION';
?>
<div class="aj ajs">
    <h1 class="aj-titulo"><?= e(t('Ajustes')) ?></h1>

    <div class="ajs-cols">
    <div class="ajs-col">
    <!-- o que mais se mexe: o dinheiro e as categorias -->
    <section class="ajs-sec" aria-labelledby="ajs-g-plano">
        <h2 class="ajs-sec-t" id="ajs-g-plano"><?= e(t('Dinheiro e planejamento')) ?></h2>
        <div class="ajs-lista">
            <?php \Financeiro\View::partial('ajustes/_categorias', ['categorias' => $categorias, 'arquivadas' => $arquivadas, 'dias' => $dias, 'aberto' => $aberto('categorias'), 'linha' => $linha]); ?>

            <details class="ajs-item" id="parametros"<?= $aberto('parametros') ?>>
                <summary class="ajs-linha"><?= $linha('target', t('Reserva e meta'), money((float) ($aj['reserva_minima']['valor'] ?? 0))) ?></summary>
                <div class="ajs-corpo">
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
                </div>
            </details>

            <?php $ir('/meta#contas-fixas', 'calendar', t('Contas fixas'), t('Em Metas')); ?>

            <details class="ajs-item" id="recursos"<?= $aberto('recursos') ?>>
                <summary class="ajs-linha"><?= $linha('check', t('O que você usa'), $usos !== [] ? implode(', ', $usos) : t('Nada marcado')) ?></summary>
                <div class="ajs-corpo">
                    <form class="aj-recursos-form" method="post" action="/ajustes/recursos">
                        <?= $csrf ?>
                        <label class="aj-marcar"><input type="checkbox" name="usa_cartao" value="1"<?= $usa('usa_cartao') ? ' checked' : '' ?>><span><?= e(t('Cartão de crédito')) ?></span></label>
                        <label class="aj-marcar"><input type="checkbox" name="usa_emprestimo" value="1"<?= $usa('usa_emprestimo') ? ' checked' : '' ?>><span><?= e(t('Empréstimo ou financiamento')) ?></span></label>
                        <label class="aj-marcar"><input type="checkbox" name="tem_carro" value="1"<?= $usa('usa_carro') ? ' checked' : '' ?>><span><?= e(t('Carro')) ?></span></label>
                        <?php $naConta = (int) round((float) ($aj['objetivo']['valor'] ?? 1)) === 3; ?>
                        <fieldset class="aj-grupo-modo aj-so-emp">
                            <legend><?= e(t('O que fazer com o que sobrar?')) ?></legend>
                            <label class="aj-marcar"><input type="radio" name="objetivo" value="3"<?= $naConta ? ' checked' : '' ?>><span><?= e(t('Deixar na conta')) ?></span></label>
                            <label class="aj-marcar"><input type="radio" name="objetivo" value="1"<?= $naConta ? '' : ' checked' ?>><span><?= e(t('Usar para adiantar o empréstimo')) ?></span></label>
                        </fieldset>
                        <button type="submit" class="btn btn-primary aj-salvar"><?= e(t('Salvar')) ?></button>
                    </form>
                </div>
            </details>

            <details class="ajs-item" id="partida"<?= $aberto('partida') ?>>
                <summary class="ajs-linha"><?= $linha('relogio', t('Ponto de partida'), $inicioPlano !== null ? diaMesAno($inicioPlano) : '') ?></summary>
                <div class="ajs-corpo">
                    <form class="aj-form aj-inicio" method="post" action="/ajustes/inicio">
                        <?= $csrf ?>
                        <div class="aj-campo">
                            <label for="aj-data_inicio"><?= e(t('Início do controle')) ?></label>
                            <span class="aj-input aj-input-mes"><input id="aj-data_inicio" type="date" name="data_inicio" value="<?= e((string) $inicioPlano) ?>" max="<?= e(\Financeiro\Relogio::hoje()) ?>" required></span>
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
                </div>
            </details>
        </div>
    </section>

    <!-- como o site aparece -->
    <section class="ajs-sec" aria-labelledby="ajs-g-aparencia">
        <h2 class="ajs-sec-t" id="ajs-g-aparencia"><?= e(t('Aparência e idioma')) ?></h2>
        <div class="ajs-lista">
            <?php $ir('/temas', 'paleta', t('Temas'), $temaConta !== null ? t((string) Temas::todos()[$temaConta]['nome']) : t('Automático')); ?>

            <details class="ajs-item" id="idioma"<?= $aberto('idioma') ?>>
                <summary class="ajs-linha"><?= $linha('globo', t('Idioma'), Idioma::LISTA[Idioma::atual()]['nome']) ?></summary>
                <div class="ajs-corpo">
                    <?php require __DIR__ . '/../partials/idioma_escolha.php'; ?>
                </div>
            </details>

            <details class="ajs-item" id="regional"<?= $aberto('regional') ?>>
                <summary class="ajs-linha"><?= $linha('emprestimo', t('Moeda e fuso horário'), Site::moeda() . ' · ' . $cidade) ?></summary>
                <div class="ajs-corpo">
                    <form class="aj-form" method="post" action="/ajustes/regional">
                        <?= $csrf ?>
                        <label class="campo"><span><?= e(t('Moeda')) ?></span>
                            <select name="moeda"><?php foreach (Site::opcoesMoeda() as $cod => $rotulo): ?><option value="<?= e($cod) ?>"<?= $cod === Site::moeda() ? ' selected' : '' ?>><?= e($rotulo) ?></option><?php endforeach; ?></select></label>
                        <p class="aj-ajuda"><?= e(t('Muda só o símbolo: os valores já lançados não são convertidos.')) ?></p>
                        <label class="campo"><span><?= e(t('Fuso horário')) ?></span>
                            <select name="fuso" data-fuso><?php foreach (Site::opcoesFuso() as $nome => $rotulo): ?><option value="<?= e($nome) ?>"<?= $nome === $fuso ? ' selected' : '' ?>><?= e($rotulo) ?></option><?php endforeach; ?></select></label>
                        <p class="aj-ajuda" data-fuso-dica hidden><?= e(t('Seu navegador está em outro fuso:')) ?> <b data-fuso-nome></b> <button type="button" class="btn btn-ghost btn-sm" data-fuso-usar><?= e(t('Usar este')) ?></button></p>
                        <button type="submit" class="btn btn-primary aj-salvar"><?= e(t('Salvar')) ?></button>
                    </form>
                </div>
            </details>

            <details class="ajs-item aj-icone" id="icone"<?= $aberto('icone') ?>>
                <summary class="ajs-linha"><?= $linha('imagem', t('Ícone do site'), \Financeiro\SiteIcone::personalizado() !== null ? t('Próprio') : t('Padrão')) ?></summary>
                <div class="ajs-corpo">
                    <div class="aj-icone-l">
                        <img class="aj-icone-img" src="<?= e(\Financeiro\SiteIcone::url()) ?>" alt="<?= e(t('Ícone atual')) ?>" width="64" height="64">
                        <p class="aj-ajuda"><?= e(t('Vale para todas as contas deste site.')) ?></p>
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
                </div>
            </details>
        </div>
    </section>
    </div>

    <div class="ajs-col">
    <!-- quem entra e como -->
    <section class="ajs-sec" aria-labelledby="ajs-g-conta">
        <h2 class="ajs-sec-t" id="ajs-g-conta"><?= e(t('Conta e segurança')) ?></h2>
        <div class="ajs-lista">
            <details class="ajs-item" id="senha"<?= $aberto('senha') ?>>
                <summary class="ajs-linha"><?= $linha('user', t('Minha conta e senha'), (string) ($login ?? '')) ?></summary>
                <div class="ajs-corpo">
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
                </div>
            </details>

            <details class="ajs-item" id="duasetapas"<?= $aberto('duasetapas') ?>>
                <summary class="ajs-linha"><?= $linha('escudo', t('Verificação em duas etapas'), '', '<span class="aj-estado' . ($duasLigada ? ' is-on' : '') . '">' . e($duasLigada ? t('Ligada') : t('Desligada')) . '</span>') ?></summary>
                <div class="ajs-corpo">
                <?php if ($duasLigada): ?>
                    <p class="aj-ajuda"><?= e(t('Ao entrar, depois da senha, o Financeiro pede o código do app autenticador. Os aparelhos lembrados continuam entrando direto.')) ?></p>
                    <p class="aj-ajuda"><?= e(tn(\Financeiro\DuasEtapas::codigosRestantes((int) \Financeiro\Auth::id()), 'Resta 1 código de recuperação.', 'Restam {n} códigos de recuperação.')) ?></p>
                    <form class="aj-form" method="post" action="/ajustes/duas-etapas/desligar">
                        <?= $csrf ?>
                        <input type="text" name="username" value="<?= e((string) ($login ?? '')) ?>" autocomplete="username" hidden>
                        <label class="campo"><span><?= e(t('Senha')) ?></span><input type="password" name="senha" autocomplete="current-password" required></label>
                        <label class="campo"><span><?= e(t('Código do app ou de recuperação')) ?></span><input type="text" name="codigo" maxlength="40" autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" required></label>
                        <?php $erroDe('duasetapas'); ?>
                        <button type="submit" class="btn btn-ghost aj-salvar"><?= e(t('Desligar')) ?></button>
                    </form>
                <?php else: ?>
                    <p class="aj-ajuda"><?= e(t('Protege a conta mesmo se alguém descobrir a senha: ao entrar, o Financeiro pede também um código de 6 dígitos que muda a cada 30 segundos.')) ?></p>
                    <p class="aj-ajuda"><?= e(t('O código aparece num app autenticador, que você instala no celular. Há vários gratuitos: procure por “autenticador” na loja de apps do celular.')) ?></p>
                    <?php if (\Financeiro\DuasEtapas::disponivel()): ?>
                        <a class="btn btn-primary aj-salvar" href="/ajustes/duas-etapas"><?= e(t('Ligar')) ?></a>
                    <?php else: ?>
                        <p class="aviso aviso-warn"><?= e(t('Falta a extensão sodium do PHP neste computador. Sem ela, a verificação não pode ser ligada.')) ?></p>
                    <?php endif; ?>
                <?php endif; ?>
                </div>
            </details>

            <details class="ajs-item" id="pessoas"<?= $aberto('pessoas') ?>>
                <summary class="ajs-linha"><?= $linha('pessoas', t('Pessoas'), tn(count($pessoas), '1 pessoa', '{n} pessoas')) ?></summary>
                <div class="ajs-corpo">
                    <p class="aj-ajuda"><?= e(implode(', ', array_column($pessoas, 'nome'))) ?></p>
                    <form class="aj-form" method="post" action="/ajustes/pessoas">
                        <?= $csrf ?>
                        <label class="campo"><span><?= e(t('Nome')) ?></span><input type="text" name="nome" maxlength="100" autocomplete="off" required></label>
                        <label class="campo"><span><?= e(t('Nome de usuário')) ?></span><input type="text" name="login" maxlength="100" autocomplete="off" autocapitalize="none" spellcheck="false" required></label>
                        <label class="campo"><span><?= e(t('Senha')) ?></span><input type="password" name="senha" autocomplete="new-password" minlength="8" required></label>
                        <?php $erroDe('pessoas'); ?>
                        <button type="submit" class="btn btn-primary aj-salvar"><?= e(t('Adicionar pessoa')) ?></button>
                    </form>
                    <?php foreach ($pessoas as $pessoa): if ((int) $pessoa['id'] === (int) \Financeiro\Auth::id()) { continue; } ?>
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
                </div>
            </details>

            <details class="ajs-item" id="aparelhos"<?= $aberto('aparelhos') ?>>
                <summary class="ajs-linha"><?= $linha('logout', t('Aparelhos')) ?></summary>
                <div class="ajs-corpo">
                    <form method="post" action="/ajustes/aparelhos/sair" class="aj-form" data-confirm="<?= e(t('Sair de todos os aparelhos? Cada um vai pedir login de novo.')) ?>">
                        <?= $csrf ?>
                        <p class="aj-ajuda"><?= e(t('Perdeu um aparelho ou entrou num que não é seu? Saia de todos; cada um vai pedir login de novo.')) ?></p>
                        <button type="submit" class="btn btn-ghost aj-sair"><?= svgIcon('logout', 16) ?><?= e(t('Sair de todos os aparelhos')) ?></button>
                    </form>
                </div>
            </details>
        </div>
    </section>

    <!-- onde o site roda: acesso pela rede, cópias e versões -->
    <section class="ajs-sec" aria-labelledby="ajs-g-sistema">
        <h2 class="ajs-sec-t" id="ajs-g-sistema"><?= e(t('Sistema')) ?></h2>
        <div class="ajs-lista">
            <?php $ir('/rede', 'celular', t('Usar no celular')); ?>
            <?php $ir('/sistema', 'download', t('Backup e atualização')); ?>
        </div>
    </section>

    <section class="ajs-sec" aria-labelledby="ajs-g-sobre">
        <h2 class="ajs-sec-t" id="ajs-g-sobre"><?= e(t('Sobre')) ?></h2>
        <div class="ajs-lista">
            <?php if (is_file($arqVersao)): ?>
                <div class="ajs-linha"><?= $linha('info', t('Versão'), trim((string) file_get_contents($arqVersao))) ?></div>
            <?php endif; ?>
            <?php // a AGPL pede que quem usa pela rede ache o código: quem modificar o app troca o arquivo REPOSITORIO ?>
            <?php $ir('https://github.com/' . \Financeiro\Atualizacao::repositorio(), 'codigo', t('Código-fonte'), '', true); ?>
            <?php $ir('/privacidade', 'lock', t('Privacidade')); ?>
        </div>
    </section>
    </div>
    </div>
</div>
