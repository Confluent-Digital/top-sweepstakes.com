/*
 * Back-office — comportements. Aucun n'est necessaire au fonctionnement :
 * l'ecran reste utilisable si ce fichier ne charge pas.
 */
(function () {
    'use strict';

    // localStorage peut lever (navigation privee, cookies bloques) : toute
    // lecture et toute ecriture sont gardees.
    var read = function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } };
    var write = function (k, v) { try { localStorage.setItem(k, v); } catch (e) { /* sans effet */ } };

    var apply = function (theme) {
        document.documentElement.setAttribute('data-bs-theme', theme);
        var icon = document.getElementById('themeIcon');
        if (icon) { icon.className = theme === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars'; }
        var btn = document.getElementById('themeToggle');
        if (btn) { btn.title = theme === 'dark' ? 'Passer en mode clair' : 'Passer en mode sombre'; }
    };

    // Sans preference enregistree on suit le reglage du systeme : c'est deja la
    // reponse que l'operateur a donnee ailleurs. Le meme calcul est fait en
    // tete de page pour eviter le flash blanc au chargement.
    var stored = read('ts.theme');
    var systemDark = false;
    try { systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches; } catch (e) { /* sans effet */ }
    apply(stored || (systemDark ? 'dark' : 'light'));

    var toggle = document.getElementById('themeToggle');
    if (toggle) {
        toggle.addEventListener('click', function () {
            var next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
            write('ts.theme', next);
            apply(next);
        });
    }

    /*
     * Copie d'une valeur — notamment l'URL de sortie previsualisee. Copier est
     * la seule manipulation sans effet de bord : ouvrir le lien declencherait
     * un clic reel, facture a l'annonceur et compte dans son reporting.
     * Le bouton ne porte jamais l'URL en href, seulement en data-copy.
     */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.copy-btn');
        if (!btn || !btn.dataset.copy) { return; }
        var done = function () {
            var icon = btn.querySelector('i');
            var before = icon ? icon.className : '';
            btn.classList.add('is-copied');
            if (icon) { icon.className = 'bi bi-check-lg'; }
            setTimeout(function () {
                btn.classList.remove('is-copied');
                if (icon) { icon.className = before; }
            }, 1200);
        };
        if (navigator.clipboard) {
            navigator.clipboard.writeText(btn.dataset.copy).then(done, function () { /* sans effet */ });
        }
    });

    /*
     * Confirmation des gestes irreversibles ou factures. Le texte vient du
     * gabarit (data-confirm) : c'est lui qui connait la consequence exacte.
     * Ce n'est qu'un garde-fou de plus — le serveur reste seul juge.
     */
    document.addEventListener('submit', function (e) {
        var form = e.target.closest('form[data-confirm]');
        if (!form) { return; }
        if (!window.confirm(form.dataset.confirm)) { e.preventDefault(); }
    });
    document.addEventListener('click', function (e) {
        var link = e.target.closest('a[data-confirm]');
        if (!link) { return; }
        if (!window.confirm(link.dataset.confirm)) { e.preventDefault(); }
    });
})();
