<?php
/**
 * Usar no celular. Variáveis: $modo, $ligada, $gerenciada, $pode, $administrador, $url, $qr, $porta, $sistema.
 * Nunca um beco sem saída: quando esta instalação não liga a rede pela tela, a página diz como ligar (o arquivo e a
 * linha) ou quem pode ligar.
 */
$csrf = csrfField();
$arqConfig = \Financeiro\Paths::dados() . DIRECTORY_SEPARATOR . 'config.env';
?>
<div class="grade aj-subtela sis">
    <?php \Financeiro\View::partial('ajustes/_sub_topo', ['titulo' => t('Usar no celular')]); ?>

    <?php if ($modo === 'instalador' && !$pode): ?>
        <p class="faixa-grade aj-sub-aviso"><?= e($administrador ? t('Faça isso no computador onde o MyMonetto está instalado.') : t('Só a primeira conta criada neste site pode fazer isso.')) ?></p>
    <?php endif; ?>

    <?php if ($ligada && $url !== null): ?>
        <section class="faixa-grade sis-grupo" aria-labelledby="sis-rede-t">
            <div class="aj-sub">
            <h2 class="aj-sub-t" id="sis-rede-t"><?= e($modo === 'instalador' ? t('O acesso pela rede está ligado') : t('Endereço deste site')) ?></h2>
            <p><?= e(t('Aponte a câmera do celular para o código ou digite o endereço no navegador dele.')) ?></p>
            <div class="sis-qr"><?= $qr ?></div>
            <p class="sis-url"><b class="num"><?= e($url) ?></b></p>
            <?php if ($modo === 'instalador'): ?>
                <ul class="sis-lista">
                    <li><?= e(t('O celular precisa estar na mesma rede Wi-Fi deste computador.')) ?></li>
                    <li><?= e(t('O site só abre enquanto este computador estiver ligado.')) ?></li>
                    <li><?= e(t('Se o endereço parar de abrir, ele mudou: volte aqui e leia o código de novo.')) ?></li>
                </ul>
                <details class="dobra sis-acao">
                    <summary><?= e(t('O celular não abre o endereço')) ?></summary>
                    <p class="aj-ajuda"><?= e(t('Em geral, é o firewall deste computador. O MyMonetto não mexe nele: libere a porta {porta} você mesmo.', ['porta' => (string) $porta])) ?></p>
                    <?php if ($sistema === 'Windows'): ?>
                        <p class="aj-ajuda"><?= e(t('No Windows, quando aparecer o aviso do firewall, permita o acesso em redes privadas. Se ele não aparecer, abra Segurança do Windows, depois Firewall e proteção de rede, depois Permitir um aplicativo pelo firewall, e permita frankenphp. Isso pode pedir a senha de quem administra o computador. Confira também se a sua rede está marcada como privada.')) ?></p>
                    <?php elseif ($sistema === 'Darwin'): ?>
                        <p class="aj-ajuda"><?= e(t('No Mac, o firewall costuma vir desligado. Se estiver ligado, abra Ajustes do Sistema, depois Rede, depois Firewall, depois Opções, e permita frankenphp.')) ?></p>
                    <?php else: ?>
                        <p class="aj-ajuda"><?= e(t('No Linux, use o comando do firewall que o seu sistema tem:')) ?></p>
                        <pre class="sis-comando">sudo ufw allow <?= (int) $porta ?>/tcp
sudo firewall-cmd --permanent --add-port=<?= (int) $porta ?>/tcp &amp;&amp; sudo firewall-cmd --reload</pre>
                    <?php endif; ?>
                </details>
            <?php endif; ?>
            <?php if ($modo === 'instalador' && $pode && $gerenciada): ?>
                <form method="post" action="/rede">
                    <?= $csrf ?><input type="hidden" name="ligar" value="0">
                    <button type="submit" class="btn btn-contorno aj-salvar"><?= e(t('Desligar o acesso pela rede')) ?></button>
                </form>
            <?php endif; ?>
            </div>
        </section>
    <?php elseif ($ligada): ?>
        <section class="faixa-grade sis-grupo" aria-labelledby="sis-rede-t">
            <div class="aj-sub">
            <h2 class="aj-sub-t" id="sis-rede-t"><?= e(t('Sem rede neste computador')) ?></h2>
            <p><?= e(t('Este computador não parece estar em uma rede. Conecte-o ao Wi-Fi ou ao cabo e abra esta página de novo.')) ?></p>
            <a class="btn btn-contorno aj-salvar" href="/rede"><?= e(t('Abrir de novo')) ?></a>
            </div>
        </section>
    <?php else: ?>
        <section class="faixa-grade sis-grupo" aria-labelledby="sis-rede-t">
            <div class="aj-sub">
            <h2 class="aj-sub-t" id="sis-rede-t"><?= e(t('O acesso pela rede está desligado')) ?></h2>
            <p><?= e(t('Por enquanto, o MyMonetto só abre neste computador. Ligue o acesso para usar no celular e em outros aparelhos da sua casa.')) ?></p>
            <p class="aj-ajuda atencao"><?= e(t('Na rede, a conexão não é criptografada: quem estiver na mesma rede pode ver a sua senha e os seus dados. Ligue só em uma rede de confiança, como a da sua casa, e nunca em Wi-Fi público.')) ?></p>
            <?php if ($modo === 'instalador' && $pode && $gerenciada): ?>
                <form method="post" action="/rede" data-confirm="<?= e(t('Ligar o acesso pela rede? Faça isso só em uma rede de confiança.')) ?>">
                    <?= $csrf ?><input type="hidden" name="ligar" value="1">
                    <button type="submit" class="btn btn-principal aj-salvar"><?= e(t('Ligar o acesso pela rede')) ?></button>
                </form>
            <?php elseif ($modo === 'instalador' && $pode): ?>
                <?php // esta instalação não liga a rede pela tela: o caminho, passo a passo ?>
                <h3 class="aj-sub-t3"><?= e(t('Como ligar nesta instalação')) ?></h3>
                <ol class="sis-passos">
                    <li><?= t('Abra o arquivo {arquivo}.', ['arquivo' => '<code class="sis-pasta">' . e($arqConfig) . '</code>']) ?></li>
                    <li><?= t('Troque a linha {antes} por {depois} e salve.', ['antes' => '<code>ACESSO_REDE=0</code>', 'depois' => '<code>ACESSO_REDE=1</code>']) ?></li>
                    <li><?= e(t('Feche e abra o MyMonetto de novo. Depois, volte a esta página para ler o código com o celular.')) ?></li>
                </ol>
            <?php elseif ($modo === 'instalador'): ?>
                <p><?= e(t('Peça a quem instalou o MyMonetto para ligar, no computador onde ele está.')) ?></p>
            <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>
</div>
