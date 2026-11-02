// Wishlist: colar o link preenche nome, valor e foto (quem lê é o servidor); a foto escolhida ou colada
// é reduzida aqui antes de subir; mudar o mês ou as parcelas troca só a previsão. Tudo funciona sem JS:
// o formulário é um POST normal e a previsão, links. Arquivo externo (CSP script-src 'self').
(function () {
    'use strict';
    var LADO = 1400;
    var LIMITE = 3145728;

    function reduzir(arquivo, pronto) {
        var leitor = new FileReader();
        leitor.onerror = function () { pronto(null); };
        leitor.onload = function () {
            var img = new Image();
            img.onerror = function () { pronto(null); };
            img.onload = function () {
                var escala = Math.min(1, LADO / Math.max(img.naturalWidth, img.naturalHeight));
                var c = document.createElement('canvas');
                c.width = Math.round(img.naturalWidth * escala);
                c.height = Math.round(img.naturalHeight * escala);
                var ctx = c.getContext('2d');
                ctx.fillStyle = '#fff';
                ctx.fillRect(0, 0, c.width, c.height);
                ctx.drawImage(img, 0, 0, c.width, c.height);
                pronto(c.toDataURL('image/jpeg', 0.85));
            };
            img.src = leitor.result;
        };
        leitor.readAsDataURL(arquivo);
    }

    function configurarForm(form) {
        var url = form.querySelector('[data-wl-url]');
        var nome = form.querySelector('[data-wl-nome]');
        var valor = form.querySelector('[data-wl-valor]');
        var status = form.querySelector('[data-wl-status]');
        var arquivo = form.querySelector('[data-wl-arquivo]');
        var foto64 = form.querySelector('[data-wl-foto64]');
        var lido = form.querySelector('[data-wl-lido]');
        var prev = form.querySelector('[data-wl-prev]');
        var salvar = form.querySelector('[data-wl-salvar]');
        var token = form.querySelector('[name="csrf_token"]');
        var moeda = form.querySelector('[data-wl-moeda]');
        var conv = form.querySelector('[data-wl-conv]');
        var taxas = {};
        try { taxas = JSON.parse(form.getAttribute('data-wl-taxas') || '{}'); } catch (e) { taxas = {}; }
        var ultimo = url.value.trim();
        var pedido = null;
        var temFotoNova = false;
        var timer = null;

        // prévia na moeda do site; quem converte de verdade é o servidor, ao mostrar a lista
        function numero(texto) {
            var t = texto.trim().split(FIN.simbolo).join('').trim();
            return FIN.pronto(t) ? FIN.ler(t) : null;
        }
        function converter() {
            var m = moeda.value;
            var v = numero(valor.value);
            if (m === FIN.moeda) {
                conv.hidden = true;
                return;
            }
            conv.hidden = false;
            if (!taxas[m]) {
                conv.textContent = T('Sem cotação de {moeda} agora.', { moeda: m });
            } else if (v === null || v <= 0) {
                conv.textContent = T('1 {moeda} = {simbolo} {valor}, cotação de {data}', { moeda: m, simbolo: FIN.simbolo, valor: FIN.num(taxas[m].v, 2, 4), data: taxas[m].d });
            } else {
                conv.textContent = T('≈ {simbolo} {valor}, cotação de {data}', { simbolo: FIN.simbolo, valor: FIN.num(v * taxas[m].v), data: taxas[m].d });
            }
        }
        function mostrar(dados) {
            prev.innerHTML = '';
            var img = document.createElement('img');
            img.alt = '';
            img.src = dados;
            prev.appendChild(img);
        }
        function usarFoto(dados) {
            foto64.value = dados;
            temFotoNova = true;
            arquivo.value = '';
            mostrar(dados);
        }
        function aviso(texto, erro) {
            status.textContent = texto;
            status.classList.toggle('is-erro', !!erro);
        }
        function tratarArquivo(f) {
            if (!f || f.type.indexOf('image/') !== 0) {
                aviso(T('Esse arquivo não é uma imagem: use JPG, PNG ou WebP.'), true);
                return;
            }
            reduzir(f, function (dados) {
                if (dados === null || dados.length * 0.75 > LIMITE) {
                    arquivo.value = '';
                    aviso(T('Não consegui abrir essa foto: tente outra em JPG ou PNG.'), true);
                    return;
                }
                usarFoto(dados);
            });
        }
        function ler() {
            var texto = url.value.trim();
            if (texto === '' || texto === ultimo) {
                return;
            }
            ultimo = texto;
            if (pedido) {
                pedido.abort();
            }
            pedido = new AbortController();
            lido.value = '';
            aviso(T('Lendo o link…'));
            var corpo = new FormData();
            corpo.append('csrf_token', token.value);
            corpo.append('url', texto);
            fetch('/wishlist/ler-link', { method: 'POST', body: corpo, credentials: 'same-origin', signal: pedido.signal }).then(function (r) {
                if (!r.ok || r.redirected) {
                    throw new Error('resposta');
                }
                return r.json();
            }).then(function (d) {
                if (d.motivo === 'link') {
                    aviso(T('Esse link não é válido: copie o endereço completo da página do produto.'), true);
                    return;
                }
                if (d.url) {
                    url.value = d.url;
                    ultimo = d.url;
                }
                lido.value = '1';
                if (!d.ok) {
                    aviso(T('A loja não liberou os dados. Preencha o nome, o valor e a foto.'), true);
                    nome.focus();
                    return;
                }
                if (d.nome && nome.value.trim() === '') { nome.value = d.nome; }
                if (d.valor && valor.value.trim() === '') {
                    valor.value = d.valor;
                    if (d.moeda) { moeda.value = d.moeda; }
                    converter();
                }
                if (d.foto && !temFotoNova) { foto64.value = d.foto; mostrar(d.foto); }
                var falta = [];
                if (!d.nome && nome.value.trim() === '') { falta.push(T('o nome')); }
                if (!d.valor && valor.value.trim() === '') { falta.push(T('o valor')); }
                if (!d.foto && !temFotoNova && !prev.querySelector('img')) { falta.push(T('a foto')); }
                aviso(falta.length ? T('Li o que deu. Falta {itens}.', { itens: falta.reduce(function (a, b) { return T('{a} e {b}', { a: a, b: b }); }) }) : T('Pronto: confira os dados e adicione.'), falta.length > 0);
            }).catch(function (e) {
                if (e.name !== 'AbortError') {
                    aviso(T('Não consegui ler o link agora. Preencha à mão ou salve que eu tento de novo.'), true);
                }
            });
        }

        moeda.addEventListener('change', converter);
        valor.addEventListener('input', converter);
        converter();
        url.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(ler, 500);
        });
        url.addEventListener('blur', ler);
        arquivo.addEventListener('change', function () {
            if (arquivo.files && arquivo.files[0]) {
                tratarArquivo(arquivo.files[0]);
            }
        });
        form.addEventListener('paste', function (ev) {
            var d = ev.clipboardData;
            if (d && d.files && d.files.length && d.files[0].type.indexOf('image/') === 0) {
                ev.preventDefault();
                tratarArquivo(d.files[0]);
            }
        });
        form.addEventListener('submit', function () {
            if (form.hasAttribute('data-enviando')) {
                return;
            }
            form.setAttribute('data-enviando', '');
            salvar.setAttribute('aria-busy', 'true');
        });
    }
    document.querySelectorAll('[data-wl-form]').forEach(configurarForm);

    var caixa = document.getElementById('wl-previsao');
    if (!caixa) {
        return;
    }
    var pedidoPrev = null;
    caixa.addEventListener('click', function (ev) {
        var a = ev.target.closest && ev.target.closest('[data-wl-mes]');
        if (!a || ev.metaKey || ev.ctrlKey || ev.shiftKey) {
            return;
        }
        ev.preventDefault();
        var destino = a.getAttribute('href');
        if (pedidoPrev) {
            pedidoPrev.abort();
        }
        pedidoPrev = new AbortController();
        fetch(destino + (destino.indexOf('?') < 0 ? '?' : '&') + 'parcial=1', { credentials: 'same-origin', signal: pedidoPrev.signal }).then(function (r) {
            if (!r.ok || r.redirected) {
                throw new Error('resposta');
            }
            return r.text();
        }).then(function (html) {
            caixa.innerHTML = html;
            history.replaceState(null, '', destino);
            var igual = caixa.querySelector('[data-wl-mes][href="' + destino.replace(/"/g, '\\"') + '"]') || caixa.querySelector('.wl-mes.is-sel');
            if (igual) {
                igual.focus({ preventScroll: true });
            }
        }).catch(function (e) {
            if (e.name !== 'AbortError') {
                location.href = destino;
            }
        });
    });
}());
