// Empréstimo: atualiza o resultado enquanto se digita, pedindo ao servidor (?parcial=1), que é quem
// calcula; sem JS o formulário é um GET normal. Valor pela metade não dispara erro: ele só aparece
// ao sair do campo. O valor simulado acompanha o "Valor pago" do Consolidar até alguém mexer nele.
// Arquivo externo (CSP script-src 'self'); não faz nada nas outras páginas.
(function () {
    'use strict';
    var form = document.querySelector('[data-emp-form]');
    var box = document.getElementById('emp-resultado');
    if (!form || !box) {
        return;
    }
    var valor = form.querySelector('[name="valor"]');
    var mes = form.querySelector('[name="mes"]');
    var anuncio = document.querySelector('[data-emp-anuncio]');
    var PRONTO = /^(\d{1,3}(\.\d{3})+|\d+)(,\d{0,2})?$/;
    var timer = null;
    var pedido = null;
    var pago = document.querySelector('[data-emp-consolidar] [name="valor"]');
    var pagoEditado = !!pago && pago.value !== '' && pago.value !== valor.value;
    if (pago) {
        pago.addEventListener('input', function () { pagoEditado = true; });
    }

    function url(parcial) {
        var p = new URLSearchParams();
        p.set('valor', valor.value.trim());
        p.set('mes', mes.value);
        if (parcial) {
            p.set('parcial', '1');
        }
        return form.getAttribute('action') + '?' + p.toString();
    }

    function atualizar() {
        clearTimeout(timer);
        if (pedido) {
            pedido.abort();
        }
        pedido = new AbortController();
        fetch(url(true), { credentials: 'same-origin', signal: pedido.signal }).then(function (r) {
            if (r.redirected || !r.ok) {        // sessão expirou ou erro: o botão Simular abre a página normal
                throw new Error('resposta');
            }
            return r.text();
        }).then(function (html) {
            box.innerHTML = html;
            history.replaceState(null, '', url(false));
            var res = box.querySelector('[data-anuncio]');
            if (anuncio) {
                anuncio.textContent = res ? res.getAttribute('data-anuncio') : '';
            }
        }).catch(function () {});
    }

    valor.addEventListener('input', function () {
        clearTimeout(timer);
        if (pago && !pagoEditado) {
            pago.value = valor.value;
        }
        var v = valor.value.trim();
        if (v === '' || PRONTO.test(v)) {
            timer = setTimeout(atualizar, 350);
        }
    });
    valor.addEventListener('blur', function () {
        var v = valor.value.trim();
        if (v !== '' && !PRONTO.test(v)) {
            atualizar();
        }
    });
    mes.addEventListener('change', atualizar);
}());
