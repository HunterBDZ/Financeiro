// Tela Temas: escolher claro, escuro ou automático de uma paleta mostra o tema no app inteiro; Salvar grava, Voltar
// desfaz. Sem JS, cada escolha é um POST que já salva. Arquivo externo (CSP script-src 'self'); não faz nada nas outras páginas.
(function () {
    'use strict';
    var barra = document.querySelector('[data-tm-barra]');
    if (!barra) {
        return;
    }
    var raiz = document.documentElement;
    var original = { theme: raiz.getAttribute('data-theme'), tema: raiz.getAttribute('data-tema') };
    var metas = Array.prototype.map.call(document.querySelectorAll('meta[name="theme-color"]'), function (m) { return [m, m.getAttribute('content')]; });
    var escolhido = null;

    function aplicar(theme, tema) {
        if (theme) { raiz.setAttribute('data-theme', theme); } else { raiz.removeAttribute('data-theme'); }
        if (tema) { raiz.setAttribute('data-tema', tema); } else { raiz.removeAttribute('data-tema'); }
    }
    function marcar(botao) {
        document.querySelectorAll('.tm.is-prev').forEach(function (b) { b.classList.remove('is-prev'); });
        if (botao) { botao.classList.add('is-prev'); }
    }
    function voltar() {
        aplicar(original.theme, original.tema);
        metas.forEach(function (x) { x[0].setAttribute('content', x[1]); });
        marcar(null);
        escolhido = null;
        barra.hidden = true;
    }

    document.querySelectorAll('[data-tm-form]').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var b = form.querySelector('button');
            if (b.hasAttribute('data-tm-auto')) {
                aplicar(null, null);
            } else {
                // automático: só a paleta; o lado segue o aparelho
                var modo = b.getAttribute('data-tm-modo');
                aplicar(modo === 'auto' ? null : modo, b.getAttribute('data-tm-id'));
                metas.forEach(function (x) { x[0].setAttribute('content', b.getAttribute('data-tm-bg')); });
            }
            if (b.classList.contains('is-on')) {        // o tema que já está em uso: nada a salvar
                voltar();
                return;
            }
            marcar(b);
            escolhido = form;
            barra.querySelector('[data-tm-barra-n]').textContent = b.getAttribute('data-tm-nome');
            barra.hidden = false;
        });
    });
    barra.querySelector('[data-tm-voltar]').addEventListener('click', voltar);
    barra.querySelector('[data-tm-salvar]').addEventListener('click', function () {
        if (!escolhido) { return; }
        var b = escolhido.querySelector('button');
        try {
            if (b.hasAttribute('data-tm-auto')) {
                localStorage.removeItem('tema');
            } else {
                localStorage.setItem('tema', b.getAttribute('data-tm-id') + ':' + b.getAttribute('data-tm-modo'));
            }
        } catch (e) {}
        escolhido.submit();
    });
}());
