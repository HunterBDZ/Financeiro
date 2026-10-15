// app.js (P1): comportamento da casca. Sem nada inline (CSP script-src 'self').
// - alterna o tema ([data-theme-toggle]);
// - fecha details.menu ao clicar fora (e com Esc);
// - [data-quando]: o radio value="outro" revela .quando-data (fallback para navegador sem :has);
// - fecha o toast ([data-toast-fechar]);
// - esconde a barra inferior enquanto um campo tem foco (fallback sem :has).
(function () {
    'use strict';

    var root = document.documentElement;

    // o tema em vigor: o escolhido ou, sem escolha, o do aparelho
    function temaAtual() {
        var t = root.getAttribute('data-theme');
        if (t === 'light' || t === 'dark') {
            return t;
        }
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    function trocarTema() {
        var novo = temaAtual() === 'light' ? 'dark' : 'light';
        root.setAttribute('data-theme', novo);
        try { localStorage.setItem('tema', novo); } catch (e) {}
        document.querySelectorAll('meta[name="theme-color"]').forEach(function (meta) {
            meta.setAttribute('content', novo === 'light' ? '#ffffff' : '#000000');
        });
    }

    function fecharMenus(excetoDentroDe) {
        var abertos = document.querySelectorAll('details.menu[open]');
        for (var i = 0; i < abertos.length; i++) {
            if (!excetoDentroDe || !abertos[i].contains(excetoDentroDe)) {
                abertos[i].removeAttribute('open');
            }
        }
    }

    // ---- cliques
    document.addEventListener('click', function (ev) {
        var alvo = ev.target;
        if (!(alvo instanceof Element)) {
            return;
        }

        if (alvo.closest('[data-theme-toggle]')) {
            trocarTema();
            fecharMenus(null);
            return;
        }

        var fechar = alvo.closest('[data-toast-fechar]');
        if (fechar) {
            var toast = fechar.closest('[data-toast]');
            if (toast) {
                toast.classList.add('is-fechado');
            }
            return;
        }
    });

    // fora do menu fecha (pointerdown: no iOS o click não borbulha de área sem ação)
    document.addEventListener('pointerdown', function (ev) {
        if (ev.target instanceof Element) {
            fecharMenus(ev.target);
        }
    });

    // abrir um menu fecha os outros
    document.addEventListener('toggle', function (ev) {
        var d = ev.target;
        if (d instanceof Element && d.matches('details.menu') && d.open) {
            fecharMenus(d);
        }
    }, true);

    document.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Escape') {
            return;
        }
        var aberto = document.querySelector('details.menu[open]');
        if (aberto) {
            aberto.removeAttribute('open');
            var s = aberto.querySelector('summary');
            if (s) {
                s.focus();
            }
        }
    });

    // ---- fieldset.quando: "outro dia" revela o type=date
    function syncQuando(fs) {
        var outro = fs.querySelector('input[type="radio"][value="outro"]');
        var ligado = !!(outro && outro.checked);
        fs.classList.toggle('is-outro', ligado);
        return ligado;
    }

    function syncTodosQuando() {
        var todos = document.querySelectorAll('[data-quando]');
        for (var i = 0; i < todos.length; i++) {
            syncQuando(todos[i]);
        }
    }

    // ---- mudanças de campo
    document.addEventListener('change', function (ev) {
        var el = ev.target;
        if (!(el instanceof Element)) {
            return;
        }

        var fs = el.closest('[data-quando]');
        if (fs && el.matches('input[type="radio"]')) {
            if (syncQuando(fs) && el.value === 'outro') {
                var data = fs.querySelector('.quando-data');
                if (data) {
                    data.focus();
                }
            }
        }
    });

    // ---- barra inferior some enquanto se digita (o CSS faz com :has; isto cobre navegador sem :has)
    function ehCampoDeTexto(el) {
        if (!(el instanceof Element)) {
            return false;
        }
        if (el.matches('select, textarea')) {
            return true;
        }
        return el.matches('input') && !el.matches('[type="checkbox"], [type="radio"], [type="hidden"], [type="submit"], [type="button"]');
    }
    document.addEventListener('focusin', function (ev) {
        if (ehCampoDeTexto(ev.target)) {
            document.body.classList.add('is-digitando');
        }
    });
    document.addEventListener('focusout', function (ev) {
        if (ehCampoDeTexto(ev.target)) {
            document.body.classList.remove('is-digitando');
        }
    });

    // ---- aba meta: "livre pra alocar" e barras recalculam enquanto se digita
    function parseBR(s) {
        if (s == null) { return 0; }
        s = String(s).replace(/[^\d.,-]/g, '').replace(/\./g, '').replace(',', '.');
        var n = parseFloat(s);
        return isFinite(n) ? n : 0;
    }
    function fmtBR(n) { return Math.round(n).toLocaleString('pt-BR'); }
    function clamp100(n) { return n < 0 ? 0 : (n > 100 ? 100 : n); }

    function metaRecalc(pg) {
        var renda = parseFloat(pg.getAttribute('data-renda')) || 0;
        var gastoTotal = parseFloat(pg.getAttribute('data-gasto-total')) || 0;
        var cats = pg.querySelectorAll('.meta-cat');
        var totalMeta = 0, metaEss = 0, metaNao = 0, gastoEss = 0, gastoNao = 0, nEss = 0, nNao = 0, gastoComMeta = 0;
        for (var i = 0; i < cats.length; i++) {
            var cat = cats[i];
            var input = cat.querySelector('input[name^="meta["]');
            var meta = input ? parseBR(input.value) : 0;
            var gasto = parseFloat(cat.getAttribute('data-gasto')) || 0;
            var grp = cat.getAttribute('data-grupo');
            if (grp === 'ess') { gastoEss += gasto; nEss++; } else { gastoNao += gasto; nNao++; }
            var fill = cat.querySelector('.meta-pbar-fill');
            var nums = cat.querySelector('[data-resumo]');
            cat.classList.remove('is-ok', 'is-near', 'is-over', 'is-none');
            if (meta > 0.004) {
                var r = gasto / meta;
                if (fill) { fill.style.setProperty('--pct', clamp100(r * 100) + '%'); }
                cat.classList.add(r > 1.0001 ? 'is-over' : (r >= 0.8 ? 'is-near' : 'is-ok'));
                var resto = meta - gasto;
                if (nums) {
                    nums.textContent = fmtBR(gasto) + ' de ' + fmtBR(meta) +
                        (resto >= -0.004 ? ', restam ' + fmtBR(Math.max(0, resto)) : ', passou ' + fmtBR(-resto));
                }
                totalMeta += meta;
                gastoComMeta += gasto;
                if (grp === 'ess') { metaEss += meta; } else { metaNao += meta; }
            } else {
                if (fill) { fill.style.setProperty('--pct', '0%'); }
                cat.classList.add('is-none');
                if (nums) { nums.textContent = gasto > 0.004 ? ('Gasto ' + fmtBR(gasto)) : 'Defina a meta'; }
            }
        }

        function setTxt(sel, txt) { var el = pg.querySelector(sel); if (el) { el.textContent = txt; } }
        function sub(tag, attr, v) { var el = pg.querySelector('[data-grupo-card="' + tag + '"] [' + attr + ']'); if (el) { el.textContent = fmtBR(v); } }
        sub('ess', 'data-grupo-meta', metaEss); sub('nao', 'data-grupo-meta', metaNao);
        sub('ess', 'data-grupo-gasto', gastoEss); sub('nao', 'data-grupo-gasto', gastoNao);
        var cardE = pg.querySelector('[data-grupo-card="ess"]'); if (cardE) { cardE.hidden = nEss === 0; }
        var cardN = pg.querySelector('[data-grupo-card="nao"]'); if (cardN) { cardN.hidden = nNao === 0; }
        var gastoSemMeta = Math.max(0, gastoTotal - gastoComMeta);
        var emConta = renda - gastoTotal;                 // saldo real (não depende das metas)
        var noVermelho = emConta < -0.004;
        setTxt('[data-v-metas]', fmtBR(gastoComMeta));
        setTxt('[data-v-metaalvo]', fmtBR(totalMeta));
        setTxt('[data-v-gasto]', fmtBR(gastoSemMeta));
        setTxt('[data-v-conta]', fmtBR(Math.abs(emConta)));
        setTxt('[data-conta-lbl]', noVermelho ? 'No vermelho' : 'Em conta');
        var cbox = pg.querySelector('[data-conta-box]');
        if (cbox) {
            cbox.classList.remove('is-neg', 'is-pos');
            cbox.classList.add(noVermelho ? 'is-neg' : 'is-pos');
        }

        var base = Math.max(renda, gastoTotal);
        var segM = pg.querySelector('[data-seg-metas]');
        if (base > 0.004 && renda > 0.004 && segM) {
            segM.style.setProperty('--w', clamp100(gastoComMeta / base * 100) + '%');
            var segG = pg.querySelector('[data-seg-gasto]');
            var segC = pg.querySelector('[data-seg-conta]');
            if (segG) { segG.style.setProperty('--w', clamp100(gastoSemMeta / base * 100) + '%'); }
            if (segC) { segC.style.setProperty('--w', clamp100(Math.max(0, emConta) / base * 100) + '%'); }
        }
        var det = pg.querySelector('[data-aloc-det]');
        if (det && !det.hidden) { metaAlocDet(pg); }
    }

    // detalhe da barra: subdivide o pedaço "gasto em metas" por categoria, em tons de tinta (ao clicar)
    var META_PAL = ['var(--m1)', 'var(--m2)', 'var(--m3)', 'var(--m4)', 'var(--m5)', 'var(--m6)'];
    function metaAlocDet(pg) {
        var det = pg.querySelector('[data-aloc-det]');
        var segM = pg.querySelector('[data-seg-metas]');
        if (!det) { return; }
        var cats = pg.querySelectorAll('.meta-cat');
        var rows = [], tot = 0;
        for (var i = 0; i < cats.length; i++) {
            var input = cats[i].querySelector('input[name^="meta["]');
            var meta = input ? parseBR(input.value) : 0;
            if (meta <= 0.004) { continue; }                       // só categorias com meta
            var gasto = parseFloat(cats[i].getAttribute('data-gasto')) || 0;
            if (gasto <= 0.004) { continue; }                      // só as que já têm gasto ocupam a barra
            rows.push({ nome: (cats[i].querySelector('.meta-cat-nome') || {}).textContent || '', gasto: gasto });
            tot += gasto;
        }
        rows.sort(function (a, b) { return b.gasto - a.gasto; });
        if (rows.length === 0 || tot <= 0) {
            if (segM) { segM.innerHTML = ''; }
            det.innerHTML = '<p class="aloc-det-vazio">Ainda não há gasto nas categorias com meta.</p>';
            return;
        }
        var segHtml = '', detHtml = '';
        for (var k = 0; k < rows.length; k++) {
            var r = rows[k];
            var color = META_PAL[k % META_PAL.length];
            var p = r.gasto / tot * 100;
            segHtml += '<span class="metas-sub" style="width:' + clamp100(p) + '%;background:' + color + '"></span>';
            detHtml += '<div class="aloc-det-row">' +
                '<span class="aloc-det-nome"><i class="dot" style="background:' + color + '"></i>' + escHtml(r.nome) + '</span>' +
                '<span class="aloc-det-pct num">' + Math.round(p) + '%</span>' +
                '</div>';
        }
        if (segM) { segM.innerHTML = segHtml; }
        det.innerHTML = detHtml;
    }
    function metaAlocLimpa(pg) {
        var segM = pg.querySelector('[data-seg-metas]');
        if (segM) { segM.innerHTML = ''; }
    }

    document.addEventListener('click', function (ev) {
        var bar = ev.target instanceof Element ? ev.target.closest('[data-meta-aloc]') : null;
        if (!bar) { return; }
        var pg = bar.closest('.meta-pg');
        var det = pg ? pg.querySelector('[data-aloc-det]') : null;
        if (!det) { return; }
        var mostrar = det.hidden;
        if (mostrar) { metaAlocDet(pg); } else { metaAlocLimpa(pg); }
        det.hidden = !mostrar;
        bar.setAttribute('aria-expanded', mostrar ? 'true' : 'false');
    });

    document.addEventListener('input', function (ev) {
        var el = ev.target;
        if (!(el instanceof Element) || !el.matches('input[name^="meta["]')) { return; }
        var pg = el.closest('.meta-pg');
        if (pg) { metaRecalc(pg); }
    });

    // ---- aba meta: escolher quais categorias entram (adicionar / tirar)
    function escHtml(s) {
        return String(s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }
    function tiraHidden(pg, id) {
        var hs = pg.querySelectorAll('.meta-form input[type="hidden"][name="meta[' + id + ']"]');
        for (var i = 0; i < hs.length; i++) { hs[i].parentNode.removeChild(hs[i]); }
    }
    function metaOpcaoDeVolta(pg, id, nome, grupo, gasto) {
        var sel = pg.querySelector('[data-meta-add]');
        if (!sel) { return; }
        var rotulo = grupo === 'ess' ? 'essenciais' : 'não essenciais';
        var og = null, ogs = sel.querySelectorAll('optgroup');
        for (var i = 0; i < ogs.length; i++) { if (ogs[i].label === rotulo) { og = ogs[i]; break; } }
        if (!og) {
            og = document.createElement('optgroup');
            og.label = rotulo;
            if (grupo === 'ess') { sel.insertBefore(og, sel.children[1] || null); } else { sel.appendChild(og); }
        }
        var opt = document.createElement('option');
        opt.value = id; opt.textContent = nome;
        opt.setAttribute('data-nome', nome);
        opt.setAttribute('data-grupo', grupo);
        opt.setAttribute('data-gasto', gasto);
        og.appendChild(opt);
        sel.disabled = false;
        var btn = pg.querySelector('.meta-add-btn'); if (btn) { btn.disabled = false; }
        var first = sel.querySelector('option[value=""]');
        if (first) { first.textContent = 'Escolher categoria…'; }
    }
    function metaAdiciona(pg, opt) {
        var id = opt.value;
        var tpl = pg.querySelector('#meta-cat-tpl');
        if (!id || !tpl) { return; }
        var grupo = opt.getAttribute('data-grupo') === 'ess' ? 'ess' : 'nao';
        var gasto = opt.getAttribute('data-gasto') || '0';
        var nome = opt.getAttribute('data-nome') || opt.textContent || '';
        tiraHidden(pg, id);
        var html = tpl.innerHTML.replace(/__ID__/g, id).replace(/__GRUPO__/g, grupo)
            .replace(/__GASTO__/g, gasto).replace(/__NOME__/g, escHtml(nome));
        var wrap = document.createElement('div');
        wrap.innerHTML = html.trim();
        var row = wrap.firstElementChild;
        var lista = pg.querySelector('.meta-lista[data-lista="' + grupo + '"]');
        if (!row || !lista) { return; }
        lista.appendChild(row);
        var og = opt.parentNode;
        og.removeChild(opt);
        if (og.tagName === 'OPTGROUP' && og.children.length === 0) { og.parentNode.removeChild(og); }
        var sel = pg.querySelector('[data-meta-add]');
        if (sel) { sel.value = ''; }
        metaRecalc(pg);
        var inp = row.querySelector('input'); if (inp) { inp.focus(); }
    }


    // ---- select[data-autosubmit]: muda e reenvia o form (ex.: ordenar o extrato)
    document.addEventListener('change', function (ev) {
        var el = ev.target;
        if (!(el instanceof Element) || !el.matches('select[data-autosubmit]')) { return; }
        var form = el.form;
        if (!form) { return; }
        if (typeof form.requestSubmit === 'function') { form.requestSubmit(); } else { form.submit(); }
    });

    document.addEventListener('click', function (ev) {
        var btn = ev.target instanceof Element ? ev.target.closest('[data-meta-rm]') : null;
        if (!btn) { return; }
        var pg = btn.closest('.meta-pg');
        var row = btn.closest('.meta-cat');
        if (!pg || !row) { return; }
        var inp = row.querySelector('input[name^="meta["]');
        var m = inp ? inp.name.match(/meta\[(\d+)\]/) : null;
        var id = m ? m[1] : null;
        var nome = (row.querySelector('.meta-cat-nome') || {}).textContent || '';
        var grupo = row.getAttribute('data-grupo') === 'ess' ? 'ess' : 'nao';
        var gasto = row.getAttribute('data-gasto') || '0';
        if (id) {
            tiraHidden(pg, id);
            var form = pg.querySelector('.meta-form');
            if (form) {
                var h = document.createElement('input');
                h.type = 'hidden'; h.name = 'meta[' + id + ']'; h.value = '';
                form.appendChild(h);
            }
        }
        row.parentNode.removeChild(row);
        if (id) { metaOpcaoDeVolta(pg, id, nome, grupo, gasto); }
        metaRecalc(pg);
    });

    function metaAddSetup(pg) {
        var form = pg.querySelector('[data-meta-add-form]');
        if (!form) { return; }
        // com JS, o botão adiciona na hora (sem recarregar); sem JS, o form manda ?add= e a página volta com ela
        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var sel = form.querySelector('select[data-meta-add]');
            var opt = sel ? sel.options[sel.selectedIndex] : null;
            if (opt && opt.value) { metaAdiciona(pg, opt); } else if (sel) { sel.focus(); }
        });
    }

    // ---- [data-confirm]: pede confirmação antes de enviar o form (ex.: remover categoria)
    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (form instanceof Element && form.matches('[data-confirm]')) {
            if (!window.confirm(form.getAttribute('data-confirm') || 'Confirmar?')) {
                ev.preventDefault();
            }
        }
    });

    // ---- ao carregar
    function iniciar() {
        syncTodosQuando();
        var pg = document.querySelector('.meta-pg');
        if (pg) { metaRecalc(pg); metaAddSetup(pg); }
        // foco automático: no computador, ou num campo com erro (no celular abriria o teclado sozinho)
        var autofocus = document.querySelector('[data-autofocus]');
        var fino = window.matchMedia && window.matchMedia('(pointer: fine)').matches;
        if (autofocus && typeof autofocus.focus === 'function' && (fino || autofocus.getAttribute('aria-invalid') === 'true')) {
            autofocus.focus();
        }
        // o aviso já vem na página: reescreve o texto para o leitor de tela anunciar
        var toastMsg = document.querySelector('[data-toast] .toast-msg');
        if (toastMsg) {
            var html = toastMsg.innerHTML;
            toastMsg.innerHTML = '';
            setTimeout(function () { toastMsg.innerHTML = html; }, 120);
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
    // voltar pelo histórico (bfcache) restaura o radio marcado sem disparar change
    window.addEventListener('pageshow', syncTodosQuando);
})();
