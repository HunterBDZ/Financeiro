// lancar.js: o "+" abre o <dialog id="lancar"> e foca o valor NO MESMO
// toque — o iOS só abre o teclado dentro do gesto do usuário. Também: [data-categoria],
// [data-valor], [data-descricao], [data-data] preenchem o form; tecla "n" (desktop); fechar
// (×, Esc, toque fora); checagem mínima antes de enviar + trava de duplo envio; o valor estilo
// maquininha (só dígitos, vírgula automática); a categoria em um toque (botões, "Mais…" e a busca
// da lista); com várias contas, o "Pagou com" / "Recebeu em" e o modo Transferir; o filtro do extrato. Sem nada inline (CSP
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

    // a categoria: rádios (botões das mais usadas + a lista do "Mais…"); o <select> antigo ainda vale
    function campoCategoria(form) {
        var marcado = form.querySelector('input[name="categoria_id"]:checked');
        if (marcado) {
            return marcado.closest('.lf-op') && !marcado.closest('details[open]') ? form.querySelector('[data-lf-mais] > summary') : marcado;
        }
        return form.querySelector('input[data-lf-rapida]') || form.querySelector('[data-lf-mais] > summary') || form.querySelector('select[name="categoria_id"]');
    }

    function categoriaDe(form) {
        var marcado = form.querySelector('input[name="categoria_id"]:checked');
        if (marcado) {
            return marcado.value;
        }
        var sel = form.querySelector('select[name="categoria_id"]');
        return sel && sel.value !== '' ? sel.value : null;
    }

    // o "Mais…" mostra a categoria escolhida na lista; a que também tem botão fica marcada no botão
    function sincronizarMais(form) {
        var mais = form.querySelector('[data-lf-mais]');
        if (!mais) {
            return;
        }
        var marcado = form.querySelector('input[name="categoria_id"]:checked');
        var rot = mais.querySelector('[data-lf-mais-rot]');
        var op = marcado ? marcado.closest('.lf-op') : null;
        mais.classList.toggle('is-sel', !!op);
        if (!rot) {
            return;
        }
        rot.textContent = '';
        if (op) {
            var ic = op.querySelector('.ci');
            if (ic) {
                rot.appendChild(ic.cloneNode(true));
            }
            var nome = document.createElement('span');
            nome.textContent = (op.querySelector('span:last-child') || op).textContent;
            rot.appendChild(nome);
        } else {
            var mais1 = document.createElement('span');
            mais1.textContent = T('Mais…');
            rot.appendChild(mais1);
        }
    }

    function marcarCategoria(form, id) {
        var radios = form.querySelectorAll('input[name="categoria_id"][value="' + String(id).replace(/[^0-9]/g, '') + '"]');
        if (radios.length) {
            var alvo = form.querySelector('input[data-lf-rapida][value="' + String(id).replace(/[^0-9]/g, '') + '"]') || radios[0];
            alvo.checked = true;
            sincronizarMais(form);
            return;
        }
        var sel = form.querySelector('select[name="categoria_id"]');
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
        var cat = form.querySelector('input[name="categoria_id"]:checked');
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

    // escolher a categoria some com o aviso; na lista, a que tem botão passa para o botão e o "Mais…" fecha
    document.addEventListener('change', function (ev) {
        var el = ev.target;
        if (el instanceof Element && el.matches('form[data-lf] [name="categoria_id"]') && el.form) {
            mostrarErro(el.form, '');
            var form = el.form;
            if (el.closest('.lf-op')) {
                var botao = form.querySelector('input[data-lf-rapida][value="' + el.value + '"]');
                if (botao) {
                    botao.checked = true;
                }
                var mais = form.querySelector('[data-lf-mais]');
                if (mais && mais.open && !el.matches(':focus-visible')) {
                    mais.open = false;                  // escolheu com o dedo ou o mouse: a lista fecha
                }
            }
            sincronizarMais(form);
        }
    });

    // busca da lista completa: mostra a caixa (sem JS ela fica escondida) e filtra enquanto se digita
    document.querySelectorAll('[data-lf-mais]').forEach(function (mais) {
        var busca = mais.querySelector('[data-lf-busca]');
        if (!busca) {
            return;
        }
        busca.hidden = false;
        var vazio = mais.querySelector('[data-lf-vazio]');
        var sem = function (s) { return s.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); };
        var filtrar = function () {
            var q = sem(busca.value.trim());
            var achou = 0;
            mais.querySelectorAll('[data-lf-grupo]').forEach(function (g) {
                var n = 0;
                g.querySelectorAll('[data-lf-op]').forEach(function (op) {
                    var ok = q === '' || sem(op.getAttribute('data-lf-op')).indexOf(q) !== -1;
                    op.hidden = !ok;
                    if (ok) { n++; }
                });
                g.hidden = n === 0;
                achou += n;
            });
            if (vazio) {
                vazio.hidden = achou > 0;
            }
            return achou;
        };
        busca.addEventListener('input', filtrar);
        busca.addEventListener('keydown', function (ev) {
            var visiveis = Array.prototype.filter.call(mais.querySelectorAll('[data-lf-op] input'), function (i) { return !i.closest('[hidden]'); });
            if (ev.key === 'Enter') {
                ev.preventDefault();                    // Enter na busca não envia o lançamento
                if (visiveis.length === 1) {
                    visiveis[0].checked = true;
                    visiveis[0].dispatchEvent(new Event('change', { bubbles: true }));
                    mais.open = false;
                    var s = mais.querySelector('summary');
                    if (s) { s.focus(); }
                }
            } else if (ev.key === 'ArrowDown' && visiveis.length) {
                ev.preventDefault();
                visiveis[0].focus();
            }
        });
        mais.addEventListener('toggle', function () {
            if (mais.open) {
                busca.value = '';
                filtrar();
                if (window.matchMedia('(pointer: fine)').matches) {
                    busca.focus();                      // no celular, o teclado não abre sozinho
                }
            }
        });
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
        } else if (!transferindo(form) && !categoriaDe(form)) {
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
