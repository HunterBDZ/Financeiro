<?php
/**
 * Backup e atualização. Variáveis: $pode, $administrador, $nomeAdministrador, $tipo, $versao, $estado, $consulta,
 * $programa (Atualizacao::programa, null fora dos pacotes), $versoes, $backupDisponivel, $backups, $pastaBackups, $limiteEnvio.
 */
$csrf = csrfField();
$senha = static function (string $id): void { ?>
    <label class="campo" for="<?= e($id) ?>"><span><?= e(t('A sua senha')) ?></span><input id="<?= e($id) ?>" type="password" name="senha_atual" autocomplete="current-password" required></label>
<?php };
?>
<div class="aj sis">
    <p class="sis-voltar"><a href="/ajustes"><?= e(t('Voltar para Ajustes')) ?></a></p>
    <h1 class="aj-titulo"><?= e(t('Backup e atualização')) ?></h1>

    <?php if (!$pode): ?>
        <p class="aviso"><?= e($administrador
            ? t('Faça isso no computador onde o MyMonetto está instalado.')
            : t('Só {nome}, a primeira conta criada neste site, pode fazer backup, restaurar e atualizar.', ['nome' => $nomeAdministrador])) ?></p>
    <?php endif; ?>

    <section class="card sis-grupo" id="versao" aria-labelledby="sis-versao-t">
        <h2 class="titulo" id="sis-versao-t"><?= e(t('Versão')) ?></h2>
        <p><?= e(t('Você está na versão {versao}.', ['versao' => $versao])) ?></p>
        <?php if ($programa !== null): ?>
            <p class="aj-ajuda sis-programa"><?= e($programa['frankenphp'] !== null
                ? t('Programa que roda o site: FrankenPHP {frankenphp}, com PHP {php}.', ['frankenphp' => $programa['frankenphp'], 'php' => $programa['php']])
                : t('Programa que roda o site: PHP {php}.', ['php' => $programa['php']])) ?></p>
            <?php if ($programa['instalar'] !== null): ?>
                <p class="aviso aviso-warn" id="sis-programa-velho"><?= e(t('Há uma versão nova do programa que roda o MyMonetto. Para instalar, baixe o instalador da versão {versao} e instale por cima. Os seus dados ficam.', ['versao' => $programa['instalar']])) ?>
                    <a href="<?= e($versoes) ?>" rel="noopener"><?= e(t('Abrir a página de versões')) ?></a></p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($estado !== null && $estado['fase'] === 'rodando'): ?>
            <p class="aviso" role="status"><?= e(t('Atualizando para a versão {versao}.', ['versao' => $estado['versao']])) ?> <?= e(t($estado['etapa'])) ?>… <?= e(t('Você pode continuar nesta página.')) ?></p>
        <?php elseif ($estado !== null && $estado['fase'] === 'ok'): ?>
            <p class="aviso" role="status"><?= e(t('Pronto: agora o site está na versão {versao}.', ['versao' => $estado['versao']])) ?></p>
        <?php elseif ($estado !== null && $estado['fase'] === 'falhou'): ?>
            <p class="aviso aviso-error" role="alert"><?= e(t($estado['mensagem'])) ?><?= !empty($estado['intacto']) ? ' ' . e(t('O site não mudou.')) : '' ?></p>
        <?php endif; ?>

        <?php if ($tipo === 'docker'): ?>
            <p class="aj-ajuda"><?= e(t('Nesta instalação, a atualização é feita por uma imagem nova. Rode na pasta do compose.yaml:')) ?></p>
            <pre class="sis-comando">docker compose pull
