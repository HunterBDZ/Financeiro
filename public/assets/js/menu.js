// menu.js: o modo organizar das abas ([data-org]: o menu lateral e a lista do botão Menu), como os ícones do celular.
// - entra pelo botão "Organizar menu" ou segurando uma aba por meio segundo; sai com Pronto, Esc ou fechando o menu;
// - arrastar (Pointer Events: mouse, toque e caneta) ou as setas de cada aba mudam a ordem, e o aviso diz a posição;
// - cada mudança vai para POST /menu/ordem, com o token; "Voltar à ordem padrão" manda padrao=1;
// - enquanto organiza, as abas não navegam.
(function () {
    'use strict';

    var T = window.T || function (s) { return s; };
    var SEGURAR = 500;       // ms do toque longo
    var TOLERANCIA = 8;      // px que o dedo pode andar sem desistir do toque longo
    var reduzido = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    document.querySelectorAll('[data-org]').forEach(iniciar);

    function iniciar(org) {
        var lista = org.querySelector('[data-org-lista]');
        var barra = org.querySelector('[data-org-barra]');
        var abrir = org.querySelector('[data-org-abrir]');
        var aviso = org.querySelector('[data-org-aviso]');
        var modelo = org.querySelector('template[data-org-setas]');
        var menu = org.closest('details');
        var rolagem = org.closest('.lateral, .menu-painel');     // quem rola quando a lista não cabe
        if (!lista || !barra || !abrir || !aviso || !modelo) {
            return;
        }
        var ativo = false;
        var segurar = null;      // toque longo em andamento: { id, x, y, item, timer, tipo }
        var arraste = null;      // arraste em andamento
        var engolirClique = false;
        var envio = null;        // salvar daqui a pouco (várias setas seguidas viram um pedido só)
        var avisoTimer = null;

        function itens() {
            return Array.prototype.slice.call(lista.children);
        }
        function nome(item) {
            var a = item.querySelector('a');
            return a ? a.textContent.trim() : '';
        }
        function avisar(texto) {
            // limpa antes, para o leitor de tela repetir o mesmo texto
            aviso.textContent = '';
            clearTimeout(avisoTimer);
            avisoTimer = setTimeout(function () { aviso.textContent = texto; }, 50);
        }
        function posicao(item) {
            var todos = itens();
            return T('{aba}: posição {n} de {total}.', { aba: nome(item), n: todos.indexOf(item) + 1, total: todos.length });
        }

        // as setas: rótulo com o nome da aba; a 1ª não sobe e a última não desce
        function rotular() {
            var todos = itens();
            todos.forEach(function (item, i) {
                var subir = item.querySelector('[data-org-subir]');
                var descer = item.querySelector('[data-org-descer]');
                if (!subir || !descer) {
                    return;
                }
                subir.setAttribute('aria-label', T('Subir {aba}', { aba: nome(item) }));
                descer.setAttribute('aria-label', T('Descer {aba}', { aba: nome(item) }));
                subir.disabled = i === 0;
                descer.disabled = i === todos.length - 1;
            });
        }

        // anima de onde cada aba estava até o lugar novo (FLIP)
        function animar(mudar) {
            var todos = itens();
            var antes = todos.map(function (item) { return item.getBoundingClientRect().top; });
            mudar();
            if (reduzido) {
                return;
            }
            todos.forEach(function (item, i) {
                var d = antes[i] - item.getBoundingClientRect().top;
                if (d !== 0) {
                    item.classList.add('org-sem-transicao');
                    item.style.transform = 'translateY(' + d + 'px)';
                }
            });
            void lista.offsetHeight;
            todos.forEach(function (item) {
                item.classList.remove('org-sem-transicao');
                item.style.transform = '';
            });
        }

        function entrar(focar) {
            if (ativo) {
                return;
            }
            ativo = true;
            org.classList.add('organizando');
            barra.hidden = false;
            abrir.hidden = true;
            itens().forEach(function (item) {
                var a = item.querySelector('a');
                if (a) {
                    a.setAttribute('tabindex', '-1');
                    a.setAttribute('aria-disabled', 'true');
                }
                item.appendChild(modelo.content.firstElementChild.cloneNode(true));
            });
            rotular();
            avisar(T('Modo de organizar o menu. Arraste as abas ou use as setas de cada uma. Para terminar, use Pronto ou a tecla Esc.'));
            document.addEventListener('keydown', aoTeclar, true);
            if (focar) {
                var primeira = lista.querySelector('.org-seta:not(:disabled)');
                if (primeira) {
                    primeira.focus();
                }
            }
        }

        function sair(devolverFoco) {
            if (!ativo) {
                return;
            }
            if (arraste) {
                soltar(true);
            }
            ativo = false;
            org.classList.remove('organizando');
            barra.hidden = true;
            abrir.hidden = false;
            itens().forEach(function (item) {
                var a = item.querySelector('a');
                if (a) {
                    a.removeAttribute('tabindex');
                    a.removeAttribute('aria-disabled');
                }
                var setas = item.querySelector('.org-setas');
                if (setas) {
                    setas.remove();
                }
            });
            if (envio !== null) {
                clearTimeout(envio);
                enviar();
            }
            document.removeEventListener('keydown', aoTeclar, true);
            avisar(T('Menu organizado.'));
            if (devolverFoco) {
                abrir.focus();
            }
        }

        function aoTeclar(ev) {
            if (ev.key !== 'Escape') {
                return;
            }
            // antes do app.js: Esc sai do modo e deixa o menu do celular aberto
            ev.preventDefault();
            ev.stopPropagation();
            if (arraste) {
                soltar(true);
            } else {
                sair(true);
            }
        }

        // ---- gravar
        function postar(corpo) {
            corpo.append('csrf_token', org.getAttribute('data-org-csrf') || '');
            return fetch('/menu/ordem', { method: 'POST', body: corpo, credentials: 'same-origin' }).then(function (r) {
                if (!r.ok || r.redirected) {
                    throw new Error('menu ' + r.status);
                }
            }).catch(function () {
                avisar(T('Não deu para salvar a ordem. Tente de novo.'));
            });
        }
        function enviar() {
            envio = null;
            var corpo = new FormData();
            itens().forEach(function (item) { corpo.append('ordem[]', item.getAttribute('data-aba')); });
            postar(corpo);
        }
        function salvar() {
            clearTimeout(envio);
            envio = setTimeout(enviar, 300);
        }
        function voltarAoPadrao() {
            clearTimeout(envio);
            envio = null;
            var padrao = (org.getAttribute('data-org-padrao') || '').split(',');
            animar(function () {
                padrao.forEach(function (chave) {
                    var item = lista.querySelector('[data-aba="' + chave + '"]');
                    if (item) {
                        lista.appendChild(item);
                    }
                });
            });
            rotular();
            var corpo = new FormData();
            corpo.append('padrao', '1');
            postar(corpo);
            avisar(T('O menu voltou à ordem padrão.'));
        }

        // ---- setas
        function mover(item, passo) {
            var todos = itens();
            var de = todos.indexOf(item);
            var para = de + passo;
            if (para < 0 || para >= todos.length) {
                return;
            }
            var foco = document.activeElement;
            animar(function () {
                lista.insertBefore(item, passo < 0 ? todos[para] : todos[para].nextSibling);
            });
            rotular();
            // mudar de lugar tira o foco; a seta que ficou desligada passa o foco para a outra
            if (foco instanceof HTMLElement && item.contains(foco)) {
                if (foco.disabled) {
                    foco = item.querySelector(foco.hasAttribute('data-org-subir') ? '[data-org-descer]' : '[data-org-subir]') || foco;
                }
                foco.focus();
            }
            avisar(posicao(item));
            salvar();
        }

        // ---- arrastar
        function comecar(item, id, y) {
            var todos = itens();
            var caixas = todos.map(function (i) { return i.getBoundingClientRect(); });
            var de = todos.indexOf(item);
            // quanto as outras andam para abrir espaço: a altura da arrastada e o vão entre as abas
            var vao = todos.length > 1 ? caixas[1].top - caixas[0].bottom : 0;
            arraste = { item: item, id: id, y0: y, y: y, de: de, para: de, todos: todos, caixas: caixas, passo: caixas[de].height + vao, s0: rolagem ? rolagem.scrollTop : 0, quadro: 0 };
            item.classList.add('org-arrastando');
            try {
                item.setPointerCapture(id);
            } catch (e) {}
            rolarNaBorda();
        }
        // perto da borda de cima ou de baixo, o menu rola sozinho enquanto a aba está segura
        function rolarNaBorda() {
            if (!arraste || !rolagem) {
                return;
            }
            var r = rolagem.getBoundingClientRect();
            var v = arraste.y < r.top + 48 ? -6 : (arraste.y > r.bottom - 48 ? 6 : 0);
            if (v !== 0) {
                var antes = rolagem.scrollTop;
                rolagem.scrollTop += v;
                if (rolagem.scrollTop !== antes) {
                    arrastar(arraste.y);
                }
            }
            arraste.quadro = requestAnimationFrame(rolarNaBorda);
        }
        function arrastar(y) {
            var a = arraste;
            var c = a.caixas;     // posições do começo; o quanto o menu rolou entra em dy
            a.y = y;
            var rolou = rolagem ? rolagem.scrollTop - a.s0 : 0;
            var dy = Math.max(c[0].top - c[a.de].top, Math.min(c[c.length - 1].bottom - c[a.de].bottom, y - a.y0 + rolou));
            a.item.style.transform = 'translateY(' + dy + 'px)';
            var centro = c[a.de].top + c[a.de].height / 2 + dy;
            var para = 0;
            c.forEach(function (caixa, i) {
                if (i !== a.de && caixa.top + caixa.height / 2 < centro) {
                    para++;
                }
            });
            a.para = para;
            a.todos.forEach(function (outro, i) {
                if (i === a.de) {
                    return;
                }
                var desloc = 0;
                if (a.de < para && i > a.de && i <= para) {
                    desloc = -a.passo;
                } else if (para < a.de && i >= para && i < a.de) {
                    desloc = a.passo;
                }
                outro.style.transform = desloc ? 'translateY(' + desloc + 'px)' : '';
            });
        }
        function soltar(cancelar) {
            var a = arraste;
            arraste = null;
            cancelAnimationFrame(a.quadro);
            var antes = a.item.getBoundingClientRect().top;
            a.todos.forEach(function (i) {
                i.classList.add('org-sem-transicao');
                i.style.transform = '';
            });
            a.item.classList.remove('org-arrastando');
            var mudou = !cancelar && a.para !== a.de;
            if (mudou) {
                var resto = a.todos.filter(function (i) { return i !== a.item; });
                lista.insertBefore(a.item, resto[a.para] || null);
            }
            var d = antes - a.item.getBoundingClientRect().top;
            if (d !== 0 && !reduzido) {
                a.item.style.transform = 'translateY(' + d + 'px)';
            }
            void lista.offsetHeight;
            a.todos.forEach(function (i) { i.classList.remove('org-sem-transicao'); });
            a.item.style.transform = '';
            if (mudou) {
                rotular();
                avisar(posicao(a.item));
                salvar();
            }
        }

        // ---- ponteiro: toque longo para entrar; no modo, arrastar
        lista.addEventListener('pointerdown', function (ev) {
            engolirClique = false;
            if (!ev.isPrimary || ev.button !== 0 || !(ev.target instanceof Element)) {
                return;
            }
            var item = ev.target.closest('.org-i');
            if (!item || item.parentNode !== lista) {
                return;
            }
            if (ativo) {
                if (!ev.target.closest('.org-setas')) {
                    ev.preventDefault();
                    comecar(item, ev.pointerId, ev.clientY);
                }
                return;
            }
            segurar = { id: ev.pointerId, x: ev.clientX, y: ev.clientY, item: item, tipo: ev.pointerType, timer: setTimeout(segurou, SEGURAR) };
        });
        function desistir() {
            if (segurar) {
                clearTimeout(segurar.timer);
                segurar = null;
            }
        }
        function segurou() {
            var s = segurar;
            segurar = null;
            if (!s) {
                return;
            }
            engolirClique = true;       // o clique que vem ao soltar não abre a aba
            entrar(false);
            if (navigator.vibrate && s.tipo === 'touch') {
                try { navigator.vibrate(10); } catch (e) {}
            }
            comecar(s.item, s.id, s.y);     // continua no mesmo gesto, como no celular
        }
        document.addEventListener('pointermove', function (ev) {
            if (segurar && ev.pointerId === segurar.id) {
                if (Math.abs(ev.clientX - segurar.x) > TOLERANCIA || Math.abs(ev.clientY - segurar.y) > TOLERANCIA) {
                    desistir();
                }
            } else if (arraste && ev.pointerId === arraste.id) {
                arrastar(ev.clientY);
            }
        });
        function fim(ev) {
            if (segurar && ev.pointerId === segurar.id) {
                desistir();
            } else if (arraste && ev.pointerId === arraste.id) {
                soltar(ev.type === 'pointercancel');
            }
        }
        document.addEventListener('pointerup', fim);
        document.addEventListener('pointercancel', fim);
        // no toque, segurar não rola a lista nem abre o menu do navegador sobre o link
        lista.addEventListener('touchmove', function (ev) {
            if (arraste) {
                ev.preventDefault();
            }
        }, { passive: false });
        lista.addEventListener('contextmenu', function (ev) {
            if (ativo || (segurar && segurar.tipo !== 'mouse')) {
                ev.preventDefault();
                if (segurar) {
                    clearTimeout(segurar.timer);
                    segurou();
                }
            }
        });

        // ---- cliques: no modo, as abas não navegam; as setas movem
        lista.addEventListener('click', function (ev) {
            if (!(ev.target instanceof Element)) {
                return;
            }
            var seta = ev.target.closest('[data-org-subir], [data-org-descer]');
            if (seta && ativo) {
                mover(seta.closest('.org-i'), seta.hasAttribute('data-org-subir') ? -1 : 1);
                return;
            }
            if ((ativo || engolirClique) && ev.target.closest('a')) {
                ev.preventDefault();
            }
            engolirClique = false;
        }, true);
        abrir.addEventListener('click', function () { entrar(true); });
        barra.querySelector('[data-org-pronto]').addEventListener('click', function () { sair(true); });
        barra.querySelector('[data-org-voltar]').addEventListener('click', voltarAoPadrao);
        // o menu do celular fechou: sai do modo junto
        if (menu) {
            menu.addEventListener('toggle', function () {
                if (!menu.open) {
                    sair(false);
                }
            });
        }
    }
})();
