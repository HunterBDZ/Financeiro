// movimento.js: o movimento que o CSS sozinho não faz (design-nov/redesenho/movimento.md § 6). Carrega no <head>, antes
// do corpo, para pegar a troca de página: o pageswap (a página que sai) e o pagereveal (a que entra, antes da 1ª
// pintura). Sem nada inline (CSP script-src 'self') e sem biblioteca.
// - o tipo da troca: outra aba, o mês seguinte ou o anterior (o conteúdo desliza no sentido do tempo), a mesma aba
//   com outro filtro, ou uma ação (POST), que troca na hora e deixa o movimento para dentro da tela;
// - antes de uma ação, guarda como a tela estava (os números com [data-mov-conta], os segmentos da barra, as linhas
//   da lista); na volta, o número conta até o novo, os segmentos ajustam com scaleX, o carimbo entra e a linha nova
//   do Extrato pisca;
// - a barra do dinheiro do Início se preenche só na primeira visita do dia (a data vem do servidor, Relogio);
// - o diálogo de lançar cresce a partir do botão que o abriu; o traço de Conta e Cartão desliza;
// - a troca de tema faz uma transição só (MOV.tema, usado por app.js e temas.js).
// O estado final é sempre o HTML que veio do servidor: o movimento só desenha o caminho até ele. Um clique ou uma tecla
// nova fixa o estado final na hora; nada depende de animationend. Com "reduzir movimento", nada se mexe.
(function () {
    'use strict';

    var raiz = document.documentElement;
    var reduz = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : { matches: false };
    var MICRO = 120, CURTO = 220, LONGO = 380, SAIDA = 0.65, CONTAGEM = 400;
    var ENTRA = 'cubic-bezier(.22, 1, .36, 1)', MOVE = 'cubic-bezier(.65, 0, .35, 1)';
    var CHAVE_NAV = 'mov-nav', CHAVE_ACAO = 'mov-acao', CHAVE_DIA = 'mov-barra-dia';
    var VALIDADE = 15000;   // o que foi guardado antes de uma ação vale por 15s

    function reduzido() { return !!reduz.matches; }
    // uma transição cancelada (outro clique, o voltar do navegador) rejeita as promessas dela: não é erro
    function calar(vt) {
        ['ready', 'finished', 'updateCallbackDone'].forEach(function (k) {
            if (vt && vt[k] && typeof vt[k].then === 'function') { vt[k].then(function () {}, function () {}); }
        });
        return vt;
    }
    // a troca de página que o navegador desiste de animar (outra navegação começou antes) rejeita uma promessa que
    // nenhum script recebe: também não é erro
    window.addEventListener('unhandledrejection', function (ev) {
        var r = ev.reason;
        if (r && (r.name === 'InvalidStateError' || r.name === 'AbortError') && /transition/i.test(String(r.message))) { ev.preventDefault(); }
    });
    function guardar(chave, valor) { try { sessionStorage.setItem(chave, JSON.stringify(valor)); } catch (e) {} }
    function tirar(chave) {
        try {
            var v = sessionStorage.getItem(chave);
            sessionStorage.removeItem(chave);
            var o = v ? JSON.parse(v) : null;
            return o && Date.now() - (o.t || 0) < VALIDADE ? o : null;
        } catch (e) { return null; }
    }

    // ---- o que está se mexendo, para fixar o fim na hora
    var vivos = [];         // animações (Web Animations) criadas aqui
    var contagens = [];     // funções que põem o número final
    var vtAtiva = null;     // a View Transition em curso
    function animar(el, quadros, opc) {
        if (!el || typeof el.animate !== 'function') { return null; }
        var a = el.animate(quadros, opc);
        vivos.push(a);
        a.finished.then(function () { vivos = vivos.filter(function (x) { return x !== a; }); }, function () {});
        return a;
    }
    function fixar() {
        vivos.splice(0).forEach(function (a) { try { a.finish(); } catch (e) { a.cancel(); } });
        contagens.splice(0).forEach(function (fim) { fim(); });
        if (vtAtiva) { try { vtAtiva.skipTransition(); } catch (e) {} vtAtiva = null; }
        // o que o CSS anima por conta própria e também é movimento de uma ação: o pisca do Mês e do Extrato, o diálogo
        if (typeof document.getAnimations === 'function') {
            document.getAnimations().forEach(function (a) {
                var nome = a.animationName || '';
                var alvo = a.effect && a.effect.target;
                if (nome === 'mov-pisca' || nome === 'mov-treme' || (alvo instanceof Element && alvo.matches('dialog.sheet'))) {
                    try { a.finish(); } catch (e) {}
                }
            });
        }
    }
    // um clique ou uma tecla nova cancela o movimento anterior e fixa o estado final (captura: antes de tudo)
    document.addEventListener('pointerdown', fixar, true);
    document.addEventListener('keydown', function (ev) { if (!ev.repeat) { fixar(); } }, true);

    // ---- o tipo da troca de página
    function mesDe(url) {
        var m = /[?&](?:mes|fatura)=(\d{4}-\d{2})/.exec(String(url));   // o mês do Mês e a fatura do Cartão
        return m ? m[1] : null;
    }
    var ultimoClique = null;   // { href, sentido }
    var ultimoPost = 0;        // quando saiu o último envio POST
    function sentidoDoLink(a) {
        if (a.matches('[rel="prev"]')) { return 'ant'; }
        if (a.matches('[rel="next"]')) { return 'prox'; }
        if (a.matches('.mes-picker-m')) {
            var on = a.closest('.mes-picker-painel') && a.closest('.mes-picker-painel').querySelector('.mes-picker-m.is-on');
            var de = on ? mesDe(on.href) : null, para = mesDe(a.href);
            if (de && para && de !== para) { return para > de ? 'prox' : 'ant'; }
        }
        return null;
    }
    document.addEventListener('click', function (ev) {
        var a = ev.target instanceof Element ? ev.target.closest('a[href]') : null;
        if (a) { ultimoClique = { href: a.href, sentido: sentidoDoLink(a) }; }
    }, true);

    function tipoDaTroca(de, para) {
        if (Date.now() - ultimoPost < 4000) { return 'acao'; }
        if (!para) { return 'aba'; }
        var u1, u2;
        try { u1 = new URL(de); u2 = new URL(para); } catch (e) { return 'aba'; }
        if (u1.pathname !== u2.pathname) { return 'aba'; }
        var s = ultimoClique && ultimoClique.href === para ? ultimoClique.sentido : null;
        if (!s) {
            var m1 = mesDe(u1.search), m2 = mesDe(u2.search);
            if (m1 && m2 && m1 !== m2) { s = m2 > m1 ? 'prox' : 'ant'; }
        }
        return s ? 'mes-' + s : 'troca';
    }

    // a página que sai: decide o tipo, marca a transição e conta para a página que entra
    window.addEventListener('pageswap', function (ev) {
        var para = ev.activation && ev.activation.entry ? ev.activation.entry.url : (ultimoClique ? ultimoClique.href : null);
        var tipo = tipoDaTroca(location.href, para);
        guardar(CHAVE_NAV, { tipo: tipo, t: Date.now() });
        var vt = calar(ev.viewTransition);
        if (!vt) { return; }
        if (tipo === 'acao' || reduzido()) { vt.skipTransition(); return; }
        try { vt.types.add(tipo); } catch (e) {}
    });

    // ---- a página que entra
    var iniciado = false;
    function revelar(ev) {
        var nav = tirar(CHAVE_NAV);
        var vt = calar(ev && ev.viewTransition);
        if (vt) {
            if (!nav || nav.tipo === 'acao' || reduzido()) {
                vt.skipTransition();
            } else {
                try { vt.types.add(nav.tipo); } catch (e) {}
                vtAtiva = vt;
                vt.finished.then(function () { if (vtAtiva === vt) { vtAtiva = null; } }, function () {});
            }
        }
        if (!iniciado) {
            iniciado = true;
            iniciar();
        }
    }
    if ('onpagereveal' in window) {
        window.addEventListener('pagereveal', revelar);
    } else if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { revelar(null); });
    } else {
        revelar(null);
    }

    // a linha dona de uma barra fina de meta: o primeiro ancestral com id que só tem essa barra (a linha da meta, do Mês)
    function donoDaBarra(i) {
        var d = i.closest('[id]');
        return d && d.querySelectorAll('.meta-barra').length === 1 ? d : null;
    }

    // ---- antes de uma ação (POST): como a tela estava
    function retratoDaTela(form) {
        var r = { t: Date.now(), rapido: !!form.closest('[data-toast]'), valores: {}, barras: {}, finas: {}, listas: {}, pago: null,
                  caminho: location.pathname + location.search, rolagem: window.scrollY };
        document.querySelectorAll('[data-mov-conta]').forEach(function (el) {
            var v = parseFloat(el.getAttribute('data-mov-valor'));
            if (isFinite(v)) { r.valores[el.getAttribute('data-mov-conta')] = v; }
        });
        document.querySelectorAll('[data-mov-barra]').forEach(function (el) {
            var barra = el.querySelector('.barra');
            if (!barra) { return; }
            var b = barra.getBoundingClientRect();
            if (b.width <= 0) { return; }
            var segs = {};
            Array.prototype.forEach.call(barra.children, function (seg) {
                var c = (seg.className.match(/\bs-[a-z]+/) || [])[0];
                var s = seg.getBoundingClientRect();
                if (c) { segs[c] = { l: (s.left - b.left) / b.width, w: s.width / b.width }; }
            });
            r.barras[el.getAttribute('data-mov-barra')] = segs;
        });
        document.querySelectorAll('.meta-barra > i').forEach(function (i) {
            var dono = donoDaBarra(i);
            var p = parseFloat(getComputedStyle(i).getPropertyValue('--p'));
            if (dono && isFinite(p)) { r.finas[dono.id] = p; }
        });
        document.querySelectorAll('[data-mov-lista]').forEach(function (el) {
            r.listas[el.getAttribute('data-mov-lista')] = Array.prototype.map.call(el.querySelectorAll('[data-mov-linha]'), function (l) {
                return l.getAttribute('data-mov-linha');
            });
        });
        if (/\/lancamentos\/pago$/.test(form.getAttribute('action') || '')) {
            var cat = form.querySelector('input[name="categoria_id"]');
            r.pago = cat ? cat.value : null;
        }
        return r;
    }
    // na janela: depois dos confirmar() e das checagens dos outros scripts, que podem cancelar o envio
    window.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (ev.defaultPrevented || !(form instanceof HTMLFormElement)) { return; }
        if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') { return; }
        ultimoPost = Date.now();
        fixar();
        guardar(CHAVE_ACAO, retratoDaTela(form));
        var pagar = ev.submitter && ev.submitter.closest ? ev.submitter.closest('.btn-pagar') : null;
        if (pagar && !reduzido()) { pagar.classList.add('is-pagando'); }
    });

    // ---- o número conta do valor antigo até o novo, e no fim volta o texto que veio do servidor
    function contar(el, de, dur) {
        var alvo = el.matches('.num') ? el : el.querySelector('.num');
        var fin = window.FIN;
        if (!alvo || !fin) { return; }
        var ate = parseFloat(el.getAttribute('data-mov-valor'));
        if (!isFinite(ate) || Math.abs(ate - de) < 0.005 || (de < 0) !== (ate < 0)) { return; }
        var andar = document.createTreeWalker(alvo, NodeFilter.SHOW_TEXT);
        var intNo = null, n;
        while ((n = andar.nextNode())) {
            if (/\d/.test(n.data) && !(n.parentNode instanceof Element && n.parentNode.closest('.cent, .moeda'))) { intNo = n; break; }
        }
        if (!intNo) { return; }
        var centEl = alvo.querySelector('.cent');
        var centNo = centEl && centEl.firstChild && centEl.firstChild.nodeType === 3 ? centEl.firstChild : null;
        var origInt = intNo.data, origCent = centNo ? centNo.data : null;
        var pre = (origInt.match(/^[^\d]*/) || [''])[0];
        var casas = centNo || new RegExp('\\' + fin.dec + '\\d+$').test(origInt) ? 2 : 0;
        var acabou = false, t0 = 0;
        function escreve(v) {
            var s = fin.num(Math.abs(v), casas);
            if (centNo) {
                var i = s.lastIndexOf(fin.dec);
                intNo.data = pre + s.slice(0, i);
                centNo.data = s.slice(i);
            } else {
                intNo.data = pre + s;
            }
        }
        function fim() {
            if (acabou) { return; }
            acabou = true;
            intNo.data = origInt;
            if (centNo) { centNo.data = origCent; }
            contagens = contagens.filter(function (f) { return f !== fim; });
        }
        function quadro(agora) {
            if (acabou) { return; }
            if (!t0) { t0 = agora; }
            var p = Math.min(1, (agora - t0) / dur);
            if (p >= 1) { fim(); return; }
            var e = 1 - Math.pow(1 - p, 3);
            escreve(de + (ate - de) * e);
            requestAnimationFrame(quadro);
        }
        contagens.push(fim);
        escreve(de);
        requestAnimationFrame(quadro);
        setTimeout(fim, dur + 200);   // sem quadros (aba em segundo plano), o número final aparece do mesmo jeito
    }

    // ---- os segmentos da barra vão da largura antiga para a nova, com scaleX no lugar (sem mexer na largura)
    function ajustarBarra(barra, antes, dur) {
        var b = barra.getBoundingClientRect();
        if (b.width <= 0) { return; }
        Array.prototype.forEach.call(barra.children, function (seg) {
            var c = (seg.className.match(/\bs-[a-z]+/) || [])[0];
            var s = seg.getBoundingClientRect();
            if (!c || s.width <= 0) { return; }
            var l = (s.left - b.left) / b.width, w = s.width / b.width, o = antes[c];
            if (o && Math.abs(o.l - l) < 0.001 && Math.abs(o.w - w) < 0.001) { return; }
            var de = o ? 'translateX(' + ((o.l - l) * b.width).toFixed(2) + 'px) scaleX(' + (o.w / w).toFixed(4) + ')' : 'scaleX(0)';
            animar(seg, [{ transform: de }, { transform: 'none' }], { duration: dur, easing: ENTRA });
        });
    }

    // ---- a barra do Início na primeira visita do dia: segmento por segmento, 380ms no total, 40ms entre eles
    function barraDoDia(soAnotar) {
        var dest = document.querySelector('[data-mov-dia]');
        var barra = dest && dest.querySelector('.barra');
        if (!barra) { return false; }
        var hoje = dest.getAttribute('data-mov-dia');
        var visto = null;
        try { visto = localStorage.getItem(CHAVE_DIA); localStorage.setItem(CHAVE_DIA, hoje); } catch (e) { return false; }
        if (soAnotar || visto === hoje) { return false; }
        var segs = Array.prototype.slice.call(barra.children);
        var passo = 40, dur = Math.max(MICRO, LONGO - passo * (segs.length - 1));
        segs.forEach(function (seg, i) {
            animar(seg, [{ transform: 'scaleX(0)' }, { transform: 'none' }], { duration: dur, delay: i * passo, easing: ENTRA, fill: 'backwards' });
        });
        return true;
    }

    // ---- na volta de uma ação: o caminho do antes para o agora
    function depoisDaAcao(r) {
        var fator = r.rapido ? SAIDA : 1;   // desfazer: o mesmo caminho, mais rápido
        // a página voltou do servidor no topo: na mesma tela, a rolagem volta para onde a pessoa estava (o carimbo e os números ficam à vista)
        if (r.caminho === location.pathname + location.search && r.rolagem > 0) { window.scrollTo(0, r.rolagem); }
        document.querySelectorAll('[data-mov-conta]').forEach(function (el) {
            var k = el.getAttribute('data-mov-conta');
            if (Object.prototype.hasOwnProperty.call(r.valores, k)) { contar(el, r.valores[k], CONTAGEM * fator); }
        });
        document.querySelectorAll('[data-mov-barra]').forEach(function (el) {
            var antes = r.barras[el.getAttribute('data-mov-barra')];
            var barra = el.querySelector('.barra');
            if (antes && barra) { ajustarBarra(barra, antes, CONTAGEM * fator); }
        });
        // as barras finas de meta crescem com scaleX só quando o valor mudou
        document.querySelectorAll('.meta-barra > i').forEach(function (i) {
            var dono = donoDaBarra(i);
            var p = parseFloat(getComputedStyle(i).getPropertyValue('--p'));
            var antes = dono && r.finas ? r.finas[dono.id] : undefined;
            if (antes === undefined || !isFinite(p) || Math.abs(antes - p) < 0.001) { return; }
            animar(i, [{ transform: 'scaleX(' + antes + ')' }, { transform: 'scaleX(' + p + ')' }], { duration: CURTO * fator, easing: ENTRA });
        });
        if (r.pago !== null) {
            var carimbo = document.querySelector('[data-mov-pago="' + String(r.pago).replace(/[^\d]/g, '') + '"] .carimbo');
            if (carimbo) {
                var cx = carimbo.getBoundingClientRect();
                if (cx.top < 0 || cx.bottom > window.innerHeight) { carimbo.scrollIntoView({ block: 'center' }); }
            }
            animar(carimbo, [{ opacity: 0, transform: 'scale(.92) rotate(-4deg)' }, { opacity: 1, transform: 'none' }], { duration: CURTO, easing: ENTRA });
        }
        document.querySelectorAll('[data-mov-lista]').forEach(function (el) {
            var antes = r.listas[el.getAttribute('data-mov-lista')];
            if (!antes || antes.length === 0) { return; }
            el.querySelectorAll('[data-mov-linha]').forEach(function (l) {
                if (antes.indexOf(l.getAttribute('data-mov-linha')) === -1) { l.classList.add('mov-novo'); }
            });
        });
    }

    // ---- o diálogo de lançar cresce a partir de onde foi aberto
    var gatilho = null;   // { x, y, t }
    document.addEventListener('click', function (ev) {
        var g = ev.target instanceof Element ? ev.target.closest('[data-lancar]') : null;
        if (!g) { return; }
        var r = g.getBoundingClientRect();
        gatilho = { x: r.left + r.width / 2, y: r.top + r.height / 2, t: Date.now() };
    }, true);
    function origemDoDialogo(dlg) {
        var g = gatilho && Date.now() - gatilho.t < 1000 ? gatilho : null;
        gatilho = null;
        if (!g) {   // a tecla N: o botão Lançar da lateral, ou o "+" da barra de baixo
            var botoes = document.querySelectorAll('.lat-lancar, .tab-add');
            for (var i = 0; i < botoes.length; i++) {
                var rb = botoes[i].getBoundingClientRect();
                if (rb.width > 0) { g = { x: rb.left + rb.width / 2, y: rb.top + rb.height / 2 }; break; }
            }
        }
        if (!g) { dlg.style.removeProperty('--origem'); return; }
        var r = dlg.getBoundingClientRect();
        dlg.style.setProperty('--origem', Math.round(g.x - r.left) + 'px ' + Math.round(g.y - r.top) + 'px');
    }

    // ---- Conta e Cartão: o traço azul desliza da aba antiga para a nova
    var abaMarcada = new WeakMap();
    function ligarAbas(fs) {
        var marcada = fs.querySelector('label:has(input:checked)');
        abaMarcada.set(fs, marcada ? marcada.getBoundingClientRect() : null);
        fs.addEventListener('pointerdown', function () {
            var m = fs.querySelector('label:has(input:checked)');
            abaMarcada.set(fs, m ? m.getBoundingClientRect() : null);
        });
        fs.addEventListener('change', function () {
            var antes = abaMarcada.get(fs);
            var nova = fs.querySelector('label:has(input:checked)');
            if (!nova) { return; }
            var r = nova.getBoundingClientRect();
            abaMarcada.set(fs, r);
            if (!antes || reduzido() || r.width <= 0 || Math.abs(antes.left - r.left) < 1) { return; }
            animar(nova, [
                { transform: 'translateX(' + (antes.left - r.left).toFixed(1) + 'px) scaleX(' + (antes.width / r.width).toFixed(4) + ')' },
                { transform: 'none' }
            ], { duration: CURTO, easing: MOVE, pseudoElement: '::after' });
        });
    }

    // ---- a troca de tema: a tela inteira numa transição só
    window.MOV = {
        tema: function (aplicar) {
            if (typeof document.startViewTransition !== 'function' || reduzido() || document.visibilityState !== 'visible') {
                aplicar();
                return;
            }
            fixar();
            raiz.classList.add('vt-tema');
            var vt;
            try {
                vt = calar(document.startViewTransition(aplicar));
            } catch (e) {
                raiz.classList.remove('vt-tema');
                aplicar();
                return;
            }
            vtAtiva = vt;
            vt.finished.then(function () {}, function () {}).then(function () {
                if (vtAtiva === vt) { vtAtiva = null; }
                raiz.classList.remove('vt-tema');
            });
        }
    };

    // ---- ao revelar a página
    function iniciar() {
        var r = tirar(CHAVE_ACAO);
        var dlg = document.getElementById('lancar');
        if (dlg && typeof MutationObserver === 'function') {
            new MutationObserver(function () { if (dlg.open) { origemDoDialogo(dlg); } }).observe(dlg, { attributes: true, attributeFilter: ['open'] });
        }
        if (typeof CSS !== 'undefined' && CSS.supports && CSS.supports('selector(:has(*))')) {
            document.querySelectorAll('.lf fieldset.ct-pagou').forEach(ligarAbas);
        }
        // com "reduzir movimento", nada se mexe; depois de uma ação, quem se mexe é o que ela mudou. Nos dois casos a
        // barra do dia só anota a visita
        var acao = !!(r && (Object.keys(r.valores).length || Object.keys(r.barras).length || Object.keys(r.finas || {}).length || r.pago !== null || Object.keys(r.listas).length));
        barraDoDia(reduzido() || acao);
        if (acao && !reduzido()) {
            depoisDaAcao(r);
        }
    }
})();
