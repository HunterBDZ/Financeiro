// Tema claro/escuro (P1). Roda no <head>, antes do CSS pintar: sem "piscar" de tema.
// localStorage só guarda 'tema' (invariante 12). "Esconder valores" (o olho do saldo) vale só
// para a aba aberta: fica no sessionStorage e some ao fechar.
(function () {
    var raiz = document.documentElement;
    var t = null;
    try { t = localStorage.getItem('tema'); } catch (e) {}
    if (t === 'light' || t === 'dark') {
        raiz.setAttribute('data-theme', t);
        document.querySelectorAll('meta[name="theme-color"]').forEach(function (meta) {   // sem escolha, as duas metas seguem o aparelho
            meta.setAttribute('content', t === 'light' ? '#f4f5f7' : '#0a0b0f');
        });
    }
    try {
        if (sessionStorage.getItem('ocultar') === '1') {
            raiz.classList.add('valores-ocultos');
        }
    } catch (e) {}
})();
