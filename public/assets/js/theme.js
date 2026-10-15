// Tema claro/escuro (P1). Roda no <head>, antes do CSS pintar: sem "piscar" de tema.
// localStorage só guarda 'tema' (invariante 12).
(function () {
    var t = null;
    try { t = localStorage.getItem('tema'); } catch (e) {}
    if (t === 'light' || t === 'dark') {
        document.documentElement.setAttribute('data-theme', t);
    }
    if (t === 'light' || t === 'dark') {                     // sem escolha, as duas metas seguem o aparelho
        document.querySelectorAll('meta[name="theme-color"]').forEach(function (meta) {
            meta.setAttribute('content', t === 'light' ? '#ffffff' : '#000000');
        });
    }
})();
