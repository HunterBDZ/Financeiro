// entrar.js — as páginas de fora do app (auth/_casca.php): o olho da senha e a prévia do começo guiado.
(function () {
    'use strict';
    // ---- [data-mostrar-senha]: o olho mostra e esconde o que foi digitado. Sem JS o botão nem aparece. Os rótulos vêm
    // no HTML (data-rotulo-*), já no idioma da página: aqui não há o i18n.js
    document.querySelectorAll('[data-mostrar-senha]').forEach(function (b) {
        var campo = document.getElementById(b.getAttribute('data-mostrar-senha'));
        if (!campo) { return; }
        b.hidden = false;
        b.addEventListener('click', function () {
            var mostrando = campo.type === 'text';
            campo.type = mostrando ? 'password' : 'text';
            b.setAttribute('aria-pressed', mostrando ? 'false' : 'true');
            b.setAttribute('aria-label', b.getAttribute(mostrando ? 'data-rotulo-mostrar' : 'data-rotulo-esconder') || '');
        });
    });
    // ---- o começo guiado: a prévia do mês ([data-previa]) refaz a conta enquanto a pessoa digita. O servidor já
    // mandou a prévia pronta; aqui só os números mudam. Os rótulos ("a responder", "já no saldo") vêm do HTML.
    var previa = document.querySelector('[data-previa]');
    if (previa) {
        var dec = previa.getAttribute('data-dec') || ',';
        var mil = previa.getAttribute('data-mil') || '.';
        var diaIni = parseInt(previa.getAttribute('data-dia-ini'), 10) || 1;
        var molde = previa.querySelector('.com-falta');
        var faltaTxt = molde ? molde.textContent : '';
        var antesMolde = document.createElement('span');
        antesMolde.className = 'com-antes';
        antesMolde.textContent = previa.getAttribute('data-antes') || '';

        var ler = function (txt) {
            txt = String(txt || '').replace(/\s/g, '');
            if (txt === '') { return null; }
            var limpo = txt.split(mil).join('');
            if (dec !== '.') { limpo = limpo.replace(dec, '.'); }
            var v = parseFloat(limpo.replace(/[^0-9.\-]/g, ''));
            return isFinite(v) ? v : null;
        };
        // o mesmo desenho do valor() do PHP: o sinal, o número e os centavos menores
        var desenhar = function (alvo, v, tipo, moeda) {
            alvo.textContent = '';
            if (v === null) {
                var f = document.createElement('span');
                f.className = 'com-falta';
                f.textContent = faltaTxt;
                alvo.appendChild(f);
                return;
            }
            var abs = Math.abs(v);
            var partes = abs.toFixed(2).split('.');
            var inteiro = partes[0].replace(/\B(?=(\d{3})+(?!\d))/g, mil);
            var n = document.createElement('span');
            n.className = 'num' + (tipo === 'previsto' ? ' v-previsto' : '');
            n.appendChild(document.createTextNode((v < 0 && abs >= 0.005 ? '−' : '')));
            if (moeda) {
                var m = document.createElement('span');
                m.className = 'moeda';
                m.textContent = previa.getAttribute('data-moeda') || '';
                n.appendChild(m);
            }
            n.appendChild(document.createTextNode(inteiro));
            var c = document.createElement('span');
            c.className = 'cent';
            c.textContent = dec + partes[1];
            n.appendChild(c);
            alvo.appendChild(n);
        };
        var linha = function (chave) { return previa.querySelector('[data-previa-linha="' + chave + '"]'); };
        var marcarAntes = function (li, antes) {
            if (!li) { return; }
            li.classList.toggle('is-antes', antes);
            var nome = li.querySelector('.com-l-n');
            var tem = nome && nome.querySelector('.com-antes');
            if (antes && nome && !tem && antesMolde.textContent) { nome.appendChild(document.createTextNode(' ')); nome.appendChild(antesMolde.cloneNode(true)); }
            if (!antes && tem) { tem.remove(); }
        };
        var somar = function () {
            var total = 0, algum = false;
            previa.querySelectorAll('[data-previa-linha]').forEach(function (li) {
                var v = li.getAttribute('data-valor');
                if (v === null || v === '' || li.classList.contains('is-antes')) { return; }
                algum = algum || li.getAttribute('data-previa-linha') === 'saldo';
                total += li.hasAttribute('data-saida') ? -parseFloat(v) : parseFloat(v);
            });
            var alvo = previa.querySelector('[data-previa-total]');
            if (alvo) { desenhar(alvo, algum ? total : null, 'neutro', true); }
        };
        var atualizar = function (chave, valor) {
            var li = linha(chave);
            if (!li) { return; }
            li.setAttribute('data-valor', valor === null ? '' : String(Math.abs(valor)));
            var alvo = li.querySelector('[data-previa-v]');
            var saida = li.hasAttribute('data-saida');
            if (alvo) { desenhar(alvo, valor === null ? null : (saida ? -Math.abs(valor) : valor), chave === 'saldo' ? 'neutro' : 'previsto', false); }
            somar();
        };
        // a linha nova de "outra conta": ganha uma linha na prévia quando a pessoa começa a preencher
        var novaLinha = function (grupo) {
            var id = grupo.getAttribute('data-previa-nova-id');
            if (!id) {
                id = 'nova-' + Math.random().toString(36).slice(2, 8);
                grupo.setAttribute('data-previa-nova-id', id);
            }
            var li = linha(id);
            var lugar = previa.querySelector('[data-previa-novas]');
            if (!li && lugar) {
                li = document.createElement('li');
                li.className = 'com-l';
                li.setAttribute('data-previa-linha', id);
                li.setAttribute('data-saida', '');
                li.innerHTML = '<span class="com-l-n"></span><span class="com-l-v" data-previa-v></span>';
                lugar.parentNode.insertBefore(li, lugar);
            }
            return li ? id : null;
        };
        document.addEventListener('input', function (ev) {
            var el = ev.target;
            if (!(el instanceof HTMLInputElement)) { return; }
            var grupo = el.closest('[data-com-nova]');
            if (grupo) {
                var id = novaLinha(grupo);
                var li = id ? linha(id) : null;
                if (!li) { return; }
                var nome = grupo.querySelector('[data-previa-nome]');
                var campoNome = li.querySelector('.com-l-n');
                if (campoNome && nome && !campoNome.querySelector('.com-antes')) { campoNome.textContent = nome.value.trim(); }
                var vNova = ler(grupo.querySelector('[data-previa-campo]').value);
                var dNova = parseInt(grupo.querySelector('[data-previa-dia]').value, 10);
                li.hidden = !nome || nome.value.trim() === '';
                marcarAntes(li, dNova > 0 && dNova < diaIni);
                atualizar(id, li.hidden ? null : vNova);
                return;
            }
            if (el.hasAttribute('data-previa-campo')) {
                atualizar(el.getAttribute('data-previa-campo'), ler(el.value));
            } else if (el.hasAttribute('data-previa-dia')) {
                var d = parseInt(el.value, 10);
                marcarAntes(linha(el.getAttribute('data-previa-dia')), d > 0 && d < diaIni);
                somar();
            }
        });
        // a linha do campo em foco acende na prévia: a pessoa vê onde a resposta entra
        var acesa = null;
        document.addEventListener('focusin', function (ev) {
            var el = ev.target;
            if (acesa) { acesa.classList.remove('is-agora'); acesa = null; }
            if (!(el instanceof HTMLInputElement)) { return; }
            var grupo = el.closest('[data-com-nova]');
            var chave = grupo ? grupo.getAttribute('data-previa-nova-id') : (el.getAttribute('data-previa-campo') || el.getAttribute('data-previa-dia'));
            var li = chave ? linha(chave) : null;
            if (li) { li.classList.add('is-agora'); acesa = li; }
        });
        // o que você usa: a linha "Também no app"
        document.addEventListener('change', function (ev) {
            var el = ev.target;
            if (!(el instanceof HTMLInputElement) || !el.hasAttribute('data-previa-uso')) { return; }
            var usos = previa.querySelector('[data-previa-usos]');
            var item = usos && usos.querySelector('[data-uso="' + el.getAttribute('data-previa-uso') + '"]');
            if (!item) { return; }
            item.hidden = !el.checked;
            usos.hidden = !usos.querySelector('[data-uso]:not([hidden])');
        });
    }

    // ---- "Adicionar outra conta fixa": copia a linha em branco (sem JS, fica a linha que já está lá)
    var mais = document.querySelector('[data-com-mais]');
    if (mais) {
        mais.hidden = false;
        mais.addEventListener('click', function () {
            var linhas = document.querySelectorAll('[data-com-nova]');
            var ultima = linhas[linhas.length - 1];
            if (!ultima) { return; }
            var nova = ultima.cloneNode(true);
            nova.removeAttribute('data-previa-nova-id');
            nova.querySelectorAll('input').forEach(function (i) { i.value = ''; });
            ultima.parentNode.insertBefore(nova, ultima.nextSibling);
            nova.querySelector('input').focus();
        });
    }

    // ---- moeda e fuso do começo: o resumo da dobra acompanha a escolha
    var regional = document.querySelector('[data-com-regional]');
    if (regional) {
        var atualizarRegional = function () {
            var m = document.querySelector('select[name="moeda"]');
            var f = document.querySelector('select[name="fuso"]');
            if (m && f && m.selectedOptions[0] && f.selectedOptions[0]) {
                regional.textContent = m.selectedOptions[0].text + ', ' + f.selectedOptions[0].text;
            }
        };
        document.addEventListener('change', function (ev) {
            if (ev.target instanceof HTMLSelectElement && (ev.target.name === 'moeda' || ev.target.name === 'fuso')) { atualizarRegional(); }
        });
        window.addEventListener('load', atualizarRegional);      // o fuso.js troca o fuso pelo do navegador ao abrir
    }
})();
