<?php
/**
 * Primeira configuração, sem o layout: $passo (1 a 4), $total, $hoje, $inicio, $contas, $erro.
 * Só o 1º passo é obrigatório; o 3º vem antes das contas para a lista mostrar só o que se aplica.
 * As perguntas da parcela e da sobra só aparecem com o empréstimo marcado (CSS, sem JS).
 */
use Financeiro\Idioma;
use Financeiro\Site;
use Financeiro\SiteIcone;

$css = ['/assets/css/style.css', '/assets/css/lancar.css', '/assets/css/temas.css', '/assets/css/comecar.css'];
$diaIni = (int) substr($inicio, 8, 2);
$mesIni = mesExtenso($inicio);
$titulos = [1 => t('A partir de que dia quer controlar?'), 2 => t('Quanto entra por mês?'), 3 => t('O que você usa?'), 4 => t('Quais contas são fixas?')];
?>
<!doctype html>
<html lang="<?= e(Idioma::html()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#f4f5f7" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0a0b0f" media="(prefers-color-scheme: dark)">
<title><?= e(t('Primeira configuração')) ?> · financeiro</title>
<?= SiteIcone::tags() ?>
<script src="<?= e(asset('/assets/js/theme.js')) ?>"></script>
<script src="<?= e(asset('/assets/js/fuso.js')) ?>" defer></script>
<?php foreach ($css as $c): ?>
<link rel="stylesheet" href="<?= e(asset($c)) ?>">
<?php endforeach; ?>
</head>
<body class="cm-body">
<main class="cm">
    <div class="cm-topo">
        <span class="marca"><img src="<?= e(SiteIcone::url()) ?>" alt="" width="96" height="96"></span>
        <b translate="no">financeiro</b>
        <span class="cm-passo num"><?= e(t('Passo {n} de {total}', ['n' => $passo, 'total' => $total])) ?></span>
    </div>
    <div class="cm-barra" aria-hidden="true"><i style="--p:<?= (int) round($passo / $total * 100) ?>%"></i></div>

    <form class="card cm-card" method="post" action="/comecar">
        <?= csrfField() ?>
        <input type="hidden" name="passo" value="<?= (int) $passo ?>">
        <h1 class="cm-titulo"><?= e($titulos[$passo]) ?></h1>
        <?php if ($erro !== null): ?><p class="aviso aviso-error" role="alert"><?= e($erro) ?></p><?php endif; ?>

        <?php if ($passo === 1): ?>
            <p class="cm-ajuda"><?= e(t('Escolha o dia em que começa a controlar e informe quanto você tinha no início desse dia. O que aconteceu antes não precisa ser lançado.')) ?></p>
            <label class="cm-campo"><span><?= e(t('Moeda')) ?></span><select name="moeda"><?php foreach (Site::opcoesMoeda() as $cod => $rotulo): ?><option value="<?= e($cod) ?>"<?= $cod === $moeda ? ' selected' : '' ?>><?= e($rotulo) ?></option><?php endforeach; ?></select></label>
            <label class="cm-campo"><span><?= e(t('Fuso horário')) ?></span><select name="fuso" data-fuso<?= $fusoEscolhido ? '' : ' data-fuso-auto' ?>><?php foreach (Site::opcoesFuso() as $nome => $rotulo): ?><option value="<?= e($nome) ?>"<?= $nome === $fuso ? ' selected' : '' ?>><?= e($rotulo) ?></option><?php endforeach; ?></select></label>
            <label class="cm-campo"><span><?= e(t('Data de início')) ?></span><input type="date" name="data_inicio" value="<?= e($inicio) ?>" max="<?= e($hoje) ?>" required></label>
            <label class="cm-campo"><span><?= e(t('Saldo da conta')) ?></span><input type="text" inputmode="decimal" name="saldo_ini" placeholder="<?= e(numInput(0)) ?>" autocomplete="off"></label>
            <label class="cm-campo"><span><?= e(t('Poupança (se tiver)')) ?></span><input type="text" inputmode="decimal" name="poupanca_ini" placeholder="<?= e(numInput(0)) ?>" autocomplete="off"></label>
            <label class="cm-campo"><span><?= e(t('Outros investimentos (se tiver)')) ?></span><input type="text" inputmode="decimal" name="investimentos_ini" placeholder="<?= e(numInput(0)) ?>" autocomplete="off"></label>
        <?php elseif ($passo === 2): ?>
            <p class="cm-ajuda"><?= e(t('Usamos esse valor para estimar os próximos meses. Você pode mudar qualquer mês depois.')) ?></p>
            <label class="cm-campo"><span><?= e(t('Quanto entra por mês, em média')) ?></span><input type="text" inputmode="decimal" name="renda" placeholder="<?= e(numInput(0)) ?>" autocomplete="off"></label>
            <label class="cm-campo"><span><?= e(t('Dia em que recebe')) ?></span><input type="text" inputmode="numeric" name="dia" maxlength="2" autocomplete="off"></label>
            <?php if ($diaIni > 1): ?><p class="cm-nota"><?= e(t('Se esse dia for antes do dia {dia}, o valor não entra na previsão de {mes}, porque já está no seu saldo.', ['dia' => $diaIni, 'mes' => $mesIni])) ?></p><?php endif; ?>
        <?php elseif ($passo === 3): ?>
            <p class="cm-ajuda"><?= e(t('As abas do que você não usa ficam escondidas. Dá para mudar depois em Ajustes.')) ?></p>
            <label class="cm-marcar"><input type="checkbox" name="usa_cartao" value="1"<?= !empty($usos['cartao']) ? ' checked' : '' ?>><span><?= e(t('Tenho cartão de crédito')) ?></span></label>
            <label class="cm-marcar"><input type="checkbox" name="usa_emprestimo" value="1"<?= !empty($usos['emprestimo']) ? ' checked' : '' ?>><span><?= e(t('Tenho um empréstimo ou financiamento')) ?></span></label>
            <label class="cm-marcar"><input type="checkbox" name="tem_carro" value="1"<?= !empty($usos['carro']) ? ' checked' : '' ?>><span><?= e(t('Tenho carro')) ?></span></label>
            <fieldset class="cm-grupo cm-so-emp">
                <legend><?= e(t('Como a parcela é paga?')) ?></legend>
                <label class="cm-marcar"><input type="radio" name="parcela_modo" value="2"<?= ($usos['parcela'] ?? 2) === 2 ? ' checked' : '' ?>><span><?= e(t('Pago à parte, com o dinheiro da conta')) ?></span></label>
                <label class="cm-marcar"><input type="radio" name="parcela_modo" value="1"<?= ($usos['parcela'] ?? 2) === 1 ? ' checked' : '' ?>><span><?= e(t('A parcela já sai direto da renda, antes de cair na conta')) ?></span></label>
            </fieldset>
            <fieldset class="cm-grupo cm-so-emp">
                <legend><?= e(t('O que fazer com o que sobrar?')) ?></legend>
                <label class="cm-marcar"><input type="radio" name="objetivo" value="3"<?= ($usos['objetivo'] ?? 3) === 3 ? ' checked' : '' ?>><span><?= e(t('Deixar na conta')) ?></span></label>
                <label class="cm-marcar"><input type="radio" name="objetivo" value="1"<?= ($usos['objetivo'] ?? 3) === 1 ? ' checked' : '' ?>><span><?= e(t('Usar para adiantar o empréstimo')) ?></span></label>
            </fieldset>
        <?php else: ?>
            <p class="cm-ajuda"><?= e(t('Preencha só as que você tem. O vencimento é opcional e faz o app avisar quando estiver perto.')) ?></p>
            <?php if ($diaIni > 1): ?><p class="cm-nota"><?= e(t('Contas que vencem antes do dia {dia} não entram na previsão de {mes}.', ['dia' => $diaIni, 'mes' => $mesIni])) ?></p><?php endif; ?>
            <div class="cm-contas">
                <span class="cm-contas-c" aria-hidden="true"></span><span class="cm-contas-c" aria-hidden="true"><?= e(t('Valor')) ?></span><span class="cm-contas-c" aria-hidden="true"><?= e(t('Dia')) ?></span>
                <?php foreach ($contas as $c): ?>
                    <span class="cm-conta-n"><?= e($c['nome']) ?></span>
                    <input type="text" inputmode="decimal" name="valor[<?= (int) $c['id'] ?>]" placeholder="<?= e(numInput(0)) ?>" autocomplete="off" aria-label="<?= e(t('Valor de {nome}', ['nome' => $c['nome']])) ?>">
                    <input type="text" inputmode="numeric" maxlength="2" name="dia[<?= (int) $c['id'] ?>]" autocomplete="off" aria-label="<?= e(t('Dia de vencimento de {nome}', ['nome' => $c['nome']])) ?>">
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="cm-acoes">
            <?php if ($passo > 1): ?><a class="btn btn-ghost" href="/comecar?passo=<?= (int) $passo - 1 ?>"><?= e(t('Voltar')) ?></a><?php endif; ?>
            <button type="submit" class="btn btn-primary"><?= e($passo === $total ? t('Concluir') : t('Continuar')) ?></button>
        </div>
        <?php if ($passo === 2): ?><a class="cm-pular" href="/comecar?passo=<?= (int) $passo + 1 ?>"><?= e(t('Pular este passo')) ?></a><?php endif; ?>
        <?php if ($passo === 4): ?><button type="submit" name="pular" value="1" class="cm-pular"><?= e(t('Pular este passo')) ?></button><?php endif; ?>
    </form>
    <?php if ($passo === 1): ?><?php require __DIR__ . '/../partials/idioma_escolha.php'; ?><?php endif; ?>
</main>
</body>
</html>
