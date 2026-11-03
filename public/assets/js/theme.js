// Tema. Roda no <head>, antes do CSS pintar: sem "piscar" de tema. Logado com tema escolhido, ele vem no HTML e
// vale em qualquer aparelho, e aqui só fica a cópia para a tela de login. Sem escolha, vale o que estiver guardado
// neste aparelho: "light", "dark" ou "id:modo" (modo light, dark ou auto). localStorage só guarda 'tema' (invariante 12). "Esconder valores"
// (o olho do saldo) vale só para a aba aberta: fica no sessionStorage e some ao fechar.
(function () {
    var raiz = document.documentElement;
    var doHtml = raiz.getAttribute('data-tema');
    var t = null;
    if (doHtml) {
        try { localStorage.setItem('tema', doHtml + ':' + (raiz.getAttribute('data-theme') || 'auto')); } catch (e) {}
    } else if (raiz.hasAttribute('data-tema-auto')) {
        // a conta está no automático (ou o tema dela saiu da lista): a cópia deste aparelho não vale mais
        try { localStorage.removeItem('tema'); } catch (e) {}
    } else {
        try { t = localStorage.getItem('tema'); } catch (e) {}
    }
    var partes = t ? t.split(':') : [];
    if (partes.length === 2 && /^[a-z0-9]+$/.test(partes[0]) && (partes[1] === 'light' || partes[1] === 'dark' || partes[1] === 'auto')) {
        raiz.setAttribute('data-tema', partes[0]);
        t = partes[1];      // auto: a paleta segue o aparelho, sem data-theme
    }
    if (t === 'light' || t === 'dark') {
        raiz.setAttribute('data-theme', t);
        if (partes.length !== 2) {
            document.querySelectorAll('meta[name="theme-color"]').forEach(function (meta) {   // sem escolha, as duas metas seguem o aparelho
                meta.setAttribute('content', t === 'light' ? '#F6F8FB' : '#0A1020');
            });
        }
    }
    try {
        if (sessionStorage.getItem('ocultar') === '1') {
            raiz.classList.add('valores-ocultos');
        }
    } catch (e) {}
})();
