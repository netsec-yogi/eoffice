// Applies the saved colour theme before first paint (no light flash in dark
// mode). A static file rather than an inline script, so the CSP stays
// nonce/self-only. Mirrors resources/js/lib/ui.ts.
(function () {
    var pref = 'light';
    try {
        pref = localStorage.getItem('ui.theme') || 'light';
    } catch (e) {}
    var dark = pref === 'dark' || (pref === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.classList.toggle('dark', dark);
})();
