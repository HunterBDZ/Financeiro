<?php
/**
 * Aba Wishlist, na grade: a única tela onde cartão faz sentido, porque cada item é um objeto. O 1º da lista fica em
 * destaque, com a foto quadrada grande e a previsão de quando comprar; os outros vêm numa grade de itens (foto
 * quadrada, nome, preço e o lugar na fila), sem sombra, com fio ao passar o mouse. A ordem decide quem é o próximo;
 * reordenar é por botão, sem arrastar. Vazio: "Cole o link de algo que você quer comprar", com o campo ali mesmo.
 * Fora de real, o valor original vem primeiro e a conversão do dia logo abaixo.
 * Variáveis: $itens (com valor_site), $comprados, $prev, $mes, $parcelas, $form, $taxas.
 */
use Financeiro\Services\Cotacao;
use Financeiro\Services\LinkProduto;
use Financeiro\Site;
use Financeiro\View;

$csrf = csrfField();
$foto = static function (array $i, int $tam): string {
    if ($i['foto_hash'] === null) {
        return '<span class="wl-foto-vazia" aria-hidden="true">' . svgIcon('gift', $tam) . '</span>';
    }
    return '<img src="/wishlist/' . (int) $i['id'] . '/foto?v=' . e(substr((string) $i['foto_hash'], 0, 12)) . '" alt="" loading="lazy" width="' . (int) $i['foto_largura'] . '" height="' . (int) $i['foto_altura'] . '">';
};
$lojaLink = static function (array $i): string {
    $loja = LinkProduto::loja($i['url']);
    return $loja === '' ? '' : '<a class="wl-loja" href="' . e((string) $i['url']) . '" target="_blank" rel="noopener noreferrer nofollow">' . e($loja) . svgIcon('externo', 13) . '<span class="sr-only"> ' . e(t('(abre em outra aba)')) . '</span></a>';
};
$mover = static function (int $id, string $para, string $rotulo, string $icone, bool $ativo) use ($csrf): string {
    return '<form method="post" action="/wishlist/' . $id . '/mover">' . $csrf . '<input type="hidden" name="para" value="' . e($para) . '"><button class="wl-mover" type="submit" title="' . e($rotulo) . '" aria-label="' . e($rotulo) . '"' . ($ativo ? '' : ' disabled') . '>' . svgIcon($icone, 18) . '</button></form>';
};
// "≈ R$ 1.627,00 hoje", com a data da cotação; sem cotação, avisa
$conversao = static function (array $i) use ($taxas): string {
    if ($i['valor'] === null || $i['moeda'] === Site::moeda()) {
        return '';
    }
    if ($i['valor_site'] === null) {
        return t('Sem cotação agora');
    }
    return t('{valor}, cotação de {data}', ['valor' => '≈' . NBSP . money((float) $i['valor_site']), 'data' => diaMes(Cotacao::dataDe((string) $i['moeda'], $taxas))]);
};
$preco = static fn(array $i): string => $i['valor'] !== null ? Cotacao::formatar((float) $i['valor'], (string) $i['moeda']) : t('Sem valor');
$n = count($itens);
$primeiro = $itens[0] ?? null;
$resto = array_slice($itens, 1);
$abrirNovo = $itens === [] || !empty($form);
?>
<div class="grade wl">
    <?php \Financeiro\View::partial('partials/topo', ['titulo' => t('Wishlist'), 'apoio' => \Financeiro\Controllers\WishlistController::resumo($itens)]); ?>

    <?php if ($itens === []): ?>
        <section class="faixa-grade wl-vazio" aria-labelledby="wl-vazio-t">
            <h2 class="titulo" id="wl-vazio-t"><?= e(t('Cole o link de algo que você quer comprar')) ?></h2>
            <p><?= e(t('O MyMonetto lê o nome, o preço e a foto, e diz em que mês a compra cabe sem passar da reserva.')) ?></p>
            <div class="wl-novo-form" id="wl-novo"><?php View::partial('wishlist/_form', ['item' => null, 'form' => $form, 'taxas' => $taxas]); ?></div>
        </section>
    <?php else: ?>
        <section class="faixa-grade wl-faixa-novo">
            <details class="wl-novo" id="wl-novo"<?= $abrirNovo ? ' open' : '' ?>>
                <summary class="btn btn-contorno"><?= svgIcon('plus', 16) ?><?= e(t('Adicionar item')) ?></summary>
                <?php View::partial('wishlist/_form', ['item' => null, 'form' => $form, 'taxas' => $taxas]); ?>
            </details>
        </section>
    <?php endif; ?>

    <?php if ($primeiro !== null): ?>
        <section class="faixa-grade wl-vitrine" id="wl-item-<?= (int) $primeiro['id'] ?>" aria-labelledby="wl-1-t">
            <div class="wl-vitrine-foto">
                <?= $foto($primeiro, 48) ?>
                <span class="wl-rank num" aria-label="<?= e(t('1º da lista')) ?>">1</span>
            </div>
            <div class="wl-vitrine-info">
                <p class="rot-resposta"><?= e(t('O próximo da lista')) ?></p>
                <h2 id="wl-1-t"><a href="/wishlist/<?= (int) $primeiro['id'] ?>/editar"><?= e((string) $primeiro['nome']) ?></a></h2>
                <?= $lojaLink($primeiro) ?>
                <p class="wl-preco num"><?= e($preco($primeiro)) ?></p>
                <?php if ($conversao($primeiro) !== ''): ?><p class="wl-conv num"><?= e($conversao($primeiro)) ?></p><p class="wl-conv-nota"><?= e(t('Cotação comercial. IOF e taxas do cartão não entram.')) ?></p><?php endif; ?>
                <div class="wl-acoes">
                    <form method="post" action="/wishlist/<?= (int) $primeiro['id'] ?>/comprei"><?= $csrf ?><button class="btn btn-contorno btn-sm" type="submit"><?= e(t('Comprei')) ?></button></form>
                    <a class="btn-link" href="/wishlist/<?= (int) $primeiro['id'] ?>/editar"><?= e(t('Editar')) ?></a>
                </div>
                <div id="wl-previsao"><?php View::partial('wishlist/_previsao', ['itens' => $itens, 'prev' => $prev, 'mes' => $mes, 'parcelas' => $parcelas]); ?></div>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($resto !== []): ?>
        <section class="faixa-grade" aria-labelledby="wl-depois-t">
            <div class="secao-cab"><h2 id="wl-depois-t"><?= e(t('Depois')) ?></h2><p><?= e(t('Na ordem da fila')) ?></p></div>
            <ol class="wl-lista" start="2">
                <?php foreach ($resto as $k => $i): $id = (int) $i['id']; $pos = $k + 2; ?>
                    <li class="wl-item" id="wl-item-<?= $id ?>">
                        <a class="wl-item-foto" href="/wishlist/<?= $id ?>/editar" tabindex="-1" aria-hidden="true"><?= $foto($i, 32) ?><span class="wl-num num"><?= $pos ?></span></a>
                        <div class="wl-item-txt">
                            <a class="wl-item-nome" href="/wishlist/<?= $id ?>/editar"><?= e((string) $i['nome']) ?></a>
                            <p class="wl-item-preco num"><?= e($preco($i)) ?></p>
                            <?php if ($conversao($i) !== ''): ?><p class="wl-conv num"><?= e($conversao($i)) ?></p><?php endif; ?>
                            <p class="wl-quando"><?= e(t('Depois do {n}º da lista', ['n' => $pos - 1])) ?></p>
                            <?= $lojaLink($i) ?>
                        </div>
                        <div class="wl-item-mover">
                            <?= $mover($id, 'primeiro', t('Colocar {nome} em 1º', ['nome' => $i['nome']]), 'topo', true) ?>
                            <?= $mover($id, 'subir', t('Subir {nome}', ['nome' => $i['nome']]), 'arrow-up', true) ?>
                            <?= $mover($id, 'descer', t('Descer {nome}', ['nome' => $i['nome']]), 'arrow-down', $pos < $n) ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>
    <?php endif; ?>

    <?php if ($comprados !== []): ?>
        <section class="faixa-grade">
        <details class="dobra wl-comprados">
            <summary><?= e(t('Compradas ({n})', ['n' => count($comprados)])) ?></summary>
            <ul>
                <?php foreach ($comprados as $c): ?>
                    <li>
                        <span class="wl-comprado-foto"><?= $foto($c, 20) ?></span>
                        <span class="wl-comprado-txt"><b><?= e((string) $c['nome']) ?></b><small class="num"><?= e(diaMesAno((string) $c['comprado_em'])) ?><?= $c['valor'] !== null ? ', ' . e(Cotacao::formatar((float) $c['valor'], (string) $c['moeda'])) : '' ?></small></span>
                        <form method="post" action="/wishlist/<?= (int) $c['id'] ?>/devolver"><?= $csrf ?><button class="btn-link" type="submit"><?= e(t('Voltar para a lista')) ?></button></form>
                    </li>
                <?php endforeach; ?>
            </ul>
        </details>
        </section>
    <?php endif; ?>
</div>
