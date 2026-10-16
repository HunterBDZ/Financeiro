// Cartão: a escada das faturas escolhe a fatura mostrada (sem JS, cada degrau é um link ?fatura=);
// no "+", "Pagou com: Cartão" mostra a prévia de em que fatura a compra cai e quais sobem.
// Arquivo externo (CSP script-src 'self'); não faz nada nas páginas sem esses elementos.
(function () {
    'use strict';
    var MESES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
    var EXT = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

    function brl(v) { return v.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function partes(m) { return [parseInt(m.slice(0, 4), 10), parseInt(m.slice(5, 7), 10) - 1]; }       // [ano, 0-11]
    function mesStr(a, m) { var d = new Date(a, m, 1); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-01'; }
    function soma(mes, n) { var p = partes(mes); return mesStr(p[0], p[1] + n); }
    function rot(mes) { var p = partes(mes); return MESES[p[1]] + '/' + String(p[0]).slice(2); }
    function diasNoMes(a, m) { return new Date(a, m + 1, 0).getDate(); }
    // mesma regra de CartaoFatura::faturaDe: no dia do fechamento já vai para a seguinte
    function faturaDe(data, fecha, vence) {
        var a = parseInt(data.slice(0, 4), 10), m = parseInt(data.slice(5, 7), 10) - 1, d = parseInt(data.slice(8, 10), 10);
        if (d >= Math.min(fecha, diasNoMes(a, m))) { m++; }
        if (vence <= fecha) { m++; }
        return mesStr(a, m);
    }

    /* ---- a escada escolhe a fatura */
    var degraus = document.querySelectorAll('.ct-cima .ct-degrau[data-fatura]');
    var leg = document.querySelector('[data-escada-leg]');
    for (var i = 0; i < degraus.length; i++) {
        degraus[i].addEventListener('click', function (ev) {
            if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.button !== 0) { return; }
            ev.preventDefault();
            var d = ev.currentTarget;
            for (var j = 0; j < degraus.length; j++) { degraus[j].removeAttribute('aria-current'); }
            d.setAttribute('aria-current', 'true');
            var fs = document.querySelectorAll('.ct-fat');
            for (var k = 0; k < fs.length; k++) { fs[k].hidden = fs[k].id !== d.getAttribute('data-fatura'); }
            if (leg) { leg.innerHTML = ''; leg.appendChild(document.createTextNode(d.getAttribute('data-rot') + ' · '));
                var b = document.createElement('b'); b.className = 'num'; b.textContent = d.getAttribute('data-v'); leg.appendChild(b); }
            if (window.history && history.replaceState) {
                var u = new URL(d.href, location.href);
                history.replaceState(null, '', u.pathname + u.search);
            }
        });
    }

    /* ---- o "+" com "Pagou com: Cartão" */
    var forms = document.querySelectorAll('form[data-lf]');
    for (var f = 0; f < forms.length; f++) { ligar(forms[f]); }

    function ligar(form) {
        var bloco = form.querySelector('[data-ct-bloco]');
        if (!bloco) { return; }
        var cfg;
        try { cfg = JSON.parse(bloco.getAttribute('data-ct') || '{}'); } catch (e) { return; }
        var valor = form.querySelector('[data-lf-valor]');
        var data = form.querySelector('input[name="data"]');
        var outro = form.querySelector('input[name="parcelas_outro"]');
        var atual = form.querySelector('input[name="parcela_atual"]');
        var out = bloco.querySelector('[data-ct-previa]');
        var sub = bloco.querySelector('[data-ct-previa-sub]');
        var barras = bloco.querySelectorAll('.ct-escada-mini .ct-degrau-barra');
        var botao = form.querySelector('[data-lf-enviar]');
        var botaoConta = botao ? botao.textContent : '';
        var dia = form.querySelector('[data-ct-dia]');
        var serie = cfg.serie || [];

        function noCartao() { var r = form.querySelector('input[name="pagou"]:checked'); return !!r && r.value === 'cartao'; }
        function lerValor() {
            var s = (valor && valor.value || '').replace(/\./g, '').replace(',', '.');
            var v = parseFloat(s); return isFinite(v) && v > 0 ? v : 0;
        }
        function vezes() {
            var o = parseInt(outro && outro.value || '', 10);
            if (o >= 1 && o <= 48) { return o; }
            var r = form.querySelector('input[name="parcelas"]:checked');
            return r ? parseInt(r.value, 10) : 1;
        }
        function atualizar() {
            if (botao) { botao.textContent = noCartao() ? (botao.getAttribute('data-ct-rot') || botaoConta) : botaoConta; }
            if (dia) { dia.textContent = noCartao() ? 'Data da compra' : 'Dia'; }
            if (!noCartao()) { return; }
            var v = lerValor(), n = vezes();
            var k = parseInt(atual && atual.value || '', 10);
            var primeira = k > 1 && k <= n ? soma(cfg.aberta, -(k - 1)) : faturaDe(data && data.value ? data.value : cfg.aberta, cfg.fecha, cfg.vence);
            var cent = Math.round(v * 100), base = Math.floor(cent / n), resto = cent - base * n;
            var add = {};
            for (var p = 0; p < n; p++) { add[soma(primeira, p)] = (base + (p === 0 ? resto : 0)) / 100; }
            var topo = 0.01;
            serie.forEach(function (s) { topo = Math.max(topo, s[1] + (add[s[0]] || 0)); });
            serie.forEach(function (s, j) {
                var b = barras[j]; if (!b) { return; }
                var a = add[s[0]] || 0, t = s[1] + a;
                b.style.setProperty('--h', Math.max(2, t / topo * 100).toFixed(1) + '%');
                var novo = b.querySelector('.ct-degrau-novo');
                if (novo) { novo.style.height = t > 0 ? (a / t * 100).toFixed(1) + '%' : '0%'; }
            });
            if (v <= 0) { out.textContent = 'Digite o valor para ver em que fatura cai.'; sub.textContent = ''; return; }
            var pf = partes(primeira), ultima = soma(primeira, n - 1);
            var venceDia = Math.min(cfg.vence, diasNoMes(pf[0], pf[1]));
            out.textContent = '';
            out.appendChild(document.createTextNode((k > 1 ? 'A parcela ' + k + ' está na fatura aberta. A 1ª foi na fatura de ' : 'Cai na fatura de ')));
            var b1 = document.createElement('b'); b1.textContent = EXT[pf[1]]; out.appendChild(b1);
            out.appendChild(document.createTextNode(', que vence em ' + venceDia + '/' + String(pf[1] + 1).padStart(2, '0') + '. '));
            var b2 = document.createElement('b'); b2.className = 'num';
            b2.textContent = n === 1 ? 'À vista, ' + brl(v) : n + ' × ' + brl(base / 100);
            out.appendChild(b2);
            out.appendChild(document.createTextNode(n > 1 ? ', de ' + rot(primeira) + ' a ' + rot(ultima) + '.' : '.'));
            sub.textContent = (n === 1 ? 'Só a fatura de ' + EXT[pf[1]] + ' sobe ' + brl(v) : 'Cada fatura de ' + rot(primeira) + ' a ' + rot(ultima) + ' sobe ' + brl(base / 100))
                + '. Em destaque, o que esta compra soma.';
        }
        form.addEventListener('input', atualizar);
        form.addEventListener('change', atualizar);
        atualizar();
    }
})();
