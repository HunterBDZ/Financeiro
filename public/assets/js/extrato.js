// extrato.js: a tecla "/" põe o cursor na busca do Extrato (o atalho que a tecla desenhada mostra). Não age quando a
// pessoa já está digitando num campo, com uma tecla de comando junto, ou com um diálogo aberto. Sem nada inline (CSP
// script-src 'self'); não faz nada nas páginas sem a busca do Extrato. O filtro em si (categoria, período) é do lancar.js.
(function () {
    'use strict';
    var busca = document.querySelector('[data-ext-busca]');
    if (!busca) {
        return;
    }
    document.addEventListener('keydown', function (ev) {
        if (ev.key !== '/' || ev.ctrlKey || ev.metaKey || ev.altKey || ev.defaultPrevented) {
            return;
        }
        var alvo = ev.target;
        if (alvo instanceof Element && alvo.closest('input, textarea, select, [contenteditable="true"], dialog[open]')) {
            return;
        }
        if (document.querySelector('dialog[open]')) {
            return;
        }
        ev.preventDefault();
        busca.focus();
        busca.select();
    });
})();

// a categoria do filtro busca na hora, como o período (o lancar.js troca o valor por f/cat no envio)
(function () {
    'use strict';
    var sel = document.getElementById('ex-cat-sel');
    if (!sel || !sel.form) {
        return;
    }
    sel.addEventListener('change', function () {
        if (typeof sel.form.requestSubmit === 'function') { sel.form.requestSubmit(); } else { sel.form.submit(); }
    });
})();
