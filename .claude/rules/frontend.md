---
description: Conventions Twig, thème, formulaires, back-office
paths:
  - "src/Views/**"
  - "public/dist/**"
---

# Front

## Public

Pas de framework JS. Le tunnel doit rester rapide sur mobile en 4G : c'est du trafic payé,
chaque centaine de millisecondes se paie en taux de conversion.

- CSS et JS dans `public/dist/`, servis avec un cache long ; pas de CDN pour le chemin critique.
- Le **thème** passe par des variables CSS déclarées dans `layout.html.twig` depuis
  `t_sweepstake.theme`. Aucune feuille de style par concours.
- Les images de dotation sont servies en `webp` quand le navigateur l'accepte, avec repli.
- Le JS ne conditionne jamais l'enregistrement d'un participant ni d'un clic : tout fonctionne
  sans lui, en dégradé.

## Twig

- Échappement automatique. `|raw` est réservé aux champs HTML éditoriaux du back-office
  (`offer_text_html`, `sweepstake_official_rules_html`) et **jamais** appliqué à une donnée
  saisie par un participant.
- Aucune URL de régie écrite dans un gabarit : elle vient de `OfferLinkBuilder`.
- Aucune requête depuis un gabarit. Le contrôleur fournit tout.
- Cache Twig actif en production seulement ; le hook `twig-cache-clear.sh` le vide à chaque
  édition de template en développement.

## Formulaires du tunnel

- Validation côté serveur faisant autorité ; le côté client n'est qu'un confort.
- Les libellés, l'ordre et le caractère obligatoire viennent de `t_sweepstake_field`.
- Les consentements sont **des cases distinctes, jamais pré-cochées**, et leur texte est archivé
  (voir `legal-us.md`).
- Champ honeypot et horodatage d'ouverture de formulaire pour l'anti-bot.

## Back-office

Bootstrap 5 + DataTables + Chart.js. CSRF sur toute méthode mutative (`CsrfMiddleware`).

- Une DataTable filtre côté serveur dès que la table dépasse quelques milliers de lignes.
- Les totaux affichés portent sur le **jeu filtré**, pas sur la page courante.
- « Vide » ne se lit pas « zéro » : un écran sans donnée le dit explicitement.
