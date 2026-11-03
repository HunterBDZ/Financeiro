// app.js: comportamento da casca. Sem nada inline (CSP script-src 'self').
// - alterna o tema ([data-theme-toggle], nas telas de entrar) ou escolhe um ([data-tema="light|dark"]);
// - esconde/mostra os valores ([data-ocultar], o olho do cartão do saldo; só nesta aba);
// - [data-ic-sugerir]: no form de nova categoria, o ícone acompanha o nome digitado até a pessoa escolher um;
// - fecha details.menu ao clicar fora (e com Esc);
// - [data-quando]: o radio value="outro" revela .quando-data (fallback para navegador sem :has);
// - fecha o toast ([data-toast-fechar]);
// - esconde a barra inferior enquanto um campo tem foco (fallback sem :has);
// - [data-conf-app]: no conferir de várias contas, a diferença de cada conta para o app.
(function () {
    'use strict';

    var T = window.T || function (s) { return s; };   // a tela de login usa este script sem i18n.js
    var root = document.documentElement;

    // o tema em vigor: o escolhido ou, sem escolha, o do aparelho
    function temaAtual() {
        var t = root.getAttribute('data-theme');
        if (t === 'light' || t === 'dark') {
            return t;
        }
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    // o atalho claro/escuro vai para o outro lado do tema da conta (o layout diz quais são); logado, grava na conta
    function usarTema(novo) {
        var comToken = document.querySelector('[data-tema-csrf]');
        var lado = novo === 'light' ? 'claro' : 'escuro';
        var id = (comToken && comToken.getAttribute('data-lado-' + lado)) || lado;
        var cor = (comToken && comToken.getAttribute('data-bg-' + lado)) || (novo === 'light' ? '#F6F8FB' : '#0A1020');
        function aplicar() {
            root.setAttribute('data-theme', novo);
            root.setAttribute('data-tema', id);
            marcarTema();
        }
        if (window.MOV) { window.MOV.tema(aplicar); } else { aplicar(); }   // a troca de tema numa transição só
        try { localStorage.setItem('tema', id + ':' + novo); } catch (e) {}
        document.querySelectorAll('meta[name="theme-color"]').forEach(function (meta) {
            meta.setAttribute('content', cor);
        });
        if (comToken && window.fetch) {
            var corpo = new FormData();
            corpo.append('csrf_token', comToken.getAttribute('data-tema-csrf'));
            corpo.append('tema', id);
            corpo.append('silencioso', '1');
            fetch('/temas', { method: 'POST', body: corpo, credentials: 'same-origin' }).catch(function () {});
        }
    }
    function trocarTema() {
        usarTema(temaAtual() === 'light' ? 'dark' : 'light');
    }
    function marcarTema() {                       // aria-pressed no seletor Claro/Escuro
        var t = temaAtual();
        document.querySelectorAll('button[data-tema]').forEach(function (b) {
            b.setAttribute('aria-pressed', b.getAttribute('data-tema') === t ? 'true' : 'false');
        });
    }
    marcarTema();

    // o olho do saldo: esconde os números da página (só nesta aba)
    function marcarOlho() {
        var oculto = root.classList.contains('valores-ocultos');
        document.querySelectorAll('[data-ocultar]').forEach(function (b) {
            b.setAttribute('aria-pressed', oculto ? 'true' : 'false');
            b.setAttribute('aria-label', oculto ? T('mostrar valores') : T('esconder valores'));
        });
    }
    marcarOlho();

    // ---- ícone sugerido pelo nome da categoria nova (as regras vêm de Icones::REGRAS, no data-ic-regras)
    function normalizar(s) {
        return String(s).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
    }
    function casaTermo(termo, norm, palavras) {
        if (termo.charAt(0) === '=') { return palavras.indexOf(termo.slice(1)) !== -1; }
        if (termo.indexOf(' ') !== -1) { return (' ' + norm + ' ').indexOf(' ' + termo) !== -1; }
        for (var i = 0; i < palavras.length; i++) { if (palavras[i].indexOf(termo) === 0) { return true; } }
        return false;
    }
    function sugerirIcone(cfg, nome, grupo) {
        var norm = normalizar(nome);
        var palavras = norm === '' ? [] : norm.split(' ');
        for (var r = 0; r < cfg.regras.length; r++) {
            var termos = cfg.regras[r][1];
            for (var t = 0; t < termos.length; t++) {
                if (casaTermo(termos[t], norm, palavras)) { return cfg.regras[r][0]; }
            }
        }
        return cfg.grupos[grupo] || 'sacola';
    }
    document.querySelectorAll('[data-ic-sugerir]').forEach(function (picker) {
        var cfg;
        try { cfg = JSON.parse(picker.getAttribute('data-ic-regras') || ''); } catch (e) { return; }
        var form = picker.closest('form');
        var campo = form ? form.querySelector('input[name="nome"]') : null;
        if (!campo) { return; }
        var escolheu = false;
        picker.addEventListener('change', function () { escolheu = true; });
        function atualizar() {
            if (escolheu) { return; }
            var tipo = form.querySelector('input[name="tipo"]:checked');
            var grupo = tipo && tipo.value === 'entrada' ? 'Entradas' : 'Dia a dia';
            var nome = campo.value.trim();
            var marcado = picker.querySelector('input[name="icone"]:checked');
            if (nome === '') { if (marcado) { marcado.checked = false; } return; }
            var alvo = picker.querySelector('input[name="icone"][value="' + sugerirIcone(cfg, nome, grupo) + '"]');
            if (alvo && alvo !== marcado) { alvo.checked = true; }
        }
        campo.addEventListener('input', atualizar);
        form.addEventListener('change', function (ev) {
            if (ev.target instanceof Element && ev.target.matches('input[name="tipo"]')) { atualizar(); }
        });
    });

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

        var escolha = alvo.closest('button[data-tema]');
        if (escolha) {
            var tema = escolha.getAttribute('data-tema');
            if (tema === 'light' || tema === 'dark') {
                usarTema(tema);
            }
            return;
        }

        if (alvo.closest('[data-ocultar]')) {
            var oculto = root.classList.toggle('valores-ocultos');
            try {
                if (oculto) { sessionStorage.setItem('ocultar', '1'); } else { sessionStorage.removeItem('ocultar'); }
            } catch (e) {}
            marcarOlho();
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

    // ---- aba Metas: o detalhe da barra (ao clicar) e o período do editor
    function clamp100(n) { return n < 0 ? 0 : (n > 100 ? 100 : n); }
    function escHtml(s) {
        return String(s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    // detalhe da barra: subdivide o pedaço "gasto em metas" por categoria, em tons de tinta
    var META_PAL = ['var(--m1)', 'var(--m2)', 'var(--m3)', 'var(--m4)', 'var(--m5)', 'var(--m6)'];
    function metaAlocDet(pg) {
        var det = pg.querySelector('[data-aloc-det]');
        var segM = pg.querySelector('[data-seg-metas]');
        if (!det) { return; }
        var linhas = pg.querySelectorAll('.meta-cat[data-valor]');
        var rows = [], tot = 0;
        for (var i = 0; i < linhas.length; i++) {
            var valor = parseFloat(linhas[i].getAttribute('data-valor')) || 0;
            var gasto = parseFloat(linhas[i].getAttribute('data-gasto')) || 0;
            if (valor <= 0.004 || gasto <= 0.004) { continue; }   // só o que tem meta e já tem gasto ocupa a barra
            rows.push({ nome: linhas[i].getAttribute('data-nome') || '', gasto: gasto });
            tot += gasto;
        }
        rows.sort(function (a, b) { return b.gasto - a.gasto; });
        if (rows.length === 0 || tot <= 0) {
            if (segM) { segM.innerHTML = ''; }
            det.innerHTML = '<p class="aloc-det-vazio">' + escHtml(T('Ainda não há gasto nas categorias com meta.')) + '</p>';
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

    document.addEventListener('click', function (ev) {
        var bar = ev.target instanceof Element ? ev.target.closest('[data-meta-aloc]') : null;
        if (!bar) { return; }
        var pg = bar.closest('.meta-pg');
        var det = pg ? pg.querySelector('[data-aloc-det]') : null;
        if (!det) { return; }
        var mostrar = det.hidden;
        if (mostrar) {
            metaAlocDet(pg);
        } else {
            var segM = pg.querySelector('[data-seg-metas]');
            if (segM) { segM.innerHTML = ''; }
        }
        det.hidden = !mostrar;
        bar.setAttribute('aria-expanded', mostrar ? 'true' : 'false');
    });

    // "Até" só oferece meses depois de "A partir de" (o servidor recusa o contrário de qualquer jeito)
    function metaPeriodo(desde) {
        var form = desde.form;
        var ate = form ? form.querySelector('[data-meta-ate]') : null;
        if (!ate) { return; }
        for (var i = 0; i < ate.options.length; i++) {
            var o = ate.options[i];
            if (/^\d{4}-\d{2}-01$/.test(o.value)) {
                o.disabled = o.value <= desde.value;
            }
        }
        if (ate.selectedOptions && ate.selectedOptions[0] && ate.selectedOptions[0].disabled) { ate.value = ''; }
    }
    document.addEventListener('change', function (ev) {
        var el = ev.target;
        if (el instanceof Element && el.matches('[data-meta-desde]')) { metaPeriodo(el); }
    });
    // ---- select[data-autosubmit]: muda e reenvia o form (ex.: ordenar o extrato)
    document.addEventListener('change', function (ev) {
        var el = ev.target;
        if (!(el instanceof Element) || !el.matches('select[data-autosubmit]')) { return; }
        var form = el.form;
        if (!form) { return; }
        if (typeof form.requestSubmit === 'function') { form.requestSubmit(); } else { form.submit(); }
    });

    // ---- Ajuda: o "?" abre a resposta; a busca esconde o que não tem as palavras, também no índice
    (function () {
        var alvo = location.hash.length > 1 ? document.getElementById(location.hash.slice(1)) : null;
        if (alvo && alvo.matches('[data-ajuda-item]')) { alvo.open = true; alvo.scrollIntoView({ block: 'center' }); }
        var busca = document.querySelector('[data-ajuda-busca]');
        if (!busca) return;
        var sem = function (t) { return t.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase(); };
        busca.addEventListener('input', function () {
            var q = sem(busca.value.trim()), algum = false;
            document.querySelectorAll('[data-ajuda-grupo]').forEach(function (g) {
                var noGrupo = false;
                g.querySelectorAll('[data-ajuda-item]').forEach(function (i) {
                    var ok = sem(i.textContent).indexOf(q) !== -1;
                    i.hidden = !ok; noGrupo = noGrupo || ok;
                });
                g.hidden = !noGrupo; algum = algum || noGrupo;
            });
            document.querySelector('[data-ajuda-vazio]').hidden = algum;
            // o índice "Nesta página" mostra só o que sobrou: cada link some junto com a resposta (ou o grupo) dele
            document.querySelectorAll('.ajuda-indice a[href^="#"]').forEach(function (a) {
                var alvo = document.getElementById(a.getAttribute('href').slice(1));
                var item = a.parentElement;
                item.hidden = !!alvo && alvo.hidden;
                if (item.matches('.ajuda-indice-g') && item.nextElementSibling) { item.nextElementSibling.hidden = item.hidden; }
            });
        });
    })();

    // ---- [data-confirm]: pede confirmação antes de enviar o form (ex.: remover categoria)
    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (form instanceof Element && form.matches('[data-confirm]')) {
            if (!window.confirm(form.getAttribute('data-confirm') || T('Confirmar?'))) {
                ev.preventDefault();
            }
        }
    });

    // ---- [data-espera]: o que demora (backup, PDF, atualizar) mostra "Gerando…" e não aceita o segundo clique.
    // Vem depois do [data-confirm]: quem cancelou a pergunta não fica esperando. Um download não troca de
    // página, então [data-espera-baixa] volta ao normal sozinho; o pageshow cobre quem volta pelo histórico.
    var esperaOriginal = new WeakMap();
    function esperaLiga(botao, texto) {
        if (!botao || esperaOriginal.has(botao)) { return; }
        esperaOriginal.set(botao, botao.innerHTML);
        botao.setAttribute('aria-busy', 'true');
        botao.setAttribute('aria-disabled', 'true');
        var circ = botao.querySelector('.circ');   // o círculo do Pagar fica, e se preenche (movimento.js)
        botao.textContent = texto;
        if (circ) { botao.insertBefore(circ, botao.firstChild); }
    }
    function esperaDesliga(botao) {
        if (!botao || !esperaOriginal.has(botao)) { return; }
        botao.innerHTML = esperaOriginal.get(botao);
        esperaOriginal.delete(botao);
        botao.removeAttribute('aria-busy');
        botao.removeAttribute('aria-disabled');
    }
    function esperaComeca(dono, botao) {
        if (dono.hasAttribute('data-esperando')) { return false; }
        dono.setAttribute('data-esperando', '');
        esperaLiga(botao, dono.getAttribute('data-espera') || T('Aguarde…'));
        if (dono.hasAttribute('data-espera-baixa')) {
            setTimeout(function () { esperaTermina(dono); }, 6000);
        }
        return true;
    }
    function esperaTermina(dono) {
        dono.removeAttribute('data-esperando');
        esperaDesliga(dono.matches('form') ? dono.querySelector('[type="submit"]') : dono);
    }
    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-espera]')) { return; }
        if (form.hasAttribute('data-esperando')) { ev.preventDefault(); return; }
        if (ev.defaultPrevented) { return; }
        esperaComeca(form, ev.submitter || form.querySelector('[type="submit"]'));
    });
    document.addEventListener('click', function (ev) {
        var a = ev.target instanceof Element ? ev.target.closest('a[data-espera]') : null;
        if (!a || ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) { return; }
        if (!esperaComeca(a, a)) { ev.preventDefault(); }
    });
    window.addEventListener('pageshow', function () {
        document.querySelectorAll('[data-esperando]').forEach(esperaTermina);
    });

    // ---- [data-arquivo]: o botão próprio de escolher arquivo mostra o nome escolhido
    document.addEventListener('change', function (ev) {
        var campo = ev.target;
        if (!(campo instanceof Element) || !campo.matches('[data-arquivo]')) {
            return;
        }
        var nome = campo.form && campo.form.querySelector('[data-arquivo-nome]');
        if (nome) {
            nome.textContent = campo.files && campo.files[0] ? campo.files[0].name : '';
        }
    });

    // ---- ao carregar
    function iniciar() {
        syncTodosQuando();
        // foco automático: no computador, ou num campo com erro (no celular abriria o teclado sozinho)
        var autofocus = document.querySelector('[data-autofocus]');
        var fino = window.matchMedia && window.matchMedia('(pointer: fine)').matches;
        if (autofocus && typeof autofocus.focus === 'function' && (fino || autofocus.getAttribute('aria-invalid') === 'true')) {
            autofocus.focus();
        }
        // o aviso já vem na página: uma cópia invisível entra depois, para o leitor de tela anunciar.
        // O texto à vista não sai do lugar (apagar e repor deixava a caixa vazia por um instante).
        var toastMsg = document.querySelector('[data-toast] .toast-msg');
        if (toastMsg) {
            var linhas = toastMsg.querySelector('.toast-linhas');
            var copia = document.createElement('span');
            copia.className = 'sr-only';
            toastMsg.appendChild(copia);
            setTimeout(function () {
                copia.textContent = linhas.textContent;
                linhas.setAttribute('aria-hidden', 'true');
            }, 120);
        }
    }
    // link para uma linha dentro de um grupo recolhido do Mês: abre o grupo
    function abrirGrupoDoLink() {
        var alvo = location.hash.length > 1 ? document.getElementById(location.hash.slice(1)) : null;
        var grupo = alvo ? alvo.closest('details.mes-sem-mov') : null;
        if (grupo) { grupo.open = true; alvo.scrollIntoView({ block: 'center' }); }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
        document.addEventListener('DOMContentLoaded', abrirGrupoDoLink);
    } else {
        iniciar();
        abrirGrupoDoLink();
    }
    // voltar pelo histórico (bfcache) restaura o radio marcado sem disparar change
    window.addEventListener('pageshow', syncTodosQuando);

    // conferir várias contas: a diferença de cada uma para o saldo do app, enquanto se digita. Em "outro dia",
    // o saldo do app é de outra data: a conta fica para o servidor
    function difConta(input) {
        var out = input.parentNode.querySelector('[data-conf-dif]');
        if (!out) { return; }
        var outroDia = input.form && input.form.querySelector('input[name="quando"][value="outro"]:checked');
        var txt = input.value.trim();
        if (txt === '' || outroDia) { out.textContent = ''; return; }
        var dif = Math.round((FIN.ler(txt) - parseFloat(input.getAttribute('data-conf-app'))) * 100) / 100;
        out.textContent = Math.abs(dif) < 0.005 ? T('Bate') : T('Diferença de {valor}', { valor: (dif > 0 ? '+' : '−') + FIN.num(Math.abs(dif)) });
        out.className = 'conf-dif num ' + (Math.abs(dif) < 0.005 ? 'pos' : 'neg');
    }
    document.addEventListener('input', function (ev) {
        if (ev.target instanceof Element && ev.target.matches('[data-conf-app]')) { difConta(ev.target); }
    });
    document.addEventListener('change', function (ev) {
        if (ev.target instanceof Element && ev.target.matches('input[name="quando"]') && ev.target.form) {
            ev.target.form.querySelectorAll('[data-conf-app]').forEach(difConta);
        }
    });
})();
