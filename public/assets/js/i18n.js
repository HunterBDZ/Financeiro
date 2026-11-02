// Tradução no navegador: T('texto em português', { n: 3 }). O dicionário vem de i18n-<idioma>.js, gerado por tools/idiomas/gerar.php.
// FIN: o formato de número, a moeda e a data curta, vindos do servidor nos atributos data-* deste <script>.
(function () {
    'use strict';
    window.T = function (texto, vars) {
        var saida = (window.FIN_DIC && window.FIN_DIC[texto]) || texto;
        Object.keys(vars || {}).forEach(function (k) { saida = saida.split('{' + k + '}').join(String(vars[k])); });
        return saida;
    };

    var cfg = (document.currentScript && document.currentScript.dataset) || {};
    var DEC = cfg.dec || ',';
    var MIL = cfg.mil || '.';
    var MESES = cfg.meses ? cfg.meses.split(',') : null;   // só em inglês: Nov 22
    var PRONTO = DEC === ',' ? /^(\d{1,3}(\.\d{3})+|\d+)(,\d{0,2})?$/ : /^(\d{1,3}(,\d{3})+|\d+)(\.\d{0,2})?$/;

    window.FIN = {
        moeda: cfg.moeda || 'BRL',
        simbolo: cfg.simbolo || 'R$',
        dec: DEC,
        // como num() do PHP. A base é sempre pt-BR, que agrupa desde o milhar (es não agrupa 1234); depois troca os separadores
        num: function (v, min, max) {
            min = min === undefined ? 2 : min;
            var s = Number(v).toLocaleString('pt-BR', { minimumFractionDigits: min, maximumFractionDigits: max === undefined ? min : max });
            return DEC === ',' ? s : s.replace(/[.,]/g, function (c) { return c === '.' ? MIL : DEC; });
        },
        // o valor digitado, com as mesmas regras de parseMoedaBr(); o que não dá para ler vale 0
        ler: function (s) {
            s = String(s === null || s === undefined ? '' : s).replace(/[^\d.,-]/g, '');
            var v = s.lastIndexOf(','), p = s.lastIndexOf('.');
            if (v >= 0 && p >= 0) {
                s = v > p ? s.replace(/\./g, '').replace(',', '.') : s.replace(/,/g, '');
            } else if (v >= 0) {
                s = DEC === '.' && /^-?[1-9]\d{0,2}(,\d{3})+$/.test(s) ? s.replace(/,/g, '') : s.replace(',', '.');
            } else if (p >= 0 && DEC === ',' && !/^-?\d+\.\d{1,2}$/.test(s)) {
                s = s.replace(/\./g, '');
            }
            var n = parseFloat(s);
            return isFinite(n) ? n : 0;
        },
        // o texto já é um valor completo, no formato do idioma?
        pronto: function (s) { return PRONTO.test(s); },
        // data curta: 22/11; em inglês, Nov 22. m de 0 a 11
        diaMes: function (d, m) {
            return MESES ? MESES[m] + ' ' + d : String(d).padStart(2, '0') + '/' + String(m + 1).padStart(2, '0');
        }
    };
})();
