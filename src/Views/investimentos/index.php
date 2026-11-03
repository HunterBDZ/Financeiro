<?php
/**
 * Aba Investimentos, na grade: o total guardado e investido é a resposta (o número grande); embaixo, a tabela de
 * posições (valor de partida, o que entrou, o que saiu e o saldo de cada uma, com o total sob o fio duplo) e os
 * últimos lançamentos de cada posição. Os valores são do ponto de vista do investimento: o que entrou nele tem "+",
 * o que saiu tem "−", sem cor (a cor do dinheiro que sai da conta fica no Extrato). Sem nenhum valor, o vazio diz
 * para que a tela serve e qual lançamento a preenche. Variáveis: $resumo (Investimentos::resumo).
 */
$volta = rawurlencode('/investimentos');
$posicoes = [
    ['nome' => t('Poupança'), 'entra' => t('Guardar'), 'p' => $resumo['poupanca']],
    ['nome' => t('Outros investimentos'), 'entra' => t('Aplicar'), 'p' => $resumo['outros']],
];
$vazio = abs((float) $resumo['total']) < 0.005 && $resumo['poupanca']['movimentos'] === [] && $resumo['outros']['movimentos'] === [];
$sinal = static fn(float $v, string $s): string => $v > 0.004 ? '<span class="inv-sinal">' . $s . '</span>' . valor($v) : valor(0.0, 'previsto');
$lancar = static fn(int $cat): string => '/lancar?categoria=' . $cat . '&volta=' . $volta;
?>
<div class="grade inv">
    <?php \Financeiro\View::partial('partials/topo', ['titulo' => t('Investimentos'), 'apoio' => t('Pelo que você lançou e pelo valor de partida. Rendimentos não entram.')]); ?>

    <?php if ($vazio): ?>
        <section class="faixa-grade inv-total inv-vazio" aria-labelledby="inv-total-t">
            <h2 class="titulo" id="inv-total-t"><?= e(t('O que você guarda e investe aparece aqui')) ?></h2>
            <p><?= e(t('Cada vez que você guardar na poupança ou aplicar num investimento, lance o valor: ele sai da conta e entra aqui, na posição certa.')) ?></p>
            <?php if ($resumo['poupanca']['idEntra'] > 0): ?>
                <p><a class="btn btn-principal" href="<?= e($lancar((int) $resumo['poupanca']['idEntra'])) ?>"><?= e(t('Lançar o primeiro valor guardado')) ?></a></p>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <section class="faixa-grade inv-total" aria-labelledby="inv-total-t">
            <h2 class="rot-resposta" id="inv-total-t"><?= e(t('Guardado e investido')) ?></h2>
            <p class="resposta-num"><?= valor((float) $resumo['total'], 'neutro', ['moeda' => true]) ?></p>
        </section>

        <section class="faixa-grade" aria-labelledby="inv-pos-t">
            <div class="secao-cab"><h2 id="inv-pos-t"><?= e(t('Posições')) ?></h2></div>
            <table class="livro inv-tabela">
                <thead>
                    <tr>
                        <th scope="col"><?= e(t('Posição')) ?></th>
                        <th scope="col" class="c-prev"><?= e(t('Valor de partida')) ?></th>
                        <th scope="col" class="c-prev"><?= e(t('Entrou')) ?></th>
                        <th scope="col" class="c-prev"><?= e(t('Saiu')) ?></th>
                        <th scope="col" class="c-real"><?= e(t('Saldo')) ?></th>
                        <th scope="col" class="situacao"><span class="sr-only"><?= e(t('Ações')) ?></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($posicoes as $pos): $p = $pos['p']; ?>
                        <tr>
                            <th scope="row" class="inv-nome"><?= e($pos['nome']) ?></th>
                            <td class="c-prev"><?= valor((float) $p['inicio']) ?></td>
                            <td class="c-prev"><?= $sinal((float) $p['entrou'], '+') ?></td>
                            <td class="c-prev"><?= $sinal((float) $p['saiu'], MENOS) ?></td>
                            <td class="c-real"><?= valor((float) $p['saldo']) ?></td>
                            <td class="situacao inv-acoes">
                                <?php if ($p['idEntra'] > 0): ?><a class="btn btn-contorno btn-sm" href="<?= e($lancar((int) $p['idEntra'])) ?>"><?= e($pos['entra']) ?></a><?php endif; ?>
                                <?php if ($p['idSai'] > 0): ?><a class="btn-link" href="<?= e($lancar((int) $p['idSai'])) ?>"><?= e(t('Resgatar')) ?></a><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="total-final">
                        <td><?= e(t('Total')) ?></td>
                        <td class="c-prev"></td><td class="c-prev"></td><td class="c-prev"></td>
                        <td class="c-real"><?= valor((float) $resumo['total']) ?></td>
                        <td class="situacao"></td>
                    </tr>
                </tfoot>
            </table>
        </section>

        <section class="faixa-grade" aria-label="<?= e(t('Últimos lançamentos')) ?>">
            <h2 class="inv-ult-t"><?= e(t('Últimos lançamentos')) ?></h2>
            <div class="duas inv-duas">
                <?php foreach ($posicoes as $pos): $p = $pos['p']; ?>
                    <section aria-labelledby="inv-ult-<?= e((string) $p['idEntra']) ?>">
                        <div class="secao-cab"><h3 id="inv-ult-<?= e((string) $p['idEntra']) ?>"><?= e($pos['nome']) ?></h3></div>
                        <?php if ($p['movimentos'] === []): ?>
                            <p class="inv-nada"><?= e(t('Nada lançado ainda. Use {acao} quando guardar um valor.', ['acao' => $pos['entra']])) ?></p>
                        <?php else: ?>
                            <ul>
                                <?php foreach ($p['movimentos'] as $r):
                                    $cat = ['nome' => $r['categoria_nome'], 'apelido' => $r['apelido']];
                                    $desc = descricaoTela(trim((string) $r['descricao']), $cat); ?>
                                    <li>
                                        <a class="linha-lista" href="/lancamentos/<?= (int) $r['id'] ?>/editar?volta=<?= e($volta) ?>">
                                            <span class="linha-ic"><?= iconeCategoria((int) $r['categoria_id'], (string) $r['categoria_nome'], (string) $r['grupo'], 20) ?></span>
                                            <span class="linha-nome"><b><?= e($desc !== '' ? $desc : nomeCurto($cat)) ?></b><?php if ($desc !== '' && mb_strtolower($desc) !== mb_strtolower(nomeCurto($cat))): ?><small><?= e(nomeCurto($cat)) ?></small><?php endif; ?></span>
                                            <span class="inv-v"><span class="num"><?= (int) $r['sinal'] === -1 ? '+' : MENOS ?><?= valor((float) $r['valor']) ?></span><small class="num"><?= e(diaMes((string) $r['data'])) ?></small></span>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</div>
