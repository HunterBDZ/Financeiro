// Importar extrato, na revisão: "Marcar todas", a conta de quantas linhas vão entrar, o aviso de linha marcada sem
// categoria e o "Sempre assim" de cada linha, que preenche a regra com a descrição e a categoria dela.
// Sem JS, a tela funciona igual: só não aparecem o "Marcar todas" nem o "Sempre assim".
// Arquivo externo (CSP script-src 'self'); não faz nada nas páginas sem [data-imp-revisar].
(function () {
    'use strict';
    var tela = document.querySelector('[data-imp-revisar]');
    if (!tela) {
        return;
    }
    var marcas = Array.prototype.slice.call(tela.querySelectorAll('[data-imp-marca]:not(:disabled)'));
    var todas = tela.querySelector('[data-imp-todas]');
    var rotTodas = tela.querySelector('[data-imp-todas-rot]');
    var contador = tela.querySelector('[data-imp-contador]');

    function atualizar() {
        var n = 0;
        marcas.forEach(function (m) {
            var linha = m.closest('[data-imp-linha]');
            var cat = linha && linha.querySelector('[data-imp-cat]');
            if (m.checked) {
                n++;
            }
            if (linha) {
                linha.classList.toggle('is-marcada', m.checked);
                linha.classList.toggle('is-sem-cat', m.checked && !!cat && cat.value === '');
            }
        });
        if (contador) {
            contador.textContent = n === 1 ? T('1 para lançar') : T('{n} para lançar', { n: n });
        }
        if (todas) {
            todas.checked = n > 0 && n === marcas.length;
            todas.indeterminate = n > 0 && n < marcas.length;
        }
    }

    if (todas && rotTodas && marcas.length > 1) {
        rotTodas.hidden = false;
        todas.addEventListener('change', function () {
            marcas.forEach(function (m) { m.checked = todas.checked; });
            atualizar();
        });
    }
    tela.addEventListener('change', function (ev) {
        var alvo = ev.target;
        if (alvo instanceof Element && (alvo.matches('[data-imp-marca]') || alvo.matches('[data-imp-cat]'))) {
            atualizar();
        }
    });

    // "Sempre assim": leva o texto e a categoria da linha para "Criar uma regra"
    var texto = document.getElementById('imp-regra-texto');
    var catRegra = document.getElementById('imp-regra-cat');
    tela.querySelectorAll('[data-imp-regra]').forEach(function (b) {
        b.hidden = false;
        b.addEventListener('click', function () {
            var linha = b.closest('[data-imp-linha]');
            var cat = linha && linha.querySelector('[data-imp-cat]');
            if (texto) {
                texto.value = b.getAttribute('data-imp-regra') || '';
            }
            if (catRegra && cat && cat.value !== '') {
                catRegra.value = cat.value;
            }
            if (texto) {
                texto.scrollIntoView({ block: 'center', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
                texto.focus({ preventScroll: true });
                texto.select();
            }
        });
    });

    atualizar();
})();