docker compose up -d</pre>
        <?php elseif ($tipo === 'servidor'): ?>
            <p class="aj-ajuda"><?= e(t('Neste servidor, a atualização é feita por um comando:')) ?></p>
            <pre class="sis-comando">sudo financeiro-atualizar</pre>
        <?php elseif ($tipo === 'loja'): ?>
            <p class="aj-ajuda"><?= e(t('Nesta instalação, quem traz as versões novas é a Microsoft Store.')) ?></p>
        <?php elseif ($tipo === 'dev'): ?>
            <p class="aj-ajuda"><?= e(t('Esta é uma pasta de desenvolvimento: atualize pelo Git.')) ?></p>
        <?php endif; ?>

        <?php if ($consulta !== null): ?>
            <?php if ($consulta['nova']): ?>
                <p class="sis-nova"><b><?= e(t('Versão {versao} disponível.', ['versao' => $consulta['versao']])) ?></b></p>
                <?php if ($consulta['notas'] !== ''): ?><details class="sis-notas"><summary><?= e(t('O que muda')) ?></summary><pre><?= e($consulta['notas']) ?></pre></details><?php endif; ?>
                <?php if ($pode && $tipo === 'pacote'): ?>
                    <p class="aj-ajuda"><?= e(t('Antes de aplicar, o MyMonetto testa a versão numa cópia dos seus dados e faz um backup. Se algo falhar, ele volta sozinho.')) ?></p>
                    <form method="post" action="/sistema/atualizar" data-espera="<?= e(t('Atualizando…')) ?>" data-confirm="<?= e(t('Atualizar para a versão {versao}? O site fica fora do ar por alguns instantes.', ['versao' => $consulta['versao']])) ?>">
                        <?= $csrf ?><input type="hidden" name="versao" value="<?= e($consulta['versao']) ?>">
                        <button type="submit" class="btn btn-primary aj-salvar"><?= e(t('Atualizar agora')) ?></button>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <p class="sis-nova"><?= e(t('Você já está na versão mais nova.')) ?></p>
            <?php endif; ?>
        <?php elseif ($pode && ($estado === null || $estado['fase'] !== 'rodando')): ?>
            <form method="post" action="/sistema/consultar" data-espera="<?= e(t('Procurando…')) ?>">
                <?= $csrf ?>
                <button type="submit" class="btn btn-ghost aj-salvar"><?= e(t('Procurar versão nova')) ?></button>
            </form>
            <p class="aj-ajuda"><?= e(t('O MyMonetto só consulta a internet quando você pede.')) ?></p>
        <?php endif; ?>
    </section>

    <?php if ($pode || !$backupDisponivel): ?>
    <section class="card sis-grupo" id="backup" aria-labelledby="sis-backup-t">
        <h2 class="titulo" id="sis-backup-t"><?= e(t('Backup')) ?></h2>
        <?php if (!$backupDisponivel): ?>
            <p class="aj-ajuda"><?= e(t('Neste site, os backups são feitos no servidor, todos os dias. Veja como restaurar no manual de instalação.')) ?></p>
        <?php elseif ($pode): ?>
            <?php if ($backups !== []): ?>
                <p><?= e(t('Último backup: {backup}.', ['backup' => $backups[0]['rotulo']])) ?></p>
            <?php endif; ?>
            <p class="aj-ajuda"><?= e(t('O MyMonetto faz uma cópia por dia, na primeira vez que você abre. Elas ficam neste computador: guarde de vez em quando uma cópia em outro lugar, como um pen drive.')) ?></p>
            <?php if ($pastaBackups !== null): ?><p class="aj-ajuda sis-pasta"><?= e($pastaBackups) ?></p><?php endif; ?>
            <form method="post" action="/sistema/backup" data-espera="<?= e(t('Fazendo backup…')) ?>">
                <?= $csrf ?>
                <button type="submit" class="btn btn-ghost aj-salvar"><?= e(t('Fazer backup agora')) ?></button>
            </form>
            <details class="sis-acao">
                <summary><?= e(t('Baixar uma cópia')) ?></summary>
                <form class="aj-form" method="post" action="/sistema/exportar" data-espera="<?= e(t('Preparando a cópia…')) ?>" data-espera-baixa>
                    <?= $csrf ?>
                    <p class="aj-ajuda"><?= e(t('A cópia tem todos os dados do site. Guarde em um lugar seguro.')) ?></p>
                    <?php $senha('sis-senha-exportar'); ?>
                    <button type="submit" class="btn btn-primary aj-salvar"><?= e(t('Baixar cópia')) ?></button>
                </form>
            </details>
            <details class="sis-acao" id="restaurar">
                <summary><?= e(t('Restaurar um backup')) ?></summary>
                <form class="aj-form" method="post" action="/sistema/restaurar" enctype="multipart/form-data" data-espera="<?= e(t('Restaurando…')) ?>" data-confirm="<?= e(t('Trocar todos os dados do site pelos do backup? Quem estiver usando vai precisar entrar de novo.')) ?>">
                    <?= $csrf ?>
                    <p class="aj-ajuda"><?= e(t('Todos os dados do site são trocados pelos do backup. Antes, o MyMonetto guarda uma cópia do que há agora.')) ?></p>
                    <?php if ($backups !== []): ?>
                        <label class="campo" for="sis-nome"><span><?= e(t('Backup deste computador')) ?></span>
                            <select id="sis-nome" name="nome">
                                <option value=""><?= e(t('Vou enviar um arquivo')) ?></option>
                                <?php foreach ($backups as $b): ?><option value="<?= e($b['nome']) ?>"><?= e($b['rotulo']) ?></option><?php endforeach; ?>
                            </select>
                        </label>
                    <?php endif; ?>
                    <p class="aj-ajuda"><?= e(t('Arquivo .sqlite, até {limite}', ['limite' => $limiteEnvio])) ?></p>
                    <label class="btn btn-ghost aj-arquivo">
                        <input type="file" name="arquivo" accept=".sqlite" data-arquivo>
                        <span><?= e(t('Escolher arquivo')) ?></span>
                    </label>
                    <p class="aj-ajuda" data-arquivo-nome role="status"></p>
                    <?php $senha('sis-senha-restaurar'); ?>
                    <button type="submit" class="btn btn-primary aj-salvar"><?= e(t('Restaurar')) ?></button>
                </form>
            </details>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>
