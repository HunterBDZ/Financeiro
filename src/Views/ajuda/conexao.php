<?php
/**
 * O aviso de conexão do navegador, sem login: quem vê o aviso ainda não entrou. Página de leitura, sem o layout
 * (auth/_casca). Os passos de cada navegador, com o nome do botão que a pessoa vai ver (no idioma da tela) e o
 * endereço que ela abriu.
 */
$endereco = preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?: 'localhost';
$botao = static fn(string $nome): string => '<b class="pub-botao">' . e($nome) . '</b>';
$casca = ['titulo' => t('ajuda · MyMonetto'), 'classe' => 'pub-leitura', 'voltaIdioma' => '/ajuda/conexao'];
require __DIR__ . '/../auth/_casca.php';
?>
    <a class="pub-volta" href="/login"><?= svgIcon('chevron', 16, 'ic-esq') ?><?= e(t('Voltar para entrar')) ?></a>
    <h1 class="pub-titulo"><?= e(t('O navegador disse que a conexão não é segura')) ?></h1>
    <p class="pub-lead"><?= e(t('É esperado. Na instalação, o MyMonetto criou um certificado próprio, e o navegador não o conhece. A conexão continua criptografada, e o MyMonetto não manda seus dados para fora da sua rede.')) ?></p>
    <p><?= e(t('Para entrar, siga os passos do seu navegador.')) ?></p>

    <section id="chrome">
        <h2><?= e(t('Chrome e Edge')) ?></h2>
        <ol class="pub-passos">
            <li><?= t('Na tela do aviso, clique ou toque em {botao}.', ['botao' => $botao(t('Avançado'))]) ?></li>
            <li><?= t('Depois, em {chrome} (no Chrome) ou {edge} (no Edge).', ['chrome' => $botao(t('Ir para {endereco} (não seguro)', ['endereco' => $endereco])), 'edge' => $botao(t('Continuar para {endereco} (não seguro)', ['endereco' => $endereco]))]) ?></li>
        </ol>
    </section>

    <section id="firefox">
        <h2>Firefox</h2>
        <ol class="pub-passos">
            <li><?= t('Na tela do aviso, clique ou toque em {botao}.', ['botao' => $botao(t('Avançado…'))]) ?></li>
            <li><?= t('Depois, em {botao}.', ['botao' => $botao(t('Aceitar o risco e continuar'))]) ?></li>
        </ol>
    </section>

    <section id="safari">
        <h2><?= e(t('Safari, no iPhone e no Mac')) ?></h2>
        <ol class="pub-passos">
            <li><?= t('Na tela do aviso, toque ou clique em {botao}.', ['botao' => $botao(t('Mostrar Detalhes'))]) ?></li>
            <li><?= t('No texto que abre, em {botao}.', ['botao' => $botao(t('visitar este site'))]) ?></li>
            <li><?= t('Confirme em {botao}.', ['botao' => $botao(t('Visitar Site'))]) ?></li>
        </ol>
    </section>

    <p class="pub-nota-fim"><?= e(t('O aviso pode voltar depois de um tempo, num aparelho ou navegador novo, ou se o endereço mudar. Se ele aparecer num endereço que não é o do MyMonetto, não continue e fale com quem instalou o app.')) ?></p>
<?php require __DIR__ . '/../auth/_casca_fim.php'; ?>
