<?php
/**
 * /lancar (P4, SPEC §2.2): o mesmo partial do diálogo, numa página. Fallback sem JS do "+",
 * dos PAGAR, e a volta de erros e duplicados (preenchida, com o aviso em linha).
 * Variáveis: $form (dados do partial).
 */
use Financeiro\View;
?>
<div class="lf-pagina">
    <?php View::partial('lancamentos/_form', $form); ?>
</div>
