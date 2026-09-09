/*
 * Ouverture des mentions légales en popin.
 *
 * Pourquoi une popin plutôt qu'une page : un participant en cours de saisie qui
 * clique sur « Privacy Policy » ne doit pas quitter le tunnel. C'est aussi ce
 * que font les autres sites du parc (voir
 * template.comparer-changer.fr/templates/1/cadre/legals_modal.twig), à ceci près
 * qu'on n'a ici ni jQuery ni Bootstrap : le tunnel public ne charge aucune
 * ressource externe, et ce fichier pèse moins de 2 Ko.
 *
 * **Amélioration progressive.** Les liens pointent vers de vraies pages
 * (`/privacy`, `/terms`…). Sans JavaScript, ou si ce script échoue, ils
 * naviguent normalement. Une mention légale rendue inaccessible par un script
 * cassé serait une non-conformité, pas un défaut d'ergonomie — la popin ne fait
 * qu'améliorer un chemin qui marche déjà.
 */
(function () {
    'use strict';

    var links = document.querySelectorAll('a[data-legal]');
    if (!links.length || typeof window.fetch !== 'function') {
        return;
    }

    var dialog = document.getElementById('legal-modal');
    if (!dialog || typeof dialog.showModal !== 'function') {
        return;
    }

    var titleNode = dialog.querySelector('[data-legal-title]');
    var bodyNode = dialog.querySelector('[data-legal-body]');
    var cache = {};

    function render(page, title) {
        titleNode.textContent = title;
        bodyNode.innerHTML = '<p class="legal-loading">Loading…</p>';

        if (cache[page]) {
            bodyNode.innerHTML = cache[page];
            markViewed(page);
            return;
        }

        fetch('/legal-fragment/' + encodeURIComponent(page), { credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.text();
            })
            .then(function (html) {
                cache[page] = html;
                bodyNode.innerHTML = html;
                markViewed(page);
            })
            .catch(function () {
                // On ne laisse jamais le visiteur devant une popin vide : le lien
                // vers la vraie page reste le chemin sûr.
                bodyNode.innerHTML =
                    '<p>This document could not be loaded. '
                    + '<a href="/' + encodeURIComponent(page) + '">Open it in a new page</a>.</p>';
            });
    }

    /*
     * Traçabilité RGPD : a-t-on présenté la liste des destinataires, et le
     * participant l'a-t-il ouverte ? C'est une information qu'aucune donnée ne
     * permet de reconstituer après coup. Le parc fait de même.
     */
    function markViewed(page) {
        if (page !== 'partners') {
            return;
        }
        var fields = document.querySelectorAll('input[name="partners_viewed"]');
        for (var i = 0; i < fields.length; i++) {
            fields[i].value = '1';
        }
    }

    for (var i = 0; i < links.length; i++) {
        links[i].addEventListener('click', function (event) {
            event.preventDefault();
            render(this.getAttribute('data-legal'), this.textContent.trim());
            dialog.showModal();
        });
    }

    var closers = dialog.querySelectorAll('[data-legal-close]');
    for (var j = 0; j < closers.length; j++) {
        closers[j].addEventListener('click', function () {
            dialog.close();
        });
    }

    // Clic sur le fond : le comportement attendu d'une popin.
    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) {
            dialog.close();
        }
    });
})();
