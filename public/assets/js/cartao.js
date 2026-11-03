// Cartão: as barras das próximas faturas escolhem a fatura mostrada (sem JS, cada barra é um link ?fatura=);
// no "+", "Pagou com: Cartão" mostra a prévia de em que fatura a compra cai e quais sobem.
// Arquivo externo (CSP script-src 'self'); não faz nada nas páginas sem esses elementos.
(function () {
    'use strict';
    var MESES = [T('jan'), T('fev'), T('mar'), T('abr'), T('mai'), T('jun'), T('jul'), T('ago'), T('set'), T('out'), T('nov'), T('dez')];
    var EXT = [T('janeiro'), T('fevereiro'), T('março'), T('abril'), T('maio'), T('junho'), T('julho'), T('agosto'), T('setembro'), T('outubro'), T('novembro'), T('dezembro')];

    function brl(v) { return FIN.num(v); }
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

    /* ---- as próximas faturas: cada barra escolhe a fatura mostrada embaixo */
    var degraus = document.querySelectorAll('.ct-barra[data-fatura]');
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
            if (leg) { leg.innerHTML = ''; leg.appendChild(document.createTextNode(d.getAttribute('data-rot') + ': '));
                var b = document.createElement('b'); b.textContent = d.getAttribute('data-v'); leg.appendChild(b); }
            if (window.history && history.replaceState) {
                var u = new URL(d.href, location.href);
                history.replaceState(null, '', u.pathname + u.search);
            }
        });
    }

    /* ---- editar a compra: a data nova leva a 1ª parcela para a fatura dela, até a pessoa escolher a fatura à mão */
    var ed = document.querySelector('form[data-ct-editar]');
    if (ed) {
        var edData = ed.querySelector('input[name="data"]'), edFat = ed.querySelector('select[name="primeira_fatura"]');
        var aMao = false;
        if (edFat) { edFat.addEventListener('change', function () { aMao = true; }); }
        if (edData && edFat) {
            edData.addEventListener('change', function () {
                if (aMao || !/^\d{4}-\d{2}-\d{2}$/.test(edData.value)) { return; }
                var f = faturaDe(edData.value, parseInt(ed.getAttribute('data-fecha'), 10), parseInt(ed.getAttribute('data-vence'), 10));
                if (edFat.querySelector('option[value="' + f + '"]')) { edFat.value = f; }
            });
        }
    }

    /* ---- "Configurar o cartão" (no ⋯) abre o configurar, lá embaixo */
    var cfgLink = document.querySelector('[data-ct-configurar]');
    var cfgDet = document.querySelector('details.ct-config');
    if (cfgLink && cfgDet) {
        cfgLink.addEventListener('click', function () {
            cfgDet.open = true;
            var menu = cfgLink.closest('details');
            if (menu) { menu.open = false; }
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
        var botaoConta = botao ? (botao.getAttribute('data-lf-rot') || botao.textContent) : '';
        var dia = form.querySelector('[data-ct-dia]');
        var base = cfg;
        var serie = cfg.serie || [];
        var rotulos = bloco.querySelectorAll('.ct-escada-mini .ct-degrau > span:last-child');

        function pagouCom() { var r = form.querySelector('input[name="pagou"]:checked'); return r ? r.value : ''; }
        function noCartao() { var v = pagouCom(); return v === 'cartao' || v.indexOf('cartao:') === 0; }
        function transferindo() { var m = form.querySelector('input[name="modo"]:checked'); return !!m && m.value === 'transferir'; }
        // vários cartões: o fechamento, o vencimento e as faturas são os do cartão escolhido
        function escolherCartao() {
            if (!base.cartoes || !base.cartoes[pagouCom()]) { return; }
            cfg = base.cartoes[pagouCom()];
            serie = cfg.serie || [];
            serie.forEach(function (s, j) { if (rotulos[j]) { rotulos[j].textContent = MESES[partes(s[0])[1]]; } });
        }
        function lerValor() {
            var v = FIN.ler((valor && valor.value || '').trim()); return v > 0 ? v : 0;
        }
        function vezes() {
            var o = parseInt(outro && outro.value || '', 10);
            if (o >= 1 && o <= 48) { return o; }
            var r = form.querySelector('input[name="parcelas"]:checked');
            return r ? parseInt(r.value, 10) : 1;
        }
        function atualizar() {
            if (transferindo()) { return; }          // o rótulo do botão é do lancar.js
            escolherCartao();
            if (botao) { botao.textContent = noCartao() ? (botao.getAttribute('data-ct-rot') || botaoConta) : botaoConta; }
            if (dia) { dia.textContent = noCartao() ? T('Data da compra') : T('Dia'); }
            if (!noCartao()) { return; }
            var v = lerValor(), n = vezes();
            var k = parseInt(atual && atual.value || '', 10);
            var antiga = k > 1 && k <= n;      // compra antiga: a parcela k cai na fatura aberta
            var primeira = antiga ? soma(cfg.aberta, -(k - 1)) : faturaDe(data && data.value ? data.value : cfg.aberta, cfg.fecha, cfg.vence);
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
            if (v <= 0) { out.textContent = T('Digite o valor para ver em que fatura cai.'); sub.textContent = ''; return; }
            var pf = partes(primeira), ultima = soma(primeira, n - 1);
            var venceDia = Math.min(cfg.vence, diasNoMes(pf[0], pf[1]));
            // só números e nomes de mês entram nestas frases: dá para montar com innerHTML
            var frase = antiga                 // as faturas de antes da aberta já foram pagas
                ? T('A parcela {parcela} está na fatura aberta, de {mes}.', { parcela: '<b class="num">' + T('{k} de {n}', { k: k, n: n }) + '</b>', mes: '<b>' + EXT[partes(cfg.aberta)[1]] + '</b>' })
                : T('Cai na fatura de {mes}, que vence em {data}.', { mes: '<b>' + EXT[pf[1]] + '</b>', data: FIN.diaMes(venceDia, pf[1]) });
            var plano = '<b class="num">' + (n === 1 ? T('À vista, {valor}', { valor: brl(v) }) : n + ' × ' + brl(base / 100)) + '</b>';
            out.innerHTML = frase + ' ' + (n > 1 ? T('{plano}, de {inicio} a {fim}.', { plano: plano, inicio: rot(primeira), fim: rot(ultima) }) : plano + '.');
            var faltam = n - k + 1;
            sub.textContent = (antiga ? (faltam === 1 ? T('Falta 1 parcela: só a fatura de {mes} sobe {valor}.', { mes: rot(cfg.aberta), valor: brl(base / 100) })
                                                      : T('Faltam {n} parcelas: cada fatura de {inicio} a {fim} sobe {valor}.', { n: faltam, inicio: rot(cfg.aberta), fim: rot(ultima), valor: brl(base / 100) }))
                : n === 1 ? T('Só a fatura de {mes} sobe {valor}.', { mes: EXT[pf[1]], valor: brl(v) }) : T('Cada fatura de {inicio} a {fim} sobe {valor}.', { inicio: rot(primeira), fim: rot(ultima), valor: brl(base / 100) }))
                + ' ' + T('Em destaque, o que esta compra soma.');
        }
        form.addEventListener('input', atualizar);
        form.addEventListener('change', atualizar);
        atualizar();
    }

    /* ---- o visual do cartão: a escolha aparece no cartão desenhado antes de salvar */
    var vis = document.querySelector('[data-ct-vis]');
    var plastico = document.querySelector('[data-ct-plastico]');
    if (vis) {
        var VARS = ['--ct-a', '--ct-b', '--ct-txt', '--ct-txt-2', '--ct-pill'];   // a cor lisa é a --ct-a
        var miniPropria = vis.querySelector('[data-ct-vis-propria]');
        var cor = vis.querySelector('input[name="visual_cor"]');
        var pintar = function (el, valor, c) {
            if (!el) { return; }
            VARS.forEach(function (v) { el.style.removeProperty(v); });
            if (valor === '') { el.removeAttribute('data-visual'); return; }
            el.setAttribute('data-visual', valor);
            if (valor === 'propria') {
                el.style.setProperty('--ct-a', c.a); el.style.setProperty('--ct-b', c.b); el.style.setProperty('--ct-txt', c.txt);
                el.style.setProperty('--ct-txt-2', c.txt); el.style.setProperty('--ct-pill', c.pill);
            }
        };
        var mostrar = function () {
            var r = vis.querySelector('input[name="visual"]:checked');
            var c = corPropria(cor && /^#[0-9a-f]{6}$/i.test(cor.value) ? cor.value.toLowerCase() : '#2f6fdf');
            pintar(miniPropria, 'propria', c);
            pintar(plastico, r ? r.value : '', c);
        };
        vis.addEventListener('change', mostrar);
        if (cor) {
            // mexer na cor já escolhe a "Cor própria"
            cor.addEventListener('input', function () {
                var r = vis.querySelector('input[name="visual"][value="propria"]');
                if (r) { r.checked = true; }
                mostrar();
            });
        }
    }

    // a mesma conta de CartaoVisual::corPropria: texto branco ou preto, a cor longe dele até o contraste bastar
    function corPropria(hex) {
        var rgb = [1, 3, 5].map(function (i) { return parseInt(hex.slice(i, i + 2), 16); });
        var branco = contraste([255, 255, 255], rgb) >= contraste([0, 0, 0], rgb);
        var alvo = branco ? [0, 0, 0] : [255, 255, 255], txt = branco ? [255, 255, 255] : [0, 0, 0];
        for (var i = 0; i < 30 && Math.min(contraste(txt, rgb), contraste(txt, misturar(rgb, [255, 255, 255], 0.10))) < 4.6; i++) {
            rgb = misturar(rgb, alvo, 0.06);
        }
        return { a: hexDe(rgb), b: hexDe(misturar(rgb, alvo, 0.28)), txt: branco ? '#ffffff' : '#000000',
                 pill: branco ? 'rgb(0 0 0 / .22)' : 'rgb(255 255 255 / .45)' };
    }
    function misturar(a, b, q) { return [0, 1, 2].map(function (i) { return a[i] + (b[i] - a[i]) * q; }); }
    // como o PHP: arredonda só ao escrever (o sprintf de lá), e a conta segue com a cor arredondada
    function hexDe(c) { return '#' + c.map(function (x) { return ('0' + Math.round(Math.max(0, Math.min(255, x))).toString(16)).slice(-2); }).join(''); }
    function lum(c) {
        var l = c.map(function (x) { x = Math.round(Math.max(0, Math.min(255, x))) / 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); });
        return 0.2126 * l[0] + 0.7152 * l[1] + 0.0722 * l[2];
    }
    function contraste(a, b) { var la = lum(a), lb = lum(b); return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05); }
})();
