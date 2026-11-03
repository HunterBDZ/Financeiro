// entrar.js — as páginas de fora do app (auth/_casca.php): o olho da senha e a prévia do começo guiado.
(function () {
    'use strict';
    // ---- [data-mostrar-senha]: o olho mostra e esconde o que foi digitado. Sem JS o botão nem aparece. Os rótulos vêm
    // no HTML (data-rotulo-*), já no idioma da página: aqui não há o i18n.js
    document.querySelectorAll('[data-mostrar-senha]').forEach(function (b) {
        var campo = document.getElementById(b.getAttribute('data-mostrar-senha'));
        if (!campo) { return; }
        b.hidden = false;
        b.addEventListener('click', function () {
            var mostrando = campo.type === 'text';
            campo.type = mostrando ? 'password' : 'text';
            b.setAttribute('aria-pressed', mostrando ? 'false' : 'true');
            b.setAttribute('aria-label', b.getAttribute(mostrando ? 'data-rotulo-mostrar' : 'data-rotulo-esconder') || '');
        });
    });
})();
