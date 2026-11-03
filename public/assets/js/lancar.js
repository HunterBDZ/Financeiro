// lancar.js: o "+" abre o <dialog id="lancar"> e foca o valor NO MESMO
// toque — o iOS só abre o teclado dentro do gesto do usuário. Também: [data-categoria],
// [data-valor], [data-descricao], [data-data] preenchem o form; tecla "n" (desktop); fechar
// (×, Esc, toque fora); checagem mínima antes de enviar + trava de duplo envio; o valor estilo
// maquininha (só dígitos, vírgula automática); o campo da categoria (combobox: abre a lista ao clicar,
// filtra ao digitar, setas, Enter e Esc; sem JS fica o <select>); com várias contas, o "Pagou com" /
// "Recebeu em" e o modo Transferir; as 3 categorias usadas por último num toque; o valor previsto sugerido
// ao escolher uma conta fixa; o erro no próprio campo; o filtro do extrato. Sem nada inline (CSP
// script-src 'self'). Sai sem fazer nada quando a página não tem #lancar, form[data-lf] nem filtro.
(function () {
    'use strict';

    var dlg = document.getElementById('lancar');
    if (!dlg && !document.querySelector('form[data-lf], #ex-filtro-form')) {
        return;
    }
    var temModal = !!(dlg && typeof dlg.showModal === 'function');

    function formDoDialogo() {
        return dlg ? dlg.querySelector('form[data-lf]') : null;
    }

    // /lancar e a coluna do extrato (desktop): o "+" usa o form que já está na tela
    function formPaginaVisivel() {
        var fs = document.querySelectorAll('form[data-lf][data-lf-modo="pagina"], form[data-lf][data-lf-modo="coluna"]');
        for (var i = 0; i < fs.length; i++) {
            if (fs[i].offsetParent !== null) {
                return fs[i];
            }
        }
        return null;
    }

    function valorDe(form) {
        return form.querySelector('[data-lf-valor]');
    }

    // a categoria: o <select> leva o valor; com JS, quem aparece é o combobox no lugar dele
    function campoCategoria(form) {
        var caixa = form.querySelector('[data-lf-combo-in]');
        if (caixa && !caixa.closest('[hidden]')) {
            return caixa;
        }
        return form.querySelector('select[name="categoria_id"]');
    }

    function categoriaDe(form) {
        var sel = form.querySelector('select[name="categoria_id"]');
        return sel && sel.value !== '' ? sel.value : null;
    }

    function marcarCategoria(form, id) {
        var sel = form.querySelector('select[name="categoria_id"]');
        if (sel) {
            sel.value = String(id).replace(/[^0-9]/g, '');
            var combo = form.querySelector('[data-lf-combo]');
            if (combo && combo.lfSincronizar) {
                combo.lfSincronizar();
            }
            contas(form);
            sugerir(form);
        }
    }

    // o erro fica no campo de que ele fala (campo: 'valor' ou 'categoria'); sem campo, no aviso de baixo.
    // Sem texto, limpa todos
    function mostrarErro(form, texto, campo) {
        form.querySelectorAll('[data-lf-erro-de]').forEach(function (p) {
            var este = !!texto && p.getAttribute('data-lf-erro-de') === campo;
            p.textContent = este ? texto : '';
            p.hidden = !este;
            var alvos = p.getAttribute('data-lf-erro-de') === 'valor'
                ? [valorDe(form)]
                : [form.querySelector('[data-lf-combo-in]'), form.querySelector('select[name="categoria_id"]')];
            alvos.forEach(function (el) {
                if (!el) { return; }
                if (este) {
                    el.setAttribute('aria-invalid', 'true');
                    el.setAttribute('aria-describedby', p.id);
                } else if (el.getAttribute('aria-describedby') === p.id) {
                    el.removeAttribute('aria-invalid');
                    el.removeAttribute('aria-describedby');
                }
            });
        });
        var box = form.querySelector('[data-lf-erro]');
        if (box) {
            var geral = !!texto && !form.querySelector('[data-lf-erro-de="' + campo + '"]');
            box.textContent = geral ? texto : '';
            box.hidden = !geral;
        }
    }

    // a conta fixa do mês sugere o valor previsto que falta pagar: entra no campo vazio (ou no lugar de outra
    // sugestão) e sai quando a pessoa digita. Os botões das usadas por último acompanham a escolha
    function sugerir(form) {
        var sel = form.querySelector('select[name="categoria_id"]');
        var v = valorDe(form);
        var dica = form.querySelector('[data-lf-sugestao]');
        var op = sel && sel.selectedIndex >= 0 ? sel.options[sel.selectedIndex] : null;
        var prev = op ? op.getAttribute('data-lf-previsto') : null;
        if (v && (v.value === '' || v.hasAttribute('data-lf-sugerido'))) {
            if (prev) {
                v.value = prev;
                v.setAttribute('data-lf-sugerido', '');
            } else if (v.hasAttribute('data-lf-sugerido')) {
                v.value = '';
                v.removeAttribute('data-lf-sugerido');
            }
            v.dispatchEvent(new Event('input', { bubbles: true }));
            if (prev) { v.setAttribute('data-lf-sugerido', ''); }
        }
        if (dica) {
            dica.hidden = !(v && v.hasAttribute('data-lf-sugerido'));
        }
        form.querySelectorAll('[data-lf-recente]').forEach(function (b) {
            b.setAttribute('aria-pressed', sel && b.getAttribute('data-lf-recente') === sel.value ? 'true' : 'false');
        });
    }

    function destravar(form) {
        form.removeAttribute('data-enviando');
        var b = form.querySelector('[data-lf-enviar]');
        if (b) {
            b.removeAttribute('aria-disabled');
        }
    }

    function aplicar(form, origem) {
        if (!origem) {
            return;
        }
        var cat = origem.getAttribute('data-categoria');
        var val = origem.getAttribute('data-valor');
        var desc = origem.getAttribute('data-descricao');
        if (cat) {
            marcarCategoria(form, cat);
        }
        if (val) {
            valorDe(form).value = val;
            valorDe(form).removeAttribute('data-lf-sugerido');     // o valor veio de quem abriu, não é sugestão
            var dica = form.querySelector('[data-lf-sugestao]');
            if (dica) { dica.hidden = true; }
        }
        if (desc !== null) {
            var d = form.querySelector('input[name="descricao"]');
            if (d) {
                d.value = desc;
            }
        }
        // [data-pagou="cartao"] (ou cartao:id): abre com "Pagou com: Cartão" (do botão da aba Cartão); sem ele, volta à
        // conta padrão (com várias contas, a da última vez)
        var dp = (origem.getAttribute('data-pagou') || '').replace(/[^a-z0-9:]/g, '');
        var pagou = dp.indexOf('cartao') === 0
            ? form.querySelector('input[name="pagou"][value="' + dp + '"]') || form.querySelector('input[name="pagou"][value="cartao"], input[name="pagou"][value^="cartao:"]')
            : form.querySelector('input[name="pagou"][data-lf-padrao]') || form.querySelector('input[name="pagou"][value="conta"]');
        if (pagou && !pagou.checked) {
            pagou.checked = true;
            pagou.dispatchEvent(new Event('change', { bubbles: true }));
        }
        var lancar = form.querySelector('input[name="modo"][value="lancar"]');
        if (lancar && !lancar.checked) {
            lancar.checked = true;
            lancar.dispatchEvent(new Event('change', { bubbles: true }));
        }
        // [data-data="AAAA-MM-DD"]: "outro dia" com essa data (ex.: conta de um mês que ainda não começou)
        var dt = origem.getAttribute('data-data');
        if (dt && /^\d{4}-\d{2}-\d{2}$/.test(dt)) {
            var outro = form.querySelector('[data-quando] input[name="quando"][value="outro"]');
            var campo = form.querySelector('[data-quando] input[name="data"]');
            if (outro && campo) {
                outro.checked = true;
                campo.value = dt;
                var fsq = form.querySelector('[data-quando]');
                if (fsq) {
                    fsq.classList.add('is-outro');
                }
            }
        }
    }

    function focarValor(form) {
        var v = valorDe(form);
        if (v) {
            v.focus();
            v.select();
        }
    }

    // true = tratou (o link não navega)
    function abrir(origem) {
        var pagina = formPaginaVisivel();
        if (pagina) {
            aplicar(pagina, origem);
            focarValor(pagina);
            return true;
        }
        var form = formDoDialogo();
        if (!temModal || !form) {
            return false;                       // sem <dialog>: segue o href (/lancar?…)
        }
        if (!dlg.open) {
            mostrarErro(form, '');              // o que já foi digitado fica (fechar sem querer não perde nada)
            destravar(form);
            dlg.showModal();                    // 1. abre
        }
        aplicar(form, origem);                  // 2. categoria/valor/descrição do gatilho
        var volta = form.querySelector('[data-lf-volta]');
        if (volta) {
            volta.value = location.pathname + location.search;
        }
        focarValor(form);                       // 3. foco no mesmo toque: o teclado abre
        return true;
    }

    function fechar() {
        if (dlg && dlg.open) {
            dlg.close();
        }
    }

    // ---- cliques: [data-lancar] abre; [data-lancar-fechar] fecha
    document.addEventListener('click', function (ev) {
        var t = ev.target;
        if (!(t instanceof Element)) {
            return;
        }
        var gatilho = t.closest('[data-lancar]');
        if (gatilho) {
            if (ev.defaultPrevented || ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) {
                return;                         // ctrl/⌘-clique: /lancar em outra aba
            }
            if (abrir(gatilho)) {
                ev.preventDefault();
            }
            return;
        }
        if (t.closest('[data-lancar-fechar]')) {
            fechar();
            return;
        }
    });

    // toque no fundo escurecido fecha (no celular a folha fica presa no topo)
    if (dlg) {
        dlg.addEventListener('click', function (ev) {
            if (ev.target !== dlg) {
                return;
            }
            var r = dlg.getBoundingClientRect();
            var dentro = ev.clientX >= r.left && ev.clientX <= r.right && ev.clientY >= r.top && ev.clientY <= r.bottom;
            if (!dentro) {
                fechar();
            }
        });
    }

    document.addEventListener('keydown', function (ev) {
        var a = document.activeElement;
        var emCampo = !!(a && (a.isContentEditable || /^(INPUT|SELECT|TEXTAREA)$/.test(a.tagName)));

        // Enter no valor sem categoria: não envia; vai para a categoria, com a lista aberta
        if (ev.key === 'Enter' && a && a.matches && a.matches('[data-lf-valor]')) {
            var form = a.form;
            if (form && !categoriaDe(form)) {
                ev.preventDefault();
                var sel = campoCategoria(form);
                if (sel) {
                    sel.focus();
                    var combo = form.querySelector('[data-lf-combo]');
                    if (combo && combo.lfAbrir && sel.matches('[data-lf-combo-in]')) {
                        combo.lfAbrir();
                    }
                }
            }
            return;
        }

        // "n" abre o lançar quando o foco não está num campo (desktop)
        if ((ev.key === 'n' || ev.key === 'N') && !emCampo && !ev.ctrlKey && !ev.metaKey && !ev.altKey && !ev.isComposing) {
            if ((dlg && dlg.open) || document.querySelector('details.menu[open]')) {
                return;
            }
            if (abrir(null)) {
                ev.preventDefault();
            }
        }
    });

    function transferindo(form) {
        var m = form.querySelector('input[name="modo"]:checked');
        return !!m && m.value === 'transferir';
    }

    // várias contas: numa entrada, "Recebeu em" e sem os cartões; Transferir troca o rótulo do botão
    function contas(form) {
        var fs = form.querySelector('.ct-pagou.is-varias');
        if (!fs) {
            return;
        }
        var sel = form.querySelector('select[name="categoria_id"]');
        var cat = sel && sel.selectedIndex >= 0 ? sel.options[sel.selectedIndex] : null;
        var entra = !!(cat && cat.hasAttribute('data-lf-entra'));
        var rot = fs.querySelector('[data-pagou-rot]');
        if (rot) {
            rot.textContent = rot.getAttribute(entra ? 'data-entrada' : 'data-gasto');
        }
        fs.querySelectorAll('[data-so-gasto]').forEach(function (l) { l.hidden = entra; });
        var marcado = fs.querySelector('input[name="pagou"]:checked');
        if (entra && marcado && marcado.closest('[data-so-gasto]')) {
            var volta = fs.querySelector('input[data-lf-padrao]');
            volta = volta && !volta.closest('[data-so-gasto]') ? volta : fs.querySelector('input[name="pagou"]:not([value^="cartao"])');
            if (volta) {
                volta.checked = true;
                volta.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
        var b = form.querySelector('[data-lf-enviar]');
        if (b && b.hasAttribute('data-transf-rot')) {
            if (!b.hasAttribute('data-lf-rot')) {
                b.setAttribute('data-lf-rot', transferindo(form) ? T('Lançar') : b.textContent);
            }
            var noCartao = !!(marcado && /^cartao/.test(marcado.value));
            b.textContent = transferindo(form) ? b.getAttribute('data-transf-rot') : (noCartao ? b.getAttribute('data-ct-rot') : b.getAttribute('data-lf-rot'));
        }
    }
    document.querySelectorAll('form[data-lf]').forEach(contas);
    document.addEventListener('change', function (ev) {
        var el = ev.target;
        if (el instanceof Element && el.form && el.form.matches('form[data-lf]') && /^(categoria_id|pagou|modo)$/.test(el.getAttribute('name') || '')) {
            contas(el.form);
        }
    });

    // escolher a categoria some com o aviso e sugere o valor da conta fixa
    document.addEventListener('change', function (ev) {
        var el = ev.target;
        if (el instanceof Element && el.matches('form[data-lf] [name="categoria_id"]') && el.form) {
            mostrarErro(el.form, '');
            sugerir(el.form);
        }
    });

    // as usadas por último: um toque escolhe a categoria (sem JS, ficam escondidas e vale o campo)
    document.querySelectorAll('form[data-lf] [data-lf-recentes]').forEach(function (g) {
        g.hidden = false;
        g.addEventListener('click', function (ev) {
            var b = ev.target instanceof Element ? ev.target.closest('[data-lf-recente]') : null;
            var form = g.closest('form');
            var sel = form ? form.querySelector('select[name="categoria_id"]') : null;
            if (!b || !sel) { return; }
            sel.value = b.getAttribute('data-lf-recente');
            var combo = form.querySelector('[data-lf-combo]');
            if (combo && combo.lfSincronizar) { combo.lfSincronizar(); }
            sel.dispatchEvent(new Event('change', { bubbles: true }));
        });
    });

    // o combobox da categoria (padrão do ARIA APG: input com role=combobox, listbox, aria-activedescendant).
    // O <select> continua no form e leva o valor; a caixa só mostra e escolhe. Clicar abre a lista inteira,
    // digitar filtra (sem acento e sem caixa), setas andam, Enter escolhe, Esc fecha e volta ao escolhido.
    var semAcento = function (s) { return s.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); };
    document.querySelectorAll('[data-lf-combo]').forEach(function (combo) {
        var sel = combo.querySelector('select[data-lf-cat]');
        var caixa = combo.querySelector('[data-lf-combo-caixa]');
        var input = combo.querySelector('[data-lf-combo-in]');
        var lista = combo.querySelector('[data-lf-combo-lista]');
        var icone = combo.querySelector('[data-lf-combo-ic]');
        var vazio = combo.querySelector('[data-lf-combo-vazio]');
        if (!sel || !caixa || !input || !lista) {
            return;
        }
        var ops = Array.prototype.slice.call(lista.querySelectorAll('[role="option"]'));
        var ativo = null;
        var digitou = false;

        // troca o <select> pela caixa; o rótulo passa a apontar para ela
        sel.hidden = true;
        caixa.hidden = false;
        var rot = combo.parentNode.querySelector('[data-lf-cat-rot]');
        if (rot) {
            rot.htmlFor = input.id;
        }

        function aberta() { return !lista.hidden; }
        function visiveis() { return ops.filter(function (o) { return !o.hidden; }); }
        function opDe(valor) {
            for (var i = 0; i < ops.length; i++) {
                if (ops[i].getAttribute('data-value') === valor) { return ops[i]; }
            }
            return null;
        }
        function nomeDe(op) { var n = op.querySelector('span:last-child'); return (n || op).textContent; }

        function marcarAtivo(op) {
            if (ativo) { ativo.classList.remove('is-ativo'); }
            ativo = op;
            if (op) {
                op.classList.add('is-ativo');
                input.setAttribute('aria-activedescendant', op.id);
                op.scrollIntoView({ block: 'nearest' });
            } else {
                input.removeAttribute('aria-activedescendant');
            }
        }

        function filtrar(q) {
            q = semAcento(q.trim());
            lista.querySelectorAll('[data-lf-combo-g]').forEach(function (g) {
                var n = 0;
                g.querySelectorAll('[role="option"]').forEach(function (op) {
                    var ok = q === '' || semAcento(op.getAttribute('data-busca') || '').indexOf(q) !== -1;
                    op.hidden = !ok;
                    if (ok) { n++; }
                });
                g.hidden = n === 0;
            });
            var achou = visiveis().length;
            if (vazio) { vazio.hidden = achou > 0; }
            return achou;
        }

        function abrir() {
            if (!aberta()) {
                filtrar('');
                lista.hidden = false;
                input.setAttribute('aria-expanded', 'true');
            }
            marcarAtivo(opDe(sel.value) || visiveis()[0] || null);
        }

        function fechar() {
            lista.hidden = true;
            if (vazio) { vazio.hidden = true; }
            input.setAttribute('aria-expanded', 'false');
            marcarAtivo(null);
            digitou = false;
        }

        // a caixa mostra o que está no <select>: nome, ícone e a marca na lista
        function sincronizar() {
            var op = opDe(sel.value);
            input.value = op ? nomeDe(op) : '';
            ops.forEach(function (o) { o.setAttribute('aria-selected', o === op ? 'true' : 'false'); });
            if (icone) {
                icone.textContent = '';
                var ic = op ? op.querySelector('.ci') : null;
                if (ic) { icone.appendChild(ic.cloneNode(true)); }
            }
        }

        function escolher(op) {
            var mudou = sel.value !== op.getAttribute('data-value');
            sel.value = op.getAttribute('data-value');
            sincronizar();
            fechar();
            if (mudou) {
                sel.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        combo.lfSincronizar = sincronizar;
        combo.lfAbrir = abrir;

        input.addEventListener('focus', function () { input.select(); });
        input.addEventListener('click', function () {
            if (aberta() && !digitou) { fechar(); } else { abrir(); }
        });
        input.addEventListener('input', function () {
            digitou = true;
            if (!aberta()) {
                lista.hidden = false;
                input.setAttribute('aria-expanded', 'true');
            }
            filtrar(input.value);
            marcarAtivo(visiveis()[0] || null);
        });
        input.addEventListener('keydown', function (ev) {
            var vs = visiveis();
            var i = ativo ? vs.indexOf(ativo) : -1;
            if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
                ev.preventDefault();
                if (!aberta()) { abrir(); return; }
                if (vs.length) {
                    i = ev.key === 'ArrowDown' ? Math.min(vs.length - 1, i + 1) : Math.max(0, i - 1);
                    marcarAtivo(vs[i]);
                }
            } else if (ev.key === 'Enter') {
                if (aberta()) {
                    ev.preventDefault();            // Enter na lista escolhe; não envia o lançamento
                    if (ativo) { escolher(ativo); }
                }
            } else if (ev.key === 'Escape') {
                if (aberta() || input.value !== (opDe(sel.value) ? nomeDe(opDe(sel.value)) : '')) {
                    ev.preventDefault();            // só fecha a lista, não o diálogo
                    ev.stopPropagation();
                    fechar();
                    sincronizar();
                }
            } else if (ev.key === 'Tab' && aberta() && digitou && ativo) {
                escolher(ativo);                    // digitou e saiu com Tab: fica a primeira que serve
            }
        });
        // ao sair: o que foi digitado vira a categoria se só uma serve; senão, volta à escolhida
        input.addEventListener('blur', function () {
            if (digitou) {
                var vs = visiveis();
                if (vs.length === 1 && input.value.trim() !== '') {
                    escolher(vs[0]);
                    return;
                }
            }
            fechar();
            sincronizar();
        });
        // tocar na lista não tira o foco da caixa (senão o blur fecharia antes do clique)
        lista.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
        lista.addEventListener('click', function (ev) {
            var op = ev.target instanceof Element ? ev.target.closest('[role="option"]') : null;
            if (!op) { return; }
            escolher(op);
            if (!window.matchMedia('(pointer: fine)').matches) {
                input.blur();                       // no celular, o teclado fecha depois de escolher
            }
        });
        caixa.addEventListener('click', function (ev) {
            if (ev.target === caixa || (icone && icone.contains(ev.target))) { input.focus(); abrir(); }
        });
        // o <select> pode mudar por fora (voltar pelo histórico, reset do form)
        sel.addEventListener('change', sincronizar);
        sincronizar();
    });

    // antes de enviar: valor e categoria (o servidor valida tudo de novo); trava o duplo toque
    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('form[data-lf]')) {
            return;
        }
        if (form.getAttribute('data-enviando') === '1') {
            ev.preventDefault();
            return;
        }
        var valor = valorDe(form);
        var erro = null;
        var foco = null;
        var campo = null;
        if (valor && !/[1-9]/.test(valor.value)) {
            erro = T('Digite o valor.');
            foco = valor;
            campo = 'valor';
        } else if (!transferindo(form) && !categoriaDe(form)) {
            erro = T('Escolha a categoria.');
            foco = campoCategoria(form);
            campo = 'categoria';
        }
        if (erro) {
            ev.preventDefault();
            mostrarErro(form, erro, campo);
            if (foco) {
                foco.focus();
            }
            return;
        }
        form.setAttribute('data-enviando', '1');
        var b = form.querySelector('[data-lf-enviar]');
        if (b) {
            b.setAttribute('aria-disabled', 'true');
        }
    });

    // voltar pelo histórico (bfcache) devolve o form destravado, com a caixa da categoria igual ao <select>
    window.addEventListener('pageshow', function () {
        var fs = document.querySelectorAll('form[data-lf]');
        for (var i = 0; i < fs.length; i++) {
            destravar(fs[i]);
            var combo = fs[i].querySelector('[data-lf-combo]');
            if (combo && combo.lfSincronizar) {
                combo.lfSincronizar();
            }
        }
    });

    // filtro do extrato: select de categoria → hidden inputs f/cat no submit
    var filtroForm = document.getElementById('ex-filtro-form');
    var filtroSel = document.getElementById('ex-cat-sel');
    if (filtroForm && filtroSel) {
        filtroForm.addEventListener('submit', function () {
            filtroForm.querySelectorAll('input[name="f"], input[name="cat"]').forEach(function (el) { el.remove(); });
            var v = filtroSel.value;
            var inp;
            if (v.indexOf('g:') === 0) {
                inp = document.createElement('input');
                inp.type = 'hidden'; inp.name = 'f'; inp.value = v.slice(2);
                filtroForm.appendChild(inp);
            } else if (v !== '') {
                inp = document.createElement('input');
                inp.type = 'hidden'; inp.name = 'cat'; inp.value = v;
                filtroForm.appendChild(inp);
            }
        });
    }

    // período do extrato: o mês, o ano ou todos os meses buscam na hora; "Entre datas" mostra as datas e espera o Buscar
    var periodoSel = document.querySelector('[data-ex-periodo]');
    if (filtroForm && periodoSel) {
        var datas = filtroForm.querySelector('[data-ex-datas]');
        periodoSel.addEventListener('change', function () {
            if (periodoSel.value === 'datas') {
                if (datas) {
                    datas.classList.add('is-on');
                    var de = datas.querySelector('input[name="de"]');
                    if (de) { de.focus(); }
                }
                return;
            }
            if (datas) {
                datas.classList.remove('is-on');
            }
            if (typeof filtroForm.requestSubmit === 'function') { filtroForm.requestSubmit(); } else { filtroForm.submit(); }
        });
    }

    // o valor é digitado como se fala: 45 é 45,00. Ao sair do campo, só formata o que não tem dúvida; o resto o servidor confere
    var SO_NUMERO = FIN.dec === ',' ? /^(\d+)(?:,(\d{1,2}))?$/ : /^(\d+)(?:\.(\d{1,2}))?$/;
    function formatarValor(txt) {
        var m = SO_NUMERO.exec(txt.trim());
        if (!m) return txt;
        return FIN.num(parseFloat(m[1] + '.' + (m[2] || '0')));
    }
    // o campo do valor acompanha a largura do número: "R$" e o valor ficam juntos e centralizados
    function ajustarLargura(input) {
        input.style.width = (Math.max(4, input.value.length) + 0.4) + 'ch';
    }
    document.querySelectorAll('[data-lf-valor]').forEach(function (input) {
        if (input.value === FIN.num(0)) input.value = '';
        ajustarLargura(input);
        input.addEventListener('input', function (ev) {
            input.value = input.value.replace(/[^\d.,]/g, '');
            ajustarLargura(input);
            if (ev.isTrusted && input.form) {           // a pessoa digitou: deixa de ser a sugestão, e o erro sai
                input.removeAttribute('data-lf-sugerido');
                var dica = input.form.querySelector('[data-lf-sugestao]');
                if (dica) { dica.hidden = true; }
                if (input.getAttribute('aria-invalid') === 'true') { mostrarErro(input.form, ''); }
            }
        });
        input.addEventListener('blur', function () {
            input.value = formatarValor(input.value);
            ajustarLargura(input);
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });
        input.addEventListener('focus', function () {
            input.select();
        });
    });
})();
