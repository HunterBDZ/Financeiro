// Tradução no navegador: T('texto em português', { n: 3 }). O dicionário vem de i18n-<idioma>.js, gerado por tools/idiomas/gerar.php.
(function () {
    'use strict';
    window.T = function (texto, vars) {
        var saida = (window.FIN_DIC && window.FIN_DIC[texto]) || texto;
        Object.keys(vars || {}).forEach(function (k) { saida = saida.split('{' + k + '}').join(String(vars[k])); });
        return saida;
    };
})();
