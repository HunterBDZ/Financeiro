<?php
/**
 * O topo da página: o título é o contexto ("Novembro de 2026"), sem saudação; a linha de apoio embaixo
 * ("Domingo, dia 22 de 30"); à direita, o seletor de mês (partials/seletor_mes) e as ações da tela.
 * É a primeira faixa da grade:
 *   <div class="grade">
 *       <?php View::partial('partials/topo', ['mes' => $mes, 'meses' => $meses, 'href' => '/mes?mes=', 'apoio' => $linha]); ?>
 *       <section class="faixa-grade">...</section>
 *   </div>
 * Variáveis (todas opcionais, menos o título ou o mês):
 *   $titulo  texto do h1; sem ele e com $mes, "{Mês} de {ano}"
 *   $apoio   a linha de baixo, texto curto
 *   $mes, $meses, $href, $sufixo, $hoje, $anual   o seletor de mês (só aparece com $meses)
 *   $acoes   HTML pronto à direita (um botão, um link), depois do seletor
 *   $idTitulo  id do h1, para aria-labelledby
 */
$mes = isset($mes) ? (string) $mes : null;
$titulo = (string) ($titulo ?? ($mes !== null ? t('{mes} de {ano}', ['mes' => mb_convert_case(mesExtenso($mes), MB_CASE_TITLE), 'ano' => substr($mes, 0, 4)]) : ''));
$apoio = (string) ($apoio ?? '');
$acoes = (string) ($acoes ?? '');
$comSeletor = $mes !== null && !empty($meses);
?>
<header class="topo faixa-grade">
    <div class="topo-txt">
        <h1<?= !empty($idTitulo) ? ' id="' . e((string) $idTitulo) . '"' : '' ?>><?= e($titulo) ?></h1>
        <?php if ($apoio !== ''): ?><p class="topo-sub"><?= e($apoio) ?></p><?php endif; ?>
    </div>
    <?php if ($comSeletor || $acoes !== ''): ?>
        <div class="topo-dir">
            <?php if ($comSeletor) {
                \Financeiro\View::partial('partials/seletor_mes', ['mes' => $mes, 'meses' => $meses, 'href' => $href ?? '?mes=', 'sufixo' => $sufixo ?? '', 'hoje' => $hoje ?? null, 'anual' => $anual ?? false]);
            } ?>
            <?= $acoes ?>
        </div>
    <?php endif; ?>
</header>
