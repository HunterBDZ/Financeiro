<?php
/**
 * Privacidade, aviso e contato, sem login: vale também para quem ainda não entrou. Página sem o layout.
 * O que sai do computador segue docs/SEGURANCA.md; mudou lá, muda aqui. Variável: $repositorio.
 */
$css = ['/assets/css/style.css', '/assets/css/lancar.css', '/assets/css/temas.css'];
$logado = \Financeiro\Auth::logado();
?>
<!doctype html>
<html lang="<?= e(\Financeiro\Idioma::html()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#F6F8FB" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0A1020" media="(prefers-color-scheme: dark)">
<title><?= e(t('privacidade · MyMonetto')) ?></title>
<?= \Financeiro\SiteIcone::tags() ?>
<script src="<?= e(asset('/assets/js/theme.js')) ?>"></script>
<?php foreach ($css as $c): ?>
<link rel="stylesheet" href="<?= e(asset($c)) ?>">
<?php endforeach; ?>
</head>
<body class="lg-body">
<main class="lg lg-texto lg-privacidade">
    <div class="lg-topo">
        <span class="marca"><img src="<?= e(\Financeiro\SiteIcone::url()) ?>" alt="" width="96" height="96"></span>
        <b translate="no">MyMonetto</b>
    </div>
    <div class="lg-card lg-card-sem-espia">
        <h1 class="titulo"><?= e(t('Privacidade e aviso')) ?></h1>

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
            <p><?= e(t('Dúvidas, sugestões e problemas vão para as Issues do repositório. Uma falha de segurança vai pelo caminho do SECURITY.md, sem abrir Issue pública.')) ?></p>
            <p class="lg-links">
                <a href="<?= e($repositorio . '/issues') ?>" rel="noopener"><?= e(t('Abrir uma Issue')) ?></a>
                <a href="<?= e($repositorio . '/blob/main/SECURITY.md') ?>" rel="noopener"><?= e(t('Relatar falha de segurança')) ?></a>
                <a href="<?= e($repositorio) ?>" rel="noopener"><?= e(t('Código-fonte')) ?></a>
            </p>
        </section>

        <a class="btn btn-primary btn-block" href="<?= $logado ? '/ajuda' : '/login' ?>"><?= e($logado ? t('Voltar para a ajuda') : t('Voltar para entrar')) ?></a>
    </div>
</main>
</body>
</html>
