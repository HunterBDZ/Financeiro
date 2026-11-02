// O fuso do site: na primeira configuração, o select já vem com o do navegador (e a data de hoje, nesse fuso);
// em Ajustes, só avisa quando o do navegador é outro. Arquivo externo (CSP script-src 'self'); nada muda sem esses elementos.
(function () {
    'use strict';
    var sel = document.querySelector('[data-fuso]');
    var zona = '';
    try { zona = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) { zona = ''; }
    if (!sel || !zona) {
        return;
    }
    // alguns navegadores ainda mandam nomes antigos (Asia/Calcutta): vale um da lista, no mesmo continente e com o mesmo deslocamento
    function opcao() {
        var ops = Array.prototype.slice.call(sel.options);
        var exata = ops.filter(function (o) { return o.value === zona; })[0];
        if (exata) { return exata; }
        var min = -new Date().getTimezoneOffset();
        var fim = '(UTC' + (min < 0 ? '-' : '+') + String(Math.floor(Math.abs(min) / 60)).padStart(2, '0') + ':' + String(Math.abs(min) % 60).padStart(2, '0') + ')';
        var iguais = ops.filter(function (o) { return o.text.slice(-fim.length) === fim; });
        return iguais.filter(function (o) { return o.value.indexOf(zona.split('/')[0] + '/') === 0; })[0] || iguais[0] || null;
    }
    var achada = opcao();
    if (!achada) {
        return;
    }
    if (sel.hasAttribute('data-fuso-auto')) {
        sel.value = achada.value;
        var d = new Date();
        var hoje = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        var data = document.querySelector('input[name="data_inicio"]');
        if (data && data.value === data.max) { data.value = hoje; }
        if (data) { data.max = hoje; }
        return;
    }
    var dica = document.querySelector('[data-fuso-dica]');
    var usar = document.querySelector('[data-fuso-usar]');
    if (dica && usar && sel.value !== achada.value) {
        dica.querySelector('[data-fuso-nome]').textContent = achada.value.replace(/_/g, ' ');
        dica.hidden = false;
        usar.addEventListener('click', function () { sel.value = achada.value; dica.hidden = true; });
    }
})();
