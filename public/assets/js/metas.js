// metas.js: na aba Metas, abrir uma linha (details.mt-linha) fecha as outras; no computador, põe o cursor no valor,
// já selecionado (no celular não: o teclado abriria sozinho em quem só queria olhar). Sem nada inline (CSP
// script-src 'self'); sem JS tudo funciona. O "Até" que só oferece meses depois do "A partir de" é do app.js.
(function () {
    'use strict';
    if (!document.querySelector('details.mt-linha')) {
        return;
    }
    document.addEventListener('toggle', function (ev) {
        var d = ev.target;
        if (!(d instanceof Element) || !d.matches('details.mt-linha, details.mt-incluir') || !d.open) {
            return;
        }
        var abertos = document.querySelectorAll('details.mt-linha[open], details.mt-incluir[open]');
        for (var i = 0; i < abertos.length; i++) {
            if (abertos[i] !== d) {
                abertos[i].removeAttribute('open');
            }
        }
        var campo = window.matchMedia('(max-width: 1023px)').matches ? null : d.querySelector('[data-mes-valor], input[name="nome"]');
        if (campo && typeof campo.focus === 'function') {
            campo.focus({ preventScroll: true });
            if (typeof campo.select === 'function') {
                campo.select();
            }
        }
    }, true);
})();
