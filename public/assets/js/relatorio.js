// Aba Relatório: escolher o período já abre o relatório, sem precisar do botão Ver.
(function () {
    'use strict';
    var form = document.querySelector('[data-rl-periodo]');
    var sel = form && form.querySelector('select');
    if (!sel) { return; }
    form.classList.add('com-js');
    sel.addEventListener('change', function () { form.submit(); });
})();
