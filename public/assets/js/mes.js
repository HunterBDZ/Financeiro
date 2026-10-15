// mes.js: comportamento da aba Mês. Sem nada inline (CSP script-src 'self'); sem JS tudo funciona.
// - abrir uma linha (details.mes-ed) fecha as outras e põe o cursor no valor, já selecionado;
// - a fita de meses ([data-mes-fita]) rola até o mês aberto, sem mexer na rolagem da página.
(function () {
    'use strict';

    document.addEventListener('toggle', function (ev) {
        var d = ev.target;
        if (!(d instanceof Element) || !d.matches('details.mes-ed, details.mes-incluir') || !d.open) {
            return;
        }
        var abertos = document.querySelectorAll('details.mes-ed[open], details.mes-incluir[open]');
        for (var i = 0; i < abertos.length; i++) {
            if (abertos[i] !== d) {
                abertos[i].removeAttribute('open');
            }
        }
        var campo = d.querySelector('[data-mes-valor], select');
        if (campo && typeof campo.focus === 'function') {
            campo.focus({ preventScroll: true });
            if (typeof campo.select === 'function') {
                campo.select();
            }
        }
    }, true);

    function centralizarFita() {
        var fita = document.querySelector('[data-mes-fita]');
        var atual = fita ? fita.querySelector('.is-on') : null;
        if (!fita || !atual) {
            return;
        }
        // scrollLeft direto: scrollIntoView também rolaria a página (e perderia o #cat-… do redirect)
        fita.scrollLeft = Math.max(0, atual.offsetLeft - (fita.clientWidth - atual.offsetWidth) / 2);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', centralizarFita);
    } else {
        centralizarFita();
    }
})();
