<?php
/** Aba Investimentos. Variáveis: $resumo (Investimentos::resumo). Cada parte mostra o saldo, de onde ele vem e os últimos lançamentos. */
$volta = rawurlencode('/investimentos');
$parte = static function (string $titulo, string $rotEntra, string $rotSai, string $acaoEntra, array $p) use ($volta): void { ?>
    <section class="card inv-parte" aria-label="<?= e($titulo) ?>">
        <h2 class="titulo"><?= e($titulo) ?></h2>
        <p class="inv-saldo num"><small><?= e(moedaSimbolo()) ?></small> <?= e(num($p['saldo'])) ?></p>
        <dl class="inv-conta num">
            <div><dt><?= e(t('Valor de partida')) ?></dt><dd><?= e(num($p['inicio'])) ?></dd></div>
            <div><dt><?= e($rotEntra) ?></dt><dd>+<?= e(num($p['entrou'])) ?></dd></div>
            <div><dt><?= e($rotSai) ?></dt><dd><?= $p['saiu'] > 0.004 ? MENOS : '' ?><?= e(num($p['saiu'])) ?></dd></div>
        </dl>
        <div class="inv-acoes">
            <?php if ($p['idEntra'] > 0): ?><a class="btn btn-ghost" href="/lancar?categoria=<?= (int) $p['idEntra'] ?>&amp;volta=<?= e($volta) ?>"><?= e($acaoEntra) ?></a><?php endif; ?>
            <?php if ($p['idSai'] > 0): ?><a class="btn btn-ghost" href="/lancar?categoria=<?= (int) $p['idSai'] ?>&amp;volta=<?= e($volta) ?>"><?= e(t('Resgatar')) ?></a><?php endif; ?>
        </div>
        <?php if ($p['movimentos'] === []): ?>
            <p class="inv-vazio"><?= e(t('Nenhum lançamento ainda.')) ?></p>
        <?php else: ?>
            <h3 class="inv-sub"><?= e(t('Últimos lançamentos')) ?></h3>
            <ul class="lista">
                <?php foreach ($p['movimentos'] as $r):
                    $cat = ['nome' => $r['categoria_nome'], 'apelido' => $r['apelido']];
                    $desc = descricaoTela(trim((string) $r['descricao']), $cat);
                    $sub = $desc !== '' && mb_strtolower($desc) !== mb_strtolower(nomeCurto($cat)) ? nomeCurto($cat) : ''; ?>
                    <li>
                        <a class="lista-i" href="/lancamentos/<?= (int) $r['id'] ?>/editar?volta=<?= e($volta) ?>">
                            <?= iconeCategoria((int) $r['categoria_id'], (string) $r['categoria_nome'], (string) $r['grupo'], 40) ?>
                            <span class="lista-n"><b><?= e($desc !== '' ? $desc : nomeCurto($cat)) ?></b><?php if ($sub !== ''): ?><small><?= e($sub) ?></small><?php endif; ?></span>
                            <span class="lista-v"><b class="num"><?= (int) $r['sinal'] === -1 ? '+' : MENOS ?><?= e(num((float) $r['valor'])) ?></b><small><?= e(diaMes((string) $r['data'])) ?></small></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
<?php };
?>
<div class="inv">
    <h1 class="pg-titulo"><?= e(t('Investimentos')) ?></h1>
    <section class="saldo is-acima inv-total" aria-labelledby="inv-total-t">
        <div class="saldo-l1"><h2 id="inv-total-t"><?= e(t('Guardado e investido')) ?></h2></div>
        <p class="saldo-v num"><small><?= e(moedaSimbolo()) ?></small><?= e(num($resumo['total'])) ?></p>
        <p class="saldo-prev"><?= e(t('Vem dos lançamentos do Extrato e do valor de partida em Ajustes. Rendimentos não entram.')) ?></p>
    </section>
    <div class="inv-partes">
        <?php $parte(t('Poupança'), t('Guardado'), t('Resgatado'), t('Guardar'), $resumo['poupanca']); ?>
        <?php $parte(t('Outros investimentos'), t('Aplicado'), t('Resgatado'), t('Aplicar'), $resumo['outros']); ?>
    </div>
</div>
