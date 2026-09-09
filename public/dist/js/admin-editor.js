/*
 * Éditeur riche du back-office (CKEditor 5, build « classic »).
 *
 * Il s'applique à tout <textarea data-editor="rich"> : Official Rules, texte de
 * remerciement, texte d'une offre au format coupon.
 *
 * Trois choses à savoir avant d'y toucher :
 *
 * 1. Le HTML produit est rendu avec |raw dans les gabarits publics. C'est
 *    acceptable parce que ces champs ne sont éditables que depuis le
 *    back-office, derrière authentification — jamais par un participant. La
 *    configuration ci-dessous n'autorise donc que des balises de texte : ni
 *    script, ni iframe, ni style arbitraire.
 *
 * 2. Si CKEditor ne se charge pas — CDN injoignable, navigateur ancien — le
 *    <textarea> reste utilisable tel quel. L'éditeur est un confort de saisie,
 *    jamais une condition pour enregistrer : un rédacteur ne doit pas se
 *    retrouver bloqué devant un champ mort.
 *
 * 3. CKEditor ne synchronise pas le <textarea> en continu. Sans le
 *    updateSourceElement() à la soumission, on enregistre la version d'avant
 *    les dernières frappes — et le rédacteur ne s'en aperçoit qu'après coup.
 */
(function () {
    'use strict';

    var fields = document.querySelectorAll('textarea[data-editor="rich"]');
    if (!fields.length || typeof ClassicEditor === 'undefined') {
        return;
    }

    var editors = [];

    fields.forEach(function (field) {
        ClassicEditor
            .create(field, {
                toolbar: [
                    'heading', '|',
                    'bold', 'italic', 'link', '|',
                    'bulletedList', 'numberedList', '|',
                    'blockQuote', 'insertTable', '|',
                    'undo', 'redo', '|',
                    'sourceEditing'
                ],
                heading: {
                    options: [
                        { model: 'paragraph', title: 'Paragraphe', class: 'ck-heading_paragraph' },
                        { model: 'heading2', view: 'h2', title: 'Titre', class: 'ck-heading_heading2' },
                        { model: 'heading3', view: 'h3', title: 'Sous-titre', class: 'ck-heading_heading3' }
                    ]
                },
                link: { addTargetToExternalLinks: true }
            })
            .then(function (editor) {
                editors.push(editor);
                var minHeight = field.getAttribute('data-min-height') || '320px';
                editor.editing.view.change(function (writer) {
                    writer.setStyle('min-height', minHeight, editor.editing.view.document.getRoot());
                });
            })
            .catch(function (error) {
                // Le <textarea> d'origine reste en place et reste soumis :
                // l'échec de l'éditeur ne doit pas empêcher d'enregistrer.
                console.error('[admin] éditeur indisponible, saisie en texte brut', error);
            });
    });

    document.addEventListener('submit', function () {
        editors.forEach(function (editor) {
            editor.updateSourceElement();
        });
    }, true);
})();
