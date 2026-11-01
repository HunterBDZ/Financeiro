// lancar.js (P4, SPEC §2.2): o "+" abre o <dialog id="lancar"> e foca o valor NO MESMO
// toque — o iOS só abre o teclado dentro do gesto do usuário. Também: [data-categoria],
// [data-valor], [data-descricao], [data-data] preenchem o form; tecla "n" (desktop); fechar
// (×, Esc, toque fora); checagem mínima antes de enviar + trava de duplo envio; o valor estilo
// maquininha (só dígitos, vírgula automática); o filtro do extrato. Sem nada inline (CSP
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

    function campoCategoria(form) {
        return form.querySelector('select[name="categoria_id"]');
    }

    function categoriaDe(form) {
        var sel = campoCategoria(form);
        return sel && sel.value !== '' ? sel.value : null;
    }

    function marcarCategoria(form, id) {
        var sel = campoCategoria(form);
        if (sel) {
            sel.value = String(id);
        }
    }

    function mostrarErro(form, texto) {
        var box = form.querySelector('[data-lf-erro]');
        if (!box) {
            return;
        }
        box.textContent = texto || '';
        box.hidden = !texto;
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
        }
        if (desc !== null) {
            var d = form.querySelector('input[name="descricao"]');
            if (d) {
                d.value = desc;
            }
        }
        // [data-pagou="cartao"]: abre com "Pagou com: Cartão" (do botão da aba Cartão); sem ele, volta a Conta
        var pagou = form.querySelector('input[name="pagou"][value="' + (origem.getAttribute('data-pagou') === 'cartao' ? 'cartao' : 'conta') + '"]');
        if (pagou && !pagou.checked) {
            pagou.checked = true;
            pagou.dispatchEvent(new Event('change', { bubbles: true }));
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

        // Enter no valor sem categoria: não envia; vai para a categoria
        if (ev.key === 'Enter' && a && a.matches && a.matches('[data-lf-valor]')) {
            var form = a.form;
            if (form && !categoriaDe(form)) {
                ev.preventDefault();
                var sel = campoCategoria(form);
                if (sel) {
                    sel.focus();
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

    // escolher a categoria some com o aviso
    document.addEventListener('change', function (ev) {
        var el = ev.target;
        if (el instanceof Element && el.matches('form[data-lf] [name="categoria_id"]') && el.form) {
            mostrarErro(el.form, '');
        }
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
        if (valor && !/[1-9]/.test(valor.value)) {
            erro = T('Digite o valor.');
            foco = valor;
        } else if (!categoriaDe(form)) {
            erro = T('Escolha a categoria.');
            foco = campoCategoria(form);
        }
        if (erro) {
            ev.preventDefault();
            mostrarErro(form, erro);
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

    // voltar pelo histórico (bfcache) devolve o form destravado
    window.addEventListener('pageshow', function () {
        var fs = document.querySelectorAll('form[data-lf]');
        for (var i = 0; i < fs.length; i++) {
            destravar(fs[i]);
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

    // o valor é digitado como se fala: 45 é 45,00. Ao sair do campo, só formata o que não tem dúvida; o resto o servidor confere
    function formatarValor(txt) {
        var m = /^(\d+)(?:,(\d{1,2}))?$/.exec(txt.trim());
        if (!m) return txt;
        var inteiro = m[1].replace(/^0+(?=\d)/, '').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        return inteiro + ',' + ((m[2] || '') + '00').slice(0, 2);
    }
    // o campo do valor acompanha a largura do número: "R$" e o valor ficam juntos e centralizados
    function ajustarLargura(input) {
        input.style.width = (Math.max(4, input.value.length) + 0.4) + 'ch';
    }
    document.querySelectorAll('[data-lf-valor]').forEach(function (input) {
        if (input.value === '0,00') input.value = '';
        ajustarLargura(input);
        input.addEventListener('input', function () {
            input.value = input.value.replace(/[^\d.,]/g, '');
            ajustarLargura(input);
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
