// Tema claro/escuro (P1). Roda no <head>, antes do CSS pintar: sem "piscar" de tema.
// localStorage só guarda 'tema' (invariante 12).
(function () {
    var t = null;
    try { t = localStorage.getItem('tema'); } catch (e) {}
    if (t === 'light' || t === 'dark') {
        document.documentElement.setAttribute('data-theme', t);
    }
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) {
        meta.setAttribute('content', t === 'light' ? '#f3f7f4' : '#0a0f0d');
    }
})();
