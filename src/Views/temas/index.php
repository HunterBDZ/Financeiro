<?php
/**
 * Tela Temas: cada cartão mostra as cores do tema. Tocar mostra o tema no app inteiro, e só fica ao salvar (temas.js);
 * sem JS, tocar já salva. Variáveis: $temas (Temas::todos), $atual (id ou null = automático).
 */
$csrf = csrfField();
$cartao = static function (string $valor, string $nome, string $sub, string $amostra, bool $on, string $attrs) use ($csrf): string {
    return '<form method="post" action="/temas" data-tm-form>' . $csrf . '<input type="hidden" name="tema" value="' . e($valor) . '">'
        . '<button class="tm' . ($on ? ' is-on' : '') . '" type="submit"' . ($on ? ' aria-current="true"' : '') . ' data-tm-nome="' . e($nome) . '"' . $attrs . '>'
        . $amostra . '<span class="tm-n"><b>' . e($nome) . ($on ? ' ' . svgIcon('check', 15) : '') . '</b><small>' . e($sub) . '</small></span></button></form>';
};
$grupos = [];
foreach ($temas as $id => $t) {
    $grupos[$t['grupo']][$id] = $t;
}
?>
<div class="tms">
    <header class="tms-topo">
        <h1>Temas</h1>
        <p>Toque num tema para ver no app inteiro. Ele só fica quando você salvar, e vale para a sua conta em qualquer aparelho.</p>
    </header>
    <section aria-labelledby="tm-g-auto">
        <h2 class="tm-grupo" id="tm-g-auto">Automático</h2>
        <div class="tm-grade">
            <?php
            $claro = $temas['claro'];
            $escuro = $temas['escuro'];
            $metade = static fn(array $t): string => '<span class="tm-am" style="--c-bg:' . e($t['bg']) . ';--c-cofre:' . e($t['cofre']) . ';--c-ct:' . e($t['ct']) . ';--c-acc:' . e($t['acc']) . ';--c-pos:' . e($t['pos']) . ';--c-neg:' . e($t['neg']) . '"><span class="tm-cofre">R$ 6.109</span><span class="tm-l"><i class="tm-b"></i><i class="tm-d is-pos"></i><i class="tm-d is-neg"></i></span></span>';
            echo $cartao('auto', 'Automático', 'segue o aparelho', '<span class="tm-par">' . $metade($claro) . $metade($escuro) . '</span>', $atual === null, ' data-tm-auto');
            ?>
        </div>
    </section>
    <?php foreach ($grupos as $grupo => $lista): ?>
        <section aria-label="<?= e((string) $grupo) ?>">
            <h2 class="tm-grupo"><?= e((string) $grupo) ?></h2>
            <div class="tm-grade">
                <?php foreach ($lista as $id => $t):
                    $fam = $t['mono'] ? ";--c-fam:'JetBrains Mono',ui-monospace,monospace" : '';
                    $raio = $t['reto'] ? ';--c-r:0' : '';
                    $am = '<span class="tm-am" style="--c-bg:' . e($t['bg']) . ';--c-cofre:' . e($t['cofre']) . ';--c-ct:' . e($t['ct']) . ';--c-acc:' . e($t['acc']) . ';--c-pos:' . e($t['pos']) . ';--c-neg:' . e($t['neg']) . $fam . $raio . '"><span class="tm-cofre">R$ 6.109</span><span class="tm-l"><i class="tm-b"></i><i class="tm-d is-pos"></i><i class="tm-d is-neg"></i></span></span>';
                    echo $cartao((string) $id, (string) $t['nome'], $t['modo'] === 'dark' ? 'escuro' : 'claro', $am, $atual === $id, ' data-tm-id="' . e((string) $id) . '" data-tm-modo="' . e((string) $t['modo']) . '" data-tm-bg="' . e((string) $t['bg']) . '"');
                endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
    <div class="tm-barra" data-tm-barra hidden role="status">
        <span class="tm-barra-n" data-tm-barra-n></span>
        <button class="btn btn-ghost btn-sm" type="button" data-tm-voltar>Voltar</button>
        <button class="btn btn-primary btn-sm" type="button" data-tm-salvar>Salvar tema</button>
    </div>
</div>
