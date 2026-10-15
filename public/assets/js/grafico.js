// grafico.js — Início: anel duplo. Passar o mouse (ou foco) numa linha da composição destaca o
// segmento e mostra o valor dele no centro; números principais contam a partir de zero.
// Sem nada inline (CSP script-src 'self'). Sai sem fazer nada fora do Início.
(function () {
    'use strict';

    var anel = document.querySelector('[data-dash-anel]');
    if (!anel) {
        return;
    }
    var svg = anel.querySelector('svg');
    var val = anel.querySelector('[data-dash-val]');
    var lbl = anel.querySelector('[data-dash-lbl]');
    var base = { val: val.textContent, lbl: lbl.textContent, cls: val.className };
    var linhas = document.querySelectorAll('.dash-bd-row[data-seg]');
    var segs = svg.querySelectorAll('.dash-seg[data-seg]');
    var reduzir = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function destacar(seg) {
        svg.classList.toggle('is-dim', !!seg);
        segs.forEach(function (c) { c.classList.toggle('is-hot', c.getAttribute('data-seg') === seg); });
        linhas.forEach(function (r) { r.classList.toggle('is-hot', r.getAttribute('data-seg') === seg); });
        var linha = seg ? document.querySelector('.dash-bd-row[data-seg="' + seg + '"]') : null;
        if (!linha) {
            val.textContent = base.val;
            val.className = base.cls;
            val.style.color = '';
            lbl.textContent = base.lbl;
            return;
        }
        val.textContent = linha.getAttribute('data-valor');
        val.className = 'dash-rc-val num';
        val.style.color = linha.getAttribute('data-cor');
        lbl.textContent = linha.getAttribute('data-nome');
    }

    linhas.forEach(function (r) {
        var seg = r.getAttribute('data-seg');
        r.addEventListener('mouseenter', function () { destacar(seg); });
        r.addEventListener('mouseleave', function () { destacar(null); });
        r.addEventListener('focus', function () { destacar(seg); });
        r.addEventListener('blur', function () { destacar(null); });
    });
    segs.forEach(function (c) {
        var seg = c.getAttribute('data-seg');
        c.addEventListener('mouseenter', function () { destacar(seg); });
        c.addEventListener('mouseleave', function () { destacar(null); });
    });
    // celular: toque numa linha alterna o destaque
    document.addEventListener('click', function (ev) {
        var r = ev.target instanceof Element ? ev.target.closest('.dash-bd-row[data-seg]') : null;
        if (!r) {
            return;
        }
        var seg = r.getAttribute('data-seg');
        destacar(r.classList.contains('is-hot') && !svg.classList.contains('is-dim') ? null : seg);
    });

    // contagem: "12.358,69" → 0 → 12.358,69 em ~700 ms
    if (reduzir || !window.Intl) {
        return;
    }
    var fmt = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    document.querySelectorAll('[data-countup]').forEach(function (el) {
        var txt = el.textContent.trim();
        var m = txt.match(/^(-?)([\d.]+),(\d{2})$/);
        if (!m) {
            return;
        }
        var alvo = parseFloat(m[1] + m[2].replace(/\./g, '') + '.' + m[3]);
        if (!isFinite(alvo)) {
            return;
        }
        var ini = null;
        var dur = 700;
        function passo(t) {
            if (ini === null) {
                ini = t;
            }
            var k = Math.min(1, (t - ini) / dur);
            var e = 1 - Math.pow(1 - k, 3);
            el.textContent = fmt.format(alvo * e);
            if (k < 1) {
                requestAnimationFrame(passo);
            } else {
                el.textContent = txt;
                if (el === val) {
                    base.val = txt;
                }
            }
        }
        requestAnimationFrame(passo);
    });
})();
