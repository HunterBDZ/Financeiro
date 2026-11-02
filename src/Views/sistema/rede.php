<?php
/** Usar no celular. Variáveis: $modo, $ligada, $gerenciada, $pode, $administrador, $url, $qr, $porta, $sistema. */
$csrf = csrfField();
?>
<div class="aj sis">
    <p class="sis-voltar"><a href="/ajustes"><?= e(t('Voltar para Ajustes')) ?></a></p>
    <h1 class="aj-titulo"><?= e(t('Usar no celular')) ?></h1>

    <?php if ($modo === 'instalador' && !$pode): ?>
        <p class="aviso"><?= e($administrador ? t('Faça isso no computador onde o Financeiro está instalado.') : t('Só a primeira conta criada neste site pode fazer isso.')) ?></p>
    <?php endif; ?>

    <?php if ($ligada && $url !== null): ?>
        <section class="card sis-grupo" aria-labelledby="sis-rede-t">
            <h2 class="titulo" id="sis-rede-t"><?= e($modo === 'instalador' ? t('O acesso pela rede está ligado') : t('Endereço deste site')) ?></h2>
            <p><?= e(t('Aponte a câmera do celular para o código ou digite o endereço no navegador dele.')) ?></p>
            <div class="sis-qr"><?= $qr ?></div>
            <p class="sis-url"><b class="num"><?= e($url) ?></b></p>
            <?php if ($modo === 'instalador'): ?>
                <ul class="sis-lista">
                    <li><?= e(t('O celular precisa estar na mesma rede Wi-Fi deste computador.')) ?></li>
                    <li><?= e(t('O site só abre enquanto este computador estiver ligado.')) ?></li>
                    <li><?= e(t('Se o endereço parar de abrir, ele mudou: volte aqui e leia o código de novo.')) ?></li>
                </ul>
                <details class="sis-acao">
                    <summary><?= e(t('O celular não abre o endereço')) ?></summary>
                    <p class="aj-ajuda"><?= e(t('Em geral, é o firewall deste computador. O Financeiro não mexe nele: libere a porta {porta} você mesmo.', ['porta' => (string) $porta])) ?></p>
                    <?php if ($sistema === 'Windows'): ?>
                        <p class="aj-ajuda"><?= e(t('No Windows, quando aparecer o aviso do firewall, permita o acesso em redes privadas. Confira também se a sua rede está marcada como privada.')) ?></p>
                    <?php elseif ($sistema === 'Darwin'): ?>
                        <p class="aj-ajuda"><?= e(t('No Mac, em Ajustes do Sistema, abra Rede e depois Firewall, e permita o Financeiro.')) ?></p>
                    <?php else: ?>
                        <p class="aj-ajuda"><?= e(t('No Linux, use o comando do firewall que o seu sistema tem:')) ?></p>
                        <pre class="sis-comando">sudo ufw allow <?= (int) $porta ?>/tcp
sudo firewall-cmd --permanent --add-port=<?= (int) $porta ?>/tcp &amp;&amp; sudo firewall-cmd --reload</pre>
                    <?php endif; ?>
                </details>
            <?php endif; ?>
        </section>
    <?php elseif ($ligada): ?>
        <p class="aviso aviso-warn"><?= e(t('Este computador não parece estar em uma rede. Conecte-o ao Wi-Fi ou ao cabo e abra esta página de novo.')) ?></p>
    <?php else: ?>
        <section class="card sis-grupo" aria-labelledby="sis-rede-t">
            <h2 class="titulo" id="sis-rede-t"><?= e(t('O acesso pela rede está desligado')) ?></h2>
            <p><?= e(t('Por enquanto, o Financeiro só abre neste computador. Ligue o acesso para usar no celular e em outros aparelhos da sua casa.')) ?></p>
            <p class="aviso aviso-warn"><?= e(t('Na rede, a conexão não é criptografada: quem estiver na mesma rede pode ver a sua senha e os seus dados. Ligue só em uma rede de confiança, como a da sua casa, e nunca em Wi-Fi público.')) ?></p>
        </section>
    <?php endif; ?>

    <?php if ($modo === 'instalador' && $pode): ?>
        <?php if (!$gerenciada): ?>
            <p class="aj-ajuda"><?= e(t('Esta instalação não liga a rede pela tela.')) ?></p>
        <?php elseif ($ligada): ?>
            <form method="post" action="/rede">
                <?= $csrf ?><input type="hidden" name="ligar" value="0">
                <button type="submit" class="btn btn-ghost aj-salvar"><?= e(t('Desligar o acesso pela rede')) ?></button>
            </form>
        <?php else: ?>
            <form method="post" action="/rede" data-confirm="<?= e(t('Ligar o acesso pela rede? Faça isso só em uma rede de confiança.')) ?>">
                <?= $csrf ?><input type="hidden" name="ligar" value="1">
                <button type="submit" class="btn btn-primary aj-salvar"><?= e(t('Ligar o acesso pela rede')) ?></button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
