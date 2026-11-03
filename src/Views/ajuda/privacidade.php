<?php
/**
 * Privacidade, aviso e contato, sem login: vale também para quem ainda não entrou. Página de leitura, sem o layout
 * (auth/_casca): uma coluna na largura de texto. Primeiro o que importa em palavras simples, depois os detalhes.
 * O que sai do computador segue docs/SEGURANCA.md; mudou lá, muda aqui. Variável: $repositorio.
 */
$logado = \Financeiro\Auth::logado();
$casca = ['titulo' => t('privacidade · MyMonetto'), 'classe' => 'pub-leitura', 'voltaIdioma' => '/privacidade'];
require __DIR__ . '/../auth/_casca.php';
?>
    <a class="pub-volta" href="<?= $logado ? '/ajuda' : '/login' ?>"><?= svgIcon('chevron', 16, 'ic-esq') ?><?= e($logado ? t('Voltar para a ajuda') : t('Voltar para entrar')) ?></a>
    <h1 class="pub-titulo"><?= e(t('Privacidade e aviso')) ?></h1>
    <p class="pub-lead"><?= e(t('Os seus números ficam no computador onde o MyMonetto foi instalado. Só três coisas saem dele, e nenhuma leva um valor seu.')) ?></p>

    <section id="dados">
        <h2><?= e(t('Seus dados')) ?></h2>
        <p><?= e(t('Os dados ficam no computador onde o app foi instalado. O app não tem telemetria nem anúncios e não pede a senha do seu banco. Só quem tem conta neste app vê os seus números.')) ?></p>
    </section>

    <section id="saidas">
        <h2><?= e(t('O que sai do computador')) ?></h2>
        <ul>
            <li><?= e(t('Cotação de moedas, no máximo a cada 2 horas, em {a} ou {b}. Vai só a lista de moedas.', ['a' => 'economia.awesomeapi.com.br', 'b' => 'api.frankfurter.dev'])) ?></li>
            <li><?= e(t('Um link colado na Wishlist: o app abre o link para ler o nome, a foto e o preço.')) ?></li>
            <li><?= e(t('Procurar versão nova: o app consulta o {site}.', ['site' => 'github.com'])) ?></li>
        </ul>
        <p><?= e(t('Nesses casos, o site vê o endereço IP do computador, mas nenhum valor seu. Sem internet, o app continua funcionando.')) ?></p>
    </section>

    <section id="cookies">
        <h2><?= e(t('Cookies')) ?></h2>
        <p><?= e(t('Só os necessários: a sessão, o “lembrar este aparelho” (60 dias) e o idioma de quem ainda não entrou. O tema fica guardado no navegador. Não há cookies de propaganda nem de medição, por isso não há aviso pedindo consentimento.')) ?></p>
    </section>

    <section id="aviso">
        <h2><?= e(t('Aviso')) ?></h2>
        <p><?= e(t('O MyMonetto é software livre, sob a licença AGPL-3.0, e vem sem garantia. Ele ajuda a organizar as contas, mas não é aconselhamento financeiro: confira os números antes de decidir. Os dados são seus, e os backups ficam com quem instalou o app.')) ?></p>
    </section>

    <section id="contato">
        <h2><?= e(t('Contato')) ?></h2>
        <p><?= e(t('Dúvidas, sugestões e problemas vão para a página do projeto. Uma falha de segurança vai por um caminho reservado, sem aviso público.')) ?></p>
        <ul class="pub-lista-links">
            <li><a href="<?= e($repositorio . '/issues') ?>" rel="noopener"><?= e(t('Relatar um problema')) ?></a></li>
            <li><a href="<?= e($repositorio . '/blob/main/SECURITY.md') ?>" rel="noopener"><?= e(t('Relatar falha de segurança')) ?></a></li>
            <li><a href="<?= e($repositorio) ?>" rel="noopener"><?= e(t('Código-fonte')) ?></a></li>
        </ul>
    </section>
<?php require __DIR__ . '/../auth/_casca_fim.php'; ?>
